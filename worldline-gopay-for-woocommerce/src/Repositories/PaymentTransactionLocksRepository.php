<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Infrastructure\ORM\Entity;
defined('ABSPATH') || exit;
/**
 * Repository for the dedicated "payment_transaction_locks" table.
 */
class PaymentTransactionLocksRepository extends BaseRepositoryWithConditionalDelete
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
        return 'payment_transaction_locks';
    }
    /**
     * Inserts a lock row. The table's unique key is the lock itself: a parallel status update of the
     * same payment that already holds the lock makes the insert fail, the core treats that as "not
     * locked" and skips. That failure is expected, so wpdb is kept from writing it to the PHP error log
     * as a database error; the exception still reaches the caller.
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
        $suppressed = $wpdb->suppress_errors(\true);
        try {
            return parent::save($entity);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }
}
