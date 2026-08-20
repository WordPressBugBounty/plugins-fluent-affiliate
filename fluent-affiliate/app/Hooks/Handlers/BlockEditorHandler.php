<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Vite;
use FluentAffiliate\Framework\Support\Arr;

class BlockEditorHandler
{
    public function init()
    {
        // The editor canvas is an iframe and WordPress only mirrors assets enqueued on
        // enqueue_block_assets into it, so the block preview styles cannot ride along
        // with the editor script on enqueue_block_editor_assets.
        add_action('enqueue_block_assets', function () {
            if (!is_admin()) {
                return;
            }

            Vite::enqueueStyle(
                'fluent-affiliate/portal-style',
                'portal_block_css',
                array(),
                Vite::isDev() ? time() : FLUENT_AFFILIATE_VERSION
            );
        });

        add_action('enqueue_block_editor_assets', function () {
            $app = App::getInstance();
            $assets = $app['url.assets'];

            $assetsVersion = Vite::isDev() ? time() : FLUENT_AFFILIATE_VERSION;

            Vite::enqueueScript(
                'fluent-affiliate/portal',
                'portal_block',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                $assetsVersion,
                true
            );

            $userId = get_current_user_id();
            $affiliate = Affiliate::query()->where('user_id', $userId)->first();
            $userDetails = ($affiliate && !empty($affiliate->user_details) && is_array($affiliate->user_details))
                ? $affiliate->user_details
                : (($user = User::query()->where('ID', $userId)->first()) ? [
                    'full_name' => $user->full_name,
                    'email'     => $user->user_email,
                    'avatar'    => $user->photo,
                ] : []);

            wp_localize_script('fluent-affiliate/portal', 'faPortalBlockVars', [
                'ajaxurl'    => admin_url('admin-ajax.php'),
                'assets_url' => $assets,
                'nonce'      => wp_create_nonce('fluent-affiliate-portal'),
                'user'       => $userDetails,
                'currency'   => (new AdminMenuHandler())->getCurrency(),
                'menu_items' => array_values(Helper::getPortalMenuItems($affiliate)),
                'site_info'  => [
                    'site_url'  => home_url('/'),
                    'share_url' => apply_filters('fluent_affiliate/default_share_url', home_url('/'), $affiliate),
                    'aff_var'   => Utility::getQueryVarName(),
                    'aff_value' => Utility::getAffiliateParam($affiliate),
                ]
            ]);
        });

        register_block_type('fluent-affiliate/portal', array(
            'editor_script_handles' => ['fluent-affiliate/portal'],
            'render_callback' => array($this, 'faRenderPortalBlock'),
            'attributes'      => [
                'layout'     => [
                    'type'    => 'string',
                    'default' => 'classic',
                ],
                'align'      => [
                    'type' => 'string',
                    'enum' => ['wide', 'full'],
                ]
            ]
        ));
    }

    public function faRenderPortalBlock($attributes)
    {
        // Both attributes are attacker-controllable: `align` is not validated by
        // WordPress (it is only declared via JS block supports) and shortcode
        // attributes are not an HTML context, so esc_attr() would not stop a `]`
        // breakout. Map each value to a fixed literal instead of interpolating.
        $shortcodes = [
            'classic' => '[fluent_affiliate_portal layout="classic"]',
            'modern'  => '[fluent_affiliate_portal layout="modern"]',
        ];

        $layout = Arr::get($attributes, 'layout', 'classic');
        if (!is_string($layout) || !isset($shortcodes[$layout])) {
            $layout = 'classic';
        }
        $shortcode = $shortcodes[$layout];

        $align = Arr::get($attributes, 'align');
        $classes = 'fluent-affiliate-portal-block';
        if (in_array($align, ['wide', 'full'], true)) {
            $classes .= ' align' . $align;
        }

        $output = '<div class="' . esc_attr($classes) . '">';
        $output .= do_shortcode($shortcode);
        $output .= '</div>';

        return $output;
    }
}
