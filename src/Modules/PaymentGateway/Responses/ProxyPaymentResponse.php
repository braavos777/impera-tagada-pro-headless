<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Responses;

use ImperaTagadaProHeadless\Core\Support\Calculator;
use ImperaTagadaProHeadless\Core\Support\ArrayUtil;


final class ProxyPaymentResponse
{
    public const STATUS_SUCCEEDED = 'succeeded';

    //Failed
    public const STATUS_DECLINED = 'declined';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ERROR = 'error';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    //Pending
    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_PENDING = 'pending';

    //Refunded
    public const STATUS_REFUNDED = 'refunded';


    public const FAILED_STATUSES = [
        self::STATUS_DECLINED,
        self::STATUS_REJECTED,
        self::STATUS_ERROR,
        self::STATUS_EXPIRED,
        self::STATUS_FAILED,
    ];

    public const PENDING_STATUSES = [
        self::STATUS_ON_HOLD,
        self::STATUS_PENDING,
    ];

    private function __construct(
        private readonly bool $success,
        private readonly string $status,
        private readonly string $subStatus,
        private readonly int $wcOrderId,
        private readonly int $amountMinor,
        private readonly string $currency,
        private readonly ?string $transactionId,
        private readonly ?string $requireAction = null,
        private readonly ?string $errorMessage = null,
        private readonly ?string $event = null,
        private readonly string $attemptId = '',
        private readonly string $mode = '',
        private readonly string $state = '',
    )
    {}

    /**
     * Tolerates a body with fields missing: the push route hands over whatever
     * arrived, and a missing key must not become a warning on the way in.
     */
    public static function fromProxyResponse(array $response): self
    {

        return new self(
            success:       ArrayUtil::getValue($response, 'success', false),
            // No status is no outcome - never read as a failure: a payment
            // may have been taken. It matches no status group, so nothing
            // is applied and the capture path treats it as unknown.
            status:        (string) ArrayUtil::getValue($response, 'status', ''),
            subStatus:     (string) ArrayUtil::getValue($response, 'subStatus', ''),
            wcOrderId:     ArrayUtil::getValue($response, 'wcOrderId', 0),
            transactionId: ArrayUtil::getValue($response, 'transactionId', null),
            amountMinor:   (int) ArrayUtil::getValue($response, 'amountMinor', 0),
            // Nothing missing is assumed: the validator refuses an answer
            // without a currency or a mode.
            currency:      strtoupper((string)  ArrayUtil::getValue($response, 'currency', '')),
            requireAction: (string)  ArrayUtil::getValue($response, 'requireAction', null),
            errorMessage:  ArrayUtil::getValue($response, 'errorMessage', null),
            event:         ArrayUtil::getValue($response, 'event', null),
            attemptId:     (string) ArrayUtil::getValue($response, 'attempt_id', ''),
            mode:          (string) ArrayUtil::getValue($response, 'mode', ''),
            state:         (string) ArrayUtil::getValue($response, 'state', ''),
        );
    }

    public function getAttemptId(): string
    {
        return $this->attemptId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * captured | not_captured | unknown - answered by the status check only.
     */
    public function getState(): string
    {
        return $this->state;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getWcOrderId(): string|int
    {
        return $this->wcOrderId;
    }


    public function getSubStatus(): string
    {
        return $this->subStatus;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRequireAction(): ?string
    {
        return $this->requireAction;
    }


    /** The webhook event behind a push, null for a direct answer. */
    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return in_array($this->status, self::FAILED_STATUSES, true);
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }


    public function getGrossAmount(bool $inMinorUnits = false): string|int
    {
        return $inMinorUnits
        ? $this->amountMinor
        : Calculator::fromMinor($this->amountMinor, $this->getCurrency());
    }

}
