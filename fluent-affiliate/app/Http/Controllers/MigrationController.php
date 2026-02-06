<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Meta;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Services\Migrator\Providers\AffiliateWP;
use FluentAffiliate\App\Services\Migrator\Providers\AffiliateManagerMigrator;
use FluentAffiliate\App\Services\Migrator\Providers\SolidAffiliate;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;

class MigrationController extends Controller
{
    protected $migrator = null;

    public function getAvailableMigrations()
    {
        $migrators = [];

        if (defined('AFFILIATEWP_VERSION')) {
            $migrators[] = [
                'name' => 'AffiliateWP',
                'slug' => 'affiliate_wp',
            ];
        }

        // Add Affiliate Manager if plugin is present
        if (defined('WPAM_PLUGIN_FILE')) {
            $migrators[] = [
                'name' => 'Affiliate Manager (Beta)',
                'slug' => 'affiliate_manager',
            ];
        }

        if (defined('SOLID_AFFILIATE_DIR')) {
            $migrators[] = [
                'name' => 'Solid Affiliate (Beta)',
                'slug' => 'solid_affiliate',
            ];
        }

        $migrators = apply_filters('fluent_affiliate/migrators', $migrators);

        $currentDataCounts = $this->getCurrentDataCounts();

        return [
            'migrators'      => $migrators,
            'data_counts'    => $currentDataCounts
        ];
    }

    public function getMigrationStatistics(Request $request)
    {
        $migrator = $request->getSafe('migrator', 'sanitize_text_field', 'affiliate_wp');

        $migrator = $this->getMigrator($migrator);

        $counts = $migrator->getCounts();

        $migrationLogs = [
            'affiliate_groups' => [
                'total'    => $counts['affiliate_groups'] ?? 0,
                'migrated' => AffiliateGroup::count()
            ],
            'affiliates' => [
                'total'    => $counts['affiliates'],
                'migrated' => Affiliate::count()
            ],
            'referrals'  => [
                'total'    => $counts['referrals'],
                'migrated' => Referral::count()
            ],
            'customers'  => [
                'total'    => $counts['customers'],
                'migrated' => Customer::count()
            ],
            'payouts'    => [
                'total'    => $counts['payouts'],
                'migrated' => Payout::count()
            ],
            'visits'     => [
                'total'    => $counts['visits'],
                'migrated' => Visit::count()
            ]
        ];

        $migrationLog = Meta::query()->where('object_type', 'migration')->where('meta_key', 'migration_logs')->first();

        if (!$migrationLog) {
            Meta::create([
                'object_type' => 'migration',
                'object_id'   => null,
                'meta_key'    => 'migration_logs', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key for performance
                'value'       => $migrationLogs
            ]);
        } else {
            $migrationLog->update([
                'value' => $migrationLogs
            ]);
        }

        return [
            'statistics' => $migrationLogs,
        ];
    }

    public function startMigration(Request $request)
    {
        $migrator = $request->getSafe('migrator', 'sanitize_text_field', 'affiliate_wp');

        $migrator = $this->getMigrator($migrator);

        if (!$migrator) {
            return $this->sendError([
                'message' => __('Migrator not found', 'fluent-affiliate')
            ], 422);
        }

        if ($request->get('reset_migration') === 'yes') {
            $migrator->updateCurrentStatus([], false);
            $this->wipeCurrentData();
        }

        $previousStatus = $migrator->getCurrentStatus();

        $previousStatus['current_stage'] = 'affiliate_groups';

        $currentStatus = $migrator->updateCurrentStatus($previousStatus);

        return [
            'current_status' => $currentStatus
        ];
    }

    public function getPollingStatus(Request $request)
    {
        $migrator = $request->getSafe('migrator', 'sanitize_text_field', 'affiliate_wp');

        $migrator = $this->getMigrator($migrator);

        $migrator->setTimeLimit(Utility::getMaxRunTime());

        $status = $migrator->getCurrentStatus();

        $currentStep = Arr::get($status, 'current_stage', 'affiliate_groups');

        $validStages = ['affiliate_groups', 'affiliates', 'referrals', 'customers', 'payouts', 'visits', 'completed'];

        if (!in_array($currentStep, $validStages)) {
            return $this->sendError([
                'message' => 'Invalid stage. Please start the migration again.'
            ]);
        }

        if ($currentStep == 'affiliate_groups') {
            return $migrator->migrateAffiliateGroups($status);
        }

        if ($currentStep == 'affiliates') {
            return $migrator->migrateAffiliates($status);
        }

        if ($currentStep == 'referrals') {
            return $migrator->migrateReferrals($status);
        }

        if ($currentStep == 'customers') {
            return $migrator->migrateCustomers($status);
        }

        if ($currentStep == 'visits') {
            return $migrator->migrateVisits($status);
        }

        if ($currentStep == 'payouts') {
            return $migrator->migratePayouts($status);
        }

        return $migrator->getCurrentStatus();
    }

    public function getCurrentDataCounts()
    {
        $data = [
            'affiliate_groups' => AffiliateGroup::count(),
            'affiliates'       => Affiliate::count(),
            'referrals'        => Referral::count(),
            'visits'          => Visit::count(),
            'payouts'         => Payout::count(),
            'customers'       => Customer::count()
        ];

        return $data;
    }

    public function wipeCurrentData()
    {
        Helper::dbTransaction(function() {
            Affiliate::truncate();
            AffiliateGroup::truncate();
            Referral::truncate();
            Visit::truncate();
            Payout::truncate();
            Customer::truncate();
            Transaction::truncate();
        });

        update_option('_fla_affwp_migrations_status', []);
        delete_option('_fla_solid_affiliate_migrations_status');

        return [
            'message' => __('Data wiped successfully', 'fluent-affiliate')
        ];
    }

    private function getMigrator($migrator)
    {
        if ($this->migrator) {
            return $this->migrator;
        }

        $migratorClasses = [
            'affiliate_wp' => AffiliateWP::class,
            'affiliate_manager' => AffiliateManagerMigrator::class,
            'solid_affiliate' => SolidAffiliate::class,
        ];

        if (!isset($migratorClasses[$migrator])) {
            return $this->sendError([
                'message' => __('Migrator not found', 'fluent-affiliate')
            ], 422);
        }

        $this->migrator = new $migratorClasses[$migrator]();

        return $this->migrator;
    }
}
