<?php

namespace WOP\OnlinePayments\Helpers;

use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Services\ModuleConfig;
defined('ABSPATH') || exit;
/**
 * Module configuration, table-name resolution and REST routing helpers.
 */
class ModuleHelper
{
    private static ?ModuleConfig $fallbackConfig = null;
    /**
     * Returns the module configuration, falling back to config.json when the core
     * ServiceRegister is not yet available (e.g. during activation/uninstall).
     *
     * @return ModuleConfig
     */
    public static function getModuleConfig(): ModuleConfig
    {
        if (class_exists(ServiceRegister::class)) {
            try {
                $config = ServiceRegister::getService(ModuleConfig::class);
                if ($config instanceof ModuleConfig) {
                    return $config;
                }
            } catch (\Throwable $e) {
            }
        }
        if (self::$fallbackConfig === null) {
            $path = defined('WOP_ONLINE_PAYMENTS_CONFIG_FILE') ? \WOP_ONLINE_PAYMENTS_CONFIG_FILE : __DIR__ . '/../../config.json';
            self::$fallbackConfig = new ModuleConfig($path);
        }
        return self::$fallbackConfig;
    }
    public static function storeName(): string
    {
        return html_entity_decode((string) get_bloginfo('name'), \ENT_QUOTES, 'UTF-8');
    }
    /**
     * Module-name-prefixed runtime key for WP-global identifiers (cron hooks, AJAX actions, nonces, etc.).
     *
     * @param string $suffix Including its own separator, e.g. "_status_fallback" or "-actions".
     *
     * @return string
     */
    public static function moduleKey(string $suffix): string
    {
        return self::getModuleConfig()->getModuleName() . $suffix;
    }
    /**
     * Gateway-prefix-prefixed runtime key for identifiers (migration markers, asset handles).
     *
     * @param string $suffix Including its own separator, e.g. "_migration_running".
     *
     * @return string
     */
    public static function gatewayKey(string $suffix): string
    {
        return self::getModuleConfig()->getGatewayPrefix() . $suffix;
    }
    /**
     * Builds a fully-qualified table name: {wpdb prefix}{lower brand}_{short name}.
     *
     * @param string $shortName
     *
     * @return string
     */
    public static function getFullTableName(string $shortName): string
    {
        global $wpdb;
        $prefix = isset($wpdb) ? $wpdb->prefix : '';
        return $prefix . strtolower(self::getModuleConfig()->getBrand()) . '_' . $shortName;
    }
    /**
     * @return string
     */
    public static function getRestNamespace(): string
    {
        return self::getModuleConfig()->getModuleName() . '/v1';
    }
    /**
     * Returns the id of the main (Hosted Checkout) gateway.
     *
     * @return string
     */
    public static function getMainGatewayId(): string
    {
        return self::getModuleConfig()->getGatewayPrefix() . '-for-woocommerce';
    }
    /**
     * Whether a WooCommerce gateway id belongs to this plugin.
     *
     * @param string $gatewayId
     *
     * @return bool
     */
    public static function ownsGateway(string $gatewayId): bool
    {
        $prefix = self::getModuleConfig()->getGatewayPrefix() . '-';
        return strncmp($gatewayId, $prefix, strlen($prefix)) === 0;
    }
    /**
     * Returns the public plugin URL for a file located under the plugin's assets/ directory.
     *
     * @param string $relativePath Path relative to assets/ (e.g. "admin-ui/js/index.js").
     *
     * @return string
     */
    public static function getAssetUrl(string $relativePath): string
    {
        $pluginFile = self::pluginFile();
        $baseUrl = rtrim(plugin_dir_url($pluginFile), '/');
        return $baseUrl . '/assets/' . ltrim($relativePath, '/');
    }
    /**
     * Renders a PHP template from the plugin's templates/ directory, exposing $vars as locals.
     *
     * @param string $relativePath Path relative to templates/ (e.g. "checkout/surcharge-notice.php").
     * @param array<string, mixed> $vars
     *
     * @return void
     */
    public static function renderTemplate(string $relativePath, array $vars = []): void
    {
        $pluginFile = self::pluginFile();
        $template = plugin_dir_path($pluginFile) . 'templates/' . ltrim($relativePath, '/');
        if (!is_readable($template)) {
            return;
        }
        extract($vars, \EXTR_SKIP);
        include $template;
    }
    /**
     * The plugin main file. Falls back to "{folder}/{folder}.php" (the main file is named after
     * the plugin folder in every build) when the bootstrap constant is not defined.
     *
     * @return string
     */
    private static function pluginFile(): string
    {
        if (defined('WOP_ONLINE_PAYMENTS_PLUGIN_FILE')) {
            return \WOP_ONLINE_PAYMENTS_PLUGIN_FILE;
        }
        $pluginDir = dirname(__DIR__, 2);
        return $pluginDir . '/' . basename($pluginDir) . '.php';
    }
}
