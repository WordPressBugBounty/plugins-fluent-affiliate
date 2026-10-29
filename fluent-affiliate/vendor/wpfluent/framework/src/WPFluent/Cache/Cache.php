<?php

namespace FluentAffiliate\Framework\Cache;

use Closure;
use FluentAffiliate\Framework\Foundation\App;

class Cache
{
    /**
     * How long "forever" lasts: a transient with no expiry would be loaded
     * on every page, so it gets a far one instead.
     */
    const FOREVER = 5 * YEAR_IN_SECONDS;

    /**
     * Whether this request already removed the old cache files.
     *
     * @var bool
     */
    protected static $filesRemoved = false;

    /**
     * Get the cache ready and remove the files older versions wrote.
     *
     * Values used to be cached in files under uploads, where anyone could
     * download them; they now live in WordPress transients.
     *
     * @param  string|null $cacheDir The old cache folder, if not the default.
     * @return static
     */
    public static function init($cacheDir = null)
    {
        static::removeCacheFiles($cacheDir);

        return new static;
    }

    /**
     * The folder older versions kept cache files in.
     *
     * @param  string|null $cacheDir
     * @return string
     */
    public static function getDir($cacheDir = null)
    {
        $slug = App::config()->get('app.slug');
        
        $cacheDir ??= WP_CONTENT_DIR . "/uploads/{$slug}/storage/app/cache";

        return rtrim($cacheDir, '/');
    }

