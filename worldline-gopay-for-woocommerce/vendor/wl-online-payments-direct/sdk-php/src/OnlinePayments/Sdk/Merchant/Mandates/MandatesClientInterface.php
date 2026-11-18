<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Mandates;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CreateMandateRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateMandateResponse;
use WOP\OnlinePayments\Sdk\Domain\GetMandateResponse;
use WOP\OnlinePayments\Sdk\Domain\RevokeMandateRequest;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * Mandates client interface.
 */
interface MandatesClientInterface
{
    /**
     * Resource /v2/{merchantId}/mandates - Create mandate
     *
     * @param CreateMandateRequest $body
     * @param CallContext|null $callContext
     * @return CreateMandateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function createMandate(CreateMandateRequest $body, ?CallContext $callContext = null): CreateMandateResponse;
    /**
     * Resource /v2/{merchantId}/mandates/{uniqueMandateReference} - Get mandate
     *
     * @param string $uniqueMandateReference
     * @param CallContext|null $callContext
     * @return GetMandateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getMandate(string $uniqueMandateReference, ?CallContext $callContext = null): GetMandateResponse;
    /**
     * Resource /v2/{merchantId}/mandates/{uniqueMandateReference}/block - Block mandate
     *
     * @param string $uniqueMandateReference
     * @param CallContext|null $callContext
     * @return GetMandateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function blockMandate(string $uniqueMandateReference, ?CallContext $callContext = null): GetMandateResponse;
    /**
     * Resource /v2/{merchantId}/mandates/{uniqueMandateReference}/unblock - Unblock mandate
     *
     * @param string $uniqueMandateReference
     * @param CallContext|null $callContext
     * @return GetMandateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function unblockMandate(string $uniqueMandateReference, ?CallContext $callContext = null): GetMandateResponse;
    /**
     * Resource /v2/{merchantId}/mandates/{uniqueMandateReference}/revoke - Revoke mandate
     *
     * @param string $uniqueMandateReference
     * @param RevokeMandateRequest $body
     * @param CallContext|null $callContext
     * @return GetMandateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function revokeMandate(string $uniqueMandateReference, RevokeMandateRequest $body, ?CallContext $callContext = null): GetMandateResponse;
}
