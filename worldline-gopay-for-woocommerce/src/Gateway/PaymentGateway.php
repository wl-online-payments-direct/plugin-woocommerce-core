<?php

namespace WOP\OnlinePayments\Gateway;

use WOP\OnlinePayments\Admin\AdminConfigPage;
use WOP\OnlinePayments\Checkout\HostedCheckoutProcessor;
use WOP\OnlinePayments\Checkout\PaymentMethodAvailability;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Repositories\PaymentMethodConfigRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\MerchantActions;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Services\Integration\LogoUrlService;
use WOP\OnlinePayments\Services\PaymentButtonTitleSetting;
defined('ABSPATH') || exit;
/**
 * The main Hosted Checkout gateway and host of the admin-ui SPA settings section.
 */
class PaymentGateway extends \WC_Payment_Gateway
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Per-request cache of the resolved Hosted Checkout logo URL. */
    private static ?string $logoUrlCache = null;
    /** Configures the WooCommerce gateway fields and native settings. */
    public function __construct()
    {
        $this->id = ModuleHelper::getMainGatewayId();
        $this->method_title = ModuleHelper::getModuleConfig()->getName();
        $this->method_description = '';
        $this->description = '';
        $this->has_fields = \false;
        $this->supports = ['products', 'refunds'];
        $this->icon = self::defaultLogoUrl();
        $this->init_form_fields();
        $this->init_settings();
        $this->title = GatewayModule::configuredTitle(PaymentProductId::hostedCheckout(), $this->get_option('title', $this->method_title));
        $this->enabled = $this->get_option('enabled', 'no');
        $buttonTitle = PaymentButtonTitleSetting::get((string) PaymentProductId::hostedCheckout());
        if ($buttonTitle !== '') {
            $this->order_button_text = $buttonTitle;
        }
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }
    /** Native settings fields: the enable toggle and the customer-facing title. */
    public function init_form_fields(): void
    {
        $this->form_fields = ['enabled' => ['title' => esc_html__('Enable/Disable', 'worldline-gopay-for-woocommerce'), 'type' => 'checkbox', 'label' => sprintf(
            /* translators: %s: plugin name */
            esc_html__('Enable %s', 'worldline-gopay-for-woocommerce'),
            $this->method_title
        ), 'default' => 'no'], 'title' => ['title' => esc_html__('Title', 'worldline-gopay-for-woocommerce'), 'type' => 'text', 'description' => esc_html__('Payment method title shown to the customer during checkout.', 'worldline-gopay-for-woocommerce'), 'default' => $this->method_title, 'desc_tip' => \true]];
    }
    /** Renders the admin-ui SPA in place of the native settings form. */
    public function admin_options(): void
    {
        $GLOBALS['hide_save_button'] = \true;
        AdminConfigPage::render();
    }
    /**
     * WooCommerce treats a gateway that "needs setup" specially in the Payments list: enabling it
     * from the list routes to the settings page instead of just flipping the gateway on. Report that
     * state until the merchant has enabled at least one Worldline payment method in the plugin, so
     * the list's "Enable" action can no longer leave the gateway active with no usable method - it
     * sends the merchant to the plugin settings (where enabling a method properly activates it).
     *
     * @return bool
     */
    public function needs_setup(): bool
    {
        return !GatewayModule::hasEnabledPaymentMethod();
    }
    /**
     * Available when Worldline offers the hosted checkout for the current cart; off-checkout
     * falls back to the gateway's enabled state.
     *
     * @return bool
     */
    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return \false;
        }
        $payOrder = OrderService::currentPayOrder();
        if (PaymentMethodAvailability::currentCartProvider($payOrder) === null) {
            return parent::is_available();
        }
        return PaymentMethodAvailability::isAvailable(PaymentProductId::hostedCheckout(), $payOrder);
    }
    /**
     * Creates a generic hosted-checkout session and redirects to it.
     *
     * @param int $order_id
     *
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        return (new HostedCheckoutProcessor())->process(wc_get_order($order_id), null);
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
        $iconUrl = function_exists('is_checkout') && is_checkout() ? self::resolveLogoUrl() : $this->icon;
        if (!$iconUrl) {
            return '';
        }
        $icon = sprintf('<img src="%s" alt="%s" style="max-height:24px;width:auto;" />', esc_url($iconUrl), esc_attr($this->get_title()));
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }
    /** The bundled default Hosted Checkout logo, used on the admin gateways screen regardless of any upload. */
    private static function defaultLogoUrl(): string
    {
        return (new LogoUrlService())->getLogoUrlIfExists((string) PaymentProductId::hostedCheckout());
    }
    /**
     * The checkout icon: the merchant's configured Hosted Checkout logo when one is set, otherwise
     * the bundled default. Reading the configured logo here is what lets an uploaded custom logo show
     * at checkout; cached per request since the gateway is constructed several times per page. Also
     * used by the block checkout registration so both checkout styles show the same logo.
     *
     * @return string
     */
    public static function resolveLogoUrl(): string
    {
        if (self::$logoUrlCache !== null) {
            return self::$logoUrlCache;
        }
        $configured = '';
        try {
            $configured = (string) StoreContext::doWithStore(self::STORE_ID, static function (): string {
                $method = ServiceRegister::getService(PaymentMethodConfigRepositoryInterface::class)->getPaymentMethod((string) PaymentProductId::hostedCheckout());
                $additionalData = $method !== null ? $method->getAdditionalData() : null;
                return $additionalData !== null && method_exists($additionalData, 'getLogo') ? (string) $additionalData->getLogo() : '';
            });
        } catch (\Throwable $e) {
            $configured = '';
        }
        if ($configured === '') {
            $configured = (new LogoUrlService())->getLogoUrlIfExists((string) PaymentProductId::hostedCheckout());
        }
        return self::$logoUrlCache = $configured;
    }
}
