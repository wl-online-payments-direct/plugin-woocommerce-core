<?php

namespace WOP;

/**
 * Plugin Name: Worldline GoPay for WooCommerce
 * Plugin URI: https://docs.direct.worldline-solutions.com/en/integration/how-to-integrate/plugins/woocommerce
 * Description: This module offers a 1-click integration to start accepting payments and grow your revenues by offering your customers with global and regional payment methods to sell across Europe.
 * Version: 3.0.0
 * Author: Worldline GoPay
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 6.3
 * Requires Plugins: woocommerce
 * Tested up to: 7.1
 * WC requires at least: 8.6
 * WC tested up to: 11.2
 * Text Domain: worldline-gopay-for-woocommerce
 *
 * @package OnlinePayments
 */
use WOP\OnlinePayments\Admin\AdminRestRouter;
use WOP\OnlinePayments\Controllers\AsyncProcessController;
use WOP\OnlinePayments\Controllers\CardSessionController;
use WOP\OnlinePayments\Controllers\PaymentStatusController;
use WOP\OnlinePayments\Controllers\WebhookController;
use WOP\OnlinePayments\Gateway\GatewayModule;
use WOP\OnlinePayments\Migration\Upgrade;
use WOP\OnlinePayments\Services\BootstrapComponent;
use WOP\OnlinePayments\Services\Installer;
\defined('ABSPATH') || exit;
\define('WOP_ONLINE_PAYMENTS_PLUGIN_FILE', __FILE__);
\define('WOP_ONLINE_PAYMENTS_PLUGIN_DIR', \plugin_dir_path(__FILE__));
\define('WOP_ONLINE_PAYMENTS_CONFIG_FILE', \WOP_ONLINE_PAYMENTS_PLUGIN_DIR . 'config.json');
\define('WOP_ONLINE_PAYMENTS_BRAND_FILE', \WOP_ONLINE_PAYMENTS_PLUGIN_DIR . 'brand.json');
$autoload = \WOP_ONLINE_PAYMENTS_PLUGIN_DIR . 'vendor/autoload.php';
if (\file_exists($autoload)) {
    require_once $autoload;
}
/**
 * Whether WooCommerce is installed and active. This plugin is a WooCommerce payment gateway, so it
 * must neither activate nor bootstrap without it.
 *
 * @return bool
 */
$online_payments_wc_active = static function (): bool {
    if (\class_exists('WooCommerce')) {
        return \true;
    }
    $active = (array) \get_option('active_plugins', []);
    if (\is_multisite()) {
        $active = \array_merge($active, \array_keys((array) \get_site_option('active_sitewide_plugins', [])));
    }
    return \in_array('woocommerce/woocommerce.php', $active, \true);
};
\add_action('before_woocommerce_init', static function (): void {
    if (!\class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', \WOP_ONLINE_PAYMENTS_PLUGIN_FILE, \true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', \WOP_ONLINE_PAYMENTS_PLUGIN_FILE, \true);
});
/**
 * Registers the integration services and entity repositories into the core.
 */
function worldlineop_boot(): void
{
    static $booted = \false;
    if ($booted || !\class_exists(BootstrapComponent::class)) {
        return;
    }
    BootstrapComponent::boot(\WOP_ONLINE_PAYMENTS_CONFIG_FILE, \WOP_ONLINE_PAYMENTS_BRAND_FILE);
    $booted = \true;
}
\add_action('plugins_loaded', static function () use ($online_payments_wc_active): void {
    if (!$online_payments_wc_active()) {
        return;
    }
    worldlineop_boot();
}, 11);
// Creates the plugin tables and requests the one-time copy from the previous plugin, on the current
// site. A closure, not a function: both brands can be active at once.
$online_payments_activate_site = static function (): void {
    if (\class_exists(Installer::class)) {
        (new Installer())->install();
    }
    // The one-time copy from the previous plugin runs on the next admin request.
    if (\class_exists(Upgrade::class)) {
        Upgrade::markPending();
    }
};
\register_activation_hook(__FILE__, static function ($networkWide = \false) use ($online_payments_wc_active, $online_payments_activate_site): void {
    if (!$online_payments_wc_active()) {
        deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die(\esc_html('This plugin requires WooCommerce to be installed and active.'), \esc_html('Plugin dependency check'), ['back_link' => \true]);
    }
    // A network activation runs this hook once, on the main site. Every site has its own tables,
    // and the plugin runs on every site's front end at once, so each site is set up here; the copy
    // from the previous plugin still runs on each site's next admin request.
    if ($networkWide && \is_multisite()) {
        foreach (\get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                $online_payments_activate_site();
            } finally {
                restore_current_blog();
            }
        }
        return;
    }
    $online_payments_activate_site();
});
// A site added to a network where the plugin is network-active gets its tables at once.
\add_action('wp_initialize_site', static function ($site) use ($online_payments_activate_site): void {
    if (!$site instanceof \WP_Site) {
        return;
    }
    if (!\function_exists('is_plugin_active_for_network') && !\function_exists('WOP\is_plugin_active_for_network')) {
        require_once \ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!\is_plugin_active_for_network(\plugin_basename(__FILE__))) {
        return;
    }
    // Set up the plugin on the new site (WooCommerce may not have created its tables there yet).
    switch_to_blog((int) $site->blog_id);
    try {
        $online_payments_activate_site();
    } finally {
        restore_current_blog();
    }
}, 200);
// Deleting a site of the network drops its tables; WordPress only knows its own, so add this plugin's
// (called while switched to the deleted site).
\add_filter('wpmu_drop_tables', static function ($tables) {
    if (!\is_array($tables) || !\class_exists(Installer::class)) {
        return $tables;
    }
    return \array_merge($tables, (new Installer())->tableNames());
});
\add_action('rest_api_init', static function (): void {
    if (\class_exists(AsyncProcessController::class)) {
        (new AsyncProcessController())->registerRoutes();
    }
    if (\class_exists(AdminRestRouter::class)) {
        (new AdminRestRouter())->registerRoutes();
    }
    if (\class_exists(WebhookController::class)) {
        (new WebhookController())->registerRoutes();
    }
    if (\class_exists(PaymentStatusController::class)) {
        (new PaymentStatusController())->registerRoutes();
    }
    if (\class_exists(CardSessionController::class)) {
        (new CardSessionController())->registerRoutes();
    }
});
// Webhooks of migrated 2.x payments arrive on the previous plugin's route: while it is active its
// handler is wrapped, otherwise the route is registered here, after every other plugin.
\add_action('rest_api_init', static function ($server): void {
    if (\class_exists(WebhookController::class)) {
        (new WebhookController())->registerLegacyRouteIfFree($server);
    }
}, \PHP_INT_MAX);
\add_filter('rest_endpoints', static function ($endpoints) {
    return \class_exists(WebhookController::class) ? (new WebhookController())->wrapLegacyRoute($endpoints) : $endpoints;
});
\add_action('plugins_loaded', static function () use ($online_payments_wc_active): void {
    if (!$online_payments_wc_active()) {
        return;
    }
    if (\class_exists(GatewayModule::class)) {
        (new GatewayModule())->register();
    }
    if (\class_exists(Upgrade::class)) {
        (new Upgrade())->register();
    }
}, 12);
