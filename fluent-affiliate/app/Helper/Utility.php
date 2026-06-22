<?php
namespace FluentAffiliate\App\Helper;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Meta;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\Framework\Support\Arr;

class Utility
{
    public static function getApp($moudle = null)
    {
        return App::getInstance($moudle);
    }

    public static function defaultReferralSettings()
    {
        return apply_filters('fluent_affiliate/default_referral_settings', [
            'referral_variable'        => 'ref',
            'rate_type'                => 'percentage',
            'rate'                     => 20,
            'cookie_duration'          => 30,
            'referral_format'          => 'id',
            'payout_method'            => 'paypal',
            'number_format'            => 'comma_separated', // or dot_separated
            'currency'                 => 'USD',
            'currency_symbol_position' => 'left',
            'credit_last_referrer'     => 'yes',
            'exclude_shipping'         => 'yes',
            'exclude_tax'              => 'yes',
            'self_referral_disabled'   => 'yes',
            'exclude_discount'         => 'yes',
            'dashboard_page_id'        => '',
            'enabled_integrations'     => [],
        ]);
    }

    public static function updateReferralSettings($settings)
    {
        $prevSettings = self::getReferralSettings(false);

        $settings = wp_parse_args($settings, $prevSettings);

        update_option('_fa_referral_settings', $settings, 'yes');

        // Clear the cached settings
        return self::getReferralSettings(false);
    }

    public static function getReferralSettings($cached = true)
    {
        static $refSettings = null;
        if ($cached && $refSettings !== null) {
            return $refSettings;
        }

        $refSettings = get_option('_fa_referral_settings', null);

        $defaults = self::defaultReferralSettings();

        if ($refSettings) {
            $refSettings = wp_parse_args($refSettings, $defaults);
        } else {
            $refSettings = $defaults;
        }

        return $refSettings;
    }

    public static function getAffiliateParam($affiliate = null)
    {
        if (! $affiliate) {
            $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();
        }

        if (! $affiliate) {
            return null;
        }

        $referalFormat = self::getReferralSetting('referral_format', 'id');

        if ($referalFormat == 'id') {
            return $affiliate->id;
        }

        if ($referalFormat == 'username') {
            return $affiliate->user->user_login ?? null;
        }

        return null;
    }

    public static function getReferralSetting($key, $default = null)
    {
        $settings = self::getReferralSettings();
        return Arr::get($settings, $key, $default);
    }

    public static function isConnectorEnabled($key)
    {
        $settings = self::getReferralSettings();
        $enabledIntegrations = Arr::get($settings, 'enabled_integrations', []);

        return in_array($key, $enabledIntegrations);
    }

    public static function getQueryVarName()
    {
        $settings = self::getReferralSettings();
        return Arr::get($settings, 'referral_variable', 'ref');
    }

    public static function getCurrency()
    {
        $settings = self::getReferralSettings();
        return Arr::get($settings, 'currency', 'USD');
    }

    public static function getCurrencySymbol($currency = null)
    {
        $currency = $currency ?: self::getCurrency();
        $currency = strtoupper($currency);
        $symbols  = self::getApp('config')->get('currency.symbols', []);
        return Arr::get($symbols, $currency, $currency);
    }

    public static function getAffiliateByParamId($paramId)
    {
        $referalFormat = self::getReferralSetting('referral_format', 'id');
        $affiliate     = null;

        if ($referalFormat == 'id') {
            $affiliate = Affiliate::find($paramId);
        } else if ($referalFormat == 'username') {
            $affiliate = Affiliate::whereHas('user', function ($query) use ($paramId) {
                $query->where('user_login', $paramId);
            })->first();
        }

        return apply_filters('fluent_affiliate/affiliate_by_param', $affiliate, $paramId);
    }

    public static function getCurrentCookieAffiliate()
    {
        if (! isset($_COOKIE['f_aff'])) {
            return null;
        }

        $cookieData  = sanitize_text_field(wp_unslash($_COOKIE['f_aff'])); // format should be "affiliate_param|visit_id"
        $cookieParts = explode('|', $cookieData);

        if (count($cookieParts) !== 2) {
            return null;
        }

        $affiliateParam = sanitize_text_field($cookieParts[0]);

        return self::getAffiliateByParamId($affiliateParam);
    }

