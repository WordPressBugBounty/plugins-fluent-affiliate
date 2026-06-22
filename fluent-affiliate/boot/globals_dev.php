<?php

defined('ABSPATH') || exit;

/**
 * Enable Query Log
 */
if (!function_exists('fluentaffiliate_eql')) {
    function fluentaffiliate_eql()
    {
        defined('SAVEQUERIES') || define('SAVEQUERIES', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core debug constant
    }
}

/**
 * Get Query Log
 */
if (!function_exists('fluentaffiliate_gql')) {
    function fluentaffiliate_gql()
    {
        $result = [];
        foreach ((array)$GLOBALS['wpdb']->queries as $key => $query) {
            $result[++$key] = array_combine([
                'query', 'execution_time'
            ], array_slice($query, 0, 2));
        }
        return $result;
    }
}

