<?php

namespace WOP\OnlinePayments\Controllers;

use WOP\OnlinePayments\Checkout\PaymentReturnHandler;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Order\OrderService;
defined('ABSPATH') || exit;
/**
 * Status endpoint polled by the thank-you waiting box; the final poll (force=1) triggers a synchronous refresh.
 */
class PaymentStatusController
{
    /**
     * Registers the payment-status REST route.
     */
    public function registerRoutes(): void
    {
        register_rest_route(ModuleHelper::getRestNamespace(), '/payment-status', ['methods' => 'GET', 'callback' => [$this, 'handle'], 'permission_callback' => '__return_true', 'args' => ['orderId' => ['required' => \true], 'key' => ['required' => \true], 'force' => ['required' => \false]]]);
    }
    /**
     * Reports whether the order's payment reached a final state and where to go next.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        $orderId = (int) $request->get_param('orderId');
        $key = (string) $request->get_param('key');
        $force = (string) $request->get_param('force') === '1';
        $order = OrderService::get($orderId);
        if (!$order instanceof \WC_Order || !hash_equals($order->get_order_key(), $key)) {
            return new \WP_REST_Response(['done' => \false], 403);
        }
        if ($force) {
            PaymentReturnHandler::reconcile($order);
            $order = OrderService::get($orderId);
            if (!$order instanceof \WC_Order) {
                return new \WP_REST_Response(['done' => \false]);
            }
        }
        $outcome = PaymentReturnHandler::outcome($order, $force);
        if ($outcome === 'pending') {
            return new \WP_REST_Response(['done' => \false]);
        }
        if ($outcome === 'success') {
            return new \WP_REST_Response(['done' => \true, 'success' => \true]);
        }
        return new \WP_REST_Response(['done' => \true, 'success' => \false, 'redirect' => PaymentReturnHandler::retryUrl($order)]);
    }
}
