<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\Exceptions\InvalidExemptionTypeException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;
/**
 * Class ExemptionType
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\CreditCard
 */
class ExemptionType
{
    public const LOW_VALUE = 'low-value';
    public const TRANSACTION_RISK_ANALYSIS = 'transaction-risk-analysis';
    /**
     * A preference, not an exemption claim: asks the issuer not to challenge the customer.
     */
    public const NO_CHALLENGE_REQUESTED = 'no-challenge-requested';
    /**
     * `threeDSecure.exemptionRequest` sent for the no-challenge preference.
     */
    private const EXEMPTION_REQUEST_NONE = 'none';
    private const CHALLENGE_INDICATOR_NO_CHALLENGE = 'no-challenge-requested';
    private const CHALLENGE_INDICATOR_NO_CHALLENGE_RISK_ANALYSIS = 'no-challenge-requested-risk-analysis-performed';
    protected string $type;
    /**
     * @param string $type
     */
    private function __construct(string $type)
    {
        $this->type = $type;
    }
    public static function lowValue(): self
    {
        return new self(self::LOW_VALUE);
    }
    public static function transactionRiskAnalysis(): self
    {
        return new self(self::TRANSACTION_RISK_ANALYSIS);
    }
    public static function noChallengeRequested(): self
    {
        return new self(self::NO_CHALLENGE_REQUESTED);
    }
    /**
     * @param string $state
     *
     * @return ExemptionType
     *
     * @throws InvalidExemptionTypeException
     */
    public static function fromState(string $state): ExemptionType
    {
        if ($state === self::TRANSACTION_RISK_ANALYSIS) {
            return new self(self::TRANSACTION_RISK_ANALYSIS);
        }
        if ($state === self::LOW_VALUE) {
            return new self(self::LOW_VALUE);
        }
        if ($state === self::NO_CHALLENGE_REQUESTED) {
            return new self(self::NO_CHALLENGE_REQUESTED);
        }
        throw new InvalidExemptionTypeException(new TranslatableLabel('Invalid exemption type. Exemption type must be "low-value", "transaction-risk-analysis" or "no-challenge-requested"', 'payment.invalidExemptionType'));
    }
    public function getType(): string
    {
        return $this->type;
    }
    /**
     * Value of `threeDSecure.exemptionRequest` when this exemption applies.
     *
     * @return string
     */
    public function getExemptionRequest(): string
    {
        return $this->type === self::NO_CHALLENGE_REQUESTED ? self::EXEMPTION_REQUEST_NONE : $this->type;
    }
    /**
     * Value of `threeDSecure.challengeIndicator` when this exemption applies.
     *
     * @return string
     */
    public function getChallengeIndicator(): string
    {
        return $this->type === self::TRANSACTION_RISK_ANALYSIS ? self::CHALLENGE_INDICATOR_NO_CHALLENGE_RISK_ANALYSIS : self::CHALLENGE_INDICATOR_NO_CHALLENGE;
    }
    public function equals(self $other): bool
    {
        return $this->type === $other->getType();
    }
}
