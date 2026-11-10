<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core;

use ImperaTagadaProHeadless\Core\Config\OptionKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;

/**
 * The plugin keeps no tables of its own: the gateway settings live in the
 * WooCommerce settings row, so activation and deactivation have nothing to do.
 */
class CoreSetupPlugin
{

    public function __construct(private readonly PluginConfig $config)
    {
    }

    public function activation(): void
    {
    }

    public function deactivation(): void
    {
    }

    /**
     * Registered as a static callback, so it reads the config off ImperaMain:
     * WordPress loads the plugin entry file before running an uninstall hook,
     * and that is where the config is built.
     */
    public static function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        $config = ImperaMain::config();

        delete_option($config->option(OptionKey::SharedKey));
    }

}
