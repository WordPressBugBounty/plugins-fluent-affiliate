<?php

namespace FluentAffiliate\Database\Migrations;

class MetaMigrator
{
    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'fa_meta';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table){
            return;
        }

            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_type` VARCHAR(50) NOT NULL,
                `object_id` BIGINT NULL,
                `meta_key` VARCHAR(192) NOT NULL,
                `value` LONGTEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 INDEX `fa_mt_idx` (`object_type` DESC),
                 INDEX `fa_mto_id_idx` (`object_id` DESC),
                 INDEX `fa_mto_id_meta_key` (`meta_key` )
            ) $charsetCollate;";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            dbDelta($sql);
    }
}
