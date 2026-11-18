<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
defined('ABSPATH') || exit;
/**
 * Version-info admin endpoint (`/admin/version`).
 */
class VersionController extends BaseAdminController
{
    /**
     * Returns the plugin version info.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $result = AdminAPI::get()->version()->getVersionInfo();
        return $this->respond($result);
    }
}
