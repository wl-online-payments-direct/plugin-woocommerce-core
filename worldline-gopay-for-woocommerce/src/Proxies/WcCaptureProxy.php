<?php

namespace WOP\OnlinePayments\Proxies;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Capture\CaptureRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Capture\CaptureResponse;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Order\Proxies\CaptureProxyInterface;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Sdk\ResponseException;
defined('ABSPATH') || exit;
/**
 * Capture proxy: the core request unchanged. A capture sent by the core auto-capture task is recorded
 * on the WooCommerce order once the payment platform has answered it, accepted or rejected, so the
 * automatic capture of a payment is sent only once.
 *
 * Without the record, a rejected automatic capture is sent again on every auto-capture run: the
 * platform keeps such a payment authorized (status 5, the capture operation is REJECTED_CAPTURE), so
 * the transaction keeps its due capture time. The merchant captures it from the order instead.
 *
 * Replaces the core proxy (registered in `BootstrapComponent::initProxies()`).
 */
class WcCaptureProxy implements CaptureProxyInterface
{
    /** StoreContext origin the core AutoCaptureCheckTask sets before each capture. */
    private const AUTO_CAPTURE_ORIGIN = 'order.autocapture';
    private CaptureProxyInterface $inner;
    /**
     * @param CaptureProxyInterface $inner Core proxy.
     */
    public function __construct(CaptureProxyInterface $inner)
    {
        $this->inner = $inner;
    }
    /**
     * @inheritDoc
     */
    public function create(CaptureRequest $captureRequest): CaptureResponse
    {
        if (StoreContext::getInstance()->getOrigin() !== self::AUTO_CAPTURE_ORIGIN) {
            return $this->inner->create($captureRequest);
        }
        try {
            $response = $this->inner->create($captureRequest);
        } catch (ResponseException $e) {
            // The platform answered and rejected the capture; a transport error is not recorded, so
            // the capture is tried again on the next run.
            $this->recordAutoCapture($captureRequest, 'rejected: ' . $e->getMessage());
            throw $e;
        }
        $this->recordAutoCapture($captureRequest, 'sent');
        return $response;
    }
    /**
     * Records the automatic capture of the request's payment on its WooCommerce order.
     *
     * @param CaptureRequest $captureRequest
     * @param string $outcome For the log.
     */
    private function recordAutoCapture(CaptureRequest $captureRequest, string $outcome): void
    {
        try {
            $paymentId = $captureRequest->getPaymentId();
            /** @var PaymentTransactionRepositoryInterface $repository */
            $repository = ServiceRegister::getService(PaymentTransactionRepositoryInterface::class);
            $transaction = $repository->get($paymentId);
            $order = $transaction ? OrderService::forMerchantReference($transaction->getMerchantReference()) : null;
            if ($order === null) {
                return;
            }
            $order->update_meta_data(OrderService::META_AUTO_CAPTURE_SENT, (string) $paymentId);
            $order->save();
            Logger::logInfo(sprintf('Automatic capture of order %d (payment %s) %s', $order->get_id(), (string) $paymentId, $outcome), 'AutoCapture');
        } catch (\Throwable $e) {
            Logger::logWarning('Automatic capture could not be recorded: ' . $e->getMessage(), 'AutoCapture');
        }
    }
}
