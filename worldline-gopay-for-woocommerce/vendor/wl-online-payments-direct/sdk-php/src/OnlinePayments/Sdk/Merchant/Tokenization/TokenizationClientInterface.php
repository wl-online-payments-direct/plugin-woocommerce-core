<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Tokenization;

use WOP\OnlinePayments\Sdk\ApiException;
use WOP\OnlinePayments\Sdk\AuthorizationException;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\InvalidResponseException;
use WOP\OnlinePayments\Sdk\Domain\CreateCertificateResponse;
use WOP\OnlinePayments\Sdk\Domain\CsrRequest;
use WOP\OnlinePayments\Sdk\Domain\DetokenizationResponse;
use WOP\OnlinePayments\Sdk\IdempotenceException;
use WOP\OnlinePayments\Sdk\PlatformException;
use WOP\OnlinePayments\Sdk\ReferenceException;
use WOP\OnlinePayments\Sdk\ValidationException;
/**
 * Tokenization client interface.
 */
interface TokenizationClientInterface
{
    /**
     * Resource /v2/{merchantId}/detokenize/csr - Sign certificate
     *
     * @param CsrRequest $body
     * @param CallContext|null $callContext
     * @return CreateCertificateResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function createCertificate(CsrRequest $body, ?CallContext $callContext = null): CreateCertificateResponse;
    /**
     * Resource /v2/{merchantId}/detokenize/tokens - Get sensitive card details by card alias tokens
     *
     * @param GetCardDataByTokensParams $query
     * @param CallContext|null $callContext
     * @return DetokenizationResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getCardDataByTokens(GetCardDataByTokensParams $query, ?CallContext $callContext = null): DetokenizationResponse;
    /**
     * Resource /v2/{merchantId}/detokenize/payments - Get sensitive card details by card payment identifiers
     *
     * @param GetCardDataByPaymentsParams $query
     * @param CallContext|null $callContext
     * @return DetokenizationResponse
     *
     * @throws IdempotenceException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws ReferenceException
     * @throws PlatformException
     * @throws ApiException
     * @throws InvalidResponseException
     */
    function getCardDataByPayments(GetCardDataByPaymentsParams $query, ?CallContext $callContext = null): DetokenizationResponse;
}
