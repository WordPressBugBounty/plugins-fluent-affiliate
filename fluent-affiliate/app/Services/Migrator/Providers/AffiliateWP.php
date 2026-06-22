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
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];
        foreach ($affiliates as $affiliate) {
            $dataToInsert[] = [
                'id'              => $affiliate->affiliate_id,
                'user_id'         => $affiliate->user_id,
                'rate'            => $affiliate->rate,
                'rate_type'       => $affiliate->rate_type ?: 'default',
                'payment_email'   => $affiliate->payment_email,
                'status'          => $affiliate->status,
                'total_earnings'  => $affiliate->earnings,
                'unpaid_earnings' => $affiliate->unpaid_earnings,
                'referrals'       => $affiliate->referrals,
                'visits'          => $affiliate->visits,
                'created_at'      => $affiliate->date_registered,
                'updated_at'      => $affiliate->date_registered,
            ];

            $lastId = $affiliate->affiliate_id;
        }

        $this->db()->table('fa_affiliates')->insert($dataToInsert);
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
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $referralToInsert = [];

        foreach ($referrals as $referral) {

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
                $providerId = NULL;
                $provider_sub_id = $providerId;
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
                'affiliate_id'    => $referral->affiliate_id ?: null,
                'visit_id'        => $referral->visit_id ?: null,
                'description'     => $referral->description ?: null,
                'status'          => $referral->status ?: null,
                'amount'          => $referral->amount ?: 0,
                'order_total'     => $orderTotal ?: 0,
                'currency'        => $referral->currency ?: null,
                'provider'        => $referral->context ?: null,
                'provider_id'     => $providerId ?: null,
                'provider_sub_id' => $provider_sub_id ?: null,
                'products'        => \maybe_serialize($formattedProducts),
                'payout_id'       => $referral->payout_id ?: null,
                'customer_id'     => $referral->customer_id ?: null,
                'created_at'      => $referral->date ?: null,
                'updated_at'      => $referral->date ?: null,
            ];
            $lastId = $referral->referral_id;
        }

        $this->db()->table('fa_referrals')->insert($referralToInsert);

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
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        foreach ($customers as $customer) {
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
                $data['by_affiliate_id'] = $firstRef->meta_value;
            }

            $dataToInsert[] = $data;
            $lastId = $customer->customer_id;
        }

        $this->db()->table('fa_customers')->insert($dataToInsert);

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

        $payoutGroups = $this->db()->table('affiliate_wp_payouts')
            ->select([
                $this->db()->raw('DATE(date) as date_group'),
                'owner',
                'payout_method'
            ])
            ->orderBy('date', 'ASC')
            ->groupBy('date_group')
            ->where('payout_id', '>', $migratedCount)
            ->limit($limit)
            ->get();

        if ($payoutGroups->isEmpty()) {
            $status['current_stage'] = 'visits';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        foreach ($payoutGroups as $payoutGroup) {
            $payouts = $this->db()->table('affiliate_wp_payouts')
                ->where('date', 'LIKE', $payoutGroup->date_group . '%')
                ->get();

            $transactions = [];
            $totalPayoutAmount = 0;

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
                $transactions[] = [
                    'created_by'    => $payout->owner ?: null,
                    'affiliate_id'  => $payout->affiliate_id ?: null,
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

            $formattedPayout['total_amount'] = $totalPayoutAmount;

            $payoutId = $this->db()
                ->table('fa_payouts')
                ->insertGetId($formattedPayout);

            foreach ($transactions as $transaction) {
                $affWpId = $transaction['aff_wp_id'];
                $referralIds = $transaction['referrals_ids'];
                $transaction['payout_id'] = $payoutId;

                unset($transaction['aff_wp_id']);
                unset($transaction['referrals_ids']);

                $payoutTransactionId = $this->db()->table('fa_payout_transactions')->insertGetId($transaction);

                $this->db()->table('fa_referrals')
                    ->whereIn('id', $referralIds)
                    ->update([
                        'payout_id'             => $payoutId,
                        'payout_transaction_id' => $payoutTransactionId
                    ]);

            }
        }

        $status['migrated_payout_id'] = $affWpId;
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
            // Stay in the 'visits' stage until the paginated recount fully completes;
            // advancing early would leave affiliates past the recount cursor at zero earnings.
            if ($this->recountEarnings()) {
                $status['current_stage'] = 'creatives';
            }

            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $visitItems = [];
        foreach ($visits as $visit) {
            $visitItems[] = [
                'id'           => $visit->visit_id ?: null,
                'affiliate_id' => $visit->affiliate_id ?: null,
                'referral_id'  => $visit->referral_id ?: null,
                'url'          => $visit->url ?: null,
                'referrer'     => $visit->referrer ?: null,
                'utm_campaign' => $visit->campaign ?: null,
                'ip'           => $visit->ip ?: null,
                'created_at'   => $visit->date ?: null,
                'updated_at'   => $visit->date ?: null,
            ];
            $lastId = $visit->visit_id;
        }

        $this->db()->table('fa_visits')->insert($visitItems);

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

    public function getCounts()
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
