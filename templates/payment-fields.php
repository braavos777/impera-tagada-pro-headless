<?php

/**
 * @var array $fields
 * @var bool $isPaymentMethodAdvanced
 */
?>
<?php if ($isPaymentMethodAdvanced && count($fields)): ?>
    <div id="impera-tagada-pro-headless-fields">
        <?php foreach ($fields as $fieldName) : ?>
            <input type="hidden" name="<?php echo $fieldName; ?>" >
        <?php endforeach; ?>
    </div>
<?php endif; ?>

