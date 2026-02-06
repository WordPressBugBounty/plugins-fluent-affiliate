<?php
namespace FluentAffiliate\Database\Migrations;

class AffiliatesMigrator
{
    static $tableName = 'fa_affiliates';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for plugin activation and table creation
            $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `contact_id` BIGINT(20) UNSIGNED NULL,
                `user_id` BIGINT(20) UNSIGNED NULL,
                `group_id` BIGINT(20) UNSIGNED NULL,
                `rate` double DEFAULT NULL,
                `total_earnings` double DEFAULT 0,
                `unpaid_earnings` double DEFAULT 0,
                `referrals` BIGINT(20) DEFAULT 0,
                `visits` BIGINT(20) DEFAULT 0,
                `lead_counts` BIGINT(20) DEFAULT 0,
                `rate_type` VARCHAR(100) DEFAULT 'percentage',
                `custom_param` VARCHAR(100) NULL,
                `payment_email` VARCHAR(192) NULL,
                `status` VARCHAR(100) DEFAULT 'active',
                `settings` LONGTEXT NULL,
                `note` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_a_idx` (`user_id` DESC),
                 INDEX `fa_a_status_idx` (`status`)
            ) $charsetCollate;";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            dbDelta($sql);
        } else {
            // check if the lead_counts column exists
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required for checking column existence in migration
            $columnExists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM `$table` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be prepared
                'lead_counts'
            ));

            if (empty($columnExists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for adding column in migration
                $wpdb->query("ALTER TABLE `wp_fa_affiliates` ADD `lead_counts` bigint NULL DEFAULT '0' AFTER `visits`;");
            }

        }

    }
}
