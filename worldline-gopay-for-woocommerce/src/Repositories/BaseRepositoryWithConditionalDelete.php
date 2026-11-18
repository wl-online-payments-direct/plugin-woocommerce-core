<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\ConditionallyDeletes;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
defined('ABSPATH') || exit;
/**
 * BaseRepository plus bulk deleteWhere (ConditionallyDeletes).
 */
class BaseRepositoryWithConditionalDelete extends BaseRepository implements ConditionallyDeletes
{
    const THIS_CLASS_NAME = __CLASS__;
    /**
     * @return string
     */
    public static function getClassName(): string
    {
        return static::THIS_CLASS_NAME;
    }
    /**
     * Deletes all rows of the entity type matching the filter; returns the deleted count.
     *
     * @param QueryFilter|null $queryFilter
     *
     * @return int
     *
     * @throws QueryFilterInvalidParamException
     */
    public function deleteWhere(QueryFilter $queryFilter = null): int
    {
        global $wpdb;
        /** @var Entity $entity */
        $entity = new $this->entityClass();
        $sql = 'DELETE FROM `' . $this->getTableName() . '` WHERE entity_type = %s';
        $params = [$entity->getConfig()->getType()];
        if ($queryFilter !== null && $queryFilter->getConditions()) {
            [$where, $whereParams] = $this->buildWhereParts($queryFilter, $entity);
            if ($where !== '') {
                $sql .= ' AND (' . $where . ')';
                $params = array_merge($params, $whereParams);
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        return (int) $wpdb->query($wpdb->prepare($sql, $params));
    }
}
