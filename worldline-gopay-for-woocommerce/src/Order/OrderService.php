<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
defined('ABSPATH') || exit;
/**
 * Access point for Worldline orders: fetching by id/merchant reference and reading Worldline meta.
 */
class OrderService
{
    public const META_PAYMENT_ID = '_worldline_payment_id';
    public const META_RETURN_HMAC = '_worldline_return_hmac';
    /** Unix time the latest Worldline payment attempt was started for the order. */
    public const META_PAYMENT_STARTED_AT = '_worldline_payment_started_at';
    /** Unix time Worldline first reported the order's payment as authorized. */
    public const META_PAYMENT_CONFIRMED_AT = '_worldline_payment_confirmed_at';
    public const META_MERCHANT_REFERENCE = '_worldline_merchant_reference';
    public const META_PAYMENT_METHOD = '_worldline_payment_method';
    /** Unix time the latest hosted checkout session or card payment was created (session timeout start). */
    public const META_SESSION_STARTED_AT = '_worldline_session_started_at';
    /** Unix time of the latest Worldline status applied to the order. */
    public const META_STATUS_RECEIVED_AT = '_worldline_status_received_at';
    /** Set by the session timeout sweep for an order that received a Worldline status. */
    public const META_SESSION_TIMEOUT_EXEMPT = '_worldline_session_timeout_exempt';
    /** Payment id whose automatic capture was answered by the payment platform (sent only once). */
    public const META_AUTO_CAPTURE_SENT = '_worldline_auto_capture_sent';
    /** Prefix of the merchant reference this plugin sends ("WC-{order id}"). */
    private const MERCHANT_REFERENCE_PREFIX = 'WC-';
    /** "WC-{order id}" or, for legacy and migrated 2.x orders, the bare "{order id}". */
    private const MERCHANT_REFERENCE_PATTERN = '/^(?:WC-)?(\d{1,20})$/';
    /** Worldline card network/scheme => display brand. */
    private const CARD_BRANDS = ['VISA' => 'Visa', 'MASTERCARD' => 'Mastercard', 'AMEX' => 'American Express', 'AMERICAN EXPRESS' => 'American Express', 'MAESTRO' => 'Maestro', 'DISCOVER' => 'Discover', 'JCB' => 'JCB', 'DINERS' => 'Diners Club', 'DINERS CLUB' => 'Diners Club', 'CARTE BANCAIRE' => 'Cartes Bancaires', 'BANCONTACT' => 'Bancontact'];
    /**
     * Restarts the session timeout for a new hosted checkout session or card payment. The caller saves
     * the order.
     *
     * @param \WC_Order $order
     */
    public static function recordSessionStart(\WC_Order $order): void
    {
        $order->update_meta_data(self::META_SESSION_STARTED_AT, (string) time());
        $order->delete_meta_data(self::META_SESSION_TIMEOUT_EXEMPT);
    }
    /**
     * @param mixed $orderId
     */
    public static function get($orderId): ?\WC_Order
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order(is_numeric($orderId) ? (int) $orderId : $orderId);
        return $order instanceof \WC_Order ? $order : null;
    }
    /**
     * The order the given customer may pay, or null. Mirrors WooCommerce's order-pay authorization:
     * the order key must match, and the order must be a guest order or belong to $customerId.
     */
    public static function authorizePayOrder(int $orderId, string $orderKey, int $customerId): ?\WC_Order
    {
        if ($orderId <= 0 || $orderKey === '') {
            return null;
        }
        $order = self::get($orderId);
        if ($order === null || !hash_equals((string) $order->get_order_key(), $orderKey)) {
            return null;
        }
        $ownerId = (int) $order->get_customer_id();
        return $ownerId === 0 || $ownerId === $customerId ? $order : null;
    }
    /**
     * The order being paid on the current order-pay request, authorized for the current user;
     * null when this is not an order-pay request or the caller may not pay that order.
     */
    public static function currentPayOrder(): ?\WC_Order
    {
        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if ($orderId <= 0) {
            return null;
        }
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        return self::authorizePayOrder($orderId, (string) $key, (int) get_current_user_id());
    }
    /**
     * Worldline merchant reference this plugin sends for an order: "WC-{order id}". It starts with a
     * non-digit, so the previous (2.x) plugin, which casts the reference to an order id, cannot match
     * this plugin's payments to an order.
     *
     * @param int $orderId
     *
     * @return string
     */
    public static function merchantReferenceFor(int $orderId): string
    {
        return self::MERCHANT_REFERENCE_PREFIX . $orderId;
    }
    /**
     * WooCommerce order id from a Worldline merchant reference. Accepts "WC-{digits}" and a plain
     * "{digits}" (orders created before the prefix and orders migrated from the 2.x plugin).
     *
     * @param string $merchantReference
     *
     * @return int|null Null when the reference has another format.
     */
    public static function orderIdFromMerchantReference(string $merchantReference): ?int
    {
        if (1 !== preg_match(self::MERCHANT_REFERENCE_PATTERN, $merchantReference, $matches)) {
            return null;
        }
        $orderId = (int) $matches[1];
        return $orderId > 0 ? $orderId : null;
    }
    /**
     * Resolves the WooCommerce order from a Worldline merchant reference (see
     * orderIdFromMerchantReference()).
     *
     * @param string $merchantReference
     *
     * @return \WC_Order|null
     */
    public static function forMerchantReference(string $merchantReference): ?\WC_Order
    {
        $orderId = self::orderIdFromMerchantReference($merchantReference);
        return $orderId !== null ? self::get($orderId) : null;
    }
    /** Parsed Worldline payment id stored on the order, or null when absent/invalid. */
    public static function paymentId(\WC_Order $order): ?PaymentId
    {
        $id = self::rawPaymentId($order);
        if ($id === '') {
            return null;
        }
        try {
            return PaymentId::parse($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
    public static function rawPaymentId(\WC_Order $order): string
    {
        return (string) $order->get_meta(self::META_PAYMENT_ID);
    }
    public static function returnHmac(\WC_Order $order): string
    {
        return (string) $order->get_meta(self::META_RETURN_HMAC);
    }
    /**
     * Worldline merchant reference stored on the order. When unset (e.g. a Pay by Link order, whose
     * reference is not stored), the reference this plugin sends for the order.
     *
     * @param \WC_Order $order
     *
     * @return string
     */
    public static function merchantReference(\WC_Order $order): string
    {
        $reference = (string) $order->get_meta(self::META_MERCHANT_REFERENCE);
        return $reference !== '' ? $reference : self::merchantReferenceFor((int) $order->get_id());
    }
    public static function paymentMethod(\WC_Order $order): string
    {
        return (string) $order->get_meta(self::META_PAYMENT_METHOD);
    }
    /**
     * Pretty display brand for a Worldline card network/scheme string ("VISA" => "Visa").
     *
     * @param string $network
     *
     * @return string
     */
    public static function brandLabel(string $network): string
    {
        $network = trim($network);
        if ($network === '') {
            return '';
        }
        return self::CARD_BRANDS[strtoupper($network)] ?? ucwords(strtolower($network));
    }
}
