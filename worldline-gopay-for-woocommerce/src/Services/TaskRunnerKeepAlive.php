<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Core\Infrastructure\TaskExecution\Interfaces\TaskRunnerWakeup;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Keep-alive for the core task runner: on WordPress requests, wakes the runner at most once per
 * minute. A safety net for a failed self-call to the async-process route, after which nothing runs
 * until the next wakeup. The wakeup itself costs one runner status read while the runner is active.
 */
class TaskRunnerKeepAlive
{
    /** Minimum number of seconds between two wakeups. */
    private const INTERVAL = 60;
    /**
     * Hooks the wakeup to the end of WordPress requests.
     */
    public function register(): void
    {
        add_action('shutdown', [$this, 'wakeup']);
    }
    /**
     * Wakes the task runner unless it was woken in the last minute. Never throws.
     */
    public function wakeup(): void
    {
        try {
            $transient = self::transient();
            if (get_transient($transient) !== \false) {
                return;
            }
            set_transient($transient, time(), self::INTERVAL);
            /** @var TaskRunnerWakeup $wakeup */
            $wakeup = ServiceRegister::getService(TaskRunnerWakeup::CLASS_NAME);
            $wakeup->wakeup();
        } catch (\Throwable $e) {
            try {
                Logger::logDebug('Task runner keep-alive wakeup failed: ' . $e->getMessage(), 'TaskRunnerKeepAlive');
            } catch (\Throwable $ignored) {
            }
        }
    }
    /** Brand-prefixed transient name. */
    private static function transient(): string
    {
        return ModuleHelper::moduleKey('_runner_wakeup');
    }
}
