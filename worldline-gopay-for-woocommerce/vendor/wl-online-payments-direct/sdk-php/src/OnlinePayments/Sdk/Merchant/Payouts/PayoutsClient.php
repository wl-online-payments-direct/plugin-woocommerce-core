<?php

/*
 * This file was automatically generated.
 */
namespace WOP\OnlinePayments\Sdk\Merchant\Payouts;

use WOP\OnlinePayments\Sdk\ApiResource;
use WOP\OnlinePayments\Sdk\CallContext;
use WOP\OnlinePayments\Sdk\Communication\ErrorResponseException;
use WOP\OnlinePayments\Sdk\Communication\ResponseClassMap;
use WOP\OnlinePayments\Sdk\Domain\CreatePayoutRequest;
use WOP\OnlinePayments\Sdk\Domain\PayoutResponse;
use WOP\OnlinePayments\Sdk\ExceptionFactory;
/**
 * Payouts client.
 */
class PayoutsClient extends ApiResource implements PayoutsClientInterface
{
    /** @var ExceptionFactory|null */
    private ?ExceptionFactory $responseExceptionFactory = null;
    /**
     * @inheritdoc
     */
    public function createPayout(CreatePayoutRequest $body, ?CallContext $callContext = null): PayoutResponse
    {
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\PayoutResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\PayoutErrorResponse';
        try {
            return $this->getCommunicator()->post($responseClassMap, $this->instantiateUri('/v2/{merchantId}/payouts'), $this->getClientMetaInfo(), $body, null, $callContext);
        } catch (ErrorResponseException $e) {
            throw $this->getResponseExceptionFactory()->createException($e->getHttpStatusCode(), $e->getErrorResponse(), $callContext);
        }
    }
    /**
     * @inheritdoc
     */
    public function getPayout(string $payoutId, ?CallContext $callContext = null): PayoutResponse
    {
        $this->context['payoutId'] = $payoutId;
        $responseClassMap = new ResponseClassMap();
        $responseClassMap->defaultSuccessResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\PayoutResponse';
        $responseClassMap->defaultErrorResponseClassName = 'WOP\OnlinePayments\Sdk\Domain\ErrorResponse';
        try {
            return $this->getCommunicator()->get($responseClassMap, $this->instantiateUri('/v2/{merchantId}/payouts/{payoutId}'), $this->getClientMetaInfo(), null, $callContext);
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
