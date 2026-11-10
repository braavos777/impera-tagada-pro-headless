
<?php
/**
 * @var bool $isPaymentMethodAdvanced
 * @var string $gatewayId
 * @var string $pluginSlug
 * @var string $proxyFullUrl
 * @var int $countGetaways
 */
?>

<style>
	#payment:has(#payment_method_<?php echo esc_attr($gatewayId); ?>:checked) #place_order,
	.wc-block-checkout:has(#<?php echo $pluginSlug ?>-credit-form-container) .wc-block-components-checkout-place-order-button {
		display: none !important;
	}
</style>
<div id="<?php echo $pluginSlug ?>-credit-form-container">
    <?php if ($isPaymentMethodAdvanced): ?>
        <iframe
                id="<?php echo $pluginSlug ?>-area"
                referrerpolicy="no-referrer"
                src="<?php echo esc_url($proxyFullUrl); ?>"
                height="370"
                frameBorder="0"
                style="width: 100%"></iframe>
        <div id="<?php echo $pluginSlug ?>-iframe-overlay"></div>
    <?php endif; ?>

</div>
