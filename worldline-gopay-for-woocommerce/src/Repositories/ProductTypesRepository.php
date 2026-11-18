<?php

namespace WOP\OnlinePayments\Repositories;

defined('ABSPATH') || exit;
/**
 * Repository for ProductTypeEntity, backed by $wpdb over the dedicated "product_types" table.
 */
class ProductTypesRepository extends BaseRepositoryWithConditionalDelete
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
     * @return string
     */
    protected function getTableSuffix(): string
    {
        return 'product_types';
    }
}
