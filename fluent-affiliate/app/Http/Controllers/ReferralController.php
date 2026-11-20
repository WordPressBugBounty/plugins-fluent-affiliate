<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\App\Services\ReferralService;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $referrals = Referral::query()->with(['affiliate' => Affiliate::identityOnly()])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'created_at'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate(Helper::clampPerPage($request->getSafe('per_page', 'intval', 10)));

        foreach ($referrals as $referral) {
            $referral->provider_url = $referral->getProviderReferenceUrl();
            $referral->hold_until = $referral->getHoldUntil();
        }

        return [
            'referrals' => $referrals
        ];
    }


    public function show($id)
    {
        // the visit and payout rows belong to their own permissions
        $relations = ['customer'];

        if (PermissionManager::hasVisitAccess()) {
            $relations[] = 'visit';
        }

        if (PermissionManager::hasPayoutAccess(true)) {
            $relations[] = 'payout';
        }

        $referral = Referral::query()->with($relations)->findOrFail($id);

        // Add provider URL
        $referral->provider_url = apply_filters(
            'fluent_affiliate/provider_reference_' . $referral->provider . '_url',
            '',
            $referral
        );

        $referral->hold_until = $referral->getHoldUntil();

        return [
            'referral' => $referral
        ];
    }

    public function createReferral(Request $request)
    {
        $data = $request->all();

        $this->validate($data, [
            'affiliate_id' => 'required|numeric|exists:fa_affiliates,id',
            'description'  => 'nullable|string',
            'amount'       => 'required|numeric|min:0',
            'status'       => 'required|string|in:unpaid,rejected,pending',
            'type'         => 'required|string|in:sale,opt_in',
        ]);

        $affiliate = Affiliate::query()->findOrFail($data['affiliate_id']);

        $newReferral = ReferralService::create($affiliate, [
            'description' => $request->getSafe('description', Sanitizer::SANITIZE_TEXT_FIELD),
            'amount'      => Arr::get($data, 'amount', 0),
            'status'      => Arr::get($data, 'status'),
            'type'        => Arr::get($data, 'type'),
            'provider'    => $request->getSafe('provider', Sanitizer::SANITIZE_TEXT_FIELD, 'manual'),
            'provider_id' => $request->getSafe('provider_id', 'intval', null),
        ]);

        if (is_wp_error($newReferral)) {
            return $this->sendError([
                'message' => $newReferral->get_error_message()
            ]);
        }

        return [
            'referral' => $newReferral,
            'message'  => __('Manual Referral has been created', 'fluent-affiliate'),
        ];
    }

    public function update(Request $request, $id)
    {
        $referral = Referral::query()->findOrFail($id);

        if ($error = ReferralService::editError($referral)) {
            return $this->sendError([
                'message' => $error->get_error_message()
            ]);
        }

        $data = $request->all();

        $this->validate($data, [
            'description' => 'nullable|string',
            'amount'      => 'required|numeric|min:0',
            'status'      => 'required|string|in:unpaid,rejected,pending',
            'type'        => 'required|string|in:' . implode(',', ReferralService::allowedTypes($referral)),
        ]);

        $referral = ReferralService::update($referral, [
            'description' => $request->getSafe('description', Sanitizer::SANITIZE_TEXT_FIELD),
            'amount'      => Arr::get($data, 'amount', 0),
            'status'      => Arr::get($data, 'status'),
            'type'        => Arr::get($data, 'type'),
        ]);

        return [
            'referral' => $referral,
            'message'  => __('Referral has been updated', 'fluent-affiliate'),
        ];
    }

    public function export(Request $request)
    {
        // the affiliate email belongs to affiliate access, not referral access
        $withEmail = PermissionManager::hasAffiliateAccess(true);

        $refQuery = Referral::query()->with($withEmail ? ['affiliate.user'] : ['affiliate' => Affiliate::identityOnly()])
        ->searchBy($request->getSafe('search', 'sanitize_text_field'))
        ->byStatus($request->getSafe('status', 'sanitize_text_field'))
        ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'created_at'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'));

        $limit = apply_filters('fluent_affiliate/data_export_limit', 5000);

        $total   = $refQuery->count();
        $limited = $total > $limit;

        $referrals = $refQuery->take($limit)->get();

        $referrals = $referrals->map(function ($referral) use ($withEmail) {
            $user = $referral->affiliate ? $referral->affiliate->user : null;
            return [
                'id'              => (int) $referral->id,
                'affiliate_name'  => Sanitizer::forCsv($user ? $user->full_name : ''),
                'affiliate_email' => Sanitizer::forCsv($user && $withEmail ? $user->user_email : ''),
                'amount'          => Sanitizer::amountForCsv($referral->amount),
                'order_total'     => Sanitizer::amountForCsv($referral->order_total),
                'currency'        => Sanitizer::forCsv($referral->currency),
                'description'     => Sanitizer::forCsv($referral->description ?? ''),
                'provider'        => Sanitizer::forCsv($referral->provider ?? ''),
                'provider_id'     => $referral->provider_id,
                'type'            => Sanitizer::forCsv($referral->type),
                'status'          => Sanitizer::forCsv($referral->status),
                'created_at'      => (string) $referral->created_at,
            ];
        });

        return [
            'referrals' => $referrals,
            'limited'   => $limited,
            'total'     => $total,
        ];
    }

    public function destroy($id)
    {
        $referral = Referral::query()->findOrFail($id);

        if ($referral->isInPayout()) {
            return $this->sendError([
                'message' => __('This referral is in a payout that is being paid. You can delete it after the payout finishes.', 'fluent-affiliate')
            ]);
        }

        // the payout keeps its total, so deleting a referral it paid would leave
        // money recorded for a commission that no longer exists
        if ($referral->status === 'paid' || $referral->payout_transaction_id) {
            return $this->sendError([
                'message' => __('This referral is paid or in a payout, so it cannot be deleted.', 'fluent-affiliate')
            ]);
        }

        $affiliate = $referral->affiliate;

        // Fire deleting event
        do_action('fluent_affiliate/referral/before_delete', $referral);
 
        $referral->delete();

        do_action('fluent_affiliate/referral/deleted', $id, $affiliate);

        return $this->sendSuccess([
            'message' => __('Referral has been deleted', 'fluent-affiliate')
        ]);
    }
}
