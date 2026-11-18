<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Checkout\WooCommerceCartProvider;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentLinks\PaymentLinkRequest;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Order-edit screen "Pay by Link" box: generates and displays a Worldline payment link for an
 * unpaid order.
 */
class PayByLinkBox
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Per-order expiration meta key (kept as-is across brands). */
    private const META_EXPIRES = '_worldline_payment_link_expires';
    private static function nonceAction(): string
    {
        return ModuleHelper::moduleKey('_pay_by_link');
    }
    private static function ajaxAction(): string
    {
        return ModuleHelper::moduleKey('_generate_payment_link');
    }
    private static function metaboxId(): string
    {
        return ModuleHelper::moduleKey('-pay-by-link');
    }
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
        add_action('wp_ajax_' . self::ajaxAction(), [$this, 'ajaxGenerate']);
    }
    /**
     * @param string $postType
     * @param mixed  $post
     */
    public function addMetaBox($postType = '', $post = null): void
    {
        $order = $this->currentOrder($post);
        if ($order === null || !$this->shouldShow($order)) {
            return;
        }
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box(self::metaboxId(), sprintf(
            /* translators: %s: brand name. */
            esc_html__('%s Pay by Link', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        ), [$this, 'render'], $screen, 'side', 'default');
    }
    /**
     * @param mixed $object The WC_Order (HPOS) or WP_Post for the current order screen.
     */
    public function render($object): void
    {
        $order = $object instanceof \WC_Order ? $object : $this->currentOrder($object);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $existing = $this->existingLink($order);
        if ($existing !== null) {
            $this->renderLink($existing, (string) $order->get_meta(self::META_EXPIRES));
            return;
        }
        $this->renderForm($order);
    }
    public function ajaxGenerate(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer(self::nonceAction(), 'nonce', \false)) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to perform this action.', 'worldline-gopay-for-woocommerce')], 403);
        }
        $order = OrderService::get(absint($_POST['order'] ?? 0));
        if (!$order instanceof \WC_Order) {
            wp_send_json_error(['message' => esc_html__('Order not found.', 'worldline-gopay-for-woocommerce')]);
        }
        $expiresAt = $this->parseExpiration(isset($_POST['expires']) ? sanitize_text_field(wp_unslash($_POST['expires'])) : '');
        if ($expiresAt === null) {
            wp_send_json_error(['message' => esc_html__('Enter a valid expiration date (today up to 6 months ahead).', 'worldline-gopay-for-woocommerce')]);
        }
        try {
            $response = AdminAPI::get()->paymentLinks(self::STORE_ID)->create(new PaymentLinkRequest(new WooCommerceCartProvider($order), $order->get_checkout_order_received_url(), $expiresAt));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
        $url = $response->getRedirectUrl();
        if (!$url) {
            /* translators: %s: brand name. */
            wp_send_json_error(['message' => sprintf(esc_html__('%s did not return a payment link.', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName())]);
        }
        $order->update_meta_data(self::META_EXPIRES, $expiresAt->format('Y-m-d'));
        $order->save();
        wp_send_json_success(['message' => esc_html__('Payment link generated.', 'worldline-gopay-for-woocommerce')]);
    }
    /** The Worldline payment link for the order if a non-expired one exists, otherwise null. */
    private function existingLink(\WC_Order $order): ?string
    {
        try {
            $response = AdminAPI::get()->paymentLinks(self::STORE_ID)->get(OrderService::merchantReference($order));
            $url = $response->getRedirectUrl();
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    private function renderLink(string $url, string $expires): void
    {
        ModuleHelper::renderTemplate('order/pay-by-link-existing.php', ['url' => $url, 'expires' => $expires]);
    }
    private function renderForm(\WC_Order $order): void
    {
        $settings = $this->settings();
        $days = max(1, (int) ($settings['expirationTime'] ?? 7));
        $today = new \DateTime('now', wp_timezone());
        $default = (clone $today)->modify('+' . $days . ' days');
        $max = (clone $today)->modify('+6 months');
        ModuleHelper::renderTemplate('order/pay-by-link-form.php', ['brandName' => ModuleHelper::getModuleConfig()->getName(), 'defaultDate' => $default->format('Y-m-d'), 'minDate' => $today->format('Y-m-d'), 'maxDate' => $max->format('Y-m-d'), 'orderId' => (int) $order->get_id(), 'nonce' => wp_create_nonce(self::nonceAction()), 'ajaxAction' => self::ajaxAction()]);
    }
    /**
     * Whether the box should appear: a Pay by Link order that is still unpaid (pending or failed).
     */
    private function shouldShow(\WC_Order $order): bool
    {
        return (string) $order->get_payment_method() === PayByLinkGateway::id() && in_array($order->get_status(), [OrderStatuses::pending(), OrderStatuses::error()], \true);
    }
    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        try {
            $all = AdminAPI::get()->generalSettings(self::STORE_ID)->getGeneralSettings()->toArray();
            return is_array($all['payByLinkSettings'] ?? null) ? $all['payByLinkSettings'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    private function parseExpiration(string $value): ?\DateTime
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value, wp_timezone());
        if ($date === \false || $date->format('Y-m-d') !== $value) {
            return null;
        }
        $date->setTime(23, 59, 59);
        $today = (new \DateTime('now', wp_timezone()))->setTime(0, 0, 0);
        $max = (new \DateTime('now', wp_timezone()))->modify('+6 months')->setTime(23, 59, 59);
        if ($date < $today || $date > $max) {
            return null;
        }
        return $date;
    }
    /**
     * @param mixed $post
     */
    private function currentOrder($post): ?\WC_Order
    {
        if ($post instanceof \WC_Order) {
            return $post;
        }
        if (is_object($post) && isset($post->ID)) {
            return OrderService::get($post->ID);
        }
        $id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        return $id > 0 ? OrderService::get($id) : null;
    }
}
