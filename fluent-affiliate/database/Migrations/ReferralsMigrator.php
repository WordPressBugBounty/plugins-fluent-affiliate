<?php

namespace FluentAffiliate\Database\Migrations;

class ReferralsMigrator
{
    static $tableName = 'fa_referrals';

    /**
     * Indexes that are also added to already installed tables.
     * index name => column definition
     */
    static $upgradableIndexes = [
        'fa_ref_customer'      => '(`customer_id`)',
        'fa_ref_provider_item' => '(`provider`, `provider_id`)',
        'fa_ref_visit'         => '(`visit_id`)',
        'fa_ref_created'       => '(`created_at`)',
        'fa_ref_payout'        => '(`payout_id`)',
        'fa_ref_parent'        => '(`parent_id`)',
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
                 INDEX `fa_ref_customer` (`customer_id`),
                 INDEX `fa_ref_provider_item` (`provider`, `provider_id`),
                 INDEX `fa_ref_visit` (`visit_id`),
                 INDEX `fa_ref_created` (`created_at`),
                 INDEX `fa_ref_payout` (`payout_id`),
                 INDEX `fa_ref_parent` (`parent_id`)
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
