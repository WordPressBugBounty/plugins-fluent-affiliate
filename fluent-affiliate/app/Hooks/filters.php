<?php defined('ABSPATH') or die;

/**
 * All registered filter's handlers should be in app\Hooks\Handlers,
 * addFilter is similar to add_filter and addCustomFlter is just a
 * wrapper over add_filter which will add a prefix to the hook name
 * using the plugin slug to make it unique in all wordpress plugins,
 * ex: $app->addCustomFilter('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_filter('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * $app
 * @var WPFluent\Foundation\Application
 */

/**
 * @var $app FluentAffiliate\Framework\Foundation\Application
 */

add_filter('fluent_affiliate/parse_smart_codes', function ($text, $data, $type = 'text') {
    return (new \FluentAffiliate\App\Services\Libs\SmartCodeParser())->parse($text, $data);
}, 10, 3);

add_filter('wp_plugin_check_ignore_directories', function ($dirs){
    $dirs[] = 'app/Services/Libs/Emogrifier';
    $dirs[] = 'dev';
    return $dirs;
});
