<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Config\SharedSecret;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Core\Support\View;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Hooks\AssetsRegister;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\PaymentMethodFactory;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProcessPaymentResponse;
use Throwable;
use WC_Admin_Settings;
use WC_Order;
use WC_Payment_Gateway;

/**
 * The WooCommerce side of the gateway: settings, availability and the
 * process_payment() entry point. The payment itself is left to the checkout
 * flow chosen in the settings (see PaymentMethodFactory), so this class knows
 * nothing about how the proxy is talked to.
 *
 * Built by the container rather than by WooCommerce: WC is handed an instance
 * through `woocommerce_payment_gateways`, which is what lets the constructor
 * take dependencies.
 */
final class PaymentGateway extends WC_Payment_Gateway
{
    private const CONFIG_FILE = __DIR__ . '/config/paypal-gateway-config.php';


    /**
     * Password fields whose stored value is never rendered back into the form.
     */
    private const SECRET_FIELDS = ['client_key', 'shared_key'];

    public function __construct(
        public readonly PluginConfig $config,
        public readonly WcGatewaySettings $gatewaySettings,
        public readonly SharedSecret $sharedSecret,
        public readonly LoggerInterface $logger,
    ) {
        $this->id                 = $config->gatewayId;
        $this->method_title       = 'Tagada Pro';
        $this->method_description = 'Tagada checkout through a proxy domain.';
        $this->has_fields         = true;
        $this->supports           = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title             = $this->get_option('title');
        $this->description       = $this->get_option('description');
        $this->order_button_text = $this->get_option('checkout_button_content');


        // Enqueue scripts
        $assets = new AssetsRegister($this);
        add_action( 'admin_enqueue_scripts', [$assets, 'registerAdmin'] );
        add_action( 'wp_enqueue_scripts', [$assets, 'registerCheckout'] );

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action( 'woocommerce_review_order_after_payment', [$this, 'render_iframe'] );//Render Iframe

        if(isset($_GET['pay_for_order'])){
            add_action( 'wp_enqueue_scripts', [$assets, 'registerOrderPay'] );
            add_action('woocommerce_pay_order_after_submit',  [$this, 'render_iframe']);
        }
    }

    public function init_form_fields(): void
    {
        // require, not require_once: the second call would get `true` back instead of the array.
        $fields = require self::CONFIG_FILE;

        $this->form_fields = $fields['payment_fields'] ?? [];

        $this->adjustSharedKeyField();
    }

    /**
     * Hidden rather than failing at checkout: an unsupported currency, a
     * missing key or a missing proxy domain would each make every payment fail,
     * so the customer is not offered the method at all. The same goes for the
     * storefront origin: without it the card form has nowhere it may be framed.
     */
    public function is_available(): bool
    {
        return parent::is_available()
            && $this->isCurrencySupported()
            && $this->gatewaySettings->getClientKey() !== ''
            && $this->sharedSecret->isConfigured()
            && $this->hasProxyDomain()
            && $this->hasStorefrontOrigin();
    }

    /**
     * WcGatewaySettings caches the settings row, and SharedSecret reads the key
     * through it: without the flush, the notices rendered right after saving
     * would still judge the old values.
     */
    public function process_admin_options(): bool
    {
        $saved = parent::process_admin_options();

        $this->gatewaySettings->flush();

        return $saved;
    }

    /**
     * A stored key is never sent back to the browser: WooCommerce would put it
     * in the input's value attribute, readable in the page source. The field
     * renders empty instead, and an empty submit keeps the stored key (see
     * keepSecret()).
     */
    public function generate_password_html($key, $data): string
    {
        if (!in_array($key, self::SECRET_FIELDS, true)) {
            return parent::generate_password_html($key, $data);
        }

        $stored = (string) $this->get_option($key);

        // Stops the browser from autofilling the admin's own login password here.
        $data['custom_attributes']['autocomplete'] = 'new-password';

        if ($stored !== '') {
            $data['placeholder'] = 'Saved. Leave empty to keep the current key.';

            // An empty field is the normal state now, so the browser must not block the submit.
            unset($data['custom_attributes']['required']);
        }

        $this->settings[$key] = '';

        try {
            return parent::generate_password_html($key, $data);
        } finally {
            $this->settings[$key] = $stored;
        }
    }