    public static function getCurrentCookieVisit()
    {
        if (! isset($_COOKIE['f_aff'])) {
            return null;
        }

        $cookieData  = sanitize_text_field(wp_unslash($_COOKIE['f_aff'])); // format should be "affiliate_param|visit_id"
        $cookieParts = explode('|', $cookieData);

        if (count($cookieParts) !== 2) {
            return null;
        }

        $visitId = (int) $cookieParts[1];
        if (! $visitId) {
            return null;
        }
        return Visit::find($visitId);
    }

    /**
     * @return bool
     */
    public static function wasReferred()
    {
        // @phpcs:ignore
        if (! empty($_COOKIE['f_aff'])) {
            return true;
        }

        return false;
    }

    public static function isDisabledSelfReferral()
    {
        $referralSetting = static::getReferralSettings();

        if ($referralSetting['self_referral_disabled'] === 'no') {
            return false;
        }

        return true;
    }

    public static function isSelfReference($customerEmail)
    {
        $affiliate = static::getCurrentCookieAffiliate();

        if ($affiliate && $affiliate->payment_email === $customerEmail) {
            return true;
        }

        $user = get_user_by('email', $customerEmail);

        if ($user && $affiliate->user_id == $user->ID) {
            return true;
        }

        return false;
    }

    /**
     * @param Affiliate $affiliate
     * @param int $amount
     * @return int
     */
    public static function calculateAffiliateAmount($affiliate, $amount = 0)
    {
        $rate            = 0;
        $rateType        = 'percentage';
        $affiliateAmount = 0;

        if (! $affiliate) {
            return $affiliateAmount;
        }

        if ($affiliate->rate_type === 'group') {
            $rate     = $affiliate->group->value['rate'];
            $rateType = $affiliate->group->value['rate_type'];
        }

        if ($affiliate->rate_type === 'default') {
            $globalSettings = static::getReferralSettings();
            $rate           = $globalSettings['rate'];
            $rateType       = $globalSettings['rate_type'];
        }

        if (! in_array($affiliate->rate_type, ['default', 'group'])) {
            $rate     = $affiliate->rate;
            $rateType = $affiliate->rate_type;
        }

        if ($rateType === 'percentage') {
            $affiliateAmount = $amount * ($rate / 100);
        } else {
            $affiliateAmount = $rate;
        }

        return $affiliateAmount ?: 0;
    }

    public static function getLastOrFirstReferredAffiliate($customerEmail)
    {
        $globalSettings = static::getReferralSettings();

        if ($globalSettings['credit_first_or_last_referrer'] !== 'yes') {
            return null;
        }

        $customer = Customer::where('email', $customerEmail)->first();

        if (! $customer) {
            return null;
        }

        $first       = $globalSettings['credit_first_or_last_affiliate_as_default'] === 'first';
        $affiliateID = $customer->by_affiliate_id;

        if (! $first) {
            $referral = Referral::where('customer_id', $customer->id)->orderBy('id', 'desc')->first();

            if ($referral) {
                $affiliateID = $referral->affiliate_id;
            }
        }

        return Affiliate::find($affiliateID);
    }

    public static function getPortalPageUrl()
    {
        $portalPageId = self::getReferralSetting('dashboard_page_id', null);
        if (! $portalPageId) {
            return home_url();
        }

        $portalPageUrl = get_permalink($portalPageId);
        if (! $portalPageUrl) {
            return home_url();
        }

        return apply_filters('fluent_affiliate/portal_page_url', $portalPageUrl);
    }

    public static function getAdminPageUrl($path = '')
    {
        $adminUrl = apply_filters('fluent_affiliate/admin_url', admin_url('admin.php?page=fluent-affiliate#/'));
        if ($path) {
            $adminUrl .= ltrim($path, '/');
        }

        return $adminUrl;
    }

