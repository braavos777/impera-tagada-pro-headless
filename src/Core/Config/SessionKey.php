<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Config;

/**
 * WC session keys, stored as the suffix only. See MetaKey for why.
 */
enum SessionKey: string
{
    case ReturnFlag = 'return_flag';

    case CancelFlag = 'cancel_flag';
}
