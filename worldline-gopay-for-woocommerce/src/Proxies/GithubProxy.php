<?php

namespace WOP\OnlinePayments\Proxies;

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Resolves the latest published plugin version from the GitHub releases endpoint configured in
 * config.json.
 */
class GithubProxy
{
    /**
     * Retrieves the latest released version tag (without a leading "v"), cached in a transient.
     *
     * @return string Empty string when the version cannot be determined.
     */
    public function getLatestVersion(): string
    {
        if (function_exists('get_transient')) {
            $cached = get_transient(self::cacheKey());
            if ($cached !== \false) {
                return (string) $cached;
            }
        }
        $version = $this->fetchLatestVersion();
        if (function_exists('set_transient')) {
            // Shorter TTL on an empty result so a transient GitHub outage self-heals.
            $ttl = $version === '' ? \HOUR_IN_SECONDS : 12 * \HOUR_IN_SECONDS;
            set_transient(self::cacheKey(), $version, $ttl);
        }
        return $version;
    }
    /**
     * Transient key, per module so that brands do not share a cached version.
     *
     * @return string
     */
    private static function cacheKey(): string
    {
        return ModuleHelper::moduleKey('_latest_version');
    }
    /**
     * Fetches the latest release tag from the configured GitHub endpoint.
     *
     * @return string
     */
    private function fetchLatestVersion(): string
    {
        $endpoint = ModuleHelper::getModuleConfig()->getGitHubEndpoint();
        if ($endpoint === '' || !function_exists('wp_remote_get')) {
            return '';
        }
        $response = wp_remote_get($endpoint, ['timeout' => 5, 'headers' => ['Accept' => 'application/vnd.github+json']]);
        if (is_wp_error($response)) {
            Logger::logError('Failed to get latest version from Github: ' . $response->get_error_message());
            return '';
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            Logger::logError('Failed to get latest version from Github, response status: ' . $status);
            return '';
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), \true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return '';
        }
        return ltrim((string) $body['tag_name'], 'v');
    }
}
