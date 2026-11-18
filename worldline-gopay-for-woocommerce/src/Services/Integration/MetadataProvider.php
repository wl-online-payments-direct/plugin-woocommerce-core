<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\Metadata;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Metadata\MetadataProviderInterface;
defined('ABSPATH') || exit;
/**
 * Builds the platform metadata sent to Worldline.
 */
class MetadataProvider implements MetadataProviderInterface
{
    private ConfigService $configService;
    /**
     * @param ConfigService $configService
     */
    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }
    /**
     * @inheritDoc
     *
     * @return Metadata
     */
    public function getMetadata(): Metadata
    {
        return new Metadata($this->configService->getIntegrationName(), $this->configService->getIntegrationVersion(), '', $this->configService->getPluginVersion(), $this->getStoreUrl());
    }
    /**
     * Returns the site home URL, or '' outside WordPress.
     *
     * @return string
     */
    private function getStoreUrl(): string
    {
        if (function_exists('home_url')) {
            return (string) home_url('/');
        }
        return '';
    }
}
