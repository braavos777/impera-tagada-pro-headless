<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Contract;

use ImperaTagadaProHeadless\Core\Notification\NotificationMessage;

interface NotificationInterface
{

    public function channel(): string;

    public function notify(NotificationMessage $message): void;

}
