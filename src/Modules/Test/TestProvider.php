<?php

/**
 * @author Artem <gammaironak@gmail.com>
 * @date 06.10.2026
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\Test;

use ImperaTagadaProHeadless\Core\Container;
use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;

/**
 * Scratch provider for manual checks during development.
 */
final class TestProvider implements ProviderInterface
{

    public function register( Container $container): void
    {

    }

    public function boot( Container $container ): void
    {

    }
}
