<?php

namespace WOP\OnlinePayments\Gateway;

use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\MerchantActions;
defined('ABSPATH') || exit;
/**
 * The admin-only Worldline "Pay by Link" payment method; never offered on the storefront checkout.
 */
class PayByLinkGateway extends \WC_Payment_Gateway
{
    public const ID_SUFFIX = '-paybylink';
    public function __construct(string $title)
    {
        $this->id = self::id();
        $this->method_title = $title;
        $this->method_description = '';
        $this->description = '';
        $this->title = $title;
        $this->has_fields = \false;
        $this->enabled = GatewayModule::isMainGatewayEnabled() ? 'yes' : 'no';
        $this->supports = ['products', 'refunds'];
    }
    public static function id(): string
    {
        return ModuleHelper::getModuleConfig()->getGatewayPrefix() . self::ID_SUFFIX;
    }
    /** Never offered on the storefront checkout; payment is collected through the generated link. */
    public function is_available(): bool
    {
        return \false;
    }
    /**
     * @param int|string $order_id
     *
     * @return array<string, string>
     */
    public function process_payment($order_id): array
    {
        return ['result' => 'failure'];
    }
    /**
     * Offer refunds only while Worldline still has a refundable (captured, not fully refunded)
     * amount, so the button is hidden for authorized-but-uncaptured or fully-refunded orders.
     *
     * @param \WC_Order|false $order
     *
     * @return bool
     */
    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && parent::can_refund_order($order) && MerchantActions::isRefundable($order);
    }
    /**
     * @param int|string $order_id
     * @param float|null  $amount
     * @param string      $reason
     *
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('worldline_no_order', esc_html__('Order not found.', 'worldline-gopay-for-woocommerce'));
        }
        return MerchantActions::refund($order, $amount !== null ? (float) $amount : null, (string) $reason);
    }
}
