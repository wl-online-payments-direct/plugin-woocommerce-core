<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\CheckoutAPI\CheckoutAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\HostedCheckout\HostedCheckoutSessionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentProductId;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Services\SoftDescriptorSetting;
use WOP\OnlinePayments\Order\OrderService;
use WOP\OnlinePayments\Order\OrderStatuses;
defined('ABSPATH') || exit;
/**
 * Creates a Worldline hosted-checkout session for an order and returns the WooCommerce
 * process_payment result; a null product id means the generic payment page.
 */
class HostedCheckoutProcessor
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Creates the session and returns the WooCommerce process_payment result.
     *
     * @param \WC_Order $order
     * @param PaymentProductId|null $productId
     * @param string|null $tokenId Saved card token to pay with.
     *
     * @return array{result: string, redirect?: string}
     */
    public function process(\WC_Order $order, ?PaymentProductId $productId, ?string $tokenId = null): array
    {
        try {
            $descriptor = $productId !== null ? SoftDescriptorSetting::forProduct((string) $productId) : '';
            $request = new HostedCheckoutSessionRequest(new WooCommerceCartProvider($order, $descriptor !== '' ? $descriptor : null), $order->get_checkout_order_received_url(), $productId, $tokenId);
            $response = CheckoutAPI::get()->hostedCheckout(self::STORE_ID)->createSession($request);
            $transaction = $response->toArray()['paymentTransaction'] ?? [];
            $order->update_meta_data('_worldline_payment_id', (string) ($transaction['paymentId'] ?? ''));
            $order->update_meta_data('_worldline_return_hmac', (string) $response->getReturnHmac());
            PaymentReturnHandler::recordPaymentStart($order);
            OrderService::recordSessionStart($order);
            $order->update_meta_data(OrderService::META_MERCHANT_REFERENCE, (string) ($transaction['merchantReference'] ?? OrderService::merchantReferenceFor((int) $order->get_id())));
            $order->update_status(OrderStatuses::pending(), sprintf(
                /* translators: %s: payment gateway name. */
                esc_html__('Awaiting %s payment.', 'worldline-gopay-for-woocommerce'),
                ModuleHelper::getModuleConfig()->getName()
            ));
            $order->save();
            return ['result' => 'success', 'redirect' => $response->getRedirectUrl()];
        } catch (\Throwable $e) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(esc_html__('The payment could not be processed. Please try again.', 'worldline-gopay-for-woocommerce'), 'error');
            }
            return ['result' => 'failure'];
        }
    }
}
