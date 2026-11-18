<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Payment\ShopPaymentService as CoreShopPaymentService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\PaymentMethod;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\PaymentMethod\Repositories\PaymentConfigRepositoryInterface;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Keeps the WooCommerce gateway availability in sync with the Worldline payment-method config.
 */
class ShopPaymentService implements CoreShopPaymentService
{
    /**
     * @inheritDoc
     *
     * @param PaymentMethod $paymentMethod
     */
    public function savePaymentMethod(PaymentMethod $paymentMethod): void
    {
    }
    /**
     * Enabling a method enables the gateway; disabling re-checks the remaining methods.
     *
     * @inheritDoc
     *
     * @param string $paymentProductId
     * @param bool $enabled
     */
    public function enable(string $paymentProductId, bool $enabled): void
    {
        if ($enabled) {
            $this->setGatewayEnabled(\true);
            return;
        }
        $this->syncGatewayAvailability();
    }
    /**
     * @inheritDoc
     *
     * @param string $mode
     */
    public function deletePaymentMethods(string $mode): void
    {
        $this->syncGatewayAvailability();
    }
    /**
     * Enables the gateway iff the active mode has at least one enabled payment method.
     */
    public function syncGatewayAvailability(): void
    {
        /** @var PaymentConfigRepositoryInterface $repository */
        $repository = ServiceRegister::getService(PaymentConfigRepositoryInterface::class);
        $hasEnabledMethod = \false;
        foreach ($repository->getPaymentMethods()->toArray() as $method) {
            if ($method->isEnabled()) {
                $hasEnabledMethod = \true;
                break;
            }
        }
        $this->setGatewayEnabled($hasEnabledMethod);
    }
    /**
     * Writes the `enabled` flag into the gateway settings option (`woocommerce_{gatewayId}_settings`).
     *
     * @param bool $enabled
     */
    private function setGatewayEnabled(bool $enabled): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        $gatewayId = ModuleHelper::getMainGatewayId();
        $optionKey = 'woocommerce_' . $gatewayId . '_settings';
        $settings = get_option($optionKey, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['enabled'] = $enabled ? 'yes' : 'no';
        update_option($optionKey, $settings);
    }
}
