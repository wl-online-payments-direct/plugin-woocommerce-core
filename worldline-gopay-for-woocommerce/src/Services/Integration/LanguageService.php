<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\BusinessLogic\Domain\Integration\Language\LanguageService as CoreLanguageService;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Language\Exception\InvalidIsoCodeException;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Language\Language;
use WOP\OnlinePayments\Core\BusinessLogic\Domain\Language\LanguageCode;
defined('ABSPATH') || exit;
/**
 * Exposes a single language: the active WordPress site locale.
 */
class LanguageService implements CoreLanguageService
{
    /**
     * @inheritDoc
     *
     * @return Language[]
     */
    public function getEnabledLanguages(): array
    {
        $code = $this->getLanguageCode($this->getSiteLocale()) ?? $this->getLanguageCode('en_US');
        if ($code === null) {
            return [];
        }
        return [new Language(strtoupper($code->getLanguage()), $this->getFlagUrl($code))];
    }
    /**
     * Returns the bundled flag-icon URL for the language's country, or '' when unavailable.
     *
     * @param LanguageCode $code
     *
     * @return string
     */
    private function getFlagUrl(LanguageCode $code): string
    {
        $country = $code->getCountry();
        if ($country === '' || !defined('WOP_ONLINE_PAYMENTS_PLUGIN_FILE') || !function_exists('plugins_url')) {
            return '';
        }
        $baseUrl = rtrim(plugins_url('assets/images', \WOP_ONLINE_PAYMENTS_PLUGIN_FILE), '/');
        return $baseUrl . '/flags/country-' . strtolower($country) . '.svg';
    }
    /**
     * Resolves the active site locale, defaulting to en_US.
     *
     * @return string
     */
    private function getSiteLocale(): string
    {
        if (function_exists('determine_locale')) {
            return determine_locale();
        }
        if (function_exists('get_locale')) {
            return get_locale();
        }
        return 'en_US';
    }
    /**
     * Parses an ISO locale into a LanguageCode, or null when invalid.
     *
     * @param string $isoCode
     *
     * @return LanguageCode|null
     */
    private function getLanguageCode(string $isoCode): ?LanguageCode
    {
        try {
            return LanguageCode::fromIso($isoCode);
        } catch (InvalidIsoCodeException $e) {
            return null;
        }
    }
}
