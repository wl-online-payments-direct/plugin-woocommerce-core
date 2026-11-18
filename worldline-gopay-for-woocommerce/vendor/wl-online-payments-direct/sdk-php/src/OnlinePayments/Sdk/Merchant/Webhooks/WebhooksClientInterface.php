<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Webhooks;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\SendTestRequest;
use WOP\OnlinePayments\Sdk\Domain\ValidateCredentialsRequest;
use WOP\OnlinePayments\Sdk\Domain\ValidateCredentialsResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * Webhooks client interface.
 */
interface WebhooksClientInterface
{
    /**
     * Resource /v2/{merchantId}/webhooks/validateCredentials - Validate credentials
     *
     * @param ValidateCredentialsRequest $body
     * @param CallContext|null $callContext
     * @return ValidateCredentialsResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function validateWebhookCredentials(ValidateCredentialsRequest $body, ?CallContext $callContext = null): ValidateCredentialsResponse;
    /**
     * Resource /v2/{merchantId}/webhooks/sendtest - Send test
     *
     * @param SendTestRequest $body
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
    function sendTestWebhook(SendTestRequest $body, ?CallContext $callContext = null): void;
}
