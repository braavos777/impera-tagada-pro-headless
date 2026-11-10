<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Support;

use DateTime;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use RuntimeException;
use Throwable;

class Logger implements LoggerInterface
{
    private string $logFile;
    private string $dateFormat = 'Y-m-d H:i:s.u';

    // Logging levels for filtering and tagging
    public const DEBUG    = 'DEBUG';
    public const INFO     = 'INFO';
    public const NOTICE   = 'NOTICE';
    public const WARNING  = 'WARNING';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /**
     * @param string|null $filePath Path to the log file. Defaults to a dated
     *                              file in the uploads directory.
     */
    public function __construct(?string $filePath = null)
    {
        $this->logFile = $filePath ?? self::defaultLogFile();

        // Create a folder for logs if there is no one
        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir))
            {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }

        $this->protectDirectory($dir);
    }

    /**
     * Logs live under wp-content/uploads, not inside the plugin: a directory
     * shipped with the plugin is wiped on every update, and the .htaccess that
     * used to guard it does nothing on nginx.
     */
    private static function defaultLogFile(): string
    {
        $suffix = function_exists('wp_hash') ? '_' . wp_hash(date('Y-m')) : '';

        $fileName = sprintf('log_%s%s.log', date('Y-m-d'), $suffix);

        if (function_exists('wp_get_upload_dir')) {
            $uploads = wp_get_upload_dir();

            if (empty($uploads['error']) && !empty($uploads['basedir'])) {
                return rtrim($uploads['basedir'], '/\\')
                    . DIRECTORY_SEPARATOR . 'impera-logs'
                    . DIRECTORY_SEPARATOR . $fileName;
            }
        }

        // Uploads are unavailable this early — fall back to the plugin folder.
        return __DIR__ . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * Belt and braces for the Apache case; the unguessable file name is what
     * actually protects the log on nginx.
     */
    private function protectDirectory(string $dir): void
    {
        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';

        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';

        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * The main method of logging
     * @param string $level Level of Importance (INFO, ERROR)
     * @param mixed $message Message (string, array, object, exception)
     * @param array $context Additional data (variables, headers, session)
     */
    public function log(string $level, mixed $message, array $context = []): void
    {
        $timestamp = (new DateTime())->format($this->dateFormat);

        // Formatting the main message
        $formattedMessage = $this->formatMessage($message);

        // Format the context (if passed)
        $formattedContext = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        // Assembling the final line
        $logLine = sprintf("[%s] [%s]: %s%s%s", $timestamp, $level, $formattedMessage, $formattedContext, PHP_EOL);

        // Secure write to a locked file (simultaneous write protection)
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    // Quick helper methods for different levels
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

    /**
     * Converts any data type to a readable string view
     */
    private function formatMessage(mixed $message): string {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Throwable) {
            return sprintf("Exception %s: %s in the file %s:%s. Trace: %s",
                get_class($message), $message->getMessage(), $message->getFile(), $message->getLine(), $message->getTraceAsString()
            );
        }

        if (is_array($message) || is_object($message)) {
            return json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return var_export($message, true);
    }
}
