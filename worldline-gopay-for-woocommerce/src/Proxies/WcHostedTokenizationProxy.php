<?php

namespace WOP\OnlinePayments\Proxies;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedTokenizationRequestTransformer;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers\CreateHostedTokenizationResponseTransformer;
use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\HostedTokenization;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\ContextLogProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Proxies\HostedTokenizationProxyInterface;
use WOP\OnlinePayments\Services\SavedCardsSetting;
defined('ABSPATH') || exit;
/**
 * Hosted tokenization (card iframe) proxy: the core request, with `askConsumerConsent` true only
 * when the payer may save their card (logged in and saved cards enabled). Token lookups and
 * deletions go to the core proxy unchanged.
 *
 * Replaces the core proxy (registered in `BootstrapComponent::initProxies()`); keep it in line with
 * the core `HostedTokenizationProxy` on core updates.
 */
class WcHostedTokenizationProxy implements HostedTokenizationProxyInterface
{
    private MerchantClientFactory $clientFactory;
    private HostedTokenizationProxyInterface $inner;
    /**
     * @param MerchantClientFactory $clientFactory
     * @param HostedTokenizationProxyInterface $inner Core proxy, for the unchanged token calls.
     */
    public function __construct(MerchantClientFactory $clientFactory, HostedTokenizationProxyInterface $inner)
    {
        $this->clientFactory = $clientFactory;
        $this->inner = $inner;
    }
    /**
     * @inheritDoc
     */
    public function create(Cart $cart, array $savedTokens = [], ?PaymentProductId $productId = null, string $template = ''): HostedTokenization
    {
        ContextLogProvider::getInstance()->setCurrentOrder($cart->getMerchantReference());
        $request = CreateHostedTokenizationRequestTransformer::transform($cart, $savedTokens, $productId, $template);
        $request->setAskConsumerConsent(SavedCardsSetting::allowsSaving($cart));
        return CreateHostedTokenizationResponseTransformer::transform($this->clientFactory->get()->hostedTokenization()->createHostedTokenization($request));
    }
    /**
     * @inheritDoc
     */
    public function getToken(string $customerId, string $tokenId): ?Token
    {
        return $this->inner->getToken($customerId, $tokenId);
    }
    /**
     * @inheritDoc
     */
    public function deleteToken(string $tokenId): void
    {
        $this->inner->deleteToken($tokenId);
    }
}
