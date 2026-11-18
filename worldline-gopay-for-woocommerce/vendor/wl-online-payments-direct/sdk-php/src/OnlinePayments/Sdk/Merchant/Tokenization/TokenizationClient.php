<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Tokenization;

use WOP\OnlinePayments\Sdk\ApiResource;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\ErrorResponseException;
use WOP\OnlinePayments\Sdk\Communication\ResponseClassMap;
use WOP\OnlinePayments\Sdk\Domain\CreateCertificateResponse;
use WOP\OnlinePayments\Sdk\Domain\CsrRequest;
use WOP\OnlinePayments\Sdk\Domain\DetokenizationResponse;
use WOP\OnlinePayments\Sdk\ExceptionFactory;
/**
 * Tokenization client.
 */
class TokenizationClient extends ApiResource implements TokenizationClientInterface
{
    /** @var ExceptionFactory|null */
    private ?ExceptionFactory $responseExceptionFactory = null;
    /**
     * @inheritdoc
     */
    public function createCertificate(CsrRequest $body, ?CallContext $callContext = null): CreateCertificateResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\CreateCertificateResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->post($responseClassMap, $this->instantiateUri('/v2/{merchantId}/detokenize/csr'), $this->getClientMetaInfo(), $body, null, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /**
     * @inheritdoc
     */
    public function getCardDataByTokens(GetCardDataByTokensParams $query, ?CallContext $callContext = null): DetokenizationResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\DetokenizationResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->get($responseClassMap, $this->instantiateUri('/v2/{merchantId}/detokenize/tokens'), $this->getClientMetaInfo(), $query, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /**
     * @inheritdoc
     */
    public function getCardDataByPayments(GetCardDataByPaymentsParams $query, ?CallContext $callContext = null): DetokenizationResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\DetokenizationResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->get($responseClassMap, $this->instantiateUri('/v2/{merchantId}/detokenize/payments'), $this->getClientMetaInfo(), $query, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /** @return ExceptionFactory */
    private function getResponseExceptionFactory(): ExceptionFactory
    {
        if (is_null($this->responseExceptionFactory)) {
            $this->responseExceptionFactory = new ExceptionFactory();
        }
        return $this->responseExceptionFactory;
    }
}
