<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\RestApi;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\RestApi\ApiEndpointsRegister;

final class RouteRegister extends ApiEndpointsRegister
{
    protected function getRoutes(): array
    {
        return [
            PaymentNotifyRoute::class,
            CardFrameRoute::class,
        ];
    }

    protected function restNamespace(): string
    {
        return ImperaMain::config()->restNamespace;
    }
}
