<?php

namespace FluentAffiliate\App\Services\Migrator\Providers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Customer;

class SolidAffiliate extends BaseMigrator
{
    public function __construct()
    {
        $this->migratorPrefix = 'solid_affiliate';
    }

    public function migrateAffiliates($status = [], $limit = 100)
    {
        if (!$status) {
            $status = $this->getCurrentStatus();
        }

        $migratedCount = Arr::get($status, 'migrated_affiliates', 0);

        // Map Solid Affiliate statuses to Fluent Affiliate statuses
        $affiliateStatusMap = [
            'approved' => 'active',   // Approved in Solid maps to active in Fluent
            'pending'  => 'pending',  // Pending remains pending
            'rejected' => 'inactive', // Rejected maps to inactive
        ];

        // Column mapping from solid_affiliate_affiliates to fa_affiliates
        $affiliateColumnsMap = [
            'id'                       => 'id',           // Affiliate ID (primary key)
            'user_id'                  => 'user_id',      // WordPress user ID
            'affiliate_group_id'       => 'group_id',     // Affiliate group ID from solid_affiliate_affiliate_groups
            'commission_type'          => 'rate_type',    // Commission type (e.g., percentage, fixed)
            'commission_rate'          => 'rate',         // Commission rate value
            'payment_email'            => 'payment_email',// Email for payments
            'registration_notes'       => 'note',         // Notes from registration
            'status'                   => 'status',       // Affiliate status (mapped via $affiliateStatusMap)
            'custom_registration_data' => 'custom_param', // Custom data from registration
            'created_at'               => 'created_at',   // Creation timestamp
            'updated_at'               => 'updated_at',   // Last updated timestamp
        ];

        // Query solid_affiliate_affiliates table
        $affiliates = $this->db()
            ->table('solid_affiliate_affiliates')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            // No more affiliates to migrate, move to next stage
            $status['current_stage'] = 'referrals';
            $this->updateCurrentStatus($status);
            return $status;
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $data = [];

            // Map each column from SolidAffiliate to FluentAffiliate
            foreach ($affiliateColumnsMap as $solidColumn => $fluentColumn) {
                if ($solidColumn === 'status' && isset($affiliate->status)) {
                    // Map status using $affiliateStatusMap, default to 'active' if not found
                    $data[$fluentColumn] = isset($affiliateStatusMap[$affiliate->status]) ? $affiliateStatusMap[$affiliate->status] : 'active';
                } elseif (isset($affiliate->$solidColumn)) {
                    // Copy field value if it exists
                    $data[$fluentColumn] = $affiliate->$solidColumn;
                }
            }

            // Hardcode required fields not in SolidAffiliate
            $data = array_merge($data, [
                'total_earnings'  => 0, // Hardcoded: Total earnings not tracked in SolidAffiliate
                'unpaid_earnings' => 0, // Hardcoded: Unpaid earnings not tracked
                'referrals'       => 0, // Hardcoded: Referral count not set during migration
                'visits'          => 0  // Hardcoded: Visit count not set during migration
            ]);

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        try {
            // Insert affiliates into fa_affiliates table
            $this->db()->table('fa_affiliates')->insert($dataToInsert);
        } catch (\Exception $e) {
            return $status; // Return current status to allow retry
        }

        // Update migration status
        $status['migrated_affiliates'] = $migratedCount;
        $this->updateCurrentStatus($status);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        // Continue migrating next batch
        return $this->migrateAffiliates($status);
    }

