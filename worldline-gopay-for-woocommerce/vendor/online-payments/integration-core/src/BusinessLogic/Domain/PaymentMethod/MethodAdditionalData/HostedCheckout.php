<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Sepa\SignatureType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
/**
 * Class HostedCheckout
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData
 */
class HostedCheckout implements PaymentMethodAdditionalData
{
    protected string $logo;
    protected bool $enableGroupCards;
    protected ThreeDSSettings $threeDSSettings;
    /**
     * SEPA Direct Debit mandate signature type used when the customer picks SEPA Direct Debit on the
     * hosted checkout page or on a payment link page. Separate from the SEPA Direct Debit method's own
     * signature type, which applies when SEPA Direct Debit is selected at checkout.
     */
    protected SignatureType $signatureType;
    public function __construct(string $logo, bool $enableGroupCards, ?ThreeDSSettings $threeDSSettings = null, ?SignatureType $signatureType = null)
    {
        $this->logo = $logo;
        $this->enableGroupCards = $enableGroupCards;
        $this->threeDSSettings = $threeDSSettings ?: new ThreeDSSettings();
        $this->signatureType = $signatureType ?: SignatureType::sms();
    }
    public function getLogo(): string
    {
        return $this->logo;
    }
    public function setLogo(string $logo): void
    {
        $this->logo = $logo;
    }
    public function isEnableGroupCards(): bool
    {
        return $this->enableGroupCards;
    }
    public function getThreeDSSettings(): ThreeDSSettings
    {
        return $this->threeDSSettings;
    }
    public function getSignatureType(): SignatureType
    {
        return $this->signatureType;
    }
}
