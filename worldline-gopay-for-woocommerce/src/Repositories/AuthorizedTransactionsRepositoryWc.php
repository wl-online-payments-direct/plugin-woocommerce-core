<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\AuthorizedTransactionsRepository;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionEntity;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\OrderMigration;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * Due automatic captures, limited to transactions the plugin may capture.
 *
 * The core selects authorized transactions whose capture time has passed. This subclass keeps only
 * those whose WooCommerce order exists, is owned by this plugin's gateways and is still in the
 * authorized status. The gate is on the live WooCommerce order, not on the stored transaction status,
 * so an order cancelled or captured in WooCommerce is not captured by the core auto-capture task.
 */
class AuthorizedTransactionsRepositoryWc extends AuthorizedTransactionsRepository
{
    /** Due transactions read from the core query per requested transaction. */
    private const OVER_FETCH_FACTOR = 5;
    /** Upper bound of due transactions read from the core query in one call. */
    private const MAX_FETCH = 100;
    /**
     * @param int $limit
     *
     * @return PaymentTransactionEntity[]
     */
    public function get(int $limit = 10): array
    {
        if ($limit <= 0 || !function_exists('wc_get_order')) {
            return [];
        }
        $fetch = min($limit * self::OVER_FETCH_FACTOR, max(self::MAX_FETCH, $limit));
        $authorizedStatus = OrderStatuses::authorized();
        $result = [];
        foreach (parent::get($fetch) as $entity) {
            if ($this->isCapturable($entity, $authorizedStatus)) {
                $result[] = $entity;
            }
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }
    /**
     * Whether the transaction's WooCommerce order exists, is owned by this plugin, is still awaiting
     * capture, was not already captured through the previous (2.x) plugin before the migration, and
     * its automatic capture was not already answered by the payment platform (`WcCaptureProxy`; a
     * rejected capture leaves the payment authorized with its capture time due).
     *
     * @param PaymentTransactionEntity $entity
     * @param string $authorizedStatus
     *
     * @return bool
     */
    private function isCapturable(PaymentTransactionEntity $entity, string $authorizedStatus): bool
    {
        $order = OrderService::forMerchantReference($entity->getPaymentTransaction()->getMerchantReference());
        return $order !== null && ModuleHelper::ownsGateway((string) $order->get_payment_method()) && $order->get_status() === $authorizedStatus && !OrderMigration::legacyCaptureSent($order) && (string) $order->get_meta(OrderService::META_AUTO_CAPTURE_SENT) !== (string) $entity->getPaymentTransaction()->getPaymentId();
    }
}
