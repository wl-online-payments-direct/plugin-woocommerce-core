<?php

namespace WOP\OnlinePayments\Core\Bootstrap\Sdk;

use Exception;
use WOP\OnlinePayments\Core\Branding\Brand\ActiveBrandProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\GeneralSettingsService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\Exceptions\InvalidLogRecordsLifetimeException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\ContextLogProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\MonitoringLog;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\MonitoringLogRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Sdk\Communication\CommunicatorLoggerHelper as SdkCommunicatorLoggerHelper;
use WOP\OnlinePayments\Sdk\Communication\ConnectionResponseInterface;
use WOP\OnlinePayments\Sdk\Logging\BodyObfuscator;
use WOP\OnlinePayments\Sdk\Logging\CommunicatorLogger;
use WOP\OnlinePayments\Sdk\Logging\ValueObfuscator;
class CommunicatorLoggerHelper extends SdkCommunicatorLoggerHelper
{
    /**
     * Body properties masked in the request/response logs on top of the SDK defaults. The webhook
     * credentials check (POST /webhooks/validateCredentials) sends the webhook key and secret in the body.
     */
    private const MASKED_BODY_PROPERTIES = ['key', 'secret'];
    public function __construct()
    {
        $bodyObfuscator = new BodyObfuscator();
        foreach (self::MASKED_BODY_PROPERTIES as $property) {
            $bodyObfuscator->setCustomRule($property, static function ($value, ValueObfuscator $valueObfuscator): string {
                return $valueObfuscator->obfuscateFixedLength(8);
            });
        }
        $this->setBodyObfuscator($bodyObfuscator);
    }
    public function logRequest(CommunicatorLogger $communicatorLogger, $requestId, $requestMethod, $requestUri, array $requestHeaders, $requestBody = ''): void
    {
        if ($this->isDebugEnabled()) {
            parent::logRequest($communicatorLogger, $requestId, $requestMethod, $requestUri, $requestHeaders, $requestBody);
            $obfuscatedRequest = $this->getHttpObfuscator()->getRawObfuscatedRequest($requestMethod, $this->getRelativeUriPathWithRequestParameters($requestUri), $requestHeaders, $requestBody);
            $message = sprintf("Request/response for %s endpoint", $this->getRelativeUriPathWithRequestParameters($requestUri));
            $this->getLogRepository()->saveMonitoringLog(new MonitoringLog($requestId, ContextLogProvider::getInstance()->getCurrentOrder() ?? '-', ContextLogProvider::getInstance()->getPaymentNumber() ?? '-', 'DEBUG', $message, new \DateTime(), $requestMethod, $this->getRelativeUriPathWithRequestParameters($requestUri), $obfuscatedRequest, '', '', ContextLogProvider::getInstance()->getPaymentNumber() ? $this->getUrl() . ContextLogProvider::getInstance()->getPaymentNumber() : ''));
        }
    }
    public function logResponse(CommunicatorLogger $communicatorLogger, $requestId, $requestUri, ConnectionResponseInterface $response): void
    {
        if ($this->isDebugEnabled()) {
            parent::logResponse($communicatorLogger, $requestId, $requestUri, $response);
            $obfuscatedResponse = $this->getHttpObfuscator()->getRawObfuscatedResponse($response);
            $message = sprintf("Response from %s", $this->getRelativeUriPathWithRequestParameters($requestUri));
            $this->getLogRepository()->saveMonitoringLog(new MonitoringLog($requestId, ContextLogProvider::getInstance()->getCurrentOrder() ?? '-', ContextLogProvider::getInstance()->getPaymentNumber() ?? '-', 'DEBUG', $message, new \DateTime(), '', $this->getRelativeUriPathWithRequestParameters($requestUri), '', (string) $response->getHttpStatusCode(), $obfuscatedResponse, ContextLogProvider::getInstance()->getPaymentNumber() ? $this->getUrl() . ContextLogProvider::getInstance()->getPaymentNumber() : ''));
        }
    }
    public function logException(CommunicatorLogger $communicatorLogger, $requestId, $requestUri, Exception $exception): void
    {
        parent::logException($communicatorLogger, $requestId, $requestUri, $exception);
        $message = sprintf("Error occurred while executing request to %s ", $this->getRelativeUriPathWithRequestParameters($requestUri));
        $this->getLogRepository()->saveMonitoringLog(new MonitoringLog($requestId, ContextLogProvider::getInstance()->getCurrentOrder() ?? '-', ContextLogProvider::getInstance()->getPaymentNumber() ?? '-', 'ERROR', $message, new \DateTime(), '', $this->getRelativeUriPathWithRequestParameters($requestUri), '', '', '', ContextLogProvider::getInstance()->getPaymentNumber() ? $this->getUrl() . ContextLogProvider::getInstance()->getPaymentNumber() : ''));
    }
    private function getUrl(): string
    {
        /** @var ActiveBrandProviderInterface $activeBrandProvider */
        $activeBrandProvider = ServiceRegister::getService(ActiveBrandProviderInterface::class);
        return $activeBrandProvider->getTransactionUrl();
    }
    /**
     * @return bool
     *
     * @throws InvalidLogRecordsLifetimeException
     */
    private function isDebugEnabled(): bool
    {
        $generalSettings = $this->getGeneralSettingsService()->getLogSettings();
        return $generalSettings->isDebugMode();
    }
    /**
     * @return MonitoringLogRepositoryInterface
     */
    private function getLogRepository(): MonitoringLogRepositoryInterface
    {
        return ServiceRegister::getService(MonitoringLogRepositoryInterface::class);
    }
    /**
     * @return GeneralSettingsService
     */
    private function getGeneralSettingsService(): GeneralSettingsService
    {
        return ServiceRegister::getService(GeneralSettingsService::class);
    }
}
