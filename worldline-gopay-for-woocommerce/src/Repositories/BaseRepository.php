<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\RepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\IntermediateObject;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryCondition;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\EntityTranslator;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Utility\IndexHelper;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * RepositoryInterface implementation backed by $wpdb over the generic entity table
 * (id, entity_type, index_1..index_9, data JSON). Translates QueryFilter into SQL.
 */
class BaseRepository implements RepositoryInterface
{
    const THIS_CLASS_NAME = __CLASS__;
    protected string $entityClass;
    private ?string $tableName = null;
    /**
     * @return string
     */
    public static function getClassName(): string
    {
        return static::THIS_CLASS_NAME;
    }
    /**
     * @param string $entityClass
     */
    public function setEntityClass(string $entityClass): void
    {
        $this->entityClass = $entityClass;
    }
    /**
     * Selects all entities matching the filter.
     *
     * @param QueryFilter|null $filter
     *
     * @return Entity[]
     *
     * @throws QueryFilterInvalidParamException
     */
    public function select(QueryFilter $filter = null): array
    {
        global $wpdb;
        /** @var Entity $entity */
        $entity = new $this->entityClass();
        $sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE entity_type = %s';
        $params = [$entity->getConfig()->getType()];
        if ($filter !== null) {
            if ($filter->getConditions()) {
                [$where, $whereParams] = $this->buildWhereParts($filter, $entity);
                if ($where !== '') {
                    $sql .= ' AND (' . $where . ')';
                    $params = array_merge($params, $whereParams);
                }
            }
            $orderBy = $this->buildOrderBy($filter, $entity);
            if ($orderBy !== '') {
                $sql .= ' ' . $orderBy;
            }
            if ($filter->getLimit()) {
                $sql .= ' LIMIT %d';
                $params[] = (int) $filter->getLimit();
                if ($filter->getOffset()) {
                    $sql .= ' OFFSET %d';
                    $params[] = (int) $filter->getOffset();
                }
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), \ARRAY_A);
        return $this->translateToEntities(is_array($rows) ? $rows : []);
    }
    /**
     * Selects the first entity matching the filter.
     *
     * @param QueryFilter|null $filter
     *
     * @return Entity|null
     *
     * @throws QueryFilterInvalidParamException
     */
    public function selectOne(QueryFilter $filter = null): ?Entity
    {
        if ($filter === null) {
            $filter = new QueryFilter();
        }
        $filter->setLimit(1);
        $results = $this->select($filter);
        return empty($results) ? null : $results[0];
    }
    /**
     * Inserts the entity and returns its new id.
     *
     * @param Entity $entity
     *
     * @return int
     *
     * @throws \RuntimeException
     */
    public function save(Entity $entity): int
    {
        global $wpdb;
        if ($wpdb->insert($this->getTableName(), $this->buildRow($entity)) === \false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
            throw new \RuntimeException('Failed to save entity: ' . $wpdb->last_error);
        }
        $id = (int) $wpdb->insert_id;
        $entity->setId($id);
        return $id;
    }
    /**
     * Updates the entity row; the optional $queryFilter adds WHERE conditions the row
     * must satisfy for the update to apply (condition-guarded update).
     *
     * @param Entity $entity
     * @param QueryFilter|null $queryFilter
     *
     * @return bool
     *
     * @throws QueryFilterInvalidParamException
     */
    public function update(Entity $entity, QueryFilter $queryFilter = null): bool
    {
        global $wpdb;
        if ($entity->getId() === null) {
            return \false;
        }
        /** @var Entity $emptyEntity */
        $emptyEntity = new $this->entityClass();
        $setParts = [];
        $params = [];
        foreach ($this->buildRow($entity) as $column => $value) {
            if ($value === null) {
                $setParts[] = '`' . $column . '` = NULL';
                continue;
            }
            $setParts[] = '`' . $column . '` = %s';
            $params[] = $value;
        }
        $sql = 'UPDATE `' . $this->getTableName() . '` SET ' . implode(', ', $setParts) . ' WHERE id = %d AND entity_type = %s';
        $params[] = (int) $entity->getId();
        $params[] = $emptyEntity->getConfig()->getType();
        if ($queryFilter !== null && $queryFilter->getConditions()) {
            [$where, $whereParams] = $this->buildWhereParts($queryFilter, $emptyEntity);
            if ($where !== '') {
                $sql .= ' AND (' . $where . ')';
                $params = array_merge($params, $whereParams);
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        return $wpdb->query($wpdb->prepare($sql, $params)) !== \false;
    }
    /**
     * Deletes the entity row by id.
     *
     * @param Entity $entity
     *
     * @return bool
     */
    public function delete(Entity $entity): bool
    {
        global $wpdb;
        if ($entity->getId() === null) {
            return \false;
        }
        return $wpdb->delete($this->getTableName(), ['id' => (int) $entity->getId()], ['%d']) !== \false;
    }
    /**
     * Counts entities matching the filter.
     *
     * @param QueryFilter|null $filter
     *
     * @return int
     *
     * @throws QueryFilterInvalidParamException
     */
    public function count(QueryFilter $filter = null): int
    {
        global $wpdb;
        /** @var Entity $entity */
        $entity = new $this->entityClass();
        $sql = 'SELECT COUNT(*) FROM `' . $this->getTableName() . '` WHERE entity_type = %s';
        $params = [$entity->getConfig()->getType()];
        if ($filter !== null && $filter->getConditions()) {
            [$where, $whereParams] = $this->buildWhereParts($filter, $entity);
            if ($where !== '') {
                $sql .= ' AND (' . $where . ')';
                $params = array_merge($params, $whereParams);
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only trusted identifiers (plugin table, index columns) and placeholders; values are bound by prepare().
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }
    /**
     * Table suffix this repository targets; specialized repositories override it.
     *
     * @return string
     */
    protected function getTableSuffix(): string
    {
        return 'entity';
    }
    /**
     * Full (prefixed) table name, resolved once per instance.
     *
     * @return string
     */
    protected function getTableName(): string
    {
        if ($this->tableName === null) {
            $this->tableName = ModuleHelper::getFullTableName($this->getTableSuffix());
        }
        return $this->tableName;
    }
    /**
     * Builds the WHERE clause: conditions accumulate into AND-groups; a condition
     * whose chain operator is OR closes the group. Groups are OR-ed together.
     *
     * @param QueryFilter $filter
     * @param Entity $entity
     *
     * @return array{0: string, 1: array}
     *
     * @throws QueryFilterInvalidParamException
     */
    protected function buildWhereParts(QueryFilter $filter, Entity $entity): array
    {
        $fieldIndexMap = IndexHelper::mapFieldsToIndexes($entity);
        $fieldIndexMap['id'] = 0;
        $fieldTypes = [];
        foreach ($entity->getConfig()->getIndexMap()->getIndexes() as $index) {
            $fieldTypes[$index->getProperty()] = $index->getType();
        }
        $orGroups = [];
        $currentGroup = [];
        $params = [];
        foreach ($filter->getConditions() as $condition) {
            $column = $condition->getColumn();
            if (!array_key_exists($column, $fieldIndexMap)) {
                throw new QueryFilterInvalidParamException(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
                    'Field ' . $column . ' is not indexed in class ' . $this->entityClass
                );
            }
            $columnName = $column === 'id' ? 'id' : 'index_' . $fieldIndexMap[$column];
            [$fragment, $conditionParams] = $this->buildConditionSql($columnName, $condition, $fieldTypes[$column] ?? null);
            $currentGroup[] = $fragment;
            foreach ($conditionParams as $param) {
                $params[] = $param;
            }
            if ($condition->getChainOperator() === 'OR') {
                $orGroups[] = '(' . implode(' AND ', $currentGroup) . ')';
                $currentGroup = [];
            }
        }
        if (!empty($currentGroup)) {
            $orGroups[] = '(' . implode(' AND ', $currentGroup) . ')';
        }
        return [implode(' OR ', $orGroups), $params];
    }
    /**
     * Renders a single query condition as a SQL fragment with its placeholder params.
     *
     * @param string $column
     * @param QueryCondition $condition
     * @param string|null $indexType Declared index type of the field, for the items of an IN / NOT IN list.
     *
     * @return array{0: string, 1: array}
     */
    protected function buildConditionSql(string $column, QueryCondition $condition, ?string $indexType = null): array
    {
        $placeholder = $column === 'id' ? '%d' : '%s';
        $quotedColumn = '`' . $column . '`';
        $value = $this->prepareValue($condition, $indexType);
        switch ($condition->getOperator()) {
            case '!=':
                return ["{$quotedColumn} != {$placeholder}", [$value]];
            case '>':
                return ["{$quotedColumn} > {$placeholder}", [$value]];
            case '>=':
                return ["{$quotedColumn} >= {$placeholder}", [$value]];
            case '<':
                return ["{$quotedColumn} < {$placeholder}", [$value]];
            case '<=':
                return ["{$quotedColumn} <= {$placeholder}", [$value]];
            case 'IN':
                $values = is_array($value) ? array_values($value) : [$value];
                if (empty($values)) {
                    return ['0 = 1', []];
                }
                return ["{$quotedColumn} IN (" . implode(', ', array_fill(0, count($values), $placeholder)) . ')', $values];
            case 'NOT IN':
                $values = is_array($value) ? array_values($value) : [$value];
                if (empty($values)) {
                    return ['1 = 1', []];
                }
                return ["{$quotedColumn} NOT IN (" . implode(', ', array_fill(0, count($values), $placeholder)) . ')', $values];
            case 'IS NULL':
                return ["{$quotedColumn} IS NULL", []];
            case 'IS NOT NULL':
                return ["{$quotedColumn} IS NOT NULL", []];
            case 'LIKE':
                return ["{$quotedColumn} LIKE {$placeholder}", [$value]];
            default:
                return ["{$quotedColumn} = {$placeholder}", [$value]];
        }
    }
    /**
     * Builds the ORDER BY clause from the filter's sort column and direction.
     *
     * @param QueryFilter $filter
     * @param Entity $entity
     *
     * @return string
     *
     * @throws QueryFilterInvalidParamException
     */
    protected function buildOrderBy(QueryFilter $filter, Entity $entity): string
    {
        $column = $filter->getOrderByColumn();
        if (!$column) {
            return '';
        }
        $fieldIndexMap = IndexHelper::mapFieldsToIndexes($entity);
        if ($column !== 'id' && !array_key_exists($column, $fieldIndexMap)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
            throw new QueryFilterInvalidParamException('Unknown or not indexed OrderBy column ' . $column);
        }
        $orderColumn = $column === 'id' ? 'id' : 'index_' . $fieldIndexMap[$column];
        $direction = strtoupper((string) $filter->getOrderDirection()) === 'ASC' ? 'ASC' : 'DESC';
        return 'ORDER BY `' . $orderColumn . '` ' . $direction;
    }
    /**
     * Casts the condition value to its indexed column representation.
     *
     * @param QueryCondition $condition
     * @param string|null $indexType Declared index type of the field, for the items of an IN / NOT IN list.
     *
     * @return mixed
     */
    protected function prepareValue(QueryCondition $condition, ?string $indexType = null)
    {
        $value = $condition->getValue();
        if ($condition->getColumn() === 'id' || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            // The condition type of an IN / NOT IN list is "array"; each item is cast to the field's
            // index type (integer indexes are stored zero-padded), else integer items would be bound as ''.
            return array_map(static function ($item) use ($indexType) {
                return self::castListItem($item, $indexType);
            }, $value);
        }
        return IndexHelper::castFieldValue($value, $condition->getValueType());
    }
    /**
     * Indexed column representation of one item of an IN / NOT IN list.
     *
     * @param mixed $item
     * @param string|null $indexType Declared index type of the field; null casts by the item's own type.
     *
     * @return mixed
     */
    private static function castListItem($item, ?string $indexType)
    {
        if ($item === null) {
            return null;
        }
        switch ($indexType) {
            case 'integer':
                return is_numeric($item) ? IndexHelper::castFieldValue((int) $item, 'integer') : $item;
            case 'double':
                return is_numeric($item) ? IndexHelper::castFieldValue((float) $item, 'double') : $item;
            case 'boolean':
                return IndexHelper::castFieldValue((bool) $item, 'boolean');
            case 'dateTime':
                return $item instanceof \DateTime ? IndexHelper::castFieldValue($item, 'dateTime') : (string) $item;
            case 'string':
                return is_scalar($item) ? (string) $item : $item;
        }
        if (is_int($item)) {
            return IndexHelper::castFieldValue($item, 'integer');
        }
        if (is_float($item)) {
            return IndexHelper::castFieldValue($item, 'double');
        }
        if (is_bool($item)) {
            return IndexHelper::castFieldValue($item, 'boolean');
        }
        return $item instanceof \DateTime ? IndexHelper::castFieldValue($item, 'dateTime') : $item;
    }
    /**
     * Maps an entity to its table row (entity_type, JSON data, index columns).
     *
     * @param Entity $entity
     *
     * @return array<string, string|null>
     */
    protected function buildRow(Entity $entity): array
    {
        $indexes = IndexHelper::transformFieldsToIndexes($entity);
        $data = $entity->toArray();
        $data['class_name'] = $entity::getClassName();
        $row = ['entity_type' => $entity->getConfig()->getType(), 'data' => json_encode($data)];
        for ($i = 1; $i <= 9; $i++) {
            $row['index_' . $i] = $indexes[$i] ?? null;
        }
        return $row;
    }
    /**
     * Hydrates database rows back into core entities.
     *
     * @param array<int, array<string, mixed>> $results
     *
     * @return Entity[]
     */
    protected function translateToEntities(array $results): array
    {
        $translator = new EntityTranslator();
        $translator->init($this->entityClass);
        $intermediates = [];
        foreach ($results as $item) {
            $intermediate = new IntermediateObject();
            $unserializedData = json_decode($item['data'], \true);
            if (empty($unserializedData['id'])) {
                $unserializedData['id'] = (int) $item['id'];
            }
            $intermediate->setData(json_encode($unserializedData));
            for ($i = 1; $i <= 9; $i++) {
                $intermediate->setIndexValue($i, $item['index_' . $i] ?? null);
            }
            $intermediates[] = $intermediate;
        }
        return $translator->translate($intermediates);
    }
}
