<?php

namespace FluentAffiliate\App\Services\Migrator\CLI;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;

/**
 * Temporary CLI Migration Class for Affiliate Manager
 *
 * This class handles the complete migration from Affiliate Manager to FluentAffiliate
 * via WP-CLI. It includes all critical fixes identified in the migration analysis.
 *
 * @package FluentAffiliate\App\Services\Migrator\CLI
 * @since 1.0.0
 */
class AffiliateManagerMigrationCLI
{
    /**
     * Database instance
     * @var \FluentAffiliate\Framework\Database\Orm\Builder
     */
    protected $db;

    /**
     * Migration start time
     * @var int
     */
    protected $startTime;

    /**
     * Batch size for processing
     * @var int
     */
    protected $batchSize = 100;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = \FluentAffiliate\App\Helper\Utility::getApp('db');
        $this->startTime = time();
    }

    /**
     * Main migration orchestrator
     * 
     * @return void
     */
    public function migrate()
    {
        $this->logInfo('=================================================');
        $this->logInfo('Affiliate Manager to FluentAffiliate Migration');
        $this->logInfo('=================================================');
        
        // Display source data statistics
        $this->displayStats();
        
        // Confirm migration
        if (!$this->confirmMigration()) {
            $this->logWarning('Migration cancelled by user.');
            return;
        }
        
        $this->logInfo('Starting migration...');
        
        // Truncate existing data if any
        $this->truncateTables();
        
        // Run migrations in sequence
        $this->migrateAffiliates();
        $this->migrateReferrals();
        $this->migrateCustomers();
        $this->migratePayouts();
        $this->migrateVisits();
        
        // Post-migration tasks
        $this->linkCustomersToReferrals();
        $this->linkVisitsToReferrals();
        $this->syncAffiliateData();
        $this->recountEarnings();
        
        $this->logSuccess('=================================================');
        $this->logSuccess('Migration completed successfully!');
        $this->logSuccess('=================================================');
        
        // Display final statistics
        $this->displayFinalStats();
    }

    /**
     * Display source data statistics
     * 
     * @return void
     */
    protected function displayStats()
    {
        $stats = [
            [
                'title' => 'Total Affiliates',
                'count' => $this->db->table('wpam_affiliates')->count() ?: 0
            ],
            [
                'title' => 'Standalone Affiliates (no WP user)',
                'count' => $this->db->table('wpam_affiliates')->whereNull('userId')->count() ?: 0
            ],
            [
                'title' => 'Total Referrals',
                'count' => $this->db->table('wpam_transactions')->whereIn('type', ['credit', 'refund', 'adjustment'])->count() ?: 0
            ],
            [
                'title' => 'Total Customers',
                'count' => $this->db->table('users')
                    ->join('usermeta as um', 'users.ID', '=', 'um.user_id')
                    ->where('um.meta_key', 'wp_capabilities')
                    ->where('um.meta_value', 'like', '%customer%')
                    ->count() ?: 0
            ],
            [
                'title' => 'Total Payouts',
                'count' => $this->db->table('wpam_transactions')->where('type', 'payout')->count() ?: 0
            ],
            [
                'title' => 'Total Visits',
                'count' => $this->db->table('wpam_tracking_tokens')->count() ?: 0
            ]
        ];

        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);
    }

    /**
     * Display final migration statistics
     * 
     * @return void
     */
    protected function displayFinalStats()
    {
        $stats = [
            [
                'title' => 'Migrated Affiliates',
                'count' => Affiliate::count()
            ],
            [
                'title' => 'Affiliates with Tracking Keys',
                'count' => Affiliate::whereNotNull('custom_param')->where('custom_param', '!=', '')->count()
            ],
            [
                'title' => 'Migrated Referrals',
                'count' => Referral::count()
            ],
            [
                'title' => 'Referrals with Visit Link',
                'count' => Referral::whereNotNull('visit_id')->count()
            ],
            [
                'title' => 'Migrated Customers',
                'count' => Customer::count()
            ],
            [
                'title' => 'Migrated Payouts',
                'count' => Payout::count()
            ],
            [
                'title' => 'Migrated Payout Transactions',
                'count' => Transaction::count()
            ],
            [
                'title' => 'Migrated Visits',
                'count' => Visit::count()
            ]
        ];

        $this->logInfo('');
        $this->logInfo('Final Statistics:');
        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);
        
        $duration = time() - $this->startTime;
        $this->logInfo(sprintf('Total migration time: %d seconds', $duration));
    }

    /**
     * Confirm migration with user
     * 
     * @return bool
     */
    protected function confirmMigration()
    {
        \WP_CLI::confirm('Are you sure you want to migrate from Affiliate Manager to Fluent Affiliate? This will truncate existing FluentAffiliate data.');
        return true;
    }

    /**
     * Truncate FluentAffiliate tables
     *
     * @return void
     */
    protected function truncateTables()
    {
        $this->logInfo('Truncating existing FluentAffiliate data...');

        // Always reset migration options, regardless of table state
        fluentAffiliate_update_option('affiliate_manager_migrated_affiliates', 0);
        fluentAffiliate_update_option('affiliate_manager_migrated_referrals', 0);
        fluentAffiliate_update_option('affiliate_manager_migrated_customers', 0);
        fluentAffiliate_update_option('affiliate_manager_migrated_payouts', 0);
        fluentAffiliate_update_option('affiliate_manager_migrated_visits', 0);

        if (Affiliate::count()) {
            Affiliate::query()->truncate();
            $this->logInfo('- Affiliates table truncated');
        }

        if (Referral::count()) {
            Referral::query()->truncate();
            $this->logInfo('- Referrals table truncated');
        }

        if (Customer::count()) {
            Customer::query()->truncate();
            $this->logInfo('- Customers table truncated');
        }

        if (Payout::count()) {
            Payout::query()->truncate();
            Transaction::query()->truncate();
            $this->logInfo('- Payouts and transactions tables truncated');
        }

        if (Visit::count()) {
            Visit::query()->truncate();
            $this->logInfo('- Visits table truncated');
        }
    }

    /**
     * Migrate affiliates with all fixes applied
     * 
     * @return void
     */
    public function migrateAffiliates()
    {
        $this->logInfo('');
        $this->logInfo('Migrating affiliates...');

        $migratedCount = fluentAffiliate_get_option('affiliate_manager_migrated_affiliates', 0);

        $affiliateStatusMap = [
            'applied'   => 'pending',
            'declined'  => 'inactive',
            'approved'  => 'active',
            'active'    => 'active',
            'inactive'  => 'inactive',
            'confirmed' => 'active',
            'blocked'   => 'inactive',
        ];

        $affiliates = $this->db->table('wpam_affiliates')
            ->select([
                'affiliateId',
                'userId',
                'status',
                'email',
                'bountyType',
                'bountyAmount',
                'dateCreated',
                'uniqueRefKey'  // FIX #2: Add tracking key to SELECT
            ])
            ->orderBy('affiliateId', 'ASC')
            ->offset($migratedCount)
            ->limit($this->batchSize)
            ->get();

        if ($affiliates->isEmpty()) {
            $this->logSuccess(sprintf('Total %d affiliates migrated', $migratedCount));
            return;
        }

        $dataToInsert = [];
        $usersCreated = 0;

        foreach ($affiliates as $affiliate) {
            // FIX #3: User creation logic - check for existing user by email first
            if (empty($affiliate->userId)) {
                $existingUser = get_user_by('email', $affiliate->email);
                
                if ($existingUser) {
                    // Use existing user
                    $affiliate->userId = $existingUser->ID;
                    $this->logInfo(sprintf('  - Using existing user for affiliate %s (email: %s)', $affiliate->affiliateId, $affiliate->email));
                } else {
                    // Create new user
                    $userId = wp_create_user(
                        $affiliate->email,
                        wp_generate_password(12, true, true),
                        $affiliate->email
                    );
                    
                    if (!is_wp_error($userId)) {
                        $affiliate->userId = $userId;
                        $user = new \WP_User($userId);
                        $user->set_role('subscriber');
                        $usersCreated++;
                        $this->logInfo(sprintf('  - Created new user for affiliate %s (email: %s)', $affiliate->affiliateId, $affiliate->email));
                    } else {
                        $this->logWarning(sprintf('  - Could not create user for affiliate %s: %s', $affiliate->affiliateId, $userId->get_error_message()));
                        continue; // Skip this affiliate
                    }
                }
            }

            $data = [
                'user_id'       => $affiliate->userId,
                'status'        => $affiliateStatusMap[$affiliate->status] ?? 'pending',
                'payment_email' => $affiliate->email,
                'rate_type'     => $affiliate->bountyType === 'percent' ? 'percentage' : 'flat',
                'rate'          => $affiliate->bountyAmount,
                'custom_param'  => substr($affiliate->uniqueRefKey ?? '', 0, 100), // FIX #3: Map tracking key
                'note'          => 'Migrated from Affiliate Manager',
                'total_earnings'  => 0,
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0,
                'created_at'    => $affiliate->dateCreated,
                'updated_at'    => $affiliate->dateCreated,
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        try {
            $this->db->table('fa_affiliates')->insert($dataToInsert);
            fluentAffiliate_update_option('affiliate_manager_migrated_affiliates', $migratedCount);
            $this->logInfo(sprintf('Migrated %d affiliates (created %d new users)...', $migratedCount, $usersCreated));
        } catch (\Exception $e) {
            $this->logError('Error migrating affiliates: ' . $e->getMessage());
            return;
        }

        // Continue recursively
        $this->migrateAffiliates();
    }

    /**
     * Migrate referrals with adjustment transaction type fix
     *
     * @return void
     */
    public function migrateReferrals()
    {
        $this->logInfo('');
        $this->logInfo('Migrating referrals...');

        $migratedCount = fluentAffiliate_get_option('affiliate_manager_migrated_referrals', 0);

        $referralStatusMap = [
            'pending'   => 'unpaid',
            'confirmed' => 'unpaid',  // Changed: confirmed means transaction confirmed, not paid out
            'failed'    => 'rejected',
            'refund'    => 'rejected'
        ];

        // FIX #4: Add 'adjustment' to transaction types
        $referrals = $this->db->table('wpam_transactions')
            ->whereIn('type', ['credit', 'refund', 'adjustment'])
            ->orderBy('transactionId', 'ASC')
            ->offset($migratedCount)
            ->limit($this->batchSize)
            ->get();

        if ($referrals->isEmpty()) {
            $this->logSuccess(sprintf('Total %d referrals migrated', $migratedCount));
            return;
        }

        $dataToInsert = [];

        foreach ($referrals as $referral) {
            $status = 'unpaid';

            // Determine status based on type and status
            // Note: In Affiliate Manager, 'confirmed' means transaction is confirmed,
            // NOT that it has been paid out. Payout status is determined later.
            if ($referral->type === 'refund') {
                $status = 'rejected';
            } elseif (isset($referral->status)) {
                $status = $referralStatusMap[$referral->status] ?? 'unpaid';
            }

            $data = [
                'id'          => $referral->transactionId,
                'affiliate_id' => $referral->affiliateId,
                'amount'      => $referral->amount,
                'description' => $referral->description,
                'provider_id' => $referral->referenceId,
                'status'      => $status,
                'type'        => 'sale',
                'provider'    => 'woo',
                'currency'    => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
                'created_at'  => $referral->dateCreated,
                'updated_at'  => $referral->dateModified ?? $referral->dateCreated,
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        try {
            $this->db->table('fa_referrals')->insert($dataToInsert);
            fluentAffiliate_update_option('affiliate_manager_migrated_referrals', $migratedCount);
            $this->logInfo(sprintf('Migrated %d referrals...', $migratedCount));
        } catch (\Exception $e) {
            $this->logError('Error migrating referrals: ' . $e->getMessage());
            return;
        }

        // Continue recursively
        $this->migrateReferrals();
    }

    /**
     * Migrate customers from referral transactions
     *
     * Extracts unique customer emails from Affiliate Manager transactions
     * and creates customer records linked to their referring affiliate.
     *
     * @return void
     */
    public function migrateCustomers()
    {
        $this->logInfo('');
        $this->logInfo('Migrating customers from referrals...');

        $migratedCount = fluentAffiliate_get_option('affiliate_manager_migrated_customers', 0);

        // Get unique customer emails from transactions with their affiliate and earliest date
        $customers = $this->db->table('wpam_transactions')
            ->select([
                'email',
                'affiliateId as affiliate_id',
                $this->db->raw('MIN(dateCreated) as first_referral_date')
            ])
            ->whereIn('type', ['credit', 'refund', 'adjustment'])
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('email', 'affiliateId')
            ->orderBy('first_referral_date', 'ASC')
            ->offset($migratedCount)
            ->limit($this->batchSize)
            ->get();

        if ($customers->isEmpty()) {
            $this->logSuccess(sprintf('Total %d customers migrated', $migratedCount));
            return;
        }

        $dataToInsert = [];
        $processedEmails = [];

        foreach ($customers as $customer) {
            // Skip if we've already processed this email (in case of multiple affiliates)
            if (in_array($customer->email, $processedEmails)) {
                continue;
            }
            $processedEmails[] = $customer->email;

            // Check if WordPress user exists with this email
            $wpUser = get_user_by('email', $customer->email);
            $userId = $wpUser ? $wpUser->ID : null;

            // Extract name from email or WP user
            $firstName = '';
            $lastName = '';

            if ($wpUser) {
                $firstName = $wpUser->first_name ?: '';
                $lastName = $wpUser->last_name ?: '';

                // If no first/last name, try display_name
                if (empty($firstName) && empty($lastName)) {
                    $parts = explode(' ', $wpUser->display_name, 2);
                    $firstName = $parts[0] ?? '';
                    $lastName = $parts[1] ?? '';
                }
            }

            // If still no name, extract from email
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
                'settings'        => '',
                'created_at'      => $customer->first_referral_date,
                'updated_at'      => $customer->first_referral_date,
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        if (empty($dataToInsert)) {
            $this->logSuccess(sprintf('Total %d customers migrated', $migratedCount));
            return;
        }

        try {
            $this->db->table('fa_customers')->insert($dataToInsert);
            fluentAffiliate_update_option('affiliate_manager_migrated_customers', $migratedCount);
            $this->logInfo(sprintf('Migrated %d customers...', count($dataToInsert)));
        } catch (\Exception $e) {
            $this->logError('Error migrating customers: ' . $e->getMessage());
            return;
        }

        // Continue recursively
        $this->migrateCustomers();
    }

    /**
     * Migrate payouts with correct dates
     *
     * @return void
     */
    public function migratePayouts()
    {
        $this->logInfo('');
        $this->logInfo('Migrating payouts...');

        $migratedCount = fluentAffiliate_get_option('affiliate_manager_migrated_payouts', 0);

        $transactions = $this->db->table('wpam_transactions')
            ->where('type', 'payout')
            ->selectRaw('transactionId, dateCreated, dateModified, affiliateId, ABS(amount) as amount')
            ->orderBy('transactionId', 'ASC')
            ->offset($migratedCount)
            ->limit($this->batchSize)
            ->get();

        if ($transactions->isEmpty()) {
            $this->logSuccess(sprintf('Total %d payouts migrated', $migratedCount));
            return;
        }

        // Group transactions by affiliate
        $affiliatePayouts = [];

        foreach ($transactions as $transaction) {
            $affiliateId = $transaction->affiliateId;

            if (!isset($affiliatePayouts[$affiliateId])) {
                $affiliatePayouts[$affiliateId] = [
                    'created_by'    => get_current_user_id(),
                    'total_amount'  => 0,
                    'payout_method' => 'manual',
                    'status'        => 'paid',
                    'currency'      => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
                    'title'         => sprintf('Affiliate payout for affiliate %s', $affiliateId),
                    'description'   => sprintf('Affiliate payout migrated from Affiliate Manager for affiliate %s', $affiliateId),
                    'created_at'    => $transaction->dateCreated,
                    'updated_at'    => $transaction->dateModified,
                    'transactions'  => []
                ];
            }

            $affiliatePayouts[$affiliateId]['total_amount'] += $transaction->amount;

            $transactionData = [
                'affiliate_id'  => $affiliateId,
                'total_amount'  => $transaction->amount,
                'status'        => 'paid',
                'currency'      => $affiliatePayouts[$affiliateId]['currency'],
                'payout_method' => 'manual',
                'created_at'    => $transaction->dateCreated,
                'updated_at'    => $transaction->dateModified,
            ];

            $affiliatePayouts[$affiliateId]['transactions'][] = $transactionData;
            $migratedCount++;
        }

        $payouts = array_values($affiliatePayouts);

        foreach ($payouts as $payout) {
            $transactions = $payout['transactions'];
            $currentPayout = [
                'created_by'    => $payout['created_by'],
                'total_amount'  => $payout['total_amount'],
                'payout_method' => $payout['payout_method'],
                'status'        => $payout['status'],
                'currency'      => $payout['currency'],
                'title'         => $payout['title'],
                'description'   => $payout['description'],
                'created_at'    => $payout['created_at'],
                'updated_at'    => $payout['updated_at']
            ];

            try {
                $payoutId = $this->db->table('fa_payouts')->insertGetId($currentPayout);
                foreach ($transactions as &$transaction) {
                    $transaction['payout_id'] = $payoutId;
                }
                if (!empty($transactions)) {
                    $this->db->table('fa_payout_transactions')->insert($transactions);
                }
            } catch (\Exception $e) {
                $this->logError('Error migrating payouts: ' . $e->getMessage());
                return;
            }
        }

        fluentAffiliate_update_option('affiliate_manager_migrated_payouts', $migratedCount);
        $this->logInfo(sprintf('Migrated %d payout transactions...', $migratedCount));

        // Continue recursively
        $this->migratePayouts();
    }

    /**
     * Migrate visits
     *
     * @return void
     */
    public function migrateVisits()
    {
        $this->logInfo('');
        $this->logInfo('Migrating visits...');

        $migratedCount = fluentAffiliate_get_option('affiliate_manager_migrated_visits', 0);

        $visits = $this->db->table('wpam_tracking_tokens')
            ->orderBy('trackingTokenId', 'ASC')
            ->offset($migratedCount)
            ->limit($this->batchSize)
            ->get();

        if ($visits->isEmpty()) {
            $this->logSuccess(sprintf('Total %d visits migrated', $migratedCount));
            return;
        }

        $dataToInsert = [];

        foreach ($visits as $visit) {
            $data = [
                'id'            => $visit->trackingTokenId,
                'affiliate_id'  => $visit->sourceAffiliateId,
                'ip'            => $visit->ipAddress,
                'referrer'      => $visit->referer,
                'url'           => get_bloginfo('url'),
                'utm_campaign'  => '',
                'utm_medium'    => '',
                'utm_source'    => '',
                'referral_id'   => null,
                'created_at'    => $visit->dateCreated,
                'updated_at'    => $visit->dateCreated,
            ];

            $dataToInsert[] = $data;
            $migratedCount++;
        }

        try {
            $this->db->table('fa_visits')->insert($dataToInsert);
            fluentAffiliate_update_option('affiliate_manager_migrated_visits', $migratedCount);
            $this->logInfo(sprintf('Migrated %d visits...', $migratedCount));
        } catch (\Exception $e) {
            $this->logError('Error migrating visits: ' . $e->getMessage());
            return;
        }

        // Continue recursively
        $this->migrateVisits();
    }

    /**
     * Link customers to referrals based on email addresses
     *
     * Matches customers to referrals by looking up the original transaction email
     * from Affiliate Manager and linking them to the migrated referrals.
     *
     * @return void
     */
    public function linkCustomersToReferrals()
    {
        $this->logInfo('');
        $this->logInfo('Linking customers to referrals...');

        $linkedCount = 0;

        // Get all customers
        $customers = Customer::query()->get();

        foreach ($customers as $customer) {
            // Get transaction IDs from Affiliate Manager that match this customer's email
            $transactionIds = $this->db->table('wpam_transactions')
                ->select('transactionId')
                ->where('email', $customer->email)
                ->where('affiliateId', $customer->by_affiliate_id)
                ->whereIn('type', ['credit', 'refund', 'adjustment'])
                ->pluck('transactionId');

            if (empty($transactionIds) || count($transactionIds) === 0) {
                continue;
            }

            // Convert Collection to array
            $ids = [];
            foreach ($transactionIds as $id) {
                $ids[] = $id;
            }

            // Update referrals that were created from these transactions
            // We stored the original transaction ID as the referral ID during migration
            $updated = $this->db->table('fa_referrals')
                ->whereIn('id', $ids)
                ->where('affiliate_id', $customer->by_affiliate_id)
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id]);

            if ($updated) {
                $linkedCount += $updated;
            }
        }

        $this->logSuccess(sprintf('Linked %d referrals to customers', $linkedCount));
    }

    /**
     * FIX #5: Link visits to referrals using purchase logs or affiliate/timing matching
     *
     * @return void
     */
    public function linkVisitsToReferrals()
    {
        $this->logInfo('');
        $this->logInfo('Linking visits to referrals...');

        $linkedCount = 0;

        // Strategy 1: Try using purchase logs table if it exists and has data
        try {
            $purchaseLogs = $this->db->table('wpam_tracking_tokens_purchase_logs')->get();

            if (!empty($purchaseLogs) && count($purchaseLogs) > 0) {
                $this->logInfo('Using purchase logs to link visits...');

                    foreach ($purchaseLogs as $log) {
                        // trackingTokenId = visit ID
                        // purchaseLogId = order ID (stored as provider_id in referrals)

                        $updated = $this->db->table('fa_referrals')
                            ->where('provider_id', $log->purchaseLogId)
                            ->update(['visit_id' => $log->trackingTokenId]);

                        if ($updated) {
                            $linkedCount++;
                        }
                    }

                $this->logSuccess(sprintf('Linked %d visits to referrals using purchase logs', $linkedCount));
                return;
            } else {
                $this->logInfo('Purchase logs table exists but is empty');
            }
        } catch (\Exception $e) {
            $this->logWarning('Could not read purchase logs: ' . $e->getMessage());
        }

        // Strategy 2: Link by matching affiliate and timing (most recent visit before referral)
        $this->logInfo('Purchase logs empty or unavailable. Using affiliate/timing matching...');

        // Get all referrals that need visit linkage
        $referrals = $this->db->table('fa_referrals')
            ->whereNull('visit_id')
            ->orderBy('created_at', 'ASC')
            ->get();

        if (empty($referrals)) {
            $this->logInfo('No referrals need visit linkage');
            return;
        }

        foreach ($referrals as $referral) {
            // Find the most recent visit for this affiliate before or at the referral time
            $visit = $this->db->table('fa_visits')
                ->where('affiliate_id', $referral->affiliate_id)
                ->where('created_at', '<=', $referral->created_at)
                ->whereNull('referral_id') // Only link visits that haven't been linked yet
                ->orderBy('created_at', 'DESC')
                ->first();

            if ($visit) {
                // Link this visit to the referral
                $this->db->table('fa_referrals')
                    ->where('id', $referral->id)
                    ->update(['visit_id' => $visit->id]);

                // Mark the visit as linked
                $this->db->table('fa_visits')
                    ->where('id', $visit->id)
                    ->update(['referral_id' => $referral->id]);

                $linkedCount++;
            }
        }

        $this->logSuccess(sprintf('Linked %d visits to referrals using affiliate/timing matching', $linkedCount));
    }

    /**
     * FIX #6: Sync affiliate data with memory optimization
     * Links referrals to payouts based on timing (only referrals created before payout)
     *
     * @return void
     */
    public function syncAffiliateData()
    {
        $this->logInfo('');
        $this->logInfo('Syncing affiliate data...');

        $syncedCount = 0;
        $linkedReferralsCount = 0;

        // Get all payout transactions with their dates
        $payoutTransactions = $this->db->table('fa_payout_transactions')
            ->orderBy('created_at', 'ASC')
            ->get();

        if (empty($payoutTransactions)) {
            $this->logInfo('No payout transactions to sync');
            return;
        }

        foreach ($payoutTransactions as $transaction) {
            // Find all unpaid referrals for this affiliate created BEFORE or AT the payout date
            // that haven't been linked to a payout yet
            // Note: We look for 'unpaid' status because referrals are initially migrated as unpaid
            $referrals = $this->db->table('fa_referrals')
                ->where('affiliate_id', $transaction->affiliate_id)
                ->where('status', 'unpaid')
                ->where('created_at', '<=', $transaction->created_at)
                ->whereNull('payout_id')
                ->get();

            if (!empty($referrals)) {
                // Convert Collection to array and extract IDs
                $referralIds = [];
                foreach ($referrals as $referral) {
                    $referralIds[] = $referral->id;
                }

                // Link these referrals to the payout and mark them as paid
                $this->db->table('fa_referrals')
                    ->whereIn('id', $referralIds)
                    ->update([
                        'payout_id' => $transaction->payout_id,
                        'payout_transaction_id' => $transaction->id,
                        'status' => 'paid'  // Mark as paid when linked to payout
                    ]);

                $linkedReferralsCount += count($referralIds);
            }

            $syncedCount++;
        }

        $this->logSuccess(sprintf('Synced %d payout transactions, linked %d referrals', $syncedCount, $linkedReferralsCount));
    }

    /**
     * Recount earnings for all affiliates
     *
     * @return void
     */
    public function recountEarnings()
    {
        $this->logInfo('');
        $this->logInfo('Recounting earnings...');

        $recountedCount = 0;

        // Use chunk() to prevent memory exhaustion
        Affiliate::query()->chunk(100, function ($affiliates) use (&$recountedCount) {
            foreach ($affiliates as $affiliate) {
                $affiliate->recountEarnings();
                $recountedCount++;
            }
        });

        $this->logSuccess(sprintf('Recounted earnings for %d affiliates', $recountedCount));
    }

    /**
     * Log info message
     *
     * @param string $message
     * @return void
     */
    protected function logInfo($message)
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::log($message);
        }
    }

    /**
     * Log success message
     *
     * @param string $message
     * @return void
     */
    protected function logSuccess($message)
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::success($message);
        }
    }

    /**
     * Log warning message
     *
     * @param string $message
     * @return void
     */
    protected function logWarning($message)
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::warning($message);
        }
    }

    /**
     * Log error message
     *
     * @param string $message
     * @return void
     */
    protected function logError($message)
    {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::error($message);
        }
    }
}

