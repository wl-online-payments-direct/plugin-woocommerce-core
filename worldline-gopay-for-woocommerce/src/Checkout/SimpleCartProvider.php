<?php

namespace WOP\OnlinePayments\Checkout;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\Cart;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Checkout\Cart\CartProvider;
defined('ABSPATH') || exit;
/**
 * Wraps an already-built Cart (used for the availability query when there is no WC_Order yet).
 */
class SimpleCartProvider implements CartProvider
{
    private Cart $cart;
    /**
     * @param Cart $cart
     */
    public function __construct(Cart $cart)
    {
        $this->cart = $cart;
    }
    /**
     * @return Cart
     */
    public function get(): Cart
    {
        return $this->cart;
    }
}
