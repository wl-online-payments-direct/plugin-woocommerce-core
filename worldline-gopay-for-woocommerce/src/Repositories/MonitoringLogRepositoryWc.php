<?php

namespace WOP\OnlinePayments\Repositories;

use WOP\OnlinePayments\Core\Bootstrap\DataAccess\Monitoring\MonitoringLogRepository;
use WOP\OnlinePayments\Order\OrderService;
defined('ABSPATH') || exit;
/**
 * Monitoring log repository that records the WooCommerce order id instead of the merchant reference
 * ("WC-{order id}" becomes "{order id}"). Replaces the core registration in BootstrapComponent.
 */
class MonitoringLogRepositoryWc extends MonitoringLogRepository
{
    /**
     * @param string $merchantReference
     *
     * @return string The order id, or the reference unchanged when it is not an order reference.
     */
    public function getOrderIdByMerchantReference(string $merchantReference): string
    {
        $orderId = OrderService::orderIdFromMerchantReference($merchantReference);
        return $orderId !== null ? (string) $orderId : $merchantReference;
    }
}
