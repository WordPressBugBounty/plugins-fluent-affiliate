<?php

namespace FluentAffiliate\Database\Migrations;

class PayoutTransactionsMigrator
{
    static $tableName = 'fa_payout_transactions';

    /**
     * Indexes that are also added to already installed tables.
     * index name => column definition
     */
    static $upgradableIndexes = [
        'fa_pay_txn_payout'    => '(`payout_id`)',
        'fa_pay_txn_affiliate' => '(`affiliate_id`)',
    ];

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            static::addMissingIndexes($table);
            return;
        }

        $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `created_by` BIGINT(20) UNSIGNED NULL,
                `affiliate_id` BIGINT(20) UNSIGNED NULL,
                `payout_id` BIGINT(20) UNSIGNED NULL,
                `total_amount` double DEFAULT 0,
                `payout_method` VARCHAR(100) DEFAULT 'manual',
                `status` VARCHAR(100) DEFAULT 'paid',
                `currency` CHAR(3),
                `settings` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_pay_status_idx` (`status`),
                 INDEX `fa_pay_txn_payout` (`payout_id`),
                 INDEX `fa_pay_txn_affiliate` (`affiliate_id`)
            ) $charsetCollate;";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }

    /**
     * @param string $table
     * @return void
     */
    protected static function addMissingIndexes($table)
    {
        global $wpdb;

        $safeTable = esc_sql($table);

        $missingIndexes = [];

        foreach (static::$upgradableIndexes as $indexName => $columns) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $indexExists = $wpdb->get_results($wpdb->prepare(
                "SHOW INDEX FROM `$safeTable` WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be prepared
                $indexName
            ));

            if (empty($indexExists)) {
                $missingIndexes[] = "ADD INDEX `{$indexName}` {$columns}";
            }
        }

        if (!$missingIndexes) {
            return;
        }

        $addClauses = implode(', ', $missingIndexes);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $addClauses is built only from the hardcoded static::$upgradableIndexes list; index names and columns are SQL identifiers, which $wpdb->prepare() cannot parameterize
        $wpdb->query("ALTER TABLE `{$safeTable}` {$addClauses};");
    }
}
