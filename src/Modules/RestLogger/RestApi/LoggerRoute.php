<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\RestLogger\RestApi;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\RestApi\Action;
use ImperaTagadaProHeadless\Core\RestApi\Contract\AbstractRoute;
use WP_REST_Request;
use WP_REST_Response;

class LoggerRoute extends AbstractRoute
{
    const BASE_URL = '/log';

    /**
     * The LoggerInterface levels a client is allowed to ask for.
     */
    private const ALLOWED_LEVELS = ['debug', 'info', 'warn', 'error'];

    private const DEFAULT_LEVEL = 'error';

    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = ImperaMain::logger();
    }

    public function defineActions(): array
    {
        return [
            Action::post(self::BASE_URL, [$this, 'log']),//Write Log
        ];
    }

    public function log(WP_REST_Request $request): WP_REST_Response
    {
        $message = $request->get_param('message');

        if (empty($message)) {
            return new WP_REST_Response([
                'success'       => false,
                'error_message' => 'The "message" field is required.'
            ], 400);
        }

        $type    = (string) ($request->get_param('type') ?? self::DEFAULT_LEVEL);
        $message = sanitize_text_field((string) $message);

        /**
         * A white list, not method_exists(): the level arrives from the client,
         * and every other public method of the logger takes a different
         * signature — 'log' alone would be called with one of its two required
         * arguments and blow up with an ArgumentCountError.
         */
        if (!in_array($type, self::ALLOWED_LEVELS, true)) {
            return new WP_REST_Response([
                'success'       => false,
                'error_message' => sprintf('The "%s" logging level is not supported.', $type)
            ], 422);
        }

        $this->logger->{$type}($message);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'The log has been successfully recorded.'
        ], 200);
    }

    public function permissionCallback(WP_REST_Request $request): bool
    {
        return true;
    }
}
