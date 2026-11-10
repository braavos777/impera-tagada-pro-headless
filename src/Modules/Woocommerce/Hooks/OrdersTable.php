<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\Woocommerce\Hooks;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use WC_Order;

class OrdersTable
{

    /**
     * Column name suffix. The prefix is the gateway id from the config, so this
     * class carries no plugin name of its own and two copies don't collide.
     */
    private const COLUMN_SUFFIX = '_proxy_source';

    private readonly string $column;

    public function __construct(private readonly PluginConfig $config)
    {
        $this->column = $this->config->gatewayId . self::COLUMN_SUFFIX;

        // HPOS (custom order tables)
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addColumn']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'addColumnContent'], 10, 2);

        /**
         * Legacy post-based order list. HPOS is declared compatible, not
         * required, so a store that has not migrated still gets the column.
         */
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'addLegacyColumnContent'], 10, 2);
    }

    public function addColumn(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            // 'order_status' on HPOS, 'order_status' on the legacy table too.
            if ($key === 'order_status') {
                $new[$this->column] = 'Tagada Proxy/Payment Source';
            }
        }

        // Nothing to anchor to (filtered column set) — append instead of dropping it.
        if (!isset($new[$this->column])) {
            $new[$this->column] = 'Tagada Proxy/Payment Source';
        }

        return $new;
    }

    public function addColumnContent(string $column, WC_Order $order): void
    {
        if ($column !== $this->column) {
            return;
        }

        $content = $order->get_meta($this->config->meta(MetaKey::ProxyDomain))
            . ' / '
            . $order->get_meta($this->config->meta(MetaKey::PaymentSource));

        echo esc_html($content);
    }

    /**
     * Legacy hook passes a post id instead of an order object.
     */
    public function addLegacyColumnContent(string $column, int $postId): void
    {
        if ($column !== $this->column) {
            return;
        }

        $order = wc_get_order($postId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->addColumnContent($column, $order);
    }

}
