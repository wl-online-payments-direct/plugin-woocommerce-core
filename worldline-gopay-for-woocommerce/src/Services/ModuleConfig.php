<?php

namespace WOP\OnlinePayments\Services;

defined('ABSPATH') || exit;
/**
 * Static plugin metadata loaded from config.json.
 */
class ModuleConfig
{
    /** @var array<string, string> */
    private array $config;
    /**
     * Loads the JSON config file.
     *
     * @param string $moduleConfigFile
     */
    public function __construct(string $moduleConfigFile)
    {
        if (!file_exists($moduleConfigFile)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, not output as HTML.
            throw new \InvalidArgumentException("Module config file {$moduleConfigFile} does not exist");
        }
        $this->config = json_decode(file_get_contents($moduleConfigFile), \true) ?: [];
    }
    /**
     * @return string
     */
    public function getBrand(): string
    {
        return $this->config['BRAND'] ?? '';
    }
    /**
     * Returns the technical module name (option/REST/table prefix).
     *
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->config['MODULE_NAME'] ?? '';
    }
    /**
     * Returns the brand-specific gateway id prefix (e.g. "worldline", "cawl").
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function getGatewayPrefix(): string
    {
        $prefix = (string) ($this->config['GATEWAY_PREFIX'] ?? '');
        if ($prefix === '') {
            throw new \RuntimeException('GATEWAY_PREFIX is missing from config.json.');
        }
        return $prefix;
    }
    /**
     * Returns the display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->config['NAME'] ?? '';
    }
    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->config['DESCRIPTION'] ?? '';
    }
    /**
     * Returns the merchant help/documentation URL.
     *
     * @return string
     */
    public function getHelpLink(): string
    {
        return $this->config['HELP_LINK'] ?? '';
    }
    /**
     * Returns the Worldline contact form URL for the admin "Need help?" button. Falls back to the
     * help/documentation link when not configured.
     *
     * @return string
     */
    public function getContactLink(): string
    {
        $contactLink = (string) ($this->config['CONTACT_LINK'] ?? '');
        return $contactLink !== '' ? $contactLink : $this->getHelpLink();
    }
    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->config['VERSION'] ?? '';
    }
    /**
     * Returns the GitHub releases API endpoint used for version checks.
     *
     * @return string
     */
    public function getGitHubEndpoint(): string
    {
        return $this->config['GITHUB_ENDPOINT'] ?? '';
    }
}
