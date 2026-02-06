<?php

namespace FluentAffiliate\Database;

use FluentAffiliate\Database\Migrations\AffiliatesMigrator;
use FluentAffiliate\Database\Migrations\CustomersMigrator;
use FluentAffiliate\Database\Migrations\MetaMigrator;
use FluentAffiliate\Database\Migrations\PayoutsMigrator;
use FluentAffiliate\Database\Migrations\PayoutTransactionsMigrator;
use FluentAffiliate\Database\Migrations\ReferralsMigrator;
use FluentAffiliate\Database\Migrations\VisitsMigrator;


class DBMigrator
{
    private static $migrations = [
        AffiliatesMigrator::class,
        CustomersMigrator::class,
        PayoutsMigrator::class,
        PayoutTransactionsMigrator::class,
        ReferralsMigrator::class,
        VisitsMigrator::class,
        MetaMigrator::class
    ];

    public static function run($network_wide = false)
    {
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }

        if ($network_wide) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for multisite network activation
            $blogs = $wpdb->get_results("SELECT blog_id FROM $wpdb->blogs");
            foreach ($blogs as $blog) {
                switch_to_blog($blog->blog_id);
                self::migrate();
                restore_current_blog();
            }
            return;
        }

        self::migrate();
    }

    public static function migrate()
    {
        foreach (static::getMigrations() as $migration) {
            $migration::migrate();
        }
    }

    public static function getMigrations()
    {
        return static::$migrations;
    }
}
