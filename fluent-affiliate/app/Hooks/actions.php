<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly


/**
 * All registered action's handlers should be in app\Hooks\Handlers,
 * addAction is similar to add_action and addCustomAction is just a
 * wrapper over add_action which will add a prefix to the hook name
 * using the plugin slug to make it unique in all WordPress plugins,
 * ex: $app->addCustomAction('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_action('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentAffiliate\Framework\Foundation\Application
 */
add_action('plugins_loaded', function () {
    (new \FluentAffiliate\App\Modules\Integrations\CoreIntegrationsInit())->register();
});

add_action('init', function () use ($app) {
    $app->addAction('admin_menu', [\FluentAffiliate\App\Hooks\Handlers\AdminMenuHandler::class, 'add']);

    // Customer Portal
    (new \FluentAffiliate\App\Modules\Portal\CustomerPortal())->register();

    // Frontend Tracker
    (new \FluentAffiliate\App\Modules\Tracker\Track())->init();

    // Email Notification Handler
    (new \FluentAffiliate\App\Hooks\Handlers\EmailNotificationHandler())->register();

    // Block Editor Handler
    (new \FluentAffiliate\App\Hooks\Handlers\BlockEditorHandler())->init();

});

$app->addAction('admin_init', function () use ($app) {
    return $app->make(\FluentAffiliate\App\Hooks\Handlers\DashboardWidgetHandler::class);
});

// require the CLI
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('fluent_affiliate', '\FluentAffiliate\App\Hooks\CLI\Commands');
}
