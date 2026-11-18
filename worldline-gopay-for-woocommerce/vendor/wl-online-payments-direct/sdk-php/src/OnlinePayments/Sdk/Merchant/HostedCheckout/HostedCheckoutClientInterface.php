<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\HostedCheckout;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutResponse;
use WOP\OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * HostedCheckout client interface.
 */
interface HostedCheckoutClientInterface
{
    /**
     * Resource /v2/{merchantId}/hostedcheckouts - Create hosted checkout
     *
     * @param CreateHostedCheckoutRequest $body
     * @param CallContext|null $callContext
     * @return CreateHostedCheckoutResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function createHostedCheckout(CreateHostedCheckoutRequest $body, ?CallContext $callContext = null): CreateHostedCheckoutResponse;
    /**
     * Resource /v2/{merchantId}/hostedcheckouts/{hostedCheckoutId} - Get hosted checkout status
     *
     * @param string $hostedCheckoutId
     * @param CallContext|null $callContext
     * @return GetHostedCheckoutResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getHostedCheckout(string $hostedCheckoutId, ?CallContext $callContext = null): GetHostedCheckoutResponse;
}
