<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Modules\Auth\AuthHelper;
use FluentAffiliate\App\Modules\FluentCRM\CrmContactService;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\App\Services\Reports\ReportService;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;
use FluentCrm\App\Services\PermissionManager as CrmPermissionManager;

class AffiliateController extends Controller
{
    /**
     * @param Request $request
     * @return array|\WP_REST_Response
     */
    public function index(Request $request)
    {
        // each filter is an array of value/operator, and getSafe would run the
        // sanitizer over those arrays, which turns every one of them into ''
        $filters = $request->get('filters', []);
        $filters = is_array($filters) ? map_deep($filters, 'sanitize_text_field') : [];

        $orderBy = $request->getSafe('order_by', 'sanitize_sql_orderby', 'id');
        $orderType = $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC');

        $affiliates = Affiliate::query()->with(['user', 'group'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($orderBy, $orderType)
            ->applyCustomFilters($filters)
            ->paginate($request->getSafe('per_page', 'intval', 20))
        ;

        foreach ($affiliates as $affiliate) {
            $affiliate->bank_details = $affiliate->bank_details;
            $affiliate->rate_details = $affiliate->getRateDetails();
        }

        return [
            'affiliates' => $affiliates
        ];
    }

    public function export(Request $request)
    {
        $limit = apply_filters('fluent_affiliate/data_export_limit', 5000);

        $affQuery = Affiliate::query()->with(['user'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'));

        $total   = $affQuery->count();
        $limited = $total > $limit;

        $affiliates = $affQuery->take($limit)->get();

        $affiliates = $affiliates->map(function ($affiliate) {
            $user = $affiliate->user;
            return [
                'id'             => (int) $affiliate->id,
                'full_name'      => Sanitizer::forCsv($user ? $user->full_name : ''),
                'email'          => Sanitizer::forCsv($user ? $user->user_email : ''),
                'payment_email'  => Sanitizer::forCsv($affiliate->payment_email ?? ''),
                'rate_type'      => $affiliate->rate_type,
                'rate'           => $affiliate->rate,
                'status'         => $affiliate->status,
                'total_earnings' => $affiliate->total_earnings,
                'unpaid_earnings'=> $affiliate->unpaid_earnings,
                'referrals'      => (int) $affiliate->referrals,
                'visits'         => (int) $affiliate->visits,
                'created_at'     => (string) $affiliate->created_at,
            ];
        });

        return [
            'affiliates' => $affiliates,
            'limited'    => $limited,
            'total'      => $total,
        ];
    }

    /**
     * @param Request $request
     * @param $userId
     * @return array|\WP_REST_Response
     */
    public function getAffiliate(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()
            ->with(['user', 'group'])
            ->findOrFail($affiliateId)
        ;

        $affiliate = $affiliate->recountEarnings();
        $affiliate->bank_details = $affiliate->bank_details;
        $affiliate->rate_details = $affiliate->getRateDetails();

        $widgets = apply_filters('fluent_affiliate/affiliate_widgets', [], $affiliate);

        $affiliate->widgets = array_values($widgets);

        if (CrmContactService::isActive() && CrmPermissionManager::currentUserCan('fcrm_read_contacts')) {
            $affiliate->crm_profile = CrmContactService::getProfileData($affiliate);
        }

        $affiliate->attached_coupons = $affiliate->getAttachedCoupons('edit');

        $affiliate->share_url = $affiliate->getShareUrl();

        $affiliate = apply_filters('fluent_affiliate/affiliate_view_data', $affiliate);

        return [
            'affiliate' => $affiliate
        ];
    }

    /**
     * Current CRM tag/list selection plus titles for the CRM Profile card.
     */
    public function getCrmContact(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::findOrFail($affiliateId);

        if (!CrmContactService::isActive()) {
            return $this->sendError(['message' => __('FluentCRM is not active.', 'fluent-affiliate')]);
        }
        if (!CrmPermissionManager::currentUserCan('fcrm_read_contacts')) {
            return $this->sendError(['message' => __('You do not have permission to read CRM contacts.', 'fluent-affiliate')]);
        }

        $state = CrmContactService::getContactState($affiliate);
        if (!$state) {
            return $this->sendError(['message' => __('No CRM contact found for this affiliate.', 'fluent-affiliate')]);
        }

        return $this->sendSuccess($state);
    }

    /**
     * Bounded, searchable tag/list options for the CRM Profile picker.
     */
    public function getCrmOptions(Request $request, $affiliateId)
    {
        Affiliate::findOrFail($affiliateId);

        if (!CrmContactService::isActive()) {
            return $this->sendError(['message' => __('FluentCRM is not active.', 'fluent-affiliate')]);
        }
        if (!CrmPermissionManager::currentUserCan('fcrm_read_contacts')) {
            return $this->sendError(['message' => __('You do not have permission to read CRM contacts.', 'fluent-affiliate')]);
        }

        $type   = $request->get('type') === 'lists' ? 'lists' : 'tags';
        $search = $request->getSafe('search', 'sanitize_text_field');

        return $this->sendSuccess(['options' => CrmContactService::getOptions($type, $search)]);
    }

    public function updateCrmTags(Request $request, $affiliateId)
    {
        return $this->updateCrmTaxonomy($request, $affiliateId, 'tags');
    }

    public function updateCrmLists(Request $request, $affiliateId)
    {
        return $this->updateCrmTaxonomy($request, $affiliateId, 'lists');
    }

    private function updateCrmTaxonomy(Request $request, $affiliateId, $type)
    {
        $affiliate = Affiliate::findOrFail($affiliateId);

        if (!CrmContactService::isActive()) {
            return $this->sendError(['message' => __('FluentCRM is not active.', 'fluent-affiliate')]);
        }
        if (!CrmPermissionManager::currentUserCan('fcrm_manage_contacts')) {
            return $this->sendError(['message' => __('You do not have permission to manage CRM contacts.', 'fluent-affiliate')]);
        }

        $key     = $type === 'tags' ? 'tag_ids' : 'list_ids';
        // Bound the payload: unique integer ids, capped, before it reaches the WHERE IN query.
        $desired = array_slice(array_values(array_unique(array_map('intval', (array) $request->get($key, [])))), 0, 200);

        $result = CrmContactService::syncTaxonomy($affiliate, $type, $desired);
        if ($result === null) {
            return $this->sendError(['message' => __('No CRM contact found for this affiliate.', 'fluent-affiliate')]);
        }

        return $this->sendSuccess(array_merge($result, ['message' => __('Updated.', 'fluent-affiliate')]));
    }

    /**
     * @param Request $request
     * @return array|\WP_REST_Response
     */
    public function createAffiliate(Request $request)
    {
        $data = $request->all();
        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        $rules = [
            'user_id'       => 'required|exists:users,ID',
            'status'        => 'required|string|in:pending,active,inactive',
            'rate_type'     => 'required|string|in:default,group,flat,percentage',
            'rate'          => 'required_if:rate_type,flat,percentage|numeric|min:0',
            'group_id'      => 'required_if:rate_type,group',
        ];

        if ($payoutMethod === 'bank_transfer') {
            $rules['bank_details'] = 'required|string|max:5000';
        } else {
            $rules['payment_email'] = 'required|email';
        }

        if (!in_array($data['rate_type'], ['flat', 'percentage'])) {
            unset($rules['rate']);
        }

        $this->validate($data, $rules);

        $user = User::findOrFail($data['user_id']);

        $filteredData = Arr::only($data, ['rate_type', 'rate', 'group_id', 'status', 'payment_email', 'bank_details']);

        // Handle bank_details in settings
        if ($payoutMethod === 'bank_transfer' && !empty($data['bank_details'])) {
            $filteredData['settings'] = ['bank_details' => sanitize_textarea_field($data['bank_details'])];
            unset($filteredData['bank_details']);
        }

        $filteredData = array_filter($filteredData, function ($value) {
            return !is_null($value);
        });

        $createdAffiliate = $user->syncAffiliateProfile($filteredData);

        return [
            'message'   => __('Affiliate has been successfully created', 'fluent-affiliate'),
            'affiliate' => $createdAffiliate
        ];
    }


    /**
     * @param Request $request
     * @return array|\WP_REST_Response
     */
    public function updateAffiliate(Request $request, $id)
    {
        $affiliate = Affiliate::query()->findOrFail($id);

        $data = $request->all();

        $rules = [
            'status'        => 'required|string|in:pending,active,inactive',
            'rate_type'     => 'required|string|in:default,group,flat,percentage',
            'rate'          => 'required_if:rate_type,flat,percentage|numeric|min:0',
            'group_id'      => 'required_if:rate_type,group',
            'payment_email' => 'required|email',
        ];

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        if ($payoutMethod === 'bank_transfer') {
            $rules['bank_details'] = 'required|string|max:5000';
            unset($rules['payment_email']);
        } else {
            $registrationSettings = AuthHelper::getRegistrationFormFields(null);
            $paymentEmailField = Arr::first($registrationSettings, function ($field) {
                return $field['name'] === 'payment_email';
            });

            if ($paymentEmailField && $paymentEmailField['enabled'] !== 'yes') {
                unset($rules['payment_email']);
            }

            if ($paymentEmailField && $paymentEmailField['required'] !== 'yes') {
                $rules['payment_email'] = 'nullable|email';
            }
        }

        if (!in_array($data['rate_type'], ['flat', 'percentage'])) {
            unset($rules['rate']);
        }

        $this->validate($data, $rules);

        $rateType = $data['rate_type'];
        if ($rateType == 'group') {
            $group = AffiliateGroup::query()->findOrFail($data['group_id']);
            if (!$group) {
                return $this->sendError([
                    'message' => __('The selected group could not be found ', 'fluent-affiliate'),
                ]);
            }
            unset($data['rate']);
        } else {
            unset($data['group_id']);
        }

        $prevStatus = $affiliate->status;

        // built explicitly rather than filtered: a 0 rate, a cleared note and the
        // group_id dropped when leaving a group rate are all legitimate values
        $affiliateData = [
            'group_id'      => $rateType == 'group' ? ($data['group_id'] ?? null) : null,
            'rate'          => in_array($rateType, ['flat', 'percentage']) ? ($data['rate'] ?? null) : null,
            'rate_type'     => $rateType,
            'status'        => $data['status'],
            'note'          => sanitize_textarea_field($data['note'] ?? ''), // Note is optional
        ];

        // Handle payment method specific fields
        if ($payoutMethod === 'bank_transfer') {
            $settings = $affiliate->settings;
            $settings['bank_details'] = sanitize_textarea_field($data['bank_details']);
            $affiliate->settings = $settings;
        } else {
            $affiliateData['payment_email'] = sanitize_email($data['payment_email']);
        }

        $affiliate->fill($affiliateData);
        $affiliate->save();

        do_action('fluent_affiliate/affiliate_updated', $affiliate, 'by_admin', $data);

        if ($prevStatus !== $affiliate->status) {
            do_action('fluent_affiliate/affiliate_status_to_' . $affiliate->status, $affiliate, $prevStatus);
        }


        return [
            'message'   => __('Affiliate has been successfully updated', 'fluent-affiliate'),
            'affiliate' => $affiliate
        ];
    }

    public function updateAffiliateStatus(Request $request, $id)
    {
        $affiliate = Affiliate::query()->findOrFail($id);

        $status = $request->getSafe('status', 'sanitize_text_field');

        if (!in_array($status, ['pending', 'active', 'inactive'])) {
            return $this->sendError([
                'message' => __('Invalid status provided', 'fluent-affiliate'),
            ]);
        }

        if ($affiliate->status === $status) {
            return $this->sendError([
                'message' => __('Affiliate status is already set to this value', 'fluent-affiliate'),
            ]);
        }


        $prevStatus = $affiliate->status;

        $affiliate->status = $status;
        $affiliate->save();
        do_action('fluent_affiliate/affiliate_updated', $affiliate, 'by_admin', [
            'status' => $status
        ]);

        if ($prevStatus !== $affiliate->status) {
            do_action('fluent_affiliate/affiliate_status_to_' . $affiliate->status, $affiliate, $prevStatus);
        }

        return [
            'message'   => __('Affiliate status has been successfully updated', 'fluent-affiliate'),
            'affiliate' => $affiliate
        ];
    }

    /**
     * @param Request $request
     * @param $affiliateId int Affiliate ID
     * @return mixed
     */
    public function deleteAffiliate(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::findOrFail($affiliateId);

        do_action('fluent_affiliate/before_delete_affiliate', $affiliate);
        // delete the visits
        Visit::query()->where('affiliate_id', $affiliate->id)->delete();
        // delete the referrals
        Referral::query()->where('affiliate_id', $affiliate->id)->delete();
        // Transactions are not deleted here, they are kept for record keeping.

        // delete the affiliate
        $affiliate->delete();
        do_action('fluent_affiliate/after_delete_affiliate', $affiliateId);

        return [
            'message' => __('Selected Affiliate successfully deleted', 'fluent-affiliate'),
        ];
    }

    public function getVisits(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);
        $visits = Visit::query()->where('affiliate_id', $affiliate->id)
            ->with(
                [
                    'referrals' => function ($query) {
                        return $query->select(['id', 'visit_id', 'status', 'amount', 'currency']);
                    }
                ])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byConvertedStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate($request->getSafe('per_page', 'intval', 10));

        return [
            'visits' => $visits
        ];
    }

    public function getReferrals(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);
        $referrals = Referral::query()->where('affiliate_id', $affiliate->id)
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate($request->getSafe('per_page', 'intval', 10));

        foreach ($referrals as $referral) {
            $referral->provider_url = $referral->getProviderUrl();
        }

        return [
            'referrals' => $referrals
        ];
    }

    public function getCustomers(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);

        $orderBy = $request->getSafe('order_by', 'sanitize_text_field', 'id');
        $orderBy = in_array($orderBy, ['id', 'created_at', 'email'], true) ? $orderBy : 'id';

        $orderType = strtoupper($request->getSafe('order_type', 'sanitize_text_field', 'DESC'));
        $orderType = $orderType === 'ASC' ? 'ASC' : 'DESC';

        $query = Customer::query()->where('by_affiliate_id', $affiliate->id)
            ->withCount('referrals')
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->orderBy($orderBy, $orderType);

        // Pro lifetime narrows this by lifetime status via the ?filter arg (active/expired).
        $query = apply_filters('fluent_affiliate/affiliate_customers_query', $query, $request, $affiliate->id);

        $customers = $query->paginate($request->getSafe('per_page', 'intval', 10));

        $response = [
            'customers' => $customers
        ];

        // Pro lifetime appends group-aware `lifetime_expiry_days` for the expiry display.
        return apply_filters('fluent_affiliate/affiliate_customers_response', $response, $affiliate, $request);
    }

    public function getTransactions(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);

        $transactions = Transaction::query()->where('affiliate_id', $affiliate->id)
            ->with(['payout'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate($request->getSafe('per_page', 'intval', 10));

        foreach ($transactions as $transaction) {
            $transaction->currency_symbol = Utility::getCurrencySymbol($transaction->currency);
            $transaction->referrals_count = $transaction->referrals()->count();
        }

        return [
            'transactions' => $transactions
        ];
    }

    public function statistics(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);

        $defaultStartDate = gmdate('Y-m-d', strtotime('-30 days'));
        $defaultEndDate = gmdate('Y-m-d');

        $startDate = $request->getSafe('start_date', 'sanitize_text_field', $defaultStartDate);
        $endDate = $request->getSafe('end_date', 'sanitize_text_field', $defaultEndDate);

        $referralQuery = Referral::query()->where('affiliate_id', $affiliate->id);
        $visitQuery = Visit::query()->where('affiliate_id', $affiliate->id);

        $seriesConfigs = [
            [
                'model' => $referralQuery,
                'label' => __('Referrals', 'fluent-affiliate')
            ],
            [
                'model' => $visitQuery,
                'label' => __('Visits', 'fluent-affiliate')
            ],
        ];

        return ReportService::create()->getMultiChartStatistics($seriesConfigs, $startDate, $endDate);
    }

    public function getOverviewStats(Request $request, $affiliateId)
    {
        $affiliate = Affiliate::query()->findOrFail($affiliateId);

        $hasAccess = PermissionManager::hasAccess(true);

        $affiliateVisits = $hasAccess['visit'] ? $affiliate->visits : 0;

        $conversationRate = $affiliateVisits > 0 ? round(($affiliate->referrals / $affiliateVisits) * 100, 2) : 0;

        $stats = [
            'total_paid'        => $hasAccess['payout'] ? [
                'title'       => __('Total Paid', 'fluent-affiliate'),
                'amount'      => $affiliate->total_earnings - $affiliate->unpaid_earnings,
                'is_currency' => true,
                'icon'        => 'MoneyPaid'
            ] : [],
            'total_unpaid'      => $hasAccess['payout'] ? [
                'title'       => __('Total Unpaid', 'fluent-affiliate'),
                'amount'      => $affiliate->unpaid_earnings,
                'is_currency' => true,
                'icon'        => 'MoneyUnPaid'
            ] : [],
            'total_order_value' => $hasAccess['referral'] ? [
                'title'       => __('Total Order Value', 'fluent-affiliate'),
                'amount'      => Referral::query()->whereIn('status', ['paid', 'unpaid'])
                    ->where('affiliate_id', $affiliate->id)
                    ->sum('order_total'),
                'is_currency' => true,
                'icon'        => 'BagLoveIcon'
            ] : [],
            'conversion_rate'   => $hasAccess['referral'] && $hasAccess['visit'] ? [
                'title'       => __('Conversion Rate', 'fluent-affiliate'),
                'amount'      => number_format($conversationRate, 2) . '%',
                'is_currency' => false,
                'is_number'   => false,
                'icon'        => 'PercentIcon'
            ] : [],
            'total_visits'      => $hasAccess['visit'] ? [
                'title'       => __('Total Visits', 'fluent-affiliate'),
                'amount'      => $affiliateVisits,
                'is_currency' => false,
                'is_number'   => true,
                'icon'        => 'LinkClick'
            ] : [],
            'total_referrals'   => $hasAccess['referral'] ? [
                'title'       => __('Total Referrals', 'fluent-affiliate'),
                'amount'      => $affiliate->referrals,
                'is_currency' => false,
                'is_number'   => true,
                'icon'        => 'LinkReferal'
            ] : []
        ];

        return [
            'stats' => array_filter($stats),
        ];
    }
}
