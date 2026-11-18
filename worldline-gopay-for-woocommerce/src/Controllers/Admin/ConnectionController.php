<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\ConnectionAPI\Request\ConnectionRequest;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Services\Integration\ShopPaymentService;
defined('ABSPATH') || exit;
/**
 * Connection-config admin endpoints (`/admin/connection`).
 */
class ConnectionController extends BaseAdminController
{
    /**
     * Returns the stored connection configuration.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $result = AdminAPI::get()->connection($storeId)->getConnectionConfig();
        return $this->respond($result);
    }
    /**
     * Saves the connection credentials and, on success, syncs gateway availability.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function submit($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = $this->getBody($request);
        $connectionRequest = new ConnectionRequest($data['mode'] ?? '', $data['testData']['pspid'] ?? '', $data['testData']['apiKey'] ?? '', $data['testData']['apiSecret'] ?? '', $data['testData']['webhooksKey'] ?? '', $data['testData']['webhooksSecret'] ?? '', $data['liveData']['pspid'] ?? '', $data['liveData']['apiKey'] ?? '', $data['liveData']['apiSecret'] ?? '', $data['liveData']['webhooksKey'] ?? '', $data['liveData']['webhooksSecret'] ?? '');
        $result = AdminAPI::get()->connection($storeId)->connect($connectionRequest);
        if ($result->isSuccessful()) {
            StoreContext::doWithStore($storeId, static function (): void {
                (new ShopPaymentService())->syncGatewayAvailability();
            });
        }
        return $this->respond($result);
    }
}
