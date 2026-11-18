<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\HostedTokenization;

use WOP\OnlinePayments\Sdk\ApiResource;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\ErrorResponseException;
use WOP\OnlinePayments\Sdk\Communication\ResponseClassMap;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedTokenizationRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedTokenizationResponse;
use WOP\OnlinePayments\Sdk\Domain\GetHostedTokenizationResponse;
use WOP\OnlinePayments\Sdk\ExceptionFactory;
/**
 * HostedTokenization client.
 */
class HostedTokenizationClient extends ApiResource implements HostedTokenizationClientInterface
{
    /** @var ExceptionFactory|null */
    private ?ExceptionFactory $responseExceptionFactory = null;
    /**
     * @inheritdoc
     */
    public function createHostedTokenization(CreateHostedTokenizationRequest $body, ?CallContext $callContext = null): CreateHostedTokenizationResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\CreateHostedTokenizationResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->post($responseClassMap, $this->instantiateUri('/v2/{merchantId}/hostedtokenizations'), $this->getClientMetaInfo(), $body, null, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /**
     * @inheritdoc
     */
    public function getHostedTokenization(string $hostedTokenizationId, ?CallContext $callContext = null): GetHostedTokenizationResponse
    {
        $this->context['hostedTokenizationId'] = $hostedTokenizationId;
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\GetHostedTokenizationResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->get($responseClassMap, $this->instantiateUri('/v2/{merchantId}/hostedtokenizations/{hostedTokenizationId}'), $this->getClientMetaInfo(), null, $callContext);
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
