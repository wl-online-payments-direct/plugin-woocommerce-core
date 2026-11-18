<?php

namespace WOP\OnlinePayments\Proxies;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreatePaymentLinkRequestTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreatePaymentLinkResponseTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\FeedbacksTransformer;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PayByLinkSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\ContextLogProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentLinks\PaymentLinkRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentLinks\PaymentLinkResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\PaymentLinksProxyInterface;
defined('ABSPATH') || exit;
/**
 * Pay by Link proxy: the core request, never offering to save the card - a link is not tied to a
 * logged-in checkout session. Link lookups go to the core proxy unchanged.
 *
 * Replaces the core proxy (registered in `BootstrapComponent::initProxies()`); keep it in line with
 * the core `PaymentLinksProxy` on core updates.
 */
class WcPaymentLinksProxy implements PaymentLinksProxyInterface
{
    private MerchantClientFactory $clientFactory;
    private PaymentLinksProxyInterface $inner;
    /**
     * @param MerchantClientFactory $clientFactory
     * @param PaymentLinksProxyInterface $inner Core proxy, for the unchanged lookups.
     */
    public function __construct(MerchantClientFactory $clientFactory, PaymentLinksProxyInterface $inner)
    {
        $this->clientFactory = $clientFactory;
        $this->inner = $inner;
    }
    /**
     * @inheritDoc
     */
    public function create(PaymentLinkRequest $request, ThreeDSSettings $cardsSettings, PaymentSettings $paymentSettings, PayByLinkSettings $payByLinkSettings, PaymentMethodCollection $paymentMethodCollection, array $supportedPaymentMethods): PaymentLinkResponse
    {
        ContextLogProvider::getInstance()->setCurrentOrder($request->getCartProvider()->get()->getMerchantReference());
        $paymentLinkRequest = CreatePaymentLinkRequestTransformer::transform($request, $cardsSettings, $paymentSettings, $payByLinkSettings, $paymentMethodCollection, $supportedPaymentMethods);
        $paymentLinkRequest->setFeedbacks(FeedbacksTransformer::transform());
        WcHostedCheckoutProxy::disableCardSaving($paymentLinkRequest->getHostedCheckoutSpecificInput());
        return CreatePaymentLinkResponseTransformer::transform($this->clientFactory->get()->paymentLinks()->createPaymentLink($paymentLinkRequest));
    }
    /**
     * @inheritDoc
     */
    public function getById(string $paymentLinkId, string $merchantReference): PaymentLinkResponse
    {
        return $this->inner->getById($paymentLinkId, $merchantReference);
    }
}
