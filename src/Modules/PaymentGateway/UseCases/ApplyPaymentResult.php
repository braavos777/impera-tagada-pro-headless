<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Support\Calculator;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProcessPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use WC_Order;

/**
 * The one place a proxy result touches the WC order - shared by the capture
 * answer (AdvancedCheckoutService) and the proxy's push (PaymentNotifyRoute).
 *
 * Every step leaves an order note and a log line; the log lines carry
 * `pp=… wc=… source=…` (pp = the Tagada payment id) so one grep finds the
 * whole story of a payment.
 */
final class ApplyPaymentResult
{
    /** The proxy's answer to the capture the shop asked for at checkout. */
    public const SOURCE_CAPTURE = 'proxy_capture';

    /** The proxy pushed the result; the Tagada event is appended. */
    public const SOURCE_PUSH = 'proxy_push';

    public function __construct(
        private readonly PluginConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function pushSource(?string $event): string
    {
        return self::SOURCE_PUSH . ':' . ($event !== null && $event !== '' ? $event : 'unknown');
    }

    /**
     * @param string $source SOURCE_CAPTURE or pushSource(); goes to the notes,
     *                       the log and - for the first "paid" - the order meta.
     *
     * @return void
     *
     * @throws PaymentProcessException When the result is about another order.
     */
    public function apply(WC_Order $order, ProxyPaymentResponse $proxyPaymentResponse, string $source): void
    {
        if ($proxyPaymentResponse->isCompleted()){
            $this->complete($order, $proxyPaymentResponse, $source);
        }

        if ($proxyPaymentResponse->isPending()){
            $this->hold($order, $proxyPaymentResponse, $source);
        }

        if ($proxyPaymentResponse->isFailed()){
            $this->failed($order, $proxyPaymentResponse, $source);
        }

        $order->save();
    }

    /**
     * The proxy checks the charge against what it was asked for; the order
     * total is checked here too, because that is what the shop actually sells.
     * A mismatch puts the order on hold for a human instead of shipping it.
     */
    public function complete(WC_Order $order, ProxyPaymentResponse $proxyPaymentResponse, string $source): void
    {

        if ($order->is_paid()) {
            $order->add_order_note(sprintf('Tagada payment confirmed again: %s (%s).', $proxyPaymentResponse->getTransactionId(), $source));

            $this->logger->info(sprintf(
                'Payment confirmed again, order already %s | pp=%s wc=%d source=%s',
                $order->get_status(),
                $proxyPaymentResponse->getTransactionId(),
                $order->get_id(),
                $source
            ));

            return;
        }

        $order->update_meta_data($this->config->meta(MetaKey::TransactionId), $proxyPaymentResponse->getTransactionId());
        $order->update_meta_data($this->config->meta(MetaKey::OrderId), $proxyPaymentResponse->getTransactionId());
        $order->update_meta_data($this->config->meta(MetaKey::GrossAmount), $proxyPaymentResponse->getGrossAmount());
        $order->update_meta_data($this->config->meta(MetaKey::PaymentStatus), $proxyPaymentResponse->getStatus());
        $order->update_meta_data($this->config->meta(MetaKey::PaidSource), $source);


        $order->add_order_note(sprintf(
            'Tagada payment completed: %s %s, transaction_id %s (%s), Tagada status: %s, Tagada substatus: %s.',
            $proxyPaymentResponse->getGrossAmount(),
            $proxyPaymentResponse->getCurrency(),
            $proxyPaymentResponse->getTransactionId(),
            $source,
            $proxyPaymentResponse->getStatus(),
            $proxyPaymentResponse->getSubStatus()
        ));

        $order->payment_complete($proxyPaymentResponse->getTransactionId());
    }

    /**
     * A 3DS step the buyer has not passed, or a processor that answers later:
     * no money for sure yet, the final word comes by push.
     */
    private function hold(WC_Order $order, ProxyPaymentResponse $proxyPaymentResponse, string $source): void
    {
        $reason = $proxyPaymentResponse->getRequireAction() ?? $proxyPaymentResponse->getSubStatus();

        $this->logger->warn(sprintf(
            'Payment pending (%s) | pp=%s wc=%d source=%s',
            $reason !== '' ? $reason : '-',
            $proxyPaymentResponse->getTransactionId(),
            $order->get_id(),
            $source
        ));

        $this->onHold($order, sprintf(
            'Tagada payment %s is pending (%s, %s). Tagada will confirm it later.',
            $proxyPaymentResponse->getTransactionId() !== '' ? $proxyPaymentResponse->getTransactionId() : '-',
            $reason !== '' ? $reason : 'no reason given',
            $source
        ));
    }



    /**
     * DECLINED / FAILED: no money was taken. A paid order is never taken back
     * by such a message - a late or out-of-order event must not undo a payment.
     */
    private function failed(WC_Order $order, ProxyPaymentResponse $proxyPaymentResponse, string $source): void
    {
        $note = sprintf(
            'Tagada payment %s: %s%s (%s).',
            $proxyPaymentResponse->getTransactionId() !== '' ? $proxyPaymentResponse->getTransactionId() : '-',
            $proxyPaymentResponse->getStatus(),
            $proxyPaymentResponse->getErrorMessage() !== null ? ', ' . $proxyPaymentResponse->getErrorMessage() : '',
            $source
        );

        $this->logger->warn(sprintf(
            'Payment %s%s | pp=%s wc=%d source=%s order_status=%s',
            $proxyPaymentResponse->getStatus(),
            $proxyPaymentResponse->getErrorMessage() !== null ? ' (' . $proxyPaymentResponse->getErrorMessage() . ')' : '',
            $proxyPaymentResponse->getTransactionId(),
            $order->get_id(),
            $source,
            $order->get_status()
        ));

        if ($order->is_paid() || $order->has_status(['refunded', 'failed'])) {
            $order->add_order_note($note . ' The order status is left as it is.');

            return;
        }

        $order->update_status('failed', $note);
    }


    private function onHold(WC_Order $order, string $note): void
    {
        if ($order->has_status('on-hold')) {
            $order->add_order_note($note);

            return;
        }

        $order->update_status('on-hold', $note);
    }

}
