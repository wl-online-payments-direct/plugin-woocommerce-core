<?php

namespace WOP\OnlinePayments\Services\Integration;

use WOP\OnlinePayments\Core\Infrastructure\Configuration\Configuration;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Interfaces\ShopLoggerAdapter;
use WOP\OnlinePayments\Core\Infrastructure\Logger\LogData;
use WOP\OnlinePayments\Core\Infrastructure\Logger\Logger;
use WOP\OnlinePayments\Core\Infrastructure\ServiceRegister;
use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * ShopLoggerAdapter implementation writing through WooCommerce's logger.
 */
class LoggerService implements ShopLoggerAdapter
{
    /** @var array<int, string> */
    private static array $logLevelName = [Logger::ERROR => 'ERROR', Logger::WARNING => 'WARNING', Logger::INFO => 'INFO', Logger::DEBUG => 'DEBUG'];
    /** @var array<int, string> */
    private static array $wcLogLevel = [Logger::ERROR => 'error', Logger::WARNING => 'warning', Logger::INFO => 'info', Logger::DEBUG => 'debug'];
    /**
     * @inheritDoc
     *
     * @param LogData $data
     */
    public function logMessage(LogData $data): void
    {
        /** @var Configuration $configService */
        $configService = ServiceRegister::getService(Configuration::CLASS_NAME);
        $logLevel = $data->getLogLevel();
        if ($logLevel > (int) $configService->getMinLogLevel() && !$configService->isDebugModeEnabled()) {
            return;
        }
        // The core passes a millisecond timestamp; times are written in UTC like the WooCommerce log entries.
        $timestamp = intdiv($data->getTimestamp(), 1000);
        $message = ModuleHelper::getModuleConfig()->getBrand() . ' LOG:' . ' | Date: ' . gmdate('d/m/Y', $timestamp) . ' | Time: ' . gmdate('H:i:s', $timestamp) . ' | Log level: ' . (self::$logLevelName[$logLevel] ?? $logLevel) . ' | Message: ' . $data->getMessage();
        $context = $data->getContext();
        if (!empty($context)) {
            $contextData = [];
            foreach ($context as $item) {
                $contextData[$item->getName()] = $item->getValue();
            }
            $message .= ' | Context: ' . json_encode($contextData);
        }
        $this->writeLog($logLevel, $message);
    }
    /**
     * Writes through the WooCommerce logger, falling back to error_log.
     *
     * @param int $logLevel
     * @param string $message
     */
    protected function writeLog(int $logLevel, string $message): void
    {
        $source = ModuleHelper::getModuleConfig()->getModuleName();
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log(self::$wcLogLevel[$logLevel] ?? 'info', $message, ['source' => $source]);
            return;
        }
        error_log('[' . $source . '] ' . $message);
    }
}