    /**
     * Called by WC_Settings_API::process_admin_options() for the `proxy_domain` field.
     * Stored as a bare host; an invalid value is rejected and the old one kept.
     */
    public function validate_proxy_domain_field($key, $value): string
    {
        $domain = WcGatewaySettings::normalizeDomain(is_scalar($value) ? (string) wp_unslash($value) : '');

        if ($domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($domain, '.')) {
            return $domain;
        }

        WC_Admin_Settings::add_error(
            $domain === ''
                ? 'Proxy domain is required.'
                : sprintf('Proxy domain "%s" is not a valid host name.', esc_html($domain))
        );

        return (string) $this->get_option($key);
    }

    /**
     * Called by WC_Settings_API::process_admin_options() for the `storefront_origin` field.
     * Stored as "scheme://host[:port]"; an invalid value is rejected and the old one kept.
     */
    public function validate_storefront_origin_field($key, $value): string
    {
        $raw    = is_scalar($value) ? trim((string) wp_unslash($value)) : '';
        $origin = WcGatewaySettings::normalizeOrigin($raw);

        if ($origin !== '') {
            return $origin;
        }

        WC_Admin_Settings::add_error(
            $raw === ''
                ? 'Storefront origin is required.'
                : sprintf('Storefront origin "%s" is not a valid origin (scheme://host[:port]).', esc_html($raw))
        );

        return (string) $this->get_option($key);
    }

    /**
     * Called by WC_Settings_API::process_admin_options() for the `client_key` field.
     */
    public function validate_client_key_field($key, $value): string
    {
        return $this->keepSecret($key, $value, 'Client key', true);
    }

    /**
     * Called by WC_Settings_API::process_admin_options() for the `shared_key` field.
     * Not required when wp-config.php sets the key: the field is ignored then.
     */
    public function validate_shared_key_field($key, $value): string
    {
        return $this->keepSecret($key, $value, 'Proxy shared key', !$this->sharedSecret->isDefinedInConfig());
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            $this->logger->error('Payment requested for a missing order.', ['order_id' => $order_id]);
            wc_add_notice('Your order could not be found. Please try again.', 'error');

            return ProcessPaymentResponse::failure()->toArray();
        }

        try {

            // From the settings only: the request has no say in test or live.
            $mode = $this->gatewaySettings->getMode();

            $tagadaToken = $this->postedString($this->id . '-token');

            // The proxy domain is not posted: no rotation, it comes from the settings.
            return PaymentMethodFactory::fromSettings($this->gatewaySettings)->processPayment($order, $mode, ['tagadaToken' => $tagadaToken])->toArray();


        } catch (PaymentProcessException $e) {
            $this->logger->error($e->getMessage(), [
                'order_id' => $order->get_id(),
                'marker'   => $e->getMarker(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);
            wc_add_notice($e->getErrorMessage(), 'error');
        } catch (Throwable $e) {
            // Throwable, not Exception: a TypeError deep in a flow must not turn the checkout into a fatal.
            $this->logger->error($e->getMessage(), [
                'order_id' => $order->get_id(),
                'trace'    => $e->getTraceAsString(),
            ]);
            wc_add_notice('The payment could not be started. Please try again or choose another payment method.', 'error');
        }

        return ProcessPaymentResponse::failure()->toArray();
    }

    /**
     * Says why the method will not appear at checkout even though it is
     * enabled - otherwise a misconfiguration only shows up as a missing option.
     */
    public function admin_options(): void
    {
        foreach ($this->configurationProblems() as $problem) {
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', wp_kses_post($problem));
        }

        parent::admin_options();
    }


    public function get_icon()
    {
        $icons = $this->gatewaySettings->getCardIcons();

        $icons_str = '';
        if (is_array($icons) && count($icons) > 0) {
            $icons = array_reverse($icons);
            foreach ($icons as $index => $icon) {
                if ($index > 3) break;
                $icons_str = '<img class="impera-payment-icon" src="' . $this->config->url . '/assets/images/icons/' . $icon . '.svg'. '"/>' . $icons_str;
            }
            if (count($icons) > 4) {
                $icons_str = '<img class="impera-payment-icon" src="' . $this->config->url . '/assets/images/icons/' . (count($icons) - 4) . '.svg' . '"/>' . $icons_str;
            }
        }
        else{
            $icons_str .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#545c6b" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"> <rect x="2" y="5" width="20" height="14" rx="2.5"/> <path d="M2 9.5h20"/> <path d="M5.5 15h3.5"/> </svg>';
        }

        return '<div class="impera-payment-icons">' . $icons_str . '</div>';
    }

    public function payment_fields()
    {
        //For custom fields
        View::render('payment-fields', [
            'isPaymentMethodAdvanced'     => $this->gatewaySettings->isPaymentMethodAdvanced(),
            'fields' => [
                $this->id . '-token',//impera_tagada_pro_headless-token
            ]
        ]);
    }

    //Render IFRAME for checkout page
    public function render_iframe() {

        $gateways = WC()->payment_gateways->get_available_payment_gateways();

        if ( empty( $gateways[ $this->id ] ) || $gateways[ $this->id ]->enabled !== 'yes' ) {
            return;
        }

        // Without the shared key there is no session token, and the proxy would
        // refuse the form anyway.
        try {
            $proxyFullUrl = Utils::buildFullProxyUrl(
                $this->gatewaySettings->getProxyDomain(),
                $this->gatewaySettings->getMode(),
                $this->sharedSecret->get()
            );
        } catch (Throwable $e) {
            $this->logger->error('Card form: ' . $e->getMessage());
            return;
        }

        View::render( 'card-form', [
            'gatewayId'                 => $this->id,
            'pluginSlug'                => $this->config->slug,
            'proxyFullUrl'              => $proxyFullUrl,
            'isPaymentMethodAdvanced'   => $this->gatewaySettings->isPaymentMethodAdvanced(),
            'countGetaways'             => count($gateways)
        ] );

    }

    /**
     * A checkout field as a sanitized string; '' when it is missing or not a
     * scalar (an array sent as `field[]=` would otherwise be a TypeError).
     * No nonce check here: WC_Checkout::process_checkout() has done it already.
     */
    private function postedString(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return '';
        }

        return Utils::parseText(wp_unslash($_POST[$key]));
    }

