<?php
namespace FluentAffiliate\App\Http\Controllers\Portal;

use FluentAffiliate\App\Http\Controllers\Controller;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Helper\Utility;

class PortalController extends Controller
{
    public function getStats(Request $request)
    {
        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();
        if (! $affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $conversationRate = $affiliate->visits > 0 ? round(($affiliate->referrals / $affiliate->visits) * 100, 2) : 0;

        $summary = [
            'total_paid'      => [
                'title'       => __('Total Paid', 'fluent-affiliate'),
                'amount'      => $affiliate->total_earnings - $affiliate->unpaid_earnings,
                'is_currency' => true,
                'icon'        => 'MoneyPaid',
            ],
            'total_unpaid'    => [
                'title'       => __('Total Unpaid', 'fluent-affiliate'),
                'amount'      => $affiliate->unpaid_earnings,
                'is_currency' => true,
                'icon'        => 'MoneyUnPaid',
            ],
            'total_referrals' => [
                'title'       => __('Total Referrals', 'fluent-affiliate'),
                'amount'      => $affiliate->referrals,
                'is_currency' => false,
                'is_number'   => true,
                'icon'        => 'LinkReferal',
            ],
            'conversion_rate' => [
                'title'       => __('Conversion Rate', 'fluent-affiliate'),
                'amount'      => number_format($conversationRate, 2) . '%',
                'is_currency' => false,
                'is_number'   => false,
                'icon'        => 'PercentIcon',
            ],
        ];

        $recentReferrals = Referral::query()
            ->where('affiliate_id', $affiliate->id)
            ->select(['description', 'created_at', 'status', 'amount'])
            ->whereIn('status', ['paid', 'unpaid', 'rejected'])
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get();

        foreach ($recentReferrals as $referral) {
            $referral->human_date = $referral->created_at->format('d M Y, H:i');
        }

        return [
            'stats'              => $summary,
            'recent_referrals'   => $recentReferrals,
            'portal_notice_html' => apply_filters('fluent_affiliate/portal_notice_html', ''),
        ];
    }

    public function getReferrals(Request $request)
    {
        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();

        if (! $affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $referrals = Referral::query()->where('affiliate_id', $affiliate->id)
            ->whereIn('status', ['paid', 'unpaid', 'rejected', 'cancelled'])
            ->orderBy('id', 'DESC')
            ->paginate();

        foreach ($referrals as $referral) {
            $referral->human_date = $referral->created_at->format('d M Y, H:i');

            $referral->makeHidden(['affiliate_id', 'customer_id', 'parent_id', 'id', 'order_total', 'payout_id', 'payout_transaction_id', 'products', 'provider', 'provider_id', 'provider_sub_id', 'settings', 'visit_id']);

        }

        return [
            'referrals' => $referrals,
        ];
    }

    public function getTransactions(Request $request)
    {

        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();

        if (! $affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $transactions = Transaction::query()->where('affiliate_id', $affiliate->id)
            ->orderBy('id', 'DESC')
            ->with(['payout'])
            ->paginate();

        $formattedData = [];

        foreach ($transactions as $transaction) {
            $formattedData[] = [
                'human_date'  => $transaction->created_at->format('d M Y, H:i'),
                'amount'      => $transaction->total_amount,
                'status'      => $transaction->status,
                'description' => $transaction->payout ? $transaction->payout->title : '--',
            ];
        }

        return [
            'transactions' => [
                'data'         => $formattedData,
                'total'        => $transactions->total(),
                'per_page'     => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
            ],
        ];
    }

    public function getVisits(Request $request)
    {

        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();

        if (! $affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $visits = Visit::query()->where('affiliate_id', $affiliate->id)
            ->with(['referrals' => function ($query) {
                $query->select(['id', 'visit_id', 'status', 'amount'])
                    ->whereIn('status', ['paid', 'unpaid', 'rejected']);
            }])
            ->select(['id', 'url', 'referrer', 'utm_campaign', 'referral_id', 'utm_medium', 'utm_source', 'created_at'])
            ->orderBy('id', 'DESC')
            ->paginate();

        foreach ($visits as $visit) {
            $visit->human_date = $visit->created_at->format('d M Y, H:i');

            $referrals = $visit->referrals;
            $total     = 0;
            foreach ($referrals as $referral) {
                $total += $referral->amount;
            }

            $visit->total_referral_amount = $total;
            $visit->is_converted          = $total ? true : false;

            $visit->makeHidden(['referral_id', 'id', 'refferals']);
        }

        return [
            'visits' => $visits,
        ];

    }

    public function getSettings(Request $request)
    {
        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();

        if (!$affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        $formFields = [];

        if ($payoutMethod === 'bank_transfer') {
            $formFields['bank_details'] = [
                'type'      => 'textarea',
                'atts'      => [
                    'size' => 'large',
                    'rows' => 3,
                ],
                'label'     => __('Bank Details', 'fluent-affiliate'),
                'help_text' => __('Enter your bank account details for payouts.', 'fluent-affiliate'),
            ];
        } else {
            $formFields['payment_email'] = [
                'type'      => 'text',
                'atts'      => [
                    'size' => 'large',
                ],
                'data_type' => 'email',
                'label'     => __('Payment Email (PayPal)', 'fluent-affiliate'),
                'help_text' => __('This email will be used to send payouts.', 'fluent-affiliate'),
            ];
        }

        $formFields['ref_email_notification'] = [
            'atts'           => [
                'size' => 'large',
            ],
            'type'           => 'inline_checkbox',
            'checkbox_label' => __('Enable Referral Email Notification', 'fluent-affiliate'),
            'help_text'      => __('If enabled then you will receive email notification on successful referrals', 'fluent-affiliate'),
            'true_value'     => 'yes',
            'false_value'    => 'no',
        ];

        $settings = [
            'ref_email_notification' => $affiliate->isNewRefEmailEnabled() ? 'yes' : 'no',
        ];

        if ($payoutMethod === 'bank_transfer') {
            $settings['bank_details'] = $affiliate->bank_details;
        } else {
            $settings['payment_email'] = $affiliate->payment_email;
        }

        return [
            'settings'    => $settings,
            'form_fields' => $formFields,
        ];
    }

    public function updateSettings(Request $request)
    {
        $affiliate = Affiliate::query()->where('user_id', get_current_user_id())->first();

        if (! $affiliate) {
            return $this->sendError([
                'message' => __('Affiliate Account could not be found', 'fluent-affiliate'),
            ]);
        }

        $data = $request->get('settings', []);
        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        $rules = [
            'ref_email_notification' => 'required|in:yes,no',
        ];

        $messages = [
            'ref_email_notification.required' => __('Please select an option for referral email notification', 'fluent-affiliate'),
            'ref_email_notification.in'       => __('Invalid option selected for referral email notification', 'fluent-affiliate'),
        ];

        if ($payoutMethod === 'bank_transfer') {
            $rules['bank_details'] = 'required|string|max:5000';
            $messages['bank_details.required'] = __('Bank details are required', 'fluent-affiliate');
        } else {
            $rules['payment_email'] = 'required|email|max:191';
            $messages['payment_email.required'] = __('Payment email is required', 'fluent-affiliate');
            $messages['payment_email.email'] = __('Please enter a valid email address', 'fluent-affiliate');
        }

        $this->validate($data, $rules, $messages);

        if ($payoutMethod !== 'bank_transfer') {
            $affiliate->payment_email = sanitize_email($data['payment_email']);
        }

        $settings = $affiliate->settings;
        $settings['disable_new_ref_email'] = Arr::get($data, 'ref_email_notification') === 'yes' ? 'no' : 'yes';
        if ($payoutMethod === 'bank_transfer') {
            $settings['bank_details'] = sanitize_textarea_field($data['bank_details']);
        }
        $affiliate->settings = $settings;
        $affiliate->save();

        return [
            'message' => __('Settings has been updated successfully', 'fluent-affiliate'),
        ];
    }

}