    public function migrateReferrals($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_referrals', 0);

        // Map Solid Affiliate referral statuses to Fluent Affiliate statuses
        $referralStatusMap = [
            'unpaid'   => 'unpaid',   // Unpaid status remains unpaid
            'paid'     => 'paid',     // Paid status remains paid
            'rejected' => 'rejected', // Rejected status remains rejected
            'draft'    => 'pending',  // Draft maps to pending
        ];

        // Column mapping from solid_affiliate_referrals to fa_referrals
        $referralColumnsMap = [
            'id'                          => 'id',              // Referral ID (primary key)
            'affiliate_id'                => 'affiliate_id',     // Affiliate ID linked to the referral
            'order_amount'                => 'order_total',      // Total order amount
            'commission_amount'           => 'amount',           // Commission amount
            'visit_id'                    => 'visit_id',         // Visit ID linked to the referral
            'customer_id'                 => 'customer_id',      // Customer ID (WordPress user ID)
            'referral_type'               => 'type',            // Referral type
            'description'                 => 'description',      // Referral description
            'order_id'                    => 'provider_id',      // WooCommerce order ID
            'created_at'                  => 'created_at',       // Creation timestamp
            'updated_at'                  => 'updated_at',       // Last updated timestamp
            'payout_id'                   => 'payout_transaction_id', // Payout transaction ID
            'status'                      => $referralStatusMap, // Status (mapped via $referralStatusMap)
            'serialized_item_commissions' => 'products',         // Product commission details
            'affiliate_customer_link_id'  => 'customer_id',      // Alternative customer ID (if present, overrides customer_id)
        ];

        // Query solid_affiliate_referrals table
        $referrals = $this->db()->table('solid_affiliate_referrals')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($referrals->isEmpty()) {
            $status['current_stage'] = 'customers';
            $this->updateCurrentStatus($status);
            return $status;
        }

        $referralToInsert = [];

        foreach ($referrals as $referral) {
            $data = [];
            foreach ($referralColumnsMap as $solidColumn => $fluentColumn) {
                if ($fluentColumn === null) {
                    continue; // Skip if mapping is null
                }
                if ($solidColumn === 'status' && isset($referral->status)) {
                    // Map status using $referralStatusMap, default to 'pending' if not found
                    $data['status'] = isset($referralStatusMap[$referral->status]) ? $referralStatusMap[$referral->status] : 'pending';
                } elseif ($solidColumn === 'affiliate_customer_link_id' && isset($referral->affiliate_customer_link_id)) {
                    // Use affiliate_customer_link_id as customer_id if present (last one wins)
                    $data['customer_id'] = $referral->affiliate_customer_link_id;
                } elseif (isset($referral->$solidColumn) && $solidColumn !== 'order_source') {
                    // Copy field value if it exists, skip order_source
                    $data[$fluentColumn] = $referral->$solidColumn;
                }
            }

            // Hardcode required fields
            $data = array_merge($data, [
                'provider' => 'woo', // Hardcoded: Set provider to 'woo' for Fluent Affiliate
                'currency' => null,  // Hardcoded: Currency not available in SolidAffiliate
            ]);

            $referralToInsert[] = $data;
            $migratedCount++;
        }

        try {
            // Insert referrals into fa_referrals table
            $this->db()->table('fa_referrals')->insert($referralToInsert);
        } catch (\Exception $e) {
            return $status; // Return current status to allow retry
        }

        $status['migrated_referrals'] = $migratedCount;
        $this->updateCurrentStatus($status);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateReferrals($status);
    }

