<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;

class ScheduledJobsHandler
{
    /**
     * A payout claims referrals as `processing` before settling them. Anything
     * still claimed after this window never got a transaction.
     */
    const STRANDED_CLAIM_GRACE = HOUR_IN_SECONDS;

    const RECONCILE_BATCH_SIZE = 500;

    /**
     * Each affiliate costs a recountEarnings, which is a handful of aggregates
     * plus a save, so the run is capped by affiliate as well as by row.
     */
    const RECONCILE_AFFILIATE_LIMIT = 25;

    public function register()
    {
        add_action('fluent_affiliate_scheduled_hour_jobs', [$this, 'reconcileStrandedReferrals']);
    }

    /**
     * Releases referrals left in the intermediate payout status back to unpaid.
     * They are invisible everywhere otherwise: excluded from earnings, absent
     * from the unpaid view and never picked up by a later payout.
     *
     * @return void
     */
    public function reconcileStrandedReferrals()
    {
        $stranded = $this->strandedReferralsQuery()
            ->orderBy('id', 'ASC')
            ->limit(static::RECONCILE_BATCH_SIZE)
            ->get(['id', 'affiliate_id']);

        if ($stranded->isEmpty()) {
            return;
        }

        $affiliateIds = array_slice(
            $stranded->pluck('affiliate_id')->unique()->filter()->values()->toArray(),
            0,
            static::RECONCILE_AFFILIATE_LIMIT
        );

        if (!$affiliateIds) {
            return;
        }

        $referralIds = $stranded->filter(function ($referral) use ($affiliateIds) {
            return in_array($referral->affiliate_id, $affiliateIds);
        })->pluck('id')->toArray();

        // the claim conditions are repeated here on purpose: a payout may have
        // re-claimed one of these rows since it was selected, and that row is
        // legitimately in flight
        $released = $this->strandedReferralsQuery()
            ->whereIn('id', $referralIds)
            ->update(['status' => 'unpaid']);

        if (!$released) {
            return;
        }

        foreach (Affiliate::query()->whereIn('id', $affiliateIds)->get() as $affiliate) {
            $affiliate->recountEarnings();
        }

        do_action('fluent_affiliate/payout/stranded_referrals_released', $affiliateIds, $released);
    }

    /**
     * @return \FluentAffiliate\Framework\Database\Orm\Builder
     */
    protected function strandedReferralsQuery()
    {
        // updated_at is written in site time by the model, so the cutoff has to
        // be built the same way rather than from UTC
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - static::STRANDED_CLAIM_GRACE);

        return Referral::query()
            ->where('status', 'processing')
            ->whereNull('payout_transaction_id')
            ->where('updated_at', '<', $cutoff);
    }
}
