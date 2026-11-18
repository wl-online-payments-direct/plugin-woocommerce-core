<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection;

use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection\Proxies\ConnectionProxyInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ConnectionDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Exceptions\InvalidConnectionDetailsException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Exceptions\InvalidWebhookCredentialsException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\Repositories\ConnectionConfigRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;
/**
 * Class ConnectionService
 *
 * @package OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection
 */
class ConnectionService
{
    protected ConnectionConfigRepositoryInterface $connectionConfigRepository;
    protected ConnectionProxyInterface $proxy;
    /**
     * @param ConnectionConfigRepositoryInterface $connectionConfigRepository
     * @param ConnectionProxyInterface $proxy
     */
    public function __construct(ConnectionConfigRepositoryInterface $connectionConfigRepository, ConnectionProxyInterface $proxy)
    {
        $this->connectionConfigRepository = $connectionConfigRepository;
        $this->proxy = $proxy;
    }
    /**
     * Saves connection config.
     *
     * @param ConnectionDetails $connectionDetails
     *
     * @return void
     *
     * @throws InvalidConnectionDetailsException
     * @throws InvalidWebhookCredentialsException
     */
    public function connect(ConnectionDetails $connectionDetails): void
    {
        $this->validateConnectionDetails($connectionDetails);
        $this->validateWebhookCredentials($connectionDetails);
        $this->connectionConfigRepository->saveConnection($connectionDetails);
    }
    /**
     * Check if user is logged in.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        $connectionDetails = $this->getConnectionConfig();
        try {
            if ($connectionDetails) {
                $this->validateConnectionDetails($connectionDetails);
                return \true;
            }
        } catch (InvalidConnectionDetailsException $e) {
            // intentionally left empty
        }
        return \false;
    }
    /**
     * Checks the webhook credentials of the saved connection.
     *
     * Returns true when there is no saved connection, or when the payment platform could not answer
     * (see ConnectionProxyInterface::isWebhookCredentialsValid()).
     *
     * @return bool
     */
    public function areWebhookCredentialsValid(): bool
    {
        $connectionDetails = $this->getConnectionConfig();
        return $connectionDetails === null || $this->proxy->isWebhookCredentialsValid($connectionDetails);
    }
    /**
     * Retrieves saved connection config.
     *
     * @return ConnectionDetails|null
     */
    public function getConnectionConfig(): ?ConnectionDetails
    {
        return $this->connectionConfigRepository->getConnection();
    }
    /**
     * @param ConnectionDetails $connectionDetails
     *
     * @return void
     *
     * @throws InvalidConnectionDetailsException
     */
    protected function validateConnectionDetails(ConnectionDetails $connectionDetails): void
    {
        $this->validate($connectionDetails);
    }
    /**
     * @param ConnectionDetails $connectionDetails
     *
     * @return void
     *
     * @throws InvalidWebhookCredentialsException
     */
    protected function validateWebhookCredentials(ConnectionDetails $connectionDetails): void
    {
        if (!$this->proxy->isWebhookCredentialsValid($connectionDetails)) {
            throw new InvalidWebhookCredentialsException(new TranslatableLabel('Invalid webhook credentials.', 'connection.webhookCredentialsValidationFailed'));
        }
    }
    /**
     * @throws InvalidConnectionDetailsException
     */
    protected function validate(ConnectionDetails $connectionDetails): void
    {
        $isValid = $this->proxy->isConnectionValid($connectionDetails);
        if (!$isValid) {
            throw new InvalidConnectionDetailsException(new TranslatableLabel('Invalid connection details.', 'connection.apiValidationFailed'));
        }
    }
}
