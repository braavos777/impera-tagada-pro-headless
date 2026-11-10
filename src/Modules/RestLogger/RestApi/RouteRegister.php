<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\RestLogger\RestApi;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\RestApi\ApiEndpointsRegister;

class RouteRegister extends ApiEndpointsRegister
{

    protected function getRoutes(): array
    {
        return [
            LoggerRoute::class
        ];
    }

    protected function restNamespace(): string
    {
        return ImperaMain::config()->restNamespace;
    }

}