<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Fixed identifiers of the previous (2.x) plugin of the same brand. They are historical values and
 * can not be derived from this plugin's own configuration, which uses its own prefix.
 */
class LegacySource
{
    /** Per brand: gateway prefix, version option, webhook REST namespace, plugin basename. */
    private const BRANDS = ['WOP' => ['prefix' => 'worldline', 'versionOption' => 'worldline_plugin_version', 'webhookNamespace' => 'inpsyde/worldline-for-woocommerce', 'pluginBasename' => 'worldline-for-woocommerce/worldline-for-woocommerce.php'], 'CAWL' => ['prefix' => 'cawl', 'versionOption' => 'cawl_plugin_version', 'webhookNamespace' => 'inpsyde/woocommerce-for-cawl', 'pluginBasename' => 'cawl/cawl.php']];
    /** Route of the 2.x webhook listener inside its namespace. */
    public const WEBHOOK_ROUTE = '/listener/notifications';
    /**
     * 2.x gateway prefix, e.g. "worldline".
     *
     * @return string
     */
    public static function gatewayPrefix(): string
    {
        return self::value('prefix');
    }
    /**
     * 2.x main (Hosted Checkout) gateway id.
     *
     * @return string
     */
    public static function mainGatewayId(): string
    {
        return self::gatewayPrefix() . '-for-woocommerce';
    }
    /**
     * 2.x gateway ids that store saved cards.
     *
     * @return string[]
     */
    public static function cardGatewayIds(): array
    {
        return [self::mainGatewayId(), self::gatewayPrefix() . '-hosted-tokenization'];
    }
    /**
     * Whether a WooCommerce gateway id belongs to the 2.x plugin. This plugin's own ids can start
     * with the 2.x prefix too (the WOP prefix extends the 2.x one), so they are excluded explicitly.
     *
     * @param string $gatewayId
     *
     * @return bool
     */
    public static function isLegacyGateway(string $gatewayId): bool
    {
        $prefix = self::gatewayPrefix() . '-';
        return strncmp($gatewayId, $prefix, strlen($prefix)) === 0 && !ModuleHelper::ownsGateway($gatewayId);
    }
    /**
     * Option the 2.x plugin stores its version in.
     *
     * @return string
     */
    public static function versionOption(): string
    {
        return self::value('versionOption');
    }
    /**
     * REST namespace of the 2.x webhook listener.
     *
     * @return string
     */
    public static function webhookNamespace(): string
    {
        return self::value('webhookNamespace');
    }
    /**
     * Full REST route of the 2.x webhook listener, as WordPress keys it ("/{namespace}{route}").
     *
     * @return string
     */
    public static function webhookRoute(): string
    {
        return '/' . self::webhookNamespace() . self::WEBHOOK_ROUTE;
    }
    /**
     * 2.x plugin basename ("folder/main-file.php").
     *
     * @return string
     */
    public static function pluginBasename(): string
    {
        return self::value('pluginBasename');
    }
    /**
     * Whether the 2.x plugin is active on the current site (also when network-activated).
     *
     * @return bool
     */
    public static function isPluginActive(): bool
    {
        $basename = self::pluginBasename();
        if (in_array($basename, (array) get_option('active_plugins', []), \true)) {
            return \true;
        }
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            return isset($network[$basename]);
        }
        return \false;
    }
    private static function value(string $key): string
    {
        $brand = ModuleHelper::getModuleConfig()->getBrand();
        return self::BRANDS[$brand][$key] ?? self::BRANDS['WOP'][$key];
    }
}
