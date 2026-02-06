<?php

namespace FluentAffiliate\App\Modules\Tracker;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Services\VisitService;
use FluentAffiliate\Framework\Support\Arr;

class Track
{
    public function init()
    {
        add_action('wp_enqueue_scripts', [$this, 'loadTrackerJs'], 9999);
        add_action('wp_ajax_fluent_aff_count_visit', [$this, 'trackVisitAjax']);
        add_action('wp_ajax_nopriv_fluent_aff_count_visit', [$this, 'trackVisitAjax']);
    }

    public function loadTrackerJs()
    {
        if (!apply_filters('fluent_affiliate/will_load_tracker_js', true)) {
            return;
        }

        wp_enqueue_script('fluent_aff_public', FLUENT_AFFILIATE_URL . 'assets/public/fluent_aff.js', [], FLUENT_AFFILIATE_VERSION, true);
        wp_localize_script('fluent_aff_public', 'fluent_aff_vars', $this->getFluentAffVars());
    }

    public function getFluentAffVars()
    {
        $creditLastReferrer = Utility::getReferralSetting('credit_last_referrer', 'yes') === 'yes';

        return apply_filters('fluent_affiliate_tracker_vars', [
            'duration_days' => Utility::getReferralSetting('cookie_duration', 30),
            'aff_param'     => Utility::getReferralSetting('referral_variable', 'ref'),
            'other_params'  => [
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'visit_id' // for multi domain tracking
            ],
            'credit_last'   => $creditLastReferrer ? 1 : '',
            'request_url'   => admin_url('admin-ajax.php?action=fluent_aff_count_visit'),
        ]);
    }

    public function trackVisitAjax()
    {
        /**
         * @var $request \FluentAffiliate\Framework\Http\Request\Request
        */
        $request = App::make('request');
        //Arr::get($_POST, 'refer', '');
        $refer = $request->post('refer', '');

//        $data = array_filter([
//            'affiliate_param' => sanitize_text_field(Arr::get($_POST, '__aff_id__', '')),
//            'referrer'        => $refer ? sanitize_url($refer) : '',
//            'url'             => sanitize_url(Arr::get($_POST, 'url', '')),
//            'utm_campaign'    => sanitize_text_field(Arr::get($_POST, 'utm_campaign', '')),
//            'utm_source'      => sanitize_text_field(Arr::get($_POST, 'utm_source', '')),
//            'utm_medium'      => sanitize_text_field(Arr::get($_POST, 'utm_medium', '')),
//            'visit_id'        => (int) Arr::get($_POST, 'visit_id', ''),
//        ]);

        $data = array_filter([
            'affiliate_param' => sanitize_text_field( $request->post('__aff_id__', '')),
            'referrer'        => $refer ? sanitize_url($refer) : '',
            'url'             => sanitize_url( $request->post('url', '')),
            'utm_campaign'    => sanitize_text_field($request->post( 'utm_campaign', '')),
            'utm_source'      => sanitize_text_field($request->post( 'utm_source', '')),
            'utm_medium'      => sanitize_text_field($request->post( 'utm_medium', '')),
            'visit_id'        => (int) $request->post( 'visit_id', ''),
        ]);

        if (empty($data['affiliate_param']) || empty($data['url'])) {
            wp_send_json([
                'message' => 'invalid param',
                'time'    => time()
            ]);
        }

        $affiliate = Utility::getAffiliateByParamId($data['affiliate_param']);

        if (!$affiliate) {
            wp_send_json([
                'message' => 'invalid aff',
                'time'    => time()
            ]);
        }

        if ($affiliate->status !== 'active') {
            wp_send_json([
                'message' => 'affiliate_inactive',
                'time'    => time()
            ]);
        }

        $oldAffiliate = Utility::getCurrentCookieAffiliate();

        if (
            ($oldAffiliate && $oldAffiliate->id == $affiliate->id) ||
            ($oldAffiliate && Utility::getReferralSetting('credit_last_referrer') !== 'yes')
        ) {
            wp_send_json([
                'message' => 'already_exist',
                'time'    => time()
            ]);
        }

        $visit = null;
        if (!empty($data['visit_id'])) {
            $exisitngVisit = Visit::find($data['visit_id']);
            if ($exisitngVisit && $exisitngVisit->affiliate_id == $affiliate->id) {
                $visit = $exisitngVisit;
            }
        }

        if (!$visit) {
            $visit = [
                'affiliate_id' => $affiliate->id,
                'user_id'      => get_current_user_id(),
                'url'          => substr(Arr::get($data, 'url', ''), 0, 1000),
                'referrer'     => substr(Arr::get($data, 'referrer', ''), 0, 1000),
                'utm_source'   => substr(Arr::get($data, 'utm_source', ''), 0, 100),
                'utm_medium'   => substr(Arr::get($data, 'utm_medium', ''), 0, 100),
                'utm_campaign' => substr(Arr::get($data, 'utm_campaign', ''), 0, 100),
                'ip'           => VisitService::getIp(),
            ];

            $visit = VisitService::addVisit($affiliate, $visit);
        }

        if (!$visit) {
            wp_send_json([
                'message' => 'no',
                'time'    => time()
            ]);
        }

        // set the cookie now
        $cookieValue = $data['affiliate_param'] . '|' . $visit->id;
        $duration = time() + Utility::getReferralSetting('cookie_duration', 30) * 24 * 60 * 60; // convert days to seconds
        setcookie('f_aff', $cookieValue, $duration, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);

        wp_send_json([
            'message'  => 'success',
            'visit_id' => $visit->id,
            'time'     => time()
        ]);
    }
}
