<?php

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ($app->config->get('app.env') == 'dev') {

    $fluentAffiliateGlobalsDevFile = __DIR__ . '/globals_dev.php';

    is_readable($fluentAffiliateGlobalsDevFile) && include $fluentAffiliateGlobalsDevFile;
}

if (!function_exists('dd')) {
    function dd() // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Scoped to this plugin
    {

        foreach (func_get_args() as $arg) {
            echo "<pre>";
            // @phpcs:ignore
            print_r($arg);
            echo "</pre>";
        }
        die();
    }
}
