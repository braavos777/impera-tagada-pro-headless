<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Notification;

final readonly class NotificationMessage
{
    public function __construct(
        public string $title,
        public string $message,
        public array $context = [],
    )
    {}
}
