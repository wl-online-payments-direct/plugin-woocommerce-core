<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Stores\StoreService as CoreStoreService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\OrderStatusMapping\Models\OrderStatusMapping;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Stores\Models\Store;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Stores\Models\StoreOrderStatus;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * WooCommerce is single-store, so a single synthetic store with id "1" is exposed.
 */
class StoreService implements CoreStoreService
{
    private const DEFAULT_STORE_ID = '1';
    /** Pseudo-statuses (without the "wc-" prefix) that must not be selectable as a mapping target. */
    private const EXCLUDED_STATUSES = ['checkout-draft', 'draft', 'auto-draft', 'trash'];
    /**
     * @inheritDoc
     */
    public function getStoreDomain(): string
    {
        if (function_exists('home_url')) {
            $host = wp_parse_url(home_url(), \PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        if (function_exists('get_bloginfo')) {
            $host = wp_parse_url((string) get_bloginfo('url'), \PHP_URL_HOST);
            return is_string($host) ? $host : '';
        }
        return '';
    }
    /**
     * @inheritDoc
     */
    public function getStores(): array
    {
        $store = $this->getDefaultStore();
        return $store ? [$store] : [];
    }
    /**
     * @inheritDoc
     */
    public function getDefaultStore(): ?Store
    {
        return new Store(self::DEFAULT_STORE_ID, $this->getStoreName(), \false);
    }
    /**
     * @inheritDoc
     */
    public function getStoreById(string $id): ?Store
    {
        if ($id !== self::DEFAULT_STORE_ID) {
            return null;
        }
        return $this->getDefaultStore();
    }
    /**
     * @inheritDoc
     */
    public function getDefaultOrderStatusMapping(): OrderStatusMapping
    {
        return new OrderStatusMapping(
            'wc-processing',
            // payment accepted/captured
            'wc-failed',
            // payment error
            'wc-pending',
            // pending / created
            'wc-on-hold',
            // authorized (awaiting capture)
            'wc-cancelled',
            // payment cancelled
            'wc-refunded',
            // payment refunded
            OrderStatuses::PARTIALLY_REFUNDED
        );
    }
    /**
     * @inheritDoc
     */
    public function getStoreOrderStatuses(): array
    {
        $result = [];
        $result[] = new StoreOrderStatus('0', 'None');
        if (!function_exists('wc_get_order_statuses')) {
            return $result;
        }
        foreach (wc_get_order_statuses() as $statusId => $statusName) {
            if (in_array($this->normalizeStatus((string) $statusId), self::EXCLUDED_STATUSES, \true)) {
                continue;
            }
            $result[] = new StoreOrderStatus((string) $statusId, (string) $statusName);
        }
        return $result;
    }
    /** Strips the optional WooCommerce "wc-" prefix so both forms match against the exclusion list. */
    private function normalizeStatus(string $status): string
    {
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }
    /**
     * Returns the site title, or '' outside WordPress.
     *
     * @return string
     */
    private function getStoreName(): string
    {
        if (function_exists('get_bloginfo')) {
            return (string) get_bloginfo('name');
        }
        return '';
    }
}
