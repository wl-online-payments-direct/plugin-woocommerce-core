<?php

namespace WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedCheckout\HostedCheckoutSessionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\CreditCard;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Descriptor;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\HostedCheckout;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Sepa;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\Sepa\SignatureType;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputForHostedCheckout;
use WOP\OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use WOP\OnlinePayments\Sdk\Domain\CreateMandateRequest;
use WOP\OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\MobilePaymentMethodHostedCheckoutSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\Order;
use WOP\OnlinePayments\Sdk\Domain\PaymentProductFilter;
use WOP\OnlinePayments\Sdk\Domain\PaymentProductFiltersHostedCheckout;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentMethodSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentProduct3112SpecificInput;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentProduct5402SpecificInput;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentProduct5403SpecificInput;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentProduct5408SpecificInput;
use WOP\OnlinePayments\Sdk\Domain\SepaDirectDebitPaymentMethodSpecificInputBase;
use WOP\OnlinePayments\Sdk\Domain\SepaDirectDebitPaymentProduct771SpecificInputBase;
use WOP\OnlinePayments\Sdk\Domain\Shipping;
use WOP\OnlinePayments\Sdk\Domain\ShippingMethod;
use WOP\OnlinePayments\Sdk\Domain\SurchargeSpecificInput;
/**
 * Class CreateHostedCheckoutRequestTransformer.
 *
 * @package OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers
 */