    /**
     * An empty list in the config means no restriction.
     */
    private function isCurrencySupported(): bool
    {
        $currencies = $this->config->availableCurrencies;

        return $currencies === [] || in_array(get_woocommerce_currency(), $currencies, true);
    }

    private function hasProxyDomain(): bool
    {
        return $this->gatewaySettings->getProxyDomain() !== '';
    }

    private function hasStorefrontOrigin(): bool
    {
        return $this->gatewaySettings->getStorefrontOrigin() !== '';
    }

    /**
     * @return string[]
     */
    private function configurationProblems(): array
    {
        $problems = [];

        if (!$this->isCurrencySupported()) {
            $problems[] = sprintf(
                'The store currency <b>%s</b> is not supported by this gateway.',
                esc_html(get_woocommerce_currency())
            );
        }

        if ($this->gatewaySettings->getClientKey() === '') {
            $problems[] = 'The client key is not set.';
        }

        if (!$this->sharedSecret->isConfigured()) {
            $problems[] = 'The proxy shared key is not set.';
        }

        if (!$this->hasProxyDomain()) {
            $problems[] = 'The proxy domain is not set.';
        }

        if (!$this->hasStorefrontOrigin()) {
            $problems[] = 'The storefront origin is not set.';
        }

        return $problems;
    }

    /**
     * With the key in wp-config.php the field is dead weight: it must not be
     * required, and the screen has to say which value is actually used.
     */
    private function adjustSharedKeyField(): void
    {
        if (!isset($this->form_fields['shared_key']) || !$this->sharedSecret->isDefinedInConfig()) {
            return;
        }

        $field = &$this->form_fields['shared_key'];

        $field['title'] = 'Proxy shared key';
        unset($field['custom_attributes']['required']);
        $field['description'] = sprintf(
            'Set in wp-config.php as <code>%s</code>; this field is ignored.',
            esc_html($this->sharedSecret->constantName())
        );
    }

    /**
     * Keys are used as-is, so only trimmed (validate_password_field) - a
     * sanitize_text_field() would silently strip characters from a secret.
     *
     * The field is rendered empty, so an empty submit means "unchanged" and
     * keeps the stored key. It is an error only when there is no key at all.
     */
    private function keepSecret(string $key, mixed $value, string $label, bool $required): string
    {
        $clean = $this->validate_password_field($key, $value);

        if ($clean !== '') {
            return $clean;
        }

        // Still the stored value here: process_admin_options() overwrites it only after this returns.
        $stored = (string) $this->get_option($key);

        if ($stored === '' && $required) {
            WC_Admin_Settings::add_error(sprintf('%s is required.', $label));
        }

        return $stored;
    }
}
