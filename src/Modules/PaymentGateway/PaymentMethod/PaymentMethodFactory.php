<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\AdvancedCheckout\AdvancedCheckoutService;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums\PaymentMethod;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;

final class PaymentMethodFactory
{
    /**
     * Resolves the service for the checkout flow chosen in the gateway settings.
     */
    public static function fromSettings(WcGatewaySettings $settings): PaymentMethodInterface
    {
        return self::create($settings->getCheckoutFlow(), $settings);
    }

    /**
     * Resolves a concrete service for an explicit method, e.g. when an order
     * has to be finished with the flow it was started with, whatever the setting is now.
     *
     * @throws PaymentProcessException For a flow that is not built for Tagada yet.
     */
    public static function create(PaymentMethod $method, WcGatewaySettings $settings): PaymentMethodInterface
    {
        return match ($method) {
            PaymentMethod::ADVANCED => new AdvancedCheckoutService(
                $settings,
                ImperaMain::config(),
                ImperaMain::resolve(ProxyClient::class),
                ImperaMain::resolve(ApplyPaymentResult::class),
                ImperaMain::logger()
            ),
            // A stale setting must not become an UnhandledMatchError at checkout.
            PaymentMethod::REDIRECT => throw new PaymentProcessException(
                'This payment method is not available right now, please choose another one.',
                'NRF'
            ),
        };
    }
}
