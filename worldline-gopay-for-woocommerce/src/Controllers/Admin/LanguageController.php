<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
defined('ABSPATH') || exit;
/**
 * Admin-config controller exposing the available admin-UI languages.
 */
class LanguageController extends BaseAdminController
{
    /**
     * Returns the available languages.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        return $this->respond(AdminAPI::get()->language($storeId)->getLanguages());
    }
}
