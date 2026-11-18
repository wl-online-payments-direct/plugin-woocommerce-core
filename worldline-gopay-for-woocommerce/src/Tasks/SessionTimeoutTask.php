<?php

namespace WOP\OnlinePayments\Tasks;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Task;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
use WOP\OnlinePayments\Order\SessionTimeoutCloser;
use WOP\OnlinePayments\Services\SessionTimeoutSetting;
defined('ABSPATH') || exit;
/**
 * Session timeout sweep on the core task queue: fails orders of this plugin left unpaid after the
 * session timeout and releases their stock. A safety net for sessions the expiry webhook did not close.
 *
 * Only orders where the shopper never completed a payment attempt are failed: an order that received
 * a status in its current session (e.g. SEPA Direct Debit or bank transfer still being processed) is
 * left alone and flagged so later runs skip it, until a new session starts. Pay by Link orders are
 * never touched (the link has its own expiration), nor are orders that are not pending.
 *
 * Enqueued by SessionTimeoutListener on the task runner tick. The task has no state, so the core
 * Task serialization (empty array) is used as is.
 */
class SessionTimeoutTask extends Task
{
    /** Orders processed per run; later runs continue with the rest. */
    private const LIMIT = 200;
    /** Log component. */
    private const COMPONENT = 'SessionTimeout';
    /**
     * Fails every abandoned order past the session timeout, oldest first.
     */
    public function execute(): void
    {
        if (!function_exists('wc_get_orders')) {
            $this->reportProgress(100);
            return;
        }
        $minutes = SessionTimeoutSetting::minutes();
        $threshold = time() - $minutes * \MINUTE_IN_SECONDS;
        $gateways = self::sweptGateways();
        if ($gateways === []) {
            $this->reportProgress(100);
            return;
        }
        $failed = 0;
        $orderIds = self::candidates($gateways, $threshold);
        foreach ($orderIds as $orderId) {
            $failed += self::process($orderId, $minutes);
            $this->reportAlive();
        }
        $name = ModuleHelper::getModuleConfig()->getName();
        if (count($orderIds) >= self::LIMIT) {
            Logger::logInfo(sprintf('%s session timeout sweep reached the batch limit (%d); the rest follows on the next run.', $name, self::LIMIT), self::COMPONENT);
        }
        if ($failed > 0) {
            Logger::logInfo(sprintf('%s session timeout sweep failed %d order(s) older than %d minute(s).', $name, $failed, $minutes), self::COMPONENT);
        }
        $this->reportProgress(100);
    }
    /**
     * Whether the order may be failed: no status since its latest session started.
     * Orders created before the session start was recorded fall back to "no transaction id".
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    public static function isAbandoned(\WC_Order $order): bool
    {
        $startedAt = (int) $order->get_meta(OrderService::META_SESSION_STARTED_AT);
        $statusAt = (int) $order->get_meta(OrderService::META_STATUS_RECEIVED_AT);
        if ($startedAt > 0) {
            return $statusAt < $startedAt;
        }
        return $statusAt === 0 && (string) $order->get_transaction_id() === '';
    }
    /**
     * @param int $orderId
     * @param int $minutes
     *
     * @return int 1 when the order was failed.
     */
    private static function process(int $orderId, int $minutes): int
    {
        try {
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order || !$order->has_status(OrderStatuses::pending())) {
                return 0;
            }
            if (!self::isAbandoned($order)) {
                $order->update_meta_data(OrderService::META_SESSION_TIMEOUT_EXEMPT, '1');
                $order->save();
                return 0;
            }
            return SessionTimeoutCloser::close($order, SessionTimeoutCloser::timeoutNote($minutes)) ? 1 : 0;
        } catch (\Throwable $e) {
            Logger::logWarning(sprintf('%s session timeout skipped order %d: %s', ModuleHelper::getModuleConfig()->getName(), $orderId, $e->getMessage()), self::COMPONENT);
            return 0;
        }
    }
    /**
     * Pending orders of the given gateways whose latest session started before the threshold (or,
     * without a recorded session start, that were created before it), not flagged as exempt.
     *
     * @param string[] $gateways
     * @param int $threshold
     *
     * @return int[]
     */
    private static function candidates(array $gateways, int $threshold): array
    {
        $metaQuery = ['relation' => 'AND', ['key' => OrderService::META_SESSION_TIMEOUT_EXEMPT, 'compare' => 'NOT EXISTS'], ['relation' => 'OR', ['key' => OrderService::META_SESSION_STARTED_AT, 'value' => $threshold, 'compare' => '<', 'type' => 'NUMERIC'], ['key' => OrderService::META_SESSION_STARTED_AT, 'compare' => 'NOT EXISTS']]];
        $query = ['status' => [OrderStatuses::pending()], 'payment_method' => $gateways, 'date_created' => '<' . $threshold, 'limit' => self::LIMIT, 'orderby' => 'date', 'order' => 'ASC', 'return' => 'ids', 'type' => 'shop_order'];
        if (self::usesOrdersTable()) {
            $query['meta_query'] = $metaQuery;
            return array_map('intval', (array) wc_get_orders($query));
        }
        // The posts storage ignores 'meta_query' in wc_get_orders(); pass it through to WP_Query.
        $queryVar = self::metaQueryVar();
        $query[$queryVar] = $metaQuery;
        $filter = static function ($wpQuery, $queryVars) use ($queryVar) {
            if (!empty($queryVars[$queryVar])) {
                $wpQuery['meta_query'] = array_merge($wpQuery['meta_query'] ?? [], [$queryVars[$queryVar]]);
            }
            return $wpQuery;
        };
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', $filter, 10, 2);
        try {
            return array_map('intval', (array) wc_get_orders($query));
        } finally {
            remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $filter, 10);
        }
    }
    /**
     * This plugin's gateway ids, without Pay by Link.
     *
     * @return string[]
     */
    private static function sweptGateways(): array
    {
        $paymentGateways = function_exists('WC') ? WC()->payment_gateways() : null;
        if ($paymentGateways === null) {
            return [];
        }
        $ids = array_filter(array_keys($paymentGateways->payment_gateways()), static function ($id): bool {
            return ModuleHelper::ownsGateway((string) $id) && (string) $id !== PayByLinkGateway::id();
        });
        return array_values(array_map('strval', $ids));
    }
    /** Brand-prefixed query var carrying the meta query on the legacy (posts) order storage. */
    private static function metaQueryVar(): string
    {
        return ModuleHelper::moduleKey('_session_timeout_meta_query');
    }
    private static function usesOrdersTable(): bool
    {
        return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
