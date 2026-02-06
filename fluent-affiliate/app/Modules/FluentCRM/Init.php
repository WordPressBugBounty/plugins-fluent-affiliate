<?php

namespace FluentAffiliate\App\Modules\FluentCRM;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Services\EmailNotificationSettings;
use FluentAffiliate\Framework\Support\Arr;
use FluentCrm\App\Models\FunnelSubscriber;

class Init
{
    /**
     * Initialize the FluentCRM module.
     */
    public function register()
    {
        $this->registerAutomations();
        $this->registerSmartCodes();

        add_filter('fluent_crm/subscriber_info_widgets', [$this, 'pushInfoWidgetToContact'], 99, 2);
        add_filter('fluent_affiliate/affiliate_widgets', [$this, 'pushInfoWidgetToAffiliate'], 99, 2);
    }

    public function registerAutomations()
    {
        // Register Triggers Here
        new AffiliateApprovedTrigger();
        new AffiliateRegisteredAsPendingTrigger();
        new ReferralCreatedTrigger();
        new PayoutSentTrigger();

        // Register Actions Here
        new CreateAffiliateAction();

        (new DeepIntegration())->init();
    }

    private function registerSmartCodes()
    {
        add_filter('fluent_crm_funnel_context_smart_codes', [$this, 'pushContextSmartCodes'], 10, 2);

        add_filter('fluent_crm/smartcode_group_callback_fa_affiliate', array($this, 'parseAffiliateSmartCodes'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_fa_transaction', array($this, 'parseTransactionSmartCodes'), 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_fa_referral', array($this, 'parseReferralSmartCodes'), 10, 4);
    }

    public function pushInfoWidgetToContact($widgets, $subscriber)
    {

        $userId = $subscriber->user_id;
        if (!$userId) {
            return $widgets;
        }

        $affiliate = \FluentAffiliate\App\Models\Affiliate::query()
            ->where('user_id', $userId)
            ->first();

        if (!$affiliate) {
            return $widgets;
        }

        $rateDetails = $affiliate->getRateDetails();

        $stats = [
            [
                'title' => __('Affiliate ID', 'fluent-affiliate'),
                'value' => '<a href="' . Utility::getAdminPageUrl('affiliates/' . $affiliate->id) . '">' . $affiliate->id . '</a>'
            ],
            [
                'title' => __('Status', 'fluent-affiliate'),
                'value' => $affiliate->status
            ],
            [
                'title' => __('Commission Rate', 'fluent-affiliate'),
                'value' => Arr::get($rateDetails, 'human_readable')
            ],
            [
                'title' => __('Total Earnings', 'fluent-affiliate'),
                'value' => Helper::formatMoney($affiliate->total_earnings),
            ],
            [
                'title' => __('Unpaid Earnings', 'fluent-affiliate'),
                'value' => Helper::formatMoney($affiliate->unpaid_earnings)
            ],
            [
                'title' => __('Total Referrals', 'fluent-affiliate'),
                'value' => $affiliate->referrals
            ],
            [
                'title' => __('Total Visits', 'fluent-affiliate'),
                'value' => $affiliate->visits
            ],
        ];

        $html = '<ul class="fc_full_listed">';
        foreach ($stats as $stat) {
            $html .= '<li><span class="fc_list_sub">' . $stat['title'] . '</span> <span class="fc_list_value">' . $stat['value'] . '</span></li>';
        }
        $html .= '</ul>';

        $widgets['fluent_affiliate'] = [
            'title'   => __('Affiliate Profile', 'fluent-affiliate'),
            'content' => $html
        ];

        return $widgets;
    }

    public function pushInfoWidgetToAffiliate($widgets, $affiliate)
    {
        $subscriber = FluentCrmApi('contacts')->getContactByUserRef($affiliate->user_id);
        if (!$subscriber) {
            return $widgets;
        }

        $substats = $subscriber->stats();

        $statusClass = 'fa_badge pending';
        if ($subscriber->status == 'subscribed') {
            $statusClass = 'fa_badge active';
        }

        $lists = '';
        foreach ($subscriber->lists as $list) {
            $lists .= '<span class="fa_badge unpaid">' . esc_html($list->title) . '</span> ';
        }

        $tags = '';
        foreach ($subscriber->tags as $tag) {
            $tags .= '<span class="fa_badge unpaid">' . esc_html($tag->title) . '</span> ';
        }

        $statsHtml = '<span class="fa_badge unpaid">' . __('Emails:', 'fluent-affiliate') . ' ' . $substats['emails'] . '</span> ';
        $statsHtml .= '<span class="fa_badge unpaid">' . __('Opens:', 'fluent-affiliate') . ' ' . $substats['opens'] . '</span> ';
        $statsHtml .= '<span class="fa_badge unpaid">' . __('Clicks:', 'fluent-affiliate') . ' ' . $substats['clicks'] . '</span> ';


        $stats = [
            [
                'title' => __('Contact Status', 'fluent-affiliate'),
                'value' => '<span class="' . $statusClass . '">' . $subscriber->status . '</span>'
            ],
            [
                'title' => __('Lists', 'fluent-affiliate'),
                'value' => $lists
            ],
            [
                'title' => __('Tags', 'fluent-affiliate'),
                'value' => $tags
            ],
            [
                'title' => __('Stats', 'fluent-affiliate'),
                'value' => $statsHtml
            ]
        ];

        $html = '';
        foreach ($stats as $stat) {
            $html .= '<div class="widget_item"><div class="item_title">' . $stat['title'] . '</div> <div class="item_description">' . $stat['value'] . '</div></div>';
        }

        $widgets['fluent_affiliate'] = [
            'title'   => __('CRM Profile', 'fluent-affiliate'),
            'content' => $html,
            'action'  => '<a style="text-decoration: none;" href="' . fluentcrm_menu_url_base('subscribers/' . $subscriber->id) . '">' . __('View Profile', 'fluent-affiliate') . '</a>'
        ];

        return $widgets;
    }

    public function pushContextSmartCodes($codes, $context)
    {
        $affiliateContexts = [
            'fluent_affiliate/affiliate_status_to_active',
            'fluent_affiliate/affiliate_created',
            'fluent_affiliate/payout/transaction/transaction_updated_to_paid',
            'fluent_affiliate/referral_marked_unpaid'
        ];

        if (!in_array($context, $affiliateContexts)) {
            return $codes;
        }

        $allSmartCodes = EmailNotificationSettings::getSmartCodes();

        $keyedSmatrCodes = [];

        foreach ($allSmartCodes as $codeGroup) {
            $key = $codeGroup['key'];
            $acceptedKeys = ['affiliate', 'transaction', 'referral'];
            if (!in_array($key, $acceptedKeys)) {
                continue;
            }

            if ($key === 'referral') {
                $codeGroup['title'] = 'Affiliate Referral';
            }

            $codeGroup['key'] = 'fa_' . $codeGroup['key'];

            $contextCodes = Arr::get($codeGroup, 'shortcodes', []);

            $formattedItems = [];
            foreach ($contextCodes as $codeKey => $code) {
                $newKey = (string)str_replace($key . '.', $codeGroup['key'] . '.', $codeKey);
                $formattedItems[$newKey] = $code;
            }

            $codeGroup['shortcodes'] = $formattedItems;

            $keyedSmatrCodes[$key] = $codeGroup;
        }

        $codes[] = Arr::get($keyedSmatrCodes, 'affiliate', []);

        if ($context == 'fluent_affiliate/payout/transaction/transaction_updated_to_paid') {
            $codes[] = Arr::get($keyedSmatrCodes, 'transaction', []);
        }

        if ($context == 'fluent_affiliate/referral_marked_unpaid') {
            $codes[] = Arr::get($keyedSmatrCodes, 'referral', []);
        }

        return $codes;
    }

    public function parseAffiliateSmartCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return $defaultValue;
        }

