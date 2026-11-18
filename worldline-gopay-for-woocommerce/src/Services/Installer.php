<?php

namespace WOP\OnlinePayments\Services;

use WOP\OnlinePayments\Helpers\ModuleHelper;
defined('ABSPATH') || exit;
/**
 * Creates / drops the plugin tables.
 */
class Installer
{
    /** Table suffix => list of composite indexes. */
    private const TABLES = ['entity' => [['entity_type', 'index_1']], 'queue' => [['index_1', 'index_2', 'index_5'], ['index_1', 'index_8']], 'monitoring_log' => [['index_1', 'index_2', 'index_7']], 'webhook_log' => [['index_1', 'index_2', 'index_5']], 'tokens' => [['index_1', 'index_2']], 'product_types' => [['index_1']], 'payment_transactions' => [['index_1', 'index_2'], ['index_1', 'index_3']], 'payment_transaction_locks' => ['unique:store_trans_merchant_ref_unique' => ['index_1', 'index_2', 'index_3']]];
    /**
     * Creates all plugin tables via dbDelta.
     */
    public function install(): void
    {
        global $wpdb;
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        $charsetCollate = $wpdb->get_charset_collate();
        foreach (self::TABLES as $suffix => $indexes) {
            dbDelta($this->buildCreateTableSql(ModuleHelper::getFullTableName($suffix), $indexes, $charsetCollate));
        }
    }
    /**
     * Drops all plugin tables.
     */
    /**
     * Full names of the plugin tables of the current site.
     *
     * @return string[]
     */
    public function tableNames(): array
    {
        return array_map(static function (string $suffix): string {
            return ModuleHelper::getFullTableName($suffix);
        }, array_keys(self::TABLES));
    }
    public function remove(): void
    {
        global $wpdb;
        foreach (array_keys(self::TABLES) as $suffix) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier only (plugin table name from trusted code); a table name cannot be a prepare() placeholder.
            $wpdb->query('DROP TABLE IF EXISTS `' . ModuleHelper::getFullTableName($suffix) . '`');
        }
    }
    /**
     * Builds the CREATE TABLE statement for one generic-entity table.
     *
     * @param string $tableName
     * @param array[] $indexes
     * @param string $charsetCollate
     *
     * @return string
     */
    private function buildCreateTableSql(string $tableName, array $indexes, string $charsetCollate): string
    {
        $columns = ['id BIGINT NOT NULL AUTO_INCREMENT', 'entity_type VARCHAR(255) NULL'];
        for ($i = 1; $i <= 9; $i++) {
            $columns[] = 'index_' . $i . ' VARCHAR(191) NULL';
        }
        $columns[] = 'data LONGTEXT NULL';
        $columns[] = 'PRIMARY KEY  (id)';
        $keyOrdinal = 0;
        foreach ($indexes as $key => $indexColumns) {
            $keyOrdinal++;
            if (is_string($key) && strpos($key, 'unique:') === 0) {
                $columns[] = 'UNIQUE KEY ' . substr($key, strlen('unique:')) . ' (' . implode(', ', $indexColumns) . ')';
                continue;
            }
            $columns[] = 'KEY idx_' . $keyOrdinal . ' (' . implode(', ', $indexColumns) . ')';
        }
        return "CREATE TABLE `{$tableName}` (\n    " . implode(",\n    ", $columns) . "\n) {$charsetCollate};";
    }
}
