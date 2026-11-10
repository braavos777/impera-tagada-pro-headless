<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return [
    'payment_fields' => [
        'enabled'               => [
            'title'       => 'Enable/Disable',
            'label'       => 'Enable Impera Tagada Gateway',
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no',
        ],
        'proxy_domain'          => [
            'title'       => 'Proxy domain <sup style="color: red">*</sup>',
            'type'        => 'text',
            'description' => 'Domain of the proxy site running impera-tagada-gateway, without https://, e.g. proxy.example.com. The card form is loaded from it and payments are captured through it.',
            'default'     => '',
            'placeholder' => 'proxy.example.com',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'storefront_origin'     => [
            'title'       => 'Storefront origin <sup style="color: red">*</sup>',
            'type'        => 'text',
            'description' => 'Origin of the headless storefront that frames the card form, with its scheme and, when it is not the default, its port — <code>https://shop.example.com</code> or <code>http://localhost:3000</code>. Its host is encrypted into the card form link, and the proxy lets only that host frame the form, so the host has to be on the proxy\'s Allowed Domains. A storefront on a non-default port (localhost:3000) also needs its origin in the proxy\'s storefront origins. Without this field the payment method is not offered at checkout.',
            'default'     => '',
            'placeholder' => 'https://shop.example.com',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'title'                 => [
            'title'       => 'Title',
            'type'        => 'text',
            'description' => 'This controls the title which the user sees during checkout.',
            'default'     => 'Tagada',
            'desc_tip'    => true,
        ],
        'description'           => [
            'title'       => 'Description',
            'type'        => 'textarea',
            'description' => 'This controls the description which the user sees during checkout.',
            'default'     => 'You will be redirected to Tagada to complete your order.',
        ],
        'checkout_button_content' => [
            'title'   => 'Checkout button text',
            'type'    => 'text',
            'default' => 'Continue to Tagada',
        ],
        'checkout_flow'         => [
            'title'       => 'Checkout flow',
            'type'        => 'select',
            'default'     => 'advanced',
            'options'     => [
                'advanced'   => 'Embedded card fields',
            ],
            'description' => '',
        ],
        'test_mode'             => [
            'title'       => 'Test mode',
            'label'       => 'Enable test mode',
            'type'        => 'checkbox',
            'description' => 'Every payment is sent to the proxy with mode=test. Turn it off for real payments.',
            'default'     => 'no',
        ],
        'client_key'            => [
            'title'       => 'Client key <sup style="color: red">*</sup>',
            'type'        => 'password',
            'description' => '',
            'default'     => '',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'shared_key'            => [
            'title'       => 'Proxy shared key <sup style="color: red">*</sup>',
            'type'        => 'password',
            'description' => 'It signs every request the shop sends and verifies every payment the proxy reports back.',
            'default'     => '',
            'custom_attributes' => [
                'required'  => 'required'
            ],
        ],
        'card_icons' => array(
            'type' => 'multiselect',
            'title' => 'Show payment icons',
            'class' => 'wc-enhanced-select',
            'default' => array('visa', 'mastercard', 'american_express', 'discover', 'diners', 'jcb'),
            'options' => array(
                'visa' => 'Visa',
                'paypal' => 'Paypal',
                'mastercard' => 'MasterCard',
                'jcb' => 'JCB',
                'discover' => 'Discover',
                'diners' => 'Diners Club',
                'american_express' => 'American Express',
            ),
            'desc_tip' => true,
            'description' => 'The selected icons will show customers which credit card brands you accept.',
        ),
        'invoice_prefix'        => [
            'title'       => 'Invoice Prefix',
            'type'        => 'text',
            'description' => 'Prefix added to the order number in the proxy statistics. It is not sent to Tagada as an invoice id — Tagada requires that to be unique across the whole account and rejects a retried payment that reuses one.',
            'default'     => 'WC-',
            'desc_tip'    => true,
        ],
        'product_title_setting' => [
            'title'       => 'Overwrite product title',
            'type'        => 'text',
            'description' => 'Applied to each item name in the order description. Supports <b>[order_id]</b>, <b>[last_word]</b>, <b>[keep_original]</b> and <b>[rand_N]</b> (random string of N characters).',
            'default'     => 'Order [order_id] item [rand_7]',
        ]
    ]
];
