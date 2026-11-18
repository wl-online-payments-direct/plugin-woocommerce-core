<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Address;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\ContactDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\Customer;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\Device;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\PersonalInformation;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItem;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItemCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Product;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Shipping;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\ShippingMethod;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Country;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\TaxableAmount;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
defined('ABSPATH') || exit;
/**
 * Builds the core Cart from a WC_Order (used when creating the payment).
 */
class WooCommerceCartProvider implements CartProvider
{
    /**
     * Logical device field => brand-prefixed POST field carrying the browser value collected at checkout.
     *
     * @return array<string, string>
     */
    public static function deviceFields(): array
    {
        $prefix = ModuleHelper::getModuleConfig()->getGatewayPrefix();
        return ['colorDepth' => $prefix . '_device_color_depth', 'screenHeight' => $prefix . '_device_screen_height', 'screenWidth' => $prefix . '_device_screen_width', 'timezoneOffset' => $prefix . '_device_timezone_offset', 'javaEnabled' => $prefix . '_device_java_enabled'];
    }
    private \WC_Order $order;
    private ?string $descriptor;
    /**
     * @param \WC_Order $order
     * @param string|null $descriptor Soft descriptor to send (order.references.descriptor); null leaves it unset.
     */
    public function __construct(\WC_Order $order, ?string $descriptor = null)
    {
        $this->order = $order;
        $this->descriptor = $descriptor;
    }
    /**
     * @return Cart
     */
    public function get(): Cart
    {
        $currency = Currency::fromIsoCode($this->order->get_currency());
        $cart = new Cart(OrderService::merchantReferenceFor((int) $this->order->get_id()), Amount::fromFloat((float) $this->order->get_total(), $currency), $this->buildTotalInEur($currency), $this->buildCustomer(), $this->buildLineItems($currency), $this->buildShipping($currency), $this->buildDiscount($currency));
        if ($this->descriptor !== null && $this->descriptor !== '') {
            $cart->setDescriptor($this->descriptor);
        }
        return $cart;
    }
    /**
     * Order total as a EUR amount, or null when the order is not in EUR.
     *
     * @param Currency $currency
     *
     * @return Amount|null
     */
    private function buildTotalInEur(Currency $currency): ?Amount
    {
        if (strtoupper($currency->getIsoCode()) !== 'EUR') {
            return null;
        }
        return Amount::fromFloat((float) $this->order->get_total(), $currency);
    }
    /**
     * Customer details (contact, billing address, locale, device) from the order.
     *
     * @return Customer
     */
    private function buildCustomer(): Customer
    {
        $contact = new ContactDetails((string) $this->order->get_billing_email(), (string) $this->order->get_billing_phone());
        $customerId = $this->order->get_customer_id();
        return new Customer($contact, $this->buildAddress('billing'), $customerId ? (string) $customerId : '', !$customerId, $this->resolveLocale(), $this->buildDevice());
    }
    /**
     * Address from the order's 'billing' or 'shipping' fields.
     *
     * @param string $type Either 'billing' or 'shipping'.
     *
     * @return Address
     */
    private function buildAddress(string $type): Address
    {
        $get = fn(string $field): string => (string) $this->order->{"get_{$type}_{$field}"}();
        $country = $get('country') ?: (string) $this->order->get_billing_country();
        return new Address(Country::fromIsoCode($country), $get('state'), $get('city'), $get('postcode'), trim($get('address_1')), trim($get('address_2')), new PersonalInformation($get('first_name'), $get('last_name')), $get('company'));
    }
    /**
     * Browser/device data (accept header, user agent, IP) of the current request.
     *
     * @return Device
     */
    private function buildDevice(): Device
    {
        return new Device(isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '', isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', class_exists('WC_Geolocation') ? (string) \WC_Geolocation::get_ip_address() : (string) $this->order->get_customer_ip_address(), (int) $this->postedDeviceField('colorDepth', '24'), $this->postedDeviceField('screenHeight', '1080'), $this->postedDeviceField('screenWidth', '1920'), $this->postedDeviceField('timezoneOffset', ''), filter_var($this->postedDeviceField('javaEnabled', 'false'), \FILTER_VALIDATE_BOOLEAN));
    }
    /**
     * Browser value submitted with the order (block checkout copies the card form's
     * device data into $_POST), or the default when absent.
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    private function postedDeviceField(string $key, string $default): string
    {
        $field = self::deviceFields()[$key] ?? '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- non-sensitive 3DS device metrics; the payment itself is nonce-gated by WooCommerce.
        return $field !== '' && isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : $default;
    }
    /**
     * Order products as line items with the PRE-discount per-unit excl/incl-tax price; the
     * coupon discount is sent once at order level (see buildDiscount) to avoid double-counting.
     *
     * @param Currency $currency
     *
     * @return LineItemCollection
     */
    private function buildLineItems(Currency $currency): LineItemCollection
    {
        $items = [];
        foreach ($this->order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $quantity = max(1, (int) $item->get_quantity());
            $subtotalExcl = (float) $item->get_subtotal();
            $subtotalIncl = $subtotalExcl + (float) $item->get_subtotal_tax();
            $unitPrice = TaxableAmount::fromAmounts(Amount::fromFloat($subtotalExcl / $quantity, $currency), Amount::fromFloat($subtotalIncl / $quantity, $currency));
            $product = $item->get_product();
            $sku = $product ? (string) $product->get_sku() : '';
            // Virtual, not downloadable: a downloadable product  ($p->is_downloadable() = true) can still be shipped.
            $digital = $product && $product->is_virtual();
            $lineItem = new LineItem(new Product((string) $item->get_product_id(), (string) $item->get_name(), $sku, '', 'piece', $digital), $unitPrice, $quantity);
            if (method_exists($lineItem, 'setLineTotal')) {
                $lineItem->setLineTotal(TaxableAmount::fromAmounts(Amount::fromFloat($subtotalExcl, $currency), Amount::fromFloat($subtotalIncl, $currency)));
            }
            $items[] = $lineItem;
        }
        return new LineItemCollection($items);
    }
    /**
     * Shipping cost, address, contact and shipping method. An order that ships nothing gets no
     * address; the core then sends only the shipping method, and only for the methods that need it.
     *
     * @param Currency $currency
     *
     * @return Shipping
     */
    private function buildShipping(Currency $currency): Shipping
    {
        $excl = (float) $this->order->get_shipping_total();
        $incl = $excl + (float) $this->order->get_shipping_tax();
        $shipsNothing = !$this->order->needs_shipping_address() && $excl <= 0.0;
        return new Shipping(TaxableAmount::fromAmounts(Amount::fromFloat($excl, $currency), Amount::fromFloat($incl, $currency)), $shipsNothing ? null : $this->buildAddress($this->order->get_shipping_country() ? 'shipping' : 'billing'), new ContactDetails((string) $this->order->get_billing_email()), $this->buildShippingMethod());
    }
    /**
     * The order's shipping method: local pickup is collected in the store, every other method is
     * delivered by a carrier. Named after the method the customer chose at checkout, or after the
     * store when the order has no shipping method (only digital products).
     *
     * @return ShippingMethod
     */
    private function buildShippingMethod(): ShippingMethod
    {
        $items = $this->order->get_shipping_methods();
        /** @var \WC_Order_Item_Shipping|false $item */
        $item = reset($items);
        if (!$item) {
            return new ShippingMethod(ShippingMethod::CARRIER_THIRD_PARTY, ModuleHelper::storeName());
        }
        $type = in_array($item->get_method_id(), ['local_pickup', 'pickup_location'], \true) ? ShippingMethod::COLLECT_AT_MERCHANT_STORE : ShippingMethod::CARRIER_THIRD_PARTY;
        $name = (string) $item->get_name();
        return new ShippingMethod($type, $name !== '' ? $name : ModuleHelper::storeName());
    }
    /**
     * Total order discount including its tax portion, or null when there is none.
     *
     * @param Currency $currency
     *
     * @return Amount|null
     */
    private function buildDiscount(Currency $currency): ?Amount
    {
        $discount = (float) $this->order->get_discount_total() + (float) $this->order->get_discount_tax();
        return $discount > 0 ? Amount::fromFloat($discount, $currency) : null;
    }
    /**
     * The current WordPress locale, falling back to en_GB.
     *
     * @return string
     */
    private function resolveLocale(): string
    {
        if (function_exists('get_user_locale')) {
            $locale = get_user_locale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }
        return function_exists('get_locale') ? (string) get_locale() : 'en_GB';
    }
}
