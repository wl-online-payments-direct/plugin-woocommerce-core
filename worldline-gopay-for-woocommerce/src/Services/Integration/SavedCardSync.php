<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\LegacySource;
use WOP\OnlinePayments\Migration\SavedCardMigration;
defined('ABSPATH') || exit;
/**
 * Keeps the saved cards of this plugin and the 2.x plugin of the same brand in step, without any
 * 2.x change. Both plugins hold a WC token row for the same Worldline token; rows are matched by
 * the same customer (user id) and the same token value only.
 *
 * - A card deleted on this plugin's gateway: the Worldline delete stays with
 *   WcTokensRepository::onWcTokenDeleted; this class then deletes the 2.x rows.
 * - A card deleted on a 2.x cards gateway: this plugin's core token and own WC row are deleted
 *   locally, without a Worldline API call (2.x deletes the token at Worldline).
 * - A card saved by 2.x: copied into this plugin.
 */
class SavedCardSync
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Hooked on woocommerce_payment_token_deleted, after WcTokensRepository::onWcTokenDeleted.
     *
     * @param int $wcTokenDbId
     * @param \WC_Payment_Token|mixed $wcToken
     */
    public static function onTokenDeleted($wcTokenDbId, $wcToken): void
    {
        if (WcTokensRepository::isSyncing() || !$wcToken instanceof \WC_Payment_Token) {
            return;
        }
        $userId = (int) $wcToken->get_user_id();
        $tokenValue = (string) $wcToken->get_token();
        $gatewayId = (string) $wcToken->get_gateway_id();
        if ($userId <= 0 || $tokenValue === '') {
            return;
        }
        try {
            if (ModuleHelper::ownsGateway($gatewayId)) {
                WcTokensRepository::deleteWcTokens($userId, $tokenValue, LegacySource::cardGatewayIds());
                return;
            }
            if (in_array($gatewayId, LegacySource::cardGatewayIds(), \true)) {
                self::deleteOwnCopy($userId, $tokenValue);
            }
        } catch (\Throwable $e) {
            Logger::logWarning('Saved-card sync failed for WooCommerce payment token ' . (int) $wcTokenDbId . ': ' . $e->getMessage(), 'Integration');
        }
    }
    /**
     * Hooked on woocommerce_new_payment_token: copies a card the 2.x plugin saves.
     *
     * @param int $wcTokenDbId
     * @param \WC_Payment_Token|mixed $wcToken
     */
    public static function onTokenCreated($wcTokenDbId, $wcToken): void
    {
        if (!$wcToken instanceof \WC_Payment_Token_CC || !in_array((string) $wcToken->get_gateway_id(), LegacySource::cardGatewayIds(), \true)) {
            return;
        }
        try {
            (new SavedCardMigration())->importOne($wcToken);
        } catch (\Throwable $e) {
            Logger::logWarning('Saved-card sync could not copy WooCommerce payment token ' . (int) $wcTokenDbId . ': ' . $e->getMessage(), 'Integration');
        }
    }
    /**
     * Deletes this plugin's core token and own WC row of a card deleted in 2.x. Local only.
     *
     * @param int $userId
     * @param string $tokenValue
     */
    private static function deleteOwnCopy(int $userId, string $tokenValue): void
    {
        StoreContext::doWithStore(self::STORE_ID, static function () use ($userId, $tokenValue): void {
            /** @var TokensRepositoryInterface $repository */
            $repository = ServiceRegister::getService(TokensRepositoryInterface::class);
            $token = $repository->get((string) $userId, $tokenValue);
            if ($token !== null) {
                $repository->delete([$token]);
            }
        });
        // The own row can exist without a core token; remove it too.
        WcTokensRepository::deleteWcTokens($userId, $tokenValue, [WcTokensRepository::ownCardsGatewayId()]);
    }
}
