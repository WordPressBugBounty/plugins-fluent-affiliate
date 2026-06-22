<?php

namespace FluentAffiliate\Database\Migrations;

class ReferralsMigrator
{
    static $tableName = 'fa_referrals';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $indexExists = $wpdb->get_results($wpdb->prepare(
                "SHOW INDEX FROM `$table` WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be prepared
                'fa_ref_customer'
            ));

            if (empty($indexExists)) {
                $safeTable = esc_sql($table);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$safeTable}` ADD INDEX `fa_ref_customer` (`customer_id`);");
            }

            return;
        }

        $sql = "CREATE TABLE $table (
                `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `affiliate_id` BIGINT(20) UNSIGNED NULL,
                `parent_id` BIGINT(20) UNSIGNED NULL,
                `customer_id` BIGINT(20) UNSIGNED NULL,
                `visit_id` BIGINT(20) UNSIGNED NULL,
                `description` LONGTEXT NULL,
                `status` VARCHAR(100) DEFAULT 'pending',
                `amount` double DEFAULT NULL,
                `order_total` double DEFAULT NULL,
                `currency` CHAR(3),
                `utm_campaign` VARCHAR(100) NULL,
                `provider` VARCHAR(100) NULL,
                `provider_id` BIGINT(20) UNSIGNED NULL,
                `provider_sub_id` VARCHAR(192) NULL,
                `products` LONGTEXT NULL,
                `payout_transaction_id` BIGINT(20) UNSIGNED NULL,
                `payout_id` BIGINT(20) UNSIGNED NULL,
                `type` VARCHAR(100) DEFAULT 'sale',
                `settings` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_aff_idx` (`affiliate_id`),
                 INDEX `fa_aff_status_idx` (`status`),
                 INDEX `fa_aff_type` (`type` ),
                 INDEX `fa_aff_provider` (`provider` ),
                 INDEX `fa_aff_provider_sub` (`provider_sub_id` ),
                 INDEX `fa_ref_customer` (`customer_id`)
            ) $charsetCollate;";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }
}
