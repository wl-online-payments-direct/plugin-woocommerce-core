<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Adds two columns to the WooCommerce order overview (orders list): the Worldline payment reference
 * and the selected Worldline payment method. Works on both the HPOS orders table and the legacy
 * posts table. The reference column is brand-specific (each co-installed brand gets its own, e.g.
 * "Worldline Payment Reference" next to "CAWL Payment Reference"); the Payment column is shared
 * across brands. Each brand fills only the cells for orders its gateway owns.
 */
class OrderListColumns
{
    public function register(): void
    {
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addColumns'], 20);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumns'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderLegacyColumn'], 10, 2);
    }
    /** The Payment column is shared (brand-neutral) across co-installed brands. */
    private const PAYMENT_KEY = 'wl_payment_method';
    /** The reference column is brand-specific so each co-installed brand gets its own column. */
    private static function referenceKey(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_payment_reference';
    }
    private static function paymentKey(): string
    {
        return self::PAYMENT_KEY;
    }
    /**
     * The stored payment id rendered in the active brand's portal reference format. Each brand shows the
     * payment reference differently: CAWL uses the canonical "90000<transaction>000" form, while WOP shows
     * the shorter hosted-checkout id "<transaction>_0". The stored id may be in either form (hosted-checkout
     * orders store "<transaction>_0"); this derives the reference from the transaction id so it stays stable
     * across later operations regardless of how the id was persisted.
     *
     * @param string $storedId
     *
     * @return string
     */
    private static function portalReference(string $storedId): string
    {
        if ($storedId === '') {
            return '';
        }
        $transactionId = PaymentId::parse($storedId)->getTransactionId();
        if ($transactionId === '') {
            return $storedId;
        }
        return ModuleHelper::getModuleConfig()->getBrand() === 'CAWL' ? '90000' . $transactionId . '000' : $transactionId . '_0';
    }
    /**
     * Inserts the two columns right after the order column.
     *
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function addColumns($columns): array
    {
        $columns = is_array($columns) ? $columns : [];
        /* translators: %s: brand name. */
        $referenceLabel = sprintf(esc_html__('%s Payment Reference', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName());
        $paymentLabel = esc_html__('Payment', 'worldline-gopay-for-woocommerce');
        $needsPayment = !array_key_exists(self::paymentKey(), $columns);
        $result = [];
        $inserted = \false;
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if (!$inserted && in_array($key, ['order_number', 'order_title', 'name'], \true)) {
                $result[self::referenceKey()] = $referenceLabel;
                if ($needsPayment) {
                    $result[self::paymentKey()] = $paymentLabel;
                }
                $inserted = \true;
            }
        }
        if (!$inserted) {
            $result[self::referenceKey()] = $referenceLabel;
            if ($needsPayment) {
                $result[self::paymentKey()] = $paymentLabel;
            }
        }
        return $result;
    }
    /**
     * HPOS custom-column renderer.
     *
     * @param string $column
     * @param mixed $order
     */
    public function renderColumn($column, $order): void
    {
        if ($order instanceof \WC_Order) {
            $this->renderCell((string) $column, $order);
        }
    }
    /**
     * Legacy posts-table custom-column renderer.
     *
     * @param string $column
     * @param mixed $postId
     */
    public function renderLegacyColumn($column, $postId): void
    {
        if ((string) $column !== self::referenceKey() && (string) $column !== self::paymentKey()) {
            return;
        }
        $order = OrderService::get((int) $postId);
        if ($order instanceof \WC_Order) {
            $this->renderCell((string) $column, $order);
        }
    }
    private function renderCell(string $column, \WC_Order $order): void
    {
        if ($column !== self::referenceKey() && $column !== self::paymentKey()) {
            return;
        }
        if (!ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        if ($column === self::referenceKey()) {
            $reference = self::portalReference((string) $order->get_meta(OrderService::META_PAYMENT_ID));
            if ($reference !== '') {
                echo '<span style="display:inline-block;padding:2px 8px;background:#f0f0f1;border-radius:3px;font-family:monospace;font-size:12px;">' . esc_html($reference) . '</span>';
            }
            return;
        }
        echo esc_html(ModuleHelper::getModuleConfig()->getName());
        $method = trim((string) $order->get_meta(OrderService::META_PAYMENT_METHOD));
        if ($method === '') {
            // No method resolved yet: Pay by Link shows "[Pay by Link]"; other method-specific gateways fall back to the checkout method (e.g. "[Google Pay]"). Generic Hosted Checkout has none.
            $gatewayId = $order->get_payment_method();
            if ($gatewayId === PayByLinkGateway::id()) {
                $method = esc_html__('Pay by Link', 'worldline-gopay-for-woocommerce');
            } elseif ($gatewayId !== ModuleHelper::getMainGatewayId()) {
                $method = trim((string) $order->get_payment_method_title());
            }
        }
        if ($method !== '') {
            echo '<br><small>[' . esc_html($method) . ']</small>';
        }
    }
}
