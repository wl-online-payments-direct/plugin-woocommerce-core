<?php

namespace WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\CreditCard;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ExemptionType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputBase;
use WOP\OnlinePayments\Sdk\Domain\PaymentProduct130SpecificInput;
use WOP\OnlinePayments\Sdk\Domain\PaymentProduct130SpecificThreeDSecure;
use WOP\OnlinePayments\Sdk\Domain\RedirectionData;
use WOP\OnlinePayments\Sdk\Domain\ThreeDSecure;
/**
 * Class CardPaymentMethodSpecificInputTransformer.
 *
 * @package OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers
 */
class CardPaymentMethodSpecificInputTransformer
{
    private const CHALLENGE_REQUIRED = 'challenge-required';
    /**
     * Narrows the card input to the shape the hosted flows accept.
     *
     * `CreateHostedCheckoutRequest` and `CreatePaymentLinkRequest` take a
     * `CardPaymentMethodSpecificInputBase`, which is not a parent of `CardPaymentMethodSpecificInput`
     * (both extend `DataObject`), so the object is rebuilt through the SDK's own
     * `toObject()`/`fromObject()` pair. Each class copies only the fields it declares; the dropped
     * ones (chiefly `threeDSecure.redirectionData`) have no meaning on the hosted flows, which carry
     * the return URL in `hostedCheckoutSpecificInput.returnUrl`.
     *
     * @param CardPaymentMethodSpecificInput $input
     *
     * @return CardPaymentMethodSpecificInputBase
     */
    public static function toHostedInput(CardPaymentMethodSpecificInput $input): CardPaymentMethodSpecificInputBase
    {
        $hostedInput = new CardPaymentMethodSpecificInputBase();
        $hostedInput->fromObject($input->toObject());
        return $hostedInput;
    }
    public static function transform(Cart $cart, string $getReturnUrl, ThreeDSSettings $cardsSettings, PaymentSettings $paymentSettings, ?PaymentMethodCollection $paymentMethodCollection = null, ?PaymentProductId $paymentProductId = null, ?Token $token = null, ?PaymentAction $paymentAction = null): CardPaymentMethodSpecificInput
    {
        $paymentMethodConfig = $paymentProductId !== null ? $paymentMethodCollection->get($paymentProductId) : null;
        $cardPaymentMethodSpecificInput = new CardPaymentMethodSpecificInput();
        if (null !== $token) {
            $cardPaymentMethodSpecificInput->setToken($token->getTokenId());
        }
        $redirectionData = new RedirectionData();
        $redirectionData->setReturnUrl($getReturnUrl);
        $threeDSecure = new ThreeDSecure();
        $threeDSecure->setRedirectionData($redirectionData);
        $threeDSecure->setSkipAuthentication(!$cardsSettings->isEnable3ds());
        if (null !== $paymentProductId && PaymentProductId::maestro()->equals($paymentProductId)) {
            $threeDSecure->setSkipAuthentication(\false);
        }
        if ($cardsSettings->isEnable3ds() && $cardsSettings->isEnforceStrongAuthentication()) {
            $threeDSecure->setChallengeIndicator(self::CHALLENGE_REQUIRED);
        }
        $exemption = self::applicableExemption($cart, $cardsSettings);
        if (null !== $exemption) {
            $threeDSecure->setChallengeIndicator($exemption->getChallengeIndicator());
            $threeDSecure->setExemptionRequest($exemption->getExemptionRequest());
            $threeDSecure->setSkipAuthentication(\false);
            $threeDSecure->setSkipSoftDecline(\false);
        }
        if ($cardsSettings->isEnable3ds()) {
            $paymentProduct130SpecificInput = new PaymentProduct130SpecificInput();
            $paymentProduct130ThreeDSecure = new PaymentProduct130SpecificThreeDSecure();
            $paymentProduct130ThreeDSecure->setUsecase('single-amount');
            $paymentProduct130ThreeDSecure->setNumberOfItems(min($cart->getLineItems()->getQuantitySum(), 99));
            $paymentProduct130ThreeDSecure->setAcquirerExemption(null !== $exemption && $exemption->equals(ExemptionType::transactionRiskAnalysis()));
            $paymentProduct130SpecificInput->setThreeDSecure($paymentProduct130ThreeDSecure);
            $cardPaymentMethodSpecificInput->setPaymentProduct130SpecificInput($paymentProduct130SpecificInput);
        }
        $cardPaymentMethodSpecificInput->setThreeDSecure($threeDSecure);
        if ($paymentProductId !== null && $paymentProductId->equals(PaymentProductId::illicado()->getId())) {
            return $cardPaymentMethodSpecificInput;
        }
        $cardPaymentMethodSpecificInput->setAuthorizationMode($paymentSettings->getPaymentAction()->getType());
        if ($paymentAction) {
            $cardPaymentMethodSpecificInput->setAuthorizationMode($paymentAction->getType());
        }
        if ($paymentMethodConfig && $paymentMethodConfig->getPaymentAction()) {
            $cardPaymentMethodSpecificInput->setAuthorizationMode($paymentMethodConfig->getPaymentAction()->getType());
        }
        if ($paymentMethodConfig && $paymentMethodConfig->getAdditionalData() instanceof CreditCard) {
            $effectiveAction = $paymentMethodConfig->getPaymentAction() ?? $paymentAction ?? $paymentSettings->getPaymentAction();
            if ($effectiveAction->getType() === PaymentAction::AUTHORIZE) {
                $cardPaymentMethodSpecificInput->setAuthorizationMode($paymentMethodConfig->getAdditionalData()->getAuthorizationMode()->getType());
            }
        }
        if ($paymentProductId !== null && $paymentProductId->equals(PaymentProductId::mealvouchers()->getId())) {
            $cardPaymentMethodSpecificInput->setAuthorizationMode(PaymentAction::authorizeCapture()->getType());
        }
        if ($paymentProductId !== null && $paymentProductId->equals(PaymentProductId::chequeVacancesConnect()->getId())) {
            $cardPaymentMethodSpecificInput->setAuthorizationMode(PaymentAction::authorizeCapture()->getType());
        }
        if ($paymentProductId !== null && $paymentProductId->isCardType() && !$paymentProductId->equals(PaymentProductId::cards()->getId())) {
            $cardPaymentMethodSpecificInput->setPaymentProductId((int) $paymentProductId->getId());
        }
        if ($paymentProductId !== null && PaymentProductId::intersolve()->equals($paymentProductId->getId())) {
            $cardPaymentMethodSpecificInput->setAuthorizationMode(PaymentAction::authorizeCapture()->getType());
            if ($paymentMethodCollection && $config = $paymentMethodCollection->get(PaymentProductId::intersolve())) {
                $cardPaymentMethodSpecificInput->setPaymentProductId((int) $config->getAdditionalData()->getProductId()->getId());
            }
        }
        return $cardPaymentMethodSpecificInput;
    }
    /**
     * The exemption that applies to this payment, or null. The single decision every 3DS field
     * reads from: 3DS enabled, strong authentication not enforced, exemption enabled, and the
     * order's EUR total below the exemption limit (which is in EUR). Orders without a EUR total
     * never get an exemption.
     *
     * @param Cart $cart
     * @param ThreeDSSettings $settings
     *
     * @return ExemptionType|null
     */
    private static function applicableExemption(Cart $cart, ThreeDSSettings $settings): ?ExemptionType
    {
        if (!$settings->isEnable3ds() || $settings->isEnforceStrongAuthentication() || !$settings->isEnable3dsExemption() || null === $settings->getExemptionType()) {
            return null;
        }
        $totalInEUR = $cart->getTotalInEUR();
        if (null === $totalInEUR || $totalInEUR->getValue() >= $settings->getExemptionLimit()->getValue()) {
            return null;
        }
        return $settings->getExemptionType();
    }
}
