<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant;

use WOP\OnlinePayments\Sdk\ApiResource;
use WOP\OnlinePayments\Sdk\Merchant\Captures\CapturesClient;
use WOP\OnlinePayments\Sdk\Merchant\Captures\CapturesClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\CofSeries\CofSeriesClient;
use WOP\OnlinePayments\Sdk\Merchant\CofSeries\CofSeriesClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Complete\CompleteClient;
use WOP\OnlinePayments\Sdk\Merchant\Complete\CompleteClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\HostedCheckout\HostedCheckoutClient;
use WOP\OnlinePayments\Sdk\Merchant\HostedCheckout\HostedCheckoutClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\HostedFields\HostedFieldsClient;
use WOP\OnlinePayments\Sdk\Merchant\HostedFields\HostedFieldsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\HostedTokenization\HostedTokenizationClient;
use WOP\OnlinePayments\Sdk\Merchant\HostedTokenization\HostedTokenizationClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Mandates\MandatesClient;
use WOP\OnlinePayments\Sdk\Merchant\Mandates\MandatesClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\MerchantBatch\MerchantBatchClient;
use WOP\OnlinePayments\Sdk\Merchant\MerchantBatch\MerchantBatchClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\PaymentLinks\PaymentLinksClient;
use WOP\OnlinePayments\Sdk\Merchant\PaymentLinks\PaymentLinksClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Payments\PaymentsClient;
use WOP\OnlinePayments\Sdk\Merchant\Payments\PaymentsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Payouts\PayoutsClient;
use WOP\OnlinePayments\Sdk\Merchant\Payouts\PayoutsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\PrivacyPolicy\PrivacyPolicyClient;
use WOP\OnlinePayments\Sdk\Merchant\PrivacyPolicy\PrivacyPolicyClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\ProductGroups\ProductGroupsClient;
use WOP\OnlinePayments\Sdk\Merchant\ProductGroups\ProductGroupsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Products\ProductsClient;
use WOP\OnlinePayments\Sdk\Merchant\Products\ProductsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Refunds\RefundsClient;
use WOP\OnlinePayments\Sdk\Merchant\Refunds\RefundsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Services\ServicesClient;
use WOP\OnlinePayments\Sdk\Merchant\Services\ServicesClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Sessions\SessionsClient;
use WOP\OnlinePayments\Sdk\Merchant\Sessions\SessionsClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Subsequent\SubsequentClient;
use WOP\OnlinePayments\Sdk\Merchant\Subsequent\SubsequentClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Tokenization\TokenizationClient;
use WOP\OnlinePayments\Sdk\Merchant\Tokenization\TokenizationClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Tokens\TokensClient;
use WOP\OnlinePayments\Sdk\Merchant\Tokens\TokensClientInterface;
use WOP\OnlinePayments\Sdk\Merchant\Webhooks\WebhooksClient;
use WOP\OnlinePayments\Sdk\Merchant\Webhooks\WebhooksClientInterface;
/**
 * Merchant client.
 */
class MerchantClient extends ApiResource implements MerchantClientInterface
{
    /**
     * @inheritdoc
     */
    public function hostedCheckout(): HostedCheckoutClientInterface
    {
        return new HostedCheckoutClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function hostedTokenization(): HostedTokenizationClientInterface
    {
        return new HostedTokenizationClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function hostedFields(): HostedFieldsClientInterface
    {
        return new HostedFieldsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function payments(): PaymentsClientInterface
    {
        return new PaymentsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function captures(): CapturesClientInterface
    {
        return new CapturesClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function refunds(): RefundsClientInterface
    {
        return new RefundsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function complete(): CompleteClientInterface
    {
        return new CompleteClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function subsequent(): SubsequentClientInterface
    {
        return new SubsequentClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function productGroups(): ProductGroupsClientInterface
    {
        return new ProductGroupsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function products(): ProductsClientInterface
    {
        return new ProductsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function services(): ServicesClientInterface
    {
        return new ServicesClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function webhooks(): WebhooksClientInterface
    {
        return new WebhooksClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function sessions(): SessionsClientInterface
    {
        return new SessionsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function tokens(): TokensClientInterface
    {
        return new TokensClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function cofSeries(): CofSeriesClientInterface
    {
        return new CofSeriesClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function tokenization(): TokenizationClientInterface
    {
        return new TokenizationClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function payouts(): PayoutsClientInterface
    {
        return new PayoutsClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function mandates(): MandatesClientInterface
    {
        return new MandatesClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function privacyPolicy(): PrivacyPolicyClientInterface
    {
        return new PrivacyPolicyClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function paymentLinks(): PaymentLinksClientInterface
    {
        return new PaymentLinksClient($this, $this->context);
    }
    /**
     * @inheritdoc
     */
    public function merchantBatch(): MerchantBatchClientInterface
    {
        return new MerchantBatchClient($this, $this->context);
    }
}
