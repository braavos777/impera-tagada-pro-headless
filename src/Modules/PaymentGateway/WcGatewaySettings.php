<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway;

use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums\PaymentMethod;

/**
 * Reads the gateway's own WooCommerce settings row without going through
 * WC_Payment_Gateway, so a use case can ask for a setting without a gateway
 * instance being constructed first.
 */
final class WcGatewaySettings
{
    private string $prefix = 'woocommerce_';

    private string $suffix = '_settings';

    private string $gatewayId;

    private array $settings = [];

    public function __construct(string $gatewayId)
    {
        $this->gatewayId = $gatewayId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (empty($this->settings)) {
            $this->initOptions();
        }

        $value = $this->settings[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }


    /**
     * Drops the cached row, so a read after the settings were saved in the
     * same request sees the new values.
     */
    public function flush(): void
    {
        $this->settings = [];
    }

    /**
     * The proxy host as stored by PaymentGateway::validate_proxy_domain_field(),
     * normalized again in case the row was written some other way.
     */
    public function getProxyDomain(): string
    {
        return self::normalizeDomain((string) $this->get('proxy_domain', ''));
    }

    public function isTestMode(): bool
    {
        return $this->get('test_mode') === 'yes';
    }

    /**
     * 'test' or 'live', as the proxy expects it in `mode`.
     */
    public function getMode(): string
    {
        return $this->isTestMode() ? 'test' : 'live';
    }

    /**
     * Bare lowercase host: no scheme, no path, no trailing slash.
     * The stored setting and a domain posted from the checkout are compared in this shape.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);

        return explode('/', $domain, 2)[0];
    }

    public function getInvoicePrefix(): string
    {
        return (string) $this->get('invoice_prefix', 'WC-');
    }

    public function getCardIcons(): array
    {
        return $this->get('card_icons', []);
    }


    public function getProductTitleSetting(): string
    {
        return (string) $this->get('product_title_setting', 'Order [order_id] item [rand_7]');
    }

    public function getCheckoutFlow(): PaymentMethod
    {
        return PaymentMethod::fromSetting($this->get('checkout_flow'));
    }

    public function isPaymentMethodAdvanced(): bool
    {
        return $this->getCheckoutFlow() === PaymentMethod::ADVANCED;
    }

    private function getOptionKey(): string
    {
        return $this->prefix . $this->gatewayId . $this->suffix;
    }

    private function initOptions(): void
    {
        $settings = get_option($this->getOptionKey(), []);

        $this->settings = is_array($settings) ? $settings : [];
    }
}
