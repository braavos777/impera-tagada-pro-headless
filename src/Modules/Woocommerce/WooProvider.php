<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\Woocommerce;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Container;
use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;
use ImperaTagadaProHeadless\Modules\Woocommerce\Hooks\OrdersTable;

class WooProvider implements ProviderInterface
{

    public function register( Container $container): void
    {

    }

    public function boot( Container $container ): void
    {
        //Add columns to orders table
        new OrdersTable($container->get(PluginConfig::class));
    }
}
