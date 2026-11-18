<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Per-payment-method "soft descriptor" (order.references.descriptor), capped at 15 characters.
 * Configured on the Sofinco and Linxo method settings; empty leaves the descriptor unset.
 */
class SoftDescriptorSetting
{
    /** Maximum length Worldline accepts for the descriptor. */
    private const MAX_LENGTH = 15;
    public static function forProduct(string $productId): string
    {
        if ($productId === PaymentProductId::WERO) {
            return trim(mb_substr(trim(ModuleHelper::storeName()), 0, self::MAX_LENGTH));
        }
        return self::get($productId);
    }
    /**
     * Configured descriptor for a payment product, or an empty string when unset.
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
     * Stores the descriptor for a payment product, stripped of tags and truncated to the max length.
     *
     * @param string $productId
     * @param string $descriptor
     */
    public static function set(string $productId, string $descriptor): void
    {
        update_option(self::optionKey($productId), substr(wp_strip_all_tags($descriptor), 0, self::MAX_LENGTH), \false);
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
        return ModuleHelper::getModuleConfig()->getModuleName() . '_soft_descriptor_' . $productId;
    }
}
