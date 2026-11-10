<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;
use ImperaTagadaProHeadless\Modules\PaymentGateway\GatewayProvider;
use ImperaTagadaProHeadless\Modules\RestLogger\RestLoggerProvider;
use ImperaTagadaProHeadless\Modules\Test\TestProvider;
use ImperaTagadaProHeadless\Modules\Woocommerce\WooProvider;

use RuntimeException;


final class ImperaMain
{
    private static ?self $instance = null;

    /**
     * Set once by the plugin entry file, before any hook is registered.
     *
     * A static holder rather than a global helper function: this class lives in
     * the plugin's namespace, so two Impera plugins on one site cannot collide.
     */
    private static ?PluginConfig $config = null;

    private Container $container;

    /**
     * Prevent double boot.
     */
    private bool $booted = false;

    /**
     * Registered provider instances.
     */
    private array $registeredProviders = [];

    private const PROVIDERS = [
        CoreProvider::class,
        RestLoggerProvider::class,
        WooProvider::class,
        GatewayProvider::class,
        TestProvider::class,
    ];


    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function setConfig(PluginConfig $config): void
    {
        self::$config = $config;
    }

    /**
     * For the places dependency injection does not reach: static helpers, WP
     * hook callbacks and the uninstall hook.
     */
    public static function config(): PluginConfig
    {
        if (self::$config === null) {
            throw new RuntimeException(
                'Plugin config is not set: call ImperaMain::setConfig() from the plugin entry file.'
            );
        }

        return self::$config;
    }

    /**
     * Shortcut for resolving out of the container from a static context.
     */
    public static function resolve(string $id): mixed
    {
        return self::getInstance()->container()->get($id);
    }

    /**
     * Shortcut for the plugin logger from a static context.
     */
    public static function logger(): LoggerInterface
    {
        return self::resolve(LoggerInterface::class);
    }

    /**
     * Register all providers.
     */
    public function registerProviders(): self
    {
        foreach (self::PROVIDERS as $provider) {

            if (!class_exists($provider)) {
                throw new RuntimeException("Provider '$provider' not found.");
            }

            $this->registerProvider(
                new $provider()
            );
        }

        return $this;
    }

    /**
     * Register provider instance.
     */
    private function registerProvider(ProviderInterface $provider): void
    {

        $provider->register($this->container);

        $this->registeredProviders[] = $provider;
    }


    /**
     * Boot application.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->registeredProviders as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    /**
     * Get container.
     */
    public function container(): Container
    {
        return $this->container;
    }

}