class CreateHostedCheckoutRequestTransformer
{
    /** Oney `productType` for a product that is shipped. */
    private const ONEY_PHYSICAL_PRODUCT = '1';
    /** Oney `productType` for a product that is not shipped (virtual, downloadable). */
    private const ONEY_DIGITAL_PRODUCT = '4';
    public static function transform(HostedCheckoutSessionRequest $input, ThreeDSSettings $cardsSettings, PaymentSettings $paymentSettings, PaymentMethodCollection $paymentMethodCollection, array $supportedPaymentMethods, ?Token $token = null): CreateHostedCheckoutRequest
    {
        $cart = $input->getCartProvider()->get();
        $request = new CreateHostedCheckoutRequest();
        $hostedCheckoutSpecificInput = new HostedCheckoutSpecificInput();
        $hostedCheckoutSpecificInput->setReturnUrl($input->getReturnUrl());
        $hostedCheckoutSpecificInput->setLocale($cart->getCustomer()->getFormattedLocale());
        $hostedCheckoutSpecificInput->setAllowedNumberOfPaymentAttempts($paymentSettings->getPaymentAttemptsNumber()->getPaymentAttemptsNumber());
        $hostedCheckoutSpecificInput->setShowResultPage($paymentSettings->isDisplayConfirmationPage());
        $paymentProductId = $input->getPaymentProductId() ?: PaymentProductId::hostedCheckout();
        if ($config = $paymentMethodCollection->get($paymentProductId)) {
            $hostedCheckoutSpecificInput->setVariant($config->getTemplate() ?: $paymentSettings->getTemplate());
        }
        $filters = new PaymentProductFiltersHostedCheckout();
        $productFilter = new PaymentProductFilter();
        $productFilter->setProducts(array_map('intval', PaymentProductId::getForHostedCheckoutPage($supportedPaymentMethods)));
        if (null !== $input->getPaymentProductId() && !$paymentProductId->equals(PaymentProductId::cards())) {
            $productFilter->setProducts([(int) $input->getPaymentProductId()->getId()]);
        }
        if (null !== $input->getPaymentProductId() && $paymentProductId->equals(PaymentProductId::cards())) {
            $productFilter->setProducts([]);
            $productFilter->setGroups(['cards']);
        }
        $filters->setRestrictTo($productFilter);
        $hostedCheckoutSpecificInput->setPaymentProductFilters($filters);
        if ($config && $config->getAdditionalData() instanceof Descriptor && $config->getAdditionalData()->getDescriptor() !== '' && ($cart->getDescriptor() === null || $cart->getDescriptor() === '')) {
            $cart->setDescriptor($config->getAdditionalData()->getDescriptor());
        }
        $order = OrderTransformer::transform($cart, $paymentSettings->isSendShoppingCart());
        if ($paymentSettings->isApplySurcharge()) {
            $surchargeSpecificInput = new SurchargeSpecificInput();
            $surchargeSpecificInput->setMode('on-behalf-of');
            $order->setSurchargeSpecificInput($surchargeSpecificInput);
        }
        $request->setOrder($order);
        $request->setHostedCheckoutSpecificInput($hostedCheckoutSpecificInput);
        $cardPaymentMethodSpecificInput = CardPaymentMethodSpecificInputTransformer::transform($cart, $input->getReturnUrl(), $cardsSettings, $paymentSettings, $paymentMethodCollection, $input->getPaymentProductId(), $token);
        $request->setCardPaymentMethodSpecificInput(CardPaymentMethodSpecificInputTransformer::toHostedInput($cardPaymentMethodSpecificInput));
        $mobilePaymentMethodSpecificInput = new MobilePaymentMethodHostedCheckoutSpecificInput();
        $mobilePaymentMethodSpecificInput->setAuthorizationMode(PaymentAction::authorizeCapture()->getType());
        if ($paymentProductId !== null && $paymentProductId->isSeparateCaptureSupported()) {
            $mobilePaymentMethodSpecificInput->setAuthorizationMode($config->getPaymentAction() ? $config->getPaymentAction()->getType() : $paymentSettings->getPaymentAction()->getType());
        }
        if (null !== $input->getPaymentProductId() && $input->getPaymentProductId()->isMobileType()) {
            $mobilePaymentMethodSpecificInput->setPaymentProductId((int) $input->getPaymentProductId()->getId());
        }
        $mobilePaymentMethodSpecificInput->setPaymentProduct320SpecificInput(GooglePaySpecificRequestTransformer::transform($cardPaymentMethodSpecificInput));
        $request->setMobilePaymentMethodSpecificInput($mobilePaymentMethodSpecificInput);
        $redirectPaymentMethodSpecificInput = new RedirectPaymentMethodSpecificInput();
        $redirectPaymentMethodSpecificInput->setRequiresApproval(\false);
        if ($paymentProductId !== null && $paymentProductId->isSeparateCaptureSupported()) {
            $redirectPaymentMethodSpecificInput->setRequiresApproval(PaymentAction::authorize()->equals($config->getPaymentAction() ?: $paymentSettings->getPaymentAction()));
        }
        if ($input->getPaymentProductId() !== null && $input->getPaymentProductId()->equals(PaymentProductId::mealvouchers())) {
            $redirectPaymentProduct5402SpecificInput = new RedirectPaymentProduct5402SpecificInput();
            $redirectPaymentProduct5402SpecificInput->setCompleteRemainingPaymentAmount(\true);
            $redirectPaymentMethodSpecificInput->setPaymentProduct5402SpecificInput($redirectPaymentProduct5402SpecificInput);
            $redirectPaymentMethodSpecificInput->setRequiresApproval(\false);
            // Reset mobile specific input because it breaks mealvouchers
            $request->setMobilePaymentMethodSpecificInput(null);
        }
        if ($input->getPaymentProductId() !== null && $input->getPaymentProductId()->equals(PaymentProductId::chequeVacancesConnect())) {
            $redirectPaymentProduct5403SpecificInput = new RedirectPaymentProduct5403SpecificInput();
            $redirectPaymentProduct5403SpecificInput->setCompleteRemainingPaymentAmount(\true);
            $redirectPaymentMethodSpecificInput->setPaymentProduct5403SpecificInput($redirectPaymentProduct5403SpecificInput);
            $redirectPaymentMethodSpecificInput->setRequiresApproval(\false);
            $request->setMobilePaymentMethodSpecificInput(null);
        }
        if ($input->getPaymentProductId() !== null && $input->getPaymentProductId()->equals(PaymentProductId::intersolve())) {
            $redirectPaymentMethodSpecificInput->setRequiresApproval(\false);
            // Reset mobile specific input because it breaks intersolve
            $request->setMobilePaymentMethodSpecificInput(null);
        }
        if (null !== $input->getPaymentProductId() && $input->getPaymentProductId()->equals(PaymentProductId::illicado()->getId())) {
            $redirectPaymentMethodSpecificInput->setRequiresApproval(\false);
        }
        if ($input->getPaymentProductId() !== null && $input->getPaymentProductId()->isRedirectType() && !$input->getPaymentProductId()->equals(PaymentProductId::sepaDirectDebit()->getId())) {
            $redirectPaymentMethodSpecificInput->setPaymentProductId((int) $input->getPaymentProductId()->getId());
        }
        self::setHostedCheckoutSpecificInput($paymentMethodCollection, $paymentProductId, $hostedCheckoutSpecificInput);
        self::setIntersolveSpecificInput($paymentMethodCollection, $hostedCheckoutSpecificInput);
        self::setSepaSpecificInput($input->getPaymentProductId(), $paymentMethodCollection, $order, $request, $cart->getCustomer()->getFormattedLocale());
        self::setBankTransferSpecificInput($paymentMethodCollection, $redirectPaymentMethodSpecificInput);
        self::setOneySpecificInput($input, $paymentMethodCollection, $redirectPaymentMethodSpecificInput, $cart, $order);
        self::setIllicadoSpecificInput($input, $redirectPaymentMethodSpecificInput);
        $request->setRedirectPaymentMethodSpecificInput($redirectPaymentMethodSpecificInput);
        return $request;
    }
    /**
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param HostedCheckoutSpecificInput $hostedCheckoutSpecificInput
     *
     * @return void
     */
    protected static function setHostedCheckoutSpecificInput(PaymentMethodCollection $paymentMethodCollection, PaymentProductId $paymentProductId, HostedCheckoutSpecificInput $hostedCheckoutSpecificInput): void
    {
        if ($config = $paymentMethodCollection->get(PaymentProductId::hostedCheckout())) {
            $cardSpecificInputForHostedCheckout = new CardPaymentMethodSpecificInputForHostedCheckout();
            $cardSpecificInputForHostedCheckout->setGroupCards($config->getAdditionalData()->isEnableGroupCards());
            $hostedCheckoutSpecificInput->setCardPaymentMethodSpecificInput($cardSpecificInputForHostedCheckout);
        }
        if (!$paymentProductId->equals(PaymentProductId::cards())) {
            return;
        }
        $cardsPaymentMethod = $paymentMethodCollection->get(PaymentProductId::cards());
        /** @var CreditCard|null $cardAdditionalData */
        $cardAdditionalData = $cardsPaymentMethod ? $cardsPaymentMethod->getAdditionalData() : null;
        if ($cardsPaymentMethod && $cardAdditionalData && $cardAdditionalData->isEnableGroupCards()) {
            $cardSpecificInputForHostedCheckout = new CardPaymentMethodSpecificInputForHostedCheckout();
            $cardSpecificInputForHostedCheckout->setGroupCards(\true);
            $hostedCheckoutSpecificInput->setCardPaymentMethodSpecificInput($cardSpecificInputForHostedCheckout);
        }
    }
    /**
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param HostedCheckoutSpecificInput $hostedCheckoutSpecificInput
     *
     * @return void
     */
    protected static function setIntersolveSpecificInput(PaymentMethodCollection $paymentMethodCollection, HostedCheckoutSpecificInput $hostedCheckoutSpecificInput): void
    {
        if ($config = $paymentMethodCollection->get(PaymentProductId::intersolve())) {
            $hostedCheckoutSpecificInput->setSessionTimeout($config->getAdditionalData()->getSessionTimeout()->getDuration());
        }
    }
    /**
     * Sets the SEPA Direct Debit mandate whenever SEPA Direct Debit can be paid on the hosted page:
     * on the generic hosted checkout (no product selected, the customer may pick SEPA Direct Debit
     * there) and when SEPA Direct Debit itself was selected. The SEPA product id, which makes the hosted
     * page open SEPA Direct Debit directly, is set only in the latter case. With any other selected
     * product the SEPA input is omitted, as a second product id conflicts with the selected one.
     *
     * The mandate signature type comes from the SEPA Direct Debit configuration when SEPA Direct Debit
     * was selected and from the Hosted Checkout configuration on the generic hosted checkout
     * (see `sepaSignatureType()`).
     *
     * @param PaymentProductId|null $selectedProductId Product selected at checkout; null for the generic hosted checkout.
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param Order $order
     * @param CreateHostedCheckoutRequest $request
     * @param string $locale Checkout locale, used to derive the mandate language.
     *
     * @return void
     */
    protected static function setSepaSpecificInput(?PaymentProductId $selectedProductId, PaymentMethodCollection $paymentMethodCollection, Order $order, CreateHostedCheckoutRequest $request, string $locale): void
    {
        $isSepaSelected = $selectedProductId !== null && $selectedProductId->equals(PaymentProductId::SEPA_DIRECT_DEBIT);
        if ($selectedProductId !== null && !$isSepaSelected) {
            return;
        }
        $signatureType = self::sepaSignatureType($paymentMethodCollection, $isSepaSelected);
        if ($signatureType === null) {
            return;
        }
        $request->setSepaDirectDebitPaymentMethodSpecificInput(self::sepaDirectDebitSpecificInput($order, $locale, $signatureType, $isSepaSelected));
    }
    /**
     * Mandate signature type for SEPA Direct Debit on Worldline's hosted page. When SEPA Direct Debit was
     * selected at checkout the SEPA Direct Debit configuration applies. On the generic hosted checkout and
     * on a payment link page the Hosted Checkout configuration applies (the customer picks SEPA Direct
     * Debit on Worldline's page); without a Hosted Checkout configuration the SEPA Direct Debit
     * configuration is used. Null when neither configuration exists: no mandate is sent then.
     *
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param bool $isSepaSelected
     *
     * @return SignatureType|null
     */
    public static function sepaSignatureType(PaymentMethodCollection $paymentMethodCollection, bool $isSepaSelected): ?SignatureType
    {
        $hostedCheckout = $paymentMethodCollection->get(PaymentProductId::hostedCheckout());
        if (!$isSepaSelected && $hostedCheckout && $hostedCheckout->getAdditionalData() instanceof HostedCheckout) {
            return $hostedCheckout->getAdditionalData()->getSignatureType();
        }
        $sepa = $paymentMethodCollection->get(PaymentProductId::sepaDirectDebit());
        if ($sepa && $sepa->getAdditionalData() instanceof Sepa) {
            return $sepa->getAdditionalData()->getSignatureType() ?: SignatureType::sms();
        }
        return null;
    }
    /**
     * SEPA Direct Debit input carrying the mandate Worldline needs to offer SEPA Direct Debit on its page.
     * The SEPA product id, which makes the hosted page open SEPA Direct Debit directly, is set only when
     * SEPA Direct Debit was selected at checkout.
     *
     * @param Order $order
     * @param string $locale Checkout locale, used to derive the mandate language.
     * @param SignatureType $signatureType
     * @param bool $setProductId
     *
     * @return SepaDirectDebitPaymentMethodSpecificInputBase
     */
    public static function sepaDirectDebitSpecificInput(Order $order, string $locale, SignatureType $signatureType, bool $setProductId = \false): SepaDirectDebitPaymentMethodSpecificInputBase
    {
        $references = $order->getReferences();
        $merchantReference = $references !== null ? (string) $references->getMerchantReference() : '';
        $customerReference = (string) $order->getCustomer()->getMerchantCustomerId();
        if ($customerReference === '') {
            $customerReference = $merchantReference;
        }
        $mandate = new CreateMandateRequest();
        $mandate->setCustomerReference($customerReference);
        $mandate->setLanguage(self::sepaMandateLanguage($locale));
        $mandate->setRecurrenceType('UNIQUE');
        $mandate->setSignatureType($signatureType->getType());
        $mandate->setUniqueMandateReference($merchantReference);
        $specificInput = new SepaDirectDebitPaymentProduct771SpecificInputBase();
        $specificInput->setMandate($mandate);
        $sepaDirectDebit = new SepaDirectDebitPaymentMethodSpecificInputBase();
        if ($setProductId) {
            $sepaDirectDebit->setPaymentProductId((int) PaymentProductId::sepaDirectDebit()->getId());
        }
        $sepaDirectDebit->paymentProduct771SpecificInput = $specificInput;
        return $sepaDirectDebit;
    }
    /**
     * SEPA mandate language: the two-letter language from the checkout locale, restricted to the set
     * Worldline accepts for the mandate (de, en, es, fr, it, nl, si, sk, sv); defaults to 'en'.
     *
     * @param string $locale
     *
     * @return string
     */
    private static function sepaMandateLanguage(string $locale): string
    {
        $allowed = ['de', 'en', 'es', 'fr', 'it', 'nl', 'si', 'sk', 'sv'];
        $language = strtolower(substr($locale, 0, 2));
        return in_array($language, $allowed, \true) ? $language : 'en';
    }
    /**
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput
     *
     * @return void
     */
    protected static function setBankTransferSpecificInput(PaymentMethodCollection $paymentMethodCollection, RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput): void
    {
        if ($config = $paymentMethodCollection->get(PaymentProductId::bankTransfer())) {
            $paymentProduct5408SpecificInput = new RedirectPaymentProduct5408SpecificInput();
            $paymentProduct5408SpecificInput->setInstantPaymentOnly($config->getAdditionalData()->isInstantPayment());
            $redirectPaymentMethodSpecificInput->setPaymentProduct5408SpecificInput($paymentProduct5408SpecificInput);
        }
    }
    /**
     * Oney specific data, sent only when an Oney method was requested.
     *
     *  - The payment option code configured on the method, except for Oney 3x4x (`5148`): Worldline
     *    expects it only for the products with a fixed number of instalments.
     *  - For the Oney 3x4x family, the shipping method and each cart line's product type (`"1"`
     *    physical, `"4"` digital). Oney refuses the payment without them. They are not sent on other
     *    methods, where `productType` carries the Meal Vouchers category instead.
     *
     * The generic Hosted Checkout page does not offer Oney 3x4x
     * ({@see PaymentProductId::getForHostedCheckoutPage()}), so nothing is sent there.
     *
     * @param HostedCheckoutSessionRequest $input
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput
     * @param Cart $cart
     * @param Order $order
     *
     * @return void
     */
    protected static function setOneySpecificInput(HostedCheckoutSessionRequest $input, PaymentMethodCollection $paymentMethodCollection, RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput, Cart $cart, Order $order): void
    {
        $requested = $input->getPaymentProductId();
        if (null === $requested || !$requested->isOney()) {
            return;
        }
        $config = $paymentMethodCollection->get($requested);
        if ($config && !$requested->equals(PaymentProductId::ONEY_3X4X)) {
            $redirectPaymentMethodSpecificInput->setPaymentOption($config->getAdditionalData()->getPaymentOption());
        }
        if (!$requested->isOney3x4x()) {
            return;
        }
        self::setOneyShippingMethod($cart, $order);
        self::setOneyProductTypes($cart, $order);
    }
    /**
     * `order.shipping.method` from the cart. An order that ships nothing gets a shipping with only the
     * method, which Oney accepts.
     *
     * @param Cart $cart
     * @param Order $order
     *
     * @return void
     */
    private static function setOneyShippingMethod(Cart $cart, Order $order): void
    {
        $method = $cart->getShipping() ? $cart->getShipping()->getMethod() : null;
        if (null === $method) {
            return;
        }
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setType($method->getType());
        $shippingMethod->setName($method->getName());
        $shippingMethod->setSpeed($method->getSpeed());
        $shipping = $order->getShipping() ?: new Shipping();
        $shipping->setMethod($shippingMethod);
        $order->setShipping($shipping);
    }
    /**
     * `productType` on each shopping cart line: `"4"` for a digital product, `"1"` otherwise. The
     * order's lines are built from the cart's lines in the same order.
     *
     * @param Cart $cart
     * @param Order $order
     *
     * @return void
     */
    private static function setOneyProductTypes(Cart $cart, Order $order): void
    {
        if (null === $order->getShoppingCart() || null === $order->getShoppingCart()->getItems()) {
            return;
        }
        $lineItems = $cart->getLineItems()->toArray();
        foreach ($order->getShoppingCart()->getItems() as $index => $item) {
            if (!isset($lineItems[$index]) || null === $item->getOrderLineDetails()) {
                continue;
            }
            $item->getOrderLineDetails()->setProductType($lineItems[$index]->getProduct()->isDigital() ? self::ONEY_DIGITAL_PRODUCT : self::ONEY_PHYSICAL_PRODUCT);
        }
    }
    /**
     * Complementary payment for Illicado (`paymentProduct3112SpecificInput.completeRemainingPaymentAmount`).
     *
     * Sent when Illicado is the requested product and on the generic Hosted Checkout button, where the
     * shopper may only pick Illicado on Worldline's page, after this request has gone.
     *
     * @param HostedCheckoutSessionRequest $input
     * @param RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput
     *
     * @return void
     */
    protected static function setIllicadoSpecificInput(HostedCheckoutSessionRequest $input, RedirectPaymentMethodSpecificInput $redirectPaymentMethodSpecificInput): void
    {
        $requested = $input->getPaymentProductId();
        if (null !== $requested && !$requested->equals(PaymentProductId::illicado()->getId())) {
            return;
        }
        $redirectPaymentMethodSpecificInput->setPaymentProduct3112SpecificInput(self::illicadoSpecificInput());
    }
    /**
     * Lets an Illicado gift card that does not cover the order total be completed with another payment
     * method on the hosted page.
     *
     * @return RedirectPaymentProduct3112SpecificInput
     */
    public static function illicadoSpecificInput(): RedirectPaymentProduct3112SpecificInput
    {
        $illicado = new RedirectPaymentProduct3112SpecificInput();
        $illicado->setCompleteRemainingPaymentAmount(\true);
        return $illicado;
    }
}
