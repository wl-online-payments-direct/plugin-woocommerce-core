<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\Bootstrap\Configuration\Configuration;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * WooCommerce implementation of the core Configuration.
 */
class ConfigService extends Configuration
{
    public const ASYNC_CALL_METHOD = 'GET';
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getIntegrationVersion(): string
    {
        if (defined('WC_VERSION')) {
            return (string) \WC_VERSION;
        }
        if (function_exists('get_bloginfo')) {
            return (string) get_bloginfo('version');
        }
        return '';
    }
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getIntegrationName(): string
    {
        return 'WooCommerce';
    }
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getPluginVersion(): string
    {
        return ModuleHelper::getModuleConfig()->getVersion();
    }
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getPluginName(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName();
    }
    /**
     * @inheritDoc
     *
     * @param string $guid
     *
     * @return string
     */
    public function getAsyncProcessUrl(string $guid): string
    {
        $url = rest_url(ModuleHelper::getRestNamespace() . '/async-process');
        $separator = strpos($url, '?') === \false ? '?' : '&';
        return $url . $separator . 'guid=' . rawurlencode($guid);
    }
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getWebhookUrl(): string
    {
        return rest_url(ModuleHelper::getRestNamespace() . '/webhook') . '?storeId=1';
    }
}
