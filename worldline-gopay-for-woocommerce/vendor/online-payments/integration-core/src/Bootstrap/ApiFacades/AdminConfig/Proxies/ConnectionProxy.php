<?php

namespace WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\Proxies;

use WOP\OnlinePayments\Core\Bootstrap\Sdk\MerchantClientFactory;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection\Proxies\ConnectionProxyInterface as BaseConnectionProxy;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ConnectionDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Exceptions\InvalidConnectionDetailsException;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Sdk\Domain\ValidateCredentialsRequest;
use WOP\OnlinePayments\Sdk\ResponseException;
class ConnectionProxy implements BaseConnectionProxy
{
    private const CONNECTION_VALID = 'OK';
    private const WEBHOOK_CREDENTIALS_VALID = 'Valid';
    private const HTTP_FORBIDDEN = 403;
    private MerchantClientFactory $clientFactory;
    public function __construct(MerchantClientFactory $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }
    /**
     * @inheritDoc
     *
     * @throws InvalidConnectionDetailsException
     */
    public function isConnectionValid(ConnectionDetails $connectionDetails): bool
    {
        return $this->clientFactory->get($connectionDetails)->services()->testConnection()->getResult() === self::CONNECTION_VALID;
    }
    /**
     * @inheritDoc
     *
     * Worldline answers HTTP 200 with the result Valid or Invalid. HTTP 403 means the request could
     * not be authenticated and is handled as invalid. Any other failure means the API is unavailable,
     * and the credentials are then handled as valid.
     */
    public function isWebhookCredentialsValid(ConnectionDetails $connectionDetails): bool
    {
        $credentials = $connectionDetails->getActiveCredentials();
        $request = new ValidateCredentialsRequest();
        $request->setKey($credentials->getWebhookKey());
        // The secret parameter is the signature of an empty payload, made with the webhook secret.
        $request->setSecret(base64_encode(hash_hmac('sha256', '', $credentials->getWebhookSecret(), \true)));
        try {
            return $this->clientFactory->get($connectionDetails)->webhooks()->validateWebhookCredentials($request)->getResult() === self::WEBHOOK_CREDENTIALS_VALID;
        } catch (ResponseException $e) {
            if ($e->getHttpStatusCode() === self::HTTP_FORBIDDEN) {
                return \false;
            }
            Logger::logWarning('Webhook credentials could not be validated, they are handled as valid.', 'Core', ['httpStatusCode' => $e->getHttpStatusCode()]);
            return \true;
        } catch (\Exception $e) {
            Logger::logWarning('Webhook credentials could not be validated, they are handled as valid.', 'Core', ['message' => $e->getMessage(), 'type' => get_class($e)]);
            return \true;
        }
    }
}
