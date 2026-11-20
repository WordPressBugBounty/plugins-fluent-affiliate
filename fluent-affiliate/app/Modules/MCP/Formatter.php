<?php

namespace FluentAffiliate\App\Modules\MCP;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Support\Arr;

/**
 * One shape per record, for every tool. Money is a decimal beside its currency,
 * dates are ISO 8601 in site time, and each record appears once.
 */
class Formatter
{
    /**
     * @param Affiliate|null $affiliate With its user loaded, or the name is empty.
     * @return array{id: int, name: string}|null
     */
    public static function affiliateRef($affiliate)
    {
        if (!$affiliate) {
            return null;
        }

        return [
            'id'   => (int) $affiliate->id,
            'name' => $affiliate->user ? (string) $affiliate->user->full_name : '',
        ];
    }

    /**
     * @param Affiliate $affiliate With user and group loaded.
     * @return array
     */
    public static function affiliate(Affiliate $affiliate)
    {
        $user = $affiliate->user;
        $currency = McpHelper::currency();

        $row = [
            'id'              => (int) $affiliate->id,
            'user_id'         => $affiliate->user_id ? (int) $affiliate->user_id : null,
            'name'            => $user ? (string) $user->full_name : '',
            'email'           => $user ? (string) $user->user_email : null,
            'status'          => (string) $affiliate->status,
            'commission'      => self::rate($affiliate),
            'total_earnings'  => McpHelper::money($affiliate->total_earnings),
            'unpaid_earnings' => McpHelper::money($affiliate->unpaid_earnings),
            'paid_earnings'   => McpHelper::money((float) $affiliate->total_earnings - (float) $affiliate->unpaid_earnings),
            'currency'        => $currency,
            'referrals'       => (int) $affiliate->referrals,
            'visits'          => (int) $affiliate->visits,
            'created_at'      => McpHelper::iso($affiliate->created_at),
        ];

        if (!$user) {
            $row['warning'] = 'This affiliate has no WordPress user.';
        }

        // where the money goes is for the people who pay it
        if (PermissionManager::hasPayoutAccess(true)) {
            $row['payment_email'] = $affiliate->payment_email ? (string) $affiliate->payment_email : null;
        }

        return $row;
    }

    /**
     * @param Affiliate $affiliate
     * @return array type (percentage|flat), rate, source (custom|group|default), group
     */
    public static function rate(Affiliate $affiliate)
    {
        $details = (array) $affiliate->getRateDetails();
        $type = in_array(Arr::get($details, 'type'), ['flat', 'fixed']) ? 'flat' : 'percentage';

        $source = 'default';
        if (Arr::get($details, 'is_custom') === 'yes') {
            $source = 'custom';
        } elseif (!empty($details['group'])) {
            $source = 'group';
        }

        $rate = [
            'type'      => $type,
            'rate'      => (float) Arr::get($details, 'rate', 0),
            'source'    => $source,
            // what update-affiliate takes; type and rate above are what applies
            'rate_type' => (string) ($affiliate->rate_type ?: 'default'),
        ];

        if ($type === 'flat') {
            $rate['currency'] = McpHelper::currency();
        }

        if ($source === 'group') {
            $rate['group'] = ['id' => (int) $details['group']->id, 'name' => (string) $details['group']->meta_key];
        } elseif ($affiliate->rate_type === 'group') {
            $rate['note'] = 'The affiliate is set to a group rate, but the group is missing or inactive, so the site default applies.';
        }

        return $rate;
    }

    /**
     * @param Referral $referral With affiliate (identity) loaded.
     * @return array
     */
    public static function referral(Referral $referral)
    {
        $row = [
            'id'           => (int) $referral->id,
            'affiliate'    => self::affiliateRef($referral->affiliate),
            'amount'       => McpHelper::money($referral->amount),
            'order_total'  => $referral->order_total === null ? null : McpHelper::money($referral->order_total),
            'currency'     => McpHelper::currency($referral->currency),
            'status'       => (string) $referral->status,
            'type'         => (string) $referral->type,
            'provider'     => $referral->provider ? (string) $referral->provider : null,
            'order_id'     => $referral->provider_id ? (int) $referral->provider_id : null,
            'description'  => $referral->description !== null ? (string) $referral->description : null,
            'payout_id'    => $referral->payout_id ? (int) $referral->payout_id : null,
            'created_at'   => McpHelper::iso($referral->created_at),
        ];

        $holdUntil = $referral->getHoldUntil();
        if ($holdUntil) {
            $row['payable_from'] = McpHelper::iso($holdUntil);
        }

        return $row;
    }

    /**
     * @param Visit $visit
     * @return array
     */
    public static function visit(Visit $visit)
    {
        return [
            'id'           => (int) $visit->id,
            'affiliate_id' => $visit->affiliate_id ? (int) $visit->affiliate_id : null,
            'url'          => (string) $visit->url,
            'referrer'     => $visit->referrer ? (string) $visit->referrer : null,
            'utm_campaign' => $visit->utm_campaign ?: null,
            'utm_source'   => $visit->utm_source ?: null,
            'utm_medium'   => $visit->utm_medium ?: null,
            'referral_ids' => $visit->relationLoaded('referrals') ? array_map('intval', $visit->referrals->pluck('id')->toArray()) : ($visit->referral_id ? [(int) $visit->referral_id] : []),
            'created_at'   => McpHelper::iso($visit->created_at),
        ];
    }

    /**
     * @param Payout $payout
     * @param array  $counts transactions, open (processing or failed) — optional
     * @return array
     */
    public static function payout(Payout $payout, $counts = [])
    {
        $settings = (array) $payout->settings;

        $row = [
            'id'            => (int) $payout->id,
            'title'         => (string) $payout->title,
            'description'   => (string) $payout->description,
            'payout_method' => (string) ($payout->payout_method ?: 'manual'),
            'status'        => (string) $payout->status,
            'total_amount'  => McpHelper::money($payout->total_amount),
            'currency'      => McpHelper::currency($payout->currency ?: Arr::get($counts, 'currency')),
            // a payout without a start date stores the epoch: every earlier referral
            'referrals_from' => strpos((string) Arr::get($settings, 'start_date'), '1970-01-01') === 0 ? null : McpHelper::iso(Arr::get($settings, 'start_date')),
            'referrals_to'  => McpHelper::iso(Arr::get($settings, 'end_date')),
            'created_at'    => McpHelper::iso($payout->created_at),
        ];

        foreach (['transactions', 'transactions_to_settle', 'transactions_failed'] as $key) {
            if (array_key_exists($key, $counts)) {
                $row[$key] = (int) $counts[$key];
            }
        }

        return $row;
    }

    /**
     * @param Transaction $transaction With affiliate (and its user) loaded.
     * @return array
     */
    public static function transaction(Transaction $transaction)
    {
        $affiliate = $transaction->affiliate;

        $row = [
            'id'            => (int) $transaction->id,
            'affiliate'     => self::affiliateRef($affiliate),
            'amount'        => McpHelper::money($transaction->total_amount),
            'currency'      => McpHelper::currency($transaction->currency),
            'status'        => (string) $transaction->status,
            'payout_method' => (string) ($transaction->payout_method ?: 'manual'),
            'paid_by'       => $transaction->isManual() ? 'hand' : 'gateway',
            'updated_at'    => McpHelper::iso($transaction->updated_at),
        ];

        if ($affiliate) {
            $row['payment_email'] = $affiliate->payment_email ? (string) $affiliate->payment_email : null;
        }

        return $row;
    }
}
