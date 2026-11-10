<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Responses;

use InvalidArgumentException;

/**
 * The typed result of a checkout flow, turned into the array
 * WC_Payment_Gateway::process_payment() must return only at the very end.
 *
 * Built through the named constructors so an impossible state - a success
 * without a redirect, a reload on success - cannot be expressed.
 *
 * How WooCommerce reads the array:
 * - classic checkout sends a success as JSON as-is and redirects to `redirect`;
 *   on failure it ignores the array and builds its own body from the notices
 *   and the `reload_checkout` session flag;
 * - the Blocks checkout copies every key into payment_details and shows
 *   `message` on failure instead of the notices.
 */
final class ProcessPaymentResponse
{
    private const SUCCESS = 'success';

    private const FAILURE = 'failure';

    /**
     * @param array<string, scalar> $data Extra keys for the checkout JS; cannot override the reserved ones.
     */
    private function __construct(
        public readonly bool $successful,
        public readonly string $redirect = '',
        public readonly string $message = '',
        public readonly bool $reload = false,
        public readonly array $data = [],
    ) {}

    /**
     * @param array<string, scalar> $data
     *
     * @throws InvalidArgumentException On an empty URL: the checkout JS would redirect to the current page.
     */
    public static function success(string $redirect, array $data = []): self
    {
        if ($redirect === '') {
            throw new InvalidArgumentException('A successful payment needs a redirect URL.');
        }

        return new self(successful: true, redirect: $redirect, data: $data);
    }

    /**
     * @param string $message Shown by the Blocks checkout; the classic one shows the wc_add_notice() notices instead.
     * @param bool   $reload  Reload the checkout page, e.g. when the cart changed during the payment.
     */
    public static function failure(string $message = '', bool $reload = false): self
    {
        return new self(successful: false, message: $message, reload: $reload);
    }

    /**
     * The classic checkout ignores `reload` in the gateway's array and only
     * reads the session flag, so the flag is set here as well.
     */
    public function toArray(): array
    {

        $result = [
            ...$this->data,
            'result'   => $this->successful ? self::SUCCESS : self::FAILURE,
            'redirect' => $this->redirect,
        ];

        if ($this->message !== '') {
            $result['message'] = $this->message;
        }

        if ($this->reload) {
            $result['reload'] = true;
        }

        return $result;
    }
}
