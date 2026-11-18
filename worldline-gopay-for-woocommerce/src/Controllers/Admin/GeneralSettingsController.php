<?php

namespace WOP\OnlinePayments\Controllers\Admin;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\AdminConfig\AdminAPI\AdminAPI;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\LogSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\PayByLinkSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\PaymentSettingsRequest;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\GeneralSettingsAPI\Request\WebhookSettingsRequest;
use WOP\OnlinePayments\Services\SavedCardsSetting;
use WOP\OnlinePayments\Services\SessionTimeoutSetting;
defined('ABSPATH') || exit;
/**
 * Admin-config controller for the General Settings tab.
 */
class GeneralSettingsController extends BaseAdminController
{
    /**
     * Returns the general settings.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function get($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = AdminAPI::get()->generalSettings($storeId)->getGeneralSettings()->toArray();
        // Plugin-level settings the core does not hold; surfaced alongside the payment settings.
        if (isset($data['paymentSettings']) && is_array($data['paymentSettings'])) {
            $data['paymentSettings']['sessionTimeout'] = SessionTimeoutSetting::selected();
            $data['paymentSettings']['sessionTimeoutCustom'] = SessionTimeoutSetting::customMinutes();
            $data['paymentSettings']['enableSavedCards'] = SavedCardsSetting::isEnabled();
        }
        return $this->respondRaw($data);
    }
    /**
     * Saves the payment settings.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function savePayment($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = $this->getBody($request);
        $sessionTimeout = (string) ($data['sessionTimeout'] ?? SessionTimeoutSetting::selected());
        $customMinutes = $data['sessionTimeoutCustom'] ?? SessionTimeoutSetting::customMinutes();
        if ($sessionTimeout === SessionTimeoutSetting::CUSTOM && !SessionTimeoutSetting::isValidMinutes($customMinutes)) {
            return $this->respondRaw(['success' => \false, 'errorCode' => 'generalSettings.paymentSettings.sessionTimeoutCustom.error'], 400);
        }
        SessionTimeoutSetting::set($sessionTimeout, $customMinutes);
        if (array_key_exists('enableSavedCards', $data)) {
            SavedCardsSetting::set(filter_var($data['enableSavedCards'], \FILTER_VALIDATE_BOOLEAN));
        }
        return $this->respond(AdminAPI::get()->generalSettings($storeId)->savePaymentSettings(new PaymentSettingsRequest($data['paymentAction'] ?? null, $data['automaticCapture'] ?? null, $data['numberOfPaymentAttempts'] ?? null, $data['applySurcharge'] ?? null, $data['paymentCapturedStatus'] ?? '', $data['paymentErrorStatus'] ?? '', $data['paymentPendingStatus'] ?? '', $data['paymentAuthorizedStatus'] ?? '', $data['paymentCancelledStatus'] ?? '', $data['paymentRefundedStatus'] ?? '', $data['template'] ?? '', $data['paymentPartiallyRefundedStatus'] ?? '', $data['sendShoppingCart'] ?? \true, $data['displayConfirmationPage'] ?? \true)));
    }
    /**
     * Saves the log settings.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function saveLog($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = $this->getBody($request);
        return $this->respond(AdminAPI::get()->generalSettings($storeId)->saveLogSettings(new LogSettingsRequest($data['debugMode'] ?? null, $data['logDays'] ?? null)));
    }
    /**
     * Saves the pay-by-link settings.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function savePayByLink($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = $this->getBody($request);
        return $this->respond(AdminAPI::get()->generalSettings($storeId)->savePayByLinkSettings(new PayByLinkSettingsRequest($data['enabled'] ?? null, $data['title'] ?? '', $data['expirationTime'] ?? 7)));
    }
    /**
     * Saves the (global) webhook settings - the optional additional webhook URLs sent in the payment
     * feedbacks object alongside the store's own webhook URL.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function saveWebhook($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        $data = $this->getBody($request);
        $urls = $data['additionalWebhookUrls'] ?? [];
        return $this->respond(AdminAPI::get()->generalSettings($storeId)->saveWebhookSettings(new WebhookSettingsRequest(is_array($urls) ? $urls : [])));
    }
    /**
     * Disconnects the store from Worldline.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function disconnect($request): \WP_REST_Response
    {
        $storeId = $this->getStoreId($request);
        return $this->respond(AdminAPI::get()->generalSettings($storeId)->disconnect());
    }
}