    public function migrateCustomers($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_customers', 0);

        // Get WordPress users with WooCommerce orders
        $userIds = $this->db()->table('wc_orders')
            ->select('customer_id')
            ->where('type', 'shop_order')
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id')
            ->toArray()
        ;

        if (empty($userIds)) {
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        // Slice user IDs for the current batch
        $userIds = array_slice($userIds, $migratedCount, $limit);

        if (empty($userIds)) {
            $status['current_stage'] = 'payouts';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        // Get existing customer user_ids to avoid duplicates
        $existingCustomerUserIds = Customer::whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->toArray()
        ;

        foreach ($userIds as $userId) {
            // Skip if user is already in fa_customers
            if (in_array($userId, $existingCustomerUserIds)) {
                $migratedCount++;
                continue;
            }

            $user = get_userdata($userId);
            if (!$user) {
                $migratedCount++;
                continue; // Skip if user doesn't exist
            }

            $data = [
                'user_id'    => $user->ID,
                'email'      => $user->user_email,
                'first_name' => get_user_meta($user->ID, 'first_name', true) ?: null,
                'last_name'  => get_user_meta($user->ID, 'last_name', true) ?: null,
                'created_at' => $user->user_registered,
                'updated_at' => null, // No direct equivalent in WP_User
            ];

            // Check for affiliate association in solid_affiliate_referrals
            $firstRef = $this->db()->table('solid_affiliate_referrals')
                ->where('customer_id', $user->ID)
                ->orWhere('affiliate_customer_link_id', $user->ID)
                ->orderBy('id', 'ASC')
                ->first()
            ;

            if ($firstRef && is_numeric($firstRef->affiliate_id)) {
                $data['by_affiliate_id'] = $firstRef->affiliate_id;
            }

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        if (!empty($dataToInsert)) {
            try {
                Customer::insert($dataToInsert);
            } catch (\Exception $e) {
                return $status;
            }
        }

        $status['migrated_customers'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateCustomers($status);
    }


    public function migratePayouts($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_payout_id', 0);

        // Map columns for payout transactions (wp_fa_payout_transactions)
        $payoutTransactionsColumnsMap = [
            'affiliate_id'       => 'affiliate_id',      // Affiliate ID linked to the payout
            'amount'             => 'total_amount',      // Payout amount
            'payout_method'      => 'payout_method',     // Payment method (e.g., manual, paypal)
            'created_by_user_id' => 'created_by',        // User who created the payout
            'status'             => 'status',            // Payout status (e.g., paid, pending)
            'created_at'         => 'created_at',        // Creation timestamp
            'updated_at'         => 'updated_at',        // Last updated timestamp
        ];

        // Map columns for payouts (wp_fa_payouts)
        $payoutsColumnsMap = [
            'currency'           => 'currency',      // Currency of the payout
            'method'             => 'payout_method', // Payment method
            'total_amount'       => 'total_amount',  // Total payout amount
            'status'             => 'status',        // Payout status
            'created_by_user_id' => 'created_by',    // User who created the payout
            'created_at'         => 'created_at',    // Creation timestamp
            'updated_at'         => 'updated_at',    // Last updated timestamp
        ];

        // Query solid_affiliates_bulk_payouts table
        $payoutGroups = $this->db()->table('solid_affiliates_bulk_payouts')
            ->select([
                'id',
                'date_range_start',
                'date_range_end',
                'created_by_user_id',
                'method',
                'currency',
                'status'
            ])
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
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
            ->whereIn('id', array_column((array)$payoutGroups, 'id'))
            ->pluck('id')
            ->toArray()
        ;

        foreach ($payoutGroups as $payout) {
            // Skip if payout already exists in fa_payouts
            if (in_array($payout->id, $existingPayoutIds)) {
                $migratedCount++;
                continue;
            }

            $formattedPayout = [];
            // Map payout fields
            foreach ($payoutsColumnsMap as $solidColumn => $fluentColumn) {
                if (isset($payout->$solidColumn)) {
                    $formattedPayout[$fluentColumn] = $payout->$solidColumn;
                }
            }

            // Pull all transactions associated with this payout
            $transactions = $db->table('solid_affiliate_payouts')
                ->where('bulk_payout_id', $payout->id)
                ->get()
            ;

            if ($transactions->isEmpty()) {
                $migratedCount++;
                continue; // Skip if no transactions found for this payout
            }

            $totalPayoutAmount = 0;
            foreach ($transactions as $transaction) {
                $totalPayoutAmount += $transaction->amount;
            }

            // Hardcode title and description
            $formattedPayout['title'] = sprintf(
                'Payouts from %s to %s',
                $payout->date_range_start,
                $payout->date_range_end
            );
            $formattedPayout['description'] = sprintf(
                'Migrated Payouts for date range %s to %s from Solid Affiliate',
                $payout->date_range_start,
                $payout->date_range_end
            );
            $formattedPayout['total_amount'] = $totalPayoutAmount;

            // Ensure currency is explicitly set
            if (!isset($formattedPayout['currency']) && isset($payout->currency)) {
                $formattedPayout['currency'] = $payout->currency;
            }

            try {
                // Store the payout
                $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

                // Process each transaction
                foreach ($transactions as $transaction) {
                    // Check if transaction already exists in fa_payout_transactions
                    $existingTransaction = $db->table('fa_payout_transactions')
                        ->where('payout_id', $payoutId)
                        ->where('affiliate_id', $transaction->affiliate_id)
                        ->where('total_amount', $transaction->amount)
                        ->first()
                    ;

                    if ($existingTransaction) {
                        continue; // Skip if transaction already exists
                    }

                    $mappedTransaction = [];
                    // Map transaction columns
                    foreach ($payoutTransactionsColumnsMap as $solidColumn => $fluentColumn) {
                        if (isset($transaction->$solidColumn)) {
                            $mappedTransaction[$fluentColumn] = $transaction->$solidColumn;
                        }
                    }
                    $mappedTransaction['payout_id'] = $payoutId; // Link to fa_payouts.id

                    // Store the transaction
                    $payoutTransactionId = $db->table('fa_payout_transactions')->insertGetId($mappedTransaction);

                    // Pull all referrals associated with this transaction
                    $referrals = $db->table('solid_affiliate_referrals')
                        ->where('payout_id', $transaction->id)
                        ->pluck('id')
                        ->toArray()
                    ;

                    // Update referrals with payout and transaction IDs
                    foreach ($referrals as $referral) {
                        $db->table('fa_referrals')
                            ->where('id', $referral)
                            ->update([
                                'payout_id'             => $payoutId,
                                'payout_transaction_id' => $transaction->id // Use solid_affiliate_payouts.id
                            ])
                        ;
                    }
                }

                $migratedCount++;
            } catch (\Exception $e) {
                if(defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::error('Error migrating payouts: ' . $e->getMessage());
                }
                return $status;
            }
        }

        $status['migrated_payout_id'] = $migratedCount;
        $this->updateCurrentStatus($status);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migratePayouts($status);
    }

    public function migrateAffiliateGroups($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_affiliate_groups', 0);

        $affiliateGroupColumnsMap = [
            'name'            => 'meta_key',
            'commission_type' => 'value.rate_type',
            'commission_rate' => 'value.rate',
        ];

        $adjustments = [
            'value' => [
                'status' => 'active',
                'notes'  => 'Migrated from Solid Affiliate',
            ],
        ];

        // Query solid_affiliate_affiliate_groups table (read operation, no try-catch)
        $affiliateGroups = $this->db()
            ->table('solid_affiliate_affiliate_groups')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($affiliateGroups->isEmpty()) {
            $status['current_stage'] = 'affiliates';
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $dataToInsert = [];

        foreach ($affiliateGroups as $group) {
            $data = [];
            $valueData = $adjustments['value'];

            foreach ($affiliateGroupColumnsMap as $solidColumn => $fluentColumn) {
                if ($fluentColumn === null) {
                    continue; // Skip if mapping is null
                }
                if (strpos($fluentColumn, 'value.') === 0) {
                    // Handle nested value fields
                    $valueKey = substr($fluentColumn, 6); // Remove 'value.' prefix
                    if (isset($group->$solidColumn)) {
                        $valueData[$valueKey] = $group->$solidColumn;
                    }
                } elseif (isset($group->$solidColumn)) {
                    $data[$fluentColumn] = $group->$solidColumn;
                }
            }

            $data['value'] = maybe_serialize($valueData);
            $data['object_type'] = 'affiliate_group'; // Hardcoded object_type
            $dataToInsert[] = $data;
            $migratedCount++;
        }

        try {
            // Insert into fa_affiliate_groups table
            AffiliateGroup::insert($dataToInsert);
        } catch (\Exception $e) {
            return $status; // Return current status to allow retry
        }

        $status['migrated_affiliate_groups'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateAffiliateGroups($status);
    }

    public function migrateVisits($status = [], $limit = 100)
    {
        $migratedCount = Arr::get($status, 'migrated_visits', 0);

        $visitsColumnsMap = [
            'id'                => 'id',
            'previous_visit_id' => null,
            'affiliate_id'      => 'affiliate_id',
            'referral_id'       => 'referral_id',
            'landing_url'       => 'url',
            'http_referrer'     => 'referrer',
            'http_ip'           => 'ip',
            'created_at'        => 'created_at',
            'updated_at'        => 'updated_at',
        ];

        // Query solid_affiliate_visits table (read operation, no try-catch)
        $visits = $this->db()
            ->table('solid_affiliate_visits')
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit($limit)
            ->get()
        ;

        if ($visits->isEmpty()) {
            $status['current_stage'] = 'completed';
            $this->recountAffiliateEarnings();
            $this->updateCurrentStatus($status, false);
            return $status;
        }

        $visitItems = [];
        foreach ($visits as $visit) {
            $data = [];
            foreach ($visitsColumnsMap as $solidColumn => $fluentColumn) {
                if ($fluentColumn === null) {
                    continue; // Skip if mapping is null
                }
                if (isset($visit->$solidColumn)) {
                    $data[$fluentColumn] = $visit->$solidColumn;
                }
            }

            // Ensure required fields are set, fallback to null
            $data = array_merge([
                'utm_campaign' => null,
            ], $data);

            $visitItems[] = $data;
            $migratedCount++;
        }

        try {
            // Insert into fa_visits table
            $this->db()->table('fa_visits')->insert($visitItems);
        } catch (\Exception $e) {
            return $status; // Return current status to allow retry
        }

        $status['migrated_visits'] = $migratedCount;
        $this->updateCurrentStatus($status, false);

        if ($this->isTimeLimitExceeded()) {
            return $status;
        }

        return $this->migrateVisits($status);
    }

    public function getCounts()
    {
        $db = $this->db();

        // Count unique WordPress users with WooCommerce orders
        $customerCount = $db->table('wc_orders')
            ->where('type', 'shop_order')
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id') ?: 0;

        $data = [
            'affiliate_groups' => $db->table('solid_affiliate_affiliate_groups')->count() ?: 0,
            'affiliates'       => $db->table('solid_affiliate_affiliates')->count() ?: 0,
            'referrals'        => $db->table('solid_affiliate_referrals')->count() ?: 0,
            'customers'        => $customerCount,
            'payouts'          => $db->table('solid_affiliates_bulk_payouts')->count() ?: 0,
            'visits'           => $db->table('solid_affiliate_visits')->count() ?: 0,
        ];

        return $data;
    }

    public function recountAffiliateEarnings()
    {
        Affiliate::query()->each(function (Affiliate $affiliate) {
            $affiliate->recountEarnings();
        });
    }
}
