<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Order\OrderAPI\OrderAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\ShopOrderService as CoreShopOrderService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentTransaction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\StatusCode;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Gateway\MethodCatalog;
use WOP\OnlinePayments\Gateway\PayByLinkGateway;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
use WOP\OnlinePayments\Order\SessionTimeoutCloser;
defined('ABSPATH') || exit;
/**
 * Syncs the WooCommerce order with the Worldline payment outcome.
 */
class ShopOrderService implements CoreShopOrderService
{
    private const STORE_ID = '1';
    /** Joins the methods of a split payment's legs, e.g. "Illicado + Carte Bancaire". */
    private const SPLIT_SEPARATOR = ' + ';
    /** Order meta flag marking the surcharge fee as already added (idempotency). */
    private const SURCHARGE_FLAG = '_worldline_surcharge_added';
    /** Order meta: payment id whose cancellation on the hosted payment page is already noted. */
    private const CUSTOMER_CANCEL_NOTED = '_worldline_customer_cancel_noted';
    /** Worldline error id of a payment cancelled because its hosted checkout session expired. */
    private const CANCELLED_BY_EXPIRATION = 'CANCELLED_BY_EXPIRATION';
    /**
     * @inheritDoc
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    public function createShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->withOrderLock($paymentTransaction, function () use ($paymentTransaction, $paymentDetails, $newState): void {
            $this->applyStatus($paymentTransaction, $paymentDetails, $newState);
        });
    }
    /**
     * @inheritDoc
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    public function updateStatus(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->withOrderLock($paymentTransaction, function () use ($paymentTransaction, $paymentDetails, $newState): void {
            $this->applyStatus($paymentTransaction, $paymentDetails, $newState);
        });
    }
    /**
     * @inheritDoc
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    public function cancelShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->withOrderLock($paymentTransaction, function () use ($paymentTransaction, $paymentDetails, $newState): void {
            $this->applyCancellation($paymentTransaction, $paymentDetails, $newState);
        });
    }
    /**
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    private function applyCancellation(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $order = $this->resolveOrder($paymentTransaction);
        // Worldline closed the hosted payment session because its timeout elapsed: the order is
        // abandoned. An older session expiring after the shopper started a new one is ignored.
        if ($order !== null && self::isSessionExpiry($paymentDetails)) {
            self::closeExpiredSession($order, $paymentTransaction);
            return;
        }
        // Only a customer cancelling on the hosted payment page leaves the order in its initial
        // pending state (the payment was never authorized). A merchant/system cancel acts on an
        // authorized (on-hold) order, and a re-fired cancel sync sees it already cancelled - both
        // must fall through to applyStatus. needs_payment() is unreliable here because the retry
        // filter (PaymentReturnHandler::allowRetryStatuses) marks cancelled/failed orders payable,
        // so a re-fire on an already-cancelled order would wrongly add the customer note.
        // The same cancellation arrives more than once (return page, webhook, later status checks of
        // the still-pending order), so the note is written once per payment.
        if ($order !== null && $order->has_status(OrderStatuses::pending())) {
            $this->recordMethod($order, $paymentTransaction, $paymentDetails);
            $paymentId = $paymentTransaction->getPaymentId() !== null ? $paymentTransaction->getPaymentId()->getTransactionId() : '';
            if ($paymentId !== '' && (string) $order->get_meta(self::CUSTOMER_CANCEL_NOTED) === $paymentId) {
                $order->save();
                return;
            }
            $order->update_meta_data(self::CUSTOMER_CANCEL_NOTED, $paymentId);
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: %s: payment gateway name. */
                esc_html__('%s: payment cancelled by the customer on the hosted payment page.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            ));
            return;
        }
        $this->applyStatus($paymentTransaction, $paymentDetails, $newState);
    }
    /**
     * @inheritDoc
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    public function refundShopOrder(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $this->withOrderLock($paymentTransaction, function () use ($paymentTransaction, $paymentDetails, $newState): void {
            $this->syncPortalRefunds($paymentTransaction, $paymentDetails);
            $this->applyStatus($paymentTransaction, $paymentDetails, $newState);
        });
    }
    /**
     * Runs one order update while holding a MySQL advisory lock for the order. Worldline sends
     * several webhooks for one operation (e.g. refund requested + refunded) and the return page can
     * run at the same time; without the lock each request reads the order before the other saved it,
     * so both write the same status change note (and both could add the same portal refund). The
     * order is read inside the callback, after the lock is taken. Best-effort: if the lock cannot be
     * taken within 10 seconds the update still runs.
     *
     * @param PaymentTransaction $paymentTransaction
     * @param callable():void $update
     */
    private function withOrderLock(PaymentTransaction $paymentTransaction, callable $update): void
    {
        global $wpdb;
        $orderId = OrderService::orderIdFromMerchantReference($paymentTransaction->getMerchantReference());
        if ($orderId === null || !$wpdb instanceof \wpdb) {
            $update();
            return;
        }
        $name = ModuleHelper::moduleKey('_order_update_') . $orderId;
        $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 10)) === '1';
        try {
            $update();
        } finally {
            if ($locked) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }
    /**
     * Records a WooCommerce refund for refunds performed on the Worldline side (e.g. the merchant
     * portal) that WooCommerce does not yet know about, so the order's refunded total and net payment
     * match Worldline. Shop-initiated refunds already create their own WooCommerce refund before
     * calling Worldline, so only the amount beyond what WooCommerce has already recorded is added -
     * which keeps this idempotent across repeated webhooks. wc_create_refund only writes the refund
     * record; it does not invoke the gateway's process_refund, so no second Worldline refund is fired.
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     */
    private function syncPortalRefunds(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails): void
    {
        if (!function_exists('wc_create_refund')) {
            return;
        }
        $order = $this->resolveOrder($paymentTransaction);
        if ($order === null) {
            return;
        }
        $amounts = $paymentDetails->getAmounts();
        $refunded = $amounts !== null && $amounts->getRefundedAmount() !== null ? (float) $amounts->getRefundedAmount()->getPriceInCurrencyUnits() : 0.0;
        if ($refunded <= 0) {
            return;
        }
        $missing = round($refunded - (float) $order->get_total_refunded(), 2);
        $remaining = round((float) $order->get_remaining_refund_amount(), 2);
        $missing = min($missing, $remaining);
        if ($missing < 0.01) {
            return;
        }
        wc_create_refund(['order_id' => $order->get_id(), 'amount' => $missing, 'reason' => sprintf(
            /* translators: %s: payment gateway name. */
            esc_html__('%s: refunded via the merchant portal.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        ), 'refund_payment' => \false, 'restock_items' => \false]);
    }
    /**
     * Applies the payment outcome to the WC order.
     *
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails $paymentDetails
     * @param string $newState
     */
    private function applyStatus(PaymentTransaction $paymentTransaction, PaymentDetails $paymentDetails, string $newState): void
    {
        $order = $this->resolveOrder($paymentTransaction);
        if ($order === null) {
            return;
        }
        $paymentId = $paymentTransaction->getPaymentId();
        $transactionId = $paymentId !== null ? (string) $paymentId : '';
        $statusCode = $paymentTransaction->getStatusCode();
        if ($transactionId !== '') {
            $order->set_transaction_id($transactionId);
            $order->update_meta_data(OrderService::META_PAYMENT_ID, $transactionId);
        }
        // A payment attempt reached Worldline: the session timeout no longer applies to this session.
        // Status 0 (created) is not an attempt: some methods create the payment as soon as the hosted
        // page opens, and the session can still expire without a payment.
        if (!$statusCode->equals(StatusCode::incomplete())) {
            $order->update_meta_data(OrderService::META_STATUS_RECEIVED_AT, (string) time());
        }
        $this->recordMethod($order, $paymentTransaction, $paymentDetails);
        if ($statusCode->equals(StatusCode::completed()) || $statusCode->equals(StatusCode::authorized())) {
            $this->applySurcharge($order, $paymentDetails);
        }
        $note = sprintf(
            /* translators: %s: payment gateway name. */
            esc_html__('%s: payment status updated.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        );
        $refunded = $paymentDetails->getAmounts() !== null ? $paymentDetails->getAmounts()->getRefundedAmount() : null;
        $isRefunded = $refunded !== null && $refunded->getValue() > 0;
        if ($statusCode->equals(StatusCode::completed()) && !$isRefunded) {
            $this->markCaptured($order, $newState, $note, $transactionId);
            return;
        }
        if ($newState === '' || in_array($statusCode->getCode(), StatusCode::CAPTURE_REQUESTED_STATUS_CODES, \true)) {
            $order->save();
            return;
        }
        // An authorization sets no paid date, so record when it was first confirmed (opens the return window).
        if ($statusCode->equals(StatusCode::authorized()) && $order->get_meta(OrderService::META_PAYMENT_CONFIRMED_AT) === '') {
            $order->update_meta_data(OrderService::META_PAYMENT_CONFIRMED_AT, (string) time());
        }
        $order->update_status($newState, $note);
    }
    /**
     * Marks the order paid on first capture: records the paid date, moves it to the configured
     * captured status with a branded note, and fires woocommerce_payment_complete once for stock and
     * downstream listeners. Uses update_status (not payment_complete) so the configured mapping is
     * honoured. No-ops once the order is already paid, so re-syncs never add duplicate notes.
     *
     * @param \WC_Order $order
     * @param string $newState
     * @param string $note
     * @param string $transactionId
     */
    private function markCaptured(\WC_Order $order, string $newState, string $note, string $transactionId): void
    {
        if ($order->is_paid()) {
            return;
        }
        if ($newState === '') {
            $order->payment_complete($transactionId);
            return;
        }
        if ($order->get_date_paid('edit') === null) {
            $order->set_date_paid(time());
        }
        $order->update_status($newState, $note);
        do_action('woocommerce_payment_complete', $order->get_id(), $transactionId);
    }
    /**
     * Adds the Worldline-calculated surcharge to the order as a fee line item, once.
     *
     * @param \WC_Order $order
     * @param PaymentDetails $paymentDetails
     */
    private function applySurcharge(\WC_Order $order, PaymentDetails $paymentDetails): void
    {
        if (!class_exists(\WC_Order_Item_Fee::class) || wc_string_to_bool((string) $order->get_meta(self::SURCHARGE_FLAG))) {
            return;
        }
        $output = $paymentDetails->getPaymentSpecificOutput();
        $surcharge = $output !== null ? $output->getSurchargeAmount() : null;
        if ($surcharge === null || $surcharge->getValue() <= 0) {
            return;
        }
        $amount = (float) $surcharge->getPriceInCurrencyUnits();
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name(esc_html__('Payment surcharge', 'worldline-gopay-for-woocommerce'));
        $fee->set_amount((string) $amount);
        $fee->set_total((string) $amount);
        $fee->set_tax_status('none');
        $order->add_item($fee);
        $order->update_meta_data(self::SURCHARGE_FLAG, wc_bool_to_string(\true));
        $order->calculate_totals(\false);
        $order->add_order_note(sprintf(
            /* translators: %1$s: formatted surcharge amount, %2$s: brand name. */
            esc_html__('Payment surcharge of %1$s applied by %2$s.', 'worldline-gopay-for-woocommerce'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
            ModuleHelper::getModuleConfig()->getName()
        ));
    }
    /**
     * Stores the resolved method for the back office and, for the generic Hosted Checkout / Pay by
     * Link gateways, shows it as the customer title (the method picked on the hosted page, no plugin
     * prefix). Method-specific gateways keep the name the customer chose at checkout. For wallet
     * payments the core already appends the underlying card brand, e.g. "Google Pay (Visa)".
     *
     * A split payment ("Illicado + Carte Bancaire") is stored in full for the back office, while the
     * customer title shows only the method the customer picked - its first leg - the same way a
     * Mealvouchers order keeps the "Mealvouchers" gateway title.
     *
     * @param \WC_Order $order
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails|null $paymentDetails
     */
    private function recordMethod(\WC_Order $order, PaymentTransaction $paymentTransaction, ?PaymentDetails $paymentDetails = null): void
    {
        $base = $this->displayMethod($order, $paymentTransaction, $paymentDetails);
        if ($base === '') {
            return;
        }
        // The wallet brand ("Google Pay (Visa)") is already part of the core-provided transaction method,
        // so $base carries it. Keep a stored split ("Mealvouchers + Visa") so a later sync that cannot
        // read the legs can't shrink it.
        $stored = OrderService::paymentMethod($order);
        $method = strpos($stored, $base . ' + ') === 0 ? $stored : $base;
        if ($stored !== $method) {
            $order->update_meta_data(OrderService::META_PAYMENT_METHOD, $method);
        }
        $title = explode(self::SPLIT_SEPARATOR, $method)[0];
        $gatewayId = $order->get_payment_method();
        if (($gatewayId === ModuleHelper::getMainGatewayId() || $gatewayId === PayByLinkGateway::id()) && $order->get_payment_method_title() !== $title) {
            $order->set_payment_method_title($title);
        }
    }
    /**
     * Resolved payment method stored for the back office (order-list column / metabox). A split
     * payment lists every leg's method in order, e.g. "Illicado + Carte Bancaire". When the legs
     * can't be read, a Mealvouchers order keeps the gift-card label with the residual method appended.
     *
     * @param \WC_Order $order
     * @param PaymentTransaction $paymentTransaction
     * @param PaymentDetails|null $paymentDetails
     *
     * @return string
     */
    private function displayMethod(\WC_Order $order, PaymentTransaction $paymentTransaction, ?PaymentDetails $paymentDetails = null): string
    {
        $transactionMethod = trim((string) $paymentTransaction->getPaymentMethod());
        $splitMethod = $paymentDetails !== null ? self::splitMethod($order, $paymentDetails) : '';
        if ($splitMethod !== '') {
            return $splitMethod;
        }
        $mealvouchers = PaymentProductId::parse(PaymentProductId::MEALVOUCHERS);
        if ($order->get_payment_method() !== MethodCatalog::gatewayId($mealvouchers)) {
            return $transactionMethod;
        }
        $mealLabel = MethodCatalog::label($mealvouchers);
        $normalize = static function (string $value): string {
            return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
        };
        if ($transactionMethod !== '' && $normalize($transactionMethod) !== $normalize($mealLabel)) {
            return $mealLabel . self::SPLIT_SEPARATOR . $transactionMethod;
        }
        return $mealLabel;
    }
    /**
     * Method label of a split payment - each leg's method in payment order, a method paid with more
     * than once listed once ("Illicado + Illicado + Carte Bancaire" => "Illicado + Carte Bancaire") -
     * or '' when the payment is not split or its legs can't be read.
     *
     * Worldline reports a split by the leg paid last (the card), so the transaction method alone loses
     * the gift card. The legs come from the core order details, which read each operation's payment.
     * Not cached: one sync can follow another for the same order within a request, each seeing more legs.
     *
     * @param \WC_Order $order
     * @param PaymentDetails $paymentDetails
     *
     * @return string
     */
    private static function splitMethod(\WC_Order $order, PaymentDetails $paymentDetails): string
    {
        $paidOperations = array_filter($paymentDetails->getOperations(), static function ($operation): bool {
            return in_array($operation->getStatus(), ['PENDING_CAPTURE', 'CAPTURED'], \true);
        });
        if (count($paidOperations) < 2) {
            return '';
        }
        try {
            $details = OrderAPI::get()->orders(self::STORE_ID)->getDetails(OrderService::merchantReference($order))->getOrderDetails();
        } catch (\Throwable $e) {
            return '';
        }
        if (!$details instanceof OrderDetails) {
            return '';
        }
        $methods = [];
        foreach ($details->getPayments() as $payment) {
            $name = trim((string) $payment->getPaymentMethodName());
            if ($name !== '' && !in_array($name, $methods, \true)) {
                $methods[] = $name;
            }
        }
        return count($methods) > 1 ? implode(self::SPLIT_SEPARATOR, $methods) : '';
    }
    /**
     * Fails the order for an expired session, if that session is the order's current payment.
     *
     * @param \WC_Order $order
     * @param PaymentTransaction $paymentTransaction
     */
    private static function closeExpiredSession(\WC_Order $order, PaymentTransaction $paymentTransaction): void
    {
        if (!self::isCurrentPayment($order, $paymentTransaction)) {
            return;
        }
        SessionTimeoutCloser::close($order, SessionTimeoutCloser::expiredNote());
    }
    /**
     * Whether Worldline cancelled the payment because the hosted checkout session expired.
     *
     * Both fields are checked: the core builds StatusError with the API error id and error code in
     * swapped positions, so the id currently arrives in getErrorCode().
     *
     * @param PaymentDetails $paymentDetails
     *
     * @return bool
     */
    private static function isSessionExpiry(PaymentDetails $paymentDetails): bool
    {
        $statusOutput = $paymentDetails->getStatusOutput();
        if ($statusOutput === null) {
            return \false;
        }
        foreach ($statusOutput->getErrors() as $error) {
            if (in_array(self::CANCELLED_BY_EXPIRATION, [$error->getId(), $error->getErrorCode()], \true)) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Whether the transaction is the order's latest Worldline payment (an older session expiring
     * must not close an order the shopper is paying again).
     *
     * @param \WC_Order $order
     * @param PaymentTransaction $paymentTransaction
     *
     * @return bool
     */
    private static function isCurrentPayment(\WC_Order $order, PaymentTransaction $paymentTransaction): bool
    {
        $current = (string) $order->get_meta(OrderService::META_PAYMENT_ID);
        $paymentId = $paymentTransaction->getPaymentId();
        if ($current === '' || $paymentId === null) {
            return \true;
        }
        try {
            return PaymentId::parse($current)->getTransactionId() === $paymentId->getTransactionId();
        } catch (\Throwable $e) {
            return \true;
        }
    }
    /**
     * Loads the WC_Order referenced by the transaction's merchant reference.
     *
     * @param PaymentTransaction $paymentTransaction
     *
     * @return \WC_Order|null
     */
    private function resolveOrder(PaymentTransaction $paymentTransaction): ?\WC_Order
    {
        return OrderService::forMerchantReference($paymentTransaction->getMerchantReference());
    }
}
