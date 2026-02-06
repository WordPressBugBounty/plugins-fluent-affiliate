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
            ->paginate($request->get('per_page', 10));

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
            'amount'       => ((int)(Arr::get($data, 'amount', 0) * 100)) / 100,
            'status'       => sanitize_text_field(Arr::get($data, 'status')),
            'type'         => sanitize_text_field(Arr::get($data, 'type')),
            'provider'     => $request->getSafe('provider', Sanitizer::SANITIZE_TEXT_FIELD, 'manual'),
            'provider_id'  => $request->getSafe('provider_id', 'intval', null),
        ]);

        // Fire creating event
        // @todo: has to conrfirm with Jewel
        do_action('fluent_affiliate/referral_marked_unpaid', $newReferral);

        // Recount affiliate earnings
        $affiliate->recountEarnings();

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

        $this->validate($data, [
            'description' => 'nullable|string',
            'amount'      => 'required|numeric|min:0',
            'status'      => 'required|string|in:unpaid,rejected,pending',
            'type'        => 'required|string|in:sale,opt_in',
        ]);

        $referral->fill(array_filter([
            'description' => $request->getSafe('description', Sanitizer::SANITIZE_TEXT_FIELD),
            'amount'      => ((int)(Arr::get($data, 'amount', 0) * 100)) / 100,
            'status'      => sanitize_text_field(Arr::get($data, 'status')),
            'type'        => sanitize_text_field(Arr::get($data, 'type')),
        ]));

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

    public function destroy($id)
    {
        $referral = Referral::query()->findOrFail($id);

        $affiliate = $referral->affiliate;

        // Fire deleting event
        do_action('fluent_affiliate/referral/before_delete', $referral);
 
        $referral->delete();
        $affiliate->recountEarnings();

        do_action('fluent_affiliate/referral/deleted', $id, $affiliate);

        return $this->sendSuccess([
            'message' => __('Referral has been deleted', 'fluent-affiliate')
        ]);
    }
}
