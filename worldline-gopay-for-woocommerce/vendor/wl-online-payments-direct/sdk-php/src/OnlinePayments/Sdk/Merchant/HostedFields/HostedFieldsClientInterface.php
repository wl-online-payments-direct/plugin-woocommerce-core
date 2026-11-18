<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\HostedFields;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedFieldsSessionRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedFieldsSessionResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * HostedFields client interface.
 */
interface HostedFieldsClientInterface
{
    /**
     * Resource /v2/{merchantId}/hostedfields/sessions - Create hosted fields session
     *
     * @param CreateHostedFieldsSessionRequest $body
     * @param CallContext|null $callContext
     * @return CreateHostedFieldsSessionResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function createHostedFieldsSession(CreateHostedFieldsSessionRequest $body, ?CallContext $callContext = null): CreateHostedFieldsSessionResponse;
}
