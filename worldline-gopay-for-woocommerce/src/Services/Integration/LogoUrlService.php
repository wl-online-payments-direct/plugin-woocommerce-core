<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Logo\LogoUrlService as CoreLogoUrlService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
defined('ABSPATH') || exit;
/**
 * Resolves payment-product logos to the plugin's bundled assets.
 */
class LogoUrlService implements CoreLogoUrlService
{
    private const IMAGE_BASE_PATH = 'assets/images/payment_products/';
    /**
     * @inheritDoc
     *
     * @return string
     */
    public function getHostedCheckoutLogoUrl(): string
    {
        return $this->getLogoUrl((string) PaymentProductId::hostedCheckout());
    }
    /**
     * @inheritDoc
     *
     * @param string $productId
     *
     * @return string
     */
    public function getLogoUrl(string $productId): string
    {
        return $this->getBaseImageUrl() . $productId . '.svg';
    }
    /**
     * Logo URL only when the SVG is actually bundled; '' otherwise.
     *
     * @param string $productId
     *
     * @return string
     */
    public function getLogoUrlIfExists(string $productId): string
    {
        if (defined('WOP_ONLINE_PAYMENTS_PLUGIN_FILE') && function_exists('plugin_dir_path')) {
            $path = plugin_dir_path(\WOP_ONLINE_PAYMENTS_PLUGIN_FILE) . self::IMAGE_BASE_PATH . $productId . '.svg';
            if (!is_file($path)) {
                return '';
            }
        }
        return $this->getLogoUrl($productId);
    }
    /**
     * Returns the public URL of the bundled payment-product image directory.
     *
     * @return string
     */
    private function getBaseImageUrl(): string
    {
        if (defined('WOP_ONLINE_PAYMENTS_PLUGIN_FILE') && function_exists('plugins_url')) {
            return plugins_url(self::IMAGE_BASE_PATH, \WOP_ONLINE_PAYMENTS_PLUGIN_FILE);
        }
        return self::IMAGE_BASE_PATH;
    }
}
