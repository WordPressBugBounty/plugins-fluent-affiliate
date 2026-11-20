<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;

class AffiliateService
{
    public static function getAffiliatesOptions($search = '', $includedIds = [], $limit = 20, $valueKey = 'id', $labelKey = 'full_name')
    {
        $affiliates = Affiliate::query()
            ->searchBy($search)
            ->whereHas('user')
            ->limit($limit)
            ->get();

        if ($includedIds) {
            $pushedIds = $affiliates->pluck('id')->toArray();
            $leftOutIds = array_diff($includedIds, $pushedIds);
            if ($leftOutIds) {
                $leftOutAffiliates = Affiliate::query()
                    ->whereIn('id', $leftOutIds)
                    ->whereHas('user')
                    ->get();
                $affiliates = $affiliates->merge($leftOutAffiliates);
            }
        }

        $formattedAffiliates = [];
        foreach ($affiliates as $affiliate) {
            if (empty($affiliate->user)) {
                continue;
            }

            $formattedAffiliates[] = [
                $valueKey => $affiliate->id,
                $labelKey => $affiliate->user_details['full_name'] ?? $affiliate->user->display_name,
            ];

        }

        return $formattedAffiliates;
    }

    /**
     * Why an affiliate cannot move to $status, or null. An affiliate without a
     * WordPress user cannot be activated: it has no portal and no payout email.
     *
     * @param Affiliate $affiliate
     * @param string    $status
     * @return \WP_Error|null
     */
    public static function activationError(Affiliate $affiliate, $status)
    {
        if ($status !== 'active' || $affiliate->status === 'active' || $affiliate->user) {
            return null;
        }

        return new \WP_Error('no_user', __('This affiliate has no WordPress user, so it cannot be activated. Add the affiliate again with a user.', 'fluent-affiliate'));
    }

    /**
     * Move an affiliate to pending, active or inactive. Fires the same hooks as
     * the admin screen; the status hooks send the affiliate's emails.
     *
     * @param Affiliate $affiliate
     * @param string    $status
     * @return Affiliate|\WP_Error
     */
    public static function changeStatus(Affiliate $affiliate, $status)
    {
        if (!in_array($status, ['pending', 'active', 'inactive'])) {
            return new \WP_Error('invalid_status', __('Invalid status provided', 'fluent-affiliate'));
        }

        if ($affiliate->status === $status) {
            return new \WP_Error('same_status', __('Affiliate status is already set to this value', 'fluent-affiliate'));
        }

        if ($error = static::activationError($affiliate, $status)) {
            return $error;
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

        return $affiliate;
    }

    /**
     * Change the commission terms and the note of an affiliate. Only the keys
     * given change. Fires fluent_affiliate/affiliate_updated as the admin screen does.
     *
     * @param Affiliate $affiliate
     * @param array     $terms rate_type (default|group|flat|percentage), rate, group_id, note
     * @return Affiliate
     */
    public static function updateTerms(Affiliate $affiliate, $terms)
    {
        $data = [];

        if (array_key_exists('rate_type', $terms)) {
            $rateType = $terms['rate_type'];
            $data['rate_type'] = $rateType;
            // the same rule as the admin form: a rate only for flat and percentage, a group only for group
            $data['rate'] = in_array($rateType, ['flat', 'percentage']) ? ($terms['rate'] ?? null) : null;
            $data['group_id'] = $rateType == 'group' ? ($terms['group_id'] ?? null) : null;
        } elseif (array_key_exists('rate', $terms) && in_array($affiliate->rate_type, ['flat', 'percentage'])) {
            $data['rate'] = $terms['rate'];
        } elseif (array_key_exists('group_id', $terms) && $affiliate->rate_type == 'group') {
            $data['group_id'] = $terms['group_id'];
        }

        if (array_key_exists('note', $terms)) {
            $data['note'] = sanitize_textarea_field((string) $terms['note']);
        }

        if (!$data) {
            return $affiliate;
        }

        $affiliate->fill($data);
        $affiliate->save();

        do_action('fluent_affiliate/affiliate_updated', $affiliate, 'by_admin', $data);

        return $affiliate;
    }

    /**
     * Make an existing WordPress user an affiliate.
     *
     * @param User  $user
     * @param array $data rate_type, rate, group_id, status, payment_email, settings
     * @return Affiliate|\WP_Error
     */
    public static function createForUser(User $user, $data)
    {
        if (Affiliate::where('user_id', $user->ID)->exists()) {
            return new \WP_Error('already_affiliate', __('This user is already an affiliate', 'fluent-affiliate'));
        }

        return $user->syncAffiliateProfile($data);
    }
}
