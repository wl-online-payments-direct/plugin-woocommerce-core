<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Plugin-level "Session timeout": how long the hosted payment page session lasts (sent as
 * `hostedCheckoutSpecificInput.sessionTimeout`) and after how long an unpaid order is failed.
 * Either a preset in hours or a custom number of minutes.
 */
class SessionTimeoutSetting
{
    public const CUSTOM = 'custom';
    public const DEFAULT_PRESET = '3';
    public const DEFAULT_CUSTOM_MINUTES = 180;
    public const MIN_MINUTES = 1;
    public const MAX_MINUTES = 1440;
    /** Allowed presets, in hours. */
    public const PRESETS = ['1', '3', '6', '12', '18', '24'];
    /** Earlier "Automatic cancellation" value meaning disabled. */
    private const LEGACY_NEVER = -1;
    /**
     * Selected option: a preset in hours or CUSTOM.
     *
     * @return string
     */
    public static function selected(): string
    {
        $value = get_option(self::presetKey(), null);
        if ($value === null || $value === \false) {
            return self::fromAutomaticCancellation()[0];
        }
        $value = (string) $value;
        return $value === self::CUSTOM || in_array($value, self::PRESETS, \true) ? $value : self::DEFAULT_PRESET;
    }
    /**
     * Custom minutes (also kept while a preset is selected).
     *
     * @return int
     */
    public static function customMinutes(): int
    {
        $value = get_option(self::customKey(), null);
        if ($value === null || $value === \false) {
            return self::fromAutomaticCancellation()[1];
        }
        return self::validMinutes($value) ?? self::DEFAULT_CUSTOM_MINUTES;
    }
    /**
     * Effective timeout in minutes.
     *
     * @return int
     */
    public static function minutes(): int
    {
        $selected = self::selected();
        return $selected === self::CUSTOM ? self::customMinutes() : (int) $selected * 60;
    }
    /**
     * Stores the setting. An unknown preset falls back to the default; custom minutes outside
     * 1-1440 fall back to 180.
     *
     * @param string $selected
     * @param mixed $customMinutes
     */
    public static function set(string $selected, $customMinutes): void
    {
        if ($selected !== self::CUSTOM && !in_array($selected, self::PRESETS, \true)) {
            $selected = self::DEFAULT_PRESET;
        }
        update_option(self::presetKey(), $selected, \false);
        update_option(self::customKey(), self::validMinutes($customMinutes) ?? self::DEFAULT_CUSTOM_MINUTES, \false);
    }
    /**
     * Whether the value is a whole number of minutes within 1-1440.
     *
     * @param mixed $minutes
     *
     * @return bool
     */
    public static function isValidMinutes($minutes): bool
    {
        return self::validMinutes($minutes) !== null;
    }
    /**
     * Setting equivalent of a number of hours: a preset when one matches, otherwise custom minutes.
     * 0 or less is the default (the session cannot be disabled), more than 24 is 24.
     *
     * @param int $hours
     *
     * @return array{0: string, 1: int} [selected, custom minutes]
     */
    public static function fromHours(int $hours): array
    {
        if ($hours <= 0) {
            return [self::DEFAULT_PRESET, self::DEFAULT_CUSTOM_MINUTES];
        }
        return self::fromMinutes(min($hours, 24) * 60);
    }
    /**
     * Setting equivalent of a number of minutes: a preset when one matches, otherwise custom.
     * More than 1440 is 24 hours.
     *
     * @param int $minutes
     *
     * @return array{0: string, 1: int} [selected, custom minutes]
     */
    public static function fromMinutes(int $minutes): array
    {
        if ($minutes <= 0) {
            return [self::DEFAULT_PRESET, self::DEFAULT_CUSTOM_MINUTES];
        }
        $minutes = min($minutes, self::MAX_MINUTES);
        $hours = (string) intdiv($minutes, 60);
        if ($minutes % 60 === 0 && in_array($hours, self::PRESETS, \true)) {
            return [$hours, self::DEFAULT_CUSTOM_MINUTES];
        }
        return [self::CUSTOM, $minutes];
    }
    /**
     * Value derived from the earlier "Automatic cancellation" option (minutes, -1 = never) while the
     * new setting was never saved.
     *
     * @return array{0: string, 1: int} [selected, custom minutes]
     */
    private static function fromAutomaticCancellation(): array
    {
        $legacy = get_option(ModuleHelper::getModuleConfig()->getModuleName() . '_automatic_cancellation', null);
        if (!is_numeric($legacy) || (int) $legacy === self::LEGACY_NEVER) {
            return [self::DEFAULT_PRESET, self::DEFAULT_CUSTOM_MINUTES];
        }
        return self::fromMinutes((int) $legacy);
    }
    /**
     * @param mixed $minutes
     *
     * @return int|null
     */
    private static function validMinutes($minutes): ?int
    {
        $raw = is_string($minutes) ? trim($minutes) : $minutes;
        if (!is_numeric($raw) || (float) (int) $raw !== (float) $raw) {
            return null;
        }
        $value = (int) $raw;
        return $value >= self::MIN_MINUTES && $value <= self::MAX_MINUTES ? $value : null;
    }
    private static function presetKey(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_session_timeout';
    }
    private static function customKey(): string
    {
        return ModuleHelper::getModuleConfig()->getModuleName() . '_session_timeout_custom';
    }
}
