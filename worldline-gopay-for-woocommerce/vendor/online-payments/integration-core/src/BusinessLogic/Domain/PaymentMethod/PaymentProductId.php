<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\Exceptions\InvalidPaymentProductIdException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Translations\Model\TranslatableLabel;
/**
 * Class PaymentProductId.
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod
 */
class PaymentProductId
{
    const SUPPORTED_PAYMENT_PRODUCTS = [self::CARDS, self::HOSTED_CHECKOUT, self::ALIPAY, self::APPLE_PAY, self::BANK_TRANSFER, self::CADO, self::BIZUM, self::BLIK, self::CADHOC, self::CHEQUE_VACANCES_CONNECT, self::CETELEM, self::COFIDIS, self::CPAY, self::AMERICAN_EXPRESS, self::BANCONTACT, self::CARTE_BANCAIRE, self::DINERS_CLUB, self::DISCOVER, self::JCB, self::MASTERCARD, self::MAESTRO, self::UPI, self::VISA, self::EPS, self::GOOGLE_PAY, self::IDEAL, self::WERO, self::ILLICADO, self::INTERSOLVE, self::KLARNA, self::KLARNA_PAY_LATER, self::KLARNA_FINANCING, self::KLARNA_BANK_TRANSFER, self::KLARNA_DIRECT_DEBIT, self::KLARNA_PAY_WITH_KLARNA, self::LINXO, self::MB_WAY, self::MEALVOUCHERS, self::MULTIBANCO, self::ONEY_3X, self::ONEY_4X, self::ONEY_3X4X_IN_3, self::ONEY_3X4X_IN_4, self::ONEY_3X4X, self::ONEY_BANK_CARD, self::ONEY_FINANCEMENT_LONG, self::ONEY_BRANDED_GIFT_CARD, self::PRZELEWY24, self::PAYPAL, self::POSTFINANCE_PAY, self::SEPA_DIRECT_DEBIT, self::SOFINCO, self::SPIRIT_OF_CADEAU, self::TWINT, self::WECHAT_PAY];
    public const CARDS = 'cards';
    public const HOSTED_CHECKOUT = 'hosted_checkout';
    public const ALIPAY = '5405';
    public const APPLE_PAY = '302';
    public const BANK_TRANSFER = '5408';
    public const CADO = '3103';
    public const BIZUM = '5001';
    public const CADHOC = '5601';
    public const CHEQUE_VACANCES_CONNECT = '5403';
    public const CETELEM = '5133';
    public const COFIDIS = '5129';
    public const CPAY = '5100';
    // Cards
    public const AMERICAN_EXPRESS = '2';
    public const BANCONTACT = '3012';
    public const CARTE_BANCAIRE = '130';
    public const DINERS_CLUB = '132';
    public const DISCOVER = '128';
    public const JCB = '125';
    public const MASTERCARD = '3';
    public const MAESTRO = '117';
    public const UPI = '56';
    public const VISA = '1';
    //
    public const EPS = '5406';
    public const GOOGLE_PAY = '320';
    public const IDEAL = '809';
    public const WERO = '900';
    public const ILLICADO = '3112';
    public const INTERSOLVE = '5700';
    public const KLARNA = '3301';
    public const KLARNA_PAY_LATER = '3302';
    public const KLARNA_FINANCING = '3303';
    public const KLARNA_BANK_TRANSFER = '3304';
    public const KLARNA_DIRECT_DEBIT = '3305';
    public const KLARNA_PAY_WITH_KLARNA = '3306';
    public const MB_WAY = '5908';
    public const MEALVOUCHERS = '5402';
    public const MULTIBANCO = '5500';
    public const ONEY_3X = '5111';
    public const ONEY_4X = '5112';
    /**
     * The Oney 3x4x family: pay in three, pay in four, or let the shopper choose on the hosted page.
     *
     * Named for the family rather than as `ONEY_3X` / `ONEY_4X`, which are taken above by `5111` and
     * `5112` - an older pair this integration does not offer (see the WooCommerce plugin's own
     * `PaymentProductService::SUPPORTED_PAYMENT_PRODUCTS`). Same instalment counts, different
     * products; the names have to keep them apart.
     *
     * France only, EUR only, and direct sale only - which is why they must NOT appear in
     * {@see self::SEPARATE_CAPTURE_SUPPORTED}: that list is the only thing that would raise
     * `requiresApproval` to true for them.
     */
    public const ONEY_3X4X_IN_3 = '5146';
    public const ONEY_3X4X_IN_4 = '5147';
    public const ONEY_3X4X = '5148';
    public const ONEY_BANK_CARD = '5127';
    public const ONEY_FINANCEMENT_LONG = '5125';
    public const ONEY_BRANDED_GIFT_CARD = '5600';
    public const PRZELEWY24 = '3124';
    public const PAYPAL = '840';
    public const POSTFINANCE_PAY = '3203';
    public const SEPA_DIRECT_DEBIT = '771';
    public const SOFINCO = '5131';
    public const SPIRIT_OF_CADEAU = '3116';
    public const TWINT = '5407';
    public const WECHAT_PAY = '5404';
    public const BLIK = '3204';
    public const LINXO = '5003';
    public const CARD_BRANDS = [self::AMERICAN_EXPRESS, self::CARTE_BANCAIRE, self::DINERS_CLUB, self::DISCOVER, self::JCB, self::MASTERCARD, self::MAESTRO, self::UPI, self::VISA];
    public const SEPARATE_CAPTURE_SUPPORTED = [self::HOSTED_CHECKOUT, self::APPLE_PAY, self::BIZUM, self::CETELEM, self::COFIDIS, self::CPAY, self::CARDS, self::GOOGLE_PAY, self::KLARNA, self::MB_WAY, self::ONEY_BANK_CARD, self::PAYPAL, self::POSTFINANCE_PAY, self::SOFINCO, self::TWINT, self::WECHAT_PAY];
    private string $id;
    private function __construct(string $id)
    {
        $this->id = $id;
    }
    /**
     * String representation of the payment method id
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->id;
    }
    public static function parse(string $id): PaymentProductId
    {
        if (!self::isSupported($id)) {
            throw new InvalidPaymentProductIdException(new TranslatableLabel(sprintf('Payment prodcut ID is invalid %s.', $id), 'paymentMethod.invalidProductId', [$id]));
        }
        return new self($id);
    }
    public static function getForHostedCheckoutPage(array $supportedPaymentProducts): array
    {
        return array_diff(
            $supportedPaymentProducts,
            [self::CARDS, self::HOSTED_CHECKOUT, self::MEALVOUCHERS],
            // Oney 3x4x needs a payment option, shipping method and product types that the generic
            // hosted page request does not carry, so it is offered only as its own payment method.
            self::oney3x4xProductIds()
        );
    }
    public static function isSupported(string $id): bool
    {
        return in_array($id, self::SUPPORTED_PAYMENT_PRODUCTS, \true);
    }
    public function equals(string $id): bool
    {
        return $this->id === $id;
    }
    public static function cards(): PaymentProductId
    {
        return new self(self::CARDS);
    }
    /**
     * @return PaymentProductId[]
     * @throws InvalidPaymentProductIdException
     */
    public static function getAllCardBrands(): array
    {
        return array_map(function ($paymentProductId) {
            return PaymentProductId::parse($paymentProductId);
        }, PaymentProductId::CARD_BRANDS);
    }
    public static function hostedCheckout(): PaymentProductId
    {
        return new self(self::HOSTED_CHECKOUT);
    }
    public static function alipay(): PaymentProductId
    {
        return new self(self::ALIPAY);
    }
    public static function applePay(): PaymentProductId
    {
        return new self(self::APPLE_PAY);
    }
    public static function bankTransfer(): PaymentProductId
    {
        return new self(self::BANK_TRANSFER);
    }
    public static function cado(): PaymentProductId
    {
        return new self(self::CADO);
    }
    public static function bizum(): PaymentProductId
    {
        return new self(self::BIZUM);
    }
    public static function cadhoc(): PaymentProductId
    {
        return new self(self::CADHOC);
    }
    public static function chequeVacancesConnect(): PaymentProductId
    {
        return new self(self::CHEQUE_VACANCES_CONNECT);
    }
    public static function cetelem(): PaymentProductId
    {
        return new self(self::CETELEM);
    }
    public static function cofidis(): PaymentProductId
    {
        return new self(self::COFIDIS);
    }
    public static function cpay(): PaymentProductId
    {
        return new self(self::CPAY);
    }
    public static function americanExpress(): PaymentProductId
    {
        return new self(self::AMERICAN_EXPRESS);
    }
    public static function bancontact(): PaymentProductId
    {
        return new self(self::BANCONTACT);
    }
    public static function carteBancaire(): PaymentProductId
    {
        return new self(self::CARTE_BANCAIRE);
    }
    public static function dinersClub(): PaymentProductId
    {
        return new self(self::DINERS_CLUB);
    }
    public static function discover(): PaymentProductId
    {
        return new self(self::DISCOVER);
    }
    public static function jcb(): PaymentProductId
    {
        return new self(self::JCB);
    }
    public static function mastercard(): PaymentProductId
    {
        return new self(self::MASTERCARD);
    }
    public static function maestro(): PaymentProductId
    {
        return new self(self::MAESTRO);
    }
    public static function upi(): PaymentProductId
    {
        return new self(self::UPI);
    }
    public static function visa(): PaymentProductId
    {
        return new self(self::VISA);
    }
    public static function eps(): PaymentProductId
    {
        return new self(self::EPS);
    }
    public static function googlePay(): PaymentProductId
    {
        return new self(self::GOOGLE_PAY);
    }
    public static function ideal(): PaymentProductId
    {
        return new self(self::IDEAL);
    }
    public static function wero(): PaymentProductId
    {
        return new self(self::WERO);
    }
    public static function illicado(): PaymentProductId
    {
        return new self(self::ILLICADO);
    }
    public static function intersolve(): PaymentProductId
    {
        return new self(self::INTERSOLVE);
    }
    public static function klarna(): PaymentProductId
    {
        return new self(self::KLARNA);
    }
    public static function klarnaPayLater(): PaymentProductId
    {
        return new self(self::KLARNA_PAY_LATER);
    }
    public static function klarnaFinancing(): PaymentProductId
    {
        return new self(self::KLARNA_FINANCING);
    }
    public static function klarnaBankTransfer(): PaymentProductId
    {
        return new self(self::KLARNA_BANK_TRANSFER);
    }
    public static function klarnaDirectDebit(): PaymentProductId
    {
        return new self(self::KLARNA_DIRECT_DEBIT);
    }
    public static function klarnaPayWithKlarna(): PaymentProductId
    {
        return new self(self::KLARNA_PAY_WITH_KLARNA);
    }
    public static function mbWay(): PaymentProductId
    {
        return new self(self::MB_WAY);
    }
    public static function mealvouchers(): PaymentProductId
    {
        return new self(self::MEALVOUCHERS);
    }
    public static function multibanco(): PaymentProductId
    {
        return new self(self::MULTIBANCO);
    }
    public static function oney3x(): PaymentProductId
    {
        return new self(self::ONEY_3X);
    }
    public static function oney3x4xIn3(): PaymentProductId
    {
        return new self(self::ONEY_3X4X_IN_3);
    }
    public static function oney3x4xIn4(): PaymentProductId
    {
        return new self(self::ONEY_3X4X_IN_4);
    }
    public static function oney3x4x(): PaymentProductId
    {
        return new self(self::ONEY_3X4X);
    }
    /**
     * Every product that carries a merchant-configured Oney payment option.
     *
     * One list rather than a chain of `equals()` per product: the request transformer and the two
     * places that build the method's additional data all ask the same question, and a product added
     * to the family has to reach all three.
     *
     * @return string[]
     */
    public static function oneyProductIds(): array
    {
        return [self::ONEY_3X, self::ONEY_4X, self::ONEY_3X4X_IN_3, self::ONEY_3X4X_IN_4, self::ONEY_3X4X, self::ONEY_BANK_CARD, self::ONEY_FINANCEMENT_LONG, self::ONEY_BRANDED_GIFT_CARD];
    }
    public function isOney(): bool
    {
        return in_array($this->id, self::oneyProductIds(), \true);
    }
    /**
     * The Oney 3x4x family (`5146`, `5147`, `5148`).
     *
     * @return string[]
     */
    public static function oney3x4xProductIds(): array
    {
        return [self::ONEY_3X4X_IN_3, self::ONEY_3X4X_IN_4, self::ONEY_3X4X];
    }
    public function isOney3x4x(): bool
    {
        return in_array($this->id, self::oney3x4xProductIds(), \true);
    }
    public static function oney4x(): PaymentProductId
    {
        return new self(self::ONEY_4X);
    }
    public static function oneyBankCard(): PaymentProductId
    {
        return new self(self::ONEY_BANK_CARD);
    }
    public static function oneyFinancementLong(): PaymentProductId
    {
        return new self(self::ONEY_FINANCEMENT_LONG);
    }
    public static function oneyBrandedGiftCard(): PaymentProductId
    {
        return new self(self::ONEY_BRANDED_GIFT_CARD);
    }
    public static function przelewy24(): PaymentProductId
    {
        return new self(self::PRZELEWY24);
    }
    public static function paypal(): PaymentProductId
    {
        return new self(self::PAYPAL);
    }
    public static function postfinancePay(): PaymentProductId
    {
        return new self(self::POSTFINANCE_PAY);
    }
    public static function sepaDirectDebit(): PaymentProductId
    {
        return new self(self::SEPA_DIRECT_DEBIT);
    }
    public static function sofinco(): PaymentProductId
    {
        return new self(self::SOFINCO);
    }
    public static function spiritOfCadeau(): PaymentProductId
    {
        return new self(self::SPIRIT_OF_CADEAU);
    }
    public static function twint(): PaymentProductId
    {
        return new self(self::TWINT);
    }
    public static function wechatPay(): PaymentProductId
    {
        return new self(self::WECHAT_PAY);
    }
    public static function blik(): PaymentProductId
    {
        return new self(self::BLIK);
    }
    public static function linxo(): PaymentProductId
    {
        return new self(self::LINXO);
    }
    public function getId(): string
    {
        return $this->id;
    }
    public function isCardBrand(): bool
    {
        return in_array($this->id, self::CARD_BRANDS);
    }
    public function isCardType(): bool
    {
        return in_array($this->id, array_merge(self::CARD_BRANDS, [self::CARDS, self::INTERSOLVE, self::CPAY, self::BANCONTACT]), \true);
    }
    public function isMobileType(): bool
    {
        return in_array($this->id, [self::APPLE_PAY, self::GOOGLE_PAY], \true);
    }
    public function isSeparateCaptureSupported(): bool
    {
        return in_array($this->id, self::SEPARATE_CAPTURE_SUPPORTED, \true);
    }
    public function isRedirectType(): bool
    {
        return !$this->isMobileType() && !$this->isCardType();
    }
}
