<?php

namespace FluentAffiliate\Database\Migrations;

class PayoutsMigrator
{
    static $tableName = 'fa_payouts';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            return;
        }

        $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `created_by` BIGINT(20) UNSIGNED NULL,
                `total_amount` double DEFAULT NULL,
                `payout_method` VARCHAR(100) DEFAULT 'manual',
                `status` VARCHAR(100) DEFAULT 'draft',
                `currency` CHAR(3),
                `title` VARCHAR(192) NULL,
                `description` LONGTEXT NULL,
                `settings` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_pay_status_idx` (`status`)
            ) $charsetCollate;";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }
}
