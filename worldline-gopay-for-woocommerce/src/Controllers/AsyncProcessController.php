<?php

namespace WOP\OnlinePayments\Controllers;

use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\AsyncProcessService;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * REST endpoint the core calls to run queued tasks; closes the connection, then runs the process server-side.
 */
class AsyncProcessController
{
    /**
     * Registers the async-process REST route.
     */
    public function registerRoutes(): void
    {
        register_rest_route(ModuleHelper::getRestNamespace(), '/async-process', [
            'methods' => 'GET',
            'callback' => [$this, 'run'],
            // Public: the guid is an unguessable process identifier.
            'permission_callback' => '__return_true',
            'args' => ['guid' => ['required' => \true, 'type' => 'string']],
        ]);
    }
    /**
     * Acknowledges the caller, then runs the queued process identified by `guid`.
     *
     * @param \WP_REST_Request $request
     */
    public function run($request): void
    {
        $guid = trim((string) $request->get_param('guid'));
        $this->respondOK();
        if ($guid !== '' && $guid !== 'auto-configure') {
            try {
                /** @var AsyncProcessService $asyncProcessService */
                $asyncProcessService = ServiceRegister::getService(AsyncProcessService::CLASS_NAME);
                $asyncProcessService->runProcess($guid);
            } catch (\Throwable $e) {
            }
        }
        // The response was already sent in respondOK(); stop the REST stack from emitting more.
        exit;
    }
    /**
     * Sends a minimal 200 response and closes the connection while the script keeps running.
     */
    protected function respondOK(): void
    {
        if (!headers_sent()) {
            if (function_exists('status_header')) {
                status_header(200);
            }
            header('Content-Type: application/json; charset=utf-8');
        }
        echo function_exists('wp_json_encode') ? wp_json_encode(['success' => \true]) : '{"success":true}';
        if (function_exists('fastcgi_finish_request') && is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request') && is_callable('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        ignore_user_abort(\true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
