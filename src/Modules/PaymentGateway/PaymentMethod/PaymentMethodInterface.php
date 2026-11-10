<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod;

use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums\PaymentMethod;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProcessPaymentResponse;
use WC_Order;

/**
 * Common type of the checkout-flow services the WooCommerce gateway delegates to.
 */
interface PaymentMethodInterface
{
    public function method(): PaymentMethod;

    /**
     * Starts the payment for an order. The proxy domain is not passed in: there
     * is no rotation here, so it always comes from the gateway settings.
     *
     * @throws PaymentProcessException When the payment cannot be started; its message is shown to the customer.
     */
    public function processPayment(WC_Order $order, string $mode, array $externalData = []): ProcessPaymentResponse;
}
