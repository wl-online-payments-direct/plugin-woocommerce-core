<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Locks editing of a Worldline order's items/totals (the payment is immutable); logs address
 * changes and trashing.
 */
class OrderEditGuard
{
    /** @var array<int, bool> Order ids already logged as trashed in this request (HPOS + post sync can fire twice). */
    private static array $loggedTrash = [];
    public function register(): void
    {
        add_filter('wc_order_is_editable', [$this, 'lockEditing'], 10, 2);
        add_action('woocommerce_before_order_object_save', [$this, 'logAddressChange']);
        add_action('woocommerce_before_trash_order', [$this, 'onTrashOrder']);
        add_action('wp_trash_post', [$this, 'onTrashPost']);
    }
    /**
     * Logs a billing/shipping address change on a Worldline order.
     *
     * @param mixed $order The WC_Order about to be saved (changes still pending).
     */
    public function logAddressChange($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_id() <= 0 || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        $changes = $order->get_changes();
        $changedAddresses = array_values(array_intersect(['billing', 'shipping'], array_keys($changes)));
        if ($changedAddresses === []) {
            return;
        }
        Logger::logInfo(sprintf('%s order #%d %s address changed.', ModuleHelper::getModuleConfig()->getName(), $order->get_id(), implode(' and ', $changedAddresses)), 'Integration', ['paymentId' => OrderService::rawPaymentId($order), 'billing' => $changes['billing'] ?? null, 'shipping' => $changes['shipping'] ?? null]);
    }
    /**
     * Marks a Worldline order with a created payment as not editable.
     *
     * @param bool  $isEditable WooCommerce's default editability for the order.
     * @param mixed $order      The WC_Order being checked.
     */
    public function lockEditing($isEditable, $order): bool
    {
        if ($order instanceof \WC_Order && ModuleHelper::ownsGateway($order->get_payment_method()) && $order->get_payment_method() !== PayByLinkGateway::id()) {
            return \false;
        }
        return (bool) $isEditable;
    }
    /**
     * @param int $orderId HPOS order id passed by woocommerce_before_trash_order.
     */
    public function onTrashOrder($orderId): void
    {
        $this->logTrash((int) $orderId);
    }
    /**
     * @param int $postId Legacy post id passed by wp_trash_post.
     */
    public function onTrashPost($postId): void
    {
        if (function_exists('get_post_type') && get_post_type((int) $postId) !== 'shop_order') {
            return;
        }
        $this->logTrash((int) $postId);
    }
    private function logTrash(int $orderId): void
    {
        if ($orderId <= 0 || isset(self::$loggedTrash[$orderId])) {
            return;
        }
        $order = OrderService::get($orderId);
        if ($order === null || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        self::$loggedTrash[$orderId] = \true;
        Logger::logInfo(sprintf('%s order #%d moved to trash.', ModuleHelper::getModuleConfig()->getName(), $orderId), 'Integration', ['paymentId' => OrderService::rawPaymentId($order)]);
    }
}
