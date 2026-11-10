<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Contract;

use ImperaTagadaProHeadless\Core\Container;

interface ProviderInterface
{
    /**
     * Register bindings.
     */
    public function register(Container $container): void;

    /**
     * Boot runtime logic.
     * register action, filters
     */
    public function boot(Container $container): void;
}
