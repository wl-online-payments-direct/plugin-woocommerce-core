<?php

namespace WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers;

use DateInterval;
use DateTime;
use DateTimeZone;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PayByLinkExpirationTime;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PayByLinkSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\GeneralSettings\PaymentSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentLinks\PaymentLinkRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\MethodAdditionalData\ThreeDSSettings\ThreeDSSettings;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethodCollection;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputForHostedCheckout;
use WOP\OnlinePayments\Sdk\Domain\CreatePaymentLinkRequest;
use WOP\OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\MobilePaymentMethodHostedCheckoutSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\PaymentLinkSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\PaymentProductFilter;
use WOP\OnlinePayments\Sdk\Domain\PaymentProductFiltersHostedCheckout;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentMethodSpecificInput;
use WOP\OnlinePayments\Sdk\Domain\RedirectPaymentProduct5402SpecificInput;
/**
 * Class CreatePaymentLinkRequestTransformer.
 *
 * @package OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\Proxies\Transformers
 */
class CreatePaymentLinkRequestTransformer
{
    public static function transform(PaymentLinkRequest $input, ThreeDSSettings $cardsSettings, PaymentSettings $paymentSettings, PayByLinkSettings $payByLinkSettings, PaymentMethodCollection $paymentMethodCollection, array $supportedPaymentMethods): CreatePaymentLinkRequest
    {
        $cart = $input->getCartProvider()->get();
        $request = new CreatePaymentLinkRequest();
        $specificInput = new PaymentLinkSpecificInput();
        $specificInput->setExpirationDate(self::getExpirationDate($input->getExpiresAt(), $payByLinkSettings->getExpirationTime()));
        $request->setPaymentLinkSpecificInput($specificInput);
        $hostedCheckoutSpecificInput = new HostedCheckoutSpecificInput();
        $hostedCheckoutSpecificInput->setReturnUrl($input->getReturnUrl());
        $hostedCheckoutSpecificInput->setAllowedNumberOfPaymentAttempts($paymentSettings->getPaymentAttemptsNumber()->getPaymentAttemptsNumber());
        $filters = new PaymentProductFiltersHostedCheckout();
        $productFilter = new PaymentProductFilter();
        $productFilter->setProducts(array_map('intval', PaymentProductId::getForHostedCheckoutPage($supportedPaymentMethods)));
        $filters->setRestrictTo($productFilter);
        $hostedCheckoutSpecificInput->setPaymentProductFilters($filters);
        $template = self::hostedCheckoutTemplate($paymentMethodCollection, $paymentSettings);
        if ($template !== '') {
            $hostedCheckoutSpecificInput->setVariant($template);
        }
        if ($config = $paymentMethodCollection->get(PaymentProductId::hostedCheckout())) {
            $cardSpecificInputForHostedCheckout = new CardPaymentMethodSpecificInputForHostedCheckout();
            $cardSpecificInputForHostedCheckout->setGroupCards($config->getAdditionalData()->isEnableGroupCards());
            $hostedCheckoutSpecificInput->setCardPaymentMethodSpecificInput($cardSpecificInputForHostedCheckout);
        }
        $order = OrderTransformer::transform($cart, $paymentSettings->isSendShoppingCart());
        $request->setOrder($order);
        $request->setHostedCheckoutSpecificInput($hostedCheckoutSpecificInput);
        // The customer may pick SEPA Direct Debit on the payment link page; the mandate signature type
        // follows the Hosted Checkout settings, like the template and group cards above.
        $signatureType = CreateHostedCheckoutRequestTransformer::sepaSignatureType($paymentMethodCollection, \false);
        if ($signatureType !== null) {
            $request->setSepaDirectDebitPaymentMethodSpecificInput(CreateHostedCheckoutRequestTransformer::sepaDirectDebitSpecificInput($order, $cart->getCustomer()->getFormattedLocale(), $signatureType));
        }
        $cardPaymentMethodSpecificInput = CardPaymentMethodSpecificInputTransformer::transform($cart, $input->getReturnUrl(), $cardsSettings, $paymentSettings, $paymentMethodCollection);
        $request->setCardPaymentMethodSpecificInput(CardPaymentMethodSpecificInputTransformer::toHostedInput($cardPaymentMethodSpecificInput));
        $mobilePaymentMethodSpecificInput = new MobilePaymentMethodHostedCheckoutSpecificInput();
        $mobilePaymentMethodSpecificInput->setPaymentProduct320SpecificInput(GooglePaySpecificRequestTransformer::transform($cardPaymentMethodSpecificInput));
        $mobilePaymentMethodSpecificInput->setAuthorizationMode($paymentSettings->getPaymentAction()->getType());
        $request->setMobilePaymentMethodSpecificInput($mobilePaymentMethodSpecificInput);
        $redirectPaymentMethodSpecificInput = new RedirectPaymentMethodSpecificInput();
        $redirectPaymentProduct5402SpecificInput = new RedirectPaymentProduct5402SpecificInput();
        $redirectPaymentProduct5402SpecificInput->setCompleteRemainingPaymentAmount(\true);
        $redirectPaymentMethodSpecificInput->setRequiresApproval(PaymentAction::authorize()->equals($paymentSettings->getPaymentAction()));
        $redirectPaymentMethodSpecificInput->setPaymentProduct5402SpecificInput($redirectPaymentProduct5402SpecificInput);
        // The shopper picks the product on Worldline's page, so Illicado may be chosen there.
        $redirectPaymentMethodSpecificInput->setPaymentProduct3112SpecificInput(CreateHostedCheckoutRequestTransformer::illicadoSpecificInput());
        $request->setRedirectPaymentMethodSpecificInput($redirectPaymentMethodSpecificInput);
        return $request;
    }
    /**
     * Pay by Link follows the Hosted Checkout settings: the Hosted Checkout template, falling back
     * to the Payment settings template, the same way the Hosted Checkout request picks its variant.
     *
     * @param PaymentMethodCollection $paymentMethodCollection
     * @param PaymentSettings $paymentSettings
     *
     * @return string
     */
    private static function hostedCheckoutTemplate(PaymentMethodCollection $paymentMethodCollection, PaymentSettings $paymentSettings): string
    {
        $config = $paymentMethodCollection->get(PaymentProductId::hostedCheckout());
        $template = $config ? $config->getTemplate() : '';
        return $template !== '' ? $template : $paymentSettings->getTemplate();
    }
    private static function getExpirationDate(?DateTime $requestExpiresAt, PayByLinkExpirationTime $expirationTime): DateTime
    {
        if ($requestExpiresAt) {
            $expiresAt = $requestExpiresAt;
        } else {
            $expiresAt = new DateTime('now', new DateTimeZone('UTC'));
            $expiresAt->add(new DateInterval('P' . $expirationTime->getDays() . 'D'));
        }
        $expiresAt->setTime(23, 59, 59);
        return $expiresAt;
    }
}
