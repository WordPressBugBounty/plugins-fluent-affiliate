<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Framework\Support\Arr;

class AffiliateWP extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'affwp';
    }

    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_affiliates', 0);

        $affiliates = $this->db()
            ->table('affiliate_wp_affiliates')
            ->orderBy('affiliate_id', 'ASC')
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

            $migratedCount++;
        }

        $this->db()->table('fa_affiliates')->insert($dataToInsert);
        $status['migrated_affiliates'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliates($status);
    }

    public function migrateReferrals($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_referrals', 0);

        $referrals = $this->db()->table('affiliate_wp_referrals')
            ->orderBy('referral_id', 'ASC')
            ->offset($migratedCount)
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
            $products = \maybe_unserialize($referral->products);

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
            $migratedCount++;
        }

        $this->db()->table('fa_referrals')->insert($referralToInsert);

        $status['migrated_referrals'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateReferrals($status);
    }

    public function migrateCustomers($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_customers', 0);

        $customers = $this->db()->table('affiliate_wp_customers')
            ->orderBy('customer_id', 'ASC')
            ->offset($migratedCount)
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
            $migratedCount++;
        }

        $this->db()->table('fa_customers')->insert($dataToInsert);

        $status['migrated_customers'] = $migratedCount;
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
        $migratedCount = Arr::get($status, 'migrated_visits', 0);

        $visits = $this->db()
            ->table('affiliate_wp_visits')
            ->orderBy('visit_id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get();

        if ($visits->isEmpty()) {
            $status['current_stage'] = 'completed';
            $this->recountEarnings($status);
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
            $migratedCount++;
        }

        $this->db()->table('fa_visits')->insert($visitItems);

        $status['migrated_visits'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateVisits($status);
    }

    protected function recountEarnings($status)
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_recount', 0);

        $affiliates = Affiliate::orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            return $status;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_recount', $migratedCount);
        }

        return $this->recountEarnings($status);
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

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        $status['current_stage'] = 'affiliates';
        $this->updateCurrentStatus($status, false);
        return $status;
    }
}
