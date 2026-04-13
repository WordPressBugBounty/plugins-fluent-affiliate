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

$fluent_affiliate_qr_defaults = ['qr_color' => '#000000', 'qr_bg_color' => '#ffffff'];

add_filter('fluent_affiliate/get_feature_settings_affiliate_qr_code', function ($response, $saved) use ($fluent_affiliate_qr_defaults) {
    $response['is_enabled'] = \FluentAffiliate\Framework\Support\Arr::get($saved, 'affiliate_qr_code.is_enabled', 'yes');
    $response['settings'] = \FluentAffiliate\Framework\Support\Arr::get($saved, 'affiliate_qr_code.settings', $fluent_affiliate_qr_defaults);
    return $response;
}, 10, 2);

add_filter('fluent_affiliate/update_feature_settings_affiliate_qr_code', function ($settings) use ($fluent_affiliate_qr_defaults) {
    $validated = [];
    foreach ($fluent_affiliate_qr_defaults as $key => $default) {
        $value = isset($settings[$key]) ? $settings[$key] : '';
        $validated[$key] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $value) ? $value : $default;
    }
    return $validated;
}, 10, 1);
