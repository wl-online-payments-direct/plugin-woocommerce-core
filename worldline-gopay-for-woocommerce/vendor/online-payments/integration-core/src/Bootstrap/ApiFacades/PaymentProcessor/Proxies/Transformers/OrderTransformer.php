<?php

namespace WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Address;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Customer\Customer;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItem;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\LineItemCollection;
use WOP\OnlinePayments\Sdk\Domain\Address as SdkAddress;
use WOP\OnlinePayments\Sdk\Domain\AddressPersonal;
use WOP\OnlinePayments\Sdk\Domain\AmountOfMoney;
use WOP\OnlinePayments\Sdk\Domain\BrowserData;
use WOP\OnlinePayments\Sdk\Domain\ContactDetails;
use WOP\OnlinePayments\Sdk\Domain\Customer as SdkCustomer;
use WOP\OnlinePayments\Sdk\Domain\CustomerDevice;
use WOP\OnlinePayments\Sdk\Domain\Discount;
use WOP\OnlinePayments\Sdk\Domain\LineItem as SdkLineItem;
use WOP\OnlinePayments\Sdk\Domain\Order;
use WOP\OnlinePayments\Sdk\Domain\OrderLineDetails;
use WOP\OnlinePayments\Sdk\Domain\OrderReferences;
use WOP\OnlinePayments\Sdk\Domain\PersonalInformation;
use WOP\OnlinePayments\Sdk\Domain\PersonalName;
use WOP\OnlinePayments\Sdk\Domain\Shipping;
use WOP\OnlinePayments\Sdk\Domain\ShoppingCart;
/**
 * Class OrderTransformer.
 *
 * @package OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers
 */
