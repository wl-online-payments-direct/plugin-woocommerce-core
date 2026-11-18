<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductService as CorePaymentProductService;
defined('ABSPATH') || exit;
/**
 * Defines the subset of Worldline payment products supported by this integration.
 */
class PaymentProductService extends CorePaymentProductService
{
    public const SUPPORTED_PAYMENT_PRODUCTS = [
        PaymentProductId::CARDS,
        PaymentProductId::HOSTED_CHECKOUT,
        PaymentProductId::ALIPAY,
        PaymentProductId::APPLE_PAY,
        PaymentProductId::BANK_TRANSFER,
        PaymentProductId::BIZUM,
        PaymentProductId::BLIK,
        PaymentProductId::WERO,
        PaymentProductId::CHEQUE_VACANCES_CONNECT,
        PaymentProductId::AMERICAN_EXPRESS,
        PaymentProductId::BANCONTACT,
        PaymentProductId::CARTE_BANCAIRE,
        PaymentProductId::DINERS_CLUB,
        PaymentProductId::DISCOVER,
        PaymentProductId::JCB,
        PaymentProductId::MASTERCARD,
        PaymentProductId::MAESTRO,
        PaymentProductId::UPI,
        PaymentProductId::VISA,
        PaymentProductId::EPS,
        PaymentProductId::GOOGLE_PAY,
        PaymentProductId::IDEAL,
        PaymentProductId::ILLICADO,
        PaymentProductId::INTERSOLVE,
        PaymentProductId::KLARNA,
        PaymentProductId::KLARNA_PAY_LATER,
        PaymentProductId::KLARNA_FINANCING,
        PaymentProductId::KLARNA_BANK_TRANSFER,
        PaymentProductId::KLARNA_DIRECT_DEBIT,
        PaymentProductId::KLARNA_PAY_WITH_KLARNA,
        PaymentProductId::LINXO,
        PaymentProductId::SOFINCO,
        PaymentProductId::SEPA_DIRECT_DEBIT,
        PaymentProductId::MEALVOUCHERS,
        PaymentProductId::MULTIBANCO,
        PaymentProductId::ONEY_BRANDED_GIFT_CARD,
        // The Oney 3x4x family. The older ONEY_3X / ONEY_4X (`5111` / `5112`) stay out: this
        // integration has never offered them, so adding them now would surface methods no merchant
        // here has ever configured.
        PaymentProductId::ONEY_3X4X_IN_3,
        PaymentProductId::ONEY_3X4X_IN_4,
        PaymentProductId::ONEY_3X4X,
        PaymentProductId::PRZELEWY24,
        PaymentProductId::PAYPAL,
        PaymentProductId::POSTFINANCE_PAY,
        PaymentProductId::TWINT,
        PaymentProductId::WECHAT_PAY,
    ];
    /**
     * @inheritDoc
     *
     * @param bool $withCards
     *
     * @return string[]
     */
    public function getSupportedPaymentMethods(bool $withCards = \true): array
    {
        if ($withCards) {
            return self::SUPPORTED_PAYMENT_PRODUCTS;
        }
        return array_diff(self::SUPPORTED_PAYMENT_PRODUCTS, PaymentProductId::CARD_BRANDS);
    }
}
