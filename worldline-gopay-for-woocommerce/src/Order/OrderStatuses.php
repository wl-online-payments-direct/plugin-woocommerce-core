<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\GeneralSettings\GeneralSettingsService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
defined('ABSPATH') || exit;
/**
 * Registers the custom "Partially refunded" WooCommerce order status (WooCommerce has none native)
 * and resolves the merchant-configured Worldline -> WooCommerce status mapping, so the rest of the
 * plugin never hardcodes a status the merchant may have remapped in the settings.
 */
class OrderStatuses
{
    public const PARTIALLY_REFUNDED = 'wc-partial-refunded';
    /** Single store. */
    private const STORE_ID = '1';
    /** Per-request cache of the configured statuses keyed by mapping slot. */
    private static ?array $configuredCache = null;
    /** Configured WC status for a not-yet-paid (created/pending) Worldline payment. */
    public static function pending(): string
    {
        return self::configured('pending', 'pending');
    }
    /** Configured WC status for an authorized (awaiting capture) Worldline payment. */
    public static function authorized(): string
    {
        return self::configured('authorized', 'on-hold');
    }
    /** Configured WC status for a captured/completed Worldline payment. */
    public static function captured(): string
    {
        return self::configured('captured', 'processing');
    }
    /** Configured WC status for a cancelled Worldline payment. */
    public static function cancelled(): string
    {
        return self::configured('cancelled', 'cancelled');
    }
    /** Configured WC status for a failed/error Worldline payment. */
    public static function error(): string
    {
        return self::configured('error', 'failed');
    }
    /**
     * @param string $slot
     * @param string $fallback
     *
     * @return string
     */
    private static function configured(string $slot, string $fallback): string
    {
        if (self::$configuredCache === null) {
            self::$configuredCache = self::loadConfigured();
        }
        return self::$configuredCache[$slot] ?? $fallback;
    }
    /**
     * Reads the merchant-configured mapping (the core falls back to defaults), normalising the
     * stored "wc-"-prefixed slugs to the bare slugs WooCommerce uses for status checks/writes.
     *
     * @return array<string, string>
     */
    private static function loadConfigured(): array
    {
        try {
            return StoreContext::doWithStore(self::STORE_ID, static function (): array {
                $settings = ServiceRegister::getService(GeneralSettingsService::class)->getPaymentSettings();
                return ['pending' => self::strip($settings->getPaymentPendingStatus()), 'authorized' => self::strip($settings->getPaymentAuthorizedStatus()), 'captured' => self::strip($settings->getPaymentCapturedStatus()), 'cancelled' => self::strip($settings->getPaymentCancelledStatus()), 'error' => self::strip($settings->getPaymentErrorStatus())];
            });
        } catch (\Throwable $e) {
            return [];
        }
    }
    /** The mapping stores "wc-"-prefixed slugs; WooCommerce status APIs use the bare slug. */
    private static function strip(string $status): string
    {
        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
    }
    public function register(): void
    {
        add_action('init', [$this, 'registerPostStatus']);
        add_filter('wc_order_statuses', [$this, 'addToOrderStatuses']);
    }
    public function registerPostStatus(): void
    {
        $label = __('Partially refunded', 'worldline-gopay-for-woocommerce');
        register_post_status(self::PARTIALLY_REFUNDED, [
            'label' => $label,
            'public' => \true,
            'exclude_from_search' => \false,
            'show_in_admin_all_list' => \true,
            'show_in_admin_status_list' => \true,
            /* translators: %s: number of orders. */
            'label_count' => _n_noop('Partially refunded <span class="count">(%s)</span>', 'Partially refunded <span class="count">(%s)</span>', 'worldline-gopay-for-woocommerce'),
        ]);
    }
    /**
     * Inserts the custom status into the WooCommerce status list, after "Refunded".
     *
     * @param array<string, string> $statuses
     *
     * @return array<string, string>
     */
    public function addToOrderStatuses(array $statuses): array
    {
        if (isset($statuses[self::PARTIALLY_REFUNDED])) {
            return $statuses;
        }
        $ordered = [];
        foreach ($statuses as $key => $label) {
            $ordered[$key] = $label;
            if ($key === 'wc-refunded') {
                $ordered[self::PARTIALLY_REFUNDED] = __('Partially refunded', 'worldline-gopay-for-woocommerce');
            }
        }
        if (!isset($ordered[self::PARTIALLY_REFUNDED])) {
            $ordered[self::PARTIALLY_REFUNDED] = __('Partially refunded', 'worldline-gopay-for-woocommerce');
        }
        return $ordered;
    }
}
