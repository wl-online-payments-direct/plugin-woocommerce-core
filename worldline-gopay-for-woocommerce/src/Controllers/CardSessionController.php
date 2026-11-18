<?php

namespace WOP\OnlinePayments\Controllers;

use WOP\OnlinePayments\Checkout\PaymentMethodAvailability;
use WOP\OnlinePayments\Checkout\WooCommerceCartProvider;
use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Services\SavedCardsSetting;
defined('ABSPATH') || exit;
/**
 * Creates a Worldline hosted-tokenization session for the current checkout cart and returns its iframe URL.
 */
class CardSessionController
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Registers the tokenization-session REST route.
     */
    public function registerRoutes(): void
    {
        register_rest_route(ModuleHelper::getRestNamespace(), '/card/tokenization-session', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // Nonce-gated to stop anonymous session-creation spam at the Worldline API.
            'permission_callback' => static function ($request) {
                return (bool) wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest');
            },
        ]);
    }
    /**
     * Creates the hosted-tokenization session and returns its iframe URL.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        try {
            // REST requests do not get the WooCommerce session cart loaded by default.
            if (function_exists('WC') && function_exists('wc_load_cart') && WC()->cart === null) {
                wc_load_cart();
            }
            // Read-only: detach the session save so it can't race a checkout and resurrect a cleared cart.
            if (function_exists('WC') && WC()->session !== null) {
                remove_action('shutdown', [WC()->session, 'save_data'], 20);
            }
            // The order-pay cart comes only from the explicit, authorized orderId + key; otherwise the session cart.
            $cartProvider = $this->orderCartProvider($request) ?? PaymentMethodAvailability::currentCartProvider();
            if ($cartProvider === null) {
                return new \WP_REST_Response(['success' => \false], 400);
            }
            if ((string) $request->get_param('savedCards') === '1') {
                return $this->savedCardsSession($cartProvider);
            }
            $productId = null;
            $requested = (string) $request->get_param('productId');
            if ($requested !== '') {
                $productId = PaymentProductId::parse($requested);
                if (!$productId->isCardBrand()) {
                    return new \WP_REST_Response(['success' => \false], 400);
                }
            }
            $response = CheckoutAPI::get()->hostedTokenization(self::STORE_ID)->crate($cartProvider, $productId);
            $url = $response->isSuccessful() ? $response->getHostedTokenization()->getUrl() : '';
            if ($url === '') {
                return new \WP_REST_Response(['success' => \false], 502);
            }
            return new \WP_REST_Response(['success' => \true, 'url' => $url]);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['success' => \false], 500);
        }
    }
    /**
     * Cart provider for the order-pay page. The order already exists (no active session cart),
     * so the cart is rebuilt from it. The order key and ownership are checked the way WooCommerce
     * authorizes its own order-pay page (OrderService::authorizePayOrder). Null when the request is
     * not an authorized order-pay request, so the caller falls back to the session cart.
     *
     * @param \WP_REST_Request $request
     *
     * @return CartProvider|null
     */
    private function orderCartProvider($request): ?CartProvider
    {
        $order = OrderService::authorizePayOrder(absint($request->get_param('orderId')), (string) $request->get_param('key'), (int) get_current_user_id());
        return $order !== null ? new WooCommerceCartProvider($order) : null;
    }
    /**
     * Session for paying with a saved card, mapping WooCommerce token ids to Worldline token ids.
     *
     * @param \OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider $cartProvider
     *
     * @return \WP_REST_Response
     */
    private function savedCardsSession($cartProvider): \WP_REST_Response
    {
        if (!SavedCardsSetting::isEnabled()) {
            return new \WP_REST_Response(['success' => \true, 'url' => '', 'wcTokens' => []]);
        }
        $valid = CheckoutAPI::get()->paymentMethods(self::STORE_ID)->getAvailablePaymentMethods($cartProvider)->getValidTokensResponse();
        if ($valid === null) {
            return new \WP_REST_Response(['success' => \true, 'url' => '', 'wcTokens' => []]);
        }
        $map = [];
        if (class_exists(\WC_Payment_Tokens::class)) {
            foreach (\WC_Payment_Tokens::get_customer_tokens(get_current_user_id()) as $wcToken) {
                if (ModuleHelper::ownsGateway((string) $wcToken->get_gateway_id())) {
                    $map[(string) $wcToken->get_id()] = $wcToken->get_token();
                }
            }
        }
        return new \WP_REST_Response(['success' => \true, 'url' => $valid->getHostedTokenization()->getUrl(), 'wcTokens' => $map]);
    }
}
