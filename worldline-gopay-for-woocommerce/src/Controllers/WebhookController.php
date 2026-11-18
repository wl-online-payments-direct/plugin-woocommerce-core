<?php

namespace WOP\OnlinePayments\Controllers;

use WOP\OnlinePayments\Core\Bootstrap\ApiFacades\PaymentProcessor\WebhookAPI\WebhookAPI;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Multistore\StoreContext;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\PaymentId;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Payment\Repositories\PaymentTransactionRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Migration\LegacySource;
defined('ABSPATH') || exit;
/**
 * Receives Worldline webhooks and hands them to the core for processing.
 *
 * Besides its own route, it takes over the webhook route of the previous (2.x) plugin of the same
 * brand (LegacySource::webhookRoute()). Webhook URLs are fixed when a payment is created, so all later
 * webhooks of a 2.x payment, including those of orders migrated to this plugin, arrive on that route.
 * Each webhook there is processed by exactly one plugin:
 * - 2.x active: its route handlers are wrapped (wrapLegacyRoute()). A webhook of a payment that has a
 *   PaymentTransaction in this plugin is processed here; any other goes to the original 2.x handler.
 * - 2.x not active: the route is registered with this controller's handler (registerLegacyRouteIfFree()).
 */
class WebhookController
{
    /** Single store. */
    private const STORE_ID = '1';
    /**
     * Registers this plugin's own webhook REST route. Hook on `rest_api_init`.
     */
    public function registerRoutes(): void
    {
        register_rest_route(ModuleHelper::getRestNamespace(), '/webhook', $this->routeArgs());
    }
    /**
     * Registers the 2.x webhook route with this controller's handler when no other plugin registered
     * it (2.x not active). Hook late on `rest_api_init`, after the 2.x plugin registered its routes.
     *
     * @param \WP_REST_Server|null $server The server passed by `rest_api_init`.
     */
    public function registerLegacyRouteIfFree($server = null): void
    {
        $server = $server instanceof \WP_REST_Server ? $server : rest_get_server();
        if (isset($server->get_routes()[LegacySource::webhookRoute()])) {
            return;
        }
        register_rest_route(LegacySource::webhookNamespace(), LegacySource::WEBHOOK_ROUTE, $this->routeArgs());
    }
    /**
     * `rest_endpoints` filter: when the 2.x webhook route is registered by another handler (2.x
     * active), wraps the callback of each of its handlers. The wrapper processes webhooks of payments
     * known to this plugin with handle() and passes all others, unchanged, to the original callback.
     *
     * @param mixed $endpoints Routes => handlers, as WordPress passes them.
     *
     * @return mixed
     */
    public function wrapLegacyRoute($endpoints)
    {
        $route = LegacySource::webhookRoute();
        if (!is_array($endpoints) || !isset($endpoints[$route]) || !is_array($endpoints[$route])) {
            return $endpoints;
        }
        foreach ($endpoints[$route] as $key => $handler) {
            if (!is_array($handler) || !isset($handler['callback']) || !is_callable($handler['callback']) || $this->isOwnCallback($handler['callback'])) {
                continue;
            }
            $original = $handler['callback'];
            $endpoints[$route][$key]['callback'] = function ($request) use ($original) {
                if ($this->isOwnPayment($request)) {
                    return $this->handle($request);
                }
                return call_user_func($original, $request);
            };
        }
        return $endpoints;
    }
    /**
     * Passes the webhook body and headers to the core for processing.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        try {
            WebhookAPI::get()->webhooks(self::STORE_ID)->process($request->get_body(), $this->collectHeaders($request));
        } catch (\Throwable $e) {
            // The core logs the details; return a generic message to avoid leaking internals.
            return new \WP_REST_Response(['error' => 'Webhook could not be processed.'], 400);
        }
        // The facade logs failures and returns an error response instead of throwing; the webhook is
        // still acknowledged with 200 (decided 2026-10-23, unchanged behaviour).
        return new \WP_REST_Response(null, 200);
    }
    /**
     * Whether the webhook belongs to a payment that has a PaymentTransaction in this plugin. The
     * payment id is read from `payment.id`, else `refund.id`, else `paymentLink.paymentId`. Any
     * failure counts as "not own", so the webhook goes to the 2.x handler.
     *
     * @param \WP_REST_Request $request
     *
     * @return bool
     */
    private function isOwnPayment($request): bool
    {
        try {
            $body = json_decode((string) $request->get_body(), \true);
            if (!is_array($body)) {
                return \false;
            }
            $id = $body['payment']['id'] ?? $body['refund']['id'] ?? $body['paymentLink']['paymentId'] ?? '';
            if (!is_string($id) || $id === '') {
                return \false;
            }
            $paymentId = PaymentId::parse($id);
            return (bool) StoreContext::doWithStore(self::STORE_ID, static function () use ($paymentId): bool {
                /** @var PaymentTransactionRepositoryInterface $repository */
                $repository = ServiceRegister::getService(PaymentTransactionRepositoryInterface::class);
                return $repository->get($paymentId) !== null;
            });
        } catch (\Throwable $e) {
            return \false;
        }
    }
    /**
     * Whether a route callback is this controller's handle() (the legacy route registered here).
     *
     * @param mixed $callback
     *
     * @return bool
     */
    private function isOwnCallback($callback): bool
    {
        return is_array($callback) && ($callback[0] ?? null) instanceof self;
    }
    /**
     * @return array<string, mixed>
     */
    private function routeArgs(): array
    {
        return ['methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => '__return_true'];
    }
    /**
     * Headers with their original dash-separated casing, as the SDK expects rather than WP's underscored keys.
     *
     * @param \WP_REST_Request $request
     *
     * @return array<string, string>
     */
    private function collectHeaders($request): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers) && !empty($headers)) {
                return $headers;
            }
        }
        $result = [];
        foreach ($request->get_headers() as $key => $value) {
            $name = str_replace(' ', '-', ucwords(str_replace('_', ' ', $key)));
            $result[$name] = is_array($value) ? implode(', ', $value) : $value;
        }
        return $result;
    }
}
