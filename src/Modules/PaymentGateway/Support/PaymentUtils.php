<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Support;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use WC_Order;

final class PaymentUtils
{
    /** Another payment of the same order is running: nothing was attempted. */
    public const MARKER_BUSY = 'BUSY';


    private const MAX_TOKEN_LENGTH = 4096;

    private const TOKEN_PATTERN = '#^[A-Za-z0-9._~+/=:-]+$#';

    /**
     * The proxy domain comes from the gateway settings only - there is no
     * rotation, so nothing about it is taken from the browser. is_available()
     * already hides the method without one; this guards a payment that gets
     * here anyway (a setting cleared mid-checkout).
     *
     * @return string The configured domain, the one the payment goes through.
     */
    public static function assertProxyDomain(WC_Order $order, string $configured): string
    {
        if ($configured === '') {
            self::rejectProxyDomain($order, 'No proxy domain is configured for the payment.', 'NPD');
        }

        return $configured;
    }

    /**
     * The card token comes from the browser too. Only its shape is checked
     * here - whether it is valid is up to Tagada. The charset covers base64,
     * base64url and JWT-style tokens with a prefix (`tok_...`, `a.b.c`).
     * The token itself never goes into a note or the log.
     */
    public static function assertToken(WC_Order $order, string $token): string
    {
        $token = trim($token);

        $reason = match (true) {
            $token === ''                              => 'No card token was submitted with the payment.',
            strlen($token) > self::MAX_TOKEN_LENGTH    => sprintf('The submitted card token is too long (%d bytes).', strlen($token)),
            !preg_match(self::TOKEN_PATTERN, $token)   => 'The submitted card token contains unexpected characters.',
            default                                    => null,
        };

        if ($reason === null) {
            return $token;
        }

        $order->add_order_note($reason);

        ImperaMain::logger()->warn(sprintf('Order %d: %s', $order->get_id(), $reason));

        throw new PaymentProcessException(
            'Your card details could not be verified. Please re-enter them and try again.',
            $token === '' ? 'NTK' : 'ITK'
        );
    }

    /**
     * One payment per order at a time. A second submit (double click, second
     * tab) must not put its attempt on the order while the first one is being
     * charged. MySQL GET_LOCK: atomic, released with the connection if PHP
     * dies. A busy order is refused at once, and nothing is attempted.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function withOrderLock(WC_Order $order, callable $work): mixed
    {
        global $wpdb;

        $lock = ltrim(ImperaMain::config()->keyPrefix, '_') . '_pay_' . $order->get_id();

        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            throw new PaymentProcessException(
                'A payment for this order is already being processed. Please wait a moment and check your order.',
                self::MARKER_BUSY
            );
        }

        try {
            return $work();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function rejectProxyDomain(WC_Order $order, string $reason, string $marker): never
    {
        $order->add_order_note($reason);

        ImperaMain::logger()->warn(sprintf('Order %d: %s', $order->get_id(), $reason));

        throw new PaymentProcessException(
            'We cannot process your payment right now, please try another payment method.',
            $marker
        );
    }

}
