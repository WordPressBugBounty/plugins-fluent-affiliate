<?php

use FluentAffiliate\Framework\Foundation\Application;
use FluentAffiliate\App\Hooks\Handlers\ActivationHandler;
use FluentAffiliate\App\Hooks\Handlers\DeactivationHandler;


if (!defined('ABSPATH')) exit; // Exit if accessed directly


return function ($file) {

    $app = new Application($file);

    register_activation_hook($file, function ($network_wide = false) use ($app) {
        ($app->make(ActivationHandler::class))->handle($network_wide);

        if (function_exists('\as_next_scheduled_action')) {
            // Schedule hourly jobs
            if (!\as_next_scheduled_action('fluent_affiliate_scheduled_hour_jobs')) {
                \as_schedule_recurring_action(time(), 3600, 'fluent_affiliate_scheduled_hour_jobs', [], 'fluent-affiliate', true);
            }

            // Schedule daily jobs
            if (!\as_next_scheduled_action('fluent_affiliate_scheduled_daily_jobs')) {
                \as_schedule_recurring_action(time(), 86400, 'fluent_affiliate_scheduled_daily_jobs', [], 'fluent-affiliate', true);
            }
        }

    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    require_once FLUENT_AFFILIATE_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

    add_action('plugins_loaded', function () use ($app) {
        $app->doAction('fluent_affiliate/loaded', $app);

        add_action('init', function () use ($app) {
            do_action('fluent_affiliate/on_wp_init', $app);
        });
    });


    add_action('fluent_affiliate/admin_app_rendering', function () {
        $currentDBVersion = get_option('fluent_affiliate_db_version');

        if ($currentDBVersion && version_compare($currentDBVersion, FLUENT_AFFILIATE_DB_VERSION, '>=')) {
            return;
        }

        if (get_transient('fluent_affiliate_db_migration_lock')) {
            return;
        }

        set_transient('fluent_affiliate_db_migration_lock', 1, 5 * MINUTE_IN_SECONDS);

        \FluentAffiliate\Database\DBMigrator::run();

        update_option('fluent_affiliate_db_version', FLUENT_AFFILIATE_DB_VERSION, 'no');

        delete_transient('fluent_affiliate_db_migration_lock');
    });


    /* Temporary Init for FluentCRM Module (check is handled inside) */
    add_action('fluentcrm_loaded', function () {
        (new \FluentAffiliate\App\Modules\FluentCRM\Init())->register();
    });


    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('fluent_affiliate', '\FluentAffiliate\App\Hooks\CLI\Commands');
    }

    add_action('fluent_affilate/rendering_admin_app', function () {
        if (function_exists('\as_next_scheduled_action')) {
            // Schedule hourly jobs
            if (!\as_next_scheduled_action('fluent_affiliate_scheduled_hour_jobs')) {
                \as_schedule_recurring_action(time(), 3600, 'fluent_affiliate_scheduled_hour_jobs', [], 'fluent-affiliate', true);
            }

            // Schedule daily jobs
            if (!\as_next_scheduled_action('fluent_affiliate_scheduled_daily_jobs')) {
                \as_schedule_recurring_action(time(), 86400, 'fluent_affiliate_scheduled_daily_jobs', [], 'fluent-affiliate', true);
            }
        }
    });

    if (defined('WP_CLI') && WP_CLI) {

        add_action('init', function () {
            \WP_CLI::add_command('fluent_affiliate', '\FluentAffiliate\App\Hooks\CLI\Commands');
        });

    }


    add_action('wp_insert_site', function ($site) use ($app) {

        // Check if the plugin is network-activated
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        if (!is_plugin_active_for_network(FLUENT_AFFILIATE_PLUGIN_BASENAME)) {
            return; // Exit if the plugin is not network-activated
        }

        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }

        // Switch to the new site's context
        switch_to_blog($site->blog_id);

        ($app->make(ActivationHandler::class))->handle(false);

        // Restore the original blog context
        restore_current_blog();
    });

    return $app;
};
