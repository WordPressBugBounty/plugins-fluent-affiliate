<?php

namespace FluentAffiliate\App\Modules\Portal;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Modules\Auth\AuthHandler;
use FluentAffiliate\App\Services\TransStrings;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Support\Str;

class CustomerPortal
{
    private $slug = 'fluent-affiliate';

    public function register()
    {
        add_shortcode('fluent_affiliate_portal', [$this, 'handlePortalShortCode']);
        (new AuthHandler())->register();
    }

    public function handlePortalShortCode($atts = [])
    {
        return $this->renderPortal($atts);
    }

    private function renderPortal($atts = [])
    {
        $userId = get_current_user_id();

        if (!$userId) {
            ob_start();
            do_action('fluent_affiliate/render_login_form');
            return ob_get_clean();
        }

        $affiliate = Affiliate::where('user_id', get_current_user_id())->first();

        if (!$affiliate) {
            ob_start();
            do_action('fluent_affiliate/render_signup_form');
            return ob_get_clean();
        }

        if ($affiliate->status !== 'active') {
            if ($affiliate->status == 'pending') {
                $html = '<div style="text-align: center; border-radius: 8px; padding: 30px 16px; border: 1px solid var(--fa-primary-border, #E1E4EA);" class="fa-no-access">';
                $html .= '<h3>' . __('Affiliate application is in review', 'fluent-affiliate') . '</h3>';
                $html .= '<p>' . __('Thank you for applying our Affiliate Program! Your application is under review, and we\'ll notify you once it\'s approved. Please check back soon or contact support for assistance.', 'fluent-affiliate') . '</p>';
                $html .= '</div>';

                return apply_filters('fluent_affiliate/portal/pending_message', $html, $affiliate);
            }

            $html = '<div style="text-align: center; border-radius: 8px; padding: 30px 16px; border: 1px solid var(--fa-primary-border, #E1E4EA);" class="fa-no-access">';
            $html .= '<h3>' . __('Affiliate account is not active', 'fluent-affiliate') . '</h3>';
            $html .= '<p>' . __('Your affiliate registration has been rejected. If you believe this is an error, please contact support for assistance.', 'fluent-affiliate') . '</p>';
            $html .= '</div>';

            return apply_filters('fluent_affiliate/portal/inactive_message', $html, $affiliate);
        }


        $isModern = Arr::get($atts, 'layout', 'classic') == 'modern';

        $this->loadAssets();
        $this->enqueueAsset($isModern);

        // Register and enqueue the loading animation styles
        add_action('wp_enqueue_scripts', [$this, 'enqueueLoadingStyles']);

        // Return the markup
        $markup = '
            <div id="fa-customer-portal">
                  <div class="fa-loading-wrapper">
                    <div class="fa-loading">
                      <p>Loading...</p>
                      <div class="fa-loading-line"></div>
                      <div class="fa-loading-line"></div>
                     <div class="fa-loading-line"></div>
                    </div>
                  </div>
            </div>';

        return $markup;
    }

    public function loadAssets()
    {

        $assetsVersion = App::getInstance()->config->get('app.env') === 'dev' ? time() : FLUENT_AFFILIATE_VERSION;
        wp_enqueue_script('fluent_affiliate_porral',
            FLUENT_AFFILIATE_URL . 'assets/public/customer/app.min.js',
            [],
            FLUENT_AFFILIATE_VERSION,
            true
        );

        if (Utility::isRtl()) {
            wp_enqueue_style(
                'fluent_affiliate_portal_style',
                FLUENT_AFFILIATE_URL . 'assets/public/customer/app.rtl.css',
                array(),
                $assetsVersion
            );
        } else {
            wp_enqueue_style(
                'fluent_affiliate_portal_style',
                FLUENT_AFFILIATE_URL . 'assets/public/customer/app.css',
                array(),
                $assetsVersion
            );
        }
    }

    private function getRestInfo()
    {

        $application = Utility::getApp();

        $restNameSpace = $application->config->get('app.rest_namespace');
        $restVersion = $application->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($restNameSpace . '/' . $restVersion),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $restNameSpace,
            'version'   => $restVersion
        ];
    }

    private function enqueueAsset($isModern)
    {
        $affiliate = Affiliate::where('user_id', get_current_user_id())->first();
        if (!$affiliate) {
            return;
        }

        $brandedCoupons = $affiliate->getAttachedCoupons('view');

        $formattedCoupons = [];
        foreach ($brandedCoupons as $coupon) {
            $formattedCoupons[] = [
                'code'        => $coupon['code'],
                'description' => $coupon['description'],
            ];
        }

        wp_localize_script('fluent_affiliate_porral', 'fluentAffiliatePortal', [
            'slug'             => $this->slug,
            'nonce'            => wp_create_nonce($this->slug),
            'rest'             => $this->getRestInfo(),
            'site_info'        => [
                'site_url'  => home_url('/'),
                'site_name' => get_bloginfo('name'),
                'aff_var'   => Utility::getReferralSetting('referral_variable', 'ref'),
                'aff_value' => Utility::getAffiliateParam($affiliate)
            ],
            'additional_sites' => apply_filters('fluent_affiliate/portal/additional_sites', []),
            'i18n'             => TransStrings::getPortalStrings(),
            'user'             => $affiliate->user_details,
            'branded_coupons'  => $formattedCoupons,
            'currency'         => $this->getCurrency(),
            'is_modern'        => $isModern,
            'menu_items'       => Helper::getPortalMenuItems($affiliate)
        ]);
    }

    /**
     * Enqueue loading animation styles
     */
    public function enqueueLoadingStyles()
    {
        // Register a handle for our loading styles
        wp_register_style(
            'fluent-affiliate-loading-style',
            false, // No stylesheet URL since we're using inline styles
            [], // Dependencies array
            FLUENT_AFFILIATE_VERSION // Add your plugin's version constant
        );

        // Enqueue the registered style
        wp_enqueue_style('fluent-affiliate-loading-style');

        // Add our inline styles to the registered handle
        $style = '
        .fa-loading-wrapper {
          min-height: 200px;
          width: 100%;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .fa-loading {
          min-width: 100px;
          min-height: 100px;
          margin: 0 10px 10px 0;
          padding: 20px 20px 20px;
          border-radius: 5px;
          text-align: center;
        }

        .fa-loading-line {
          display: inline-block;
          width: 15px;
          height: 15px;
          border-radius: 15px;
          background-color: #4b9cdb;
        }

        .fa-loading-line:nth-last-child(1) {
            animation: loadingC 0.6s 0.1s linear infinite;
        }
        .fa-loading-line:nth-last-child(2) {
          animation: loadingC 0.6s 0.2s linear infinite;
        }
        .fa-loading-line:nth-last-child(3) {
          animation: loadingC 0.6s 0.3s linear infinite;
        }

      @keyframes loadingC {
          0 {
            transform: translate(0, 0);
          }
          50% {
            transform: translate(0, 15px);
          }
          100% {
            transform: translate(0, 0);
          }
      }
    ';

        wp_add_inline_style('fluent-affiliate-loading-style', $style);
    }

    protected function getCurrency()
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
        ];
    }
}
