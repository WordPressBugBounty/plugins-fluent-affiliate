<?php

namespace FluentAffiliate\Database\Migrations;

class CustomersMigrator
{
    static $tableName = 'fa_customers';

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
                `user_id` BIGINT(20) UNSIGNED NULL,
                `by_affiliate_id` BIGINT(20) UNSIGNED NULL,
                `email` VARCHAR(192) NULL,
                `first_name` VARCHAR(192) NULL,
                `last_name` VARCHAR(192) NULL,
                `ip` VARCHAR(100) NULL,
                `settings` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_cust_idx` (`email`),
                 INDEX `fa_cust_user_id` (`user_id`)
            ) $charsetCollate;";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }
}
