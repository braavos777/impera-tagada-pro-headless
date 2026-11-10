<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Notification\Chanels;

use ImperaTagadaProHeadless\Core\Contract\NotificationInterface;
use ImperaTagadaProHeadless\Core\Notification\NotificationMessage;

final class EmailNotification implements NotificationInterface
{
    public function channel(): string
    {
        return 'email';
    }

    public function notify(NotificationMessage $message): void
    {
        wp_mail(
            get_option('admin_email'),
            $message->title,
            $message->message
        );
    }
}
