<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Address;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\ContactDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\Customer;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItem;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItemCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Product;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Country;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\TaxableAmount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
defined('ABSPATH') || exit;
/**
 * Answers which Worldline methods are available for the current cart, cached per request.
 */
class PaymentMethodAvailability
{
    /** Single store. */
    private const STORE_ID = '1';
    /** @var array<string, PaymentMethodCollection|null> Per-request results, keyed by the cart they were fetched for. */
    private static array $cache = [];
    /**
     * Whether the given method is available for the current cart.
     *
     * @param PaymentProductId $productId
     * @param \WC_Order|null $payOrder An order already authorized by the caller (order-pay page), or null for the session cart.
     *
     * @return bool
     */
    public static function isAvailable(PaymentProductId $productId, ?\WC_Order $payOrder = null): bool
    {
        $collection = self::collection($payOrder);
        return $collection !== null && $collection->has($productId);
    }
    /**
     * Available methods for the current cart, fetched once per request; null on failure or no cart.
     *
     * @param \WC_Order|null $payOrder An order already authorized by the caller (order-pay page), or null for the session cart.
     *
     * @return PaymentMethodCollection|null
     */
    public static function collection(?\WC_Order $payOrder = null): ?PaymentMethodCollection
    {
        $cacheKey = $payOrder instanceof \WC_Order ? 'order-' . $payOrder->get_id() : 'cart';
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }
        self::$cache[$cacheKey] = null;
        try {
            $cartProvider = self::currentCartProvider($payOrder);
            if ($cartProvider === null) {
                return null;
            }
            $response = CheckoutAPI::get()->paymentMethods(self::STORE_ID)->getAvailablePaymentMethods($cartProvider);
            self::$cache[$cacheKey] = $response->getPaymentMethods();
        } catch (\Throwable $e) {
            self::$cache[$cacheKey] = null;
        }
        return self::$cache[$cacheKey];
    }
    /** Lets gateways reset the per-request cache if the cart changes mid-request. */
    public static function reset(): void
    {
        self::$cache = [];
    }
    /**
     * Cart provider for the current checkout request: the order being paid (order-pay page) or the
     * session cart. Null when there is nothing to pay for yet. The order is never resolved from the
     * request here; the caller passes one it has already authorized (see OrderService::authorizePayOrder).
     *
     * @param \WC_Order|null $payOrder An order already authorized by the caller, or null for the session cart.
     *
     * @return CartProvider|null
     */
    public static function currentCartProvider(?\WC_Order $payOrder = null): ?CartProvider
    {
        if (!function_exists('WC')) {
            return null;
        }
        if ($payOrder instanceof \WC_Order) {
            return new WooCommerceCartProvider($payOrder);
        }
        $wc = WC();
        if ($wc->cart === null || $wc->cart->is_empty()) {
            return null;
        }
        $currency = Currency::fromIsoCode(get_woocommerce_currency());
        $total = Amount::fromFloat((float) $wc->cart->get_total('edit'), $currency);
        $country = '';
        if ($wc->customer !== null) {
            $country = (string) ($wc->customer->get_billing_country() ?: $wc->customer->get_shipping_country());
        }
        if ($country === '' && function_exists('wc_get_base_location')) {
            $base = wc_get_base_location();
            $country = (string) ($base['country'] ?? '');
        }
        $email = $wc->customer !== null ? (string) $wc->customer->get_billing_email() : '';
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($email === '' && $userId > 0 && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            $email = $user ? (string) $user->user_email : '';
        }
        $merchantCustomerId = $userId > 0 ? (string) $userId : '';
        $address = new Address(Country::fromIsoCode($country), '', '', '', '', '');
        $customer = new Customer(new ContactDetails($email), $address, $merchantCustomerId, $userId === 0);
        return new SimpleCartProvider(new Cart('', $total, null, $customer, self::buildSessionLineItems($wc->cart, $currency)));
    }
    /**
     * Line items for the session cart, carrying the product id so the core can match each item
     * against its assigned product type (used to decide Mealvouchers eligibility).
     */
    private static function buildSessionLineItems(\WC_Cart $cart, Currency $currency): LineItemCollection
    {
        $items = [];
        foreach ($cart->get_cart() as $cartItem) {
            $productId = (int) ($cartItem['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int) ($cartItem['quantity'] ?? 1));
            $totalExcl = (float) ($cartItem['line_total'] ?? 0);
            $totalIncl = $totalExcl + (float) ($cartItem['line_tax'] ?? 0);
            $product = $cartItem['data'] ?? null;
            $name = $product instanceof \WC_Product ? (string) $product->get_name() : '';
            $sku = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
            $items[] = new LineItem(new Product((string) $productId, $name, $sku), TaxableAmount::fromAmounts(Amount::fromFloat($totalExcl / $quantity, $currency), Amount::fromFloat($totalIncl / $quantity, $currency)), $quantity);
        }
        return new LineItemCollection($items);
    }
}
