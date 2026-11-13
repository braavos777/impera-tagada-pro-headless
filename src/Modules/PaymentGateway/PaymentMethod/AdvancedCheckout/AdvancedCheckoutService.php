<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\AdvancedCheckout;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentUnknownException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\ProxyRequestException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums\PaymentMethod;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\PaymentMethodInterface;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProcessPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Support\OrderParts;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Support\PaymentUtils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\PaymentReconciler;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use WC_Order;

/**
 * The card form flow: the proxy's iframe turned the card into a tagadaToken,
 * the checkout posted it here, and the proxy charges it server to server
 * (/payment/advance/capture) - as one attempt.
 *
 * The card is charged in that very call, so the attempt (its id, the proxy,
 * the mode) is stored on the order BEFORE the call: a charge whose answer is
 * lost must still be traceable. The proxy binds the attempt to exactly one
 * Tagada payment and refuses a new one while an earlier attempt of the order
 * is paid or not finished. The answer is read three ways:
 *  - 200: Tagada answered - applied through ApplyPaymentResult (paid, pending
 *    on hold, or declined);
 *  - 400/401/403/422: refused before any charge - no money moved;
 *  - 409, 5xx, 502 outcome_unknown, no answer: the money may have moved -
 *    PaymentUnknownException: the order is held and reconciled, never failed.
 */
final class AdvancedCheckoutService implements PaymentMethodInterface
{
    public function __construct(
        private readonly WcGatewaySettings $settings,
        private readonly PluginConfig $pluginConfig,
        private readonly ProxyClient $proxyClient,
        private readonly ApplyPaymentResult $applyPaymentResult,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::ADVANCED;
    }

    public function processPayment(WC_Order $order, string $mode, array $externalData = []): ProcessPaymentResponse
    {
        if ($order->is_paid()) {
            // The token never goes into a note or the log.
            $order->add_order_note(sprintf('Payment requested again for a paid order (proxy %s); nothing charged.', $this->settings->getProxyDomain()));

            return ProcessPaymentResponse::success($order->get_checkout_order_received_url());
        }

        // No rotation here: the one proxy is the shop's own setting.
        $proxyDomain = PaymentUtils::assertProxyDomain($order, $this->settings->getProxyDomain());
        $tagadaToken = PaymentUtils::assertToken($order, (string) ($externalData['tagadaToken'] ?? ''));

        return PaymentUtils::withOrderLock($order, function () use ($order, $proxyDomain, $mode, $tagadaToken): ProcessPaymentResponse {
            $attemptId = bin2hex(random_bytes(16));

            // Before the call: the card is charged in it.
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::ProxyDomain), $proxyDomain);
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::Mode), $mode);
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::Test), $mode === 'test');
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::AttemptId), $attemptId);
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::PaymentSource), 'card');
            $order->update_meta_data($this->pluginConfig->meta(MetaKey::PaymentFlow), 'advanced');
            $order->delete_meta_data($this->pluginConfig->meta(MetaKey::TransactionId));
            $order->save_meta_data();

            $payload = $this->buildPayload($order, $mode, $attemptId, $tagadaToken);

            $order->add_order_note(sprintf(
                'Charging the card through Tagada proxy %s (attempt %s, %s, %s %s).',
                $proxyDomain,
                $attemptId,
                $mode,
                $payload['amount'],
                $payload['currency']
            ));

            try {
                $result = ProxyPaymentResponse::fromProxyResponse($this->proxyClient->advancedCapture($proxyDomain, $payload));
            } catch (ProxyRequestException $e) {
                $this->logger->error(sprintf(
                    'Capture failed: HTTP %d %s: %s | wc=%d proxy=%s attempt=%s',
                    $e->getHttpStatus(),
                    $e->getErrorCode() !== '' ? $e->getErrorCode() : '-',
                    $e->getMessage(),
                    $order->get_id(),
                    $proxyDomain,
                    $attemptId
                ));

                $order->add_order_note(sprintf(
                    'Tagada proxy %s did not take the payment: %s',
                    $proxyDomain,
                    $e->getErrorCode() !== '' ? $e->getErrorCode() : 'HTTP ' . $e->getHttpStatus()
                ));

                $status = $e->getHttpStatus();

                // Another attempt of this order is paid or still running:
                // reconcile that one instead of charging again.
                if ($status === 409) {
                    $blocking = (string) ($e->body['blocking_attempt_id'] ?? '');
                    $pp       = (string) ($e->body['blocking_transaction_id'] ?? '');

                    throw new PaymentUnknownException($e->getErrorCode() ?: 'conflict', $blocking !== '' ? $blocking : null, $pp !== '' ? $pp : null);
                }

                // Refused before any charge (bad body, signature, key, token).
                if (in_array($status, [400, 401, 403, 422], true)) {
                    throw new PaymentProcessException(
                        'We cannot process your payment right now, please try another payment method.',
                        'RC-PX' . $status,
                        0,
                        $e
                    );
                }

                // No answer, 5xx, outcome_unknown: the card may be charged.
                throw new PaymentUnknownException($e->getErrorCode() ?: ('HTTP ' . $status));
            }

            if ($result->getAttemptId() !== $attemptId) {
                throw new PaymentUnknownException('the proxy answered for another attempt');
            }

            // An answer with no status Tagada uses says nothing about the
            // money: reconcile, never decline.
            if (!$result->isCompleted() && !$result->isPending() && !$result->isFailed()) {
                throw new PaymentUnknownException(sprintf('the proxy answered with status "%s"', $result->getStatus()));
            }

            // A refusal that never became a Tagada payment has nothing to apply:
            // the order stays as it is and the buyer sees the decline.
            if (!$result->isCompleted() && !$result->isPending() && (string) $result->getTransactionId() === '') {
                $order->add_order_note(sprintf('Tagada declined the card (attempt %s): %s', $attemptId, (string) ($result->getErrorMessage() ?? '-')));

                throw new PaymentProcessException(
                    'Your card was declined. Please check the details or use another card.',
                    'DCL'
                );
            }

            $applied = $this->applyPaymentResult->apply($order->get_id(), $result, ApplyPaymentResult::SOURCE_CAPTURE);

            if ($result->isCompleted() || $result->isPending()) {
                if ($result->isPending()) {
                    // Tagada decides later (3DS, review): its push or the
                    // reconciler settles the order.
                    ImperaMain::resolve(PaymentReconciler::class)->schedule($order->get_id(), 0);
                }

                WC()->cart?->empty_cart();

                return ProcessPaymentResponse::success($applied['order']->get_checkout_order_received_url());
            }

            throw new PaymentProcessException(
                'Your card was declined. Please check the details or use another card.',
                'DCL'
            );
        });
    }

    private function buildPayload(WC_Order $order, string $mode, string $attemptId, string $tagadaToken): array
    {
        $orderParts = new OrderParts($order, $this->settings);

        return $orderParts->filled([
            'mode'            => $mode,
            'merchant_site'   => Utils::homeDomain(),
            'attempt_id'      => $attemptId,
            'tagadaToken'     => $tagadaToken,
            'wc_order_id'     => $order->get_id(),
            'invoiceId'       => $orderParts->invoiceId(),
            'amount'          => $orderParts->amount($order->get_total()),
            'currency'        => strtoupper($order->get_currency()),
            'returnUrl'       => $orderParts->buildReturnUrl(),
            'shippingAddress' => $orderParts->shippingAddress(),
            'billingAddress'  => $orderParts->billingAddress(),
            'customer'        => $orderParts->customer(),
        ]);
    }
}
