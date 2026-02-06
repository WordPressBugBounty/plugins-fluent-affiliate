<?php

use FluentAffiliate\App\App;

/*
 * var $app \FluentAffiliate\Framework\Foundation\Application
 */

/**
 * @param null|string $module Module name or empty string to get the app of specific moduleFh
 * @returns \FluentAffiliate\App\App
 * @returns \FluentAffiliate\Framework\Database\ConnectionInterface
 * @throws \FluentAffiliate\Framework\Container\Contracts\BindingResolutionException
 */
function FluentAffiliate($module = null)
{
    return App::getInstance($module);
}

/**
 * @return \FluentAffiliate\Framework\Database\ConnectionInterface
 * @throws \FluentAffiliate\Framework\Container\Contracts\BindingResolutionException
 */
function FluentAffiliateDB()
{
    return FluentAffiliate('db');
}

/**
 * Get FluentAffiliate Option
 * @param $optionName string
 * @param mixed $default
 * @return mixed|string
 */
function fluentAffiliate_get_option($optionName, $default = '')
{
    $option = \FluentAffiliate\App\Models\Meta::where('meta_key', $optionName)
        ->where('object_type', 'option')
        ->first();

    if (!$option) {
        return $default;
    }
    return ($option->value) ? $option->value : $default;
}

/**
 * Update FluentAffiliate Option
 * @param $optionName
 * @param $value
 * @return int Created or updated meta entry id
 */
function fluentAffiliate_update_option($optionName, $value)
{
    $option = \FluentAffiliate\App\Models\Meta::where('meta_key', $optionName)
        ->where('object_type', 'option')
        ->first();

    if ($option) {
        $option->value = $value;
        $option->save();
        return $option->id;
    }

    $model = \FluentAffiliate\App\Models\Meta::create([
        'meta_key'    => $optionName, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key for performance
        'value'       => $value,
        'object_type' => 'option'
    ]);

    return $model->id;
}
