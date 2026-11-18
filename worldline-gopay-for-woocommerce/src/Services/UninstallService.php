<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\LegacySource;
defined('ABSPATH') || exit;
/**
 * Full uninstall: drops the plugin tables and deletes the plugin options and saved-card rows. Data
 * of the 2.x plugin of the same brand (options, gateway settings, saved cards) is left untouched.
 */
class UninstallService
{
    private Installer $installer;
    /**
     * @param Installer|null $installer
     */
    public function __construct(?Installer $installer = null)
    {
        $this->installer = $installer ?? new Installer();
    }
    /**
     * Drops the plugin tables and deletes the plugin options.
     *
     * On a multisite network the plugin can only be deleted network-wide, and uninstall runs once,
     * on the main site. Every site of the network has its own tables and options, so each one is
     * cleaned in turn.
     */
    public function uninstall(): void
    {
        // User meta is shared by all sites: the per-user dismissal of the migration notice.
        delete_metadata('user', 0, ModuleHelper::moduleKey('_migration_notice_dismissed'), '', \true);
        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->uninstallSite();
            return;
        }
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                $this->uninstallSite();
            } finally {
                restore_current_blog();
            }
        }
    }
    /**
     * Drops the plugin tables and deletes the plugin options and saved-card rows of the current site.
     */
    private function uninstallSite(): void
    {
        $this->installer->remove();
        $this->deletePluginOptions();
        $this->deletePaymentTokens();
    }
    /**
     * Deletes options namespaced with the module name (including the migration markers, so a
     * reinstall re-runs the migration) and the WC gateway settings.
     */
    private function deletePluginOptions(): void
    {
        global $wpdb;
        $moduleName = ModuleHelper::getModuleConfig()->getModuleName();
        $gatewayPrefix = ModuleHelper::getModuleConfig()->getGatewayPrefix();
        // Transients are stored as options too (without a persistent object cache):
        // _transient_{module}_* and their _transient_timeout_{module}_* rows.
        $prefixes = [$moduleName, 'woocommerce_' . $moduleName, 'woocommerce_' . $gatewayPrefix . '-', '_transient_' . $moduleName, '_transient_timeout_' . $moduleName];
        $legacyNames = [LegacySource::versionOption()];
        foreach (self::legacyGatewayIds() as $legacyGatewayId) {
            $legacyNames[] = 'woocommerce_' . $legacyGatewayId . '_settings';
        }
        foreach ($prefixes as $prefix) {
            if (!self::isSafePrefix($prefix, $legacyNames)) {
                continue;
            }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
        }
    }
    /**
     * Deletes this plugin's WC payment token rows and their meta with direct SQL, so that no
     * woocommerce_payment_token_deleted hook fires and no token is deleted at Worldline. The 2.x
     * plugin's rows for the same cards stay.
     */
    private function deletePaymentTokens(): void
    {
        global $wpdb;
        $prefix = ModuleHelper::getModuleConfig()->getGatewayPrefix() . '-';
        if (!self::isSafePrefix($prefix, self::legacyGatewayIds())) {
            return;
        }
        $like = $wpdb->esc_like($prefix) . '%';
        $tokens = $wpdb->prefix . 'woocommerce_payment_tokens';
        $meta = $wpdb->prefix . 'woocommerce_payment_tokenmeta';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WooCommerce token table names built from $wpdb->prefix; values are bound by prepare().
        $wpdb->query($wpdb->prepare("DELETE m FROM {$meta} m INNER JOIN {$tokens} t ON t.token_id = m.payment_token_id" . " WHERE t.gateway_id LIKE %s", $like));
        $wpdb->query($wpdb->prepare("DELETE FROM {$tokens} WHERE gateway_id LIKE %s", $like));
        // phpcs:enable
    }
    /**
     * 2.x gateway ids known to this plugin.
     *
     * @return string[]
     */
    private static function legacyGatewayIds(): array
    {
        return array_values(array_unique(array_merge([LegacySource::mainGatewayId()], LegacySource::cardGatewayIds())));
    }
    /**
     * Whether a deletion prefix is non-empty and matches none of the given 2.x names. An unsafe
     * prefix is skipped and logged.
     *
     * @param string $prefix
     * @param string[] $legacyNames
     *
     * @return bool
     */
    private static function isSafePrefix(string $prefix, array $legacyNames): bool
    {
        $unsafe = trim($prefix, '_-') === '';
        foreach ($legacyNames as $legacyName) {
            if (strncmp($legacyName, $prefix, strlen($prefix)) === 0) {
                $unsafe = \true;
                break;
            }
        }
        if ($unsafe) {
            try {
                Logger::logWarning('Uninstall skipped the prefix "' . $prefix . '": it matches 2.x plugin data.', 'Uninstall');
            } catch (\Throwable $e) {
                // The core logger is not available during uninstall; the skip itself is what matters.
            }
        }
        return !$unsafe;
    }
}
