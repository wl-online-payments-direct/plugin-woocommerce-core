<?php

namespace WOP\OnlinePayments\Repositories;

use DateInterval;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PaymentTransactionEntity;
use WOP\OnlinePayments\Core\Bootstrap\DataAccess\PaymentTransaction\PendingTransactionsRepository;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\BusinessLogic\PaymentProcessor\Services\PaymentLinks\PaymentLinkTransactionService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Time\TimeProviderInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\Interfaces\RepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\Operators;
use WOP\OnlinePayments\Core\Infrastructure\ORM\QueryFilter\QueryFilter;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * Transactions refreshed by the core transaction status check task.
 *
 * Wider than the core selection: pending and authorized transactions created in the last 7 days,
 * Pay by Link included, newest first, 50 per run. Authorized transactions are included so a capture
 * or cancellation made outside the shop without a webhook still reaches the order.
 *
 * As before the move to the task queue, only transactions whose WooCommerce order exists, belongs to
 * this plugin and is still pending or authorized are refreshed. The stored transaction status is not
 * enough: an order the merchant cancelled or trashed in WooCommerce keeps an authorized payment, and
 * refreshing it would set it back to the authorized status (and make it due for auto-capture again).
 *
 * A Pay by Link transaction has no payment id until the link is paid and the shop learns of it (webhook
 * or the payer's return). For such a transaction the link is looked up first: a paid link gives the
 * payment id, which is stored and refreshed; an unpaid link is skipped (the core task needs a payment id).
 */
class PendingTransactionsRepositoryWc extends PendingTransactionsRepository
{
    /** Transactions refreshed per run. */
    private const LIMIT = 50;
    /** Transactions read from the query per refreshed transaction (some are filtered out). */
    private const OVER_FETCH_FACTOR = 5;
    /** How far back transactions are refreshed. */
    private const MAX_AGE = 'P7D';
    /**
     * @var RepositoryInterface
     */
    private RepositoryInterface $transactionRepository;
    /**
     * @var TimeProviderInterface
     */
    private TimeProviderInterface $clock;
    /**
     * @param RepositoryInterface $repository
     * @param TimeProviderInterface $timeProvider
     */
    public function __construct(RepositoryInterface $repository, TimeProviderInterface $timeProvider)
    {
        parent::__construct($repository, $timeProvider);
        $this->transactionRepository = $repository;
        $this->clock = $timeProvider;
    }
    /**
     * @param int $limit Ignored below the plugin's per-run limit.
     *
     * @return PaymentTransactionEntity[]
     */
    public function get(int $limit = 10): array
    {
        $statusCodes = array_values(array_unique(array_merge(StatusCode::PENDING_STATUS_CODES, [StatusCode::authorized()->getCode()])));
        $oldest = $this->clock->getCurrentLocalTime()->sub(new DateInterval(self::MAX_AGE));
        $queryFilter = new QueryFilter();
        $queryFilter->where('statusCode', Operators::IN, $statusCodes)->where('createdAtTimestamp', Operators::GREATER_OR_EQUAL_THAN, $oldest->getTimestamp())->orderBy('createdAtTimestamp', QueryFilter::ORDER_DESC)->setLimit(max($limit, self::LIMIT) * self::OVER_FETCH_FACTOR);
        /** @var PaymentTransactionEntity[] $entities */
        $entities = $this->transactionRepository->select($queryFilter);
        $wanted = max($limit, self::LIMIT);
        $orderStatuses = [OrderStatuses::pending(), OrderStatuses::authorized()];
        $result = [];
        foreach ($entities as $entity) {
            if (count($result) >= $wanted) {
                break;
            }
            $transaction = $entity->getPaymentTransaction();
            if (!self::isOpenOrder($transaction->getMerchantReference(), $orderStatuses)) {
                continue;
            }
            if ($transaction->getPaymentId() === null) {
                $paymentId = $this->paymentLinkPaymentId($entity);
                if ($paymentId === null) {
                    continue;
                }
                $transaction->setPaymentId($paymentId);
            }
            $result[] = $entity;
        }
        return $result;
    }
    /**
     * Whether the WooCommerce order of the merchant reference exists, belongs to this plugin and is
     * pending or authorized.
     *
     * @param string $merchantReference
     * @param string[] $orderStatuses
     *
     * @return bool
     */
    private static function isOpenOrder(string $merchantReference, array $orderStatuses): bool
    {
        if (!function_exists('wc_get_order')) {
            return \false;
        }
        $order = OrderService::forMerchantReference($merchantReference);
        return $order !== null && ModuleHelper::ownsGateway((string) $order->get_payment_method()) && in_array($order->get_status(), $orderStatuses, \true);
    }
    /**
     * Payment id of a paid Pay by Link transaction, read from the link and stored on the transaction.
     *
     * @param PaymentTransactionEntity $entity
     *
     * @return PaymentId|null Null for a transaction without a link, an unpaid link, or a failed lookup.
     */
    private function paymentLinkPaymentId(PaymentTransactionEntity $entity): ?PaymentId
    {
        $transaction = $entity->getPaymentTransaction();
        if (!$transaction->getPaymentLinkId()) {
            return null;
        }
        try {
            return StoreContext::doWithStore($entity->getStoreId(), static function () use ($transaction): ?PaymentId {
                /** @var PaymentLinkTransactionService $service */
                $service = ServiceRegister::getService(PaymentLinkTransactionService::class);
                return $service->updatePaymentId($transaction->getMerchantReference());
            });
        } catch (\Throwable $e) {
            return null;
        }
    }
}
