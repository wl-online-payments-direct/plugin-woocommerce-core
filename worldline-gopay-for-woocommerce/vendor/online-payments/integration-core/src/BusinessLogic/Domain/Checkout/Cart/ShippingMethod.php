<?php

namespace WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart;

/**
 * Class ShippingMethod.
 *
 * How the order reaches the shopper (`order.shipping.method`). Oney refuses a payment without it, and
 * accepts only the three types below.
 *
 * @package OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart
 */
class ShippingMethod
{
    /** Delivery by a carrier to the shopper's address. */
    public const CARRIER_THIRD_PARTY = 'carrier-third-party';
    /** The shopper picks the order up in the merchant's store. */
    public const COLLECT_AT_MERCHANT_STORE = 'collect-at-merchant-store';
    /** The shopper picks the order up at a relay point. */
    public const COLLECT_AT_NETWORK_POINTS = 'collect-at-network-points';
    public const DEFAULT_SPEED = 48;
    private string $type;
    private string $name;
    private int $speed;
    /**
     * @param string $type One of the class constants; anything else falls back to
     *     {@see self::CARRIER_THIRD_PARTY}.
     * @param string $name Name of the shipping method.
     * @param int $speed Delivery time in hours.
     */
    public function __construct(string $type, string $name, int $speed = self::DEFAULT_SPEED)
    {
        $this->type = in_array($type, [self::CARRIER_THIRD_PARTY, self::COLLECT_AT_MERCHANT_STORE, self::COLLECT_AT_NETWORK_POINTS], \true) ? $type : self::CARRIER_THIRD_PARTY;
        $this->name = $name;
        $this->speed = $speed;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getSpeed(): int
    {
        return $this->speed;
    }
}
