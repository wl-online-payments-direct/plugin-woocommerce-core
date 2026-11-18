<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Captures;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CapturesResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * Captures client interface.
 */
interface CapturesClientInterface
{
    /**
     * Resource /v2/{merchantId}/payments/{paymentId}/captures - Get captures of payment
     *
     * @param string $paymentId
     * @param CallContext|null $callContext
     * @return CapturesResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getCaptures(string $paymentId, ?CallContext $callContext = null): CapturesResponse;
}
