<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

class AffiliateWP extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'affwp';
    }

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        $status['current_stage'] = 'affiliates';
        $this->updateCurrentStatus($status, false);
        return $status;
    }

    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $lastId = (int) Arr::get($status, 'migrated_affiliates', 0);



        $affiliates = $this->db()
            ->table('affiliate_wp_affiliates')
            ->where('affiliate_id', '>', $lastId)
            ->orderBy('affiliate_id', 'ASC')
            ->limit($limit)
            ->get();

        if ($affiliates->isEmpty()) {
            $this->resetAutoIncrement('fa_affiliates');
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $siteSettings = (array) get_option('affwp_settings', []);

        $dataToInsert = [];
        foreach ($affiliates as $affiliate) {
            $lastId = $affiliate->affiliate_id;

            list($rate, $rateType) = $this->mapRate($affiliate->rate, $affiliate->rate_type, $siteSettings);

            $row = [
                'id'              => $affiliate->affiliate_id,
                '__source_id'     => $affiliate->affiliate_id,
                'user_id'         => $affiliate->user_id,
                'rate'            => $rate,
                'rate_type'       => $rateType,
                'payment_email'   => $affiliate->payment_email,
                'status'          => $affiliate->status,
                'total_earnings'  => $affiliate->earnings,
                'unpaid_earnings' => $affiliate->unpaid_earnings,
                'referrals'       => $affiliate->referrals,
                'visits'          => $affiliate->visits,
                'created_at'      => $affiliate->date_registered,
                'updated_at'      => $affiliate->date_registered,
            ];

            $dataToInsert[] = $row;
        }

        $this->insertAffiliates($dataToInsert);
        $status['migrated_affiliates'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliates($status);
    }

    public function migrateReferrals($status = [], $limit = 100)
    {
        $lastId = (int) Arr::get($status, 'migrated_referrals', 0);

        $referrals = $this->db()->table('affiliate_wp_referrals')
            ->where('referral_id', '>', $lastId)
            ->orderBy('referral_id', 'ASC')
            ->limit($limit)
            ->get();

        if ($referrals->isEmpty()) {
            $this->resetAutoIncrement('fa_referrals');
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($this->db()->table('fa_referrals')
            ->whereIn('id', $referrals->pluck('referral_id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $referralToInsert = [];

        foreach ($referrals as $referral) {
            $lastId = $referral->referral_id;

            if (isset($existingIds[$referral->referral_id])) {
                continue;
            }

            $orderTotal = 0;

            if ($mata = $this->db()->table('affiliate_wp_sales')->where('referral_id',
                $referral->referral_id)->first()) {
                $orderTotal = $mata->order_total;
            }

            $providerId = $referral->reference;
            $provider_sub_id = '';
            if (is_numeric($providerId)) {
                $providerId = (int)$providerId;
            } else {
                $provider_sub_id = $providerId;
                $providerId = NULL;
            }

            $formattedProducts = [];

            $products = Utility::safeUnserialize($referral->products);

            if ($products && is_array($products)) {
                foreach ($products as $product) {
                    $price = isset($product['price']) ? (float)$product['price'] : 0.00;
                    $formattedProducts[] = [
                        'item_id'  => Arr::get($product, 'id'),
                        'title'    => Arr::get($product, 'name', $referral->description),
                        'subtotal' => isset($product['price']) ? (float)$product['price'] : 0.00,
                        'price'    => $price,
                        'total'    => $price
                    ];
                }
            } else {
                $formattedProducts[] = [
                    'item_id'  => NULL,
                    'title'    => $referral->description,
                    'subtotal' => $orderTotal,
                    'price'    => $orderTotal,
                    'total'    => $orderTotal
                ];
            }

            $referralToInsert[] = [
                'id'              => $referral->referral_id ?: null,
                'affiliate_id'    => $this->mapAffiliateId($referral->affiliate_id) ?: null,
                'visit_id'        => $referral->visit_id ?: null,
                'description'     => $referral->description ? sanitize_text_field($referral->description) : null,
                'status'          => $referral->status ?: null,
                'amount'          => $referral->amount ?: 0,
                'order_total'     => $orderTotal ?: 0,
                'currency'        => $referral->currency ?: null,
                'provider'        => $this->mapProvider($referral->context),
                'provider_id'     => $providerId ?: null,
                'provider_sub_id' => $provider_sub_id ?: null,
                'products'        => \maybe_serialize($formattedProducts),
                'payout_id'       => $referral->payout_id ?: null,
                'customer_id'     => $referral->customer_id ?: null,
                'created_at'      => $referral->date ?: null,
                'updated_at'      => $referral->date ?: null,
            ];
        }

        $this->insertBatch('fa_referrals', $referralToInsert);

        $status['migrated_referrals'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateReferrals($status);
    }

    public function migrateCustomers($status = [], $limit = 100)
    {
        $lastId = (int) Arr::get($status, 'migrated_customers', 0);

        $customers = $this->db()->table('affiliate_wp_customers')
            ->where('customer_id', '>', $lastId)
            ->orderBy('customer_id', 'ASC')
            ->limit($limit)
            ->get();

        if ($customers->isEmpty()) {
            $this->resetAutoIncrement('fa_customers');
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($this->db()->table('fa_customers')
            ->whereIn('id', $customers->pluck('customer_id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $dataToInsert = [];

        foreach ($customers as $customer) {
            $lastId = $customer->customer_id;

            if (isset($existingIds[$customer->customer_id])) {
                continue;
            }

            $data = [
                'id'         => $customer->customer_id ?: null,
                'user_id'    => $customer->user_id ?: null,
                'email'      => $customer->email ?: null,
                'first_name' => $customer->first_name ?: null,
                'last_name'  => $customer->last_name ?: null,
                'created_at' => $customer->date_created ?: null,
                'updated_at' => $customer->date_created ?: null,
                'by_affiliate_id' => null,
            ];

            $firstRef = $this->db()->table('affiliate_wp_customermeta')
                ->where('affwp_customer_id', $customer->customer_id)
                ->where('meta_key', 'affiliate_id')
                ->orderBy('meta_id', 'ASC')
                ->first();

            if ($firstRef && is_numeric($firstRef->meta_value)) {
                $data['by_affiliate_id'] = $this->mapAffiliateId($firstRef->meta_value);
            }

            $dataToInsert[] = $data;
        }

        $this->insertBatch('fa_customers', $dataToInsert);

        $status['migrated_customers'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCustomers($status);
    }

    public function migratePayouts($status = [], $limit = 5)
    {
        $migratedCount = Arr::get($status, 'migrated_payout_id', 0);

        $affWPSettings = get_option('affwp_settings');

        $currency = isset($affWPSettings['currency']) ? $affWPSettings['currency'] : 'USD';

        // owner and payout_method are not grouping columns, so they have to be
        // aggregated or MySQL rejects the query outright under the
        // ONLY_FULL_GROUP_BY mode it enables by default.
        $payoutGroups = $this->db()->table('affiliate_wp_payouts')
            ->select([
                $this->db()->raw('DATE(date) as date_group'),
                $this->db()->raw('MIN(owner) as owner'),
                $this->db()->raw('MIN(payout_method) as payout_method')
            ])
            ->groupBy('date_group')
            ->orderBy('date_group', 'ASC')
            ->where('payout_id', '>', $migratedCount)
            ->limit($limit)
            ->get();

        if ($payoutGroups->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $lastId = $migratedCount;
        $failedPayoutIds = [];
        $failedReferralIds = [];

        foreach ($payoutGroups as $payoutGroup) {
            // Without the cursor here a date group straddling a batch boundary
            // would import its earlier payouts a second time.
            $payouts = $this->db()->table('affiliate_wp_payouts')
                ->where('date', 'LIKE', $payoutGroup->date_group . '%')
                ->where('payout_id', '>', $migratedCount)
                ->orderBy('payout_id', 'ASC')
                ->get();

            if ($payouts->isEmpty()) {
                continue;
            }

            $transactions = [];
            $totalPayoutAmount = 0;
            $hasOpenPayout = false;

            $formattedPayout = [
                'created_by'    => $payoutGroup->owner,
                'total_amount'  => 0,
                'payout_method' => $payoutGroup->payout_method,
                'status'        => 'paid',
                'currency'      => $currency,
                'title'         => sprintf('Payouts at %s', $payoutGroup->date_group),
                'description'   => sprintf('Migrated Payouts at %s from AffiliateWP', $payoutGroup->date_group),
                'created_at'    => $payoutGroup->date_group . ' 00:00:00',
                'updated_at'    => $payoutGroup->date_group . ' 00:00:00'
            ];

            foreach ($payouts as $payout) {
                $lastId = max($lastId, (int) $payout->payout_id);

                // a failed payout never sent the money, so its referrals stay owed
                // and are released after the batch
                if ($payout->status === 'failed') {
                    $failedPayoutIds[] = (int) $payout->payout_id;
                    $failedReferralIds = array_merge($failedReferralIds, array_map('intval', explode(',', (string) $payout->referrals)));
                    continue;
                }

                $hasOpenPayout = $hasOpenPayout || $payout->status !== 'paid';

                $transactions[] = [
                    'created_by'    => $payout->owner ?: null,
                    'affiliate_id'  => $this->mapAffiliateId($payout->affiliate_id) ?: null,
                    'total_amount'  => $payout->amount ?: null,
                    'currency'      => $currency ?: null,
                    'payout_method' => $payout->payout_method ?: null,
                    'status'        => $payout->status ?: null,
                    'created_at'    => $payout->date ?: null,
                    'updated_at'    => $payout->date ?: null,
                    'aff_wp_id'     => $payout->payout_id ?: null,
                    'referrals_ids' => explode(',', $payout->referrals) ?: null,
                ];
                $totalPayoutAmount += $payout->amount;
            }

            if (!$transactions) {
                continue;
            }

            $formattedPayout['total_amount'] = $totalPayoutAmount;
            // an open payout keeps the header open, as Payout::recountPaymentTotal() does
            $formattedPayout['status'] = $hasOpenPayout ? 'processing' : 'paid';

            $payoutId = $this->db()
                ->table('fa_payouts')
                ->insertGetId($formattedPayout);

            foreach ($transactions as $transaction) {
                $referralIds = array_filter($transaction['referrals_ids']);
                $transaction['payout_id'] = $payoutId;

                unset($transaction['aff_wp_id']);
                unset($transaction['referrals_ids']);

                $payoutTransactionId = $this->db()->table('fa_payout_transactions')->insertGetId($transaction);

                if (!empty($referralIds)) {
                    $this->db()->table('fa_referrals')
                        ->whereIn('id', $referralIds)
                        ->update([
                            'payout_id'             => $payoutId,
                            'payout_transaction_id' => $payoutTransactionId
                        ]);
                }
            }
        }

        // AffiliateWP marks a payout's referrals paid when it creates the payout and
        // keeps them paid, and on its payout id, when the payout fails
        $failedReferralIds = array_filter($failedReferralIds);

        if ($failedReferralIds) {
            $failedReferrals = $this->db()->table('fa_referrals')
                ->whereIn('id', $failedReferralIds)
                ->whereIn('payout_id', $failedPayoutIds);

            (clone $failedReferrals)->where('status', 'paid')->update(['status' => 'unpaid']);
            $failedReferrals->update(['payout_id' => null, 'payout_transaction_id' => null]);
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
        $lastId = (int) Arr::get($status, 'migrated_visits', 0);

        $visits = $this->db()
            ->table('affiliate_wp_visits')
            ->where('visit_id', '>', $lastId)
            ->orderBy('visit_id', 'ASC')
            ->limit($limit)
            ->get();

        if ($visits->isEmpty()) {
            $this->resetAutoIncrement('fa_visits');

            // Stay in the 'visits' stage until the paginated recount fully completes;
            // advancing early would leave affiliates past the recount cursor at zero earnings.
            if ($this->recountEarnings()) {
                $status['current_stage'] = 'creatives';
            }

            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $existingIds = array_flip($this->db()->table('fa_visits')
            ->whereIn('id', $visits->pluck('visit_id')->toArray())
            ->pluck('id')
            ->toArray()
        );

        $visitItems = [];
        foreach ($visits as $visit) {
            $lastId = $visit->visit_id;

            if (isset($existingIds[$visit->visit_id])) {
                continue;
            }

            $visitItems[] = [
                'id'           => $visit->visit_id ?: null,
                'affiliate_id' => $this->mapAffiliateId($visit->affiliate_id) ?: null,
                'referral_id'  => $visit->referral_id ?: null,
                'url'          => $visit->url ? sanitize_url($visit->url) : null,
                'referrer'     => $visit->referrer ? sanitize_url($visit->referrer) : null,
                'utm_campaign' => $visit->campaign ?: null,
                'ip'           => $visit->ip ?: null,
                'created_at'   => $visit->date ?: null,
                'updated_at'   => $visit->date ?: null,
            ];
        }

        $this->insertBatch('fa_visits', $visitItems);

        $status['migrated_visits'] = $lastId;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateVisits($status);
    }

    /**
     * Recount affiliate earnings in keyset-paginated batches so very large
     * affiliate counts cannot exceed the request time limit and stall the
     * migration. The cursor stores the last processed affiliate id (not a row
     * offset) so each batch resumes with an indexed id range instead of an
     * ever-growing SQL OFFSET scan. Returns true when every affiliate has been
     * recounted, false when it stopped early on the time limit (the caller must
     * re-invoke to resume).
     *
     * @return bool
     */
    protected function recountEarnings()
    {
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            fluentAffiliate_update_option('affwp_migrated_recount', 0);
            return true;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
        }

        fluentAffiliate_update_option('affwp_migrated_recount', $lastId);

        if ($this->isTimeLimitExceeded()) {
            return false;
        }

        return $this->recountEarnings();
    }

    /**
     * AffiliateWP fills an empty affiliate rate or rate type from its site settings, so
     * an affiliate that sets only one of them still has its own commission. Only an
     * affiliate that sets neither follows the site rate, which is our `default`.
     *
     * @return array [rate, rate_type]
     */
    protected function mapRate($rate, $rateType, $siteSettings)
    {
        $hasRate = $rate !== null && $rate !== '';
        $hasRateType = in_array($rateType, ['percentage', 'flat'], true);

        if (!$hasRate && !$hasRateType) {
            return [$rate, 'default'];
        }

        $siteRateType = Arr::get($siteSettings, 'referral_rate_type') === 'flat' ? 'flat' : 'percentage';

        return [
            $hasRate ? $rate : Arr::get($siteSettings, 'referral_rate', 20),
            $hasRateType ? $rateType : $siteRateType,
        ];
    }

    /**
     * AffiliateWP's referral context, as the provider slug our connectors look referrals
     * up by. The other contexts we have a connector for (edd, give, lifterlms,
     * memberpress, pmp) already match.
     */
    protected function mapProvider($context)
    {
        if (!$context) {
            return null;
        }

        $map = [
            'woocommerce' => 'woo',
        ];

        return Arr::get($map, $context, $context);
    }

    protected function getSourceCounts()
    {
        $db = $this->db();

        return [
            'affiliates' => $db->table('affiliate_wp_affiliates')->count() ?: 0,
            'referrals'  => $db->table('affiliate_wp_referrals')->count() ?: 0,
            'customers'  => $db->table('affiliate_wp_customers')->count() ?: 0,
            'payouts'    => $db->table('affiliate_wp_payouts')->count() ?: 0,
            'visits'     => $db->table('affiliate_wp_visits')->count() ?: 0,
        ];
    }

    public function migrateCreatives($status = [], $limit = 100)
    {
        // AffiliateWP has no creatives to migrate
        $status['current_stage'] = 'completed';
        $this->updateCurrentStatus($status, false);
        return $status;
    }
}
