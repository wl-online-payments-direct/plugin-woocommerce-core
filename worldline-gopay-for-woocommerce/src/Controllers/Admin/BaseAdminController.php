<?php

namespace WOP\OnlinePayments\Controllers\Admin;

defined('ABSPATH') || exit;
/**
 * Shared WP REST plumbing for the admin-config controllers.
 */
abstract class BaseAdminController
{
    /** WooCommerce is single-store, so a constant store id is passed everywhere. */
    public const DEFAULT_STORE_ID = '1';
    /**
     * Capability + REST nonce check used as the permission callback for all admin routes.
     *
     * @param \WP_REST_Request $request
     *
     * @return bool|\WP_Error
     */
    public function checkPermission($request)
    {
        if (!current_user_can('manage_woocommerce')) {
            return new \WP_Error('rest_forbidden', __('You are not allowed to access this resource.', 'worldline-gopay-for-woocommerce'), ['status' => 403]);
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
        }
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('rest_cookie_invalid_nonce', __('Invalid or missing security token.', 'worldline-gopay-for-woocommerce'), ['status' => 403]);
        }
        return \true;
    }
    /**
     * Store id from the request, defaulting to the single-store id.
     *
     * @param \WP_REST_Request $request
     *
     * @return string
     */
    protected function getStoreId($request): string
    {
        $storeId = $request->get_param('storeId');
        return $storeId === null || $storeId === '' ? self::DEFAULT_STORE_ID : (string) $storeId;
    }
    /**
     * Payment method id from the route.
     *
     * @param \WP_REST_Request $request
     *
     * @return string
     */
    protected function getMethodId($request): string
    {
        return (string) $request->get_param('methodId');
    }
    /**
     * Returns the request body as an associative array, falling back to body params for form posts.
     *
     * @param \WP_REST_Request $request
     *
     * @return array
     */
    protected function getBody($request): array
    {
        $body = $request->get_json_params();
        if (empty($body)) {
            $body = $request->get_body_params();
        }
        return is_array($body) ? $body : [];
    }
    /**
     * Wraps a core response object in a WP REST response.
     *
     * @param object $result A response object exposing a `toArray()` method.
     *
     * @return \WP_REST_Response
     */
    protected function respond($result): \WP_REST_Response
    {
        return new \WP_REST_Response($result->toArray());
    }
    /**
     * Wraps already-serialized data in a WP REST response.
     *
     * @param mixed $data
     * @param int $status
     *
     * @return \WP_REST_Response
     */
    protected function respondRaw($data, int $status = 200): \WP_REST_Response
    {
        return new \WP_REST_Response($data, $status);
    }
    /**
     * Builds an error response with the given status.
     *
     * @param mixed $data
     * @param int $status
     *
     * @return \WP_REST_Response
     */
    protected function respondError($data = null, int $status = 400): \WP_REST_Response
    {
        return new \WP_REST_Response($data, $status);
    }
}
