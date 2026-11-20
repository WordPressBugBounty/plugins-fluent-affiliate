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
        add_filter('fluent_crm/funnel_icons', [$this, 'addFunnelCategoryIcon']);
    }

    public function addFunnelCategoryIcon($icons)
    {
        $key = strtolower(str_replace(' ', '', __('FluentAffiliate', 'fluent-affiliate')));

        $icons[$key] = ['svg' => '<svg width="20" height="20" viewBox="0 0 1199 1199" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="1199" height="1199" rx="239.8" fill="black"/><rect x="863.48" y="325.93" width="737.846" height="184.462" rx="92.2308" transform="rotate(120 863.48 325.93)" fill="#FEFFFF"/><rect x="935.942" y="691.179" width="276.692" height="184.462" rx="92.2308" transform="rotate(120 935.942 691.179)" fill="#FEFFFF"/><rect x="560.42" y="360.04" width="276.692" height="184.462" rx="92.2308" transform="rotate(120 560.42 360.04)" fill="#FEFFFF"/></svg>'];

        return $icons;
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
                'value' => (int)$affiliate->referrals
            ],
            [
                'title' => __('Total Visits', 'fluent-affiliate'),
                'value' => (int)$affiliate->visits
            ],
        ];

        // FluentCRM renders this widget with v-html, so every value is escaped here.
        $html = '<ul class="fc_full_listed">';
        $html .= '<li><span class="fc_list_sub">' . esc_html__('Affiliate ID', 'fluent-affiliate') . '</span> <span class="fc_list_value"><a href="' . esc_url(Utility::getAdminPageUrl('affiliates/' . (int)$affiliate->id)) . '">' . (int)$affiliate->id . '</a></span></li>';
        foreach ($stats as $stat) {
            $html .= '<li><span class="fc_list_sub">' . esc_html($stat['title']) . '</span> <span class="fc_list_value">' . esc_html($stat['value']) . '</span></li>';
        }
        $html .= '</ul>';

        $widgets['fluent_affiliate'] = [
            'title'   => __('Affiliate Profile', 'fluent-affiliate'),
            'content' => $html
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
            // source_ref_id is a transaction id only in funnels started by a payout
            if ($funnelSub && $funnelSub->source_trigger_name === 'fluent_affiliate/payout/transaction/transaction_updated_to_paid') {
                $transaction = Transaction::find($funnelSub->source_ref_id);
                if ($transaction && $transaction->affiliate_id != $affiliate->id) {
                    $transaction = null;
                }
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
            // source_ref_id is a referral id only in funnels started by a referral
            if ($funnelSub && $funnelSub->source_trigger_name === 'fluent_affiliate/referral_marked_unpaid') {
                $referral = Referral::find($funnelSub->source_ref_id);
                if ($referral && $referral->affiliate_id != $affiliate->id) {
                    $referral = null;
                }
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

        $newCode = '{{referral.' . $valueKey . '|' . $defaultValue . '}}';

        return apply_filters('fluent_affiliate/parse_smart_codes', $newCode, [
            'referral' => $referral
        ], 'text');
    }

}
