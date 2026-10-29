<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Framework\Container\Contracts\BindingResolutionException;
use FluentAffiliate\Framework\Support\Arr;
class AffiliateManagerMigrator extends BaseMigrator
{
    // Opens the description of every payout this migrator creates, which is how
    // they are told apart from payouts that were already in the table.
    const PAYOUT_MARKER = 'Migrated from Affiliate Manager';

    public function __construct()
    {
        $this->migratorPrefix = 'wpam';
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

        $lastId = (int) Arr::get($status, 'migrated_affiliates', 0);


        $affiliates = $this->db()->table('wpam_affiliates')
            ->where('affiliateId', '>', $lastId)
            ->orderBy('affiliateId', 'ASC')
            ->limit($limit)
            ->get();

        if ($affiliates->isEmpty()) {
            $this->resetAutoIncrement('fa_affiliates');
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $lastId = $affiliate->affiliateId;
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

            // Affiliate Manager stores 'fixed' or 'percent'; without a bounty the
            // affiliate follows the site rate, which is our 'default'
            $rateTypeMap = ['fixed' => 'flat', 'percent' => 'percentage'];
            $rateType = Arr::get($rateTypeMap, (string) $affiliate->bountyType, 'default');

            // Affiliate Manager sets userId only when it approves an applicant, so a
            // row without one is an applicant: no account is created for it, and none
            // is bound by an unverified email or a guessable login
            $userId = (int) $affiliate->userId ?: null;

            $data = [
                'id'              => $affiliate->affiliateId,
                'user_id'         => $userId,
                '__source_id'     => $affiliate->affiliateId,
                'custom_param'    => $affiliate->uniqueRefKey, // CRITICAL: Preserve tracking key
                'rate'            => $rateType === 'default' ? null : ($affiliate->bountyAmount ?: 0),
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
        }

        $this->insertAffiliates($dataToInsert);

        // Update status
        $status['migrated_affiliates'] = $lastId;
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

        $lastId = (int) Arr::get($status, 'migrated_referrals', 0);


        // A refund is a second row reversing a whole credit, so it is applied to that
        // credit once all credits are in. Affiliate Manager never counts failed rows.
        $referrals = $this->db()->table('wpam_transactions')
            ->whereIn('type', ['credit', 'adjustment'])
            ->where('status', '!=', 'failed')
            ->where('transactionId', '>', $lastId)
            ->orderBy('transactionId', 'ASC')
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($referrals->isEmpty()) {
            if (!$this->rejectRefundedCredits($status)) {
                return $status;
            }

            $this->resetAutoIncrement('fa_referrals');
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // The source transaction id becomes the referral id so the later
        // linking stages can match the two, so anything already occupying that
        // id has to be left alone rather than collided with.
        $existingIds = array_flip($this->db()->table('fa_referrals')
            ->whereIn('id', $referrals->pluck('transactionId')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($referrals as $referral) {
            $lastId = $referral->transactionId;

            if (isset($existingIds[$referral->transactionId])) {
                continue;
            }

            // confirmed is owed but not paid yet; payouts settle it in syncAffiliateData()
            $referralStatus = $referral->status === 'pending' ? 'pending' : 'unpaid';

            // Get currency
            $currency = 'USD';
            if (function_exists('get_woocommerce_currency')) {
                $currency = get_woocommerce_currency();
            }

            $data = [
                'id'              => $referral->transactionId, // CRITICAL: Preserve original ID
                'affiliate_id'    => $this->mapAffiliateId($referral->affiliateId),
                'customer_id'     => null, // Will be linked later
                'visit_id'        => null, // Will be linked later
                // a negative adjustment is a deduction, so it keeps its sign
                'amount'          => $referral->type === 'adjustment' ? $referral->amount : abs($referral->amount),
                'order_total'     => 0,
                'status'          => $referralStatus, // All start as unpaid
                'provider'        => 'woo',
                'provider_id'     => $referral->referenceId, // Order/purchase log ID
                'provider_sub_id' => '',
                'payout_id'       => null, // Will be linked later
                'payout_transaction_id' => null,
                'type'            => 'sale',
                'currency'        => $currency,
                'description'     => $referral->description ? sanitize_text_field($referral->description) : 'Migrated from Affiliate Manager',
                'products'        => maybe_serialize([]),
                'created_at'      => $referral->dateCreated ?: current_time('mysql'),
                'updated_at'      => (!empty($referral->dateModified) ? $referral->dateModified : $referral->dateCreated) ?: current_time('mysql'),
            ];

            $dataToInsert[] = $data;
        }

        // Insert batch
        if (!empty($dataToInsert)) {
            $this->db()->table('fa_referrals')->insert($dataToInsert);
        }

        // Update status
        $status['migrated_referrals'] = $lastId;
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
            // Offset paging needs a total order; first_referral_date ties would
            // otherwise let rows shift between batches and be skipped.
            ->orderBy('email', 'ASC')
            ->orderBy('affiliateId', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($customers->isEmpty()) {
            $status = $this->linkCustomersToReferrals($status);

            if ($this->isTimeLimitExceeded()) {
                $this->updateCurrentStatus($status, false);
                return $status;
            }

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
                'by_affiliate_id' => $this->mapAffiliateId($customer->affiliate_id),
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

        $lastId = (int) Arr::get($status, 'migrated_payout_id', 0);


        // a failed payout never sent the money, so its referrals stay owed
        $transactions = $this->db()->table('wpam_transactions')
            ->where('type', 'payout')
            ->where('status', '!=', 'failed')
            ->where('transactionId', '>', $lastId)
            ->selectRaw('*, ABS(amount) as amount') // Make amount positive
            ->orderBy('transactionId', 'ASC')
            ->limit($limit)
            ->get();

        // If empty, move to next stage
        if ($transactions->isEmpty()) {
            if (!$this->syncAffiliateData($status)) {
                return $status;
            }

            // Every pass into the visits stage links again, so rows that reached
            // the source after an earlier run are linked too.
            fluentAffiliate_update_option('wpam_visits_linked', 0);

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
            // a pending PayPal Mass Pay payout is still on its way
            $payoutStatus = $transaction->status === 'pending' ? 'processing' : 'paid';

            // Create payout
            $payoutData = [
                'created_by'    => get_current_user_id(),
                'total_amount'  => $transaction->amount,
                'payout_method' => 'manual',
                'status'        => $payoutStatus,
                'currency'      => $adjustments['currency'],
                'title'         => sprintf('Payout for Affiliate #%s', $transaction->affiliateId),
                'description'   => sprintf(self::PAYOUT_MARKER . ' - Amount: %s, Date: %s',
                    $transaction->amount, $transaction->dateCreated),
                'settings'      => maybe_serialize([]),
                'created_at'    => $transaction->dateCreated ?: current_time('mysql'),
                'updated_at'    => (!empty($transaction->dateModified) ? $transaction->dateModified : $transaction->dateCreated) ?: current_time('mysql'),
            ];

            $payoutId = $this->db()->table('fa_payouts')->insertGetId($payoutData);

            // Create payout transaction
            $transactionData = [
                'payout_id'     => $payoutId,
                'affiliate_id'  => $this->mapAffiliateId($transaction->affiliateId),
                'total_amount'  => $transaction->amount,
                'payout_method' => 'manual',
                'status'        => $payoutStatus,
                'currency'      => $adjustments['currency'],
                'settings'      => maybe_serialize([]),
                'created_at'    => $transaction->dateCreated ?: current_time('mysql'),
                'updated_at'    => (!empty($transaction->dateModified) ? $transaction->dateModified : $transaction->dateCreated) ?: current_time('mysql'),
            ];

            $this->db()->table('fa_payout_transactions')->insert($transactionData);

            $lastId = $transaction->transactionId;
        }

        // Update status
        $status['migrated_payout_id'] = $lastId;
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

        $lastId = (int) Arr::get($status, 'migrated_visits', 0);

        if ($lastId === 0) {
            fluentAffiliate_update_option('wpam_skipped_visits', []);
        }

        $visits = $this->db()->table('wpam_tracking_tokens')
            ->where('trackingTokenId', '>', $lastId)
            ->orderBy('trackingTokenId', 'ASC')
            ->limit($limit)
            ->get();

        // If empty, trigger post-migration tasks
        if ($visits->isEmpty()) {
            $this->resetAutoIncrement('fa_visits');

            // The recount below needs several requests on a large site and
            // re-enters this branch each time, so the linking runs once.
            if (!fluentAffiliate_get_option('wpam_visits_linked', 0)) {
                $status = $this->linkVisitsToReferrals($status);
                fluentAffiliate_update_option('wpam_visits_linked', 1);

                if ($this->isTimeLimitExceeded()) {
                    $this->updateCurrentStatus($status, false);
                    return $status;
                }
            }

            // Stay in the 'visits' stage until the paginated recount fully completes;
            // advancing early would leave affiliates past the recount cursor at zero earnings.
            if ($this->recountEarnings()) {
                $status['current_stage'] = 'creatives';
            }

            $this->updateCurrentStatus($status, false);
            return $status;
        }


        // The tracking token id becomes the visit id, so an id already in use
        // belongs to a different visit and must not be overwritten.
        $existingIds = array_flip($this->db()->table('fa_visits')
            ->whereIn('id', $visits->pluck('trackingTokenId')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];
        $siteUrl = get_bloginfo('url');
        $skippedIds = (array) fluentAffiliate_get_option('wpam_skipped_visits', []);

        foreach ($visits as $visit) {
            $lastId = $visit->trackingTokenId;

            if (isset($existingIds[$visit->trackingTokenId])) {
                $skippedIds[(int) $visit->trackingTokenId] = 1;
                continue;
            }

            $data = [
                'id'          => $visit->trackingTokenId,
                'affiliate_id' => $this->mapAffiliateId($visit->sourceAffiliateId),
                'url'         => $siteUrl,
                'referrer'    => $visit->referer ? sanitize_url($visit->referer) : '',
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
        }

        // Insert batch
        if (!empty($dataToInsert)) {
            $this->db()->table('fa_visits')->insert($dataToInsert);
        }

        fluentAffiliate_update_option('wpam_skipped_visits', $skippedIds);

        // Update status
        $status['migrated_visits'] = $lastId;
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
            if (!$customer->by_affiliate_id) {
                continue;
            }

            // by_affiliate_id is the mapped FA id; the source table needs the
            // original one to find the matching transactions.
            $sourceAffiliateId = array_search((int) $customer->by_affiliate_id, $this->affiliateIdMap(), true);
            $sourceAffiliateId = ($sourceAffiliateId === false) ? $customer->by_affiliate_id : $sourceAffiliateId;

            $transactionIds = $this->db()->table('wpam_transactions')
                ->select('transactionId')
                ->where('email', $customer->email)
                ->where('affiliateId', $sourceAffiliateId)
                ->whereIn('type', ['credit', 'refund', 'adjustment'])
                ->pluck('transactionId');

            if (!empty($transactionIds)) {
                $ids = is_array($transactionIds) ? $transactionIds : $transactionIds->toArray();

                // Update referrals (id matches transactionId)
                $updated = $this->db()->table('fa_referrals')
                    ->whereIn('id', $ids)
                    ->where('affiliate_id', $customer->by_affiliate_id)  // already mapped at import
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
                // A row under that id is not proof this migration created it:
                // the visits stage skips a tracking token whose id was already
                // taken, and that row belongs to an unrelated visit.
                $skippedIds = (array) fluentAffiliate_get_option('wpam_skipped_visits', []);

                $migratedVisitIds = array_flip($this->db()->table('fa_visits')
                    ->whereIn('id', $purchaseLogs->pluck('trackingTokenId')->toArray())
                    ->pluck('id')
                    ->toArray()
                );

                foreach ($purchaseLogs as $log) {
                    if (!isset($migratedVisitIds[$log->trackingTokenId]) || isset($skippedIds[(int) $log->trackingTokenId])) {
                        continue;
                    }

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
     * Migrate creatives from wpam_creatives → fa_creatives.
     * Only runs if FA Pro creatives table exists.
     */
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

        $creatives = $db->table('wpam_creatives')
            ->where('creativeId', '>', $lastId)
            ->orderBy('creativeId', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($creatives->isEmpty()) {
            $status['current_stage'] = 'completed';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingNames = array_flip($db->table('fa_creatives')
            ->whereIn('name', $creatives->pluck('name')->toArray())
            ->pluck('name')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($creatives as $creative) {
            $lastId = $creative->creativeId;

            // Affiliate Manager keeps deleted creatives in the table behind a
            // status flag rather than removing the row.
            if ($creative->status === 'deleted' || isset($existingNames[$creative->name])) {
                continue;
            }

            $isImage = $creative->type === 'image';

            $image = $isImage ? $this->getCreativeImageUrl($creative) : null;
            // FA reuses the text column for the alt text of an image creative.
            $text = $isImage ? ($creative->altText ?: null) : ($creative->linkText ?: null);

            // A creative with nothing to show cannot be rendered in the portal
            // and would fail validation on its first edit, so it is left behind
            // rather than imported broken.
            if ($isImage ? !$image : !$text) {
                continue;
            }

            $createdAt = $creative->dateCreated ?: current_time('mysql');

            $dataToInsert[] = [
                'name'        => $creative->name,
                'type'        => $isImage ? 'image' : 'text',
                'image'       => $image,
                'text'        => $text,
                'url'         => $this->getCreativeUrl($creative),
                'privacy'     => 'public',
                'status'      => $creative->status ?: 'active',
                'meta'        => (!$isImage && $creative->altText) ? maybe_serialize(['alt_text' => $creative->altText]) : null,
                'created_at'  => $createdAt,
                'updated_at'  => $createdAt,
            ];
        }

        $this->insertBatch('fa_creatives', $dataToInsert);

        $status['migrated_creatives'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCreatives($status, $limit);
    }

    /**
     * Affiliate Manager stores the image URL directly on newer creatives and
     * an attachment id on ones created before that change.
     */
    protected function getCreativeImageUrl($creative)
    {
        if (!empty($creative->image)) {
            return esc_url_raw($creative->image) ?: null;
        }

        if (empty($creative->imagePostId)) {
            return null;
        }

        return wp_get_attachment_url($creative->imagePostId) ?: null;
    }

    /**
     * A creative's destination is the affiliate landing page (or the site root
     * when none is configured) with the creative slug appended, matching
     * WPAM_Tracking_TrackingLinkBuilder::getUrl(). The affiliate reference is
     * appended by FluentAffiliate at render time, so it is left off here.
     */
    protected function getCreativeUrl($creative)
    {
        $slug = trim((string) $creative->slug);

        $landingPage = get_option('wpam_landing_page');

        if ($landingPage) {
            return esc_url_raw(trailingslashit($landingPage) . $slug) ?: null;
        }

        return esc_url_raw(home_url('/' . $slug)) ?: null;
    }

    /**
     * Applicants are imported without a user, but earlier releases bound them to
     * one. The base check compares users whenever either side has one, so a
     * re-run would import those earlier copies a second time; match them by the
     * payment email instead.
     */
    protected function isSameAffiliate($existing, $row, $recorded = false)
    {
        $existingEmail = (string) $existing->payment_email;

        if (empty($row['user_id']) && (int) $existing->user_id && $existingEmail !== '') {
            return $existingEmail === (string) ($row['payment_email'] ?? '');
        }

        return parent::isSameAffiliate($existing, $row, $recorded);
    }

    protected function getSourceCounts()
    {
        $db = $this->db();

        $referralTypes = ['credit', 'refund', 'adjustment'];

        return [
            // Affiliate Manager has no groups feature, so this stays at 0.
            'affiliate_groups' => 0,
            'affiliates'       => (int) $db->table('wpam_affiliates')->count(),
            // refunds reject their credit instead of becoming rows, and failed rows are skipped
            'referrals'        => (int) $db->table('wpam_transactions')
                ->whereIn('type', ['credit', 'adjustment'])
                ->where('status', '!=', 'failed')
                ->count(),
            'customers'        => (int) $db->table('wpam_transactions')
                ->selectRaw("COUNT(DISTINCT CONCAT(email, '-', affiliateId)) as count")
                ->whereIn('type', $referralTypes)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->value('count'),
            'payouts'          => (int) $db->table('wpam_transactions')
                ->where('type', 'payout')
                ->where('status', '!=', 'failed')
                ->count(),
            'visits'           => (int) $db->table('wpam_tracking_tokens')->count(),
            // Deleted creatives are not migrated, so they are not counted either.
            'creatives'        => (int) $db->table('wpam_creatives')
                ->where('status', '!=', 'deleted')
                ->count(),
        ];
    }

    /**
     * Links the imported payouts to the referrals they paid, in batches. The
     * imported payouts are all written by now, so the ordered list is fixed and
     * an offset into it can resume after the time limit.
     *
     * @param array $status
     * @return bool true when every payout is linked, false when it stopped on the time limit
     * @throws BindingResolutionException
     */
    protected function syncAffiliateData(&$status)
    {
        $offset = (int) Arr::get($status, 'synced_payouts', 0);

        while (true) {
            // Payouts that were already here belong to affiliates this migration
            // knows nothing about; settling their referrals is not its call.
            // oldest payout first, so each one settles the oldest referrals still owed
            $transactions = $this->db()->table('fa_payout_transactions')
                ->join('fa_payouts', 'fa_payouts.id', '=', 'fa_payout_transactions.payout_id')
                ->where('fa_payouts.description', 'LIKE', self::PAYOUT_MARKER . '%')
                ->whereIn('fa_payout_transactions.status', ['paid', 'processing'])
                ->select(['fa_payout_transactions.*'])
                ->orderBy('fa_payout_transactions.created_at', 'ASC')
                ->orderBy('fa_payout_transactions.id', 'ASC')
                ->offset($offset)
                ->limit(100)
                ->get();

            if ($transactions->isEmpty()) {
                return true;
            }

            $status['referrals_linked_to_payouts'] = (int) Arr::get($status, 'referrals_linked_to_payouts', 0)
                + $this->linkPayoutBatch($transactions);

            $offset += count($transactions);
            $status['synced_payouts'] = $offset;
            $this->updateCurrentStatus($status, false);

            if ($this->isTimeLimitExceeded()) {
                return false;
            }
        }
    }

    /**
     * @param iterable $transactions imported payout transactions, oldest first
     * @return int referrals linked
     */
    protected function linkPayoutBatch($transactions)
    {
        $linkedCount = 0;

        foreach ($transactions as $transaction) {
            // this runs again when a pass hits the time limit; an allocated
            // payout must not take a second share
            $alreadyLinked = $this->db()->table('fa_referrals')
                ->where('payout_transaction_id', $transaction->id)
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            $referrals = $this->db()->table('fa_referrals')
                ->where('affiliate_id', $transaction->affiliate_id)
                ->where('status', 'unpaid')
                ->where('created_at', '<=', $transaction->created_at)
                ->whereNull('payout_id')
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->get(['id', 'amount']);

            $referralIds = $this->allocateToPayout($referrals, $transaction->total_amount);

            if (!$referralIds) {
                continue;
            }

            $link = [
                'payout_id'             => $transaction->payout_id,
                'payout_transaction_id' => $transaction->id,
            ];

            // a processing payout keeps its referrals unpaid; marking it paid
            // later settles them (Transaction::settle())
            if ($transaction->status === 'paid') {
                $link['status'] = 'paid';
            }

            $this->db()->table('fa_referrals')
                ->whereIn('id', $referralIds)
                ->update($link);

            $linkedCount += count($referralIds);
        }

        return $linkedCount;
    }

    /**
     * Affiliate Manager pays a date range, so a payout covers the oldest referrals
     * owed at that time. Takes the longest oldest-first run whose net total fits
     * the amount, so a later deduction can offset an earlier credit, and leaves
     * the rest owed.
     *
     * @param iterable $referrals rows with id and amount, oldest first
     * @param float $amount
     * @return int[]
     */
    protected function allocateToPayout($referrals, $amount)
    {
        $referralIds = [];
        $runningTotal = 0.0;
        $covered = 0;

        foreach ($referrals as $referral) {
            $runningTotal += (float) $referral->amount;
            $referralIds[] = (int) $referral->id;

            // half a cent of slack absorbs float residue from decimal sums
            if ($runningTotal <= (float) $amount + 0.005) {
                $covered = count($referralIds);
            }
        }

        return array_slice($referralIds, 0, $covered);
    }

    /**
     * Affiliate Manager refunds a commission with a second, negative row for the
     * same purchase, so the credit it reverses is rejected instead of imported as
     * owed. The match is the affiliate plus the purchase, which is the credit
     * this import wrote for that sale. Runs in batches that resume from the last
     * refund applied.
     *
     * @param array $status
     * @return bool true when every refund is applied, false when it stopped on the time limit
     */
    protected function rejectRefundedCredits(&$status)
    {
        $lastId = (int) Arr::get($status, 'rejected_refund_id', 0);

        while (true) {
            $refunds = $this->db()->table('wpam_transactions')
                ->where('type', 'refund')
                ->where('status', '!=', 'failed')
                ->where('transactionId', '>', $lastId)
                ->orderBy('transactionId', 'ASC')
                ->limit(500)
                ->get(['transactionId', 'affiliateId', 'referenceId']);

            if ($refunds->isEmpty()) {
                return true;
            }

            foreach ($refunds as $refund) {
                $lastId = $refund->transactionId;
                $affiliateId = $this->mapAffiliateId($refund->affiliateId);

                if (!$affiliateId || !$refund->referenceId) {
                    continue;
                }

                $this->db()->table('fa_referrals')
                    ->where('provider', 'woo')
                    ->where('provider_id', $refund->referenceId)
                    ->where('affiliate_id', $affiliateId)
                    ->whereIn('status', ['pending', 'unpaid'])
                    ->update(['status' => 'rejected']);
            }

            $status['rejected_refund_id'] = $lastId;
            $this->updateCurrentStatus($status, false);

            if ($this->isTimeLimitExceeded()) {
                return false;
            }
        }
    }

    /**
     * Recount affiliate earnings in paginated batches so very large affiliate
     * counts cannot exceed the request time limit and stall the migration.
     * Returns true when every affiliate has been recounted, false when it
     * stopped early on the time limit (the caller must re-invoke to resume).
     *
     * @return bool
     */
    protected function recountEarnings()
    {
        $lastId = (int) fluentAffiliate_get_option('wpam_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            fluentAffiliate_update_option('wpam_migrated_recount', 0);
            return true;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
        }

        fluentAffiliate_update_option('wpam_migrated_recount', $lastId);

        if ($this->isTimeLimitExceeded()) {
            return false;
        }

        return $this->recountEarnings();
    }
}
