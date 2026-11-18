<?php

namespace WOP\OnlinePayments\Repositories;

use DateTime;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Connection\ActiveConnectionProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Monitoring\Repositories\RepositoryWithAdvancedSearchInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\IndexHelper;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
defined('ABSPATH') || exit;
/**
 * Repository for WebhookLog entities, backed by $wpdb over the dedicated "webhook_log" table.
 */
class WebhookLogsRepository extends BaseRepositoryWithConditionalDelete implements RepositoryWithAdvancedSearchInterface
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
        return 'webhook_log';
    }
    /**
     * Logs for the current store/mode with pagination, ordered newest first.
     *
     * @param int $pageNumber 1-based page index.
     * @param int $pageSize
     * @param string $searchTerm
     * @param DateTime|null $disconnectTime
     *
     * @return \OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\WebhookLog[]
     *
     * @throws QueryFilterInvalidParamException
     */
    public function getLogs(int $pageNumber, int $pageSize, string $searchTerm, ?DateTime $disconnectTime = null): array
    {
        global $wpdb;
        [$where, $params] = $this->buildLogsConditions($searchTerm, $disconnectTime);
        $sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE ' . $where . ' ORDER BY `' . $this->getIndexColumn('createdAt') . '` DESC' . ' LIMIT %d OFFSET %d';
        $params[] = (int) $pageSize;
        $params[] = (int) (($pageNumber - 1) * $pageSize);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), \ARRAY_A);
        return $this->translateToEntities(is_array($rows) ? $rows : []);
    }
    /**
     * Mock implementation - This method is not called within the WooCommerce plugin.
     * However, the interface that this class implements declares this method, so it must be implemented somehow.
     *
     * @param DateTime|null $disconnectTime
     * @param string $searchTerm
     *
     * @return int|null
     */
    public function countLogs(?DateTime $disconnectTime = NULL, string $searchTerm = ''): ?int
    {
        return 0;
    }
    /**
     * @param string $searchTerm
     * @param DateTime|null $disconnectTime
     *
     * @return array{0: string, 1: array}
     *
     * @throws QueryFilterInvalidParamException
     */
    protected function buildLogsConditions(string $searchTerm, ?DateTime $disconnectTime): array
    {
        /** @var ActiveConnectionProvider $activeConnectionProvider */
        $activeConnectionProvider = ServiceRegister::getService(ActiveConnectionProvider::class);
        $entity = new $this->entityClass();
        $where = 'entity_type = %s' . ' AND `' . $this->getIndexColumn('storeId') . '` = %s' . ' AND `' . $this->getIndexColumn('mode') . '` = %s';
        $params = [$entity->getConfig()->getType(), StoreContext::getInstance()->getStoreId(), (string) $activeConnectionProvider->get()->getMode()];
        // Logs from before a disconnect are being deleted by the disconnect task; show only newer ones.
        if ($disconnectTime !== null) {
            $where .= ' AND `' . $this->getIndexColumn('createdAt') . '` > %d';
            $params[] = $disconnectTime->getTimestamp();
        }
        if ($searchTerm !== '') {
            $like = '%' . $this->escapeLike($searchTerm) . '%';
            $where .= ' AND (' . '`' . $this->getIndexColumn('orderId') . '` LIKE %s' . ' OR `' . $this->getIndexColumn('paymentNumber') . '` LIKE %s' . ')';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }
    /**
     * @param string $term
     *
     * @return string
     */
    private function escapeLike(string $term): string
    {
        global $wpdb;
        return method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($term) : addcslashes($term, '_%\\');
    }
    /**
     * Resolves an entity property to its index_N column name.
     *
     * @param string $property
     *
     * @return string
     *
     * @throws QueryFilterInvalidParamException
     */
    private function getIndexColumn(string $property): string
    {
        if ($this->indexMapping === null) {
            $this->indexMapping = IndexHelper::mapFieldsToIndexes(new $this->entityClass());
        }
        if (!array_key_exists($property, $this->indexMapping)) {
            throw new QueryFilterInvalidParamException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
                'Field ' . $property . ' is not indexed in class ' . $this->entityClass
            );
        }
        return 'index_' . $this->indexMapping[$property];
    }
}
