<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;
use ImperaTagadaProHeadless\Core\Support\View;
use ImperaTagadaProHeadless\Core\Support\WcLogger;
use ImperaTagadaProHeadless\Core\UpdateChecker\UpdateChecker;

class CoreProvider implements ProviderInterface
{

    public function register(Container $container): void
    {
        $config = ImperaMain::config();

        $container->singleton(PluginConfig::class, static fn () => $config);

        $container->singleton(
            LoggerInterface::class,
            static fn (Container $container) => new WcLogger($container->get(PluginConfig::class)->slug)
        );
    }

    public function boot(Container $container): void
    {
        /** @var PluginConfig $config */
        $config = $container->get(PluginConfig::class);

        View::setViewPath($config->viewPath);

        //Check Updates from GITHUB
        new UpdateChecker($config->repoUrl, $config->file, $config->slug);
    }
}