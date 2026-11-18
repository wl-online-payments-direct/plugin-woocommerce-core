<?php

namespace WOP\OnlinePayments\Proxies;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedCheckoutRequestTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedCheckoutResponseTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\FeedbacksTransformer;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedCheckout\HostedCheckoutSessionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\PaymentResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\ContextLogProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedCheckoutProxyInterface;
use WOP\OnlinePayments\Services\SavedCardsSetting;
use WOP\OnlinePayments\Services\SessionTimeoutSetting;
use WOP\OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputForHostedCheckout;
use WOP\OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
defined('ABSPATH') || exit;
/**
 * Hosted checkout session proxy: the core request, plus
 * - `sessionTimeout` from the plugin's "Session timeout" setting. It replaces the value the core
 *   copies from the Intersolve configuration onto every hosted checkout request;
 * - `tokenizationMode = noTokenization` when the payer may not save their card (guest, or saved
 *   cards disabled). When saving is allowed the parameter is not sent, so the Worldline account
 *   default applies.
 *
 * Replaces the core proxy (registered in `BootstrapComponent::initProxies()`); keep it in line with
 * the core `HostedCheckoutProxy` on core updates.
 */
class WcHostedCheckoutProxy implements HostedCheckoutProxyInterface
{
    /** Worldline `tokenizationMode` value that hides "Remember my card". */
    private const NO_TOKENIZATION = 'noTokenization';
    private MerchantClientFactory $clientFactory;
    /**
     * @param MerchantClientFactory $clientFactory
     */
    public function __construct(MerchantClientFactory $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }
    /**
     * @inheritDoc
     */
    public function createSession(HostedCheckoutSessionRequest $request, ThreeDSSettings $cardsSettings, PaymentSettings $paymentSettings, PaymentMethodCollection $paymentMethodCollection, array $supportedPaymentMethods, ?Token $token = null): PaymentResponse
    {
        $cart = $request->getCartProvider()->get();
        ContextLogProvider::getInstance()->setCurrentOrder($cart->getMerchantReference());
        $hostedCheckoutRequest = CreateHostedCheckoutRequestTransformer::transform($request, $cardsSettings, $paymentSettings, $paymentMethodCollection, $supportedPaymentMethods, $token);
        $feedbacks = FeedbacksTransformer::transform();
        if ($feedbacks !== null) {
            $hostedCheckoutRequest->setFeedbacks($feedbacks);
        }
        $hostedCheckoutRequest->getHostedCheckoutSpecificInput()->setSessionTimeout(SessionTimeoutSetting::minutes());
        if (!SavedCardsSetting::allowsSaving($cart)) {
            self::disableCardSaving($hostedCheckoutRequest->getHostedCheckoutSpecificInput());
        }
        return CreateHostedCheckoutResponseTransformer::transform($this->clientFactory->get()->hostedCheckout()->createHostedCheckout($hostedCheckoutRequest));
    }
    /**
     * Tells the hosted page not to offer saving the card, keeping any card input already set
     * (e.g. card grouping).
     *
     * @param HostedCheckoutSpecificInput $hostedCheckoutSpecificInput
     */
    public static function disableCardSaving(HostedCheckoutSpecificInput $hostedCheckoutSpecificInput): void
    {
        $cardInput = $hostedCheckoutSpecificInput->getCardPaymentMethodSpecificInput() ?? new CardPaymentMethodSpecificInputForHostedCheckout();
        $cardInput->setTokenizationMode(self::NO_TOKENIZATION);
        $hostedCheckoutSpecificInput->setCardPaymentMethodSpecificInput($cardInput);
    }
}
