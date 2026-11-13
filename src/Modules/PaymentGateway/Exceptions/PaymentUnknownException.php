<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions;

use RuntimeException;

/**
 * The charge was sent, but whether Tagada took the money is not known: the
 * proxy did not answer, answered 502 outcome_unknown, or named an earlier
 * attempt of the same order that is paid or still running (409).
 *
 * Never a reason to fail the order - the buyer would pay a second time. The
 * order is held and PaymentReconciler finds out.
 *
 * $attemptId / $transactionId name the attempt to reconcile when it is not
 * the one stored on the order (the proxy named the attempt that blocked).
 */
final class PaymentUnknownException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $attemptId = null,
        public readonly ?string $transactionId = null,
    ) {
        parent::__construct($message);
    }
}
