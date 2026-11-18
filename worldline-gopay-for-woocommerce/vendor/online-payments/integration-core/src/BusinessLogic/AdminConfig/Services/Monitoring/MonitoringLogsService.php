<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Monitoring;

use DateTime;
use Exception;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Disconnect\Repositories\DisconnectRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\MonitoringLog;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\MonitoringLogRepositoryInterface;
/**
 * Class MonitoringLogsService
 *
 * @package OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Monitoring
 */
class MonitoringLogsService
{
    protected MonitoringLogRepositoryInterface $monitoringLogRepository;
    protected DisconnectRepositoryInterface $repository;
    /**
     * @param MonitoringLogRepositoryInterface $monitoringLogRepository
     * @param DisconnectRepositoryInterface $repository
     */
    public function __construct(MonitoringLogRepositoryInterface $monitoringLogRepository, DisconnectRepositoryInterface $repository)
    {
        $this->monitoringLogRepository = $monitoringLogRepository;
        $this->repository = $repository;
    }
    /**
     * @param int $pageNumber
     * @param int $pageSize
     * @param string $searchTerm
     *
     * @return MonitoringLog[]
     *
     * @throws Exception
     */
    public function getLogs(int $pageNumber, int $pageSize, string $searchTerm): array
    {
        $disconnectTime = $this->repository->getDisconnectTime();
        return $this->monitoringLogRepository->getLogs($pageNumber, $pageSize, $searchTerm, $disconnectTime);
    }
    /**
     * @return array
     */
    public function getAllLogs(): array
    {
        $logs = $this->monitoringLogRepository->getAllLogs();
        $result = [];
        foreach ($logs as $log) {
            $result[] = $log->toArray();
        }
        return $result;
    }
    /**
     * @param string $searchTerm
     * @return int
     *
     * @throws Exception
     */
    public function count(string $searchTerm = ''): int
    {
        $disconnectTime = $this->repository->getDisconnectTime();
        return $this->monitoringLogRepository->count($disconnectTime, $searchTerm);
    }
    /**
     * @param string $mode
     * @param int $limit
     *
     * @return void
     *
     * @throws Exception
     */
    public function delete(string $mode, int $limit = 5000): void
    {
        $disconnectTime = $this->repository->getDisconnectTime();
        $this->monitoringLogRepository->deleteByMode($disconnectTime, $mode, $limit);
    }
    /**
     * Number of logs of the given mode created before the given date. Does not depend on the stored
     * disconnect time or an active connection, so the disconnect task can use it after the connection
     * was removed or a new one was made.
     *
     * @param DateTime $beforeDate
     * @param string $mode
     *
     * @return int
     */
    public function countByMode(DateTime $beforeDate, string $mode): int
    {
        return $this->monitoringLogRepository->countByMode($beforeDate, $mode);
    }
    /**
     * Deletes up to `$limit` logs of the given mode created before the given date.
     *
     * @param DateTime $beforeDate
     * @param string $mode
     * @param int $limit
     *
     * @return void
     */
    public function deleteByMode(DateTime $beforeDate, string $mode, int $limit = 5000): void
    {
        $this->monitoringLogRepository->deleteByMode($beforeDate, $mode, $limit);
    }
}
