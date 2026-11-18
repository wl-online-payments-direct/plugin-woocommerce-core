<?php

namespace WOP\OnlinePayments\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Block-checkout integration - one instance per gateway id (main + each method).
 */
class BlocksSupport extends AbstractPaymentMethodType
{
    private string $title;
    private string $icon;
    /** @var array<string, mixed> Method-specific data merged into the block config. */
    private array $extraData;
    /** @var string[] */
    private array $scriptHandles;
    /**
     * Captures the block config for one gateway id.
     *
     * @param string $gatewayId
     * @param string $title
     * @param string $icon
     * @param array<string, mixed> $extraData
     * @param string[]|null $scriptHandles Defaults to the shared block script handle.
     */
    public function __construct(string $gatewayId, string $title, string $icon = '', array $extraData = [], ?array $scriptHandles = null)
    {
        $this->name = $gatewayId;
        $this->title = $title;
        $this->icon = $icon;
        $this->extraData = $extraData;
        $this->scriptHandles = $scriptHandles ?? [ModuleHelper::moduleKey('-blocks')];
    }
    /** No-op; everything is provided through the constructor. */
    public function initialize(): void
    {
    }
    /**
     * Always active - availability is decided by the gateway's is_available().
     *
     * @return bool
     */
    public function is_active(): bool
    {
        return \true;
    }
    /**
     * Script handles to enqueue for this method in the block checkout.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        return $this->scriptHandles;
    }
    /**
     * Config exposed to the client-side payment method registration.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        return array_merge(['name' => $this->name, 'title' => $this->title, 'icon' => $this->icon, 'description' => '', 'supports' => ['products']], $this->extraData);
    }
}
