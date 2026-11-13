<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\Support\Calculator;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use WC_Order;

/**
 * One check for every Tagada result the shop applies - the capture answer, the
 * proxy's push and the status check alike - before a single meta field or
 * status is written. A missing field is never read as success.
 */
final class PaymentResultValidator
{
    public const OPERATION_MISMATCH = 'operation_mismatch';
    public const MODE_MISMATCH      = 'mode_mismatch';
    public const CURRENCY_MISMATCH  = 'currency_mismatch';
    public const AMOUNT_MISMATCH    = 'amount_mismatch';
    public const INCOMPLETE_RESULT  = 'incomplete_result';
    public const STATUS_CONFLICT    = 'status_conflict';

    /**
     * Null when the result belongs to this order and agrees with it; otherwise
     * the reason. $checkMoney is false for results that move no money onto the
     * order (a decline, a pending): only the operation has to match.
     */
    public function validate(WC_Order $order, ProxyPaymentResponse $result, bool $checkMoney = true): ?string
    {
        $config = ImperaMain::config();

        // The operation: this gateway, this WC order, the attempt the proxy
        // bound to exactly one Tagada payment, and that payment.
        if ($order->get_payment_method() !== $config->gatewayId) {
            return self::OPERATION_MISMATCH;
        }

        if ((int) $result->getWcOrderId() !== $order->get_id()) {
            return self::OPERATION_MISMATCH;
        }

        $storedAttempt = (string) $order->get_meta($config->meta(MetaKey::AttemptId));
        $attempt       = $result->getAttemptId();

        if ($storedAttempt === '' || $attempt === '' || !hash_equals($storedAttempt, $attempt)) {
            return self::OPERATION_MISMATCH;
        }

        $transactionId     = (string) $result->getTransactionId();
        $storedTransaction = (string) $order->get_meta($config->meta(MetaKey::TransactionId));

        if ($transactionId === '') {
            return self::INCOMPLETE_RESULT;
        }

        if ($storedTransaction !== '' && !hash_equals($storedTransaction, $transactionId)) {
            return self::OPERATION_MISMATCH;
        }

        // The environment fixed when the checkout was rendered.
        $mode       = $result->getMode();
        $storedMode = (string) $order->get_meta($config->meta(MetaKey::Mode));

        if (!in_array($mode, ['test', 'live'], true)) {
            return self::INCOMPLETE_RESULT;
        }

        if ($storedMode === '' || $mode !== $storedMode) {
            return self::MODE_MISMATCH;
        }

        if (!$checkMoney) {
            return null;
        }

        // "succeeded" with success:false is a contradiction, not a payment.
        if ($result->isCompleted() && !$result->isSuccess()) {
            return self::STATUS_CONFLICT;
        }

        $currency = $result->getCurrency();

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return self::INCOMPLETE_RESULT;
        }

        if ($currency !== strtoupper((string) $order->get_currency())) {
            return self::CURRENCY_MISMATCH;
        }

        // The charged amount against the order total, in the currency's own
        // minor units - never floats, never a tolerance.
        $gross = (int) $result->getGrossAmount(true);
        $total = Calculator::strictMinor(
            number_format((float) $order->get_total(), Calculator::decimals($currency), '.', ''),
            $currency
        );

        if ($gross <= 0) {
            return self::INCOMPLETE_RESULT;
        }

        if ($total === null || $gross !== $total) {
            return self::AMOUNT_MISMATCH;
        }

        return null;
    }
}
