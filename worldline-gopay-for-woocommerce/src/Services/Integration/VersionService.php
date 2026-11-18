<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Version\VersionService as CoreVersionService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Version\VersionInfo;
use WOP\OnlinePayments\Helpers\ModuleHelper;
use WOP\OnlinePayments\Proxies\GithubProxy;
defined('ABSPATH') || exit;
/**
 * Reports the installed plugin version and the latest published version.
 */
class VersionService implements CoreVersionService
{
    private GithubProxy $proxy;
    /**
     * @param GithubProxy $proxy
     */
    public function __construct(GithubProxy $proxy)
    {
        $this->proxy = $proxy;
    }
    /**
     * @inheritDoc
     *
     * @return VersionInfo
     */
    public function getVersionInfo(): VersionInfo
    {
        $current = ModuleHelper::getModuleConfig()->getVersion();
        try {
            $latest = $this->proxy->getLatestVersion();
        } catch (\Exception $e) {
            $latest = $current;
        }
        if ($latest === '') {
            $latest = $current;
        }
        return new VersionInfo($current, $latest);
    }
}
