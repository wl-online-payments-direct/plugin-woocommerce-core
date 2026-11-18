<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Repositories\TokensRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\Token;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Gateway\MethodCatalog;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\LegacySource;
defined('ABSPATH') || exit;
/**
 * Token repository that mirrors every saved card into WooCommerce's native payment tokens. Mirror
 * lookups and writes only touch this plugin's own cards gateway; the 2.x plugin's rows for the same
 * Worldline token are removed when the core deletes the token.
 */
class WcTokensRepository implements TokensRepositoryInterface
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Suppresses the WC deletion hooks of this plugin while it deletes WC token rows itself. */
    private static bool $syncing = \false;
    private TokensRepositoryInterface $inner;
    /**
     * @param TokensRepositoryInterface $inner
     */
    public function __construct(TokensRepositoryInterface $inner)
    {
        $this->inner = $inner;
    }
    /**
     * @param string $customerId
     * @param string $tokenId
     *
     * @return Token|null
     */
    public function get(string $customerId, string $tokenId): ?Token
    {
        return $this->inner->get($customerId, $tokenId);
    }
    /**
     * @param string $customerId
     *
     * @return Token[]
     */
    public function getForCustomer(string $customerId): array
    {
        return $this->inner->getForCustomer($customerId);
    }
    /**
     * @param string $customerId
     * @param Token $token
     */
    public function save(string $customerId, Token $token): void
    {
        // Serialize per customer so concurrent saves don't duplicate the mirror.
        $release = self::acquireSaveLock($customerId);
        try {
            $this->inner->save($customerId, $token);
            $this->saveMirror($customerId, $token);
        } finally {
            $release();
        }
    }
    /**
     * Deletes the tokens from the core store, the own WC mirrors and the 2.x WC rows with the same
     * customer and token value (a token Worldline reports invalid is invalid for both plugins).
     *
     * @param Token[] $tokens
     */
    public function delete(array $tokens): void
    {
        $this->inner->delete($tokens);
        foreach ($tokens as $token) {
            $userId = (int) $token->getCustomerId();
            self::deleteWcTokens($userId, $token->getTokenId(), [self::ownCardsGatewayId()]);
            self::deleteWcTokens($userId, $token->getTokenId(), LegacySource::cardGatewayIds());
        }
    }
    /**
     * Whether this class is currently deleting WC token rows itself; deletion hooks must then
     * not react to the rows it removes.
     *
     * @return bool
     */
    public static function isSyncing(): bool
    {
        return self::$syncing;
    }
    /**
     * Gateway id of this plugin's cards gateway, where the mirrors live.
     *
     * @return string
     */
    public static function ownCardsGatewayId(): string
    {
        return MethodCatalog::gatewayId(PaymentProductId::cards());
    }
    /**
     * Deletes the WC token rows of one customer with the given Worldline token value on the given
     * gateways, through the WooCommerce API. This plugin's own deletion hooks are suppressed for
     * these rows; other plugins' hooks still run. A failing row is logged and skipped.
     *
     * @param int $userId
     * @param string $tokenValue
     * @param string[] $gatewayIds
     */
    public static function deleteWcTokens(int $userId, string $tokenValue, array $gatewayIds): void
    {
        if (!class_exists(\WC_Payment_Tokens::class)) {
            return;
        }
        foreach (self::findWcTokenIds($userId, $tokenValue, $gatewayIds) as $wcTokenId) {
            $previous = self::$syncing;
            self::$syncing = \true;
            try {
                \WC_Payment_Tokens::delete($wcTokenId);
            } catch (\Throwable $e) {
                Logger::logWarning('Failed to delete WooCommerce payment token ' . $wcTokenId . ': ' . $e->getMessage(), 'Integration');
            } finally {
                self::$syncing = $previous;
            }
        }
    }
    /**
     * Deletes the token at Worldline (and from the core storage) when the customer
     * removes a saved card through a native WooCommerce surface. Hooked on
     * woocommerce_payment_token_deleted.
     *
     * @param int $wcTokenDbId
     * @param \WC_Payment_Token|mixed $wcToken
     */
    public static function onWcTokenDeleted($wcTokenDbId, $wcToken): void
    {
        if (self::$syncing || !$wcToken instanceof \WC_Payment_Token || !ModuleHelper::ownsGateway((string) $wcToken->get_gateway_id())) {
            return;
        }
        try {
            CheckoutAPI::get()->hostedTokenization(self::STORE_ID)->deleteToken((string) $wcToken->get_user_id(), (string) $wcToken->get_token());
        } catch (\Throwable $e) {
            // The token row is already gone in WooCommerce; Worldline cleanup is best-effort.
        }
    }
    /**
     * Creates the WC_Payment_Token_CC mirror of a token, or skips it when the customer already has a
     * mirror with the same token value.
     *
     * @param string $customerId
     * @param Token $token
     */
    private function saveMirror(string $customerId, Token $token): void
    {
        $userId = (int) $customerId;
        if ($userId <= 0 || !class_exists(\WC_Payment_Token_CC::class)) {
            return;
        }
        $expiry = $token->getExpiryDate();
        $month = substr($expiry, 0, 2);
        $year = substr($expiry, -2);
        $last4 = substr($token->getCardNumber(), -4);
        $cardType = self::cardType($token->getProductId());
        // One mirror per Worldline token value: a card saved twice (two token values with the same
        // last4/expiry/brand) keeps two mirrors, so each 2.x row keeps its own 3.0 copy and deletion
        // by token value finds it. Read the rows directly: get_customer_tokens() is filtered (hidden
        // rows, rewritten gateway ids) and limited to one page.
        $gatewayId = self::ownCardsGatewayId();
        if (self::findWcTokenIds($userId, $token->getTokenId(), [$gatewayId]) !== []) {
            return;
        }
        $wcToken = new \WC_Payment_Token_CC();
        $wcToken->set_token($token->getTokenId());
        $wcToken->set_user_id($userId);
        $wcToken->set_gateway_id($gatewayId);
        $wcToken->set_card_type($cardType);
        $wcToken->set_last4(ctype_digit((string) $last4) ? $last4 : '0000');
        $wcToken->set_expiry_month(ctype_digit((string) $month) ? $month : '00');
        $wcToken->set_expiry_year(ctype_digit((string) $year) ? '20' . $year : '0000');
        $wcToken->add_meta_data('paymentProductId', $token->getProductId(), \true);
        $wcToken->save();
    }
    /**
     * Ids of a customer's WC token rows on the given gateways, optionally with one token value.
     * Read directly so that no token filter hides or rewrites rows.
     *
     * @param int $userId
     * @param string|null $tokenValue Null for every token value.
     * @param string[] $gatewayIds
     *
     * @return int[]
     */
    private static function findWcTokenIds(int $userId, ?string $tokenValue, array $gatewayIds): array
    {
        global $wpdb;
        if ($userId <= 0 || $tokenValue === '' || $gatewayIds === [] || !$wpdb instanceof \wpdb) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($gatewayIds), '%s'));
        $sql = "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens" . " WHERE user_id = %d AND gateway_id IN ({$placeholders})";
        $args = array_merge([$userId], array_values($gatewayIds));
        if ($tokenValue !== null) {
            $sql .= ' AND token = %s';
            $args[] = $tokenValue;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only the WooCommerce tokens table name and placeholders; values are bound by prepare().
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql . ' ORDER BY token_id', $args)));
    }
    /**
     * Takes a MySQL advisory lock scoped to the customer and returns a release callback.
     * Best-effort: if the lock cannot be taken the save still proceeds.
     *
     * @param string $customerId
     *
     * @return callable():void
     */
    private static function acquireSaveLock(string $customerId): callable
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return static function (): void {
            };
        }
        $name = ModuleHelper::moduleKey('_token_save_') . $customerId;
        $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 10));
        return static function () use ($wpdb, $name): void {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        };
    }
    /**
     * Card type slug for the WC token (rendered through wc_get_credit_card_type_label).
     *
     * @param string $productId
     *
     * @return string
     */
    private static function cardType(string $productId): string
    {
        try {
            return strtolower(MethodCatalog::label(PaymentProductId::parse($productId)));
        } catch (\Throwable $e) {
            return 'card';
        }
    }
}
