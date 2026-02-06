<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\Framework\Support\Arr;

class BlockEditorHandler
{
    public function init()
    {
        add_action('enqueue_block_editor_assets', function () {
            $app = App::getInstance();
            $assets = $app['url.assets'];

            wp_enqueue_script(
                'fluent-affiliate/portal',
                $assets . 'admin/fa-portal-index.js',
                array('wp-blocks', 'wp-components', 'wp-block-editor', 'wp-element'),
                FLUENT_AFFILIATE_VERSION,
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
                    'site_url' => home_url(),
                    'aff_var'  => Utility::getQueryVarName(),
                    'aff_value' => Utility::getAffiliateParam($affiliate),
                ]
            ]);
        });

        register_block_type('fluent-affiliate/portal', array(
            'editor_script'   => 'fluent-affiliate/portal',
            'render_callback' => array($this, 'faRenderPortalBlock'),
            'attributes'      => [
                'layout'     => [
                    'type'    => 'string',
                    'default' => 'classic',
                ]
            ]
        ));
    }

    public function faRenderPortalBlock($attributes)
    {
        $layout = Arr::get($attributes, 'layout', 'classic');

        $output = '<div class="fluent-affiliate-portal-block align' . Arr::get($attributes, 'align') . '">';
        $output .= do_shortcode("[fluent_affiliate_portal layout=$layout]");
        $output .= '</div>';

        return $output;
    }
}
