<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Config;

/**
 * WC order meta keys, stored as the suffix only.
 *
 * The plugin prefix cannot live here: enum case values must be compile-time
 * constant expressions, so `case OrderId = PREFIX . '_order_id'` is not valid
 * PHP. Keeping only the suffix is what makes this file portable as is — the
 * prefix comes from PluginConfig::meta().
 */
enum MetaKey: string
{
    case ProxyDomain   = 'proxy_domain';

    case OrderId       = 'order_id';

    case TransactionId = 'transaction_id';

    case RefundId      = 'refund_id';

    case GrossAmount   = 'gross_amount';

    case PaymentSource = 'payment_source';

    case PaymentFlow   = 'payment_flow';

    case Test          = 'test';

    case RefundUser    = 'refund_user';

    /** The proxy status last applied (ProxyPaymentResponse::STATUS_*). */
    case PaymentStatus = 'payment_status';

    /** Who confirmed the payment first (ApplyPaymentResult::SOURCE_*) and when. */
    case PaidSource    = 'paid_source';


}
