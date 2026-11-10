<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Support;

use RuntimeException;
use Throwable;

class View
{
    /**
     * Base path to views directory.
     *
     * Set once while booting, via setViewPath(): a static initializer can only
     * hold a constant expression, which is what used to tie this class to one
     * plugin's IMPERA_*_VIEW_PATH.
     */
    protected static ?string $viewPath = null;

    /**
     * Render PHP template directly.
     *
     * Example:
     * View::render('pages/home', [
     *     'title' => 'Homepage'
     * ]);
     */
    public static function render(string $template, array $data = []): void
    {
        $template = ltrim($template, '/\\');

        /**
         * Full template path.
         */
        if (self::$viewPath === null) {
            throw new RuntimeException(
                'View path is not set: call View::setViewPath() while booting the plugin.'
            );
        }

        $viewPath = rtrim(self::$viewPath, '/\\');

        $path = $viewPath . DIRECTORY_SEPARATOR . $template . '.php';

        /**
         * Check template exists.
         */
        if (!file_exists($path)) {
            throw new RuntimeException(
                sprintf('View template not found: %s', $path)
            );
        }

        /**
         * Real protection against path traversal: stripping a leading slash
         * does nothing about '../', so the resolved path is compared with the
         * view directory it must live under.
         */
        $realPath = realpath($path);
        $realRoot = realpath($viewPath);

        if ($realPath === false || $realRoot === false
            || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(
                sprintf('View template is outside the view directory: %s', $template)
            );
        }

        $path = $realPath;

        /**
         * Extract variables for template.
         *
         * EXTR_SKIP prevents overwriting existing variables.
         */
        extract($data, EXTR_SKIP);

        /**
         * Import WordPress query vars.
         *
         * Example:
         * ?paged=2
         */
        global $wp_query;

        if (isset($wp_query->query_vars) && is_array($wp_query->query_vars)) {
            extract($wp_query->query_vars, EXTR_SKIP);
        }

        /**
         * Include template.
         */
        include $path;
    }

    /**
     * Render template and return HTML as string.
     */
    public static function getRender(
        string $template,
        array $data = []
    ): string {
        ob_start();

        try {

            self::render($template, $data);

            return ob_get_clean();

        } catch (Throwable $e) {

            ob_end_clean();

            throw $e;
        }
    }

    /**
     * Change views path dynamically.
     */
    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/\\');
    }
}
