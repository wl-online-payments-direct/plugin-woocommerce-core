<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\IntegrationAPI\Response;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\ApiFacades\Response\Response;
/**
 * Class StateResponse
 *
 * @package OnlinePayments\Core\BusinessLogic\AdminConfig\ApiFacades\IntegrationAPI\Response
 */
class StateResponse extends Response
{
    /**
     * Connection string constant.
     */
    private const CONNECTION = 'connection';
    /**
     * Dashboard string constant.
     */
    private const PAYMENTS = 'payments';
    /**
     * String representation of state.
     *
     * @var string
     */
    private $state;
    /**
     * Whether the saved webhook credentials are valid. Null when they were not checked.
     *
     * @var bool|null
     */
    private $webhookCredentialsValid;
    /**
     * @param string $state
     * @param bool|null $webhookCredentialsValid
     */
    private function __construct(string $state, ?bool $webhookCredentialsValid = null)
    {
        $this->state = $state;
        $this->webhookCredentialsValid = $webhookCredentialsValid;
    }
    /**
     * Called when user is not logged in.
     *
     * @return StateResponse
     */
    public static function connection(): self
    {
        return new self(self::CONNECTION);
    }
    /**
     * Called when user is loggedIn.
     *
     * @param bool $webhookCredentialsValid
     *
     * @return StateResponse
     */
    public static function payments(bool $webhookCredentialsValid = \true): self
    {
        return new self(self::PAYMENTS, $webhookCredentialsValid);
    }
    /**
     *  Transforms state to array.
     *
     * @return array Array representation of state.
     */
    public function toArray(): array
    {
        $result = ['state' => $this->state];
        if ($this->webhookCredentialsValid !== null) {
            $result['webhookCredentialsValid'] = $this->webhookCredentialsValid;
        }
        return $result;
    }
}
