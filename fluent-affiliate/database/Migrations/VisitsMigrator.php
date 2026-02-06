<?php

namespace FluentAffiliate\Database\Migrations;

class VisitsMigrator
{
    static $tableName = 'fa_visits';

    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . static::$tableName;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            return;
        }

        $sql = "CREATE TABLE $table (
        `id` BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
        `affiliate_id` BIGINT(20) UNSIGNED NULL,
        `user_id` BIGINT(20) UNSIGNED NULL,
        `referral_id` BIGINT(20) UNSIGNED NULL,
        `url` MEDIUMTEXT NULL,
        `referrer` MEDIUMTEXT NULL,
        `utm_campaign` VARCHAR(100) NULL,
        `utm_medium` VARCHAR(100) NULL,
        `utm_source` VARCHAR(100) NULL,
        `ip` VARCHAR(100) NULL,
        `created_at` TIMESTAMP NULL,
        `updated_at` TIMESTAMP NULL,
         INDEX `fa_visit_idx` (`affiliate_id`),
         INDEX `fa_visit_utm_campaign` (`utm_campaign`)
    ) $charsetCollate;";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        dbDelta($sql);
    }
}
