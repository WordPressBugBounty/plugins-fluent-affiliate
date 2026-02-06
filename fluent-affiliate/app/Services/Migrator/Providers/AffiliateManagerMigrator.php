<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Framework\Container\Contracts\BindingResolutionException;
use FluentAffiliate\Framework\Support\Arr;
class AffiliateManagerMigrator extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'wpam';
    }

    /**
     * Get counts of source data from Affiliate Manager
     *
     * @return array
     */
    public function getCounts()
    {
        $counts = [
            'affiliates' => 0,
            'referrals' => 0,
            'customers' => 0,
            'payouts' => 0,
            'visits' => 0,
        ];

        try {
            // Count affiliates
            $counts['affiliates'] = (int) $this->db()->table('wpam_affiliates')->count();

            // Count referrals (credit, refund, adjustment)
            $counts['referrals'] = (int) $this->db()->table('wpam_transactions')
                ->whereIn('type', ['credit', 'refund', 'adjustment'])
                ->count();

            // Count unique customers from transaction emails
            $counts['customers'] = (int) $this->db()->table('wpam_transactions')
                ->selectRaw("COUNT(DISTINCT CONCAT(email, '-', affiliateId)) as count")
                ->whereIn('type', ['credit', 'refund', 'adjustment'])
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->value('count');

            // Count payouts
            $counts['payouts'] = (int) $this->db()->table('wpam_transactions')
                ->where('type', 'payout')
                ->count();

            // Count visits
            $counts['visits'] = (int) $this->db()->table('wpam_tracking_tokens')->count();
        } catch (\Exception $e) {
            // If tables don't exist, return zeros
        }

        return $counts;
    }

    /**
     * Migrate affiliate groups (skip - not applicable)
     *
     * @param array $status
     * @param int $limit
     * @return array
     */
    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        // Skip to affiliates stage
        $status['current_stage'] = 'affiliates';
        $this->updateCurrentStatus($status, false);

        return $status;
    }

    /**
     * Migrate affiliates from Affiliate Manager
     *
     * @param array $status
     * @param int $limit
     * @return array
     * @throws BindingResolutionException
     */
    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_affiliates', 0);
        $affiliates = $this->db()->table('wpam_affiliates')
            ->orderBy('affiliateId', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        if ($affiliates->isEmpty()) {
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            // Status mapping
            $statusMap = [
                'applied'   => 'pending',
                'declined'  => 'inactive',
                'approved'  => 'active',
                'active'    => 'active',
                'inactive'  => 'inactive',
                'confirmed' => 'active',
                'blocked'   => 'inactive',
            ];

            $status_value = isset($statusMap[$affiliate->status]) ? $statusMap[$affiliate->status] : 'pending';

            // Rate type mapping
            $rateType = 'percentage';
            if ($affiliate->bountyType === 'flat') {
                $rateType = 'flat';
            }

            // Get or create user
            $userId = $affiliate->userId;
            if (!$userId) {
                // Create a WordPress user for standalone affiliate
                $username = 'affiliate_' . $affiliate->affiliateId;
                $email = $affiliate->email ?: $username . '@example.com';

                $existingUser = get_user_by('email', $email);
                if ($existingUser) {
                    $userId = $existingUser->ID;
                } else {
                    $userId = wp_create_user($username, wp_generate_password(), $email);
                }
            }

            $data = [
                'user_id'         => $userId,
                'custom_param'    => $affiliate->uniqueRefKey, // CRITICAL: Preserve tracking key
                'rate'            => $affiliate->bountyAmount ?: 0,
                'rate_type'       => $rateType,
                'payment_email'   => $affiliate->email,
                'status'          => $status_value,
                'total_earnings'  => 0, // Will be recounted later
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0,
                'lead_counts'     => 0,
                'note'            => 'Migrated from Affiliate Manager',
                'settings'        => maybe_serialize([]),
                'created_at'      => $affiliate->dateCreated ?: current_time('mysql'),
                'updated_at'      => (!empty($affiliate->dateModified) ? $affiliate->dateModified : $affiliate->dateCreated) ?: current_time('mysql'),
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        // Insert batch
        $this->db()->table('fa_affiliates')->insert($dataToInsert);

        // Update status
        $status['migrated_affiliates'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        // Check time limit
        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue recursively
        return $this->migrateAffiliates($status, $limit);
    }

    /**
     * Migrate referrals from Affiliate Manager
     *
     * @param array $status
     * @param int $limit
     * @return array
     * @throws BindingResolutionException
     */
    public function migrateReferrals($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_referrals', 0);


        $referrals = $this->db()->table('wpam_transactions')
            ->whereIn('type', ['credit', 'refund', 'adjustment']) // CRITICAL: Include adjustment
            ->orderBy('transactionId', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($referrals->isEmpty()) {
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }


        $dataToInsert = [];

        foreach ($referrals as $referral) {
            // Status mapping (CRITICAL: confirmed -> unpaid, NOT paid)
            $referralStatus = 'unpaid';
            if ($referral->type === 'refund') {
                $referralStatus = 'rejected';
            }

            // Get currency
            $currency = 'USD';
            if (function_exists('get_woocommerce_currency')) {
                $currency = get_woocommerce_currency();
            }

            $data = [
                'id'              => $referral->transactionId, // CRITICAL: Preserve original ID
                'affiliate_id'    => $referral->affiliateId,
                'customer_id'     => null, // Will be linked later
                'visit_id'        => null, // Will be linked later
                'amount'          => abs($referral->amount),
                'order_total'     => 0,
                'status'          => $referralStatus, // All start as unpaid
                'provider'        => 'woo',
                'provider_id'     => $referral->referenceId, // Order/purchase log ID
                'provider_sub_id' => '',
                'payout_id'       => null, // Will be linked later
                'payout_transaction_id' => null,
                'type'            => 'sale',
                'currency'        => $currency,
                'description'     => $referral->description ?: 'Migrated from Affiliate Manager',
                'products'        => maybe_serialize([]),
                'created_at'      => $referral->dateCreated ?: current_time('mysql'),
                'updated_at'      => (!empty($referral->dateModified) ? $referral->dateModified : $referral->dateCreated) ?: current_time('mysql'),
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        // Insert batch
        $this->db()->table('fa_referrals')->insert($dataToInsert);

        // Update status
        $status['migrated_referrals'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        // Check time limit
        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue recursively
        return $this->migrateReferrals($status, $limit);
    }

    /**
     * Migrate customers from Affiliate Manager
     *
     * @param array $status
     * @param int $limit
     * @return array
     * @throws BindingResolutionException
     */
    public function migrateCustomers($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_customers', 0);


        $customers = $this->db()->table('wpam_transactions')
            ->select([
                'email',
                'affiliateId as affiliate_id',
                $this->db()->raw('MIN(dateCreated) as first_referral_date')
            ])
            ->whereIn('type', ['credit', 'refund', 'adjustment'])
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('email', 'affiliateId')
            ->orderBy('first_referral_date', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($customers->isEmpty()) {
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        foreach ($customers as $customer) {
            // Check if WordPress user exists
            $wpUser = get_user_by('email', $customer->email);
            $userId = $wpUser ? $wpUser->ID : null;

            // Extract name from WP user or email
            $firstName = '';
            $lastName = '';

            if ($wpUser) {
                $firstName = $wpUser->first_name ?: '';
                $lastName = $wpUser->last_name ?: '';

                // If no first/last name, try display name
                if (empty($firstName) && empty($lastName)) {
                    $nameParts = explode(' ', $wpUser->display_name, 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName = $nameParts[1] ?? '';
                }
            }

            // Fallback to email username
            if (empty($firstName)) {
                $emailParts = explode('@', $customer->email);
                $firstName = $emailParts[0] ?? '';
            }

            $data = [
                'user_id'         => $userId,
                'by_affiliate_id' => $customer->affiliate_id,
                'email'           => $customer->email,
                'first_name'      => substr($firstName, 0, 192),
                'last_name'       => substr($lastName, 0, 192),
                'ip'              => null,
                'settings'        => maybe_serialize([]),
                'created_at'      => $customer->first_referral_date ?: current_time('mysql'),
                'updated_at'      => $customer->first_referral_date ?: current_time('mysql'),
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        // Insert batch
        $this->db()->table('fa_customers')->insert($dataToInsert);

        // Update status
        $status['migrated_customers'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        // Check time limit
        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue recursively
        return $this->migrateCustomers($status, $limit);
    }

    /**
     * Migrate payouts from Affiliate Manager
     *
     * @param array $status
     * @param int $limit
     * @return array
     * @throws BindingResolutionException
     */
    public function migratePayouts($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_payout_id', 0);


        $transactions = $this->db()->table('wpam_transactions')
            ->where('type', 'payout')
            ->selectRaw('*, ABS(amount) as amount') // Make amount positive
            ->orderBy('transactionId', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($transactions->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $adjustments = [
            'title'         => 'Affiliate payout for the affiliate %s',
            'description'   => 'Affiliate payout migrated from Affiliate Manager for the affiliate %s, total amount %s, date %s',
            'currency'      => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'status'        => 'paid',
            'created_by'    => get_current_user_id(),
            'payout_method' => 'manual',
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ];


        foreach ($transactions as $transaction) {
            // Create payout
            $payoutData = [
                'created_by'    => get_current_user_id(),
                'total_amount'  => $transaction->amount,
                'payout_method' => 'manual',
                'status'        => 'paid',
                'currency'      => $adjustments['currency'],
                'title'         => sprintf('Payout for Affiliate #%s', $transaction->affiliateId),
                'description'   => sprintf('Migrated from Affiliate Manager - Amount: %s, Date: %s',
                    $transaction->amount, $transaction->dateCreated),
                'settings'      => maybe_serialize([]),
                'created_at'    => $transaction->dateCreated ?: current_time('mysql'),
                'updated_at'    => (!empty($transaction->dateModified) ? $transaction->dateModified : $transaction->dateCreated) ?: current_time('mysql'),
            ];

            $payoutId = $this->db()->table('fa_payouts')->insertGetId($payoutData);

            // Create payout transaction
            $transactionData = [
                'payout_id'     => $payoutId,
                'affiliate_id'  => $transaction->affiliateId,
                'total_amount'  => $transaction->amount,
                'payout_method' => 'manual',
                'status'        => 'paid',
                'currency'      => $adjustments['currency'],
                'settings'      => maybe_serialize([]),
                'created_at'    => $transaction->dateCreated ?: current_time('mysql'),
                'updated_at'    => (!empty($transaction->dateModified) ? $transaction->dateModified : $transaction->dateCreated) ?: current_time('mysql'),
            ];

            $this->db()->table('fa_payout_transactions')->insert($transactionData);

            $migratedCount++;
        }

        // Update status
        $status['migrated_payout_id'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        // Check time limit
        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue recursively
        return $this->migratePayouts($status, $limit);
    }

    /**
     * Migrate visits from Affiliate Manager
     *
     * @param array $status
     * @param int $limit
     * @return array
     * @throws BindingResolutionException
     */
    public function migrateVisits($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_visits', 0);

        $visits = $this->db()->table('wpam_tracking_tokens')
            ->orderBy('trackingTokenId', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        // If empty, trigger post-migration tasks
        if ($visits->isEmpty()) {
            $status['current_stage'] = 'completed';

            $status = $this->linkCustomersToReferrals($status);

            // Check time limit after customer linking
            if ($this->isTimeLimitExceeded()) {
                $this->updateCurrentStatus($status, false);
                return $status;
            }

            $status = $this->linkVisitsToReferrals($status);

            // Check time limit after visit linking
            if ($this->isTimeLimitExceeded()) {
                $this->updateCurrentStatus($status, false);
                return $status;
            }

            $status = $this->syncAffiliateData($status);

            // Check time limit after sync
            if ($this->isTimeLimitExceeded()) {
                $this->updateCurrentStatus($status, false);
                return $status;
            }

            $status = $this->recountEarnings($status);

            $this->updateCurrentStatus($status, false);
            return $status;
        }


        $dataToInsert = [];
        $siteUrl = get_bloginfo('url');

        foreach ($visits as $visit) {
            $data = [
                'id'          => $visit->trackingTokenId,
                'affiliate_id' => $visit->sourceAffiliateId,
                'url'         => $siteUrl,
                'referrer'    => $visit->referer ?: '',
                'utm_campaign' => '',
                'utm_medium'  => '',
                'utm_source'  => '',
                'ip'          => $visit->ipAddress ?: '',
                'user_id'     => null,
                'referral_id' => null, // Will be linked later
                'created_at'  => $visit->dateCreated ?: current_time('mysql'),
                'updated_at'  => $visit->dateCreated ?: current_time('mysql'),
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        // Insert batch
        $this->db()->table('fa_visits')->insert($dataToInsert);

        // Update status
        $status['migrated_visits'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        // Check time limit
        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue recursively
        return $this->migrateVisits($status, $limit);
    }

    /**
     * Link customers to their referrals
     *
     * @param array $status
     * @return array
     * @throws BindingResolutionException
     */
    protected function linkCustomersToReferrals($status)
    {
        $customers = Customer::query()->get();
        $linkedCount = 0;

        foreach ($customers as $customer) {

            $transactionIds = $this->db()->table('wpam_transactions')
                ->select('transactionId')
                ->where('email', $customer->email)
                ->where('affiliateId', $customer->by_affiliate_id)
                ->whereIn('type', ['credit', 'refund', 'adjustment'])
                ->pluck('transactionId');

            if (!empty($transactionIds)) {
                $ids = is_array($transactionIds) ? $transactionIds : $transactionIds->toArray();

                // Update referrals (id matches transactionId)
                $updated = $this->db()->table('fa_referrals')
                    ->whereIn('id', $ids)
                    ->where('affiliate_id', $customer->by_affiliate_id)
                    ->whereNull('customer_id')
                    ->update(['customer_id' => $customer->id]);

                $linkedCount += $updated;
            }
        }

        $status['customers_linked'] = $linkedCount;
        return $status;
    }

    /**
     * Link visits to referrals
     *
     * @param array $status
     * @return array
     * @throws BindingResolutionException
     */
    protected function linkVisitsToReferrals($status)
    {
        $linkedCount = 0;

        try {
            $purchaseLogs = $this->db()->table('wpam_tracking_tokens_purchase_logs')->get();

            if (!$purchaseLogs->isEmpty()) {
                foreach ($purchaseLogs as $log) {
                    $updated = $this->db()->table('fa_referrals')
                        ->where('provider_id', $log->purchaseLogId)
                        ->update(['visit_id' => $log->trackingTokenId]);

                    $linkedCount += $updated;
                }

                $status['visits_linked'] = $linkedCount;
                return $status;
            }
        } catch (\Exception $e) {
            // Table might not exist, continue to strategy 2
        }


        $referrals = $this->db()->table('fa_referrals')
            ->whereNull('visit_id')
            ->get();

        foreach ($referrals as $referral) {
            $visit = $this->db()->table('fa_visits')
                ->where('affiliate_id', $referral->affiliate_id)
                ->where('created_at', '<=', $referral->created_at)
                ->whereNull('referral_id')
                ->orderBy('created_at', 'DESC')
                ->first();

            if ($visit) {
                // Two-way linking
                $this->db()->table('fa_referrals')
                    ->where('id', $referral->id)
                    ->update(['visit_id' => $visit->id]);

                $this->db()->table('fa_visits')
                    ->where('id', $visit->id)
                    ->update(['referral_id' => $referral->id]);

                $linkedCount++;
            }
        }

        $status['visits_linked'] = $linkedCount;
        return $status;
    }

    /**
     * Sync affiliate data - link referrals to payouts based on timestamp
     *
     * @param array $status
     * @return array
     * @throws BindingResolutionException
     */
    protected function syncAffiliateData($status)
    {

        $transactions = $this->db()->table('fa_payout_transactions')
            ->where('status', 'paid')
            ->get();

        $linkedCount = 0;

        foreach ($transactions as $transaction) {
            // Get referrals created BEFORE or AT payout date (CRITICAL)
            $referrals = $this->db()->table('fa_referrals')
                ->where('affiliate_id', $transaction->affiliate_id)
                ->where('status', 'unpaid')
                ->where('created_at', '<=', $transaction->created_at) // CRITICAL: Timestamp comparison
                ->whereNull('payout_id')
                ->get();

            if (!$referrals->isEmpty()) {
                $referralIds = [];
                foreach ($referrals as $referral) {
                    $referralIds[] = $referral->id;
                }

                // Link referrals and mark as paid
                $this->db()->table('fa_referrals')
                    ->whereIn('id', $referralIds)
                    ->update([
                        'payout_id' => $transaction->payout_id,
                        'payout_transaction_id' => $transaction->id,
                        'status' => 'paid' // CRITICAL: Mark as paid
                    ]);

                $linkedCount += count($referralIds);
            }
        }

        $status['referrals_linked_to_payouts'] = $linkedCount;
        return $status;
    }

    /**
     * Recount earnings for all affiliates
     *
     * @param array $status
     * @return array
     */
    protected function recountEarnings($status)
    {
        $affiliates = Affiliate::query()->get();

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
        }

        $status['affiliates_recounted'] = $affiliates->count();
        return $status;
    }
}
