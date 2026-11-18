<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueItem;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\QueueService;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Summary of the one-time copy from the previous (2.x) plugin and the dismissible notice that reports
 * it. The summary is one JSON option written while the migration runs; the notice shows once the copy
 * steps are finished, inside this plugin's admin UI above every tab (rendered into the admin page
 * template by AdminConfigPage), and is dismissed per user.
 */
class MigrationNotice
{
    /** Areas the summary can name, in display order. */
    public const AREA_CONNECTION = 'connection';
    public const AREA_GENERAL_SETTINGS = 'general_settings';
    public const AREA_WEBHOOK_SETTINGS = 'webhook_settings';
    public const AREA_HOSTED_CHECKOUT = 'hosted_checkout';
    public const AREA_CARDS = 'credit_cards';
    public const AREA_REDIRECT_METHODS = 'redirect_methods';
    public const AREA_SOFT_DESCRIPTOR = 'soft_descriptor';
    public const AREA_SAVED_CARDS_SETTING = 'saved_cards_setting';
    public const AREA_SESSION_TIMEOUT = 'session_timeout';
    public const AREA_PRODUCT_TYPES = 'product_types';
    public const AREA_SAVED_CARDS = 'saved_cards';
    /** Summary option (JSON). */
    private static function option(): string
    {
        return ModuleHelper::moduleKey('_migration_notice');
    }
    /** User meta flag set when a user dismisses the notice. */
    private static function dismissedMeta(): string
    {
        return ModuleHelper::moduleKey('_migration_notice_dismissed');
    }
    /** Query argument and nonce action of the dismiss link. */
    private static function dismissAction(): string
    {
        return ModuleHelper::moduleKey('_dismiss_migration_notice');
    }
    public function register(): void
    {
        add_action('admin_init', [$this, 'handleDismiss']);
    }
    /**
     * Records the outcome of one migrated area. A later success clears an earlier failure of the
     * same area (a failed step is retried).
     *
     * @param string $area One of the AREA_* constants.
     * @param bool $success
     */
    public static function recordArea(string $area, bool $success): void
    {
        $summary = self::summary();
        $copied = array_values(array_diff($summary['copied'], [$area]));
        $failed = array_values(array_diff($summary['failed'], [$area]));
        if ($success) {
            $copied[] = $area;
        } else {
            $failed[] = $area;
        }
        $summary['copied'] = $copied;
        $summary['failed'] = $failed;
        self::save($summary);
    }
    /**
     * Records how many saved cards were copied.
     *
     * @param int $count
     */
    public static function recordSavedCards(int $count): void
    {
        $summary = self::summary();
        $summary['savedCards'] = max(0, $count);
        self::save($summary);
    }
    /** Records that the order migration was started in the background. */
    public static function startOrders(): void
    {
        $summary = self::summary();
        $summary['ordersStarted'] = \true;
        self::save($summary);
    }
    /**
     * Records that the order migration finished and how many orders it migrated.
     *
     * @param int $orders Number of migrated orders.
     */
    public static function finishOrders(int $orders): void
    {
        $summary = self::summary();
        $summary['orders'] = max(0, $orders);
        $summary['ordersDone'] = \true;
        self::save($summary);
    }
    /**
     * Marks the copy steps as finished, which makes the notice visible. Only the first call counts.
     *
     * @param string $sourceVersion The 2.x version the data was copied from.
     */
    public static function finish(string $sourceVersion): void
    {
        $summary = self::summary();
        if ($summary['finishedAt'] > 0) {
            return;
        }
        $summary['source'] = $sourceVersion;
        $summary['finishedAt'] = time();
        self::save($summary);
    }
    /**
     * The stored summary, with defaults for missing fields.
     *
     * @return array{source: string, finishedAt: int, copied: string[], failed: string[], savedCards: int, orders: int, ordersStarted: bool, ordersDone: bool}
     */
    public static function summary(): array
    {
        $raw = get_option(self::option(), '');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, \true) : [];
        $data = is_array($data) ? $data : [];
        return ['source' => (string) ($data['source'] ?? ''), 'finishedAt' => (int) ($data['finishedAt'] ?? 0), 'copied' => array_map('strval', (array) ($data['copied'] ?? [])), 'failed' => array_map('strval', (array) ($data['failed'] ?? [])), 'savedCards' => (int) ($data['savedCards'] ?? 0), 'orders' => (int) ($data['orders'] ?? 0), 'ordersStarted' => (bool) ($data['ordersStarted'] ?? \false), 'ordersDone' => (bool) ($data['ordersDone'] ?? \false)];
    }
    /**
     * Stores the dismissal for the current user (GET link with a nonce).
     */
    public function handleDismiss(): void
    {
        $action = self::dismissAction();
        if (!isset($_GET[$action], $_GET['_wpnonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        if (!wp_verify_nonce($nonce, $action) || !current_user_can('manage_woocommerce')) {
            return;
        }
        update_user_meta(get_current_user_id(), self::dismissedMeta(), 1);
        wp_safe_redirect(remove_query_arg([$action, '_wpnonce']));
        exit;
    }
    /**
     * HTML of the notice for the plugin's admin page, or '' when there is nothing to show or the
     * current user dismissed it.
     *
     * @return string
     */
    public function markup(): string
    {
        if (!current_user_can('manage_woocommerce')) {
            return '';
        }
        if (get_user_meta(get_current_user_id(), self::dismissedMeta(), \true)) {
            return '';
        }
        $summary = self::summary();
        if ($summary['finishedAt'] <= 0) {
            return '';
        }
        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . ModuleHelper::getMainGatewayId());
        $dismissUrl = add_query_arg([self::dismissAction() => 1, '_wpnonce' => wp_create_nonce(self::dismissAction())]);
        $legacyActive = LegacySource::isPluginActive();
        $ordersFailed = !$summary['ordersDone'] && $summary['ordersStarted'] && self::ordersTaskFailed();
        $failed = $this->labels($summary['failed']);
        // One short summary line; the full list goes into the collapsible details.
        $lines = [esc_html($this->copiedSentence($summary))];
        if ($legacyActive) {
            $lines[] = esc_html__('Both plugins\' payment methods are shown at checkout.', 'worldline-gopay-for-woocommerce');
        }
        if ($ordersFailed) {
            $lines[] = esc_html__('The migration of the previous plugin\'s orders failed. Orders that were not migrated are still managed by the previous plugin. Please contact support.', 'worldline-gopay-for-woocommerce');
        } elseif ($summary['ordersStarted'] && !$summary['ordersDone']) {
            $lines[] = esc_html__('Orders of the previous plugin are being migrated in the background.', 'worldline-gopay-for-woocommerce');
        }
        if ($failed !== []) {
            $lines[] = sprintf(
                /* translators: 1: list of areas, 2: opening link tag, 3: closing link tag */
                esc_html__('Not copied: %1$s. Please check them in the %2$ssettings%3$s.', 'worldline-gopay-for-woocommerce'),
                esc_html(implode(', ', $failed)),
                '<a href="' . esc_url($settingsUrl) . '">',
                '</a>'
            );
        }
        $details = [];
        $copied = $this->labels($summary['copied']);
        if ($copied !== []) {
            $details[] = esc_html(sprintf(
                /* translators: 1: previous plugin version, 2: list of copied areas */
                __('Copied from the previous plugin (version %1$s): %2$s.', 'worldline-gopay-for-woocommerce'),
                $summary['source'] !== '' ? $summary['source'] : '2.x',
                implode(', ', $copied)
            ));
        }
        $details[] = $legacyActive ? esc_html__('The previous plugin was not changed and is still active. Disable the methods you do not want to offer twice in one of the plugins.', 'worldline-gopay-for-woocommerce') : esc_html__('The previous plugin was not changed.', 'worldline-gopay-for-woocommerce');
        $details[] = esc_html__('Cards that customers save with this plugin are not visible in the previous plugin.', 'worldline-gopay-for-woocommerce');
        $status = $failed !== [] || $ordersFailed ? 'warning' : 'info';
        // The whole first line is the <summary>, so "Details" stays at the end of the sentence and the
        // details open below it without JavaScript.
        $html = '<div class="op-migration-notice opt--' . $status . '">';
        $html .= '<span class="opp-migration-notice-icon" aria-hidden="true"></span>';
        $html .= '<details class="opp-migration-notice-body"><summary>';
        $html .= '<span class="opp-migration-notice-text">' . implode(' ', array_map('wp_kses_post', $lines)) . '</span> ';
        $html .= '<span class="opp-migration-notice-toggle">' . esc_html__('Details', 'worldline-gopay-for-woocommerce') . '</span>';
        $html .= '</summary><div class="opp-migration-notice-details">';
        foreach ($details as $detail) {
            $html .= '<p>' . wp_kses_post($detail) . '</p>';
        }
        $html .= '</div></details>';
        $html .= '<a class="opp-migration-notice-dismiss" href="' . esc_url($dismissUrl) . '" title="' . esc_attr__('Dismiss', 'worldline-gopay-for-woocommerce') . '" aria-label="' . esc_attr__('Dismiss', 'worldline-gopay-for-woocommerce') . '"></a>';
        $html .= '</div>';
        return $html;
    }
    /**
     * "Settings, 6 saved cards and 58 orders were copied from the previous plugin."
     *
     * @param array{copied: string[], savedCards: int, orders: int, ordersDone: bool} $summary
     *
     * @return string
     */
    private function copiedSentence(array $summary): string
    {
        $parts = [];
        if (array_diff($summary['copied'], ['saved_cards']) !== []) {
            $parts[] = __('Settings', 'worldline-gopay-for-woocommerce');
        }
        if ($summary['savedCards'] > 0) {
            /* translators: %d: number of saved cards */
            $parts[] = sprintf(_n('%d saved card', '%d saved cards', $summary['savedCards'], 'worldline-gopay-for-woocommerce'), $summary['savedCards']);
        }
        if ($summary['ordersDone'] && $summary['orders'] > 0) {
            /* translators: %d: number of orders */
            $parts[] = sprintf(_n('%d order', '%d orders', $summary['orders'], 'worldline-gopay-for-woocommerce'), $summary['orders']);
        }
        if ($parts === []) {
            return __('Data was copied from the previous plugin.', 'worldline-gopay-for-woocommerce');
        }
        // A single item that is one card or one order is singular ("1 order was copied").
        $singular = count($parts) === 1 && $parts[0] !== __('Settings', 'worldline-gopay-for-woocommerce') && $summary['savedCards'] + ($summary['ordersDone'] ? $summary['orders'] : 0) === 1;
        // Plain statements with the translators comment right above each call: the build (php-scoper)
        // drops comments placed inside ternary expressions.
        $list = array_pop($parts);
        if ($parts !== []) {
            /* translators: 1: comma-separated items, 2: last item */
            $list = sprintf(__('%1$s and %2$s', 'worldline-gopay-for-woocommerce'), implode(', ', $parts), $list);
        }
        if ($singular) {
            /* translators: %s: what was copied, e.g. "1 order" */
            return sprintf(__('%s was copied from the previous plugin.', 'worldline-gopay-for-woocommerce'), $list);
        }
        /* translators: %s: what was copied, e.g. "Settings, 6 saved cards and 58 orders" */
        return sprintf(__('%s were copied from the previous plugin.', 'worldline-gopay-for-woocommerce'), $list);
    }
    /**
     * This plugin's settings page only (WooCommerce > Settings > Payments > this plugin's section).
     *
     * @return bool
     */
    /**
     * Whether the order migration task gave up (the queue marks it failed after its retries).
     *
     * @return bool
     */
    private static function ordersTaskFailed(): bool
    {
        try {
            /** @var QueueService $queue */
            $queue = ServiceRegister::getService(QueueService::class);
            $item = $queue->findLatestByType(OrderMigrationTask::getClassName());
            return $item !== null && $item->getStatus() === QueueItem::FAILED;
        } catch (\Throwable $e) {
            return \false;
        }
    }
    /**
     * Translated labels for area keys, in display order.
     *
     * @param string[] $areas
     *
     * @return string[]
     */
    private function labels(array $areas): array
    {
        $all = [self::AREA_CONNECTION => __('connection credentials and mode', 'worldline-gopay-for-woocommerce'), self::AREA_GENERAL_SETTINGS => __('general settings', 'worldline-gopay-for-woocommerce'), self::AREA_WEBHOOK_SETTINGS => __('additional webhook URLs', 'worldline-gopay-for-woocommerce'), self::AREA_HOSTED_CHECKOUT => __('hosted checkout', 'worldline-gopay-for-woocommerce'), self::AREA_CARDS => __('credit cards', 'worldline-gopay-for-woocommerce'), self::AREA_REDIRECT_METHODS => __('payment methods', 'worldline-gopay-for-woocommerce'), self::AREA_SOFT_DESCRIPTOR => __('soft descriptor', 'worldline-gopay-for-woocommerce'), self::AREA_SAVED_CARDS_SETTING => __('saved cards setting', 'worldline-gopay-for-woocommerce'), self::AREA_SESSION_TIMEOUT => __('session timeout', 'worldline-gopay-for-woocommerce'), self::AREA_PRODUCT_TYPES => __('product types', 'worldline-gopay-for-woocommerce'), self::AREA_SAVED_CARDS => __('saved cards', 'worldline-gopay-for-woocommerce')];
        $labels = [];
        foreach ($all as $key => $label) {
            if (in_array($key, $areas, \true)) {
                $labels[] = $label;
            }
        }
        return $labels;
    }
    /**
     * @param array<string, mixed> $summary
     */
    private static function save(array $summary): void
    {
        update_option(self::option(), (string) wp_json_encode($summary), \false);
    }
}
