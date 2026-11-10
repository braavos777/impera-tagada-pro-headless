<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Notification;

use ImperaTagadaProHeadless\Core\Contract\NotificationInterface;
use InvalidArgumentException;

final class NotificationManager
{
    /**
     * @var array<string, NotificationInterface>
     */
    private array $channels = [];

    /**
     * @param iterable<NotificationInterface> $notifications
     */
    public function __construct(iterable $notifications)
    {
        foreach ($notifications as $notification) {
            $this->channels[$notification->channel()] = $notification;
        }
    }

    public function notify(NotificationMessage $message): void
    {
        foreach ($this->channels as $channel) {
            $channel->notify($message);
        }
    }

    public function notifyTo(string $channel, NotificationMessage $message): void
    {
        if (!$this->hasChannel($channel)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Notification channel "%s" not found.',
                    $channel
                )
            );
        }

        $this->channels[$channel]->notify($message);
    }

    public function hasChannel(string $channel): bool
    {
        return isset($this->channels[$channel]);
    }

    public function channels(): array
    {
        return array_keys($this->channels);
    }
}
