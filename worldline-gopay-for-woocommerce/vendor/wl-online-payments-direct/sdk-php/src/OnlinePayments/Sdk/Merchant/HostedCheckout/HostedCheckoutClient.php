<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\HostedCheckout;

use WOP\OnlinePayments\Sdk\ApiResource;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\ErrorResponseException;
use WOP\OnlinePayments\Sdk\Communication\ResponseClassMap;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutResponse;
use WOP\OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse;
use WOP\OnlinePayments\Sdk\ExceptionFactory;
/**
 * HostedCheckout client.
 */
class HostedCheckoutClient extends ApiResource implements HostedCheckoutClientInterface
{
    /** @var ExceptionFactory|null */
    private ?ExceptionFactory $responseExceptionFactory = null;
    /**
     * @inheritdoc
     */
    public function createHostedCheckout(CreateHostedCheckoutRequest $body, ?CallContext $callContext = null): CreateHostedCheckoutResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->post($responseClassMap, $this->instantiateUri('/v2/{merchantId}/hostedcheckouts'), $this->getClientMetaInfo(), $body, null, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /**
     * @inheritdoc
     */
    public function getHostedCheckout(string $hostedCheckoutId, ?CallContext $callContext = null): GetHostedCheckoutResponse
    {
        $this->context['hostedCheckoutId'] = $hostedCheckoutId;
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\GetHostedCheckoutResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->get($responseClassMap, $this->instantiateUri('/v2/{merchantId}/hostedcheckouts/{hostedCheckoutId}'), $this->getClientMetaInfo(), null, $callContext);
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
