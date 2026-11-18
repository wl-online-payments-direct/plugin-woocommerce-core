<?php

namespace WOP\OnlinePayments\Core\Bootstrap\Disconnect\Tasks;

use DateTime;
use Exception;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Disconnect\Repositories\DisconnectRepositoryInterface;
use WOP\OnlinePayments\Core\Bootstrap\TaskExecution\TenantAwareTask;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Monitoring\MonitoringLogsService;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Monitoring\WebhookLogsService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Serializer\Serializer;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
/**
 * Class DisconnectTask
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\Disconnect\Tasks
 */
class DisconnectTask extends TenantAwareTask
{
    private const DELETE_BATCH_SIZE = 5000;
    private string $storeId;
    private DateTime $dateTime;
    private string $mode;
    /**
     * @param string $tenantId
     * @param string $storeId
     * @param DateTime $dateTime
     * @param string $mode
     */
    public function __construct(string $tenantId, string $storeId, DateTime $dateTime, string $mode)
    {
        parent::__construct($tenantId);
        $this->storeId = $storeId;
        $this->dateTime = $dateTime;
        $this->mode = $mode;
    }
    /**
     * @inheritDoc
     */
    public static function fromArray(array $array): DisconnectTask
    {
        return new static($array['tenantId'] ?? '', $array['storeId'], (new DateTime())->setTimestamp($array['date']), $array['mode']);
    }
    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['tenantId' => $this->tenantId, 'storeId' => $this->storeId, 'date' => $this->dateTime->getTimestamp(), 'mode' => $this->mode];
    }
    /**
     * @inheritDoc
     */
    public function serialize(): string
    {
        return Serializer::serialize($this->toArray());
    }
    /**
     * @inheritDoc
     */
    public function unserialize(string $serialized): void
    {
        $unserialized = Serializer::unserialize($serialized);
        $this->tenantId = $unserialized['tenantId'] ?? '';
        $this->storeId = $unserialized['storeId'];
        $this->dateTime = (new DateTime())->setTimestamp($unserialized['date']);
        $this->mode = $unserialized['mode'];
    }
    /**
     * @inheritDoc
     *
     * @throws Exception
     */
    protected function doExecute(): void
    {
        StoreContext::doWithStore($this->storeId, function () {
            $this->doDisconnect();
        });
    }
    /**
     * @return void
     *
     * @throws Exception
     */
    protected function doDisconnect(): void
    {
        $this->deleteMonitoringLogs();
        $this->reportProgress(45);
        $this->deleteWebhookLogs();
        $this->reportProgress(90);
        $this->getDisconnectRepository()->deleteDisconnectTime();
        $this->reportProgress(100);
    }
    /**
     * Deletes the logs created before the disconnect. Counts and deletes by the same condition (this
     * task's mode and disconnect time), and without an active connection: the task runs after the
     * connection was removed, and the merchant may have connected again since.
     */
    protected function deleteMonitoringLogs(): void
    {
        $service = $this->getMonitoringLogsService();
        while ($service->countByMode($this->dateTime, $this->mode) > 0) {
            $service->deleteByMode($this->dateTime, $this->mode, self::DELETE_BATCH_SIZE);
        }
    }
    /**
     * @see self::deleteMonitoringLogs()
     */
    protected function deleteWebhookLogs(): void
    {
        $service = $this->getWebhookLogsService();
        while ($service->countByMode($this->dateTime, $this->mode) > 0) {
            $service->deleteByMode($this->dateTime, $this->mode, self::DELETE_BATCH_SIZE);
        }
    }
    protected function getMonitoringLogsService(): MonitoringLogsService
    {
        return ServiceRegister::getService(MonitoringLogsService::class);
    }
    protected function getWebhookLogsService(): WebhookLogsService
    {
        return ServiceRegister::getService(WebhookLogsService::class);
    }
    /**
     * @return DisconnectRepositoryInterface
     */
    protected function getDisconnectRepository(): DisconnectRepositoryInterface
    {
        return ServiceRegister::getService(DisconnectRepositoryInterface::class);
    }
}
