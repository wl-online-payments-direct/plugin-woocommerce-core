<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
defined('ABSPATH') || exit;
/**
 * Integration-state admin endpoint (`/admin/state`).
 */
class StateController extends BaseAdminController
{
    /**
     * Returns the integration state.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $result = AdminAPI::get()->integration($storeId)->getState();
        return $this->respond($result);
    }
}
