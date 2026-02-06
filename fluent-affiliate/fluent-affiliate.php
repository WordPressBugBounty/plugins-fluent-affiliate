<?php defined('ABSPATH') or die;

/**
 * Plugin Name: FluentAffiliate
 * Description: FluentAffiliate WordPress Plugin
 * Version: 1.3.0
 * Author: WPManageNinja
 * Author URI: https://wpmanageninja.com
 * Plugin URI: https://fluentaffiliate.com
 * License: GPLv2 or later
 * Text Domain: fluent-affiliate
 * Domain Path: /language
 **/

define('FLUENT_AFFILIATE_DIR', plugin_dir_path(__FILE__));
define('FLUENT_AFFILIATE_URL', plugin_dir_url(__FILE__));
define('FLUENT_AFFILIATE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FLUENT_AFFILIATE_VERSION', '1.3.0');

require __DIR__ . '/vendor/autoload.php';

call_user_func(function ($bootstrap) {
    $bootstrap(__FILE__);
}, require(__DIR__ . '/boot/app.php'));