    public static function defaultEmailSettings()
    {
        return [
            'logo'                 => '',
            'body_bg'              => '#f3f4f6',
            'content_bg'           => '#ffffff',
            'content_color'        => '#374151',
            'footer_content_color' => '#6b7280',
            'highlight_bg'         => 'rgb(249, 250, 251)',
            'highlight_color'      => '#374151',
            'font_family'          => '',
            'template'             => 'default',
            'email_footer'         => 'You are getting this email because you are an affiliate of {{site.name_with_url}}.',
            'send_from_email'      => '',
            'send_from_name'       => '',
            'reply_to_email'       => '',
            'reply_to_name'        => '',
            'disable_powered_by'   => 'no',
            'summary_email_day'    => 'daily',
            'admin_email'          => get_bloginfo('admin_email'),
        ];
    }

    /**
     * Get the email settings.
     *
     * @return array The email settings.
     */
    public static function getEmailSettings()
    {
        static $settings;
        if ($settings) {
            return $settings;
        }

        $default = self::defaultEmailSettings();

        $settings = array_filter(self::getOption('global_email_settings', $default));

        $settings = wp_parse_args($settings, $default);

        return $settings;
    }

    public static function setCache($key, $value, $expire = 3600)
    {
        $key = 'fa_' . $key;

        return wp_cache_set($key, $value, 'fluent_affiliate', $expire);
    }

    public static function getFromCache($key, $callback = false, $expire = 3600)
    {
        $key = 'fa_' . $key;

        $value = wp_cache_get($key, 'fluent_affiliate');

        if ($value !== false) {
            return $value;
        }

        if ($callback) {
            $value = $callback();
            if ($value) {
                wp_cache_set($key, $value, 'fluent_affiliate', $expire);
            }
        }

        return $value;
    }

    public static function forgetCache($key)
    {
        $key = 'fa_' . $key;
        return wp_cache_delete($key, 'fluent_affiliate');
    }

    public static function getRegisteredFeatures()
    {
        return apply_filters('fluent_affiliate/registered_features', [
            [
                'key'          => 'social_media_share',
                'title'        => __('Social Media Share', 'fluent-affiliate'),
                'description'  => __('Allow affiliates to share their referral links directly to social media platforms.', 'fluent-affiliate'),
                'icon'         => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 13.3996C14.3667 13.3996 13.8 13.6496 13.3667 14.0413L7.425 10.5829C7.46667 10.3913 7.5 10.1996 7.5 9.99961C7.5 9.79961 7.46667 9.60794 7.425 9.41628L13.3 5.99128C13.75 6.40794 14.3417 6.66628 15 6.66628C16.3833 6.66628 17.5 5.54961 17.5 4.16628C17.5 2.78294 16.3833 1.66628 15 1.66628C13.6167 1.66628 12.5 2.78294 12.5 4.16628C12.5 4.36628 12.5333 4.55794 12.575 4.74961L6.7 8.17461C6.25 7.75794 5.65833 7.49961 5 7.49961C3.61667 7.49961 2.5 8.61628 2.5 9.99961C2.5 11.3829 3.61667 12.4996 5 12.4996C5.65833 12.4996 6.25 12.2413 6.7 11.8246L12.6333 15.2913C12.5917 15.4663 12.5667 15.6496 12.5667 15.8329C12.5667 17.1746 13.6583 18.2663 15 18.2663C16.3417 18.2663 17.4333 17.1746 17.4333 15.8329C17.4333 14.4913 16.3417 13.3996 15 13.3996Z" fill="currentColor"/></svg>',
                'is_enabled'   => 'no',
                'is_pro'       => true,
                'has_settings' => true,
                'settings'     => [],
            ],
            [
                'key'          => 'affiliate_qr_code',
                'title'        => __('Affiliate QR Code', 'fluent-affiliate'),
                'description'  => __('Display a scannable QR code on the affiliate portal so affiliates can easily share their referral link. Customize the QR code colors to match your brand.', 'fluent-affiliate'),
                'icon'         => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1.5" y="1.5" width="6" height="6" rx="0.5" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="3" width="3" height="3" fill="currentColor"/><rect x="12.5" y="1.5" width="6" height="6" rx="0.5" stroke="currentColor" stroke-width="1.5"/><rect x="14" y="3" width="3" height="3" fill="currentColor"/><rect x="1.5" y="12.5" width="6" height="6" rx="0.5" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="14" width="3" height="3" fill="currentColor"/><rect x="12" y="12" width="2.5" height="2.5" fill="currentColor"/><rect x="15.5" y="12" width="2.5" height="2.5" fill="currentColor"/><rect x="12" y="15.5" width="2.5" height="2.5" fill="currentColor"/><rect x="15.5" y="15.5" width="2.5" height="2.5" fill="currentColor"/><rect x="12" y="12" width="2.5" height="2.5" fill="currentColor"/><rect x="18" y="12" width="0.5" height="2.5" fill="currentColor"/><rect x="12" y="18" width="2.5" height="0.5" fill="currentColor"/></svg>',
                'is_enabled'   => 'yes',
                'is_pro'       => false,
                'has_settings' => true,
                'settings'     => [],
            ],
        ]);
    }

