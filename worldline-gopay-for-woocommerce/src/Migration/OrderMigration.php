<?php

namespace WOP\OnlinePayments\Migration;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentTransaction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Gateway\MethodCatalog;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
defined('ABSPATH') || exit;
/**
 * Migrates the previous (2.x) plugin's orders: for each order paid with a 2.x gateway that reached
 * Worldline, creates the core PaymentTransaction row (folding in the scheduled auto-capture time) and
 * the `_worldline_*` meta, then moves the order to this plugin's matching gateway. 2.x `_wlop_*`
 * meta is only read. Runs in batches from OrderMigrationTask.
 */
class OrderMigration
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Orders processed per batch. */
    private const BATCH = 50;
    /** State option keys (per site); module-prefixed at runtime. The paging position is kept by the task. */
    private static function startedOption(): string
    {
        return ModuleHelper::moduleKey('_migration_orders_started');
    }
    private static function doneOption(): string
    {
        return ModuleHelper::moduleKey('_migration_orders_done');
    }
    /** Legacy order meta keys (fixed across brands). */
    private const LEGACY_TRANSACTION_ID = '_wlop_transaction_id';
    private const LEGACY_STATUS_CODE = '_wlop_transaction_status_code';
    private const LEGACY_CREATION_TIME = '_wlop_creation_time';
    private const LEGACY_PAYMENT_METHOD_NAME = '_wlop_payment_method_name';
    private const LEGACY_AUTO_CAPTURE_SENT = '_wlop_auto_capture';
    private const LEGACY_MANUAL_CAPTURE_SENT = '_wlop_manual_capture';
    /**
     * Order meta written in the same save that moves the order: marks a migrated order (counted for the
     * notice when the migration finishes) and, while set, an order whose status refresh is still due.
     * Both survive a run stopped in the middle of an order, so the continuation neither loses the
     * count nor skips the refresh.
     */
    private const META_MIGRATED = '_worldline_migrated';
    private const META_REFRESH_PENDING = '_worldline_refresh_due';
    /** @var array<string, mixed>|null Lazily-read legacy gateway settings. */
    private ?array $legacyConfig = null;
    /** @return bool */
    public static function isStarted(): bool
    {
        return (bool) get_option(self::startedOption());
    }
    /** @return bool */
    public static function isDone(): bool
    {
        return (bool) get_option(self::doneOption());
    }
    /** Records that the background migration has been kicked off. */
    public static function markStarted(): void
    {
        update_option(self::startedOption(), 1, \false);
    }
    /**
     * Migrates the batch of orders that starts at the given position.
     *
     * @param int $offset Position of the first order of the batch (orders sorted by id).
     *
     * @return int|null Position of the next batch, or null when every order was processed.
     */
    public function processBatch(int $offset): ?int
    {
        $ids = wc_get_orders(['limit' => self::BATCH, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids', 'status' => 'any', 'type' => 'shop_order']);
        if (!is_array($ids) || $ids === []) {
            $this->markDone();
            return null;
        }
        foreach ($ids as $id) {
            try {
                // When a stopped or failed run is continued, the batch runs again; an order the earlier
                // attempt migrated is not migrated twice (its transaction row exists).
                $order = wc_get_order($id);
                if ($order instanceof \WC_Order) {
                    $this->migrateOrder($order);
                }
            } catch (\Throwable $e) {
                Logger::logWarning('Migration skipped order ' . $id . ': ' . $e->getMessage(), 'Migration');
            }
        }
        if (count($ids) < self::BATCH) {
            $this->markDone();
            return null;
        }
        return $offset + count($ids);
    }
    /**
     * Number of orders the migration pages through (all orders; each one is checked).
     *
     * @return int
     */
    public static function totalOrders(): int
    {
        $result = wc_get_orders(['limit' => 1, 'paginate' => \true, 'return' => 'ids', 'status' => 'any', 'type' => 'shop_order']);
        return is_object($result) && isset($result->total) ? (int) $result->total : 0;
    }
    /** Records that every order was processed. */
    private function markDone(): void
    {
        update_option(self::doneOption(), 1, \false);
        MigrationNotice::finishOrders(self::migratedOrders());
    }
    /**
     * Number of orders the migration moved to this plugin (orders carrying the migration mark).
     *
     * @return int
     */
    private static function migratedOrders(): int
    {
        $result = wc_get_orders(['limit' => 1, 'paginate' => \true, 'return' => 'ids', 'status' => 'any', 'type' => 'shop_order', 'meta_key' => self::META_MIGRATED, 'meta_compare' => 'EXISTS']);
        return is_object($result) && isset($result->total) ? (int) $result->total : 0;
    }
    /**
     * Migrates one 2.x order: creates its transaction row and `_worldline_*` meta, moves it to this
     * plugin's matching gateway (the title is kept) and refreshes a non-final status from Worldline.
     * Skips orders that never reached Worldline, carry no usable status, or whose gateway has no
     * counterpart in this plugin (those stay with 2.x unchanged). An order that was moved to this
     * plugin's gateway by an earlier installation (whose tables were dropped on uninstall) only gets
     * its transaction row back.
     *
     * @param \WC_Order $order
     *
     * @return bool Whether the order was migrated.
     */
    private function migrateOrder(\WC_Order $order): bool
    {
        $currentGatewayId = (string) $order->get_payment_method();
        $alreadyMoved = ModuleHelper::ownsGateway($currentGatewayId);
        if (!$alreadyMoved && !LegacySource::isLegacyGateway($currentGatewayId)) {
            return \false;
        }
        $transactionId = (string) $order->get_meta(self::LEGACY_TRANSACTION_ID);
        if ($transactionId === '') {
            return \false;
        }
        $statusInt = (int) $order->get_meta(self::LEGACY_STATUS_CODE);
        if ($statusInt <= 0) {
            // -1 (legacy "unknown") and 0 (incomplete) would make the new order page throw.
            return \false;
        }
        $legacyGatewayId = $currentGatewayId;
        $gatewayId = $alreadyMoved ? $currentGatewayId : $this->targetGatewayId($legacyGatewayId);
        if ($gatewayId === null) {
            Logger::logInfo('Migration left order ' . $order->get_id() . ' with the previous plugin: no gateway for ' . $legacyGatewayId . '.', 'Migration');
            return \false;
        }
        $created = $this->createTransaction($order, $transactionId, $statusInt, $gatewayId);
        if ($alreadyMoved && !$created) {
            // Moved by an earlier attempt of this migration that stopped before the refresh.
            if ($order->get_meta(self::META_REFRESH_PENDING) !== '') {
                $this->refreshStatus($order, $transactionId);
                $this->clearRefreshPending($order);
            }
            return \false;
        }
        $order->update_meta_data(OrderService::META_PAYMENT_ID, $transactionId);
        $order->update_meta_data(OrderService::META_MERCHANT_REFERENCE, (string) $order->get_id());
        $methodName = (string) $order->get_meta(self::LEGACY_PAYMENT_METHOD_NAME);
        if ($methodName !== '') {
            $order->update_meta_data(OrderService::META_PAYMENT_METHOD, $methodName);
        }
        // A row that exists although the order was not moved yet was created by an earlier attempt
        // that stopped before this save; the order is migrated (and refreshed) now.
        $needsRefresh = !self::isFinal(StatusCode::parse($statusInt));
        $order->update_meta_data(self::META_MIGRATED, '1');
        if ($needsRefresh) {
            $order->update_meta_data(self::META_REFRESH_PENDING, '1');
        }
        $order->set_payment_method($gatewayId);
        $order->save();
        if ($needsRefresh) {
            $this->refreshStatus($order, $transactionId);
            $this->clearRefreshPending($order);
        }
        return \true;
    }
    /**
     * Removes the refresh mark after the refresh ran (successful or not; a failure is logged). The order
     * is read again because the refresh may have saved it.
     *
     * @param \WC_Order $order
     */
    private function clearRefreshPending(\WC_Order $order): void
    {
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof \WC_Order) {
            $fresh->delete_meta_data(self::META_REFRESH_PENDING);
            $fresh->save();
        }
    }
    /**
     * This plugin's gateway id for a 2.x gateway id (same suffix, this plugin's prefix), or null when
     * this plugin has no such gateway (methods without a 3.0 counterpart are handled separately).
     * Whether the method is currently enabled does not matter.
     *
     * @param string $legacyGatewayId
     *
     * @return string|null
     */
    private function targetGatewayId(string $legacyGatewayId): ?string
    {
        $gatewayId = ModuleHelper::getModuleConfig()->getGatewayPrefix() . substr($legacyGatewayId, strlen(LegacySource::gatewayPrefix()));
        return MethodCatalog::isKnownGatewayId($gatewayId) ? $gatewayId : null;
    }
    /**
     * Whether a Worldline status needs no refresh: captured, refunded, cancelled or rejected.
     *
     * @param StatusCode $status
     *
     * @return bool
     */
    private static function isFinal(StatusCode $status): bool
    {
        return $status->equals(StatusCode::completed()) || $status->isRefunded() || $status->isCanceledOrRejected();
    }
    /**
     * Brings the order up to date with Worldline once, for events 2.x received before the row existed.
     *
     * @param \WC_Order $order
     * @param string $transactionId
     */
    private function refreshStatus(\WC_Order $order, string $transactionId): void
    {
        try {
            $response = CheckoutAPI::get()->payment(self::STORE_ID)->updateOrderStatus(PaymentId::parse($transactionId), null);
            // The facade returns an error response instead of throwing.
            $failed = is_object($response) && method_exists($response, 'isSuccessful') && !$response->isSuccessful();
            $reason = 'the payment platform returned an error';
        } catch (\Throwable $e) {
            $failed = \true;
            $reason = $e->getMessage();
        }
        if ($failed) {
            Logger::logWarning('Migration could not refresh order ' . $order->get_id() . ' from the payment platform: ' . $reason, 'Migration');
        }
    }
    /**
     * Re-creates the core PaymentTransaction row for a legacy order, skipping if one already exists.
     *
     * @param \WC_Order $order
     * @param string $transactionId
     * @param int $statusInt
     * @param string $paymentMethod This plugin's gateway id the order moves to.
     *
     * @return bool Whether a row was created now.
     */
    private function createTransaction(\WC_Order $order, string $transactionId, int $statusInt, string $paymentMethod): bool
    {
        $captureAt = $this->captureAt($order, $statusInt);
        $createdAt = $this->createdAt($order);
        $customerId = $order->get_customer_id() > 0 ? (string) $order->get_customer_id() : null;
        $merchantReference = (string) $order->get_id();
        return (bool) StoreContext::doWithStore(self::STORE_ID, static function () use ($merchantReference, $transactionId, $statusInt, $customerId, $createdAt, $paymentMethod, $captureAt): bool {
            /** @var PaymentTransactionRepositoryInterface $repository */
            $repository = ServiceRegister::getService(PaymentTransactionRepositoryInterface::class);
            if ($repository->getByMerchantReference($merchantReference) !== null) {
                return \false;
            }
            $repository->save(new PaymentTransaction($merchantReference, PaymentId::parse($transactionId), null, StatusCode::parse($statusInt), $customerId, $createdAt, null, null, $paymentMethod, $captureAt));
            return \true;
        });
    }
    /**
     * Scheduled capture time for an authorized, not-yet-captured order, or null when none applies.
     *
     * @param \WC_Order $order
     * @param int $statusInt
     *
     * @return \DateTime|null
     */
    private function captureAt(\WC_Order $order, int $statusInt): ?\DateTime
    {
        if (!StatusCode::parse($statusInt)->equals(StatusCode::authorized())) {
            return null;
        }
        if (self::legacyCaptureSent($order)) {
            return null;
        }
        $config = $this->legacyConfig();
        $captureMode = (string) ($config['capture_mode'] ?? 'manual');
        $authorizationMode = (string) ($config['authorization_mode'] ?? 'authorization');
        if ($captureMode === 'manual' || $authorizationMode === 'sale') {
            return null;
        }
        $base = $this->createdAt($order);
        if ($base === null) {
            return null;
        }
        if ($captureMode === 'end_of_day') {
            return (clone $base)->setTime(23, 59, 59);
        }
        $days = (int) substr($captureMode, 0, 1);
        if ($days >= 1 && $days <= 7) {
            return (clone $base)->modify('+' . $days . ' days');
        }
        return null;
    }
    /**
     * Whether the previous plugin already sent a capture request for the order (its auto-capture job
     * or the merchant's Capture button). Such an order gets no capture time and is left out of this
     * plugin's auto-capture, also when a later status update would schedule one.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    public static function legacyCaptureSent(\WC_Order $order): bool
    {
        return $order->get_meta(self::LEGACY_AUTO_CAPTURE_SENT) === 'yes' || $order->get_meta(self::LEGACY_MANUAL_CAPTURE_SENT) === 'yes';
    }
    /**
     * Order creation time, from `_wlop_creation_time` when present, else the WooCommerce order date.
     *
     * @param \WC_Order $order
     *
     * @return \DateTime|null
     */
    private function createdAt(\WC_Order $order): ?\DateTime
    {
        $timestamp = (int) $order->get_meta(self::LEGACY_CREATION_TIME);
        if ($timestamp <= 0) {
            $created = $order->get_date_created();
            $timestamp = $created instanceof \WC_DateTime ? $created->getTimestamp() : 0;
        }
        if ($timestamp <= 0) {
            return null;
        }
        $dateTime = new \DateTime('@' . $timestamp);
        $dateTime->setTimezone(wp_timezone());
        return $dateTime;
    }
    /**
     * Legacy main-gateway settings (capture mode, authorization mode), read once.
     *
     * @return array<string, mixed>
     */
    private function legacyConfig(): array
    {
        if ($this->legacyConfig !== null) {
            return $this->legacyConfig;
        }
        $this->legacyConfig = (new LegacyConfigReader())->mainRow();
        return $this->legacyConfig;
    }
}
