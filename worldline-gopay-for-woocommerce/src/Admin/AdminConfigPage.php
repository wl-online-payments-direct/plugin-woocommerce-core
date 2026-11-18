<?php

namespace WOP\OnlinePayments\Admin;

use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\MigrationNotice;
defined('ABSPATH') || exit;
/**
 * Host page for the admin-ui SPA: renders the mount points and emits the bootstrap script. The
 * WP REST nonce is baked into every URL as a `_wpnonce` param since the SPA has no nonce support.
 */
class AdminConfigPage
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Renders the SPA host markup and the bootstrap script that instantiates it.
     */
    public static function render(): void
    {
        AdminAssets::enqueue(\true);
        $nonce = wp_create_nonce('wp_rest');
        $brand = self::getBrand();
        $baseImgUrl = self::getBaseImgUrl();
        $urls = self::getUrls($nonce);
        $webhookUrl = self::getWebhookUrl();
        $config = ['brandCode' => $brand['code'], 'baseImgUrl' => $baseImgUrl, 'brand' => $brand, 'storeId' => self::STORE_ID, 'translations' => ['default' => self::getTranslations(), 'current' => self::getTranslations()], 'pageConfiguration' => ['connection' => ['getSettingsUrl' => $urls['connection']['getSettingsUrl'], 'submitUrl' => $urls['connection']['submitUrl'], 'disconnectUrl' => '', 'webhooksUrl' => $webhookUrl], 'payments' => ['getAvailablePaymentsUrl' => $urls['payments']['getAvailablePaymentsUrl'], 'enableMethodUrl' => $urls['payments']['enableMethodUrl'], 'saveMethodConfigurationUrl' => $urls['payments']['saveMethodConfigurationUrl'], 'getMethodConfigurationUrl' => $urls['payments']['getMethodConfigurationUrl'], 'paymentMethodLogoUrl' => $baseImgUrl . '/payment_products/', 'getLanguagesUrl' => $urls['payments']['getLanguagesUrl']], 'settings' => ['getGeneralSettingsUrl' => $urls['settings']['getGeneralSettingsUrl'], 'getPaymentStatusesUrl' => $urls['settings']['getPaymentStatusesUrl'], 'saveConnectionUrl' => $urls['settings']['saveConnectionUrl'], 'savePaymentSettingsUrl' => $urls['settings']['savePaymentSettingsUrl'], 'saveLogSettingsUrl' => $urls['settings']['saveLogSettingsUrl'], 'savePayByLinkSettingsUrl' => $urls['settings']['savePayByLinkSettingsUrl'], 'saveWebhookSettingsUrl' => $urls['settings']['saveWebhookSettingsUrl'], 'webhooksUrl' => $webhookUrl, 'disconnectUrl' => $urls['settings']['disconnectUrl']], 'monitoring' => ['getMonitoringLogsUrl' => $urls['monitoring']['getMonitoringLogsUrl'], 'getWebhookLogsUrl' => $urls['monitoring']['getWebhookLogsUrl'], 'downloadMonitoringLogsUrl' => $urls['monitoring']['downloadMonitoringLogsUrl'], 'downloadWebhookLogsUrl' => $urls['monitoring']['downloadWebhookLogsUrl'], 'page' => 'webhooks']], 'stateConfig' => ['storesUrl' => '', 'connectionDetailsUrl' => $urls['connection']['getSettingsUrl'], 'currentStoreUrl' => '', 'stateUrl' => $urls['integration']['stateUrl'], 'versionUrl' => $urls['version']['versionUrl']]];
        $configJson = wp_json_encode($config);
        ModuleHelper::renderTemplate('admin/config-page.php', ['configJson' => $configJson, 'migrationNotice' => (new MigrationNotice())->markup()]);
    }
    /**
     * Builds the REST URL map for the SPA, appending `_wpnonce` to every URL for cookie auth.
     *
     * @param string $nonce
     *
     * @return array<string, array<string, string>>
     */
    private static function getUrls(string $nonce): array
    {
        $base = rtrim(rest_url(ModuleHelper::getRestNamespace() . '/admin'), '/');
        $storeQuery = '?storeId=' . self::STORE_ID;
        $urls = ['connection' => ['getSettingsUrl' => $base . '/connection' . $storeQuery, 'submitUrl' => $base . '/connection' . $storeQuery, 'webhooksUrl' => ''], 'integration' => ['stateUrl' => $base . '/state' . $storeQuery], 'version' => ['versionUrl' => $base . '/version'], 'payments' => ['getAvailablePaymentsUrl' => $base . '/payments' . $storeQuery, 'enableMethodUrl' => $base . '/payments/enable' . $storeQuery, 'saveMethodConfigurationUrl' => $base . '/payments/{methodId}' . $storeQuery, 'getMethodConfigurationUrl' => $base . '/payments/{methodId}' . $storeQuery, 'getLanguagesUrl' => $base . '/languages' . $storeQuery], 'settings' => ['getGeneralSettingsUrl' => $base . '/general-settings' . $storeQuery, 'getPaymentStatusesUrl' => $base . '/order-statuses' . $storeQuery, 'saveConnectionUrl' => $base . '/connection' . $storeQuery, 'savePaymentSettingsUrl' => $base . '/general-settings/payment' . $storeQuery, 'saveLogSettingsUrl' => $base . '/general-settings/log' . $storeQuery, 'savePayByLinkSettingsUrl' => $base . '/general-settings/paybylink' . $storeQuery, 'saveWebhookSettingsUrl' => $base . '/general-settings/webhooks' . $storeQuery, 'webhooksUrl' => '', 'disconnectUrl' => $base . '/disconnect' . $storeQuery], 'monitoring' => ['getMonitoringLogsUrl' => $base . '/monitoring/logs' . $storeQuery, 'getWebhookLogsUrl' => $base . '/monitoring/webhook-logs' . $storeQuery, 'downloadMonitoringLogsUrl' => $base . '/monitoring/logs/download' . $storeQuery, 'downloadWebhookLogsUrl' => $base . '/monitoring/webhook-logs/download' . $storeQuery]];
        array_walk_recursive($urls, static function (&$value) use ($nonce) {
            if (is_string($value) && $value !== '') {
                $value .= (strpos($value, '?') !== \false ? '&' : '?') . '_wpnonce=' . rawurlencode($nonce);
            }
        });
        return $urls;
    }
    /**
     * Public webhook endpoint the merchant registers in the Worldline portal; it carries no
     * `_wpnonce` because the route authenticates by verifying the webhook signature.
     *
     * @return string
     */
    private static function getWebhookUrl(): string
    {
        return rest_url(ModuleHelper::getRestNamespace() . '/webhook') . '?storeId=' . self::STORE_ID;
    }
    /**
     * Brand descriptor for the SPA; `code` doubles as the bundle's `window` namespace.
     *
     * @return array{name: string, code: string}
     */
    private static function getBrand(): array
    {
        $brand = ModuleHelper::getModuleConfig()->getBrand();
        return ['name' => $brand, 'code' => $brand];
    }
    /**
     * Base URL of the bundled SPA images.
     *
     * @return string
     */
    private static function getBaseImgUrl(): string
    {
        return ModuleHelper::getAssetUrl('images');
    }
    /**
     * Merged translations (core admin-ui lang + our integration lang) for the current locale,
     * falling back to English.
     *
     * @return array<string, mixed>
     */
    public static function getTranslations(): array
    {
        $baseDir = self::getLangDir();
        $locale = function_exists('determine_locale') ? determine_locale() : 'en';
        $lang = substr((string) $locale, 0, 2);
        $merged = self::loadLang($baseDir, $lang);
        if ($merged === null) {
            $merged = self::loadLang($baseDir, 'en');
        }
        $merged = $merged ?? [];
        $config = ModuleHelper::getModuleConfig();
        $helpLink = $config->getHelpLink();
        // The "Need help?" button (general.helpLink) leads to the Worldline contact form; the core
        // admin-ui ships it empty.
        $merged['general'] = $merged['general'] ?? [];
        $merged['general']['helpLink'] = $config->getContactLink();
        // The documentation dropdown reads the Plugin Configuration link from "<brand>.links
        // .configurePlugin"; the core ships it pointing at the generic plugins page. Point it at
        // the WooCommerce documentation page (the brand's help link).
        $brand = $config->getBrand();
        if ($brand !== '') {
            $brandData = is_array($merged[$brand] ?? null) ? $merged[$brand] : [];
            $brandLinks = is_array($brandData['links'] ?? null) ? $brandData['links'] : [];
            $brandLinks['configurePlugin'] = $helpLink;
            $brandData['links'] = $brandLinks;
            $merged[$brand] = $brandData;
        }
        return $merged;
    }
    /**
     * Merges the core and integration translation files for a language.
     *
     * @param string $baseDir
     * @param string $lang
     *
     * @return array<string, mixed>|null Null when the core file for the language is missing.
     */
    private static function loadLang(string $baseDir, string $lang): ?array
    {
        $corePath = $baseDir . $lang . '.json';
        if (!file_exists($corePath)) {
            return null;
        }
        $core = json_decode((string) file_get_contents($corePath), \true) ?: [];
        $integrationPath = $baseDir . 'integration/' . $lang . '.json';
        $integration = file_exists($integrationPath) ? json_decode((string) file_get_contents($integrationPath), \true) ?: [] : [];
        // Replace, not merge: on a key present in both files array_merge_recursive() turns the two
        // strings into an array, and the admin UI's translate() then crashes on `text.replace`.
        return array_replace_recursive($core, $integration);
    }
    /**
     * Directory holding the admin-ui translation files.
     *
     * @return string
     */
    private static function getLangDir(): string
    {
        return __DIR__ . '/../../assets/admin-ui/lang/';
    }
}
