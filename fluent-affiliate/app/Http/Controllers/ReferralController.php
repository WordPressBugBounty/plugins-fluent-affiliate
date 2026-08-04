<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $referrals = Referral::query()->with(['affiliate'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'created_at'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate($request->getSafe('per_page', 'intval', 10));

        foreach ($referrals as $referral) {
            $referral->provider_url = $referral->getProviderReferenceUrl();
        }

        return [
            'referrals' => $referrals
        ];
    }


    public function show($id)
    {
        $referral = Referral::query()->with(['visit', 'payout', 'customer'])->findOrFail($id);

        // Add provider URL
        $referral->provider_url = apply_filters(
            'fluent_affiliate/provider_reference_' . $referral->provider . '_url',
            '',
            $referral
        );

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

        if ($affiliate->status != 'active') {
            return $this->sendError([
                'message' => __('You cannot create a referral for an inactive affiliate.', 'fluent-affiliate')
            ]);
        }

        $newReferral = Referral::create([
            'affiliate_id' => $affiliate->id,
            'description'  => $request->getSafe('description', Sanitizer::SANITIZE_TEXT_FIELD),
            'amount'       => round((float)Arr::get($data, 'amount', 0), 2),
            'status'       => sanitize_text_field(Arr::get($data, 'status')),
            'type'         => sanitize_text_field(Arr::get($data, 'type')),
            'provider'     => $request->getSafe('provider', Sanitizer::SANITIZE_TEXT_FIELD, 'manual'),
            'provider_id'  => $request->getSafe('provider_id', 'intval', null),
        ]);

        $affiliate->recountEarnings();

        if ($newReferral->status === 'unpaid') {
            do_action('fluent_affiliate/referral_marked_unpaid', $newReferral);
        } else {
            do_action('fluent_affiliate/referral_created', $newReferral);
        }

        return [
            'referral' => $newReferral,
            'message'  => __('Manual Referral has been created', 'fluent-affiliate'),
        ];
    }

    public function update(Request $request, $id)
    {
        $referral = Referral::query()->findOrFail($id);

        if ($referral->status == 'paid') {
            return $this->sendError([
                'message' => __('You cannot update a paid referral.', 'fluent-affiliate')
            ]);
        }

        $data = $request->all();

        // a provider written type such as payment or recurring_sale identifies who
        // owns the referral, so it can only be echoed back, never introduced or
        // changed. Manual referrals stay switchable between the two manual types.
        $allowedTypes = in_array($referral->type, ['sale', 'opt_in'])
            ? ['sale', 'opt_in']
            : [$referral->type];

        $this->validate($data, [
            'description' => 'nullable|string',
            'amount'      => 'required|numeric|min:0',
            'status'      => 'required|string|in:unpaid,rejected,pending',
            'type'        => 'required|string|in:' . implode(',', $allowedTypes),
        ]);

        $referral->fill([
            'description' => $request->getSafe('description', Sanitizer::SANITIZE_TEXT_FIELD),
            'amount'      => round((float)Arr::get($data, 'amount', 0), 2),
            'status'      => sanitize_text_field(Arr::get($data, 'status')),
            'type'        => sanitize_text_field(Arr::get($data, 'type')),
        ]);

        $referral->save();

        $affiliate = $referral->affiliate;
        if ($affiliate) {
            $affiliate->recountEarnings();
        }

        return [
            'referral' => $referral,
            'message'  => __('Referral has been updated', 'fluent-affiliate'),
        ];
    }

    public function export(Request $request)
    {
        $refQuery = Referral::query()->with(['affiliate.user'])
        ->searchBy($request->getSafe('search', 'sanitize_text_field'))
        ->byStatus($request->getSafe('status', 'sanitize_text_field'))
        ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'created_at'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'));

        $limit = apply_filters('fluent_affiliate/data_export_limit', 5000);

        $total   = $refQuery->count();
        $limited = $total > $limit;

        $referrals = $refQuery->take($limit)->get();

        $referrals = $referrals->map(function ($referral) {
            $user = $referral->affiliate ? $referral->affiliate->user : null;
            return [
                'id'              => (int) $referral->id,
                'affiliate_name'  => Sanitizer::forCsv($user ? $user->full_name : ''),
                'affiliate_email' => Sanitizer::forCsv($user ? $user->user_email : ''),
                'amount'          => $referral->amount,
                'order_total'     => $referral->order_total,
                'currency'        => $referral->currency,
                'description'     => Sanitizer::forCsv($referral->description ?? ''),
                'provider'        => Sanitizer::forCsv($referral->provider ?? ''),
                'provider_id'     => $referral->provider_id,
                'type'            => $referral->type,
                'status'          => $referral->status,
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
