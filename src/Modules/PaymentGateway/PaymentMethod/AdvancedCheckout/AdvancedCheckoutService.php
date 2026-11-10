<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\AdvancedCheckout;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Config\SharedSecret;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\ProxyRequestException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums\PaymentMethod;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\PaymentMethodInterface;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProcessPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Support\OrderParts;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Support\PaymentUtils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use WC_Order;

/**
 * The card form flow: the proxy's iframe turned the card into a tagadaToken,
 * the checkout posted it here, and the proxy charges it server to server
 * (/payment/advance/capture). The answer is applied to the order right away;
 * a pending one (3DS, processor delay) waits on hold for the proxy's push.
 */
class AdvancedCheckoutService implements PaymentMethodInterface
{
    public function __construct(
        private readonly WcGatewaySettings $settings,
        private readonly PluginConfig $pluginConfig,
        private readonly ProxyClient $proxyClient,
        private readonly SharedSecret $sharedSecret,
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

        $proxyDomain = PaymentUtils::assertProxyDomain($order, $this->settings->getProxyDomain());
        $tagadaToken = PaymentUtils::assertToken($order, (string) ($externalData['tagadaToken'] ?? ''));

        PaymentUtils::assertSharedKey($order, $this->sharedSecret);

        $order->update_meta_data($this->pluginConfig->meta(MetaKey::ProxyDomain), $proxyDomain);
        $order->update_meta_data($this->pluginConfig->meta(MetaKey::Test), $mode === 'test');
        $order->update_meta_data($this->pluginConfig->meta(MetaKey::PaymentSource), 'card');
        $order->update_meta_data($this->pluginConfig->meta(MetaKey::PaymentFlow), 'advanced');

        $payload = $this->buildPayload($order, $mode, $tagadaToken);


        $order->save_meta_data();

        $order->add_order_note(sprintf(
            'Charging the card through Tagada proxy %s (%s, %s %s).',
            $proxyDomain,
            $mode,
            $payload['amount'],
            $payload['currency']
        ));

        try {
            $result = ProxyPaymentResponse::fromProxyResponse($this->proxyClient->advancedCapture($proxyDomain, $payload));
        } catch (ProxyRequestException $e) {
            $this->logger->error(sprintf(
                'Capture failed: HTTP %d %s: %s | wc=%d proxy=%s',
                $e->getHttpStatus(),
                $e->getErrorCode() !== '' ? $e->getErrorCode() : '-',
                $e->getMessage(),
                $order->get_id(),
                $proxyDomain
            ));

            $order->add_order_note(sprintf(
                'Tagada proxy %s refused the payment: %s',
                $proxyDomain,
                $e->getErrorCode() !== '' ? $e->getErrorCode() : 'HTTP ' . $e->getHttpStatus()
            ));

            throw new PaymentProcessException(
                'We cannot process your payment right now, please try another payment method.',
                'RC-PX' . ($e->getHttpStatus() ?: ''),
                0,
                $e
            );
        }

        $this->applyPaymentResult->apply($order, $result, ApplyPaymentResult::SOURCE_CAPTURE);

        if ($result->isCompleted() || $result->isPending()) {
            WC()->cart?->empty_cart();

            return ProcessPaymentResponse::success($order->get_checkout_order_received_url());
        }

        throw new PaymentProcessException(
            'Your card was declined. Please check the details or use another card.',
            'DCL'
        );
    }

    private function buildPayload(WC_Order $order, string $mode, string $tagadaToken): array
    {
        $orderParts = new OrderParts($order, $this->settings);

        return $orderParts->filled([
            'mode'            => $mode,
            'merchant_site'   => Utils::homeDomain(),
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
