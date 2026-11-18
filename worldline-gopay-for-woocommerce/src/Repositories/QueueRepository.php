<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\QueueItemRepository;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\IndexHelper;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Exceptions\QueueItemSaveException;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;
defined('ABSPATH') || exit;
/**
 * QueueItemRepository for the async task queue (dedicated "queue" table).
 */
class QueueRepository extends BaseRepositoryWithConditionalDelete implements QueueItemRepository
{
    const THIS_CLASS_NAME = __CLASS__;
    /** @var array<string, int>|null */
    private ?array $indexMapping = null;
    /**
     * @return string
     */
    public static function getClassName(): string
    {
        return static::THIS_CLASS_NAME;
    }
    /**
     * @return string
     */
    protected function getTableSuffix(): string
    {
        return 'queue';
    }
    /**
     * Oldest QUEUED item per queue at the given priority, skipping queues already running.
     *
     * @param int $priority
     * @param int $limit
     *
     * @return QueueItem[]
     */
    public function findOldestQueuedItems(int $priority, int $limit = 10): array
    {
        try {
            return $this->getQueuedItems($priority, $this->getRunningQueueNames(), $limit);
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * Saves the queue item, guarding updates with the additional WHERE conditions.
     *
     * @param QueueItem $queueItem
     * @param array<string, mixed> $additionalWhere
     *
     * @return int
     *
     * @throws QueryFilterInvalidParamException
     * @throws QueueItemSaveException
     */
    public function saveWithCondition(QueueItem $queueItem, array $additionalWhere = []): int
    {
        if ($queueItem->getId()) {
            $this->updateQueueItem($queueItem, $additionalWhere);
            return $queueItem->getId();
        }
        return $this->save($queueItem);
    }
    /**
     * Sets the status of all given queue items in a single UPDATE.
     *
     * @param int[] $ids
     * @param string $status
     */
    public function batchStatusUpdate(array $ids, string $status)
    {
        global $wpdb;
        if (empty($ids)) {
            return;
        }
        $sql = 'UPDATE `' . $this->getTableName() . '` SET `' . $this->getIndexMapping('status') . '` = %s' . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        $wpdb->query($wpdb->prepare($sql, array_merge([$status], array_map('intval', $ids))));
    }
    /**
     * Updates the queue item only if a row matching the additional conditions still exists.
     *
     * @param QueueItem $queueItem
     * @param array<string, mixed> $additionalWhere
     *
     * @throws QueueItemSaveException
     * @throws QueryFilterInvalidParamException
     */
    protected function updateQueueItem(QueueItem $queueItem, array $additionalWhere): void
    {
        $filter = new QueryFilter();
        $filter->where('id', Operators::EQUALS, $queueItem->getId());
        foreach ($additionalWhere as $name => $value) {
            if ($value === null) {
                $filter->where($name, Operators::NULL);
                continue;
            }
            $filter->where($name, Operators::EQUALS, $value);
        }
        if ($this->selectOne($filter) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
            throw new QueueItemSaveException("Cannot update queue item with id {$queueItem->getId()}.");
        }
        $this->update($queueItem);
    }
    /**
     * Names of queues that currently have an item in progress.
     *
     * @return string[]
     *
     * @throws QueryFilterInvalidParamException
     */
    protected function getRunningQueueNames(): array
    {
        $filter = new QueryFilter();
        $filter->where('status', Operators::EQUALS, QueueItem::IN_PROGRESS);
        $filter->setLimit(10000);
        return array_map(static function (QueueItem $item) {
            return $item->getQueueName();
        }, $this->select($filter));
    }
    /**
     * @param int $priority
     * @param string[] $runningQueueNames
     * @param int $limit
     *
     * @return QueueItem[]
     */
    protected function getQueuedItems(int $priority, array $runningQueueNames, int $limit): array
    {
        global $wpdb;
        $queueNameIndex = $this->getIndexMapping('queueName');
        $statusIndex = $this->getIndexMapping('status');
        $priorityIndex = $this->getIndexMapping('priority');
        $table = $this->getTableName();
        $type = (new $this->entityClass())->getConfig()->getType();
        try {
            $sql = 'SELECT queueTable.id, queueTable.data FROM `' . $table . '` queueTable' . ' INNER JOIN ( SELECT MIN(id) AS id FROM `' . $table . '`' . ' WHERE entity_type = %s AND `' . $statusIndex . '` = %s AND `' . $priorityIndex . '` = %d';
            $params = [$type, QueueItem::QUEUED, $priority];
            if (!empty($runningQueueNames)) {
                $sql .= ' AND `' . $queueNameIndex . '` NOT IN (' . implode(', ', array_fill(0, count($runningQueueNames), '%s')) . ')';
                $params = array_merge($params, array_values($runningQueueNames));
            }
            $sql .= ' GROUP BY `' . $queueNameIndex . '` LIMIT %d ) queueView ON queueView.id = queueTable.id';
            $params[] = $limit;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
            $records = $wpdb->get_results($wpdb->prepare($sql, $params), \ARRAY_A);
            return $this->translateToEntities(is_array($records) ? $records : []);
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * Resolves an entity property to its index_N column name, or null if not indexed.
     *
     * @param string $property
     *
     * @return string|null
     */
    protected function getIndexMapping(string $property): ?string
    {
        if ($this->indexMapping === null) {
            $this->indexMapping = IndexHelper::mapFieldsToIndexes(new $this->entityClass());
        }
        return array_key_exists($property, $this->indexMapping) ? 'index_' . $this->indexMapping[$property] : null;
    }
}
