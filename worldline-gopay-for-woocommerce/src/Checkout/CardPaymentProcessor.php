<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedTokenization\PaymentRequest;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * Pays an order with a card tokenized on the checkout page (iframe flow) by submitting
 * the hosted tokenization id to the core.
 */
class CardPaymentProcessor
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Creates the payment and returns the WooCommerce process_payment result.
     *
     * @param \WC_Order $order
     * @param string $hostedTokenizationId
     *
     * @return array{result: string, redirect?: string}
     */
    public function process(\WC_Order $order, string $hostedTokenizationId): array
    {
        try {
            $response = CheckoutAPI::get()->hostedTokenization(self::STORE_ID)->pay(new PaymentRequest($hostedTokenizationId, new WooCommerceCartProvider($order), $order->get_checkout_order_received_url()));
            if (!$response->isSuccessful()) {
                throw new \RuntimeException('Card payment was rejected.');
            }
            $transaction = $response->getPaymentTransaction();
            $order->update_meta_data('_worldline_payment_id', (string) $transaction->getPaymentId());
            $order->update_meta_data('_worldline_return_hmac', (string) $transaction->getReturnHmac());
            PaymentReturnHandler::recordPaymentStart($order);
            OrderService::recordSessionStart($order);
            $order->update_meta_data(OrderService::META_MERCHANT_REFERENCE, (string) ($transaction->getMerchantReference() ?: OrderService::merchantReferenceFor((int) $order->get_id())));
            $order->update_status(OrderStatuses::pending(), sprintf(
                /* translators: %s: payment gateway name. */
                esc_html__('Awaiting %s payment.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            ));
            $order->save();
            return ['result' => 'success', 'redirect' => $response->isRedirectRequired() ? $response->getRedirectUrl() : $order->get_checkout_order_received_url()];
        } catch (\Throwable $e) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(esc_html__('The payment could not be processed. Please try again.', 'worldline-gopay-for-woocommerce'), 'error');
            }
            return ['result' => 'failure'];
        }
    }
}
