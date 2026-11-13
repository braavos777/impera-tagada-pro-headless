<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use RuntimeException;
use Throwable;
use WC_Order;

/**
 * The single place a Tagada result changes an order - the capture answer
 * (AdvancedCheckoutService), the proxy's push (PaymentNotifyRoute) and the
 * status check (PaymentReconciler) all come through here, so they can never
 * disagree or race.
 *
 * Under a per-order MySQL lock (atomic, released with the connection if PHP
 * dies) the order is re-read from storage, the result validated
 * (PaymentResultValidator), and only an allowed transition is applied:
 *
 *   now                     | result                      | action
 *   ------------------------+-----------------------------+------------------------------
 *   unpaid (incl. on-hold)  | succeeded, checks pass      | payment_complete(), turnover counted once
 *   unpaid                  | pending / on_hold           | on-hold, nothing else
 *   paid                    | succeeded, same payment     | idempotent, nothing repeated
 *   paid                    | pending / failed (late)     | ignored - paid stays paid, meta untouched
 *   on-hold / pending       | failed / declined           | failed
 *   refunded / cancelled    | anything                    | not reopened, noted
 *   any                     | mismatch / other operation  | not applied; unpaid -> on-hold for a human
 *
 * The rotation turnover is counted only when the order becomes paid, once
 * (MetaKey::TurnoverCounted). The lock is never held across a call to the
 * proxy: callers take it after the answer is in hand.
 */
final class ApplyPaymentResult
{
    public const SOURCE_CAPTURE = 'proxy_capture';

    public const SOURCE_PUSH = 'proxy_push';

    public const SOURCE_STATUS = 'status_check';

    public const OUTCOME_COMPLETED  = 'completed';
    public const OUTCOME_ON_HOLD    = 'on-hold';
    public const OUTCOME_IDEMPOTENT = 'idempotent';
    public const OUTCOME_IGNORED    = 'ignored';
    public const OUTCOME_FAILED     = 'failed';
    public const OUTCOME_CONFLICT   = 'conflict';

    private const LOCK_TIMEOUT = 10;

    /** @var (callable(string $domain, float $amount): void)|null */
    private $countTurnover;

    /**
     * @param (callable(string $domain, float $amount): void)|null $countTurnover
     *        the proxy domain rotation, where the shop rotates its proxies
     */
    public function __construct(
        private readonly PluginConfig $config,
        private readonly PaymentResultValidator $validator,
        private readonly LoggerInterface $logger,
        ?callable $countTurnover = null,
    ) {
        $this->countTurnover = $countTurnover;
    }

    public static function pushSource(?string $event): string
    {
        return self::SOURCE_PUSH . ':' . ($event !== null && $event !== '' ? $event : 'unknown');
    }