    public static function isFeatureEnabled($key)
    {
        if (!defined('FLUENT_AFFILIATE_PRO_VERSION')) {
            return false;
        }

        return Arr::get(self::getOption('fluent_affiliate_features', []), $key . '.is_enabled') === 'yes';
    }

    public static function getFeaturesConfig($refresh = false)
    {
        static $features = null;

        if ($features !== null && !$refresh) {
            return $features;
        }

        $registered = self::getRegisteredFeatures();

        $saved = self::getOption('fluent_affiliate_features', []);

        $isPro = defined('FLUENT_AFFILIATE_PRO_VERSION');

        $features = [];
        foreach ($registered as $feature) {
            $key = $feature['key'];
            $canEnable = $isPro || empty($feature['is_pro']);
            $isDefaultEnabled = Arr::get($feature, 'is_enabled', 'yes');
            $feature['is_enabled'] = ($canEnable && Arr::get($saved, $key . '.is_enabled', $isDefaultEnabled) === 'yes') ? 'yes' : 'no';
            $features[] = $feature;
        }
   
        return $features;
    }

    public static function getOption($key, $default = null)
    {
        return self::getFromCache('option_' . $key, function () use ($key, $default) {
            $exist = Meta::where('object_type', 'option')
                ->where('meta_key', $key)
                ->first();

            if ($exist) {
                return $exist->value;
            }

            return $default;
        });
    }

    public static function updateOption($key, $value)
    {
        $exist = Meta::where('object_type', 'option')
            ->where('meta_key', $key)
            ->first();
        if ($exist) {
            $exist->value = $value;
            $exist->save();
        } else {
            $exist = Meta::create([
                'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key for performance
                'object_type' => 'option',
                'value'       => $value,
            ]);
        }

        self::setCache('option_' . $key, $value);

        return $exist;
    }

    public static function deleteOption($key)
    {
        Meta::where('object_type', 'option')
            ->where('meta_key', $key)
            ->delete();

        self::forgetCache('option_' . $key);
    }

    public static function getMaxRunTime()
    {
        if (function_exists('ini_get')) {
            $maxRunTime = (int) ini_get('max_execution_time');
            if ($maxRunTime === 0) {
                $maxRunTime = 60;
            }

            if ($maxRunTime <= 0) {
                return PHP_INT_MAX;
            }

        } else {
            $maxRunTime = 30;
        }

        if ($maxRunTime > 58) {
            $maxRunTime = 58;
        }

        $maxRunTime = $maxRunTime - 3;

        return apply_filters('fluent_affiliate/max_execution_time', $maxRunTime);
    }

    public static function asset($path)
    {
        $app = App::getInstance();
        return $app->url('assets/' . $path);
    }

    public static function getUpgradeUrl()
    {
        return 'https://fluentaffiliate.com/pricing/?utm_source=plugin&utm_medium=wp_install&utm_campaign=fa_upgrade';
    }

    public static function safeUnserialize($data)
    {
        if ($data && is_serialized($data)) {
            return @unserialize(trim($data), [
                'allowed_classes' => false,
            ]);
        }

        return $data;
    }

    public static function isRtl()
    {
        $rtl = apply_filters('fluent_affiliate/is_rtl', is_rtl());
        return $rtl;
    }
}
