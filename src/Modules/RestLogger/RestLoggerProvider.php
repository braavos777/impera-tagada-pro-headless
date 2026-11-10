<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\RestLogger;

use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;
use ImperaTagadaProHeadless\Core\Container;
use ImperaTagadaProHeadless\Modules\RestLogger\RestApi\RouteRegister;

class RestLoggerProvider implements ProviderInterface
{

    public function register(Container $container): void
    {


    }

    public function boot(Container $container): void
    {

        //Register Rest API Route
        new RouteRegister();

    }
}
