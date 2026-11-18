<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
defined('ABSPATH') || exit;
/**
 * Admin-config controller for the Monitoring tab.
 */
class MonitoringController extends BaseAdminController
{
    /** Download filename for the monitoring log export. */
    private const MONITORING_LOGS_FILENAME = 'online-payments-monitoring-logs.json';
    /** Download filename for the webhook log export. */
    private const WEBHOOK_LOGS_FILENAME = 'online-payments-webhook-logs.json';
    /** @var array<string, array{orderId: string, orderLink: string}> Per-request cache of resolved order references. */
    private array $orderLinkCache = [];
    /**
     * Returns the paginated monitoring logs.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function logs($request): \WP_REST_Response
    {
        [$page, $size, $term] = $this->paginationArgs($request);
        $result = AdminAPI::get()->monitoringLogs($this->getStoreId($request))->getMonitoringLogs($page, $size, $term);
        return $this->respondRaw($this->withOrderLinks($result->toArray(), 'monitoringLogs'));
    }
    /**
     * Returns the paginated webhook logs.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function webhookLogs($request): \WP_REST_Response
    {
        [$page, $size, $term] = $this->paginationArgs($request);
        $result = AdminAPI::get()->monitoringLogs($this->getStoreId($request))->getWebhookLogs($page, $size, $term);
        return $this->respondRaw($this->withOrderLinks($result->toArray(), 'webhookLogs'));
    }
    /**
     * Resolves each log's order reference to the WooCommerce order id and its admin edit URL.
     *
     * @param array $data
     * @param string $listKey
     *
     * @return array
     */
    private function withOrderLinks(array $data, string $listKey): array
    {
        if (empty($data[$listKey]) || !is_array($data[$listKey])) {
            return $data;
        }
        $data[$listKey] = array_map([$this, 'addOrderLink'], $data[$listKey]);
        return $data;
    }
    /**
     * Resolves a single log's order reference to the WooCommerce order id and its admin edit URL,
     * caching lookups so a repeated reference is resolved only once.
     *
     * @param array $log
     *
     * @return array
     */
    private function addOrderLink(array $log): array
    {
        $reference = isset($log['orderId']) ? (string) $log['orderId'] : '';
        if (!array_key_exists($reference, $this->orderLinkCache)) {
            $order = $reference !== '' && function_exists('wc_get_order') ? wc_get_order($reference) : \false;
            // Only WC_Order has get_edit_order_url(); a reference can resolve to a refund
            // (WC_Order_Refund), so guard against it to avoid a fatal that breaks the endpoint.
            $isOrder = $order instanceof \WC_Order;
            $this->orderLinkCache[$reference] = ['orderId' => $isOrder ? (string) $order->get_id() : $reference, 'orderLink' => $isOrder ? $order->get_edit_order_url() : ''];
        }
        $log['orderId'] = $this->orderLinkCache[$reference]['orderId'];
        $log['orderLink'] = $this->orderLinkCache[$reference]['orderLink'];
        return $log;
    }
    /**
     * Extracts the pagination and search parameters from the request.
     *
     * @param \WP_REST_Request $request
     *
     * @return array{0:int,1:int,2:string}
     */
    private function paginationArgs($request): array
    {
        $page = (int) $request->get_param('pageNumber');
        $size = (int) $request->get_param('pageSize');
        return [$page > 0 ? $page : 1, $size > 0 ? $size : 10, (string) ($request->get_param('searchTerm') ?? '')];
    }
    /**
     * Streams the monitoring logs as a JSON download.
     *
     * @param \WP_REST_Request $request
     */
    public function downloadLogs($request): void
    {
        $storeId = $this->getStoreId($request);
        $result = AdminAPI::get()->monitoringLogs($storeId)->downloadMonitoringLogs();
        $this->fileResponse(array_map([$this, 'addOrderLink'], $result->toArray()), self::MONITORING_LOGS_FILENAME);
    }
    /**
     * Streams the webhook logs as a JSON download.
     *
     * @param \WP_REST_Request $request
     */
    public function downloadWebhookLogs($request): void
    {
        $storeId = $this->getStoreId($request);
        $result = AdminAPI::get()->monitoringLogs($storeId)->downloadWebhookLogs();
        $this->fileResponse(array_map([$this, 'addOrderLink'], $result->toArray()), self::WEBHOOK_LOGS_FILENAME);
    }
    /**
     * Streams the logs as a downloadable JSON file, writing raw output and terminating the request.
     *
     * @param array $data
     * @param string $filename
     */
    private function fileResponse(array $data, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }
        echo wp_json_encode($data, \JSON_PRETTY_PRINT);
        exit;
    }
}
