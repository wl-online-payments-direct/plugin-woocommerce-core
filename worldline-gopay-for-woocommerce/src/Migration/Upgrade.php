<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\ProductTypes\ProductType;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Services\SessionTimeoutSetting;
use WOP\OnlinePayments\Services\Installer;
defined('ABSPATH') || exit;
/**
 * Runs the version-gated upgrades and the one-time, read-only copy from the previous (2.x) plugin.
 *
 * The copy runs only while the "migration pending" flag set on activation is present, each step
 * guarded by its own per-site flag, so it never runs again once done (also after a later 2.x
 * change or a re-activation). The flag is cleared when every step is done.
 */
class Upgrade
{
    /** 2.x copy steps, in run order. */
    private const COPY_STEPS = ['plugin_config', 'session_timeout', 'product_type', 'saved_cards', 'order_details'];
    /** Last plugin version whose upgrades completed for this site; module-prefixed at runtime. */
    private static function versionOption(): string
    {
        return ModuleHelper::moduleKey('_migration_version');
    }
    /** Per-migration completion flag prefix; module-prefixed at runtime. */
    private static function donePrefix(): string
    {
        return ModuleHelper::moduleKey('_migration_done_');
    }
    /** Short-lived guard so parallel admin requests don't run the upgrade twice. */
    private static function lock(): string
    {
        return ModuleHelper::moduleKey('_migration_running');
    }
    /** Set on activation; the 2.x copy runs while it is present. */
    private static function pendingOption(): string
    {
        return ModuleHelper::moduleKey('_migration_pending');
    }
    /** Legacy short product type => core ProductType value (legacy "none" carries no type). */
    private const PRODUCT_TYPE_MAP = ['food' => 'FoodAndDrink', 'home' => 'HomeAndGarden', 'gift' => 'GiftAndFlowers'];
    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeRun']);
    }
    /**
     * Requests the 2.x copy on the next admin request of this site. Called from the activation hook,
     * where the core is not booted yet.
     */
    public static function markPending(): void
    {
        update_option(self::pendingOption(), 1, \false);
    }
    /**
     * Runs the schema upgrade when the plugin code is newer than the migrated version, and the 2.x
     * copy while it is pending.
     */
    public function maybeRun(): void
    {
        $current = $this->currentVersion();
        $stored = get_option(self::versionOption(), null);
        if ($stored === null || $stored === \false) {
            // First run on this site (single activation, network activation or a site added later):
            // the activation hook only reaches the site it runs on, so mark the copy pending here too.
            self::markPending();
        }
        $upgrade = version_compare((string) ($stored ?: '0'), $current, '<');
        $pending = (bool) get_option(self::pendingOption());
        if (!$upgrade && !$pending) {
            return;
        }
        if (get_transient(self::lock())) {
            return;
        }
        set_transient(self::lock(), 1, 5 * \MINUTE_IN_SECONDS);
        try {
            if ($upgrade) {
                // An in-place update does not fire the activation hook, so ensure the schema exists.
                try {
                    (new Installer())->install();
                } catch (\Throwable $e) {
                    Logger::logError('Schema install failed: ' . $e->getMessage(), 'Migration');
                }
                update_option(self::versionOption(), $current, \false);
            }
            if ($pending && $this->copyFromLegacy()) {
                delete_option(self::pendingOption());
            }
        } finally {
            delete_transient(self::lock());
        }
    }
    /**
     * Runs the 2.x copy steps. Without 2.x data every step is marked done and no notice is recorded.
     *
     * @return bool Whether every step is done.
     */
    private function copyFromLegacy(): bool
    {
        $reader = new LegacyConfigReader();
        if (!$reader->hasLegacyConfig()) {
            foreach (self::COPY_STEPS as $key) {
                if (!get_option(self::donePrefix() . $key)) {
                    update_option(self::donePrefix() . $key, 1, \false);
                }
            }
            return \true;
        }
        // Configuration first, so the store is connected before the rest.
        $done = $this->step('plugin_config', static function () use ($reader): void {
            (new ConfigMigration($reader))->run();
        });
        $done = $this->step('session_timeout', [$this, 'migrateSessionTimeout']) && $done;
        $done = $this->step('product_type', [$this, 'migrateProductTypes']) && $done;
        $done = $this->step('saved_cards', [$this, 'migrateSavedCards']) && $done;
        $done = $this->step('order_details', [$this, 'enqueueOrderMigration']) && $done;
        if ($done) {
            MigrationNotice::finish($reader->version());
        }
        return $done;
    }
    /**
     * Runs a migration once, guarded by its per-site completion flag.
     *
     * @param string $key
     * @param callable $migration
     *
     * @return bool Whether the migration is complete (already done, or finished now).
     */
    private function step(string $key, callable $migration): bool
    {
        $flag = self::donePrefix() . $key;
        if (get_option($flag)) {
            return \true;
        }
        try {
            $migration();
            update_option($flag, 1, \false);
            return \true;
        } catch (\Throwable $e) {
            Logger::logError('Migration step "' . $key . '" failed: ' . $e->getMessage(), 'Migration');
            return \false;
        }
    }
    /**
     * Copies customers' 2.x saved cards and records how many were added to this plugin's cards.
     */
    private function migrateSavedCards(): void
    {
        $before = $this->ownCardTokenCount();
        (new SavedCardMigration())->run();
        $copied = $this->ownCardTokenCount() - $before;
        MigrationNotice::recordSavedCards($copied);
        if ($copied > 0) {
            MigrationNotice::recordArea(MigrationNotice::AREA_SAVED_CARDS, \true);
        }
    }
    /**
     * Number of WooCommerce card tokens on this plugin's gateways.
     *
     * @return int
     */
    private function ownCardTokenCount(): int
    {
        global $wpdb;
        $prefix = ModuleHelper::getModuleConfig()->getGatewayPrefix() . '-';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE type = %s AND gateway_id LIKE %s", 'CC', $wpdb->esc_like($prefix) . '%'));
    }
    /**
     * Starts the 2.x order migration as a task on the core queue.
     */
    private function enqueueOrderMigration(): void
    {
        if (OrderMigration::isStarted() || OrderMigration::isDone()) {
            return;
        }
        /** @var QueueService $queueService */
        $queueService = ServiceRegister::getService(QueueService::class);
        $queueService->enqueue(ModuleHelper::moduleKey('-order-migration'), new OrderMigrationTask());
        OrderMigration::markStarted();
        MigrationNotice::startOrders();
    }
    /**
     * Copies product types from the 2.x `{prefix}product_type` table (read with SELECT) to the core service.
     */
    private function migrateProductTypes(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'product_type';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is not user input.
        $rows = $wpdb->get_results("SELECT product_id, type FROM `{$table}`", \ARRAY_A);
        if (!is_array($rows)) {
            return;
        }
        $copied = 0;
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $oldType = (string) ($row['type'] ?? '');
            if ($productId <= 0 || !isset(self::PRODUCT_TYPE_MAP[$oldType])) {
                continue;
            }
            try {
                AdminAPI::get()->productTypes()->save((string) $productId, ProductType::parse(self::PRODUCT_TYPE_MAP[$oldType]));
                $copied++;
            } catch (\Throwable $e) {
                Logger::logWarning('Migration could not set product type for product ' . $productId . ': ' . $e->getMessage(), 'Migration');
            }
        }
        if ($copied > 0) {
            MigrationNotice::recordArea(MigrationNotice::AREA_PRODUCT_TYPES, \true);
        }
    }
    /**
     * Seeds the session timeout from the legacy gateway settings: `session_timeout` (a preset in hours
     * or `custom` with `session_timeout_custom_value` minutes) or, on older versions, the former
     * `automatic_cancellation_hours` (0 = disabled becomes 3 hours, more than 24 becomes 24).
     */
    private function migrateSessionTimeout(): void
    {
        $reader = new LegacyConfigReader();
        if (!$reader->hasLegacyConfig()) {
            return;
        }
        $selected = (string) $reader->main('session_timeout');
        if ($selected === SessionTimeoutSetting::CUSTOM) {
            SessionTimeoutSetting::set(SessionTimeoutSetting::CUSTOM, $reader->main('session_timeout_custom_value', SessionTimeoutSetting::DEFAULT_CUSTOM_MINUTES));
            MigrationNotice::recordArea(MigrationNotice::AREA_SESSION_TIMEOUT, \true);
            return;
        }
        if ($selected === '') {
            $selected = (string) $reader->main('automatic_cancellation_hours');
        }
        [$preset, $customMinutes] = SessionTimeoutSetting::fromHours($selected === '' ? 0 : (int) $selected);
        SessionTimeoutSetting::set($preset, $customMinutes);
        MigrationNotice::recordArea(MigrationNotice::AREA_SESSION_TIMEOUT, \true);
    }
    /**
     * Current plugin version; '0' when it cannot be read so the upgrade is not skipped.
     *
     * @return string
     */
    private function currentVersion(): string
    {
        try {
            $version = ModuleHelper::getModuleConfig()->getVersion();
        } catch (\Throwable $e) {
            return '0';
        }
        return $version !== '' ? $version : '0';
    }
}
