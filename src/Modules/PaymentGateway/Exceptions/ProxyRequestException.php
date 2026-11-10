<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions;

use Exception;
use Throwable;

/**
 * A call to the proxy that did not produce a usable answer: transport failure,
 * non-2xx status or a body that is not JSON.
 *
 * The code is the HTTP status (0 for a transport failure); errorCode is the
 * proxy's own error_code (invalid_signature, merchant_not_allowed,
 * create_order_failed…) when it sent one. The message is for the log, never
 * for the customer.
 */
final class ProxyRequestException extends Exception
{
    public function __construct(
        string $message,
        int $httpStatus = 0,
        private readonly string $errorCode = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
