<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Customer;

class SolidAffiliate extends BaseMigrator
{
    private $groupIdMap = null;

    public function __construct()
    {
        $this->migratorPrefix = 'solid_affiliate';
    }

    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliates', 0);

        // SA status → FA status
        $affiliateStatusMap = [
            'approved' => 'active',
            'pending'  => 'pending',
            'rejected' => 'inactive',
        ];

        // SA commission_type → FA rate_type
        $rateTypeMap = [
            'site_default' => 'default',
            'percentage'   => 'percentage',
            'flat'         => 'flat',
        ];

        // Query solid_affiliate_affiliates table
        $affiliates = $this->db()
            ->table('solid_affiliate_affiliates')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status);
            return $status;
        }

        $groupIdMap = $this->getGroupIdMap();

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $lastId = $affiliate->id;

            $groupId = isset($groupIdMap[$affiliate->affiliate_group_id]) ? $groupIdMap[$affiliate->affiliate_group_id] : null;

            $rateType = isset($rateTypeMap[$affiliate->commission_type]) ? $rateTypeMap[$affiliate->commission_type] : 'default';

            // Solid resolves a rate through affiliate → group → site, so an affiliate
            // left on the site default still earns its group's rate. Fluent only reads
            // the group when the rate type is `group`, so point it there whenever the
            // affiliate belongs to one. A group that is itself on `default` falls back
            // to the site rate, which is what Solid does too.
            if ($rateType == 'default' && $groupId) {
                $rateType = 'group';
            }

            $data = [
                'id'              => $affiliate->id,
                'user_id'         => $affiliate->user_id,
                'group_id'        => $groupId,
                'rate'            => $affiliate->commission_rate ?: null,
                'rate_type'       => $rateType,
                'payment_email'   => $affiliate->payment_email ?: null,
                'note'            => $affiliate->registration_notes ?: null,
                'status'          => isset($affiliateStatusMap[$affiliate->status]) ? $affiliateStatusMap[$affiliate->status] : 'active',
                'custom_param'    => $affiliate->custom_registration_data ?: null,
                'total_earnings'  => 0,
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0,
                'created_at'      => $affiliate->created_at,
                'updated_at'      => $affiliate->updated_at,
            ];
            $dataToInsert[] = $data;
        }
        
        try {
            $this->db()->table('fa_affiliates')->insert($dataToInsert);
        } catch (\Exception $e) {
            // Skip this batch to avoid infinite retry loop
        }

        $status['migrated_affiliates'] = $lastId;
        $this->updateCurrentStatus($status);

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

        // SA referral status → FA referral status
        $referralStatusMap = [
            'unpaid'   => 'unpaid',
            'paid'     => 'paid',
            'rejected' => 'rejected',
            'draft'    => 'pending',
        ];

        // SA referral_type → FA type. Fluent has no event type, so Solid's event
        // referrals land as sales, which is what they are paid out as either way.
        $referralTypeMap = [
            'purchase'             => 'sale',
            'subscription_renewal' => 'recurring_sale',
            'auto_referral'        => 'sale',
            'event'                => 'sale',
        ];

        // Query solid_affiliate_referrals table
        $referrals = $this->db()->table('solid_affiliate_referrals')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($referrals->isEmpty()) {
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status);
            return $status;
        }

        // Parse every commission blob up front so the titles behind them can be
        // read in one query instead of one per distinct product.
        $itemCommissions = [];
        $productIds = [];

        foreach ($referrals as $referral) {
            $items = $this->parseItemCommissions($referral->serialized_item_commissions);

            $itemCommissions[$referral->id] = $items;

            foreach ($items as $item) {
                $productId = (int) Arr::get($item, 'product_id');

                if ($productId) {
                    $productIds[$productId] = $productId;
                }
            }
        }

        $productTitles = $this->getProductTitles($productIds);

        $referralToInsert = [];

        foreach ($referrals as $referral) {
            $lastId = $referral->id;

            $data = [
                'id'            => $referral->id,
                'affiliate_id'  => $referral->affiliate_id,
                'customer_id'   => $referral->customer_id ?: null,
                'visit_id'      => $referral->visit_id ?: null,
                'description'   => $referral->description ?: null,
                'amount'        => $referral->commission_amount,
                'order_total'   => $referral->order_amount,
                'currency'      => null,
                'provider'      => 'woo',
                'provider_id'   => $referral->order_id ?: null,
                'products'      => $this->formatReferralProducts($itemCommissions[$referral->id], $productTitles),
                'type'          => isset($referralTypeMap[$referral->referral_type]) ? $referralTypeMap[$referral->referral_type] : 'sale',
                'status'        => isset($referralStatusMap[$referral->status]) ? $referralStatusMap[$referral->status] : 'pending',
                'created_at'    => $referral->created_at,
                'updated_at'    => $referral->updated_at,
            ];
            $referralToInsert[] = $data;
        }

        try {
            $this->db()->table('fa_referrals')->insert($referralToInsert);
        } catch (\Exception $e) {
            // Skip this batch to avoid infinite retry loop
        }

        $status['migrated_referrals'] = $lastId;
        $this->updateCurrentStatus($status);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateReferrals($status);
    }

    /**
     * Solid stores its per-item commission breakdown as serialized ItemCommission
     * objects, so reading the blob has to drop those classes.
     *
     * @param string|null $serializedItemCommissions
     * @return array Item rows as plain arrays, empty when there is nothing usable.
     */
    private function parseItemCommissions($serializedItemCommissions)
    {
        if (!$serializedItemCommissions) {
            return [];
        }

        // Drops the Solid classes rather than instantiating them, so this leaves
        // incomplete objects behind and never runs their constructors.
        $itemCommissions = Utility::safeUnserialize($serializedItemCommissions);

        if (!is_array($itemCommissions)) {
            return [];
        }

        // Casting reaches the properties whether an entry unserialized into an
        // incomplete object or was already an array.
        return array_map(function ($itemCommission) {
            return (array) $itemCommission;
        }, $itemCommissions);
    }

    /**
     * @param array $productIds
     * @return array Product id → title.
     */
    private function getProductTitles($productIds)
    {
        if (!$productIds) {
            return [];
        }

        $posts = $this->db()->table('posts')
            ->select(['ID', 'post_title'])
            ->whereIn('ID', array_values($productIds))
            ->get()
        ;

        $titles = [];

        foreach ($posts as $post) {
            $titles[(int) $post->ID] = $post->post_title;
        }

        return $titles;
    }

    /**
     * Copying Solid's blob across leaves it unreadable, because Fluent reads the
     * column with class loading disabled and gets back __PHP_Incomplete_Class.
     * Rebuild it as the plain rows the referral view reads.
     *
     * @param array $items
     * @param array $productTitles Product id → title, resolved for the whole batch.
     * @return string|null Serialized product rows, or null when there is nothing usable.
     */
    private function formatReferralProducts($items, $productTitles)
    {
        $products = [];

        foreach ($items as $item) {
            $productId = (int) Arr::get($item, 'product_id');
            $amount    = (float) Arr::get($item, 'purchase_amount', 0);

            $title = isset($productTitles[$productId]) ? $productTitles[$productId] : '';

            $products[] = [
                'item_id'  => $productId,
                // A product deleted since the sale keeps its id as the label.
                'title'    => $title ?: ($productId ? '#' . $productId : ''),
                'quantity' => (int) Arr::get($item, 'quantity', 1),
                'subtotal' => $amount,
                // Solid tracks tax as an order-level exclusion, not per item.
                'tax'      => 0,
                'total'    => $amount,
            ];
        }

        if (!$products) {
            return null;
        }

        // The referrals batch is written through the query builder rather than the
        // model, so the serializing mutator on the column never runs.
        return \maybe_serialize($products);
    }

    public function migrateCustomers($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_customers', 0);

        $totalLinks = $this->db()->table('solid_affiliate_affiliate_customer_links')->count();

        // Phase 1: offset < totalLinks → migrate from links table
        // Phase 2: offset >= totalLinks → migrate from referrals table
        if ($migratedCount < $totalLinks) {
            return $this->migrateCustomersFromLinks($status, $migratedCount, $limit, $totalLinks);
        }

        $referralOffset = $migratedCount - $totalLinks;
        return $this->migrateCustomersFromReferrals($status, $migratedCount, $referralOffset, $limit);
    }

    private function migrateCustomersFromLinks($status, $migratedCount, $limit, $totalLinks)
    {
        $links = $this->db()->table('solid_affiliate_affiliate_customer_links')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($links->isEmpty()) {
            // Links phase done, continue to referrals
            $status['migrated_customers'] = $migratedCount;
            $this->updateCurrentStatus($status, false);
            return $this->migrateCustomersFromReferrals($status, $migratedCount, 0, $limit);
        }

        $dataToInsert = [];

        // Collect emails and user_ids to check for existing customers
        $emails = [];
        $userIds = [];
        foreach ($links as $link) {
            if ($link->customer_id > 0) {
                $userIds[] = $link->customer_id;
            }
            if (!empty($link->customer_email)) {
                $emails[] = $link->customer_email;
            }
        }

        $existingByUserId = !empty($userIds)
            ? Customer::whereIn('user_id', $userIds)->pluck('user_id')->toArray()
            : [];

        $existingByEmail = !empty($emails)
            ? Customer::whereIn('email', $emails)->pluck('email')->toArray()
            : [];

        foreach ($links as $link) {
            $migratedCount++;

            $email = $link->customer_email ?: null;
            $userId = ($link->customer_id > 0) ? $link->customer_id : null;
            $firstName = null;
            $lastName = null;

            // Skip if already exists
            if ($userId && in_array($userId, $existingByUserId)) {
                continue;
            }
            if ($email && in_array($email, $existingByEmail)) {
                continue;
            }

            // Enrich from WP user if available
            if ($userId) {
                $user = get_userdata($userId);
                if ($user) {
                    $email = $email ?: $user->user_email;
                    $firstName = get_user_meta($userId, 'first_name', true) ?: null;
                    $lastName = get_user_meta($userId, 'last_name', true) ?: null;
                }
            }

            if (!$email && !$userId) {
                continue;
            }

            $customerData = [
                'user_id'         => $userId,
                'by_affiliate_id' => $link->affiliate_id,
                'email'           => $email,
                'first_name'      => $firstName,
                'last_name'       => $lastName,
                'created_at'      => $link->created_at ?? null,
                'updated_at'      => $link->updated_at ?? null,
            ];

            $dataToInsert[] = $customerData;
        }

        if (!empty($dataToInsert)) {
            try {
                Customer::insert($dataToInsert);
            } catch (\Exception $e) {
                // Skip this batch to avoid infinite retry loop
            }
        }

        $status['migrated_customers'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCustomersFromLinks($status, $migratedCount, $limit, $totalLinks);
    }

    private function migrateCustomersFromReferrals($status, $migratedCount, $referralOffset, $limit)
    {
        // Get unique customers from referrals not already imported
        $rows = $this->db()->table('solid_affiliate_referrals')
            ->select(['customer_id', $this->db()->raw('MIN(affiliate_id) as affiliate_id')])
            ->where('customer_id', '>', 0)
            ->groupBy('customer_id')
            ->orderBy('customer_id', 'ASC')
            ->offset($referralOffset)
            ->limit($limit)
            ->get()
        ;

        if ($rows->isEmpty()) {
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $userIds = $rows->pluck('customer_id')->toArray();

        // Skip users already imported (from links phase or previous runs)
        $existingUserIds = Customer::whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->toArray()
        ;

        // Build affiliate_id lookup from the grouped query
        $affiliateMap = [];
        foreach ($rows as $row) {
            $affiliateMap[$row->customer_id] = $row->affiliate_id;
        }

        $dataToInsert = [];

        foreach ($userIds as $userId) {
            $migratedCount++;

            if (in_array($userId, $existingUserIds)) {
                continue;
            }

            $user = get_userdata($userId);
            if (!$user) {
                continue;
            }

            $customerData = [
                'user_id'         => $user->ID,
                'by_affiliate_id' => $affiliateMap[$userId] ?? null,
                'email'           => $user->user_email,
                'first_name'      => get_user_meta($user->ID, 'first_name', true) ?: null,
                'last_name'       => get_user_meta($user->ID, 'last_name', true) ?: null,
                'created_at'      => $user->user_registered,
                'updated_at'      => null,
            ];

            $dataToInsert[] = $customerData;
        }

        if (!empty($dataToInsert)) {
            try {
                Customer::insert($dataToInsert);
            } catch (\Exception $e) {
                // Skip this batch to avoid infinite retry loop
            }
        }

        $status['migrated_customers'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        $referralOffset += count($rows);
        return $this->migrateCustomersFromReferrals($status, $migratedCount, $referralOffset, $limit);
    }

    public function migratePayouts($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_payout_id', 0);

        // SA bulk payout status → FA payout status
        $payoutStatusMap = [
            'success'    => 'paid',
            'processing' => 'processing',
            'fail'       => 'draft',
        ];

        // SA payout method → FA payout method
        $payoutMethodMap = [
            'csv'          => 'manual',
            'paypal'       => 'paypal',
            'store_credit' => 'manual',
        ];

        // Query solid_affiliates_bulk_payouts table
        $payoutGroups = $this->db()->table('solid_affiliates_bulk_payouts')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($payoutGroups->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status);
            return $status;
        }

        $db = $this->db();

        // Get existing payout IDs to avoid duplicates
        $existingPayoutIds = $db->table('fa_payouts')
            ->whereIn('id', $payoutGroups->pluck('id')->toArray())
            ->pluck('id')
            ->toArray()
        ;

        foreach ($payoutGroups as $payout) {
            $lastId = $payout->id;

            // Skip if payout already exists in fa_payouts
            if (in_array($payout->id, $existingPayoutIds)) {
                continue;
            }

            // Pull all transactions associated with this payout
            $transactions = $db->table('solid_affiliate_payouts')
                ->where('bulk_payout_id', $payout->id)
                ->get()
            ;

            if ($transactions->isEmpty()) {
                continue;
            }

            $totalPayoutAmount = 0;
            foreach ($transactions as $transaction) {
                $totalPayoutAmount += $transaction->amount;
            }

            $formattedPayout = [
                'currency'      => $payout->currency ?: null,
                'payout_method' => isset($payoutMethodMap[$payout->method]) ? $payoutMethodMap[$payout->method] : 'manual',
                'total_amount'  => $totalPayoutAmount,
                'status'        => isset($payoutStatusMap[$payout->status]) ? $payoutStatusMap[$payout->status] : 'draft',
                'created_by'    => $payout->created_by_user_id ?: null,
                'title'         => sprintf('Payouts from %s to %s', $payout->date_range_start, $payout->date_range_end),
                'description'   => sprintf('Migrated Payouts for date range %s to %s from Solid Affiliate', $payout->date_range_start, $payout->date_range_end),
                'created_at'    => $payout->created_at ?? null,
                'updated_at'    => $payout->updated_at ?? null,
            ];

            try {
                // Store the payout
                $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

                // Process each transaction
                foreach ($transactions as $transaction) {
                    // Check if transaction already exists
                    $existingTransaction = $db->table('fa_payout_transactions')
                        ->where('payout_id', $payoutId)
                        ->where('affiliate_id', $transaction->affiliate_id)
                        ->where('total_amount', $transaction->amount)
                        ->first()
                    ;

                    if ($existingTransaction) {
                        continue;
                    }

                    $mappedTransaction = [
                        'affiliate_id'  => $transaction->affiliate_id,
                        'payout_id'     => $payoutId,
                        'total_amount'  => $transaction->amount,
                        'payout_method' => isset($payoutMethodMap[$transaction->payout_method]) ? $payoutMethodMap[$transaction->payout_method] : 'manual',
                        'created_by'    => $transaction->created_by_user_id ?: null,
                        'status'        => ($transaction->status === 'paid') ? 'paid' : 'processing',
                        'created_at'    => $transaction->created_at ?? null,
                        'updated_at'    => $transaction->updated_at ?? null,
                    ];

                    // Store the transaction and get the new FA ID
                    $payoutTransactionId = $db->table('fa_payout_transactions')->insertGetId($mappedTransaction);

                    // Pull all referrals associated with this SA transaction
                    $referralIds = $db->table('solid_affiliate_referrals')
                        ->where('payout_id', $transaction->id)
                        ->pluck('id')
                        ->toArray()
                    ;

                    if (!empty($referralIds)) {
                        // Update referrals with correct FA payout and transaction IDs
                        $db->table('fa_referrals')
                            ->whereIn('id', $referralIds)
                            ->update([
                                'payout_id'             => $payoutId,
                                'payout_transaction_id' => $payoutTransactionId,
                            ])
                        ;
                    }
                }
            } catch (\Exception $e) {
                // Skip this payout to avoid infinite retry loop
            }
        }

        $status['migrated_payout_id'] = $lastId;
        $this->updateCurrentStatus($status);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migratePayouts($status);
    }

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliate_groups', 0);

        // Query solid_affiliate_affiliate_groups table
        $affiliateGroups = $this->db()
            ->table('solid_affiliate_affiliate_groups')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($affiliateGroups->isEmpty()) {
            $status['current_stage'] = 'affiliates';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // SA commission_type → FA rate_type
        $rateTypeMap = [
            'site_default' => 'default',
            'percentage'   => 'percentage',
            'flat'         => 'flat',
        ];

        $dataToInsert = [];

        foreach ($affiliateGroups as $group) {
            $lastId = $group->id;

            $rateType = isset($rateTypeMap[$group->commission_type]) ? $rateTypeMap[$group->commission_type] : 'default';

            $data = [
                'meta_key'    => $group->name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a DB query argument
                'value'       => \maybe_serialize([
                    'status'    => 'active',
                    'notes'     => 'Migrated from Solid Affiliate',
                    'rate_type' => $rateType,
                    'rate'      => $group->commission_rate,
                    'source_id' => $group->id,
                ]),
                'object_type' => 'affiliate_group',
            ];

            $dataToInsert[] = $data;
        }
        
        try {
            AffiliateGroup::insert($dataToInsert);
        } catch (\Exception $e) {
            // Skip this batch to avoid infinite retry loop
        }

        $status['migrated_affiliate_groups'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliateGroups($status);
    }

    /**
     * Solid group ids are not preserved on insert, so affiliates have to be
     * mapped onto the fa_meta ids. Groups migrated by this class carry their
     * source id, which is exact. Group names are not unique in Solid, so the
     * name fallback (for groups migrated before source ids were stored) can
     * only resolve to the last group of that name.
     *
     * @return array
     */
    private function getGroupIdMap()
    {
        if ($this->groupIdMap !== null) {
            return $this->groupIdMap;
        }

        $this->groupIdMap = [];

        $solidGroups = $this->db()
            ->table('solid_affiliate_affiliate_groups')
            ->select(['id', 'name'])
            ->get()
        ;

        if ($solidGroups->isEmpty()) {
            return $this->groupIdMap;
        }

        $faGroupsBySourceId = [];
        $faGroupsByName = [];

        foreach (AffiliateGroup::where('object_type', 'affiliate_group')->get() as $faGroup) {
            $sourceId = is_array($faGroup->value) ? Arr::get($faGroup->value, 'source_id') : null;

            if ($sourceId) {
                $faGroupsBySourceId[$sourceId] = $faGroup->id;
            }

            $faGroupsByName[$faGroup->meta_key] = $faGroup->id;
        }

        foreach ($solidGroups as $solidGroup) {
            if (isset($faGroupsBySourceId[$solidGroup->id])) {
                $this->groupIdMap[$solidGroup->id] = $faGroupsBySourceId[$solidGroup->id];
                continue;
            }

            if (isset($faGroupsByName[$solidGroup->name])) {
                $this->groupIdMap[$solidGroup->id] = $faGroupsByName[$solidGroup->name];
            }
        }

        return $this->groupIdMap;
    }

    public function migrateVisits($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_visits', 0);

        // Query solid_affiliate_visits table
        $visits = $this->db()
            ->table('solid_affiliate_visits')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
        ;

        if ($visits->isEmpty()) {
            // Stay in the 'visits' stage until the paginated recount fully completes;
            // advancing early would leave affiliates past the recount cursor at zero earnings.
            if ($this->recountAffiliateEarnings()) {
                $status['current_stage'] = 'creatives';
            }

            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $visitItems = [];
        foreach ($visits as $visit) {
            $lastId = $visit->id;

            $data = [
                'id'           => $visit->id,
                'affiliate_id' => $visit->affiliate_id,
                'referral_id'  => $visit->referral_id ?: null,
                'url'          => $visit->landing_url ?: null,
                'referrer'     => $visit->http_referrer ?: null,
                'ip'           => $visit->http_ip ?: null,
                'utm_campaign' => null,
                'created_at'   => $visit->created_at,
                'updated_at'   => $visit->updated_at,
            ];
            $visitItems[] = $data;
        }
        
        try {
            $this->db()->table('fa_visits')->insert($visitItems);
        } catch (\Exception $e) {
            // Skip this batch to avoid infinite retry loop
        }

        $status['migrated_visits'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateVisits($status);
    }

    public function getCounts()
    {
        $db = $this->db();

        // Count from affiliate_customer_links + unique customers from referrals
        $linksCount = $db->table('solid_affiliate_affiliate_customer_links')->count();

        $referralCustomerCount = $db->table('solid_affiliate_referrals')
            ->where('customer_id', '>', 0)
            ->distinct()
            ->count('customer_id');

        $data = [
            'affiliate_groups' => $db->table('solid_affiliate_affiliate_groups')->count(),
            'affiliates'       => $db->table('solid_affiliate_affiliates')->count(),
            'referrals'        => $db->table('solid_affiliate_referrals')->count(),
            'customers'        => $linksCount + $referralCustomerCount,
            'payouts'          => $db->table('solid_affiliates_bulk_payouts')->count(),
            'visits'           => $db->table('solid_affiliate_visits')->count(),
            'creatives'        => $db->table('solid_affiliate_creatives')->count(),
        ];

        return $data;
    }

    /**
     * Recount affiliate earnings in keyset-paginated batches so very large
     * affiliate counts cannot exceed the request time limit and stall the
     * migration. The cursor stores the last processed affiliate id (not a row
     * offset) so each batch resumes with an indexed id range instead of an
     * ever-growing SQL OFFSET scan. Returns true when every affiliate has been
     * recounted, false when it stopped early on the time limit (the caller must
     * re-invoke to resume).
     */
    public function recountAffiliateEarnings()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_affiliate_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            fluentAffiliate_update_option('solid_affiliate_migrated_recount', 0);
            return true;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
        }

        fluentAffiliate_update_option('solid_affiliate_migrated_recount', $lastId);

        if ($this->isTimeLimitExceeded()) {
            return false;
        }

        return $this->recountAffiliateEarnings();
    }

    public function migrateCreatives($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        // fa_creatives ships with the Pro plugin, so there is nowhere to put
        // them when only the free plugin is installed.
        if (!$this->hasTable('fa_creatives')) {
            $status['current_stage'] = 'completed';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $lastId = (int) Arr::get($status, 'migrated_creatives', 0);
        $db = $this->db();

        $creatives = $db->table('solid_affiliate_creatives')
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
                // Solid has no type column, so the banner image decides between
                // FA's image and text creatives.
                'type'        => $creative->creative_image_url ? 'image' : 'text',
                'image'       => $creative->creative_image_url ?: null,
                'text'        => $creative->creative_text ?: null,
                'url'         => $creative->url ?: null,
                'privacy'     => 'public',
                'status'      => ($creative->status === 'inactive') ? 'inactive' : 'active',
                'created_at'  => $creative->created_at,
                'updated_at'  => $creative->updated_at,
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

    private function hasTable($tableName)
    {
        $wpdb = $GLOBALS['wpdb'];
        $fullName = $wpdb->prefix . $tableName;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check; result not worth caching
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullName)));
    }
}