        $user = User::find($userId);
        if (!$user || !$user->affiliate) {
            return $defaultValue;
        }

        $affiliate = $user->affiliate;
        $newCode = '{{affiliate.' . $valueKey . '|' . $defaultValue . '}}';

        return apply_filters('fluent_affiliate/parse_smart_codes', $newCode, [
            'affiliate' => $affiliate
        ], 'text');
    }

    public function parseTransactionSmartCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return $defaultValue;
        }

        $user = User::find($userId);
        if (!$user || !$user->affiliate) {
            return $defaultValue;
        }

        $affiliate = $user->affiliate;

        $transaction = null;

        if ($subscriber->funnel_subscriber_id) {
            $funnelSub = FunnelSubscriber::find($subscriber->funnel_subscriber_id);
            if ($funnelSub) {
                $transaction = Transaction::find($funnelSub->source_ref_id);
            }
        }

        if (!$transaction) {
            // get the last transaction of the affiliate
            $transaction = Transaction::query()
                ->where('affiliate_id', $affiliate->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$transaction) {
            return $defaultValue;
        }

        $newCode = '{{transaction.' . $valueKey . '|' . $defaultValue . '}}';

        return apply_filters('fluent_affiliate/parse_smart_codes', $newCode, [
            'transaction' => $transaction
        ], 'text');
    }

    public function parseReferralSmartCodes($code, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            return $defaultValue;
        }

        $user = User::find($userId);
        if (!$user || !$user->affiliate) {
            return $defaultValue;
        }

        $affiliate = $user->affiliate;

        $referral = null;

        if ($subscriber->funnel_subscriber_id) {
            $funnelSub = FunnelSubscriber::find($subscriber->funnel_subscriber_id);
            if ($funnelSub) {
                $referral = Referral::find($funnelSub->source_ref_id);
            }
        }

        if (!$referral) {
            // get the last transaction of the affiliate
            $referral = Referral::query()
                ->where('affiliate_id', $affiliate->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$referral) {
            return $defaultValue;
        }

        $newCode = '{{transaction.' . $valueKey . '|' . $defaultValue . '}}';

        return apply_filters('fluent_affiliate/parse_smart_codes', $newCode, [
            'referral' => $referral
        ], 'text');
    }

}
