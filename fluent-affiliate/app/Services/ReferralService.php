<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\Framework\Support\Arr;

/**
 * Manual referral changes. ReferralController and the MCP tools call these, so
 * both paths store the same values and fire the same hooks.
 *
 * A refusal is a WP_Error with the translated message the admin screen shows and
 * a code that names the case.
 */
class ReferralService
{
    /**
     * Create a manual referral for an affiliate.
     *
     * @param Affiliate $affiliate
     * @param array     $data description (sanitized), amount, status (unpaid|rejected|pending),
     *                        type (sale|opt_in), provider, provider_id
     * @return Referral|\WP_Error
     */
    public static function create(Affiliate $affiliate, $data)
    {
        if ($affiliate->status != 'active') {
            return new \WP_Error('affiliate_inactive', __('You cannot create a referral for an inactive affiliate.', 'fluent-affiliate'));
        }

        $newReferral = Referral::create([
            'affiliate_id' => $affiliate->id,
            'description'  => Arr::get($data, 'description'),
            'amount'       => round((float)Arr::get($data, 'amount', 0), 2),
            'status'       => sanitize_text_field(Arr::get($data, 'status')),
            'type'         => sanitize_text_field(Arr::get($data, 'type')),
            'provider'     => Arr::get($data, 'provider', 'manual'),
            'provider_id'  => Arr::get($data, 'provider_id'),
        ]);

        $affiliate->recountEarnings();

        if ($newReferral->status === 'unpaid') {
            do_action('fluent_affiliate/referral_marked_unpaid', $newReferral);
        } else {
            do_action('fluent_affiliate/referral_created', $newReferral);
        }

        // listeners can load the full affiliate, which a referral manager may not see
        $newReferral->unsetRelation('affiliate');

        return $newReferral;
    }

    /**
     * Why a referral cannot be edited now, or null when it can.
     *
     * @param Referral $referral
     * @return \WP_Error|null
     */
    public static function editError(Referral $referral)
    {
        if ($referral->status == 'paid') {
            return new \WP_Error('referral_paid', __('You cannot update a paid referral.', 'fluent-affiliate'));
        }

        if ($referral->isInPayout()) {
            return new \WP_Error('referral_in_payout', __('This referral is in a payout that is being paid. You can change it after the payout finishes.', 'fluent-affiliate'));
        }

        return null;
    }

    /**
     * The types an edit may set. A provider written type such as payment or
     * recurring_sale identifies who owns the referral, so it can only be echoed
     * back, never introduced or changed. Manual referrals stay switchable
     * between the two manual types.
     *
     * @param Referral $referral
     * @return string[]
     */
    public static function allowedTypes(Referral $referral)
    {
        return in_array($referral->type, ['sale', 'opt_in'])
            ? ['sale', 'opt_in']
            : [$referral->type];
    }

    /**
     * Save an edit that passed editError() and validation.
     *
     * @param Referral $referral
     * @param array    $data description (sanitized), amount, status, type. Without an amount
     *                       key the stored amount stays as it is: integrations store
     *                       commissions unrounded, and a status change must not round them.
     * @return Referral
     */
    public static function update(Referral $referral, $data)
    {
        $values = [
            'description' => Arr::get($data, 'description'),
            'status'      => sanitize_text_field(Arr::get($data, 'status')),
            'type'        => sanitize_text_field(Arr::get($data, 'type')),
        ];

        if (array_key_exists('amount', $data)) {
            $values['amount'] = round((float)Arr::get($data, 'amount', 0), 2);
        }

        $referral->fill($values);

        $referral->save();

        $affiliate = $referral->affiliate;
        if ($affiliate) {
            $affiliate->recountEarnings();
        }

        $referral->unsetRelation('affiliate');

        return $referral;
    }
}
