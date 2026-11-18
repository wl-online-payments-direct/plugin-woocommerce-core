<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
defined('ABSPATH') || exit;
/**
 * Copies customers' saved cards (vaulted tokens) from the 2.x plugin. The 2.x plugin stores card
 * tokens as WC payment tokens under two gateways: the hosted-tokenization iframe gateway and the
 * main hosted-checkout gateway (LegacySource::cardGatewayIds()). Each card token is saved through
 * the core tokens repository, which seeds the core token store and mirrors the card onto this
 * plugin's cards gateway so it is offered to the customer again. The 2.x rows are only read.
 * Idempotent: cards already present in the core store are skipped.
 */
class SavedCardMigration
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Legacy WC card-type slug => Worldline payment product id, for tokens that lack the product id. */
    private const CARD_TYPE_PRODUCT_ID = ['visa' => '1', 'mastercard' => '3', 'amex' => '2', 'american-express' => '2', 'maestro' => '117', 'diners' => '132', 'diners-club' => '132', 'discover' => '128', 'jcb' => '125', 'cartes-bancaires' => '130', 'carte-bancaire' => '130', 'cb' => '130', 'bancontact' => '3012', 'upi' => '56'];
    /**
     * Copies every 2.x card token.
     */
    public function run(): void
    {
        if (!class_exists(\WC_Payment_Token_CC::class) || !class_exists(\WC_Payment_Tokens::class)) {
            return;
        }
        global $wpdb;
        $gatewayIds = LegacySource::cardGatewayIds();
        $placeholders = implode(', ', array_fill(0, count($gatewayIds), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is a generated list of %s placeholders; values are bound by prepare().
        $tokenIds = $wpdb->get_col($wpdb->prepare("SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens" . " WHERE type = %s AND gateway_id IN ({$placeholders}) ORDER BY token_id", array_merge(['CC'], $gatewayIds)));
        // phpcs:enable
        foreach ((array) $tokenIds as $tokenId) {
            $wcToken = \WC_Payment_Tokens::get((int) $tokenId);
            if ($wcToken instanceof \WC_Payment_Token) {
                $this->importOne($wcToken);
            }
        }
    }
    /**
     * Copies one 2.x card token into the core store (and so onto this plugin's cards gateway).
     * Tokens that are not CC tokens on a 2.x cards gateway, or that the core already holds for the
     * customer, are skipped.
     *
     * @param \WC_Payment_Token $wcToken
     *
     * @return bool Whether the token was saved.
     */
    public function importOne(\WC_Payment_Token $wcToken): bool
    {
        if (!$wcToken instanceof \WC_Payment_Token_CC || !in_array((string) $wcToken->get_gateway_id(), LegacySource::cardGatewayIds(), \true)) {
            return \false;
        }
        $customerId = (string) $wcToken->get_user_id();
        $worldlineTokenId = (string) $wcToken->get_token();
        // Newer tokens carry the product id; older ones only the WC card-type slug.
        $productId = (string) $wcToken->get_meta('paymentProductId');
        if ($productId === '') {
            $productId = self::CARD_TYPE_PRODUCT_ID[strtolower((string) $wcToken->get_card_type())] ?? '';
        }
        if ((int) $customerId <= 0 || $worldlineTokenId === '' || $productId === '') {
            return \false;
        }
        $month = str_pad((string) $wcToken->get_expiry_month(), 2, '0', \STR_PAD_LEFT);
        $year = substr((string) $wcToken->get_expiry_year(), -2);
        $token = new Token($customerId, $worldlineTokenId, $productId, (string) $wcToken->get_last4(), $month . $year);
        try {
            return (bool) StoreContext::doWithStore(self::STORE_ID, static function () use ($customerId, $token): bool {
                /** @var TokensRepositoryInterface $repository */
                $repository = ServiceRegister::getService(TokensRepositoryInterface::class);
                if ($repository->get($customerId, $token->getTokenId()) !== null) {
                    return \false;
                }
                $repository->save($customerId, $token);
                return \true;
            });
        } catch (\Throwable $e) {
            Logger::logWarning('Saved-card migration skipped token ' . $worldlineTokenId . ': ' . $e->getMessage(), 'Migration');
            return \false;
        }
    }
}
