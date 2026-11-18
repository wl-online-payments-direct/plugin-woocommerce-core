<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
defined('ABSPATH') || exit;
/**
 * Standalone "payment not completed" page for a cancelled or failed Pay by Link payment.
 */
class PayByLinkReturnHandler
{
    /** Brand-prefixed query var, derived at runtime so each brand is unique. */
    private static function queryVar(): string
    {
        return ModuleHelper::moduleKey('_paylink_failed');
    }
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRender']);
    }
    /** URL of the failed page for the order, gated by the order key (no session needed). */
    public static function failedUrl(\WC_Order $order): string
    {
        return add_query_arg([self::queryVar() => '1', 'order' => $order->get_id(), 'key' => $order->get_order_key()], home_url('/'));
    }
    public function maybeRender(): void
    {
        if (!isset($_GET[self::queryVar()])) {
            return;
        }
        $order = OrderService::get(isset($_GET['order']) ? absint($_GET['order']) : 0);
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (!$order instanceof \WC_Order || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $shopUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
        get_header();
        ModuleHelper::renderTemplate('checkout/pay-by-link-failed.php', ['shopUrl' => $shopUrl]);
        get_footer();
        exit;
    }
}
