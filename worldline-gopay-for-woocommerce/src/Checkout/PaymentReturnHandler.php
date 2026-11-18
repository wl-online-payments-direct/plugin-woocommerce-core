<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * Routes the order-received (thank-you) page by payment outcome: render, retry redirect, or waiting spinner.
 */
class PaymentReturnHandler
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * How long after an order is placed, paid or has a Worldline payment started the customer sees it without
     * WooCommerce's second verification step (WooCommerce's own guest grace period is 10 minutes).
     */
    private const RETURN_WINDOW_SECONDS = 30 * 60;
    /** Hooks into the thank-you page. */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'syncPayByLinkReturn']);
        add_action('woocommerce_before_thankyou', [$this, 'onThankYou'], 10, 1);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'allowRetryStatuses'], 10, 2);
        add_filter('woocommerce_order_email_verification_required', [$this, 'skipEmailVerification'], 10, 3);
        add_filter('woocommerce_order_received_verify_known_shoppers', [$this, 'skipKnownShopperVerification'], 10, 1);
    }
    /**
     * Skips WooCommerce's "known shopper must be logged in" gate on the order-received page for our
     * gateway's orders, right after payment only. An order tied to a customer account (e.g. a Pay by Link
     * order created for a registered customer in the admin) is otherwise shown only a "Log in here to
     * view your order" stub when the shopper pays from a fresh/incognito session where they are not
     * logged in as that customer. The login gate protects leaked order URLs (the order key alone is not
     * enough), so it is skipped only within RETURN_WINDOW_SECONDS of the order being placed, paid or
     * having a Worldline payment started; later visits get WooCommerce's default. The filter carries no
     * order argument, so the order is resolved from the order-received query var (the same source the
     * confirmation block uses).
     *
     * @param bool $required
     *
     * @return bool
     */
    public function skipKnownShopperVerification($required): bool
    {
        $orderId = function_exists('get_query_var') ? absint(get_query_var('order-received')) : 0;
        if ($orderId <= 0) {
            return (bool) $required;
        }
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && ModuleHelper::ownsGateway((string) $order->get_payment_method()) && self::isWithinReturnWindow($order)) {
            return \false;
        }
        return (bool) $required;
    }
    /**
     * Skips WooCommerce's guest "Confirm email and view order" gate for our gateway's orders, right after
     * payment only: on the order-received page, and on the order-pay page a failed payment redirects to for
     * a retry. When a customer returns from the Worldline hosted payment page their WooCommerce session can
     * be lost on the cross-site redirect, or the 10-minute verification grace period may have elapsed during
     * payment; WooCommerce would then show the email-verification form instead of the order details. The
     * email gate protects leaked order URLs (the order key alone is not enough), so it is skipped only within
     * RETURN_WINDOW_SECONDS of the order being placed, paid or having a Worldline payment started; later visits
     * get WooCommerce's default.
     *
     * @param bool $required
     * @param mixed $order
     * @param string $context
     *
     * @return bool
     */
    public function skipEmailVerification($required, $order = null, $context = ''): bool
    {
        if (in_array($context, ['order-received', 'order-pay'], \true) && $order instanceof \WC_Order && ModuleHelper::ownsGateway((string) $order->get_payment_method()) && self::isWithinReturnWindow($order)) {
            return \false;
        }
        return (bool) $required;
    }
    /**
     * Brings a Pay by Link order up to date when the customer lands on its order-received page, before
     * WooCommerce decides whether to show it. A payment link is paid on Worldline's side, so the shop does not
     * know the payment happened until a webhook arrives; the core asks Worldline for the link's payment instead.
     * This must run ahead of WooCommerce's verification step, which is decided before woocommerce_before_thankyou:
     * otherwise a customer who is not recognised (e.g. paying from a fresh session) gets a login or email prompt
     * and the order stays unpaid until a webhook arrives. A successful payment sets the paid date, which opens the
     * return window; any other outcome is sent to the "payment not completed" page.
     */
    public function syncPayByLinkReturn(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-received')) {
            return;
        }
        $order = OrderService::get(absint(get_query_var('order-received')));
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if ($order === null || $order->get_payment_method() !== PayByLinkGateway::id() || $key === '' || !hash_equals((string) $order->get_order_key(), (string) $key) || self::isSuccessStatus($order)) {
            return;
        }
        $orderId = $order->get_id();
        try {
            CheckoutAPI::get()->payment(self::STORE_ID)->startWaitingForOutcome(null, null, OrderService::merchantReference($order));
        } catch (\Throwable $e) {
        }
        $order = OrderService::get($orderId);
        if ($order !== null && !self::isSuccessStatus($order)) {
            wp_safe_redirect(PayByLinkReturnHandler::failedUrl($order));
            exit;
        }
    }
    /**
     * Whether the order was placed, paid or had a Worldline payment started within RETURN_WINDOW_SECONDS, i.e.
     * the visit can be the customer's return from Worldline (or the retry redirect right after it). Only
     * server-side events count, so replaying an old URL cannot reopen the window. The last-modified date is
     * deliberately not used: WooCommerce saves admin-created orders on every order-received visit.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    private static function isWithinReturnWindow(\WC_Order $order): bool
    {
        $latest = max((int) $order->get_meta(OrderService::META_PAYMENT_STARTED_AT), (int) $order->get_meta(OrderService::META_PAYMENT_CONFIRMED_AT));
        foreach ([$order->get_date_created(), $order->get_date_paid()] as $date) {
            if ($date instanceof \WC_DateTime) {
                $latest = max($latest, $date->getTimestamp());
            }
        }
        return $latest > 0 && time() - $latest <= self::RETURN_WINDOW_SECONDS;
    }
    /**
     * Records that a Worldline payment was started for the order, which opens the return window - but only
     * when the payer may already see the order without that window: its logged-in owner, the checkout session
     * that placed it, a session carrying the order's billing email (WooCommerce's own guest rule), or a retry
     * while the window is still open. WooCommerce's order-pay form accepts a payment start from anyone holding
     * the order key, so a leaked pay link must not be able to reopen the window this way.
     *
     * @param \WC_Order $order Order to update; the caller saves it.
     */
    public static function recordPaymentStart(\WC_Order $order): void
    {
        if (self::isKnownPayer($order) || self::isWithinReturnWindow($order)) {
            $order->update_meta_data(OrderService::META_PAYMENT_STARTED_AT, (string) time());
        }
    }
    /**
     * Whether the current visitor is identified as the order's payer independently of the order key.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    private static function isKnownPayer(\WC_Order $order): bool
    {
        $customerId = (int) $order->get_customer_id();
        if ($customerId > 0) {
            return $customerId === (int) get_current_user_id();
        }
        $session = function_exists('WC') ? WC()->session : null;
        if (!$session instanceof \WC_Session) {
            return \false;
        }
        $orderId = (int) $order->get_id();
        if ((int) $session->get('order_awaiting_payment') === $orderId || (int) $session->get('store_api_draft_order') === $orderId) {
            return \true;
        }
        $customer = $session->get('customer');
        $email = is_array($customer) ? (string) ($customer['email'] ?? '') : '';
        return $email !== '' && strtolower($email) === strtolower((string) $order->get_billing_email());
    }
    /**
     * Lets the customer re-pay a cancelled/failed Worldline order on the order-pay page. WooCommerce
     * treats only pending/failed as payable, so a cancelled order would otherwise fall back to the
     * checkout page - which is empty because the cart was cleared when the order was placed. Keeping
     * the order payable means a cancelled payment is retried on the existing order, with no empty cart.
     *
     * @param array<int, string> $statuses
     * @param mixed $order
     *
     * @return array<int, string>
     */
    public function allowRetryStatuses($statuses, $order = null): array
    {
        if (!$order instanceof \WC_Order || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return (array) $statuses;
        }
        if (self::isStoreApiRequest()) {
            if (OrderService::rawPaymentId($order) !== '') {
                return array_values(array_diff((array) $statuses, [$order->get_status()]));
            }
            return (array) $statuses;
        }
        return array_values(array_unique(array_merge((array) $statuses, [OrderStatuses::cancelled(), OrderStatuses::error()])));
    }
    /**
     * Whether the current request is a WooCommerce Store API (block checkout) request.
     *
     * @return bool
     */
    private static function isStoreApiRequest(): bool
    {
        return function_exists('WC') && method_exists(WC(), 'is_store_api_request') && WC()->is_store_api_request();
    }
    /**
     * Routes the thank-you page by payment outcome: render, retry redirect, or waiting spinner.
     *
     * @param int $orderId
     */
    public function onThankYou($orderId): void
    {
        $order = OrderService::get($orderId);
        if ($order === null || !ModuleHelper::ownsGateway($order->get_payment_method())) {
            return;
        }
        // Pay by Link returns are reconciled earlier, in syncPayByLinkReturn().
        if ($order->get_payment_method() === PayByLinkGateway::id()) {
            return;
        }
        if (OrderService::rawPaymentId($order) === '') {
            return;
        }
        switch (self::outcome($order)) {
            case 'success':
                return;
            case 'fail':
                $this->renderRedirect(self::retryUrl($order));
                return;
            default:
                self::startBackgroundWait($order);
                $this->renderWaiting($order);
        }
    }
    /**
     * Starts the core background process that polls Worldline for the final outcome and updates the order.
     *
     * @param \WC_Order $order
     */
    public static function startBackgroundWait(\WC_Order $order): void
    {
        $paymentId = OrderService::paymentId($order);
        if ($paymentId === null) {
            return;
        }
        $returnHmac = OrderService::returnHmac($order);
        try {
            CheckoutAPI::get()->payment(self::STORE_ID)->startWaitingForOutcomeInBackground($paymentId, $returnHmac !== '' ? $returnHmac : null);
        } catch (\Throwable $e) {
        }
    }
    /**
     * Asks the core to refresh the order from the latest Worldline outcome.
     *
     * @param \WC_Order $order
     */
    public static function reconcile(\WC_Order $order): void
    {
        $paymentId = OrderService::paymentId($order);
        if ($paymentId === null) {
            return;
        }
        $returnHmac = OrderService::returnHmac($order);
        try {
            CheckoutAPI::get()->payment(self::STORE_ID)->updateOrderStatus($paymentId, $returnHmac !== '' ? $returnHmac : null);
        } catch (\Throwable $e) {
        }
    }
    /**
     * Whether the order reached a successful Worldline status (paid/captured or authorized). Routes
     * pay-by-link returns without relying on the payment-id meta, which pay-by-link does not persist
     * unless a webhook fires - so a successful payment would otherwise be misread as failed.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    private static function isSuccessStatus(\WC_Order $order): bool
    {
        $status = $order->get_status();
        return $order->is_paid() || $status === OrderStatuses::captured() || $status === OrderStatuses::authorized();
    }
    /**
     * Coarse payment outcome ('success', 'fail' or 'pending') derived from the order status.
     *
     * A payment still in a pending status is reported as 'fail' only on the final check ($final, the
     * waiting page's forced last poll, after a synchronous refresh). Earlier checks keep waiting: the
     * refresh from Worldline can still be running in the core's background wait for the same payment
     * (it holds the transaction lock), so the order may be updated a moment later.
     *
     * @param \WC_Order $order
     * @param bool $final
     *
     * @return string
     */
    public static function outcome(\WC_Order $order, bool $final = \false): string
    {
        $paymentId = OrderService::paymentId($order);
        if ($paymentId === null) {
            return 'pending';
        }
        $returnHmac = OrderService::returnHmac($order);
        try {
            $response = CheckoutAPI::get()->payment(self::STORE_ID)->getPaymentOutcome($paymentId, $returnHmac !== '' ? $returnHmac : null);
        } catch (\Throwable $e) {
            return 'pending';
        }
        if ($response->isWaiting()) {
            return 'pending';
        }
        $statusCode = $response->getPaymentTransaction()->getStatusCode();
        if ($statusCode->isCanceledOrRejected()) {
            return 'fail';
        }
        if ($statusCode->isPending()) {
            // The order may already be updated (paid or authorized) while the transaction read above is not.
            $fresh = OrderService::get($order->get_id());
            if ($fresh !== null && self::isSuccessStatus($fresh)) {
                return 'success';
            }
            return $final ? 'fail' : 'pending';
        }
        return 'success';
    }
    /**
     * Where to send the customer to retry after an unsuccessful payment: the order-pay page.
     *
     * @param \WC_Order $order
     *
     * @return string
     */
    public static function retryUrl(\WC_Order $order): string
    {
        return $order->needs_payment() ? $order->get_checkout_payment_url() : wc_get_checkout_url();
    }
    /**
     * Adds the failure notice and sends the customer to $url client-side.
     *
     * @param string $url
     */
    private function renderRedirect(string $url): void
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice(esc_html__('Your payment was not completed. Please try again.', 'worldline-gopay-for-woocommerce'), 'error');
        }
        echo '<script>window.location.replace(' . wp_json_encode($url) . ');</script>';
    }
    /**
     * Renders the waiting box and enqueues the script polling the payment-status endpoint.
     *
     * @param \WC_Order $order
     */
    private function renderWaiting(\WC_Order $order): void
    {
        $statusUrl = add_query_arg(['orderId' => $order->get_id(), 'key' => $order->get_order_key()], rest_url(ModuleHelper::getRestNamespace() . '/payment-status'));
        $waitHandle = ModuleHelper::moduleKey('-payment-waiting');
        wp_register_script($waitHandle, ModuleHelper::getAssetUrl('checkout/payment-waiting.js'), [], ModuleHelper::getModuleConfig()->getVersion(), \true);
        wp_localize_script($waitHandle, ModuleHelper::moduleKey('Wait'), ['url' => $statusUrl, 'interval' => 2000, 'max' => 8, 'slow' => 15000, 'contactDelay' => 12000]);
        wp_enqueue_script($waitHandle);
        $message = esc_html__('Processing your payment, please wait…', 'worldline-gopay-for-woocommerce');
        $paymentId = OrderService::paymentId($order);
        $transactionId = $paymentId !== null ? $paymentId->getTransactionId() : '';
        $storeEmail = (string) (get_option('woocommerce_email_from_address') ?: get_option('admin_email'));
        $contactUrl = (string) apply_filters(ModuleHelper::moduleKey('_payment_wait_contact_url'), $storeEmail !== '' ? 'mailto:' . $storeEmail : '');
        $template = __DIR__ . '/../../templates/checkout/payment-waiting.php';
        if (is_readable($template)) {
            include $template;
        }
    }
}
