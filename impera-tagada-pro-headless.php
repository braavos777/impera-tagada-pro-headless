<?php
/**
 * Plugin Name: Impera Tagada Pro Headless
 * Description: Tagada checkout through a proxy domain, for WooCommerce.
 * Author: Impera
 * Author URI:
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * Text Domain: impera-tagada-pro-headless
 * License: GPL-2.0-or-later
 *
 * Requires PHP is not decoration: this code uses readonly classes and enums,
 * so on PHP 8.1 and older it is a parse error rather than a graceful failure, and only
 * that header lets WordPress refuse the activation with an explanation.
 */


if (!defined('ABSPATH')) {
    exit;
}

if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}


$autoloader = plugin_dir_path(__FILE__) . 'vendor/autoload.php';


if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Display a notification in the admin panel if the autoloader was forgotten to collect
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Alert: Run <code>composer install</code> in the plugin folder.</p></div>';
    });
    return;
}



add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if ( ! defined( 'GITHUB_ACCESS_TOKEN' ) || empty( GITHUB_ACCESS_TOKEN ) ) {
    add_action( 'admin_notices', static function () {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>Impera Proxy Payment Plugin:</strong>
                The <code>GITHUB_ACCESS_TOKEN</code> constant is not defined.
                GitHub updates and private repository access will not work until the token is configured.
            </p>
        </div>
        <?php
    } );
}


use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\CoreSetupPlugin;
use ImperaTagadaProHeadless\Core\ImperaMain;


$imperaTagadaProHeadlessConfig = new PluginConfig(
    slug:                'impera-tagada-pro-headless',
    file:                __FILE__,
    dir:                 plugin_dir_path(__FILE__),
    url:                 plugin_dir_url(__FILE__),
    version:             get_file_data(__FILE__, ['Version' => 'Version'])['Version'],
    restNamespace:       'impera-tagada-pro-headless/v1',
    viewPath:            plugin_dir_path(__FILE__) . 'templates',
    keyPrefix:           '_impera_tagada_pro_headless',
    gatewayId:           'impera_tagada_pro_headless',
    repoUrl:             'https://github.com/braavos777/impera-tagada-pro-headless/',
    availableCurrencies: ['AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB', 'INR'],
    proxyRestNamespace:  'impera-tagada-gateway/v1',
);


ImperaMain::setConfig($imperaTagadaProHeadlessConfig);


/**
 * REGISTER ACTIVATION HOOKS
 */
$coreSetup = new CoreSetupPlugin($imperaTagadaProHeadlessConfig);
register_activation_hook(__FILE__, [$coreSetup, 'activation']);
register_deactivation_hook(__FILE__, [$coreSetup, 'deactivation']);
register_uninstall_hook(__FILE__, [CoreSetupPlugin::class, 'uninstall']);

/**
 * RUNNING THE PLUGIN
 */
ImperaMain::getInstance()->registerProviders()->boot();
