<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\PaymentLinks;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CreatePaymentLinkRequest;
use WOP\OnlinePayments\Sdk\Domain\PaymentLinkResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * PaymentLinks client interface.
 */
interface PaymentLinksClientInterface
{
    /**
     * Resource /v2/{merchantId}/paymentlinks - Create payment link
     *
     * @param CreatePaymentLinkRequest $body
     * @param CallContext|null $callContext
     * @return PaymentLinkResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function createPaymentLink(CreatePaymentLinkRequest $body, ?CallContext $callContext = null): PaymentLinkResponse;
    /**
     * Resource /v2/{merchantId}/paymentlinks/{paymentLinkId} - Get payment link by ID
     *
     * @param string $paymentLinkId
     * @param CallContext|null $callContext
     * @return PaymentLinkResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getPaymentLinkById(string $paymentLinkId, ?CallContext $callContext = null): PaymentLinkResponse;
    /**
     * Resource /v2/{merchantId}/paymentlinks/{paymentLinkId}/cancel - Cancel PaymentLink by ID
     *
     * @param string $paymentLinkId
     * @param CallContext|null $callContext
     * @return null
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function cancelPaymentLinkById(string $paymentLinkId, ?CallContext $callContext = null): void;
}
