<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use RuntimeException;
use Throwable;
use WC_Order;

/**
 * Finds out what happened to a payment whose outcome is not known yet - an
 * answer that was lost, or a payment Tagada left pending (3DS, review).
 *
 * Asks the proxy (/payment/status) about the order's stored attempt; the proxy
 * asks Tagada, and replays the very same charge (same Idempotency-Key) when
 * its own answer was lost - never a second one:
 *  - captured     -> applied through ApplyPaymentResult, like any result;
 *  - not_captured -> no money: the order fails and the buyer may pay again;
 *  - unknown      -> asked again later (DELAYS); after the last try the order
 *                    stays on hold with a note for a human.
 *
 * While an order is unknown, a new payment for it first reconciles this one
 * (PaymentGateway::process_payment).
 */
final class PaymentReconciler
{
    public const OUTCOME_CAPTURED     = 'captured';
    public const OUTCOME_NOT_CAPTURED = 'not_captured';
    public const OUTCOME_UNKNOWN       = 'unknown';

    /** Seconds before check n (0-based). */
    public const DELAYS = [120, 600, 1800, 7200, 43200];

    private bool $reschedule = true;

    public function __construct(
        private readonly PluginConfig $config,
        private readonly ProxyClient $proxyClient,
        private readonly ApplyPaymentResult $applier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function hook(): string
    {
        return ltrim(ImperaMain::config()->keyPrefix, '_') . '_reconcile';
    }

    public static function register(): void
    {
        add_action(self::hook(), static function ($orderId, $attempt = 0): void {
            ImperaMain::resolve(self::class)->reconcile((int) $orderId, (int) $attempt);
        }, 10, 2);
    }

    /**
     * Holds the order as "payment being verified" and schedules the first
     * check. $attemptId / $transactionId, when given, replace the stored ones:
     * they name the attempt the proxy said blocks a new payment.
     */
    public function markUnknown(WC_Order $order, string $why, ?string $attemptId = null, ?string $transactionId = null): void
    {
        if ($attemptId !== null && preg_match('/^[a-f0-9]{32}$/', $attemptId) === 1) {
            $order->update_meta_data($this->config->meta(MetaKey::AttemptId), $attemptId);

            // The payment belongs to that attempt; a stale one of another
            // attempt would make its result look foreign.
            if ($transactionId !== null && $transactionId !== '') {
                $order->update_meta_data($this->config->meta(MetaKey::TransactionId), $transactionId);
            } else {
                $order->delete_meta_data($this->config->meta(MetaKey::TransactionId));
            }
        }

        $order->update_meta_data($this->config->meta(MetaKey::Unknown), (string) time());
        $order->save_meta_data();

        $note = sprintf(
            'Tagada payment status unknown (%s) - verifying attempt %s before anything else.',
            $why,
            (string) $order->get_meta($this->config->meta(MetaKey::AttemptId))
        );

        $order->has_status('on-hold') ? $order->add_order_note($note) : $order->update_status('on-hold', $note);

        $this->logger->warn(sprintf('Order %d: %s', $order->get_id(), $note));

        $this->schedule($order->get_id(), 0);
    }

    public function isUnknown(WC_Order $order): bool
    {
        return (string) $order->get_meta($this->config->meta(MetaKey::Unknown)) !== '';
    }

    /**
     * @param bool $reschedule false for a one-off check (a new payment attempt):
     *                         an unknown answer then schedules nothing.
     * @return string one of OUTCOME_*
     */
    public function reconcile(int $orderId, int $attempt = 0, bool $reschedule = true): string
    {
        $this->reschedule = $reschedule;

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->config->gatewayId) {
            return self::OUTCOME_UNKNOWN;
        }

        // Settled meanwhile (a push arrived, or an earlier check).
        if ($order->is_paid() || $order->has_status(['refunded', 'cancelled'])) {
            $this->clear($order);

            return self::OUTCOME_CAPTURED;
        }

        try {
            $status = $this->fetchStatus($order);
        } catch (Throwable $e) {
            return $this->retry($order, $attempt, $e->getMessage());
        }

        if ($status->getState() === self::OUTCOME_CAPTURED) {
            try {
                $applied = $this->applier->apply($orderId, $status, ApplyPaymentResult::SOURCE_STATUS);
            } catch (Throwable $e) {
                return $this->retry($order, $attempt, 'applying the result failed: ' . $e->getMessage());
            }

            $this->clear($applied['order']);

            return self::OUTCOME_CAPTURED;
        }

        if ($status->getState() === self::OUTCOME_NOT_CAPTURED) {
            $order->add_order_note(sprintf(
                'Tagada holds no payment for attempt %s - no money was taken (%s).',
                (string) $order->get_meta($this->config->meta(MetaKey::AttemptId)),
                (string) $status->getErrorMessage()
            ));

            if ($order->has_status(['on-hold', 'pending'])) {
                $order->update_status('failed', 'Payment not completed at Tagada.');
            }

            $this->clear($order);

            return self::OUTCOME_NOT_CAPTURED;
        }

        return $this->retry($order, $attempt, (string) ($status->getErrorMessage() ?: 'no definite answer yet'));
    }

    /**
     * Server to server, about the attempt stored on the order. Throws when
     * there is no usable answer.
     */
    private function fetchStatus(WC_Order $order): ProxyPaymentResponse
    {
        $proxyDomain = (string) $order->get_meta($this->config->meta(MetaKey::ProxyDomain));
        $attemptId   = (string) $order->get_meta($this->config->meta(MetaKey::AttemptId));
        $mode        = (string) $order->get_meta($this->config->meta(MetaKey::Mode));

        if ($proxyDomain === '' || $attemptId === '' || !in_array($mode, ['test', 'live'], true)) {
            throw new RuntimeException('The order has no proxy, attempt or mode stored.');
        }

        $body = $this->proxyClient->paymentStatus($proxyDomain, [
            'mode'          => $mode,
            'merchant_site' => Utils::homeDomain(),
            'wc_order_id'   => $order->get_id(),
            'attempt_id'    => $attemptId,
        ]);

        if (!in_array($body['state'] ?? '', [self::OUTCOME_CAPTURED, self::OUTCOME_NOT_CAPTURED, self::OUTCOME_UNKNOWN], true)) {
            throw new RuntimeException('The proxy answered the status without a state.');
        }

        return ProxyPaymentResponse::fromProxyResponse($body);
    }

    private function retry(WC_Order $order, int $attempt, string $why): string
    {
        if (!$this->reschedule) {
            return self::OUTCOME_UNKNOWN;
        }

        $next = $attempt + 1;

        if ($next < count(self::DELAYS)) {
            $this->schedule($order->get_id(), $next);

            return self::OUTCOME_UNKNOWN;
        }

        $note = sprintf(
            'Tagada payment status still unknown after %d checks (%s) - manual reconciliation required. Do not ask the buyer to pay again before it is resolved.',
            count(self::DELAYS),
            $why
        );

        $order->add_order_note($note);
        $this->logger->error(sprintf('Order %d: %s', $order->get_id(), $note));

        return self::OUTCOME_UNKNOWN;
    }

    public function schedule(int $orderId, int $attempt): void
    {
        $when = time() + self::DELAYS[$attempt];
        $args = [$orderId, $attempt];

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($when, self::hook(), $args, $this->config->slug);

            return;
        }

        wp_schedule_single_event($when, self::hook(), $args);
    }

    private function clear(WC_Order $order): void
    {
        if ($this->isUnknown($order)) {
            $order->delete_meta_data($this->config->meta(MetaKey::Unknown));
            $order->save_meta_data();
        }
    }
}
