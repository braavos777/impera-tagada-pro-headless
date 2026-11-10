<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Hooks;

use ImperaTagadaProHeadless\Core\Support\StringUtil;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentGateway;

final class AssetsRegister
{
    public function __construct(private readonly PaymentGateway $gateway)
    {}

    public function registerAdmin(): void
    {
        $screen    = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
            return;
        }


    }


    public function registerCheckout(): void
    {
        if ( ! is_checkout() ||
            is_order_received_page() ||
            is_wc_endpoint_url( 'order-pay' ) ||
            ! $this->gateway->is_available() ||
            WC()->cart->is_empty()
        ) {
            return;
        }

        wp_enqueue_style( $this->gateway->config->handle('checkout-css'), $this->gateway->config->asset('css/impera-checkout.css'), [], time());

        $this->registerCardForm();
    }

    public function registerOrderPay()
    {
        if (!is_wc_endpoint_url( 'order-pay' ) || !$this->gateway->is_available()) {
            return;
        }

        wp_enqueue_style( $this->gateway->config->handle('checkout-css'), $this->gateway->config->asset('css/impera-checkout.css'), [], time());

        $this->registerCardForm();

    }

    /**
     * Only the "Card Form" variant embeds the proxy iframe; the redirect variant
     * has no frame to talk to.
     */
    private function registerCardForm(): void
    {

        if (!$this->gateway->gatewaySettings->isPaymentMethodAdvanced()) {
            return;
        }

        wp_enqueue_script(
            $this->gateway->config->handle('mj-bridge'),
            $this->gateway->config->asset('js/mj-bridge.js'),
            ['jquery'],
            time(),
            true
        );

        wp_enqueue_script(
            $this->gateway->config->handle('card-form-js'),
            $this->gateway->config->asset('js/card-form.js'),
            ['jquery', $this->gateway->config->handle('mj-bridge')],
            time(),
            true
        );


        wp_localize_script(
            $this->gateway->config->handle('card-form-js'),
            StringUtil::camel($this->gateway->id),
            [
                'gatewayId'       => $this->gateway->id,
                // The bridge takes the proxy origin from the iframe itself (see card-form.js).
                'frameId'         => $this->gateway->config->slug . '-area',
                'nonce'           => wp_create_nonce('wp_rest'),
                'baseApiUrl'      => esc_url_raw(rest_url($this->gateway->config->restNamespace)),
            ]
        );
    }
}
