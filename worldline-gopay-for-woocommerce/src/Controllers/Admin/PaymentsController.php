<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\PaymentAPI\Request\PaymentMethodRequest;
use WOP\OnlinePayments\Services\PaymentButtonTitleSetting;
use WOP\OnlinePayments\Services\SoftDescriptorSetting;
defined('ABSPATH') || exit;
/**
 * Admin-config controller for the Payments tab.
 */
class PaymentsController extends BaseAdminController
{
    /**
     * Returns all available payment methods.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function list($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        return $this->respond(AdminAPI::get()->payment($storeId)->list());
    }
    /**
     * Enables or disables a payment method.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function enable($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $body = $this->getBody($request);
        return $this->respond(AdminAPI::get()->payment($storeId)->enable($body['paymentProductId'] ?? '', filter_var($body['enabled'] ?? \false, \FILTER_VALIDATE_BOOLEAN)));
    }
    /**
     * Returns a single payment method's configuration.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $methodId = $this->getMethodId($request);
        $data = AdminAPI::get()->payment($storeId)->getPaymentMethod($methodId)->toArray();
        // Sofinco/Linxo carry a plugin-stored per-method soft descriptor; surface it for the config form.
        if (in_array($methodId, ['5131', '5003'], \true)) {
            if (!isset($data['additionalData']) || !is_array($data['additionalData'])) {
                $data['additionalData'] = [];
            }
            $data['additionalData']['descriptor'] = SoftDescriptorSetting::get($methodId);
        }
        if (!isset($data['additionalData']) || !is_array($data['additionalData'])) {
            $data['additionalData'] = [];
        }
        $data['additionalData']['buttonTitle'] = PaymentButtonTitleSetting::get($methodId);
        return $this->respondRaw($data);
    }
    /**
     * Saves a payment method's configuration.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function save($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $body = $this->getBody($request);
        $logo = $this->storeUploadedLogo($request);
        if ($logo === null || $logo === '') {
            $logo = $this->additionalLogo($body);
        }
        if ($logo === '') {
            $logo = $this->existingLogo($storeId, (string) ($body['paymentProductId'] ?? ''));
        }
        if ($logo !== '') {
            $body['logo'] = $logo;
        }
        // The soft descriptor is a plugin-level per-method setting (Sofinco/Linxo), not part of the core config.
        $productId = (string) ($body['paymentProductId'] ?? '');
        if (in_array($productId, ['5131', '5003'], \true)) {
            $additional = $body['additionalData'] ?? null;
            if (is_string($additional)) {
                $additional = json_decode($additional, \true);
            }
            $descriptor = is_array($additional) ? (string) ($additional['descriptor'] ?? '') : '';
            SoftDescriptorSetting::set($productId, $descriptor);
        }
        // The button title is a plugin-level per-method setting applied to the checkout pay button.
        $rawAdditional = $body['additionalData'] ?? null;
        if (is_string($rawAdditional)) {
            $rawAdditional = json_decode($rawAdditional, \true);
        }
        $buttonTitle = is_array($rawAdditional) ? (string) ($rawAdditional['buttonTitle'] ?? '') : '';
        PaymentButtonTitleSetting::set($productId, $buttonTitle);
        return $this->respond(AdminAPI::get()->payment($storeId)->save($this->createPaymentMethodRequest($body)));
    }
    /**
     * The logo carried in the request's additionalData payload (the admin-ui's real current logo),
     * or '' when absent or not an http(s) URL. additionalData arrives as a JSON string or an
     * already-decoded array.
     *
     * @param array $body
     *
     * @return string
     */
    private function additionalLogo(array $body): string
    {
        $additional = $body['additionalData'] ?? null;
        if (is_string($additional)) {
            $additional = json_decode($additional, \true);
        }
        if (!is_array($additional) || !isset($additional['logo']) || !is_string($additional['logo'])) {
            return '';
        }
        return esc_url_raw(trim($additional['logo']), ['http', 'https']);
    }
    /**
     * The logo URL currently stored for a payment method, or '' when there is none or it can't be
     * read. Used to preserve an uploaded logo when other fields are saved without re-uploading it.
     *
     * @param string $storeId
     * @param string $paymentProductId
     *
     * @return string
     */
    private function existingLogo(string $storeId, string $paymentProductId): string
    {
        if ($paymentProductId === '') {
            return '';
        }
        try {
            $current = AdminAPI::get()->payment($storeId)->getPaymentMethod($paymentProductId)->toArray();
        } catch (\Throwable $e) {
            return '';
        }
        if (isset($current['logo']) && is_string($current['logo'])) {
            return $current['logo'];
        }
        if (isset($current['additionalData']['logo']) && is_string($current['additionalData']['logo'])) {
            return $current['additionalData']['logo'];
        }
        return '';
    }
    /**
     * Persists an uploaded logo file to the WordPress media uploads and returns its public URL, or
     * null when no (valid) file was uploaded. Mirrors the admin-ui field, which only allows JPEG/PNG.
     *
     * @param \WP_REST_Request $request
     *
     * @return string|null
     */
    private function storeUploadedLogo($request): ?string
    {
        $files = method_exists($request, 'get_file_params') ? $request->get_file_params() : [];
        $file = $files['logo'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !empty($file['error'])) {
            return null;
        }
        $allowed = ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png'];
        $check = wp_check_filetype_and_ext($file['tmp_name'], (string) ($file['name'] ?? ''), $allowed);
        if (empty($check['type']) || !in_array($check['type'], $allowed, \true)) {
            return null;
        }
        if (!function_exists('wp_handle_upload')) {
            require_once \ABSPATH . 'wp-admin/includes/file.php';
        }
        $result = wp_handle_upload($file, ['test_form' => \false, 'mimes' => $allowed]);
        if (!is_array($result) || !empty($result['error']) || empty($result['url'])) {
            return null;
        }
        return (string) $result['url'];
    }
    /**
     * Builds the core {@see PaymentMethodRequest} from the request body.
     *
     * @param array $requestData
     *
     * @return PaymentMethodRequest
     */
    private function createPaymentMethodRequest(array $requestData): PaymentMethodRequest
    {
        $translations = isset($requestData['name']) ? is_array($requestData['name']) ? $requestData['name'] : json_decode($requestData['name'], \true) : [];
        $names = [];
        foreach ((array) $translations as $translation) {
            $names[$translation['locale']] = $translation['value'];
        }
        $additionalData = [];
        $titles = [];
        $enableGroupCards = null;
        $instantPayment = null;
        $recurrenceType = null;
        $signatureType = null;
        $paymentProductId = null;
        $sessionTimeout = null;
        $enable3ds = null;
        $enforceStrongAuthentication = null;
        $enable3dsExemption = null;
        $exemptionType = null;
        $exemptionLimit = null;
        $flowType = null;
        $authorizationMode = null;
        $paymentOption = null;
        if (isset($requestData['additionalData'])) {
            $additionalData = is_array($requestData['additionalData']) ? $requestData['additionalData'] : json_decode($requestData['additionalData'], \true);
            $instantPayment = $additionalData['instantPayment'] ?? null;
            // One-off payments only: recurrence is hardcoded to UNIQUE, ignoring any submitted value.
            $recurrenceType = 'unique';
            $signatureType = $additionalData['signatureType'] ?? null;
            $paymentProductId = $additionalData['paymentProductId'] ?? null;
            $sessionTimeout = $additionalData['sessionTimeout'] ?? null;
            $enableGroupCards = $additionalData['enableGroupCards'] ?? null;
            $enable3ds = $additionalData['enable3ds'] ?? null;
            $enforceStrongAuthentication = $additionalData['enforceStrongAuthentication'] ?? null;
            $enable3dsExemption = $additionalData['enable3dsExemption'] ?? null;
            $exemptionType = $additionalData['exemptionType'] ?? null;
            $exemptionLimit = $additionalData['exemptionLimit'] ?? null;
            $flowType = $additionalData['flowType'] ?? null;
            $authorizationMode = $additionalData['authorizationMode'] ?? null;
            $paymentOption = $additionalData['paymentOption'] ?? null;
            $vaultTitles = $additionalData['vaultTitleCollection'] ?? [];
            foreach ($vaultTitles as $vaultTitle) {
                $titles[$vaultTitle['locale']] = $vaultTitle['value'];
            }
        }
        $logo = $requestData['logo'] ?? $additionalData['logo'] ?? '';
        return new PaymentMethodRequest($requestData['paymentProductId'], $names, filter_var($requestData['enabled'], \FILTER_VALIDATE_BOOLEAN), $requestData['template'] ?? '', $requestData['paymentAction'] ?? null, $titles, $logo, $enableGroupCards, $paymentOption, $sessionTimeout, $paymentProductId, $recurrenceType, $signatureType, $instantPayment, $enable3ds, $enforceStrongAuthentication, $enable3dsExemption, $exemptionType, $exemptionLimit, $flowType, $authorizationMode);
    }
}
