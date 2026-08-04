<?php

namespace FluentAffiliate\App;

use FluentAffiliate\App\Helper\Utility;

/**
 * Resolves front-end asset URLs for both build modes.
 *
 * In production the compiled files under assets/ are served directly. In
 * development (config/app.php 'env' => 'dev') requests are pointed at the Vite
 * dev server instead, which serves the sources over native ES modules and
 * pushes hot updates over its own websocket.
 */
class Vite
{
    /**
     * Logical entry name => source path, built path, and whether the built file
     * needs a type="module" script tag. The source paths must match the entry
     * list in vite.config.mjs.
     */
    protected static $entries = [
        'admin_app'    => [
            'src'    => 'resources/admin/app.js',
            'build'  => 'admin/app.min.js',
            'module' => true,
        ],
        'global_admin' => [
            'src'    => 'resources/admin/global_admin.js',
            'build'  => 'admin/global_admin.js',
            'module' => false,
        ],
        'portal_block' => [
            'src'    => 'resources/blocks/portal/fa-portal-index.jsx',
            'build'  => 'admin/fa-portal-index.js',
            'module' => false,
        ],
        'customer_app' => [
            'src'    => 'resources/Customer/app.js',
            'build'  => 'public/customer/app.min.js',
            'module' => true,
        ],
        'fluent_aff'   => [
            'src'    => 'resources/public/fluent_aff.js',
            'build'  => 'public/fluent_aff.js',
            'module' => false,
        ],
        'user_auth'    => [
            'src'    => 'resources/public/user_auth.js',
            'build'  => 'public/user_auth.js',
            'module' => false,
        ],
        'connector'    => [
            'src'    => 'resources/public/fluent-affiliate-connector.js',
            'build'  => 'public/fluent-affiliate-connector.js',
            'module' => false,
        ],
    ];

    /**
     * Stylesheets that are compiled from their own entry. In development Vite
     * serves these as ES modules that inject the styles, so they are enqueued
     * as scripts instead of link tags.
     */
    protected static $styles = [
        'admin_css'         => [
            'src'   => 'resources/scss/admin.scss',
            'build' => 'admin/admin.css',
        ],
        'admin_app_css'     => [
            'src'   => null,
            'build' => 'admin/app.min.css',
        ],
        'customer_css'      => [
            'src'   => 'resources/Customer/stylesheet/application.scss',
            'build' => 'public/customer/app.css',
        ],
        'customer_app_css'  => [
            'src'   => null,
            'build' => 'public/customer/app.min.css',
        ],
        'user_auth_css'     => [
            'src'   => null,
            'build' => 'public/user_auth.css',
        ],
        'portal_block_css'  => [
            'src'   => null,
            'build' => 'admin/fa-portal-index.css',
        ],
    ];

    protected static $moduleHandles = [];

    protected static $clientEnqueued = false;

    /**
     * The dev server writes its origin to .vite-hot while it is running and
     * removes the file on shutdown. Requiring that file means a release that
     * happens to ship with 'env' => 'dev' still serves the built assets rather
     * than pointing a live site at localhost.
     */
    protected static function devServer()
    {
        static $origin = null;

        if ($origin !== null) {
            return $origin;
        }

        $origin = '';

        if (defined('FLUENT_AFFILIATE_DISABLE_VITE_DEV') && FLUENT_AFFILIATE_DISABLE_VITE_DEV) {
            return $origin;
        }

        if (App::getInstance()->config->get('app.env') !== 'dev') {
            return $origin;
        }

        $hotFile = FLUENT_AFFILIATE_DIR . '.vite-hot';

        if (!is_readable($hotFile)) {
            return $origin;
        }

        $contents = trim((string) file_get_contents($hotFile));

        // Only ever a localhost origin written by our own dev server.
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $contents)) {
            $origin = $contents;
        }

        return $origin;
    }

    public static function isDev()
    {
        return static::devServer() !== '';
    }

    public static function scriptUrl($entry)
    {
        if (!isset(static::$entries[$entry])) {
            return '';
        }

        if (static::isDev()) {
            return static::devServer() . '/' . static::$entries[$entry]['src'];
        }

        return App::getInstance()['url.assets'] . static::$entries[$entry]['build'];
    }

    public static function enqueueScript($handle, $entry, $deps = [], $version = false, $inFooter = true)
    {
        if (!isset(static::$entries[$entry])) {
            return;
        }

        $src = static::scriptUrl($entry);

        if (!$src) {
            return;
        }

        static::bootDevClient();

        wp_enqueue_script($handle, $src, $deps, $version, $inFooter);

        if (static::isDev() || static::$entries[$entry]['module']) {
            static::markAsModule($handle);
        }
    }

    public static function enqueueStyle($handle, $entry, $deps = [], $version = false, $media = 'all')
    {
        if (!isset(static::$styles[$entry])) {
            return;
        }

        $style = static::$styles[$entry];

        if (static::isDev()) {
            // Styles bundled into a JS entry are injected by that entry's module
            // in development, so only the standalone stylesheets need loading.
            if (!$style['src']) {
                return;
            }

            static::bootDevClient();

            // Scripts and styles share no handle registry, but a stylesheet
            // served as a module would collide with a script of the same name.
            $devHandle = $handle . '-vite-style';

            wp_enqueue_script($devHandle, static::devServer() . '/' . $style['src'], [], $version, true);
            static::markAsModule($devHandle);

            return;
        }

        $file = $style['build'];

        if (Utility::isRtl()) {
            $file = preg_replace('/\.css$/', '.rtl.css', $file);
        }

        wp_enqueue_style($handle, App::getInstance()['url.assets'] . $file, $deps, $version, $media);
    }

    /**
     * The dev server's client script owns the websocket connection that drives
     * hot updates, so it has to load before any module it manages.
     */
    protected static function bootDevClient()
    {
        if (!static::isDev() || static::$clientEnqueued) {
            return;
        }

        static::$clientEnqueued = true;

        wp_enqueue_script('fluent-affiliate-vite-client', static::devServer() . '/@vite/client', [], null, false);
        static::markAsModule('fluent-affiliate-vite-client');
    }

    protected static function markAsModule($handle)
    {
        if (in_array($handle, static::$moduleHandles, true)) {
            return;
        }

        if (!static::$moduleHandles) {
            add_filter('script_loader_tag', function ($tag, $handle) {
                if (!in_array($handle, self::$moduleHandles, true)) {
                    return $tag;
                }

                // WordPress may already have written a type attribute, and two
                // of them would make the tag invalid.
                $tag = preg_replace('/\stype=([\'"])[^\'"]*\1/', '', $tag);

                return str_replace('<script ', '<script type="module" ', $tag);
            }, 10, 2);
        }

        static::$moduleHandles[] = $handle;
    }
}
