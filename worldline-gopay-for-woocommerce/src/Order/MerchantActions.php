<?php

namespace WOP\OnlinePayments\Order;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\Order\OrderAPI\OrderAPI;
use WOP\OnlinePayments\Core\Branding\Brand\ActiveBrandProviderInterface;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Cancel\CancelRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Capture\CaptureRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Amount;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Currency;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderAction;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Order\OrderDetails;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Refund\RefundRequest;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Merchant-initiated payment actions on a placed order: capture, cancel (void) and refund.
 */
class MerchantActions
{
    /** Single store. */
    private const STORE_ID = '1';
    /** Per-request cache of fetched order details, keyed by merchant reference. */
    private static array $detailsCache = [];
    /**
     * @return true|\WP_Error
     */
    public static function refund(\WC_Order $order, ?float $amount, string $reason = '')
    {
        $paymentId = self::paymentId($order);
        if ($paymentId === null) {
            return new \WP_Error('worldline_no_payment', sprintf(
                /* translators: %s: brand name. */
                esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            ));
        }
        $details = self::details($order);
        if ($details === null) {
            return new \WP_Error('worldline_refund_unavailable', sprintf(
                /* translators: %s: brand name. */
                esc_html__('%s payment details are currently unavailable. Please try again.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            ));
        }
        $paymentId = self::resolvePaymentId($order, $details);
        if ($paymentId === null) {
            /* translators: %s: brand name. */
            return new \WP_Error('worldline_no_payment', sprintf(esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName()));
        }
        if (count($details->getPayments()) > 1 && $amount !== null && $amount + 0.0001 < $details->getRefund()->getAvailable()->getPriceInCurrencyUnits()) {
            return new \WP_Error('worldline_split_refund', esc_html__('This order has more than one payment and can only be refunded in full.', 'worldline-gopay-for-woocommerce'));
        }
        $notRefundableMessage = $details->getCapture()->getDone()->getValue() > 0 ? esc_html__('This payment cannot be refunded yet because the capture has not been settled. Refunds become available once the payment platform finishes processing the capture.', 'worldline-gopay-for-woocommerce') : esc_html__('This payment cannot be refunded because nothing has been captured.', 'worldline-gopay-for-woocommerce');
        $error = self::amountError($order, $details->getRefund(), $amount, $notRefundableMessage);
        if ($error !== null) {
            return new \WP_Error('worldline_refund_invalid', $error);
        }
        try {
            $response = OrderAPI::get()->refund(self::STORE_ID)->handle(new RefundRequest($paymentId, self::amount($order, $amount), self::merchantReference($order), self::isWero($details, $paymentId) ? (string) self::postedRefundReason() : null));
        } catch (\Throwable $e) {
            return new \WP_Error('worldline_refund_failed', $e->getMessage());
        }
        $responseError = self::responseError($response);
        if ($responseError !== null) {
            return new \WP_Error('worldline_refund_failed', $responseError);
        }
        $order->add_order_note(sprintf(
            /* translators: %1$s: brand name, %2$s: refunded amount */
            esc_html__('%1$s: refund request for %2$s submitted.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName(),
            self::formatAmount($order, $amount)
        ));
        return \true;
    }
    public static function isWero(OrderDetails $details, ?PaymentId $paymentId = null): bool
    {
        foreach ($details->getPayments() as $payment) {
            if ($paymentId !== null && (string) $payment->getId() !== (string) $paymentId) {
                continue;
            }
            if ((string) $payment->getPaymentMethodId() === PaymentProductId::WERO) {
                return \true;
            }
        }
        return \false;
    }
    public static function refundReasonField(): string
    {
        return ModuleHelper::moduleKey('_refund_reason');
    }
    private static function postedRefundReason(): ?string
    {
        $field = self::refundReasonField();
        return isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : null;
    }
    /**
     * @return array{success: bool, message: string}
     */
    public static function capture(\WC_Order $order, ?float $amount): array
    {
        $paymentId = self::paymentId($order);
        if ($paymentId === null) {
            return ['success' => \false, 'message' => sprintf(
                /* translators: %s: brand name. */
                esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            )];
        }
        $details = self::details($order);
        if ($details === null) {
            return ['success' => \false, 'message' => sprintf(
                /* translators: %s: brand name. */
                esc_html__('%s payment details are currently unavailable. Please try again.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            )];
        }
        $paymentId = self::resolvePaymentId($order, $details);
        if ($paymentId === null) {
            /* translators: %s: brand name. */
            return ['success' => \false, 'message' => sprintf(esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName())];
        }
        $error = self::amountError($order, $details->getCapture(), $amount, esc_html__('This payment cannot be captured.', 'worldline-gopay-for-woocommerce'));
        if ($error !== null) {
            return ['success' => \false, 'message' => $error];
        }
        if ($amount === null) {
            $available = $details->getCapture()->getAvailable();
            if ($available !== null) {
                $amount = $available->getPriceInCurrencyUnits();
            }
        }
        try {
            $response = OrderAPI::get()->capture(self::STORE_ID)->handle(new CaptureRequest($paymentId, $amount !== null ? self::amount($order, $amount) : null, self::merchantReference($order)));
        } catch (\Throwable $e) {
            return ['success' => \false, 'message' => $e->getMessage()];
        }
        $responseError = self::responseError($response);
        if ($responseError !== null) {
            return ['success' => \false, 'message' => $responseError];
        }
        $order->add_order_note(sprintf(
            /* translators: %1$s: brand name, %2$s: captured amount */
            esc_html__('%1$s: capture request for %2$s submitted.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName(),
            self::formatAmount($order, $amount)
        ));
        return ['success' => \true, 'message' => esc_html__('Capture submitted.', 'worldline-gopay-for-woocommerce')];
    }
    /**
     * @return array{success: bool, message: string}
     */
    public static function cancel(\WC_Order $order, ?float $amount): array
    {
        $paymentId = self::paymentId($order);
        if ($paymentId === null) {
            return ['success' => \false, 'message' => sprintf(
                /* translators: %s: brand name. */
                esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            )];
        }
        $details = self::details($order);
        if ($details === null) {
            return ['success' => \false, 'message' => sprintf(
                /* translators: %s: brand name. */
                esc_html__('%s payment details are currently unavailable. Please try again.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            )];
        }
        $paymentId = self::resolvePaymentId($order, $details);
        if ($paymentId === null) {
            /* translators: %s: brand name. */
            return ['success' => \false, 'message' => sprintf(esc_html__('No %s payment found for this order.', 'worldline-gopay-for-woocommerce'), ModuleHelper::getModuleConfig()->getName())];
        }
        $error = self::amountError($order, $details->getCancel(), $amount, esc_html__('This payment cannot be cancelled.', 'worldline-gopay-for-woocommerce'));
        if ($error !== null) {
            return ['success' => \false, 'message' => $error];
        }
        try {
            $response = OrderAPI::get()->cancel(self::STORE_ID)->handle(new CancelRequest($paymentId, self::amount($order, $amount), self::merchantReference($order)));
        } catch (\Throwable $e) {
            return ['success' => \false, 'message' => $e->getMessage()];
        }
        $responseError = self::responseError($response);
        if ($responseError !== null) {
            return ['success' => \false, 'message' => $responseError];
        }
        $order->add_order_note(sprintf(
            /* translators: %1$s: brand name, %2$s: cancelled amount */
            esc_html__('%1$s: cancellation request for %2$s submitted.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName(),
            self::formatAmount($order, $amount)
        ));
        return ['success' => \true, 'message' => esc_html__('Cancellation submitted.', 'worldline-gopay-for-woocommerce')];
    }
    /**
     * Error message from an OrderAPI action response, or null when it succeeded. The capture/cancel/
     * refund facades return a TranslatableErrorResponse (isSuccessful() === false) instead of throwing
     * when the payment platform rejects the action, so callers must inspect the response and not just
     * catch exceptions - otherwise a rejected action would be reported to the merchant as submitted.
     *
     * @param mixed $response
     *
     * @return string|null
     */
    private static function responseError($response): ?string
    {
        if (!is_object($response) || !method_exists($response, 'isSuccessful') || $response->isSuccessful()) {
            return null;
        }
        $data = method_exists($response, 'toArray') ? $response->toArray() : [];
        if (is_array($data) && isset($data['errorMessage']) && is_string($data['errorMessage']) && $data['errorMessage'] !== '') {
            return $data['errorMessage'];
        }
        return sprintf(
            /* translators: %s: brand name. */
            esc_html__('%s could not process the request. Please try again or check the transaction in the merchant portal.', 'worldline-gopay-for-woocommerce'),
            ModuleHelper::getModuleConfig()->getName()
        );
    }
    /** Live order details from Worldline (status, amounts, per-payment, action availability). */
    public static function details(\WC_Order $order): ?OrderDetails
    {
        $reference = self::merchantReference($order);
        if (array_key_exists($reference, self::$detailsCache)) {
            return self::$detailsCache[$reference];
        }
        try {
            $details = OrderAPI::get()->orders(self::STORE_ID)->getDetails($reference)->getOrderDetails();
        } catch (\Throwable $e) {
            $details = null;
        }
        if (!$details instanceof OrderDetails) {
            $details = null;
        }
        return self::$detailsCache[$reference] = $details;
    }
    /**
     * Whether Worldline currently has a refundable (captured, not fully refunded) amount, so the
     * refund action should be offered. False when nothing is captured, the order is fully refunded,
     * or the details cannot be fetched.
     *
     * @param \WC_Order $order
     *
     * @return bool
     */
    public static function isRefundable(\WC_Order $order): bool
    {
        $details = self::details($order);
        if ($details === null) {
            return \false;
        }
        // Follow Worldline's refundability signal (the core exposes it as the refund action's
        // available amount, which is 0 whenever the API reports the payment as not refundable).
        $available = $details->getRefund()->getAvailable();
        return $available !== null && $available->getValue() > 0;
    }
    /** Deep link to the payment in the Worldline back office, or null if it can't be built. */
    public static function transactionUrl(\WC_Order $order): ?string
    {
        $paymentId = self::paymentId($order);
        if ($paymentId === null) {
            return null;
        }
        try {
            $base = StoreContext::doWithStore(self::STORE_ID, static function (): string {
                return ServiceRegister::getService(ActiveBrandProviderInterface::class)->getTransactionUrl();
            });
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($base) && $base !== '' ? $base . $paymentId->getTransactionId() : null;
    }
    public static function paymentId(\WC_Order $order): ?PaymentId
    {
        return OrderService::paymentId($order);
    }
    /**
     * The order's Worldline payment id, recovering it from the live order details when the order
     * meta is missing. Pay by Link orders never store the payment id at link creation, so without
     * this the merchant actions (refund/capture/cancel) would not find a payment. When recovered it
     * is persisted to the order meta so the Orders list reference column and later lookups work.
     *
     * @param \WC_Order $order
     * @param OrderDetails $details
     *
     * @return PaymentId|null
     */
    private static function resolvePaymentId(\WC_Order $order, OrderDetails $details): ?PaymentId
    {
        $paymentId = self::paymentId($order);
        if ($paymentId !== null) {
            return $paymentId;
        }
        foreach ($details->getPayments() as $payment) {
            $id = (string) $payment->getId();
            if ($id === '') {
                continue;
            }
            try {
                $parsed = PaymentId::parse($id);
            } catch (\Throwable $e) {
                continue;
            }
            $order->update_meta_data(OrderService::META_PAYMENT_ID, $id);
            $order->save();
            return $parsed;
        }
        return null;
    }
    /**
     * Validates a requested amount against what Worldline still allows, returning an error message
     * or null when acceptable.
     */
    private static function amountError(\WC_Order $order, OrderAction $action, ?float $amount, string $notPossibleMessage): ?string
    {
        if (!$action->isPossible()) {
            return $notPossibleMessage;
        }
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $requested = round($amount !== null ? $amount : (float) $order->get_total(), $decimals);
        $available = $action->getAvailable()->getPriceInCurrencyUnits();
        if ($requested > $available + 0.0001) {
            return sprintf(
                /* translators: %s: maximum allowed amount */
                esc_html__('The amount exceeds the available amount (%s).', 'worldline-gopay-for-woocommerce'),
                self::formatAmount($order, $available)
            );
        }
        return null;
    }
    private static function amount(\WC_Order $order, ?float $amount): Amount
    {
        $value = $amount !== null ? $amount : (float) $order->get_total();
        return Amount::fromFloat($value, Currency::fromIsoCode($order->get_currency()));
    }
    private static function merchantReference(\WC_Order $order): string
    {
        return OrderService::merchantReference($order);
    }
    /** Plain-text formatted amount for an order note (full order total when $amount is null). */
    private static function formatAmount(\WC_Order $order, ?float $amount): string
    {
        $value = $amount !== null ? $amount : (float) $order->get_total();
        return html_entity_decode(wp_strip_all_tags(wc_price($value, ['currency' => $order->get_currency()])));
    }
}