class OrderTransformer
{
    public static function transform(Cart $cart, bool $sendShoppingCart = \true): Order
    {
        $order = new Order();
        $amountOfMoney = new AmountOfMoney();
        $amountOfMoney->setCurrencyCode((string) $cart->getTotal()->getCurrency());
        $amountOfMoney->setAmount($cart->getTotal()->getValue());
        $order->setAmountOfMoney($amountOfMoney);
        $reference = new OrderReferences();
        $reference->setMerchantReference($cart->getMerchantReference());
        if ($cart->getDescriptor() !== null && $cart->getDescriptor() !== '') {
            $reference->setDescriptor($cart->getDescriptor());
        }
        $order->setReferences($reference);
        if ($cart->getDiscount()) {
            $discount = new Discount();
            $discount->setAmount($cart->getDiscount()->getValue());
            $order->setDiscount($discount);
        }
        // A cart without a shipping address ships nothing (only digital products). Its shipping carries
        // only the shipping method, which is added for the payment methods that need it.
        if ($cart->getShipping() && $cart->getShipping()->getAddress()) {
            $shipping = new Shipping();
            $shipping->setAddress(self::transformPersonalAddress($cart->getShipping()->getAddress()));
            if ($cart->getShipping()->getContactDetails()) {
                $shipping->setEmailAddress($cart->getShipping()->getContactDetails()->getEmail());
            }
            $shipping->setShippingCost($cart->getShipping()->getCost()->getAmountExclTax()->getValue());
            $shipping->setShippingCostTax($cart->getShipping()->getCost()->getTaxAmount()->getValue());
            $order->setShipping($shipping);
        }
        $order->setCustomer(self::transformCustomer($cart->getCustomer()));
        if ($sendShoppingCart && !$cart->getLineItems()->isEmpty()) {
            $order->setShoppingCart(self::transformLineItems($cart->getLineItems()));
        }
        return $order;
    }
    /**
     * The billing address. `customer.billingAddress` is an `Address`, which carries no name -
     * the payer's name travels in `customer.personalInformation` instead.
     */
    private static function transformAddress(Address $cartAddress): SdkAddress
    {
        $address = new SdkAddress();
        self::applyAddressFields($cartAddress, $address);
        return $address;
    }
    /**
     * The shipping address. `shipping.address` is an `AddressPersonal`, so it carries the
     * recipient's name.
     */
    private static function transformPersonalAddress(Address $cartAddress): AddressPersonal
    {
        $address = new AddressPersonal();
        self::applyAddressFields($cartAddress, $address);
        $personalInfo = $cartAddress->getPersonalInformation();
        if ($personalInfo) {
            $personalName = new PersonalName();
            $personalName->setFirstName($personalInfo->getFirstName());
            $personalName->setSurname($personalInfo->getLastName());
            $personalName->setTitle($personalInfo->getTitle());
            $address->setName($personalName);
        }
        return $address;
    }
    /**
     * The fields `Address` and `AddressPersonal` share.
     *
     * @param SdkAddress|AddressPersonal $address
     */
    private static function applyAddressFields(Address $cartAddress, $address): void
    {
        $address->setCountryCode((string) $cartAddress->getCountry());
        $address->setCity($cartAddress->getCity());
        $address->setZip($cartAddress->getZip());
        $address->setStreet($cartAddress->getStreet());
        $address->setHouseNumber($cartAddress->getHouseNumber());
        $address->setAdditionalInfo($cartAddress->getAdditionalInfo());
        $address->setState($cartAddress->getState());
    }
    /**
     * Country calling codes, for promoting a national number to E.164.
     *
     * Not the full ISO list: the countries Worldline Direct's payment methods are offered in, plus
     * the handful of neighbours a European store commonly ships to. A country that is NOT here makes
     * `normalisePhone()` return '' rather than guess - which costs nothing, because the alternative
     * today is that no phone number is sent at all.
     *
     * @var array<string, string>
     */
    private const CALLING_CODES = ['AT' => '43', 'BE' => '32', 'BG' => '359', 'CH' => '41', 'CY' => '357', 'CZ' => '420', 'DE' => '49', 'DK' => '45', 'EE' => '372', 'ES' => '34', 'FI' => '358', 'FR' => '33', 'GB' => '44', 'GR' => '30', 'HR' => '385', 'HU' => '36', 'IE' => '353', 'IT' => '39', 'LT' => '370', 'LU' => '352', 'LV' => '371', 'MT' => '356', 'NL' => '31', 'NO' => '47', 'PL' => '48', 'PT' => '351', 'RO' => '40', 'SE' => '46', 'SI' => '386', 'SK' => '421'];
    /**
     * A shop-stored phone number as E.164, or '' when it cannot be made into one.
     *
     * Shops store whatever the shopper typed - `01 75 75 75 75`, `0033175757575`, `+33 175 757 575` -
     * and Oney refuses anything that is not `+33…`. Normalising here rather than in each platform is
     * what makes the single `setPhoneNumber()` above worth having: every platform fills the same
     * field, and only this layer knows both the number and the billing country it belongs to.
     *
     * Deliberately conservative. A number already in `+` form is passed through, a national number is
     * promoted using the billing country's calling code when that country is known, and anything else
     * returns '' rather than a guess - sending a wrong number is worse than sending none, because the
     * payment is then refused at the end of a redirect instead of the method being absent.
     *
     * @param string $phone
     * @param string $countryCode ISO 3166-1 alpha-2 of the billing address.
     *
     * @return string
     */
    private static function normalisePhone(string $phone, string $countryCode): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone);
        if (null === $digits || '' === $digits) {
            return '';
        }
        if (0 === strpos($digits, '+')) {
            return '+' . preg_replace('/\D/', '', substr($digits, 1));
        }
        $digits = preg_replace('/\D/', '', $digits);
        if ('' === $digits) {
            return '';
        }
        // `00` is the international prefix most of Europe dials, so `0033…` is already a full number.
        if (0 === strpos($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        $callingCode = self::CALLING_CODES[strtoupper($countryCode)] ?? null;
        if (null === $callingCode) {
            return '';
        }
        // A national number carries a trunk prefix the country code replaces.
        return '+' . $callingCode . ltrim($digits, '0');
    }
    private static function transformCustomer(Customer $cartCustomer): SdkCustomer
    {
        $customer = new SdkCustomer();
        $contactDetails = new ContactDetails();
        $contactDetails->setEmailAddress($cartCustomer->getContactDetails()->getEmail());
        // The phone has been on the cart's own `ContactDetails` all along and was never sent. Oney
        // requires it, and every other method is better off with it.
        $phone = self::normalisePhone($cartCustomer->getContactDetails()->getPhone(), (string) $cartCustomer->getBillingAddress()->getCountry());
        if ('' !== $phone) {
            $contactDetails->setPhoneNumber($phone);
        }
        $customer->setContactDetails($contactDetails);
        $customer->setLocale($cartCustomer->getFormattedLocale());
        $customer->setMerchantCustomerId($cartCustomer->getMerchantCustomerId());
        $personalInfo = $cartCustomer->getBillingAddress()->getPersonalInformation();
        if ($personalInfo) {
            $personalInformation = new PersonalInformation();
            $personalName = new PersonalName();
            $personalName->setFirstName($cartCustomer->getBillingAddress()->getPersonalInformation()->getFirstName());
            $personalName->setSurname($cartCustomer->getBillingAddress()->getPersonalInformation()->getLastName());
            $personalName->setTitle($cartCustomer->getBillingAddress()->getPersonalInformation()->getTitle());
            $personalInformation->setName($personalName);
            $personalInformation->setGender($cartCustomer->getBillingAddress()->getPersonalInformation()->getGender());
            $customer->setPersonalInformation($personalInformation);
        }
        if ($cartCustomer->getDevice()) {
            $device = new CustomerDevice();
            $device->setAcceptHeader($cartCustomer->getDevice()->getAcceptHeader());
            $device->setUserAgent($cartCustomer->getDevice()->getUserAgent());
            $device->setIpAddress($cartCustomer->getDevice()->getIpAddress());
            $device->setLocale($cartCustomer->getLocale());
            $device->setTimezoneOffsetUtcMinutes($cartCustomer->getDevice()->getTimezoneOffsetUtcMinutes());
            $browserData = new BrowserData();
            $browserData->setColorDepth($cartCustomer->getDevice()->getColorDepth());
            $browserData->setJavaEnabled($cartCustomer->getDevice()->isJavaEnabled());
            $browserData->setScreenHeight($cartCustomer->getDevice()->getScreenHeight());
            $browserData->setScreenWidth($cartCustomer->getDevice()->getScreenWidth());
            $device->setBrowserData($browserData);
            $customer->setDevice($device);
        }
        $customer->setBillingAddress(self::transformAddress($cartCustomer->getBillingAddress()));
        return $customer;
    }
    private static function transformLineItems(LineItemCollection $lineItems): ShoppingCart
    {
        $shoppingCart = new ShoppingCart();
        $shoppingCart->setItems(array_map(function (LineItem $lineItem) {
            $cartItem = new SdkLineItem();
            $amountOfMoney = new AmountOfMoney();
            $amountOfMoney->setCurrencyCode((string) $lineItem->getTotal()->getCurrency());
            $amountOfMoney->setAmount($lineItem->getTotal()->getValue());
            $orderLineDetails = new OrderLineDetails();
            $orderLineDetails->setProductPrice($lineItem->getUnitPrice()->getAmountExclTax()->getValue());
            $orderLineDetails->setTaxAmount($lineItem->getUnitPrice()->getTaxAmount()->getValue());
            $orderLineDetails->setQuantity($lineItem->getQuantity());
            $orderLineDetails->setDiscountAmount(null !== $lineItem->getDiscount() ? $lineItem->getDiscount()->getValue() : null);
            $orderLineDetails->setProductName($lineItem->getProduct()->getName());
            $orderLineDetails->setProductCode($lineItem->getProduct()->getUpcCode());
            $orderLineDetails->setProductType($lineItem->getProduct()->getType());
            $orderLineDetails->setUnit($lineItem->getProduct()->getUnit());
            $cartItem->setAmountOfMoney($amountOfMoney);
            $cartItem->setOrderLineDetails($orderLineDetails);
            return $cartItem;
        }, $lineItems->toArray()));
        return $shoppingCart;
    }
}
