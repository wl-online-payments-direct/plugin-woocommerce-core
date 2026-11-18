<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk;

use WOP\OnlinePayments\Sdk\Logging\CommunicatorLogger;
use WOP\OnlinePayments\Sdk\Merchant\MerchantClientInterface;
/**
 * Payment platform client interface.
 */
interface ClientInterface
{
    /**
     * @param CommunicatorLogger $communicatorLogger
     */
    function enableLogging(CommunicatorLogger $communicatorLogger): void;
    /**
     * @return void
     */
    function disableLogging(): void;
    /**
     * @param string $clientMetaInfo
     * @return $this
     */
    function setClientMetaInfo(string $clientMetaInfo): ClientInterface;
    /**
     * Resource /v2/{merchantId}
     *
     * @param string $merchantId
     * @return MerchantClientInterface
     */
    function merchant(string $merchantId): MerchantClientInterface;
}
