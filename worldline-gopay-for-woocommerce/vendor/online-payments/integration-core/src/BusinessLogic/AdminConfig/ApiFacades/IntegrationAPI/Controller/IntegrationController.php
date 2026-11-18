<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\IntegrationAPI\Controller;

use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\IntegrationAPI\Response\StateResponse;
use WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\Services\Connection\ConnectionService;
/**
 * Class IntegrationController
 *
 * @package OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\IntegrationAPI\Controller
 */
class IntegrationController
{
    private ConnectionService $connectionService;
    /**
     * @param ConnectionService $connectionService
     */
    public function __construct(ConnectionService $connectionService)
    {
        $this->connectionService = $connectionService;
    }
    /**
     * Returns the integration state. When the API credentials are valid, the saved webhook credentials
     * are checked as well; invalid ones are reported in the response but do not change the state.
     *
     * @return StateResponse
     */
    public function getState(): StateResponse
    {
        if (!$this->connectionService->isLoggedIn()) {
            return StateResponse::connection();
        }
        return StateResponse::payments($this->connectionService->areWebhookCredentialsValid());
    }
}