    /**
     * @return array{outcome: string, reason: ?string, order: WC_Order}
     */
    public function apply(WC_Order|int $order, ProxyPaymentResponse $result, string $source): array
    {
        global $wpdb;

        $orderId = $order instanceof WC_Order ? $order->get_id() : $order;
        $lock    = ltrim($this->config->keyPrefix, '_') . '_order_' . $orderId;

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT)) !== 1) {
            throw new RuntimeException(sprintf('Order %d is busy (lock timeout) - not applied.', $orderId));
        }

        try {
            return $this->transition($this->fresh($orderId), $result, $source);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function fresh(int $orderId): WC_Order
    {
        clean_post_cache($orderId);
        wp_cache_delete($orderId, 'orders');
        wp_cache_delete('order-' . $orderId, 'orders');

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            throw new RuntimeException(sprintf('Order %d not found.', $orderId));
        }

        return $order;
    }

    private function transition(WC_Order $order, ProxyPaymentResponse $result, string $source): array
    {
        $ids = sprintf('pp=%s wc=%d attempt=%s source=%s', (string) $result->getTransactionId(), $order->get_id(), $result->getAttemptId() ?: '-', $source);

        if ($order->has_status(['refunded', 'cancelled'])) {
            $note = sprintf(
                'Tagada %s result for a %s order ignored (%s)%s',
                $result->getStatus(),
                $order->get_status(),
                $source,
                $result->isCompleted() ? ' - Tagada reports the money as taken: reconcile by hand.' : '.'
            );

            return $this->conflict($order, $note, false, $ids);
        }

        if ($result->isCompleted()) {
            $reason = $this->validator->validate($order, $result);

            if ($reason !== null) {
                return $this->conflict($order, sprintf(
                    'Tagada result NOT applied - manual reconciliation required: %s (%s, payment %s, %s %s, mode %s).',
                    $reason,
                    $source,
                    (string) ($result->getTransactionId() ?: '-'),
                    (string) $result->getGrossAmount(),
                    $result->getCurrency() ?: '-',
                    $result->getMode() ?: '-'
                ), !$order->is_paid(), $ids, $reason);
            }

            if ($order->is_paid()) {
                // The validator already matched the attempt and the payment.
                return $this->result(self::OUTCOME_IDEMPOTENT, $order);
            }

            $order->update_meta_data($this->config->meta(MetaKey::TransactionId), (string) $result->getTransactionId());
            $order->update_meta_data($this->config->meta(MetaKey::OrderId), (string) $result->getTransactionId());
            $order->update_meta_data($this->config->meta(MetaKey::GrossAmount), (string) $result->getGrossAmount());
            $order->update_meta_data($this->config->meta(MetaKey::PaymentStatus), $result->getStatus());
            $order->update_meta_data($this->config->meta(MetaKey::PaidSource), $source);

            if ($result->getMode() === 'test') {
                $order->add_order_note('THIS IS A TEST ORDER!!!!');
            }

            $order->add_order_note(sprintf(
                'Tagada payment completed: %s %s, transaction_id %s (%s), Tagada status: %s, Tagada substatus: %s.',
                (string) $result->getGrossAmount(),
                $result->getCurrency(),
                (string) $result->getTransactionId(),
                $source,
                $result->getStatus(),
                $result->getSubStatus()
            ));

            // payment_complete() saves the order, meta included.
            $order->payment_complete((string) $result->getTransactionId());

            $this->countTurnover($order, (float) $result->getGrossAmount());

            $this->logger->info(sprintf('Payment completed | %s', $ids));

            return $this->result(self::OUTCOME_COMPLETED, $order);
        }

        // Pending or failed: only the operation has to match.
        $reason = $this->validator->validate($order, $result, false);

        if ($reason !== null) {
            return $this->conflict($order, sprintf('%s for a %s result (%s)', $reason, $result->getStatus(), $source), false, $ids, $reason);
        }

        // A late pending or failure never touches a paid order - neither its
        // status nor its meta.
        if ($order->is_paid()) {
            $order->add_order_note(sprintf('Late Tagada %s for payment %s ignored (%s): the order is already paid.', $result->getStatus(), (string) $result->getTransactionId(), $source));

            return $this->result(self::OUTCOME_IGNORED, $order);
        }

        if ($result->isPending()) {
            $reason = (string) ($result->getRequireAction() ?: $result->getSubStatus());
            $note   = sprintf(
                'Tagada payment %s is pending (%s, %s). Tagada will confirm it later.',
                (string) $result->getTransactionId(),
                $reason !== '' ? $reason : 'no reason given',
                $source
            );

            $order->has_status('on-hold') ? $order->add_order_note($note) : $order->update_status('on-hold', $note);

            $this->logger->warn(sprintf('Payment pending | %s', $ids));

            return $this->result(self::OUTCOME_ON_HOLD, $order);
        }

        if ($result->isFailed()) {
            $note = sprintf(
                'Tagada payment %s: %s%s (%s). No funds were taken.',
                (string) $result->getTransactionId(),
                $result->getStatus(),
                $result->getErrorMessage() !== null ? ', ' . $result->getErrorMessage() : '',
                $source
            );

            if ($order->has_status('failed')) {
                $order->add_order_note($note);

                return $this->result(self::OUTCOME_IGNORED, $order);
            }

            $order->update_status('failed', $note);

            $this->logger->warn(sprintf('Payment %s | %s', $result->getStatus(), $ids));

            return $this->result(self::OUTCOME_FAILED, $order);
        }

        $order->add_order_note(sprintf('Tagada payment %s is %s (%s); the order is left as it is.', (string) $result->getTransactionId(), $result->getStatus(), $source));

        return $this->result(self::OUTCOME_IGNORED, $order);
    }

    /**
     * Not applied. A paid order keeps everything it has - only a note; an
     * unpaid one goes on hold for a human, and nothing ships. No automatic
     * refund: a wrong binding would refund someone else's money.
     */
    private function conflict(WC_Order $order, string $note, bool $hold, string $ids, string $reason = PaymentResultValidator::OPERATION_MISMATCH): array
    {
        $this->logger->error(sprintf('%s | %s', $note, $ids));

        if ($hold && !$order->has_status('on-hold')) {
            $order->update_status('on-hold', $note);
        } else {
            $order->add_order_note($note);
        }

        return ['outcome' => self::OUTCOME_CONFLICT, 'reason' => $reason, 'order' => $order];
    }

    /**
     * Once per order, written under the same order lock.
     */
    private function countTurnover(WC_Order $order, float $amount): void
    {
        $domain = (string) $order->get_meta($this->config->meta(MetaKey::ProxyDomain));

        if ($domain === '' || $this->countTurnover === null
            || (string) $order->get_meta($this->config->meta(MetaKey::TurnoverCounted)) !== '') {
            return;
        }

        $order->update_meta_data($this->config->meta(MetaKey::TurnoverCounted), (string) time());
        $order->save_meta_data();

        try {
            ($this->countTurnover)($domain, $amount);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Rotation usage for %s (WC order %d) not recorded: %s', $domain, $order->get_id(), $e->getMessage()));
        }
    }

    private function result(string $outcome, WC_Order $order): array
    {
        return ['outcome' => $outcome, 'reason' => null, 'order' => $order];
    }
}
