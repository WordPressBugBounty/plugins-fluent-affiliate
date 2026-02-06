<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\App\Services\TransStrings;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Support\Str;

class AdminMenuHandler
{
    protected $slug = 'fluent-affiliate';

    protected $baseUrl;

    protected $name;

    public function __construct()
    {
        $config = App::getInstance('config');
        $this->slug = $config->get('app.slug');
        $this->name = $config->get('app.name');
        $this->baseUrl = apply_filters('fluent_affiliate_base_url', admin_url('admin.php?page=' . $this->slug . '#'));
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function add()
    {
        $capabilities = PermissionManager::getMenuCapabilities();

        add_menu_page(
            __('FluentAffiliate', 'fluent-affiliate'),
            __('FluentAffiliate', 'fluent-affiliate'),
            $capabilities['dashboard'],
            $this->slug,
            [$this, 'render'],
            $this->getMenuIcon(),
            56
        );

        add_submenu_page(
            $this->slug,
            __('Dashboard', 'fluent-affiliate'),
            __('Dashboard', 'fluent-affiliate'),
            $capabilities['dashboard'],
            $this->slug,
            ''
        );

        add_submenu_page(
            $this->slug,
            __('Affiliates', 'fluent-affiliate'),
            __('Affiliates', 'fluent-affiliate'),
            $capabilities['affiliate'],
            esc_url("{$this->baseUrl}/affiliates"),
            ''
        );

        add_submenu_page(
            $this->slug,
            __('Referrals', 'fluent-affiliate'),
            __('Referrals', 'fluent-affiliate'),
            $capabilities['referral'],
            esc_url("{$this->baseUrl}/referrals"),
            ''
        );

        add_submenu_page(
            $this->slug,
            __('Payouts', 'fluent-affiliate'),
            __('Payouts', 'fluent-affiliate'),
            $capabilities['payout'],
            esc_url("{$this->baseUrl}/payouts"),
            ''
        );

        add_submenu_page(
            $this->slug,
            __('Visits', 'fluent-affiliate'),
            __('Visits', 'fluent-affiliate'),
            $capabilities['visit'],
            esc_url("{$this->baseUrl}/visits"),
            ''
        );

        add_submenu_page(
            $this->slug,
            __('Settings', 'fluent-affiliate'),
            __('Settings', 'fluent-affiliate'),
            $capabilities['settings'],
            esc_url("{$this->baseUrl}/settings/referral-settings"),
            ''
        );
    }

    public function render()
    {
        do_action('fluent_affiliate/admin_app_rendering');

        $menuItems = [];

        $menuOptions = [
            [
                'permission' => [PermissionManager::class, 'isLoggedIn'],
                'key'        => 'dashboard',
                'label'      => __('Dashboard', 'fluent-affiliate'),
                'path'       => '/'
            ],
            [
                'permission' => [PermissionManager::class, 'hasAffiliateAccess'],
                'key'        => 'affiliates',
                'label'      => __('Affiliates', 'fluent-affiliate'),
                'path'       => '/affiliates'
            ],
            [
                'permission' => [PermissionManager::class, 'hasReferralAccess'],
                'key'        => 'referrals',
                'label'      => __('Referrals', 'fluent-affiliate'),
                'path'       => '/referrals'
            ],
            [
                'permission' => [PermissionManager::class, 'hasPayoutAccess'],
                'key'        => 'payouts',
                'label'      => __('Payouts', 'fluent-affiliate'),
                'path'       => '/payouts'
            ],
            [
                'permission' => [PermissionManager::class, 'hasVisitAccess'],
                'key'        => 'visits',
                'label'      => __('Visits', 'fluent-affiliate'),
                'path'       => '/visits'
            ]
        ];

        foreach ($menuOptions as $option) {
            if (call_user_func($option['permission'], true)) {
                $menuItems[] = [
                    'key'       => $option['key'],
                    'label'     => $option['label'],
                    'permalink' => esc_url($this->baseUrl . $option['path'])
                ];
            }
        }

        $menuItems = apply_filters('fluent_affiliate/top_menu_items', $menuItems);

        $rightMenuItems = [];

        if (PermissionManager::userCan('manage_all_data')) {
            $rightMenuItems[] = [
                'key'       => 'settings',
                'label'     => __('Settings', 'fluent-affiliate'),
                'permalink' => esc_url($this->baseUrl . '/settings/referral-settings')
            ];
        }

        $rightMenuItems = apply_filters('fluent_affiliate/right_menu_items', $rightMenuItems);

        $assets = App::getInstance()['url.assets'];

        App::make('view')->render('admin.menu', [
            'name'           => $this->name,
            'slug'           => $this->slug,
            'baseUrl'        => $this->baseUrl,
            'menuItems'      => $menuItems,
            'rightMenuItems' => $rightMenuItems,
            'logo'           => $assets . 'images/FluentAffiliateLogo.png',
            'dark_logo'      => $assets . 'images/FluentAffiliateLogoDark.png'
        ]);
    }

    public function enqueueAssets()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Admin menu page parameter, no nonce needed
        if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'fluent-affiliate') {
            return;
        }

