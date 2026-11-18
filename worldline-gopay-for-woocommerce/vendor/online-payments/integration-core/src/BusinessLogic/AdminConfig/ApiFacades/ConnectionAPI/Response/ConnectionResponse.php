<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\ConnectionAPI\Response;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;
/**
 * Class ConnectionResponse
 *
 * @package OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\ConnectionAPI\Response
 */
class ConnectionResponse extends Response
{
    /**
     * Returned only after the API credentials and the webhook credentials were validated and the
     * connection was saved.
     *
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['webhookCredentialsValidated' => \true];
    }
}
