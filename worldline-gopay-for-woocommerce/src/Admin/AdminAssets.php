<?php

namespace WOP\OnlinePayments\Admin;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Enqueues the admin-ui SPA assets on the plugin's settings screen.
 */
class AdminAssets
{
    /** Brand-prefixed asset handle. */
    private static function handle(): string
    {
        return ModuleHelper::moduleKey('-admin-ui');
    }
    /**
     * Enqueues the SPA script and styles; `$force` skips the settings-screen guard.
     *
     * @param bool $force
     */
    public static function enqueue(bool $force = \false): void
    {
        if (!$force && !self::isSettingsScreen()) {
            return;
        }
        $version = ModuleHelper::getModuleConfig()->getVersion();
        wp_enqueue_script(self::handle(), ModuleHelper::getAssetUrl('admin-ui/js/index.js'), ['jquery'], $version, \true);
        wp_enqueue_style(self::handle(), ModuleHelper::getAssetUrl('admin-ui/css/index.css'), [], $version);
        wp_enqueue_style(self::handle() . '-admin', ModuleHelper::getAssetUrl('admin-ui/css/op-admin.css'), [self::handle()], $version);
    }
    /**
     * Whether the current admin request renders the plugin's WooCommerce settings section.
     *
     * @return bool
     */
    private static function isSettingsScreen(): bool
    {
        if (!is_admin()) {
            return \false;
        }
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
        return $page === 'wc-settings' && $tab === 'checkout' && $section === ModuleHelper::getMainGatewayId();
    }
}
