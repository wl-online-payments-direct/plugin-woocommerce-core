<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\Refund;

class RefundReason
{
    public const WRONG_AMOUNT_CORRECTION = 'WrongAmountCorrection';
    public const PRE_DISPUTE_REFUND = 'PreDisputeRefund';
    public const SUBSCRIPTION_REFUND = 'SubscriptionRefund';
    public const SERVICE_LATE_CANCELLATION = 'ServiceLateCancellation';
    public const OTHER = 'Other';
    public const DEFAULT = self::WRONG_AMOUNT_CORRECTION;
    public static function values(): array
    {
        return [self::WRONG_AMOUNT_CORRECTION, self::PRE_DISPUTE_REFUND, self::SUBSCRIPTION_REFUND, self::SERVICE_LATE_CANCELLATION, self::OTHER];
    }
    public static function resolve(?string $reason): string
    {
        return in_array($reason, self::values(), \true) ? $reason : self::DEFAULT;
    }
}
