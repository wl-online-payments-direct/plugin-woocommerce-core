<?php

namespace WOP;

/**
 * Removes all plugin data when the plugin is deleted.
 */
use WOP\OnlinePayments\Services\UninstallService;
if (!\defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
$autoload = __DIR__ . '/vendor/autoload.php';
if (\file_exists($autoload)) {
    require_once $autoload;
}
if (!\defined('WOP_ONLINE_PAYMENTS_CONFIG_FILE')) {
    \define('WOP_ONLINE_PAYMENTS_CONFIG_FILE', __DIR__ . '/config.json');
}
if (\class_exists(UninstallService::class)) {
    (new UninstallService())->uninstall();
}
