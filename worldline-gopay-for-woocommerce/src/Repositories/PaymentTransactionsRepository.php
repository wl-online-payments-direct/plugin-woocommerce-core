<?php

namespace WOP\OnlinePayments\Repositories;

defined('ABSPATH') || exit;
/**
 * Repository for the dedicated "payment_transactions" table.
 */
class PaymentTransactionsRepository extends BaseRepositoryWithConditionalDelete
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
        return 'payment_transactions';
    }
}
