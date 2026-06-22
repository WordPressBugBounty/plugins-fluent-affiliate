<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

class UltimateAffiliate extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'ultimate_affiliate';
    }

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliate_groups', 0);
        $db = $this->db();

        $ranks = $db->table('uap_ranks')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($ranks->isEmpty()) {
            $status['current_stage'] = 'affiliates';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // UAP amount_type → FA rate_type
        $rateTypeMap = [
            'percentage' => 'percentage',
            'flat'       => 'flat',
        ];

        $existingNames = AffiliateGroup::where('object_type', 'affiliate_group')
            ->whereIn('meta_key', $ranks->pluck('label')->toArray())
            ->pluck('meta_key')
            ->toArray()
        ;

        foreach ($ranks as $rank) {
            $lastId = $rank->id;

            if (in_array($rank->label, $existingNames)) {
                continue;
            }

            $faRateType = isset($rateTypeMap[$rank->amount_type]) ? $rateTypeMap[$rank->amount_type] : 'percentage';

            try {
                AffiliateGroup::insert([[
                    'meta_key'    => $rank->label, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a DB query argument
                    'value'       => \maybe_serialize([
                        'status'    => 'active',
                        'notes'     => 'Migrated from Ultimate Affiliate',
                        'rate_type' => $faRateType,
                        'rate'      => $rank->amount_value ?: 0,
                    ]),
                    'object_type' => 'affiliate_group',
                ]]);
            } catch (\Exception $e) {
                // Skip failed group to avoid blocking the rest of the migration
            }
        }

        $status['migrated_affiliate_groups'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliateGroups($status);
    }

    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliates', 0);

        $db = $this->db();

        $affiliates = $db->table('uap_affiliates')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            $this->resetAutoIncrement('fa_affiliates');
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $affiliateIds = $affiliates->pluck('id')->toArray();

        $existingIds = array_flip($db->table('fa_affiliates')
            ->whereIn('id', $affiliateIds)
            ->pluck('id')
            ->toArray()
        );

        $rankMap     = $this->getRankMap();
        $rankGroupMap = $this->getGroupIdMap();

        // Prime the user cache so the per-row get_user_meta() below are cache hits.
        $uapUserIds = array_filter(array_map('intval', $affiliates->pluck('uid')->toArray()));
        if (!empty($uapUserIds)) {
            cache_users($uapUserIds);
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $affId = $affiliate->id;
            $lastId = $affId;

            if (isset($existingIds[$affId])) {
                continue;
            }

            // UAP affiliate uid links to a WP user; keep it null when absent rather than
            $userId = (int) $affiliate->uid ?: null;

            // Commission for a ranked affiliate is governed at the group (rank) level,
            // so the rate lives on the group, not the affiliate row.
            $faRateType = isset($rankMap[$affiliate->rank_id]) ? 'group' : 'default';

            $groupId = isset($rankGroupMap[$affiliate->rank_id]) ? $rankGroupMap[$affiliate->rank_id] : null;

            $paymentEmail = $userId ? get_user_meta($userId, 'uap_affiliate_paypal_email', true) : '';

            $dataToInsert[] = [
                'id'              => $affId,
                'user_id'         => $userId,
                'group_id'        => $groupId,
                'rate'            => null,
                'rate_type'       => $faRateType,
                'payment_email'   => $paymentEmail ?: null,
                'status'          => $affiliate->status ? 'active' : 'inactive',
                'note'            => 'Migrated from Ultimate Affiliate',
                'total_earnings'  => 0,
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0,
                'created_at'      => $this->normalizeDate($affiliate->start_data),
                'updated_at'      => $this->normalizeDate($affiliate->start_data),
            ];
        }

        $this->insertBatch('fa_affiliates', $dataToInsert);

        $status['migrated_affiliates'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliates($status);
    }

    public function migrateReferrals($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_referrals', 0);

        $db = $this->db();

        $referrals = $db->table('uap_referrals')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($referrals->isEmpty()) {
            $this->resetAutoIncrement('fa_referrals');
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($db->table('fa_referrals')
            ->whereIn('id', $referrals->pluck('id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $defaultCurrency = (get_option('uap_currency') ?: Utility::getCurrency());

        $dataToInsert = [];

        foreach ($referrals as $referral) {
            $lastId = $referral->id;

            if (isset($existingIds[$referral->id])) {
                continue;
            }

            $reference     = $referral->reference;
            $providerId    = is_numeric($reference) ? (int) $reference : null;
            $providerSubId = is_numeric($reference) ? null : $reference;

            $dataToInsert[] = [
                'id'              => $referral->id,
                'affiliate_id'    => $referral->affiliate_id ?: null,
                'visit_id'        => $referral->visit_id ?: null,
                'parent_id'       => $referral->parent_referral_id ?: null,
                'customer_id'     => null, // linked after customers stage
                'description'     => $referral->description ?: null,
                'status'          => $this->mapReferralStatus($referral->status, $referral->payment),
                'amount'          => $referral->amount ?: 0,
                'order_total'     => 0,
                'currency'        => $referral->currency ?: $defaultCurrency,
                'utm_campaign'    => $referral->campaign ?: null,
                'provider'        => $referral->source ?: null,
                'provider_id'     => $providerId,
                'provider_sub_id' => $providerSubId,
                'type'            => 'sale',
                'products'        => \maybe_serialize([]),
                'created_at'      => $this->normalizeDate($referral->date),
                'updated_at'      => $this->normalizeDate($referral->date),
            ];
        }

        $this->insertBatch('fa_referrals', $dataToInsert);

        $status['migrated_referrals'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateReferrals($status);
    }

    public function migrateCustomers($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_customers', 0);
        $db = $this->db();

        // UAP has no customers table — synthesize one row per (referred WP user, affiliate) pair
        $customers = $db->table('uap_referrals')
            ->select([
                'refferal_wp_uid',
                'affiliate_id',
                $db->raw('MIN(date) as first_referral_date'),
            ])
            ->where('refferal_wp_uid', '>', 0)
            ->groupBy('refferal_wp_uid', 'affiliate_id')
            ->orderBy('refferal_wp_uid', 'ASC')
            ->orderBy('affiliate_id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($customers->isEmpty()) {
            $status = $this->linkCustomersToReferrals($status);

            if ($this->isTimeLimitExceeded()) {
                $this->updateCurrentStatus($status, false);
                return $status;
            }

            $this->resetAutoIncrement('fa_customers');
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // Synthesized customers have no source id, so dedup on the natural
        // (user_id, by_affiliate_id) pair to stay idempotent on re-run.
        $existingPairs = [];
        $existingRows = $db->table('fa_customers')
            ->whereIn('user_id', $customers->pluck('refferal_wp_uid')->toArray())
            ->get(['user_id', 'by_affiliate_id'])
        ;
        foreach ($existingRows as $row) {
            $existingPairs[$row->user_id . '-' . $row->by_affiliate_id] = true;
        }

        // Prime the user cache so the per-row get_user_by('id') below are cache hits.
        $customerUserIds = array_filter(array_map('intval', $customers->pluck('refferal_wp_uid')->toArray()));
        if (!empty($customerUserIds)) {
            cache_users($customerUserIds);
        }

        $dataToInsert = [];

        foreach ($customers as $customer) {
            $migratedCount++;

            $userId = (int) $customer->refferal_wp_uid;

            if (isset($existingPairs[$userId . '-' . (int) $customer->affiliate_id])) {
                continue;
            }

            $wpUser = $userId ? get_user_by('id', $userId) : null;

            $email     = '';
            $firstName = '';
            $lastName  = '';

            if ($wpUser) {
                $email     = $wpUser->user_email;
                $firstName = $wpUser->first_name ?: '';
                $lastName  = $wpUser->last_name ?: '';

                if (empty($firstName) && empty($lastName)) {
                    $nameParts = explode(' ', $wpUser->display_name, 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName  = $nameParts[1] ?? '';
                }
            }

            $dataToInsert[] = [
                'user_id'         => $userId ?: null,
                'by_affiliate_id' => $customer->affiliate_id ?: null,
                'email'           => $email ?: null,
                'first_name'      => substr($firstName, 0, 192),
                'last_name'       => substr($lastName, 0, 192),
                'settings'        => \maybe_serialize([]),
                'created_at'      => $this->normalizeDate($customer->first_referral_date),
                'updated_at'      => $this->normalizeDate($customer->first_referral_date),
            ];
        }

        $this->insertBatch('fa_customers', $dataToInsert);

        $status['migrated_customers'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCustomers($status);
    }

    public function migratePayouts($status = [], $limit = 50)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_payout_id', 0);
        $totalPayouts  = (int) $this->db()->table('uap_payouts')->count();

        // Source-id dedup: skip payouts already imported, so a re-run (or
        // reset_migration without a wipe) does not double-credit payout records.
        $migratedSources = $this->getMigratedPayoutSources();

        // Phase A imports real payout batches; once exhausted, Phase B imports
        // standalone payments not attached to any batch.
        if ($migratedCount < $totalPayouts) {
            return $this->migratePayoutBatches($status, $migratedCount, $totalPayouts, $migratedSources, $limit);
        }

        return $this->migrateOrphanPayments($status, $migratedCount, $totalPayouts, $migratedSources, $limit);
    }

    /**
     * Phase A — import real payout batches (uap_payouts) and their member payments.
     */
    private function migratePayoutBatches($status, $migratedCount, $totalPayouts, $migratedSources, $limit)
    {
        $db = $this->db();

        $payouts = $db->table('uap_payouts')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        // Batch-load the payout→payment links and the payments for the whole page
        // (one query each) instead of two queries per payout.
        $payoutIds          = $payouts->pluck('id')->toArray();
        $paymentIdsByPayout = [];
        $allPaymentIds      = [];

        if (!empty($payoutIds)) {
            $metaRows = $db->table('uap_payments_meta')
                ->where('meta_name', 'payout_id')
                ->whereIn('meta_value', $payoutIds)
                ->get()
            ;
            foreach ($metaRows as $metaRow) {
                $paymentIdsByPayout[$metaRow->meta_value][] = $metaRow->payment_id;
                $allPaymentIds[] = $metaRow->payment_id;
            }
        }

        $paymentsById = [];
        if (!empty($allPaymentIds)) {
            $paymentRows = $db->table('uap_payments')->whereIn('id', $allPaymentIds)->get();
            foreach ($paymentRows as $paymentRow) {
                $paymentsById[$paymentRow->id] = $paymentRow;
            }
        }

        foreach ($payouts as $payout) {
            $migratedCount++;

            if (isset($migratedSources['payout:' . $payout->id])) {
                continue;
            }

            $paymentIds = $paymentIdsByPayout[$payout->id] ?? [];

            $payments = [];
            foreach ($paymentIds as $paymentId) {
                if (isset($paymentsById[$paymentId])) {
                    $payments[] = $paymentsById[$paymentId];
                }
            }

            if (empty($payments)) {
                continue;
            }

            $currency = $payout->currency ?: (get_option('uap_currency') ?: Utility::getCurrency());

            $formattedPayout = [
                'created_by'    => get_current_user_id(),
                'total_amount'  => $payout->amount ?: 0,
                'payout_method' => $this->mapPayoutMethod($payout->method),
                'status'        => 'paid',
                'currency'      => $currency,
                'title'         => 'Payout batch at ' . $payout->created_time,
                'description'   => 'Migrated from Ultimate Affiliate',
                'settings'      => \maybe_serialize(['uap_source' => 'payout:' . $payout->id]),
                'created_at'    => $this->normalizeDate($payout->created_time),
                'updated_at'    => $this->normalizeDate($payout->updated_time ?: $payout->created_time),
            ];

            $this->writePayoutBatch($formattedPayout, $payments, $currency);
        }

        $status['migrated_payout_id'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migratePayouts($status);
    }

    /**
     * Phase B — import standalone payments not attached to any payout batch,
     * each as its own single-transaction payout.
     */
    private function migrateOrphanPayments($status, $migratedCount, $totalPayouts, $migratedSources, $limit)
    {
        $db = $this->db();

        // Anti-join via NOT EXISTS keeps the exclusion server-side instead of
        // materializing every batched payment id into a growing whereNotIn list.
        $orphanOffset = $migratedCount - $totalPayouts;

        $orphans = $db->table('uap_payments')
            ->orderBy('id', 'ASC')
            ->whereNotExists(function ($q) use ($db) {
                $q->select($db->raw(1))
                    ->from('uap_payments_meta')
                    ->where('uap_payments_meta.meta_name', 'payout_id')
                    ->whereColumn('uap_payments_meta.payment_id', 'uap_payments.id');
            })
            ->offset($orphanOffset)
            ->limit($limit)
            ->get()
        ;

        if ($orphans->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        foreach ($orphans as $payment) {
            $migratedCount++;

            if (isset($migratedSources['payment:' . $payment->id])) {
                continue;
            }

            $currency = $payment->currency ?: (get_option('uap_currency') ?: Utility::getCurrency());

            $formattedPayout = [
                'created_by'    => get_current_user_id(),
                'total_amount'  => $payment->amount ?: 0,
                'payout_method' => $this->mapPayoutMethod($payment->payment_type),
                'status'        => 'paid',
                'currency'      => $currency,
                'title'         => sprintf('Payout for Affiliate #%s', $payment->affiliate_id),
                'description'   => 'Migrated from Ultimate Affiliate',
                'settings'      => \maybe_serialize(['uap_source' => 'payment:' . $payment->id]),
                'created_at'    => $this->normalizeDate($payment->create_date),
                'updated_at'    => $this->normalizeDate($payment->update_date ?: $payment->create_date),
            ];

            $this->writePayoutBatch($formattedPayout, [$payment], $currency);
        }

        $status['migrated_payout_id'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migratePayouts($status);
    }

    public function migrateVisits($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_visits', 0);

        $db = $this->db();

        $visits = $db->table('uap_visits')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($visits->isEmpty()) {
            $this->resetAutoIncrement('fa_visits');

            // Stay in the 'visits' stage until the paginated recount fully completes;
            // advancing early would leave affiliates past the recount cursor at zero earnings.
            if ($this->recountAffiliateEarnings()) {
                $status['current_stage'] = 'creatives';
            }

            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($db->table('fa_visits')
            ->whereIn('id', $visits->pluck('id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($visits as $visit) {
            $lastId = $visit->id;

            if (isset($existingIds[$visit->id])) {
                continue;
            }

            $dataToInsert[] = [
                'id'           => $visit->id,
                'affiliate_id' => $visit->affiliate_id ?: null,
                'referral_id'  => $visit->referral_id ?: null,
                'url'          => $visit->url ?: null,
                'referrer'     => $visit->ref_url ?: null,
                'utm_campaign' => $visit->campaign_name ?: null,
                'ip'           => $visit->ip ?: null,
                'created_at'   => $this->normalizeDate($visit->visit_date),
                'updated_at'   => $this->normalizeDate($visit->visit_date),
            ];
        }

        $this->insertBatch('fa_visits', $dataToInsert);

        $status['migrated_visits'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateVisits($status);
    }

    public function migrateCreatives($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        if (!$this->hasTable('fa_creatives')) {
            $status['current_stage'] = 'completed';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $lastId = (int) Arr::get($status, 'migrated_creatives', 0);
        $db = $this->db();

        $banners = $db->table('uap_banners')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($banners->isEmpty()) {
            $status['current_stage'] = 'completed';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingNames = array_flip($db->table('fa_creatives')
            ->whereIn('name', $banners->pluck('name')->toArray())
            ->pluck('name')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($banners as $banner) {
            $lastId = $banner->id;

            if (isset($existingNames[$banner->name])) {
                continue;
            }

            $dataToInsert[] = [
                'name'        => $banner->name,
                'description' => $banner->description ?: null,
                'type'        => 'image',
                'image'       => $banner->image ?: $banner->url,
                'text'        => null,
                'url'         => $banner->url ?: null,
                'privacy'     => 'public',
                'status'      => $banner->status ? 'active' : 'inactive',
                'created_at'  => $this->normalizeDate($banner->DATE),
                'updated_at'  => $this->normalizeDate($banner->DATE),
            ];
        }

        $this->insertBatch('fa_creatives', $dataToInsert);

        $status['migrated_creatives'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCreatives($status);
    }

    public function getCounts()
    {
        $db = $this->db();

        $counts = [
            'affiliate_groups' => 0,
            'affiliates'       => 0,
            'referrals'        => 0,
            'customers'        => 0,
            'payouts'          => 0,
            'visits'           => 0,
            'creatives'        => 0,
        ];

        try {
            $counts['affiliate_groups'] = (int) $db->table('uap_ranks')->count();
            $counts['affiliates']       = (int) $db->table('uap_affiliates')->count();
            $counts['referrals']        = (int) $db->table('uap_referrals')->count();
            $counts['customers']        = (int) $db->table('uap_referrals')
                ->selectRaw("COUNT(DISTINCT CONCAT(refferal_wp_uid, '-', affiliate_id)) as count")
                ->where('refferal_wp_uid', '>', 0)
                ->value('count');
            $counts['payouts']          = (int) $db->table('uap_payouts')->count();
            $counts['visits']           = (int) $db->table('uap_visits')->count();
            $counts['creatives']        = (int) $db->table('uap_banners')->count();
        } catch (\Exception $e) {
            // Tables missing — return zeros
        }

        return $counts;
    }

    /**
     * Bulk-insert a batch; on failure fall back to per-row inserts so one bad row
     * (e.g. a zero-date rejected under strict SQL mode) can't drop the whole batch.
     * Skipped source ids are logged (no PII) for auditing.
     */
    private function insertBatch($table, $rows)
    {
        if (empty($rows)) {
            return;
        }

        try {
            $this->db()->table($table)->insert($rows);
            return;
        } catch (\Exception $e) {
            // Bulk insert failed — retry row-by-row below.
        }

        $skipped = [];

        foreach ($rows as $row) {
            try {
                $this->db()->table($table)->insert($row);
            } catch (\Exception $e) {
                $skipped[] = isset($row['id']) ? $row['id'] : '?';
            }
        }

        if (!empty($skipped)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Migration audit trail; ids only, no PII
            error_log(sprintf(
                '[FluentAffiliate] Ultimate Affiliate migration skipped %d row(s) inserting into %s (source ids: %s)',
                count($skipped),
                $table,
                implode(',', $skipped)
            ));
        }
    }

    /**
     * Normalize a UAP source timestamp: empty / MySQL zero-date sentinels become null
     * (the fa_* timestamp columns are TIMESTAMP NULL) so strict-mode inserts don't throw.
     */
    private function normalizeDate($value)
    {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }

    /**
     * Collapse UAP's two state columns into FA's single referral status.
     * status: 0 refused / 1 unverified / 2 verified — payment: 0 unpaid / 1 pending / 2 paid
     *
     * Policy: UAP's `payment` flag is authoritative for paid/unpaid. A referral can
     * therefore be 'paid' without an imported payout row when UAP holds no matching
     * uap_payments record. We intentionally do NOT defer 'paid' to payout linkage —
     * flipping such a row back to 'unpaid' would resurrect an already-paid commission
     * into unpaid_earnings and risk a double payout. Earnings stay correct because
     * Affiliate::recountEarnings() is driven by status, not by payout_id presence.
     */
    private function mapReferralStatus($status, $payment)
    {
        $status  = (int) $status;
        $payment = (int) $payment;

        if ($status === 0) {
            return 'rejected';
        }

        if ($status === 1) {
            return 'pending';
        }

        return $payment === 2 ? 'paid' : 'unpaid';
    }

    private function mapPayoutMethod($method)
    {
        return ($method === 'paypal') ? 'paypal' : 'manual';
    }

    /**
     * Build uap_ranks.id → ['rate', 'rate_type'] map (ranks are few; load once per batch).
     */
    private function getRankMap()
    {
        $map = [];

        $ranks = $this->db()->table('uap_ranks')
            ->select(['id', 'amount_type', 'amount_value'])
            ->get()
        ;

        foreach ($ranks as $rank) {
            $map[$rank->id] = [
                'rate'      => $rank->amount_value ?: 0,
                'rate_type' => $rank->amount_type,
            ];
        }

        return $map;
    }

    /**
     * Build uap_ranks.id → fa_meta.id (affiliate_group) map by matching rank label to group name.
     */
    private function getGroupIdMap()
    {
        $map = [];

        $ranks = $this->db()->table('uap_ranks')->select(['id', 'label'])->get();

        if ($ranks->isEmpty()) {
            return $map;
        }

        $faGroups = AffiliateGroup::where('object_type', 'affiliate_group')->get();

        foreach ($ranks as $rank) {
            foreach ($faGroups as $group) {
                if ($group->meta_key === $rank->label) {
                    $map[$rank->id] = $group->id;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Link synthesized customers back to their referrals via the source table.
     * Idempotent — only touches referrals whose customer_id is still null.
     */
    private function linkCustomersToReferrals($status)
    {
        $db = $this->db();

        $customers = $db->table('fa_customers')
            ->whereNotNull('user_id')
            ->whereNotNull('by_affiliate_id')
            ->get()
        ;

        foreach ($customers as $customer) {
            $referralIds = $db->table('uap_referrals')
                ->where('refferal_wp_uid', $customer->user_id)
                ->where('affiliate_id', $customer->by_affiliate_id)
                ->pluck('id')
            ;
            $referralIds = is_array($referralIds) ? $referralIds : $referralIds->toArray();

            if (!empty($referralIds)) {
                $db->table('fa_referrals')
                    ->whereIn('id', $referralIds)
                    ->where('affiliate_id', $customer->by_affiliate_id)
                    ->whereNull('customer_id')
                    ->update(['customer_id' => $customer->id])
                ;
            }

            if ($this->isTimeLimitExceeded()) {
                break;
            }
        }

        return $status;
    }

    /**
     * Set of UAP source markers ('payout:<id>' / 'payment:<id>') already imported into
     * fa_payouts.settings, used to skip re-importing on a re-run or reset-without-wipe.
     */
    private function getMigratedPayoutSources()
    {
        $sources = [];

        $rows = $this->db()->table('fa_payouts')->select(['settings'])->get();

        foreach ($rows as $row) {
            $data = Utility::safeUnserialize($row->settings);
            if (is_array($data) && !empty($data['uap_source'])) {
                $sources[$data['uap_source']] = true;
            }
        }

        return $sources;
    }

    /**
     * Persist one fa_payouts header + its fa_payout_transactions, and link the paid referrals.
     */
    private function writePayoutBatch($formattedPayout, $payments, $currency)
    {
        $db = $this->db();

        // FA transaction statuses: paid / processing
        $paymentStatusMap = [
            2 => 'paid',
            1 => 'processing',
            0 => 'processing',
        ];

        try {
            $db->beginTransaction();

            $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

            $totalAmount = 0;

            foreach ($payments as $payment) {
                $transactionId = $db->table('fa_payout_transactions')->insertGetId([
                    'payout_id'     => $payoutId,
                    'affiliate_id'  => $payment->affiliate_id ?: null,
                    'total_amount'  => $payment->amount ?: 0,
                    'payout_method' => $this->mapPayoutMethod($payment->payment_type),
                    'created_by'    => get_current_user_id(),
                    'status'        => $paymentStatusMap[(int) $payment->status] ?? 'processing',
                    'currency'      => $currency,
                    'settings'      => \maybe_serialize([]),
                    'created_at'    => $this->normalizeDate($payment->create_date),
                    'updated_at'    => $this->normalizeDate($payment->update_date ?: $payment->create_date),
                ]);

                $totalAmount += (float) ($payment->amount ?: 0);

                $referralIds = $this->parseReferralIds($payment->referral_ids);

                if (!empty($referralIds)) {
                    $db->table('fa_referrals')
                        ->whereIn('id', $referralIds)
                        ->update([
                            'payout_id'             => $payoutId,
                            'payout_transaction_id' => $transactionId,
                        ])
                    ;
                }
            }

            // Reconcile the header total with the sum of its transactions so the
            // payout total never diverges from the rows it actually contains.
            $db->table('fa_payouts')
                ->where('id', $payoutId)
                ->update(['total_amount' => $totalAmount])
            ;

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            // Skip this payout batch to avoid blocking the rest of the migration
        }
    }

    private function hasTable($tableName)
    {
        $wpdb     = $GLOBALS['wpdb'];
        $fullName = $wpdb->prefix . $tableName;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check; result not worth caching
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullName)));
    }

    private function getAllowedResetTables()
    {
        return [
            'fa_affiliates', 'fa_referrals', 'fa_customers', 'fa_visits',
        ];
    }

    /**
     * Reset AUTO_INCREMENT to MAX(id)+1 so future organic inserts don't collide with imported IDs.
     */
    private function resetAutoIncrement($tableName)
    {
        if (!in_array($tableName, $this->getAllowedResetTables(), true)) {
            return;
        }

        $wpdb      = $GLOBALS['wpdb'];
        $table     = $wpdb->prefix . $tableName;
        $safeTable = esc_sql($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from allowlist, sanitized via esc_sql()
        $maxId = (int) $wpdb->get_var("SELECT MAX(id) FROM `{$safeTable}`");

        if ($maxId > 0) {
            $next = $maxId + 1;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from allowlist, sanitized via esc_sql()
            $wpdb->query($wpdb->prepare("ALTER TABLE `{$safeTable}` AUTO_INCREMENT = %d", $next));
        }
    }

    /**
     * Paginated recount to avoid OOM on large datasets. The cursor stores the
     * last processed affiliate id (not a row offset) so each batch resumes with
     * an indexed id range instead of an ever-growing SQL OFFSET scan. Returns
     * true when every affiliate has been recounted, false if it stopped early
     * on the time limit (the caller must re-invoke to resume).
     */
    private function recountAffiliateEarnings()
    {
        $lastId = (int) fluentAffiliate_get_option('ultimate_affiliate_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            fluentAffiliate_update_option('ultimate_affiliate_migrated_recount', 0);
            return true;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
        }

        fluentAffiliate_update_option('ultimate_affiliate_migrated_recount', $lastId);

        if ($this->isTimeLimitExceeded()) {
            return false;
        }

        return $this->recountAffiliateEarnings();
    }

    /**
     * Parse referral_ids from a UAP payment record (CSV / JSON / serialized / numeric).
     */
    private function parseReferralIds($raw)
    {
        if (empty($raw)) {
            return [];
        }

        $ids = Utility::safeUnserialize($raw);

        if (is_array($ids)) {
            return array_filter(array_map('absint', $ids));
        }

        $ids = json_decode($raw, true);

        if (is_array($ids)) {
            return array_filter(array_map('absint', $ids));
        }

        if (is_string($raw) && strpos($raw, ',') !== false) {
            return array_filter(array_map('absint', explode(',', $raw)));
        }

        if (is_numeric($raw)) {
            return [absint($raw)];
        }

        return [];
    }
}
