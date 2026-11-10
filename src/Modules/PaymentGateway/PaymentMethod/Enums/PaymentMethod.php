<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\PaymentMethod\Enums;

/**
 * Values match the options of the `checkout_flow` gateway setting.
 */
enum PaymentMethod: string
{

    case REDIRECT = 'redirect';
    case ADVANCED = 'advanced';

    /**
     * The only flow built for Tagada so far (the settings offer nothing else);
     * REDIRECT is kept for when the hosted checkout is added.
     */
    public const DEFAULT = self::ADVANCED;

    /**
     * Unknown or empty values (a stale setting, a hand-edited option) fall back to DEFAULT.
     */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::DEFAULT) : self::DEFAULT;
    }
}
