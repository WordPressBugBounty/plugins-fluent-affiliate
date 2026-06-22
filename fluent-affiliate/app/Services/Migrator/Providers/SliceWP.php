<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

class SliceWP extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'slicewp';
    }

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliate_groups', 0);
        $db = $this->db();

        $groups = $db->table('slicewp_collections')
            ->where('object_context', 'affiliate')
            ->where('type', 'group')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($groups->isEmpty()) {
            $status['current_stage'] = 'affiliates';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // SliceWP rate_type → FA rate_type
        $rateTypeMap = [
            'percentage' => 'percentage',
            'flat'       => 'flat',
        ];

        $groupIds = $groups->pluck('id')->toArray();

        $allMeta = $db->table('slicewp_collection_meta')
            ->whereIn('slicewp_collection_id', $groupIds)
            ->whereIn('meta_key', ['commission_rate_sale', 'commission_rate_type_sale'])
            ->get()
        ;

        $metaMap = [];
        foreach ($allMeta as $meta) {
            $metaMap[$meta->slicewp_collection_id][$meta->meta_key] = $meta->meta_value;
        }

        $existingNames = AffiliateGroup::where('object_type', 'affiliate_group')
            ->whereIn('meta_key', $groups->pluck('name')->toArray())
            ->pluck('meta_key')
            ->toArray()
        ;

        foreach ($groups as $group) {
            $lastId = $group->id;

            if (in_array($group->name, $existingNames)) {
                continue;
            }

            $rate = $metaMap[$group->id]['commission_rate_sale'] ?? null;
            $rateType = $metaMap[$group->id]['commission_rate_type_sale'] ?? null;
            $faRateType = isset($rateTypeMap[$rateType]) ? $rateTypeMap[$rateType] : 'percentage';

            try {
                AffiliateGroup::insert([[
                    'meta_key'    => $group->name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a DB query argument
                    'value'       => \maybe_serialize([
                        'status'    => 'active',
                        'notes'     => 'Migrated from SliceWP',
                        'rate_type' => $faRateType,
                        'rate'      => $rate ?: 0,
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

        $affiliateStatusMap = [
            'active'   => 'active',
            'pending'  => 'pending',
            'inactive' => 'inactive',
            'rejected' => 'inactive',
        ];

        // SliceWP rate_type → FA rate_type
        $rateTypeMap = [
            'percentage' => 'percentage',
            'flat'       => 'flat',
        ];

        $db = $this->db();

        $affiliates = $db->table('slicewp_affiliates')
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

        $groupLinks = $db->table('slicewp_collection_object_relationships')
            ->whereIn('object_id', $affiliateIds)
            ->where('object_context', 'affiliate')
            ->get()
        ;

        $affiliateGroupMap = [];
        foreach ($groupLinks as $link) {
            $affiliateGroupMap[$link->object_id] = $link->collection_id;
        }

        $faGroupIdMap = $this->getGroupIdMap();

        $customRates = $db->table('slicewp_affiliate_meta')
            ->whereIn('slicewp_affiliate_id', $affiliateIds)
            ->whereIn('meta_key', ['commission_rate_sale', 'commission_rate_type_sale'])
            ->get()
        ;

        $rateMap = [];
        foreach ($customRates as $meta) {
            $affId = $meta->slicewp_affiliate_id;
            if ($meta->meta_key === 'commission_rate_sale') {
                $rateMap[$affId]['rate'] = $meta->meta_value;
            }
            if ($meta->meta_key === 'commission_rate_type_sale') {
                $rateMap[$affId]['rate_type'] = $meta->meta_value;
            }
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $affId = $affiliate->id;
            $lastId = $affId;

            if (isset($existingIds[$affId])) {
                continue;
            }

            $rate = null;
            $faRateType = 'default';
            $faGroupId = null;

            if (isset($rateMap[$affId]['rate'])) {
                $sliceRateType = $rateMap[$affId]['rate_type'] ?? 'percentage';
                $faRateType = isset($rateTypeMap[$sliceRateType]) ? $rateTypeMap[$sliceRateType] : 'percentage';
                $rate = $rateMap[$affId]['rate'];
            } elseif (isset($affiliateGroupMap[$affId])) {
                $sliceCollectionId = $affiliateGroupMap[$affId];
                if (isset($faGroupIdMap[$sliceCollectionId])) {
                    $faGroupId = $faGroupIdMap[$sliceCollectionId];
                    $faRateType = 'group';
                }
            }

            $dataToInsert[] = [
                'id'              => $affId,
                'user_id'         => $affiliate->user_id,
                'group_id'        => $faGroupId,
                'rate'            => $rate,
                'rate_type'       => $faRateType,
                'payment_email'   => $affiliate->payment_email ?: null,
                'status'          => isset($affiliateStatusMap[$affiliate->status]) ? $affiliateStatusMap[$affiliate->status] : 'active',
                'note'            => $affiliate->website ? 'Website: ' . $affiliate->website : null,
                'total_earnings'  => 0,
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0,
                'created_at'      => $affiliate->date_created,
                'updated_at'      => $affiliate->date_modified,
            ];
        }

        try {
            if (!empty($dataToInsert)) {
                $db->table('fa_affiliates')->insert($dataToInsert);
            }
        } catch (\Exception $e) {
            // Skip this batch to avoid blocking the rest of the migration
        }

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

        // SliceWP commission statuses: paid, unpaid, pending, rejected
        // FA referral statuses: paid, unpaid, pending, rejected, cancelled
        $commissionStatusMap = [
            'paid'     => 'paid',
            'unpaid'   => 'unpaid',
            'pending'  => 'pending',
            'rejected' => 'rejected',
        ];

        $db = $this->db();

        $commissions = $db->table('slicewp_commissions')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($commissions->isEmpty()) {
            $this->resetAutoIncrement('fa_referrals');
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($db->table('fa_referrals')
            ->whereIn('id', $commissions->pluck('id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($commissions as $commission) {
            $lastId = $commission->id;

            if (isset($existingIds[$commission->id])) {
                continue;
            }

            $dataToInsert[] = [
                'id'              => $commission->id,
                'affiliate_id'    => $commission->affiliate_id ?: null,
                'visit_id'        => $commission->visit_id ?: null,
                'customer_id'     => $commission->customer_id ?: null,
                'parent_id'       => $commission->parent_id ?: null,
                'description'     => null,
                'status'          => $commissionStatusMap[$commission->status] ?? 'pending',
                'amount'          => $commission->amount,
                'order_total'     => $commission->reference_amount,
                'currency'        => $commission->currency ?: null,
                'type'            => ($commission->type === 'subscription') ? 'recurring_sale' : 'sale',
                'provider'        => $commission->origin ?: null,
                'provider_id'     => is_numeric($commission->reference) ? $commission->reference : null,
                'provider_sub_id' => !is_numeric($commission->reference) ? $commission->reference : null,
                'created_at'      => $commission->date_created,
                'updated_at'      => $commission->date_modified,
            ];
        }

        try {
            if (!empty($dataToInsert)) {
                $db->table('fa_referrals')->insert($dataToInsert);
            }
        } catch (\Exception $e) {
            // Skip this batch to avoid blocking the rest of the migration
        }

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

        $lastId = (int) Arr::get($status, 'migrated_customers', 0);
        $db = $this->db();

        $customers = $db->table('slicewp_customers')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($customers->isEmpty()) {
            $this->resetAutoIncrement('fa_customers');
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($db->table('fa_customers')
            ->whereIn('id', $customers->pluck('id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($customers as $customer) {
            $lastId = $customer->id;

            if (isset($existingIds[$customer->id])) {
                continue;
            }

            $dataToInsert[] = [
                'id'              => $customer->id,
                'user_id'         => $customer->user_id ?: null,
                'by_affiliate_id' => $customer->affiliate_id ?: null,
                'email'           => $customer->email ?: null,
                'first_name'      => $customer->first_name ?: null,
                'last_name'       => $customer->last_name ?: null,
                'created_at'      => $customer->date_created,
                'updated_at'      => $customer->date_modified,
            ];
        }

        try {
            if (!empty($dataToInsert)) {
                $db->table('fa_customers')->insert($dataToInsert);
            }
        } catch (\Exception $e) {
            // Skip this batch to avoid blocking the rest of the migration
        }

        $status['migrated_customers'] = $lastId;
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

        $lastId = (int) Arr::get($status, 'migrated_payout_id', 0);

        $payoutMethodMap = [
            'manual' => 'manual',
            'paypal' => 'paypal',
        ];

        // SliceWP payment statuses: paid, unpaid, processing, failed
        // FA transaction statuses: paid, processing
        $paymentStatusMap = [
            'paid'       => 'paid',
            'unpaid'     => 'processing',
            'processing' => 'processing',
            'failed'     => 'processing',
        ];

        $db = $this->db();

        $payouts = $db->table('slicewp_payouts')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($payouts->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $payoutIds = $payouts->pluck('id')->toArray();

        $allPayments = $db->table('slicewp_payments')
            ->whereIn('payout_id', $payoutIds)
            ->get()
        ;

        $paymentsByPayout = [];
        foreach ($allPayments as $payment) {
            $paymentsByPayout[$payment->payout_id][] = $payment;
        }

        foreach ($payouts as $payout) {
            $lastId = $payout->id;

            $payments = $paymentsByPayout[$payout->id] ?? [];

            if (empty($payments)) {
                continue;
            }

            $firstPayment = $payments[0];

            $formattedPayout = [
                'created_by'    => $payout->originator_user_id ?: null,
                'total_amount'  => $payout->amount,
                'payout_method' => $payoutMethodMap[$firstPayment->payout_method] ?? 'manual',
                'status'        => 'paid',
                'currency'      => $firstPayment->currency ?: null,
                'title'         => 'Payout batch at ' . $payout->date_created,
                'description'   => 'Migrated from SliceWP',
                'created_at'    => $payout->date_created,
                'updated_at'    => $payout->date_modified,
            ];

            try {
                $db->beginTransaction();

                $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

                foreach ($payments as $payment) {
                    $transactionId = $db->table('fa_payout_transactions')->insertGetId([
                        'affiliate_id'  => $payment->affiliate_id,
                        'payout_id'     => $payoutId,
                        'total_amount'  => $payment->amount,
                        'payout_method' => $payoutMethodMap[$payment->payout_method] ?? 'manual',
                        'created_by'    => $payment->originator_user_id ?: null,
                        'status'        => $paymentStatusMap[$payment->status] ?? 'paid',
                        'currency'      => $payment->currency ?: null,
                        'created_at'    => $payment->date_created,
                        'updated_at'    => $payment->date_modified,
                    ]);

                    $commissionIds = $this->parseCommissionIds($payment->commission_ids);

                    if (!empty($commissionIds)) {
                        $db->table('fa_referrals')
                            ->whereIn('id', $commissionIds)
                            ->update([
                                'payout_id'             => $payoutId,
                                'payout_transaction_id' => $transactionId,
                            ])
                        ;
                    }
                }

                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();
                // Skip this payout batch to avoid blocking the rest of the migration
            }
        }

        $status['migrated_payout_id'] = $lastId;
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

        $visits = $db->table('slicewp_visits')
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
                'referral_id'  => $visit->commission_id ?: null,
                'url'          => $visit->landing_url ?: null,
                'referrer'     => $visit->referrer_url ?: null,
                'ip'           => $visit->ip_address ?: null,
                'utm_campaign' => null,
                'created_at'   => $visit->date_created,
                'updated_at'   => $visit->date_modified,
            ];
        }

        try {
            if (!empty($dataToInsert)) {
                $db->table('fa_visits')->insert($dataToInsert);
            }
        } catch (\Exception $e) {
            // Skip this batch to avoid blocking the rest of the migration
        }

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

        $creatives = $db->table('slicewp_creatives')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($creatives->isEmpty()) {
            $status['current_stage'] = 'completed';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // FA accepted types: image, qr_code, text
        // SliceWP types: image, text, long_text
        $validTypes = ['image', 'qr_code', 'text'];

        $existingNames = array_flip($db->table('fa_creatives')
            ->whereIn('name', $creatives->pluck('name')->toArray())
            ->pluck('name')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($creatives as $creative) {
            $lastId = $creative->id;

            if (isset($existingNames[$creative->name])) {
                continue;
            }

            $dataToInsert[] = [
                'name'        => $creative->name,
                'description' => $creative->description ?: null,
                'type'        => in_array($creative->type, $validTypes) ? $creative->type : 'text',
                'image'       => $creative->image_url ?: null,
                'text'        => $creative->text ?: null,
                'url'         => $creative->landing_url ?: null,
                'privacy'     => 'public',
                'status'      => $creative->status ?: 'active',
                'meta'        => $creative->alt_text ? maybe_serialize(['alt_text' => $creative->alt_text]) : null,
                'created_at'  => $creative->date_created,
                'updated_at'  => $creative->date_modified,
            ];
        }

        try {
            if (!empty($dataToInsert)) {
                $db->table('fa_creatives')->insert($dataToInsert);
            }
        } catch (\Exception $e) {
            // Skip this batch to avoid blocking the rest of the migration
        }

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

        return [
            'affiliate_groups' => $db->table('slicewp_collections')
                ->where('object_context', 'affiliate')
                ->where('type', 'group')
                ->count() ?: 0,
            'affiliates'       => $db->table('slicewp_affiliates')->count() ?: 0,
            'referrals'        => $db->table('slicewp_commissions')->count() ?: 0,
            'customers'        => $db->table('slicewp_customers')->count() ?: 0,
            'payouts'          => $db->table('slicewp_payouts')->count() ?: 0,
            'visits'           => $db->table('slicewp_visits')->count() ?: 0,
            'creatives'        => $db->table('slicewp_creatives')->count() ?: 0
        ];
    }

    /**
     * Build slicewp collection_id → fa_meta.id map by matching group names.
     */
    private function getGroupIdMap()
    {
        $map = [];

        $sliceGroups = $this->db()
            ->table('slicewp_collections')
            ->select(['id', 'name'])
            ->where('object_context', 'affiliate')
            ->where('type', 'group')
            ->get()
        ;

        if ($sliceGroups->isEmpty()) {
            return $map;
        }

        $faGroups = AffiliateGroup::where('object_type', 'affiliate_group')->get();

        foreach ($sliceGroups as $sg) {
            foreach ($faGroups as $fg) {
                if ($fg->meta_key === $sg->name) {
                    $map[$sg->id] = $fg->id;
                    break;
                }
            }
        }

        return $map;
    }

    private function hasTable($tableName)
    {
        $wpdb = $GLOBALS['wpdb'];
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
     * Reset AUTO_INCREMENT to MAX(id)+1 so future organic inserts
     * don't collide with imported IDs.
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
     * true when every affiliate has been recounted, false when it stopped early
     * on the time limit (the caller must re-invoke to resume).
     */
    private function recountAffiliateEarnings()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            fluentAffiliate_update_option('slicewp_migrated_recount', 0);
            return true;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
        }

        fluentAffiliate_update_option('slicewp_migrated_recount', $lastId);

        if ($this->isTimeLimitExceeded()) {
            return false;
        }

        return $this->recountAffiliateEarnings();
    }

    /**
     * Parse commission_ids from SliceWP payment record.
     */
    private function parseCommissionIds($raw)
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
