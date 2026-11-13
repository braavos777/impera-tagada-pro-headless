<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Container;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Contract\ProviderInterface;
use ImperaTagadaProHeadless\Modules\PaymentGateway\RestApi\RouteRegister;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\PaymentReconciler;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\PaymentResultValidator;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;

final class GatewayProvider implements ProviderInterface
{

    public function register(Container $container): void
    {
        $container->singleton(
            WcGatewaySettings::class,
            static fn (Container $container) => new WcGatewaySettings($container->get(PluginConfig::class)->gatewayId)
        );

        // Resolved only when WooCommerce asks for its gateways, so WC_Payment_Gateway is loaded by then.
        $container->singleton(PaymentGateway::class, static function (Container $container) {
            return new PaymentGateway(
                $container->get(PluginConfig::class),
                $container->get(WcGatewaySettings::class),
                $container->get(LoggerInterface::class)
            );
        });

        $container->singleton(ProxyClient::class, static fn (Container $container) => new ProxyClient(
            $container->get(PluginConfig::class),
            $container->get(LoggerInterface::class)
        ));

        // The one place a result changes an order: capture, push and status
        // check alike. No proxy rotation in this plugin.
        $container->singleton(ApplyPaymentResult::class, static fn (Container $container) => new ApplyPaymentResult(
            $container->get(PluginConfig::class),
            new PaymentResultValidator(),
            $container->get(LoggerInterface::class)
        ));

        $container->singleton(PaymentReconciler::class, static fn (Container $container) => new PaymentReconciler(
            $container->get(PluginConfig::class),
            $container->get(ProxyClient::class),
            $container->get(ApplyPaymentResult::class),
            $container->get(LoggerInterface::class)
        ));
    }

    public function boot(Container $container): void
    {
        add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($container): array {
            $gateways[] = $container->get(PaymentGateway::class);

            return $gateways;
        });

        // /payment/notify - the proxy's signed push.
        new RouteRegister();

        // Background checks of payments whose outcome is not known yet.
        PaymentReconciler::register();

    }
}
