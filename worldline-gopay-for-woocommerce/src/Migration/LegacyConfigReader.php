<?php

namespace WOP\OnlinePayments\Migration;

defined('ABSPATH') || exit;
/**
 * Read-only accessor over the previous (2.x) plugin's WooCommerce gateway option rows. The 2.x
 * identifiers come from LegacySource, never from this plugin's own prefix.
 */
class LegacyConfigReader
{
    /** Credit-cards (hosted tokenization) gateway suffix. */
    public const CARDS_SUFFIX = 'hosted-tokenization';
    /** Bank-transfer gateway suffix (holds its own instant-payment flag). */
    public const BANK_TRANSFER_SUFFIX = 'bank-transfer';
    /** @var array<string, array<string, mixed>> Cache of loaded option rows by gateway id. */
    private array $cache = [];
    /**
     * Whether the previous plugin left configuration behind on this site.
     *
     * @return bool
     */
    public function hasLegacyConfig(): bool
    {
        if (!function_exists('get_option')) {
            return \false;
        }
        $version = get_option(LegacySource::versionOption());
        if (is_string($version) && $version !== '' && $version !== '0.0.0') {
            return \true;
        }
        return $this->row(LegacySource::mainGatewayId()) !== [];
    }
    /**
     * Value from the main gateway option row.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function main(string $key, $default = '')
    {
        return $this->value(LegacySource::mainGatewayId(), $key, $default);
    }
    /**
     * Reads a `'yes'`/`'no'` flag from the main option row as a bool.
     *
     * @param string $key
     * @param bool $default
     *
     * @return bool
     */
    public function mainBool(string $key, bool $default = \false): bool
    {
        return self::toBool($this->main($key, $default ? 'yes' : 'no'), $default);
    }
    /**
     * Value from a per-method gateway option row, addressed by its suffix
     * ("woocommerce_{2.x prefix}-{suffix}_settings").
     *
     * @param string $suffix
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function method(string $suffix, string $key, $default = '')
    {
        return $this->value($this->gatewayId($suffix), $key, $default);
    }
    /**
     * Reads a `'yes'`/`'no'` flag from a per-method gateway option row as a bool.
     *
     * @param string $suffix
     * @param string $key
     * @param bool $default
     *
     * @return bool
     */
    public function methodBool(string $suffix, string $key, bool $default = \false): bool
    {
        return self::toBool($this->method($suffix, $key, $default ? 'yes' : 'no'), $default);
    }
    /**
     * Whether a per-method gateway option row exists and is non-empty.
     *
     * @param string $suffix
     *
     * @return bool
     */
    public function hasGateway(string $suffix): bool
    {
        return $this->row($this->gatewayId($suffix)) !== [];
    }
    /**
     * The 2.x installed version, or '' when the option is not set.
     *
     * @return string
     */
    public function version(): string
    {
        $version = function_exists('get_option') ? get_option(LegacySource::versionOption()) : '';
        return is_string($version) ? $version : '';
    }
    /**
     * All settings of the 2.x main gateway option row.
     *
     * @return array<string, mixed>
     */
    public function mainRow(): array
    {
        return $this->row(LegacySource::mainGatewayId());
    }
    /**
     * Builds a 2.x gateway id from the 2.x prefix and a method suffix.
     *
     * @param string $suffix
     *
     * @return string
     */
    private function gatewayId(string $suffix): string
    {
        return LegacySource::gatewayPrefix() . '-' . $suffix;
    }
    /**
     * @param mixed $value
     * @param bool $default
     *
     * @return bool
     */
    private static function toBool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strtolower($value) === 'yes';
        }
        return $default;
    }
    /**
     * @param string $gatewayId
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    private function value(string $gatewayId, string $key, $default)
    {
        $row = $this->row($gatewayId);
        return array_key_exists($key, $row) ? $row[$key] : $default;
    }
    /**
     * Loads and caches a gateway's option row.
     *
     * @param string $gatewayId
     *
     * @return array<string, mixed>
     */
    private function row(string $gatewayId): array
    {
        if (isset($this->cache[$gatewayId])) {
            return $this->cache[$gatewayId];
        }
        $row = function_exists('get_option') ? get_option('woocommerce_' . $gatewayId . '_settings') : \false;
        $this->cache[$gatewayId] = is_array($row) ? $row : [];
        return $this->cache[$gatewayId];
    }
}
