<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Support;

use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use Throwable;
use WC_Logger_Interface;

final class WcLogger implements LoggerInterface
{
    // PSR-3 levels as plain strings, so this class does not need WooCommerce loaded
    private const DEBUG   = 'debug';
    private const INFO    = 'info';
    private const WARNING = 'warning';
    private const ERROR   = 'error';

    private const VALID_LEVELS = [
        'emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug',
    ];

    private ?WC_Logger_Interface $wcLogger;
    private ?LoggerInterface $fallback;

    /** @var array{source: string} */
    private readonly array $baseContext;

    public function __construct(
        private readonly string $source = 'impera-tagada-pro-headless',
        ?WC_Logger_Interface $wcLogger = null,
        ?LoggerInterface $fallback = null,
    ) {
        $this->wcLogger = $wcLogger;
        $this->fallback = $fallback;
        $this->baseContext = ['source' => $source];
    }

    public function log(string $level, mixed $message, array $context = []): void
    {
        $context = array_merge($this->baseContext, $context);
        $wcLogger = $this->resolveWcLogger();

        // WooCommerce is not loaded yet - fall back to the file logger
        if ($wcLogger === null) {
            $this->resolveFallback()->log($level, $message, $context);
            return;
        }

        $wcLogger->log(
            $this->normalizeLevel($level),
            $this->formatMessage($message),
            $context,
        );
    }

    public function debug(mixed $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(mixed $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warn(mixed $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(mixed $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    private function resolveWcLogger(): ?WC_Logger_Interface
    {
        if ($this->wcLogger instanceof WC_Logger_Interface) {
            return $this->wcLogger;
        }

        if (!function_exists('wc_get_logger')) {
            return null;
        }

        return $this->wcLogger = \wc_get_logger();
    }

    private function resolveFallback(): LoggerInterface
    {
        // Built lazily: only needed while WC is unavailable
        return $this->fallback ??= new Logger();
    }

    /**
     * WC expects lowercase PSR-3 levels. Maps 'warn' and the file logger's
     * old uppercase constants; anything unknown becomes info.
     */
    private function normalizeLevel(string $level): string
    {
        $normalized = strtolower($level);

        if ($normalized === 'warn') {
            $normalized = self::WARNING;
        }

        return in_array($normalized, self::VALID_LEVELS, true)
            ? $normalized
            : self::INFO;
    }

    private function formatMessage(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Throwable) {
            return sprintf(
                'Exception %s: %s in %s:%d%sTrace: %s',
                $message::class,
                $message->getMessage(),
                $message->getFile(),
                $message->getLine(),
                PHP_EOL,
                $message->getTraceAsString(),
            );
        }

        if (is_array($message) || is_object($message)) {
            return (string) json_encode(
                $message,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return var_export($message, true);
    }
}
