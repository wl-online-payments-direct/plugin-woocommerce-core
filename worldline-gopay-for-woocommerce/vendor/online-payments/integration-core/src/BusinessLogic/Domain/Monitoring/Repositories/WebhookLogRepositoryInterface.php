<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories;

use DateTime;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\WebhookLog;
/**
 * Interface WebhookLogRepositoryInterface
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories
 */
interface WebhookLogRepositoryInterface
{
    /**
     * @param WebhookLog $webhookLog
     *
     * @return void
     */
    public function saveWebhookLog(WebhookLog $webhookLog): void;
    /**
     * @param int $pageNumber
     * @param int $pageSize
     * @param string $searchTerm
     * @param DateTime|null $disconnectTime
     *
     * @return WebhookLog[]
     */
    public function getWebhookLogs(int $pageNumber, int $pageSize, string $searchTerm, ?DateTime $disconnectTime = null): array;
    /**
     * @return WebhookLog[]
     */
    public function getAllLogs(): array;
    /**
     * @param \DateTime|null $disconnectTime
     * @param string $searchTerm
     *
     * @return int
     */
    public function count(?\DateTime $disconnectTime = null, string $searchTerm = ''): int;
    /**
     * Number of logs of the given mode created before the given date, for the current store. Unlike
     * {@see self::count()} it does not depend on an active connection, so it can be used after a disconnect.
     *
     * @param DateTime $beforeDate
     * @param string $mode
     *
     * @return int
     */
    public function countByMode(DateTime $beforeDate, string $mode): int;
    /**
     * @param DateTime $beforeDate
     * @param string $mode
     * @param int $limit
     *
     * @return void
     */
    public function deleteByMode(DateTime $beforeDate, string $mode, int $limit): void;
    /**
     * @return int
     */
    public function countExpired(): int;
    /**
     * @param int $limit
     *
     * @return void
     */
    public function deleteExpired(int $limit = 5000): void;
    /**
     * @param WebhookLog $webhookLog
     *
     * @return string
     */
    public function getOrderUrl(WebhookLog $webhookLog): string;
    /**
     * @param string $merchantReference
     *
     * @return string
     */
    public function getOrderIdByMerchantReference(string $merchantReference): string;
}
