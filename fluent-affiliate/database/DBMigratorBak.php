<?php

namespace FluentAffiliate\Database;

use FluentAffiliate\Database\Migrations\AffiliatesMigrator;
use FluentAffiliate\Database\Migrations\CustomersMigrator;
use FluentAffiliate\Database\Migrations\MetaMigrator;
use FluentAffiliate\Database\Migrations\PayoutsMigrator;
use FluentAffiliate\Database\Migrations\PayoutTransactionsMigrator;
use FluentAffiliate\Database\Migrations\ReferralsMigrator;
use FluentAffiliate\Database\Migrations\VisitsMigrator;

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

class DBMigratorBak
{
    public static function run($network_wide = false)
    {
        global $wpdb;
        if ($network_wide) {
            // Retrieve all site IDs from this network (WordPress >= 4.6 provides easy to use functions for that).
            if (function_exists('get_sites') && function_exists('get_current_network_id')) {
                $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $site_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;");
            }
            // Install the plugin for all these sites.
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                self::migrate();
                restore_current_blog();
            }
        } else {
            self::migrate();
        }
    }

    public static function migrate()
    {
        AffiliatesMigrator::migrate();
        CustomersMigrator::migrate();
        PayoutTransactionsMigrator::migrate();
        PayoutsMigrator::migrate();
        ReferralsMigrator::migrate();
        VisitsMigrator::migrate();
        MetaMigrator::migrate();
        // AffiliateGroupMigrator::migrate();
    }
}