        if (function_exists('wp_enqueue_media')) {
            // Editor default styles.
            add_filter('user_can_richedit', '__return_true');
            wp_tinymce_inline_scripts();
            wp_enqueue_editor();
            wp_enqueue_media();
            if (current_user_can('upload_files')) {
                wp_enqueue_script('media-upload');
            }
            add_thickbox();
        }

        add_action('admin_footer', function () {
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (_ && _.noConflict) {
                        if (window._.each.length == 2) {
                            window.lodash = _.noConflict();
                            console.log('_.noConflict() Loaded');
                        }
                    }
                });
            </script>
            <?php
        }, 99999);

        $app = App::getInstance();

        $assets = $app['url.assets'];

        $assetsVersion = App::getInstance()->config->get('app.env') === 'dev' ? time() : FLUENT_AFFILIATE_VERSION;

        if (Utility::isRtl()) {
            wp_enqueue_style(
                $this->slug . '_admin_app',
                $assets . 'admin/admin.rtl.css',
                array(),
                $assetsVersion
            );
        } else {
            wp_enqueue_style(
                $this->slug . '_admin_app',
                $assets . 'admin/admin.css',
                array(),
                $assetsVersion
            );
        }

        do_action($this->slug . '_loading_app'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- added slug as a prefix

        wp_enqueue_script(
            $this->slug . '_admin_app',
            $assets . 'admin/app.min.js',
            array('jquery'),
            $assetsVersion,
            true
        );

        wp_enqueue_script(
            $this->slug . '_global_admin',
            $assets . 'admin/global_admin.js',
            array(),
            $assetsVersion,
            true
        );

        $currentUser = get_user_by('ID', get_current_user_id());

        $adminScriptVars = apply_filters('fluent_affiliate/admin_vars', [
            'site_name'           => $this->name,
            'slug'                => $this->slug,
            'nonce'               => wp_create_nonce($this->slug),
            'base_url'            => $this->baseUrl,
            'site_url'            => site_url('/'),
            'rest'                => $this->getRestInfo($app),
            'brand_logo'          => $this->getMenuIcon(),
            'asset_url'           => $assets,
            'locale'              => get_locale(),
            'user'                => $this->getCurrentUser(),
            'me'                  => [
                'id'          => $currentUser->ID,
                'full_name'   => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'email'       => $currentUser->user_email,
                'permissions' => PermissionManager::getUserPermissions(),
                'is_admin'    => PermissionManager::isAdmin()
            ],
            'currency'            => $this->getCurrency(),
            'settings_menu_items' => $this->getSettingsMenuItems(),
            'is_onboarded'        => !!get_option('_fa_referral_settings', false),
            'currencies'          => Helper::getCurrencies(),
            'referral_formats'    => Helper::getReferralFormats(),
            'integration_types'   => apply_filters('fluent_affiliate/get_integrations', []),
            'dashboard_notices'   => apply_filters('fluent_affiliate/dashboard_notices', []),
            'payout_method'       => Utility::getReferralSetting('payout_method', 'paypal'),
            'enable_renewal'      => Utility::getReferralSetting('enable_subscription_renewal', 'no'),
            'has_pro'             => defined('FLUENT_AFFILIATE_PRO_VERSION'),
            'i18n'                => TransStrings::getAdminStrings(),
            'suggested_colors'    => Helper::getSuggestedColors(),
            'upgrade_url'         => Utility::getUpgradeUrl(),
            'is_rtl'              => Utility::isRtl(),
        ]);

        wp_localize_script($this->slug . '_admin_app', 'fluentAffiliateAdmin', $adminScriptVars);
    }

    public function getCurrency()
    {
        $setting = Utility::getReferralSettings(false);
        $currencySymbols = Helper::getCurrencySymbols();

        $currency = Arr::get($setting, 'currency', 'USD');
        $currencySymbol = Arr::get($currencySymbols, Str::upper($currency), '$');
        $currencySymbolPosition = Arr::get($setting, 'currency_symbol_position', 'left');

        return [
            'currency'                 => $currency,
            'currency_symbol'          => $currencySymbol,
            'currency_symbol_position' => $currencySymbolPosition,
            'number_format'            => Arr::get($setting, 'number_format', 'dot_separated'),
        ];
    }

    protected function getRestInfo($app)
    {
        $ns = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver
        ];
    }

    protected function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->logo()); //$assets . 'images/logo.svg';
    }

    public function getAffiliateGroups()
    {
        $groups = AffiliateGroup::all();

        $modifiedGroups = [];

        $groups->map(function ($group) use (&$modifiedGroups) {
            $modifiedGroups[$group->id] = $group->key;
        });

        return (object)$modifiedGroups;
    }

    public function logo()
    {
        return '
	        <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
			  <g clip-path="url(#clip0_312_61)">
			    <path fill-rule="evenodd" clip-rule="evenodd" d="M8 0C3.58172 0 0 3.58172 0 8V28C0 32.4183 3.58172 36 8 36H28C32.4183 36 36 32.4183 36 28V8C36 3.58172 32.4183 0 28 0H8ZM24.5412 12.1844L16.2335 26.5737C15.4688 27.8982 13.7751 28.352 12.4506 27.5873C11.1261 26.8226 10.6723 25.129 11.437 23.8045L19.7447 9.41512C20.5094 8.09062 22.203 7.63681 23.5275 8.40151L23.5276 8.40152C24.8521 9.16622 25.3059 10.8599 24.5412 12.1844ZM25.3323 25.5491L26.7169 23.1509C27.4816 21.8264 27.0278 20.1328 25.7033 19.3681C24.3788 18.6034 22.6852 19.0572 21.9205 20.3817L20.5359 22.7799C19.7712 24.1044 20.225 25.798 21.5495 26.5627C22.874 27.3274 24.5676 26.8736 25.3323 25.5491ZM15.442 13.2085L14.0574 15.6067C13.2927 16.9312 11.5991 17.3851 10.2746 16.6204L10.2746 16.6203C8.95008 15.8556 8.49627 14.162 9.26097 12.8375L10.6456 10.4393C11.4103 9.11479 13.1039 8.66098 14.4284 9.42568L14.4284 9.42569C15.7529 10.1904 16.2067 11.884 15.442 13.2085Z" fill="#A7AAAD" />
			  </g>
			  <defs>
			    <clipPath id="clip0_312_61">
			      <rect width="36" height="36" fill="white" />
			    </clipPath>
			  </defs>
			</svg>';
    }

    public function getCurrentUser()
    {
        // get current user with avatar
        $currentUser = get_user_by('ID', get_current_user_id());
        return [
            'id'     => $currentUser->ID,
            'name'   => $currentUser->display_name,
            'email'  => $currentUser->user_email,
            'avatar' => get_avatar_url($currentUser->ID),
        ];
    }

    public function getSettingsMenuItems()
    {
        return apply_filters('fluent_affiliate/settings_menu_items', [
            'referral_settings'        => [
                'title'          => __('Referral Settings', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.625 6.16309V13.8359L10 17.6719L3.375 13.8359V6.16309L10 2.32715L16.625 6.16309ZM10 7.5C10.663 7.5 11.2987 7.76358 11.7676 8.23242C12.2364 8.70126 12.5 9.33696 12.5 10C12.5 10.663 12.2364 11.2987 11.7676 11.7676C11.2987 12.2364 10.663 12.5 10 12.5C9.33696 12.5 8.70126 12.2364 8.23242 11.7676C7.76358 11.2987 7.5 10.663 7.5 10C7.5 9.33696 7.76358 8.70126 8.23242 8.23242C8.70126 7.76358 9.33696 7.5 10 7.5ZM10 8C9.46957 8 8.96101 8.21087 8.58594 8.58594C8.21087 8.96101 8 9.46957 8 10C8 10.5304 8.21087 11.039 8.58594 11.4141C8.96101 11.7891 9.46957 12 10 12C10.5304 12 11.039 11.7891 11.4141 11.4141C11.7891 11.039 12 10.5304 12 10C12 9.46957 11.7891 8.96101 11.4141 8.58594C11.039 8.21086 10.5304 8 10 8ZM16.125 6.45117L15.876 6.30664L10.251 3.05078L10 2.90527L9.74902 3.05078L4.12402 6.30664L3.875 6.45117V13.5488L4.12402 13.6934L9.74902 16.9492L10 17.0947L10.251 16.9492L15.876 13.6934L16.125 13.5488V6.45117Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'referral_settings'
                ]
            ],
            'affiliate_group_settings' => [
                'title'          => __('Affiliate Groups', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.9521 12.7109C15.725 13.1473 16.3847 13.7632 16.8691 14.5127C17.3534 15.262 17.644 16.1164 17.7246 17H17.2217C17.1427 16.2131 16.881 15.4534 16.4492 14.7852C16.0171 14.1166 15.4316 13.5653 14.7461 13.1699L14.9521 12.7109ZM8.5 12C9.95869 12 11.3572 12.5799 12.3887 13.6113C13.3016 14.5242 13.8592 15.7247 13.9756 17H13.4746C13.3598 15.8576 12.8542 14.7839 12.0352 13.9648C11.0975 13.0272 9.82608 12.5 8.5 12.5C7.17392 12.5 5.90253 13.0272 4.96484 13.9648C4.14577 14.7839 3.64018 15.8576 3.52539 17H3.02441C3.14082 15.7247 3.69843 14.5242 4.61133 13.6113C5.64278 12.5799 7.04131 12 8.5 12ZM14.5107 4.2793C14.9581 4.55194 15.3438 4.9183 15.6377 5.35742C16.0371 5.95436 16.2503 6.65675 16.25 7.375L16.2461 7.54395C16.207 8.38749 15.8744 9.19298 15.3027 9.81934C14.8183 10.3501 14.1895 10.7201 13.5 10.8916V10.374C13.9958 10.2298 14.4513 9.96637 14.8223 9.60059C15.2981 9.13133 15.6109 8.52149 15.7148 7.86133C15.8188 7.20122 15.708 6.52537 15.3994 5.93262C15.1565 5.46603 14.8003 5.07169 14.3672 4.78125L14.5107 4.2793ZM8.5 2.25C10.7101 2.25 12.5 4.03989 12.5 6.25C12.5 8.46011 10.7101 10.25 8.5 10.25C6.28989 10.25 4.5 8.46011 4.5 6.25C4.5 4.03989 6.28989 2.25 8.5 2.25ZM8.5 2.75C6.56636 2.75 5 4.31636 5 6.25C5 8.18364 6.56636 9.75 8.5 9.75C10.4336 9.75 12 8.18364 12 6.25C12 4.31636 10.4336 2.75 8.5 2.75Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'affiliate_group_settings'
                ]
            ],
            'affiliate_creatives'      => [
                'title'          => __('Promo Materials', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.1504 3.875L6.58691 6.12207L6.12305 6.30957L6.31055 6.77246L9.96289 15.8135L10.1504 16.2773L10.6143 16.0898L16.1777 13.8418L16.6416 13.6543L16.4541 13.1914L12.8018 4.15137L12.6143 3.6875L12.1504 3.875ZM7.46387 10.9629L6.50098 11.1504V15.75H9.39844L9.12109 15.0625L7.46387 10.9629ZM5.03711 10.3799L3.78223 13.4854L3.59863 13.9414L4.05078 14.1328L5.30566 14.666L6.00098 14.9619V10.5674L5.03711 10.3799ZM8.63379 7.18262C8.69526 7.15785 8.76417 7.15869 8.8252 7.18457C8.88618 7.21053 8.93414 7.25983 8.95898 7.32129C8.98375 7.38277 8.98389 7.45167 8.95801 7.5127C8.93207 7.57375 8.88182 7.62162 8.82031 7.64648C8.75882 7.67126 8.68993 7.67045 8.62891 7.64453C8.56813 7.61863 8.51998 7.56999 8.49512 7.50879C8.47027 7.44726 8.47113 7.37748 8.49707 7.31641C8.52302 7.25569 8.57162 7.20743 8.63281 7.18262H8.63379ZM6.00098 15.4609L5.68848 15.334L3.17578 14.3193C3.11437 14.2945 3.06494 14.2465 3.03906 14.1855C3.01318 14.1245 3.01322 14.0556 3.03809 13.9941L5.78711 7.1875L5.86328 7L5.78711 6.8125L5.56641 6.26562C5.55414 6.23528 5.54761 6.20265 5.54785 6.16992C5.54814 6.13718 5.55462 6.10437 5.56738 6.07422C5.58022 6.04399 5.59961 6.01617 5.62305 5.99316C5.64632 5.97038 5.67392 5.95266 5.7041 5.94043V5.93945L12.6582 3.13086C12.7196 3.10608 12.7886 3.10597 12.8496 3.13184C12.9106 3.15771 12.9585 3.20715 12.9834 3.26855L17.1982 13.6992C17.2105 13.7296 17.2171 13.7621 17.2168 13.7949C17.2165 13.8278 17.2091 13.8604 17.1963 13.8906C17.1835 13.9207 17.1649 13.9478 17.1416 13.9707C17.1183 13.9936 17.0909 14.0121 17.0605 14.0244H17.0596L10.1064 16.834H10.1055C10.0751 16.8462 10.0425 16.8528 10.0098 16.8525C9.97703 16.8522 9.9442 16.8448 9.91406 16.832C9.88406 16.8192 9.85684 16.8006 9.83398 16.7773C9.81107 16.754 9.79255 16.7266 9.78027 16.6963V16.6953L9.71387 16.5322L9.25098 16.6221V16.25H6.25098C6.18467 16.25 6.1211 16.2236 6.07422 16.1768C6.02735 16.1299 6.00098 16.0663 6.00098 16V15.4609Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'affiliate_creatives'
                ]
            ],
            'integration_settings'     => [
                'title'          => __('Integration Settings', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7.75 13.498C8.11223 13.498 8.46602 13.6101 8.76172 13.8193C9.0573 14.0285 9.28085 14.3245 9.40137 14.666L9.51855 15H17V15.5H9.51855L9.40137 15.834C9.28088 16.1752 9.05707 16.4705 8.76172 16.6797C8.46602 16.8889 8.11223 17.002 7.75 17.002C7.38777 17.002 7.03398 16.8889 6.73828 16.6797C6.44293 16.4705 6.21912 16.1752 6.09863 15.834L5.98145 15.5H3V15H5.98145L6.09863 14.666C6.21915 14.3245 6.4427 14.0285 6.73828 13.8193C7.03398 13.6101 7.38777 13.498 7.75 13.498ZM7.75 14C7.41848 14 7.10063 14.1318 6.86621 14.3662C6.63183 14.6006 6.5 14.9185 6.5 15.25C6.50003 15.5815 6.63182 15.8994 6.86621 16.1338C7.10063 16.3682 7.41851 16.5 7.75 16.5C8.08149 16.5 8.39937 16.3682 8.63379 16.1338C8.86818 15.8994 8.99997 15.5815 9 15.25C9 14.9185 8.86817 14.6006 8.63379 14.3662C8.39937 14.1318 8.08152 14 7.75 14ZM12.25 8.24805C12.6122 8.24805 12.966 8.36011 13.2617 8.56934C13.5573 8.77854 13.7809 9.07453 13.9014 9.41602L14.0186 9.75H17V10.25H14.0186L13.9014 10.584C13.7809 10.9252 13.5571 11.2205 13.2617 11.4297C12.966 11.6389 12.6122 11.752 12.25 11.752C11.8878 11.752 11.534 11.6389 11.2383 11.4297C10.9429 11.2205 10.7191 10.9252 10.5986 10.584L10.4814 10.25H3V9.75H10.4814L10.5986 9.41602C10.7191 9.07453 10.9427 8.77854 11.2383 8.56934C11.534 8.36011 11.8878 8.24805 12.25 8.24805ZM12.25 8.75C11.9185 8.75 11.6006 8.88179 11.3662 9.11621C11.1318 9.35063 11 9.66851 11 10C11 10.3315 11.1318 10.6494 11.3662 10.8838C11.6006 11.1182 11.9185 11.25 12.25 11.25C12.5815 11.25 12.8994 11.1182 13.1338 10.8838C13.3682 10.6494 13.5 10.3315 13.5 10C13.5 9.66851 13.3682 9.35063 13.1338 9.11621C12.8994 8.88179 12.5815 8.75 12.25 8.75ZM7.75 2.99805C8.11223 2.99805 8.46602 3.11011 8.76172 3.31934C9.0573 3.52854 9.28085 3.82453 9.40137 4.16602L9.51855 4.5H17V5H9.51855L9.40137 5.33398C9.28088 5.67525 9.05707 5.97054 8.76172 6.17969C8.46602 6.38892 8.11223 6.50195 7.75 6.50195C7.38777 6.50195 7.03398 6.38892 6.73828 6.17969C6.44293 5.97054 6.21912 5.67525 6.09863 5.33398L5.98145 5H3V4.5H5.98145L6.09863 4.16602C6.21915 3.82453 6.4427 3.52854 6.73828 3.31934C7.03398 3.11011 7.38777 2.99805 7.75 2.99805ZM7.75 3.5C7.41848 3.5 7.10063 3.63179 6.86621 3.86621C6.63183 4.10063 6.5 4.41851 6.5 4.75C6.50003 5.08148 6.63182 5.3994 6.86621 5.63379C7.10063 5.86817 7.41851 6 7.75 6C8.08149 6 8.39937 5.86817 8.63379 5.63379C8.86818 5.3994 8.99997 5.08148 9 4.75C9 4.41851 8.86817 4.10063 8.63379 3.86621C8.39937 3.63179 8.08152 3.5 7.75 3.5Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'integration_settings'
                ]
            ],
            'email_settings'           => [
                'title'          => __('Email Notifications', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 4.25H16.75C16.8163 4.25 16.8799 4.27636 16.9268 4.32324C16.9736 4.37013 17 4.43369 17 4.5V15.5C17 15.5663 16.9736 15.6299 16.9268 15.6768C16.8799 15.7236 16.8163 15.75 16.75 15.75H3.25C3.18369 15.75 3.12013 15.7236 3.07324 15.6768C3.02636 15.6299 3 15.5663 3 15.5V4.5C3 4.4337 3.02636 4.37013 3.07324 4.32324C3.12013 4.27636 3.1837 4.25 3.25 4.25ZM3.5 15.25H16.5V5.80957L15.666 6.55566L10.0508 11.584L4.33105 6.53711L3.5 5.80371V15.25ZM4.05273 5.625L9.71484 10.6211L10.0479 10.915L10.3789 10.6191L15.96 5.62207L16.9346 4.75H3.06055L4.05273 5.625Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'children'       => [
                    'email_settings'        => [
                        'title'   => __('Mail Settings', 'fluent-affiliate'),
                        'disable' => false,
                        'route'   => [
                            'name' => 'email_settings'
                        ]
                    ],
                    'notification_settings' => [
                        'title'   => __('Notification Settings', 'fluent-affiliate'),
                        'disable' => false,
                        'route'   => [
                            'name' => 'notification_settings'
                        ]
                    ]
                ],
                'route'          => [
                    'name' => 'email_settings'
                ]
            ],
            'registration_settings'    => [
                'title'          => __('Registration Settings', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 24 24" color="currentColor" fill="none"><path fill="currentColor" d="M7.25,9 C7.25,8.586 7.586,8.25 8,8.25 L16,8.25 C16.414,8.25 16.75,8.586 16.75,9 C16.75,9.414 16.414,9.75 16,9.75 L8,9.75 C7.586,9.75 7.25,9.414 7.25,9 Z M7.25,15 C7.25,14.586 7.586,14.25 8,14.25 L16,14.25 C16.414,14.25 16.75,14.586 16.75,15 C16.75,15.414 16.414,15.75 16,15.75 L8,15.75 C7.586,15.75 7.25,15.414 7.25,15 Z" /><path fill="currentColor" d="M12.057,1.75 L12.057,1.75 C14.248,1.75 15.969,1.75 17.312,1.931 C18.689,2.116 19.781,2.503 20.639,3.361 C21.497,4.219 21.884,5.311 22.069,6.688 C22.25,8.031 22.25,9.752 22.25,11.943 L22.25,12.057 C22.25,14.248 22.25,15.969 22.069,17.312 C21.884,18.689 21.497,19.781 20.639,20.639 C19.781,21.497 18.689,21.884 17.312,22.069 C15.969,22.25 14.248,22.25 12.057,22.25 L11.943,22.25 C9.752,22.25 8.031,22.25 6.688,22.069 C5.311,21.884 4.219,21.497 3.361,20.639 C2.503,19.781 2.116,18.689 1.931,17.312 C1.75,15.969 1.75,14.248 1.75,12.057 L1.75,11.943 C1.75,9.752 1.75,8.031 1.931,6.688 C2.116,5.311 2.503,4.219 3.361,3.361 C4.219,2.503 5.311,2.116 6.688,1.931 C8.031,1.75 9.752,1.75 11.943,1.75 L12.057,1.75 Z M6.888,3.417 C5.678,3.58 4.955,3.889 4.422,4.422 C3.889,4.955 3.58,5.678 3.417,6.888 C3.252,8.12 3.25,9.74 3.25,12 C3.25,14.26 3.252,15.88 3.417,17.112 C3.58,18.322 3.889,19.045 4.422,19.578 C4.955,20.111 5.678,20.42 6.888,20.583 C8.12,20.748 9.74,20.75 12,20.75 C14.26,20.75 15.88,20.748 17.112,20.583 C18.322,20.42 19.045,20.111 19.578,19.578 C20.111,19.045 20.42,18.322 20.583,17.112 C20.748,15.88 20.75,14.26 20.75,12 C20.75,9.74 20.748,8.12 20.583,6.888 C20.42,5.678 20.111,4.955 19.578,4.422 C19.045,3.889 18.322,3.58 17.112,3.417 C15.88,3.252 14.26,3.25 12,3.25 C9.74,3.25 8.12,3.252 6.888,3.417 Z" /></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'registration_settings'
                ]
            ],
            'permission_settings'      => [
                'title'          => __('Permission Management', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.36149 6.44141C5.54419 4.57171 7.62785 3.33317 10.0002 3.33317C12.3725 3.33317 14.4562 4.57171 15.6389 6.44141L17.0474 5.55043C15.5721 3.21816 12.9677 1.6665 10.0002 1.6665C7.03273 1.6665 4.42828 3.21816 2.95297 5.55043L4.36149 6.44141ZM10.0002 16.6665C7.62785 16.6665 5.54419 15.428 4.36149 13.5583L2.95297 14.4493C4.42828 16.7815 7.03273 18.3332 10.0002 18.3332C12.9677 18.3332 15.5721 16.7815 17.0474 14.4493L15.6389 13.5583C14.4562 15.428 12.3725 16.6665 10.0002 16.6665ZM10 6.6665C10.4602 6.6665 10.8333 7.0396 10.8333 7.49984C10.8333 7.96007 10.4602 8.33317 10 8.33317C9.53975 8.33317 9.16667 7.96007 9.16667 7.49984C9.16667 7.0396 9.53975 6.6665 10 6.6665ZM10 9.99984C11.3807 9.99984 12.5 8.88059 12.5 7.49984C12.5 6.11913 11.3807 4.99984 10 4.99984C8.61925 4.99984 7.5 6.11913 7.5 7.49984C7.5 8.88059 8.61925 9.99984 10 9.99984ZM10 12.4998C9.0795 12.4998 8.33333 13.246 8.33333 14.1665H6.66667C6.66667 12.3256 8.15905 10.8332 10 10.8332C11.8409 10.8332 13.3333 12.3256 13.3333 14.1665H11.6667C11.6667 13.246 10.9205 12.4998 10 12.4998ZM2.5 9.1665C2.03977 9.1665 1.66667 9.53959 1.66667 9.99984C1.66667 10.4601 2.03977 10.8332 2.5 10.8332C2.96023 10.8332 3.33333 10.4601 3.33333 9.99984C3.33333 9.53959 2.96023 9.1665 2.5 9.1665ZM0 9.99984C0 8.61909 1.11929 7.49984 2.5 7.49984C3.88071 7.49984 5 8.61909 5 9.99984C5 11.3806 3.88071 12.4998 2.5 12.4998C1.11929 12.4998 0 11.3806 0 9.99984ZM16.6667 9.99984C16.6667 9.53959 17.0397 9.1665 17.5 9.1665C17.9602 9.1665 18.3333 9.53959 18.3333 9.99984C18.3333 10.4601 17.9602 10.8332 17.5 10.8332C17.0397 10.8332 16.6667 10.4601 16.6667 9.99984ZM17.5 7.49984C16.1192 7.49984 15 8.61909 15 9.99984C15 11.3806 16.1192 12.4998 17.5 12.4998C18.8807 12.4998 20 11.3806 20 9.99984C20 8.61909 18.8807 7.49984 17.5 7.49984Z" fill="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'permission_settings'
                ]
            ],
            'migrator_settings'        => [
                'title'          => __('Migrator Settings', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.75 3H15.25C15.3163 3 15.3799 3.02636 15.4268 3.07324C15.4736 3.12012 15.5 3.18369 15.5 3.25V16.75C15.5 16.8163 15.4736 16.8799 15.4268 16.9268C15.3799 16.9736 15.3163 17 15.25 17H4.75C4.68369 17 4.62013 16.9736 4.57324 16.9268C4.52636 16.8799 4.5 16.8163 4.5 16.75V12.75H5V16.5H15V3.5H5V7.25H4.5V3.25C4.5 3.1837 4.52636 3.12013 4.57324 3.07324C4.62013 3.02636 4.6837 3 4.75 3ZM11.4492 10L9 11.959V10.25H3V9.75H9V8.04004L11.4492 10Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'migrator_settings'
                ]
            ],
            'license_settings'         => [
                'title'          => __('License Management', 'fluent-affiliate'),
                'disable'        => true,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.25C11.0609 2.25 12.078 2.67173 12.8281 3.42188C13.5783 4.17202 14 5.18913 14 6.25V7.5H16C16.0663 7.5 16.1299 7.52636 16.1768 7.57324C16.2236 7.62012 16.25 7.68369 16.25 7.75V16.75C16.25 16.8163 16.2236 16.8799 16.1768 16.9268C16.1299 16.9736 16.0663 17 16 17H4C3.93369 17 3.87012 16.9736 3.82324 16.9268C3.77636 16.8799 3.75 16.8163 3.75 16.75V7.75C3.75 7.6837 3.77636 7.62013 3.82324 7.57324C3.87013 7.52636 3.9337 7.5 4 7.5H6V6.25C6 5.18913 6.42173 4.17202 7.17188 3.42188C7.92202 2.67173 8.93913 2.25 10 2.25ZM4.25 16.5H15.75V8H4.25V16.5ZM10 2.75C9.07174 2.75 8.18177 3.11901 7.52539 3.77539C6.86901 4.43177 6.5 5.32174 6.5 6.25V7.5H13.5V6.25C13.5 5.32174 13.131 4.43177 12.4746 3.77539C11.8182 3.11901 10.9283 2.75 10 2.75ZM9.75 12.5107L9.5 12.3662C9.30941 12.2562 9.16043 12.0861 9.07617 11.8828C8.99194 11.6794 8.97722 11.4538 9.03418 11.2412C9.09115 11.0286 9.21696 10.841 9.3916 10.707C9.56624 10.573 9.77988 10.5 10 10.5C10.2201 10.5 10.4338 10.573 10.6084 10.707C10.783 10.841 10.9088 11.0286 10.9658 11.2412C11.0228 11.4538 11.0081 11.6794 10.9238 11.8828C10.8396 12.0861 10.6906 12.2562 10.5 12.3662L10.25 12.5107V14H9.75V12.5107Z" stroke="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'license_settings'
                ]
            ],
            'domain_settings'          => [
                'title'          => __('Domain Management', 'fluent-affiliate'),
                'disable'        => false,
                'svg_icon'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 17.5C5.85775 17.5 2.5 14.1422 2.5 10C2.5 5.85775 5.85775 2.5 10 2.5C14.1422 2.5 17.5 5.85775 17.5 10C17.5 14.1422 14.1422 17.5 10 17.5ZM8.2825 15.7502C7.54256 14.1807 7.1139 12.4827 7.02025 10.75H4.0465C4.19244 11.9042 4.67044 12.9911 5.42243 13.8788C6.17441 14.7664 7.16801 15.4166 8.2825 15.7502ZM8.5225 10.75C8.63575 12.5792 9.1585 14.2975 10 15.814C10.8642 14.2574 11.3691 12.5271 11.4775 10.75H8.5225ZM15.9535 10.75H12.9797C12.8861 12.4827 12.4574 14.1807 11.7175 15.7502C12.832 15.4166 13.8256 14.7664 14.5776 13.8788C15.3296 12.9911 15.8076 11.9042 15.9535 10.75ZM4.0465 9.25H7.02025C7.1139 7.51734 7.54256 5.81926 8.2825 4.24975C7.16801 4.58341 6.17441 5.23356 5.42243 6.12122C4.67044 7.00888 4.19244 8.09583 4.0465 9.25ZM8.52325 9.25H11.4767C11.3686 7.47295 10.864 5.74265 10 4.186C9.13576 5.74259 8.63092 7.47289 8.5225 9.25H8.52325ZM11.7175 4.24975C12.4574 5.81926 12.8861 7.51734 12.9797 9.25H15.9535C15.8076 8.09583 15.3296 7.00888 14.5776 6.12122C13.8256 5.23356 12.832 4.58341 11.7175 4.24975Z" fill="currentColor"/></svg>',
                'component_type' => 'StandAloneComponent',
                'route'          => [
                    'name' => 'domain_settings'
                ]
            ]
        ]);
    }
}