    /**
     * Store data in the cache using transients.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $ttl   Time to live (in seconds)
     * @return bool
     */
    public static function put($key, $value, $ttl = HOUR_IN_SECONDS)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_set($key, $value, static::cacheGroup(), $ttl);
        }

        return static::transientPut($key, $value, $ttl);
    }

    /**
     * Store data in the cache using transients.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  int     $ttl   Time to live (in seconds)
     * @return bool
     */
    public static function set($key, $value, $ttl = HOUR_IN_SECONDS)
    {
        return static::put($key, $value, $ttl);
    }

    /**
     * Retrieve data from the cache.
     *
     * @param  string  $key
     * @return mixed
     */
    public static function get($key)
    {
        // Initialize so wp_cache_get's by-reference $found arg doesn't
        // trigger a PHP 8.1+ "writing to undefined variable" deprecation.
        $found = null;

        $key = static::key($key);

        if (static::isPersistent()) {
            $value = wp_cache_get($key, static::cacheGroup(), false, $found);
            $value = $found ? $value : null;
        } else {
            $stored = get_transient($key);
            $value = is_array($stored) && array_key_exists('value', $stored)
                ? $stored['value']
                : null;
        }

        return $value;
    }

    /**
     * Remove data from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public static function forget($key)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_delete($key, static::cacheGroup());
        }

        return delete_transient($key);
    }

    /**
     * The group key for the cache.
     * 
     * @return string
     */
    public static function cacheGroup()
    {
        $slug = App::config()->get('app.slug');

        return 'wpfluent_cache_group_' . $slug;
    }

    /**
     * Store data in the cache "forever" using 5 years of expiry time.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return bool
     */
    public static function forever($key, $value)
    {
        $key = static::key($key);

        if (static::isPersistent()) {
            return wp_cache_set($key, $value, static::cacheGroup(), 0);
        }

        return static::transientPut($key, $value, static::FOREVER);
    }

    /**
     * Get an item from the cache, or store what the callback returns.
     *
     * A WP_Error is returned without being stored, so the next call tries
     * again; an exception from the callback reaches the caller.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @param  int      $ttl   Time to live (in seconds)
     * @return mixed
     */
    public static function remember(
        $key, Closure $callback, $ttl = HOUR_IN_SECONDS
    )
    {
        $value = static::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        if (!is_wp_error($value)) {
            static::set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Increment the value of an item in the cache, keeping the time it
     * has left.
     * 
     * @param  string  $key
     * @param  integer $count
     * @return bool
     */
    public static function increment($key, $count = 1)
    {
        return static::adjust($key, $count);
    }

    /**
     * Decrement the value of an item in the cache, keeping the time it
     * has left.
     * 
     * @param  string  $key
     * @param  integer $count
     * @return bool
     */
    public static function decrement($key, $count = 1)
    {
        return static::adjust($key, -$count);
    }

    /**
     * Add $count to a numeric value without changing when it expires.
     *
     * An object cache changes a whole number in place, which also keeps two
     * requests from losing each other's change; a transient is stored again
     * for the time WordPress says it has left.
     *
     * @param  string  $key
     * @param  int|float  $count
     * @return bool
     */
    protected static function adjust($key, $count)
    {
        $value = static::get($key);

        if (!is_numeric($value)) {
            return false;
        }

        $cacheKey = static::key($key);

        if (!static::isPersistent()) {
            $expires = (int) get_option('_transient_timeout_' . $cacheKey);

            return static::transientPut(
                $cacheKey, $value + $count, $expires ? max(1, $expires - time()) : 0
            );
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false && is_int($count)) {
            $result = $count < 0
                ? wp_cache_decr($cacheKey, -$count, static::cacheGroup())
                : wp_cache_incr($cacheKey, $count, static::cacheGroup());

            return $result !== false;
        }

        // An object cache doesn't say how long a value has left, so a
        // number that isn't whole gets the default lifetime.
        return wp_cache_set($cacheKey, $value + $count, static::cacheGroup(), HOUR_IN_SECONDS);
    }

    /**
     * Delete all cached items for the plugin.
     * 
     * @return void
     */
    public static function flush()
    {
        if (static::isPersistent()) {
            // Preferred: Use wp_cache_flush_group if available
            if (function_exists('wp_cache_flush_group')) {
                return wp_cache_flush_group(static::cacheGroup());
            }

            // Fallback: Manual flush for in-memory cache
            global $wp_object_cache;
            $group = static::cacheGroup();

            if (!isset($wp_object_cache->cache[$group])) {
                return false;
            }

            foreach (array_keys($wp_object_cache->cache[$group]) as $key) {
                unset($wp_object_cache->cache[$group][$key]);
            }

            return true;
        }

        static::removeCacheFiles();

        return static::transientFlush();
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public static function has($key)
    {
        return static::get($key) !== null;
    }

    /**
     * Make unique key with config.app.slug
     * 
     * @param  string $key
     * @return string
     */
    protected static function key($key)
    {
        // Every cache call builds a key, so the old files go on first use,
        // however the cache is reached.
        if (!static::$filesRemoved) {
            static::removeCacheFiles();
        }

        $key = static::prefix() . sanitize_key($key);

        // A transient name may be at most 172 characters long.
        return strlen($key) > 172 ? substr($key, 0, 140) . md5($key) : $key;
    }

    /**
     * The start every cache key of the plugin shares.
     *
     * @return string
     */
    protected static function prefix()
    {
        return App::config()->get('app.slug') . '_wpfluent_cache_';
    }

    /**
     * Check if persistent cache is available.
     *
     * @return bool
     */
    protected static function isPersistent()
    {
        return wp_using_ext_object_cache();
    }

    /**
     * Store a value in a transient. It is wrapped, so a cached false, 0 or
     * empty string can be told from a missing key.
     *
     * @param  string $key
     * @param  mixed  $value
     * @param  int    $ttl
     * @return bool
     */
    protected static function transientPut($key, $value, $ttl)
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        return set_transient($key, ['value' => $value], $ttl > 0 ? $ttl : static::FOREVER);
    }

    /**
     * Delete every transient the plugin's cache wrote.
     *
     * @return bool
     */
    protected static function transientFlush()
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_' . static::prefix()) . '%'
        ));

        foreach ($names as $name) {
            delete_transient(substr($name, strlen('_transient_')));
        }

        return true;
    }

    /**
     * Delete the cache files older versions wrote, and their folder.
     *
     * @param  string|null $cacheDir
     * @return void
     */
    protected static function removeCacheFiles($cacheDir = null)
    {
        static::$filesRemoved = true;

        $dir = static::getDir($cacheDir);

        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
