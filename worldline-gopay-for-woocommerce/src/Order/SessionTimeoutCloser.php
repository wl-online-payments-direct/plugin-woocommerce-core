<?php

namespace WOP\OnlinePayments\Order;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Closes an order whose hosted payment session timed out: marks it failed and gives its stock back.
 * Used by the expiry webhook and by the session timeout sweep.
 */
class SessionTimeoutCloser
{
    private const FAILED = 'failed';
    /** Seconds after which a lock left behind by a failed request is taken over. */
    private const LOCK_TTL = 60;
    /**
     * Marks a pending order failed, releases its reserved stock and restores stock that was already
     * reduced. WooCommerce "failed order" e-mails are not sent: an abandoned session is not a failed
     * payment. Does nothing for an order that is no longer pending.
     *
     * @param \WC_Order $order
     * @param string $reason Order note.
     *
     * @return bool Whether the order was closed.
     */
    public static function close(\WC_Order $order, string $reason): bool
    {
        if (!$order->has_status(OrderStatuses::pending())) {
            return \false;
        }
        // Worldline sends payment.created and payment.cancelled for an expired session at the same
        // moment, so two webhook requests can close the same order concurrently. Only the request
        // holding the lock closes it, and only while the stored status is still pending.
        $lock = ModuleHelper::moduleKey('_session_close_' . $order->get_id());
        if (!self::acquireLock($lock)) {
            return \false;
        }
        try {
            return self::isStoredPending($order->get_id()) && self::fail($order, $reason);
        } finally {
            self::releaseLock($lock);
        }
    }
    /**
     * @param \WC_Order $order
     * @param string $reason
     *
     * @return bool
     */
    private static function fail(\WC_Order $order, string $reason): bool
    {
        add_filter('woocommerce_email_enabled_failed_order', '__return_false', 99);
        add_filter('woocommerce_email_enabled_customer_failed_order', '__return_false', 99);
        try {
            $order->update_status(self::FAILED, $reason);
        } finally {
            remove_filter('woocommerce_email_enabled_failed_order', '__return_false', 99);
            remove_filter('woocommerce_email_enabled_customer_failed_order', '__return_false', 99);
        }
        if (function_exists('wc_release_stock_for_order')) {
            wc_release_stock_for_order($order);
        }
        if (function_exists('wc_increase_stock_levels') && $order->get_data_store()->get_stock_reduced($order->get_id())) {
            wc_increase_stock_levels($order);
        }
        return \true;
    }
    /**
     * Takes the per-order lock. A lock left behind by a request that died is taken over after
     * LOCK_TTL seconds.
     *
     * @param string $lock Option name.
     *
     * @return bool
     */
    private static function acquireLock(string $lock): bool
    {
        if (self::insertLock($lock)) {
            return \true;
        }
        global $wpdb;
        $lockedAt = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock));
        if ($lockedAt > time() - self::LOCK_TTL) {
            return \false;
        }
        self::releaseLock($lock);
        return self::insertLock($lock);
    }
    private static function releaseLock(string $lock): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => $lock]);
    }
    /**
     * Atomic insert of the lock option. add_option() is not usable as a lock: it writes with
     * ON DUPLICATE KEY UPDATE, so concurrent requests all succeed.
     *
     * @param string $lock
     *
     * @return bool Whether this request inserted the row.
     */
    private static function insertLock(string $lock): bool
    {
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock, (string) time()));
        return (int) $inserted === 1;
    }
    /**
     * Reads the order status straight from the database, bypassing the order caches, which may still
     * hold the status loaded before another request closed the order.
     *
     * @param int $orderId
     *
     * @return bool
     */
    private static function isStoredPending(int $orderId): bool
    {
        global $wpdb;
        $usesOrdersTable = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
        $sql = $usesOrdersTable ? 'SELECT status FROM ' . OrdersTableDataStore::get_orders_table_name() . ' WHERE id = %d' : "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only the WooCommerce orders/posts table name and a placeholder; the id is bound by prepare().
        return (string) $wpdb->get_var($wpdb->prepare($sql, $orderId)) === 'wc-' . OrderStatuses::pending();
    }
    /**
     * Order note for an order closed after the session timeout.
     *
     * @param int $minutes
     *
     * @return string
     */
    public static function timeoutNote(int $minutes): string
    {
        return sprintf(
            /* translators: 1: payment gateway name, 2: minutes. */
            esc_html__('%1$s: payment session timed out after %2$d minute(s) without a completed payment.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName(),
            $minutes
        );
    }
    /**
     * Order note for an order closed because Worldline reported the session as expired.
     *
     * @return string
     */
    public static function expiredNote(): string
    {
        return sprintf(
            /* translators: %s: payment gateway name. */
            esc_html__('%s: the hosted payment session expired without a completed payment.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        );
    }
}
