<?php

namespace WOP\OnlinePayments\Admin;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Registers the admin-config REST routes the bundled SPA talks to, each as a
 * `[HTTP method, path, controller, action]` row dispatched to a controller under
 * `OnlinePayments\Controllers\Admin`.
 */
class AdminRestRouter
{
    private const METHOD_ID_PATTERN = '[\w-]+';
    /**
     * The route table.
     *
     * @return array<int, array{0:string,1:string,2:string,3:string}>
     */
    private function routes(): array
    {
        $methodId = '(?P<methodId>' . self::METHOD_ID_PATTERN . ')';
        return [['GET', '/admin/connection', 'ConnectionController', 'get'], ['POST', '/admin/connection', 'ConnectionController', 'submit'], ['GET', '/admin/state', 'StateController', 'get'], ['GET', '/admin/version', 'VersionController', 'get'], ['GET', '/admin/payments', 'PaymentsController', 'list'], ['POST', '/admin/payments/enable', 'PaymentsController', 'enable'], ['GET', '/admin/payments/' . $methodId, 'PaymentsController', 'get'], ['POST', '/admin/payments/' . $methodId, 'PaymentsController', 'save'], ['GET', '/admin/languages', 'LanguageController', 'get'], ['GET', '/admin/general-settings', 'GeneralSettingsController', 'get'], ['POST', '/admin/general-settings/payment', 'GeneralSettingsController', 'savePayment'], ['POST', '/admin/general-settings/log', 'GeneralSettingsController', 'saveLog'], ['POST', '/admin/general-settings/paybylink', 'GeneralSettingsController', 'savePayByLink'], ['POST', '/admin/general-settings/webhooks', 'GeneralSettingsController', 'saveWebhook'], ['GET', '/admin/disconnect', 'GeneralSettingsController', 'disconnect'], ['GET', '/admin/order-statuses', 'OrderStatusesController', 'get'], ['GET', '/admin/monitoring/logs', 'MonitoringController', 'logs'], ['GET', '/admin/monitoring/webhook-logs', 'MonitoringController', 'webhookLogs'], ['GET', '/admin/monitoring/logs/download', 'MonitoringController', 'downloadLogs'], ['GET', '/admin/monitoring/webhook-logs/download', 'MonitoringController', 'downloadWebhookLogs']];
    }
    /**
     * Registers every route from the table with the WP REST server.
     */
    public function registerRoutes(): void
    {
        $namespace = ModuleHelper::getRestNamespace();
        foreach ($this->routes() as [$httpMethod, $path, $controller, $action]) {
            register_rest_route($namespace, $path, ['methods' => $httpMethod, 'callback' => $this->makeCallback($controller, $action), 'permission_callback' => $this->makePermissionCallback($controller)]);
        }
    }
    /**
     * Fully-qualified controller class for a short controller name, derived from this class's own
     * namespace so it survives php-scoper prefixing.
     *
     * @param string $controller
     *
     * @return string
     */
    private function controllerClass(string $controller): string
    {
        $base = substr(__NAMESPACE__, 0, -strlen('Admin'));
        // {prefix}\OnlinePayments\
        return $base . 'Controllers\Admin\\' . $controller;
    }
    private function makeCallback(string $controller, string $action): callable
    {
        $class = $this->controllerClass($controller);
        return static function ($request) use ($class, $action, $controller) {
            if (class_exists($class) && method_exists($class, $action)) {
                return (new $class())->{$action}($request);
            }
            return new \WP_REST_Response(['success' => \false, 'message' => sprintf('%s::%s is not implemented yet.', $controller, $action)], 501);
        };
    }
    /**
     * Delegates to the controller's capability + nonce check, falling back to the
     * `manage_woocommerce` capability.
     *
     * @param string $controller
     *
     * @return callable
     */
    private function makePermissionCallback(string $controller): callable
    {
        $class = $this->controllerClass($controller);
        return static function ($request) use ($class) {
            if (class_exists($class) && method_exists($class, 'checkPermission')) {
                return (new $class())->checkPermission($request);
            }
            return current_user_can('manage_woocommerce');
        };
    }
}
