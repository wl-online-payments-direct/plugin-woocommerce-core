<?php

namespace WOP\OnlinePayments\Gateway;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Labels and WooCommerce gateway ids for the Worldline payment products shown as
 * their own checkout fields (MethodGateway).
 */
class MethodCatalog
{
    /**
     * Gateway id suffixes; these must not change, as WooCommerce resolves the gateway of
     * historical orders and saved tokens by id.
     */
    private const GATEWAY_SUFFIXES = [
        PaymentProductId::CARDS => 'hosted-tokenization',
        PaymentProductId::GOOGLE_PAY => 'google-pay',
        PaymentProductId::APPLE_PAY => 'apple-pay',
        PaymentProductId::BANK_TRANSFER => 'bank-transfer',
        PaymentProductId::IDEAL => 'ideal',
        PaymentProductId::WERO => 'wero',
        PaymentProductId::PAYPAL => 'paypal',
        PaymentProductId::KLARNA => 'klarna-pay-now',
        PaymentProductId::KLARNA_PAY_LATER => 'klarna-pay-later',
        PaymentProductId::KLARNA_FINANCING => 'klarna-financing',
        PaymentProductId::KLARNA_BANK_TRANSFER => 'klarna-bank-transfer',
        PaymentProductId::KLARNA_DIRECT_DEBIT => 'klarna-direct-debit',
        PaymentProductId::KLARNA_PAY_WITH_KLARNA => 'klarna-pay-with-klarna',
        PaymentProductId::POSTFINANCE_PAY => 'postfinance',
        PaymentProductId::TWINT => 'twint',
        PaymentProductId::MEALVOUCHERS => 'mealvouchers',
        PaymentProductId::CHEQUE_VACANCES_CONNECT => 'cvco',
        PaymentProductId::ILLICADO => 'illicado',
        PaymentProductId::EPS => 'eps',
        PaymentProductId::PRZELEWY24 => 'przelewy24',
        PaymentProductId::SEPA_DIRECT_DEBIT => 'sepa-direct-debit',
        PaymentProductId::VISA => 'visa',
        PaymentProductId::MASTERCARD => 'mastercard',
        PaymentProductId::AMERICAN_EXPRESS => 'american-express',
        PaymentProductId::MAESTRO => 'maestro',
        PaymentProductId::CARTE_BANCAIRE => 'carte-bancaire',
        PaymentProductId::DINERS_CLUB => 'diners-club',
        PaymentProductId::DISCOVER => 'discover',
        PaymentProductId::JCB => 'jcb',
        PaymentProductId::UPI => 'upi',
        PaymentProductId::SOFINCO => 'sofinco',
        PaymentProductId::BANCONTACT => 'bancontact',
        PaymentProductId::MULTIBANCO => 'multibanco',
        PaymentProductId::BIZUM => 'bizum',
        PaymentProductId::WECHAT_PAY => 'wechat-pay',
        PaymentProductId::ALIPAY => 'alipay',
        PaymentProductId::INTERSOLVE => 'intersolve',
        PaymentProductId::ONEY_BRANDED_GIFT_CARD => 'oney-gift-card',
        // Readable gateway ids. Without them these become `worldline-5146` and so on, and the gateway
        // id is written onto every order - changing it later is a data migration.
        PaymentProductId::ONEY_3X4X_IN_3 => 'oney-3x',
        PaymentProductId::ONEY_3X4X_IN_4 => 'oney-4x',
        PaymentProductId::ONEY_3X4X => 'oney-3x4x',
        PaymentProductId::BLIK => 'blik',
        PaymentProductId::LINXO => 'linxo-connect',
    ];
    /**
     * WooCommerce gateway id for a payment product: "{brand prefix}-{suffix}", with
     * the product id itself as the suffix fallback for unmapped products.
     *
     * @param PaymentProductId $productId
     *
     * @return string
     */
    public static function gatewayId(PaymentProductId $productId): string
    {
        $key = (string) $productId;
        $suffix = self::GATEWAY_SUFFIXES[$key] ?? $key;
        return ModuleHelper::getModuleConfig()->getGatewayPrefix() . '-' . $suffix;
    }
    /**
     * Whether a gateway id is one this plugin can register: the main Hosted Checkout gateway,
     * Pay by Link, or a payment product listed in this catalog. Independent of which methods are
     * enabled right now.
     *
     * @param string $gatewayId
     *
     * @return bool
     */
    public static function isKnownGatewayId(string $gatewayId): bool
    {
        if ($gatewayId === ModuleHelper::getMainGatewayId() || $gatewayId === ModuleHelper::getModuleConfig()->getGatewayPrefix() . PayByLinkGateway::ID_SUFFIX) {
            return \true;
        }
        $prefix = ModuleHelper::getModuleConfig()->getGatewayPrefix() . '-';
        if (strncmp($gatewayId, $prefix, strlen($prefix)) !== 0) {
            return \false;
        }
        return in_array(substr($gatewayId, strlen($prefix)), self::GATEWAY_SUFFIXES, \true);
    }
    /**
     * Display label for a payment product, with a generic fallback for unknown ids.
     *
     * @param PaymentProductId $productId
     *
     * @return string
     */
    public static function label(PaymentProductId $productId): string
    {
        $labels = [
            PaymentProductId::CARDS => 'Cards',
            PaymentProductId::VISA => 'Visa',
            PaymentProductId::MASTERCARD => 'Mastercard',
            PaymentProductId::AMERICAN_EXPRESS => 'American Express',
            PaymentProductId::MAESTRO => 'Maestro',
            PaymentProductId::CARTE_BANCAIRE => 'Carte Bancaire',
            PaymentProductId::DINERS_CLUB => 'Diners Club',
            PaymentProductId::DISCOVER => 'Discover',
            PaymentProductId::JCB => 'JCB',
            PaymentProductId::APPLE_PAY => 'Apple Pay',
            PaymentProductId::GOOGLE_PAY => 'Google Pay',
            PaymentProductId::IDEAL => 'iDEAL | Wero',
            PaymentProductId::WERO => 'Wero',
            PaymentProductId::PAYPAL => 'PayPal',
            PaymentProductId::KLARNA => 'Klarna',
            PaymentProductId::KLARNA_PAY_LATER => 'Klarna Pay Later',
            PaymentProductId::KLARNA_FINANCING => 'Klarna Financing',
            PaymentProductId::KLARNA_BANK_TRANSFER => 'Klarna Bank Transfer',
            PaymentProductId::KLARNA_DIRECT_DEBIT => 'Klarna Direct Debit',
            PaymentProductId::KLARNA_PAY_WITH_KLARNA => 'Klarna Pay with Klarna',
            PaymentProductId::SOFINCO => 'Sofinco',
            PaymentProductId::SEPA_DIRECT_DEBIT => 'SEPA Direct Debit',
            PaymentProductId::BANCONTACT => 'Bancontact',
            PaymentProductId::PRZELEWY24 => 'Przelewy24',
            PaymentProductId::EPS => 'EPS',
            PaymentProductId::MULTIBANCO => 'Multibanco',
            PaymentProductId::POSTFINANCE_PAY => 'PostFinance Pay',
            PaymentProductId::TWINT => 'TWINT',
            PaymentProductId::BIZUM => 'Bizum',
            PaymentProductId::WECHAT_PAY => 'WeChat Pay',
            PaymentProductId::ALIPAY => 'Alipay',
            PaymentProductId::BANK_TRANSFER => 'Bank transfer',
            PaymentProductId::ILLICADO => 'Illicado',
            PaymentProductId::INTERSOLVE => 'Intersolve',
            PaymentProductId::ONEY_BRANDED_GIFT_CARD => 'Oney gift card',
            // Only reached when the core has no configured name for the method; the merchant's own
            // name wins (`GatewayModule::enabledMethods()`).
            PaymentProductId::ONEY_3X4X_IN_3 => 'Oney 3x',
            PaymentProductId::ONEY_3X4X_IN_4 => 'Oney 4x',
            PaymentProductId::ONEY_3X4X => 'Oney 3x4x',
            PaymentProductId::CHEQUE_VACANCES_CONNECT => 'Chèque-Vacances Connect',
            PaymentProductId::MEALVOUCHERS => 'Mealvouchers',
            PaymentProductId::UPI => 'UPI',
            PaymentProductId::BLIK => 'BLIK',
            PaymentProductId::LINXO => 'Linxo Connect',
        ];
        $key = (string) $productId;
        return $labels[$key] ?? ModuleHelper::getModuleConfig()->getName() . ' ' . $key;
    }
}
