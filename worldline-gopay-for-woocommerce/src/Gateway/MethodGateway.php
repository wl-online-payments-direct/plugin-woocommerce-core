<?php

namespace WOP\OnlinePayments\Gateway;

use WOP\OnlinePayments\Checkout\HostedCheckoutProcessor;
use WOP\OnlinePayments\Checkout\PaymentMethodAvailability;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\MerchantActions;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Services\Integration\LogoUrlService;
use WOP\OnlinePayments\Services\PaymentButtonTitleSetting;
defined('ABSPATH') || exit;
/**
 * A single Worldline payment method (iDEAL, PayPal, Google Pay, …) as its own WooCommerce
 * checkout field. Registered only for admin-enabled methods and hidden from the admin
 * Payments list; pays through the hosted checkout with this product id.
 */
class MethodGateway extends \WC_Payment_Gateway
{
    protected PaymentProductId $productId;
    /**
     * Configures the WooCommerce gateway fields for this payment product.
     *
     * @param PaymentProductId $productId
     * @param string $label
     */
    public function __construct(PaymentProductId $productId, string $label)
    {
        $this->productId = $productId;
        $this->id = MethodCatalog::gatewayId($productId);
        $this->method_title = $label;
        $this->method_description = '';
        $this->description = '';
        $this->title = $label;
        $this->has_fields = \false;
        $this->enabled = GatewayModule::isMainGatewayEnabled() ? 'yes' : 'no';
        $this->supports = ['products', 'refunds'];
        $this->icon = (new LogoUrlService())->getLogoUrlIfExists((string) $productId);
        $buttonTitle = PaymentButtonTitleSetting::get($this->buttonTitleProductId());
        if ($buttonTitle !== '') {
            $this->order_button_text = $buttonTitle;
        }
    }
    /**
     * Product id whose configured button title applies to this gateway; card gateways override
     * this to the grouped Cards config.
     *
     * @return string
     */
    protected function buttonTitleProductId(): string
    {
        return (string) $this->productId;
    }
    /**
     * Available when Worldline offers this method for the current cart; off-checkout
     * falls back to the gateway's enabled state.
     *
     * @return bool
     */
    public function is_available(): bool
    {
        if (!GatewayModule::isMainGatewayEnabled()) {
            return \false;
        }
        $payOrder = OrderService::currentPayOrder();
        if (PaymentMethodAvailability::currentCartProvider($payOrder) === null) {
            return parent::is_available();
        }
        return PaymentMethodAvailability::isAvailable($this->productId, $payOrder);
    }
    /**
     * Creates a hosted-checkout session for this product and redirects to it.
     *
     * @param int $order_id
     *
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        return (new HostedCheckoutProcessor())->process(wc_get_order($order_id), $this->productId);
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
    /**
     * Constrains the icon height so it matches the other method icons.
     *
     * @return string
     */
    public function get_icon(): string
    {
        if (!$this->icon) {
            return '';
        }
        $icon = sprintf('<img src="%s" alt="%s" style="max-height:24px;width:auto;" />', esc_url($this->icon), esc_attr($this->get_title()));
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }
}
