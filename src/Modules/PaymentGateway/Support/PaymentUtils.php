<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Support;

use ImperaTagadaProHeadless\Core\Config\SharedSecret;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use WC_Order;

final class PaymentUtils
{

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
     * Through SharedSecret, so a key pinned in wp-config.php counts too.
     */
    public static function assertSharedKey(WC_Order $order, SharedSecret $sharedSecret): void
    {
        if ($sharedSecret->isConfigured()) {
            return;
        }

        ImperaMain::logger()->error(sprintf(
            'Order %d cannot be paid: the Tagada gateway has no shared key configured.',
            $order->get_id()
        ));

        throw new PaymentProcessException(
            'We cannot process your payment right now, please try another payment method.',
            'PPNC'
        );
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
