<?php

namespace FluentAffiliate\Database\Migrations;

class PayoutTransactionsMigrator
{
    static $tableName = 'fa_payout_transactions';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
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
                 INDEX `fa_pay_status_idx` (`status`)
            ) $charsetCollate;";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }
}
