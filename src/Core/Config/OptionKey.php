<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Config;

/**
 * WP option names, stored as the suffix only. See MetaKey for why.
 */
enum OptionKey: string
{
    case SharedKey = 'shared_key';
}
