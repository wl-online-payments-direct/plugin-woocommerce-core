<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
defined('ABSPATH') || exit;
/**
 * Admin-config controller exposing the store's order statuses.
 */
class OrderStatusesController extends BaseAdminController
{
    /**
     * Returns the store's order statuses.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        return $this->respond(AdminAPI::get()->store($storeId)->getStoreOrderStatuses());
    }
}
