<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Per-payment-method checkout order-button text (WooCommerce's `order_button_text`), applied when
 * that method is selected. Configured on each method's settings; empty uses the WooCommerce default.
 */
class PaymentButtonTitleSetting
{
    /**
     * Configured button title for a payment product, or an empty string when unset.
     *
     * @param string $productId
     *
     * @return string
     */
    public static function get(string $productId): string
    {
        return (string) get_option(self::optionKey($productId), '');
    }
    /**
     * Stores the button title for a payment product, stripped of tags.
     *
     * @param string $productId
     * @param string $title
     */
    public static function set(string $productId, string $title): void
    {
        update_option(self::optionKey($productId), wp_strip_all_tags($title), \false);
    }
    /**
     * Product-scoped, module-name-namespaced option key.
     *
     * @param string $productId
     *
     * @return string
     */
    private static function optionKey(string $productId): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_payment_button_title_' . $productId;
    }
}
