<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Plugin-level "Enable Saved Cards" switch. Controls whether customers are offered to save their
 * card (Hosted Checkout, Cards redirect and Cards iframe) and whether saved cards can be used at
 * checkout. Enabled unless explicitly turned off.
 */
class SavedCardsSetting
{
    private const ENABLED = 'yes';
    private const DISABLED = 'no';
    /**
     * Whether saved cards are enabled; a missing or unexpected value counts as enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (string) get_option(self::optionKey(), self::ENABLED) !== self::DISABLED;
    }
    /**
     * Stores the switch.
     *
     * @param bool $enabled
     */
    public static function set(bool $enabled): void
    {
        update_option(self::optionKey(), $enabled ? self::ENABLED : self::DISABLED, \false);
    }
    /**
     * Whether the payer of this cart may be offered to save their card: never for guests, and
     * only while the switch is enabled.
     *
     * @param Cart $cart
     *
     * @return bool
     */
    public static function allowsSaving(Cart $cart): bool
    {
        return !$cart->getCustomer()->isGuest() && self::isEnabled();
    }
    /**
     * Module-name-namespaced option key.
     *
     * @return string
     */
    private static function optionKey(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_saved_cards_enabled';
    }
}
