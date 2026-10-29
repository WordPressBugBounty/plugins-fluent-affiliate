<?php

namespace FluentAffiliate\App\Hooks\CLI;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Services\Migrator\BaseMigrator;
use FluentAffiliate\Database\DBSeeder;
use FluentAffiliate\Database\Seeder\AffiliateSeeder;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliatePro\App\Services\Integrations\WooCommerce\Bootstrap;

class Commands
{
    private $sliceWPGroupIdMap = null;

    public function migrate_from_affiliatewp()
    {

        if (!class_exists('\Affiliate_WP')) {
            \WP_CLI::error("AffiliateWP is not installed or activated. Please install and activate AffiliateWP before running this command.");
        }

        $migrator = new \FluentAffiliate\App\Services\Migrator\Providers\AffiliateWP();

        $this->printSourceCounts($migrator->getCounts(), [
            'affiliates' => 'Total Affiliates',
            'referrals'  => 'Total Referrals',
            'payouts'    => 'Total Payouts',
            'visits'     => 'Total Visits',
            'customers'  => 'Total Affiliated Customers',
        ]);

        // Ask if user wants to continue with WP CLI
        \WP_CLI::confirm("Are you sure you want to migrate?");

        // Show start migrating message
        \WP_CLI::log("Starting migration...");

        // This command has always replaced rather than merged.
        Affiliate::truncate();
        BaseMigrator::clearAffiliateIdMaps();
        Referral::truncate();
        Customer::truncate();
        Payout::truncate();
        Transaction::truncate();
        Visit::truncate();

        $this->runMigratorStages($migrator);

        \WP_CLI::success('AffiliateWP migration completed.');
    }

    public function recount_earnings()
    {
        // Keyset cursor: stores the last processed affiliate id (not a row
        // offset) so it shares the same semantics as the provider migrators
        // that read/write the same affwp_migrated_recount option.
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            // reset, so the next run recounts every affiliate again instead of
            // finishing at once from the end of this one
            fluentAffiliate_update_option('affwp_migrated_recount', 0);
            \WP_CLI::log("Affiliate recount done");
            return $lastId;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
            fluentAffiliate_update_option('affwp_migrated_recount', $lastId);
        }

        \WP_CLI::log(sprintf("Recounted affiliates up to #%d.....", $lastId));

        $this->recount_earnings();

    }

    /**
     * Returns Seed FluentAffiliate fake data to view.
     * @return void
     */
    public function seed()
    {
        if (!defined('FLUENT_AFFILIATE_DEV_MODE')) {
            \WP_CLI::error("FLUENT_AFFILIATE_DEV_MODE is not active.");
        }

        DBSeeder::run();
    }

    public function freshSeed()
    {
        // truncates the affiliate data, so never on a site that is not in dev mode
        if (!defined('FLUENT_AFFILIATE_DEV_MODE')) {
            \WP_CLI::error("FLUENT_AFFILIATE_DEV_MODE is not active.");
        }

        (new AffiliateSeeder())->freshSeed();
    }

    public function seed_affiliates($args, $assocArgs)
    {
        if (!defined('FLUENT_AFFILIATE_DEV_MODE')) {
            \WP_CLI::error("FLUENT_AFFILIATE_DEV_MODE is not active.");
        }

        $count = Arr::get($assocArgs, 'count', 100);

        if (!is_numeric($count) || $count < 1) {
            \WP_CLI::error("Please provide a valid count.");
        }

        $users = User::query()->whereDoesntHave('affiliate')
            ->limit($count * 2)
            ->inRandomOrder()
            ->get();

        if ($users->isEmpty()) {
            \WP_CLI::error("No users found to seed affiliates.");
        }

        $faker = \Faker\Factory::create('en_US');

        $created = 0;

        foreach ($users as $index => $user) {
            if ($index % 2 === 0) {
                continue;
            }

            $extraData = [
                'payment_email' => $faker->email(),
                'status'        => 'active',
            ];

            $affiliate = $user->syncAffiliateProfile($extraData);

            $affiliate->created_at = $faker->dateTimeBetween('-1 year', 'now');
            $affiliate->save();

            $created++;
        }

        \WP_CLI::success(sprintf("Seeded %d affiliates successfully.", $created));
    }

    public function seed_woo_referrals($args, $assocArgs)
    {
        if (!defined('FLUENT_AFFILIATE_DEV_MODE')) {
            \WP_CLI::error("FLUENT_AFFILIATE_DEV_MODE is not active.");
        }

        $count = Arr::get($assocArgs, 'count', 100);

        if (!is_numeric($count) || $count < 1) {
            \WP_CLI::error("Please provide a valid count.");
        }

        $db = Utility::getApp('db');

        $orders = $db->table('wc_orders')
            ->leftJoin('fa_referrals', 'wc_orders.id', '=', 'fa_referrals.provider_id')
            ->whereNull('fa_referrals.provider_id')
            ->select('wc_orders.id')
            ->limit($count)
            ->inRandomOrder()
            ->get();

        $faker = \Faker\Factory::create('en_US');

        function getRandomWPUrl()
        {
            $random_post = new \WP_Query(array(
                'post_type'      => 'any',
                'posts_per_page' => 1,
                'orderby'        => 'rand',
            ));

            $url = '';

            if ($random_post->have_posts()) {
                while ($random_post->have_posts()) {
                    $random_post->the_post();
                    $url = get_permalink();
                }
                wp_reset_postdata();
            } else {
                $url = home_url();
            }

            if (!$url) {
                $url = home_url();
            }

            return $url;
        }


        // add progress bar
        \WP_CLI::line(sprintf("Seeding %d WooCommerce referrals...", count($orders)));
        \WP_CLI::line("This may take a while, please be patient...");
        $progress = \WP_CLI\Utils\make_progress_bar('Seeding WooCommerce Referrals', count($orders));

        $wooProvider = new Bootstrap();
        foreach ($orders as $order) {
            $progress->tick();
            $order = wc_get_order($order->id);
            if (!$order || !$order->get_id()) {
                continue;
            }

            $affiliate = Affiliate::query()
                ->inRandomOrder()
                ->first();

            // create 3 visit for this affiliate
            $count = wp_rand(3, 10);
            $totalCount = $count;
            $visit = null;
            while ($count) {
                $visit = Visit::create([
                    'affiliate_id' => $affiliate->id,
                    'url'          => getRandomWPUrl(),
                    'referrer'     => $faker->url(),
                    'utm_campaign' => $faker->word(),
                    'referral_id'  => null,
                    'utm_medium'   => $faker->word(),
                    'utm_source'   => $faker->word(),
                    'ip'           => $faker->ipv4(),
                    'user_id'      => $order->get_user_id()
                ]);
                $visit->created_at = $order->get_date_created();
                $visit->save();
                $count--;
            }

            // update the affiliate visits count
            $affiliate->visits = $affiliate->visits + $totalCount;
            $affiliate->save();

            $customerData = array_filter([
                'user_id'    => $order->get_user_id(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'ip'         => $order->get_customer_ip_address()
            ]);

            if (empty($customerData['email'])) {
                continue; // skip if email is empty
            }

            $customerData['by_affiliate_id'] = $affiliate->id;
            $affiliatedCustomer = $wooProvider->addOrUpdateCustomer($customerData);

            $orderData = $wooProvider->getFormattedOrderData($order);

            $orderTotal = $orderData['referral_order_total'];
            $orderData['order_total'] = $orderTotal;
            $commissionAmount = $wooProvider->calculateFinalCommissionAmount($affiliate, $orderData, 'product_cat');

            $formattedItems = Arr::get($orderData, 'items');
            // create a description for the order
            $description = $formattedItems[0]['title'] ?? 'Order';
            if (count($formattedItems) > 1) {
                $description .= ' and ' . (count($formattedItems) - 1) . ' more items';
            }

            $status = 'pending';
            if (in_array($orderData['status'], wc_get_is_paid_statuses())) {
                $status = 'unpaid';
            }

            $referralData = [
                'affiliate_id' => $affiliate->id,
                'customer_id'  => $affiliatedCustomer->id,
                'visit_id'     => ($visit) ? $visit->id : null,
                'description'  => $description,
                'status'       => $status,
                'type'         => 'sale',
                'amount'       => $commissionAmount,
                'order_total'  => $orderTotal,
                'currency'     => $order->currency,
                'utm_campaign' => ($visit) ? $visit->utm_campaign : '',
                'provider'     => 'woo',
                'provider_id'  => $orderData['id'],
                'products'     => $formattedItems
            ];

            $referral = $wooProvider->recordReferral($referralData);

            $referral->created_at = $order->get_date_created();
            $referral->updated_at = $order->get_date_created();
            $referral->save();


            $referralLink = Utility::getAdminPageUrl('referrals/' . $referral->id . '/view');
            $order->add_order_note(\sprintf(
                // translators: %1$s: referral link, %2$s: referral amount, %3$s: affiliate name, %4$d: affiliate id
                    __('Referral %1$s for %2$s recorded for %3$s (ID: %4$d).', 'fluent-affiliate'),
                    '<a href="' . $referralLink . '" target="_blank">' . $referral->id . '</a>',
                    get_woocommerce_currency_symbol() . ' ' . $referral->amount,
                    $affiliate->full_name,
                    $affiliate->id
                )
            );

            if ($order->get_status() == 'failed') {
                $wooProvider->rejectReferral($referral);
            }

        }

        $progress->finish();

        \WP_CLI::success(sprintf("Seeded %d WooCommerce referrals successfully.", count($orders)));
    }


    /**
     * Migrate from Solid Affiliate to FluentAffiliate
     *
     * Drives the shared SolidAffiliate provider, the same code path the admin
     * migration screen runs, so the two cannot drift apart again.
     *
     * ## EXAMPLES
     *
     *     wp fluent_affiliate migrate_from_solid_affiliate
     *
     * @when after_wp_load
     */
    public function migrate_from_solid_affiliate()
    {
        if (!defined('SOLID_AFFILIATE_DIR')) {
            \WP_CLI::error('Solid Affiliate is not active. Nothing to migrate.');
            return;
        }

        $migrator = new \FluentAffiliate\App\Services\Migrator\Providers\SolidAffiliate();

        $counts = $migrator->getCounts();

        $labels = [
            'affiliate_groups' => 'Total Affiliate Groups',
            'affiliates'       => 'Total Affiliates',
            'referrals'        => 'Total Referrals',
            'customers'        => 'Total Customers',
            'payouts'          => 'Total Payouts',
            'visits'           => 'Total Visits',
            'creatives'        => 'Total Creatives',
        ];

        $stats = [];
        foreach ($labels as $countKey => $title) {
            $stats[] = [
                'title' => $title,
                'count' => (int) Arr::get($counts, $countKey, 0),
            ];
        }

        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);

        \WP_CLI::confirm('Are you sure you want to migrate from Solid Affiliate? Existing FluentAffiliate data will be replaced.');

        \WP_CLI::log('Starting Solid Affiliate migration...');

        // This command has always replaced rather than merged. The provider
        // preserves Solid's ids, so rows left behind would collide with them.
        AffiliateGroup::truncate();
        Affiliate::truncate();
        BaseMigrator::clearAffiliateIdMaps();
        Referral::truncate();
        Customer::truncate();
        Payout::truncate();
        Transaction::truncate();
        Visit::truncate();

        $this->runMigratorStages($migrator);

        \WP_CLI::success('Solid Affiliate migration completed.');
    }


    /**
     * Migrate from Affiliate Manager to FluentAffiliate
     *
     * Drives the shared AffiliateManagerMigrator provider, the same code path
     * the admin migration screen runs, so the two cannot drift apart.
     *
     * ## EXAMPLES
     *
     *     wp fluent_affiliate migrate_from_affiliate_manager
     *
     * @when after_wp_load
     */
    public function migrate_from_affiliate_manager()
    {
        // the run truncates the FluentAffiliate tables first, so there must be a source to refill them
        if (!defined('WPAM_PLUGIN_FILE')) {
            \WP_CLI::error('Affiliate Manager is not active. Nothing to migrate.');
        }

        $migrator = new \FluentAffiliate\App\Services\Migrator\Providers\AffiliateManagerMigrator();

        $this->printSourceCounts($migrator->getCounts(), [
            'affiliates' => 'Total Affiliates',
            'referrals'  => 'Total Referrals',
            'customers'  => 'Total Customers',
            'payouts'    => 'Total Payouts',
            'visits'     => 'Total Visits',
            'creatives'  => 'Total Creatives',
        ]);

        \WP_CLI::confirm('Are you sure you want to migrate from Affiliate Manager to Fluent Affiliate? This will truncate existing FluentAffiliate data.');

        \WP_CLI::log('Starting Affiliate Manager migration...');

        Affiliate::truncate();
        BaseMigrator::clearAffiliateIdMaps();
        Referral::truncate();
        Customer::truncate();
        Payout::truncate();
        Transaction::truncate();
        Visit::truncate();

        $this->runMigratorStages($migrator);

        \WP_CLI::success('Affiliate Manager migration completed.');
    }

    private function printSourceCounts($counts, $labels)
    {
        $stats = [];
        foreach ($labels as $countKey => $title) {
            $stats[] = [
                'title' => $title,
                'count' => (int) Arr::get($counts, $countKey, 0),
            ];
        }

        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);
    }

    /**
     * Run a migrator provider from its first stage to completion, the way the
     * admin migration screen does over repeated requests.
     */
    private function runMigratorStages($migrator)
    {
        // Start a fresh run: clears every stage cursor and the recount cursor.
        $migrator->updateCurrentStatus([], false);

        $status = $migrator->getCurrentStatus();
        $status['current_stage'] = 'affiliate_groups';
        $migrator->updateCurrentStatus($status, false);

        $stageMethods = [
            'affiliate_groups' => 'migrateAffiliateGroups',
            'affiliates'       => 'migrateAffiliates',
            'referrals'        => 'migrateReferrals',
            'customers'        => 'migrateCustomers',
            'payouts'          => 'migratePayouts',
            'visits'           => 'migrateVisits',
            'creatives'        => 'migrateCreatives',
        ];

        $guard = 0;
        while (true) {
            // Fresh time budget each dispatch; a stage that exceeds it returns
            // without advancing and is resumed from its keyset cursor on the
            // next iteration, so progress is monotonic.
            $migrator->setTimeLimit(Utility::getMaxRunTime());

            $status = $migrator->getCurrentStatus();
            $stage = Arr::get($status, 'current_stage', 'affiliate_groups');

            if ($stage === 'completed') {
                break;
            }

            if (!isset($stageMethods[$stage])) {
                \WP_CLI::error(sprintf('Unknown migration stage "%s". Aborting.', $stage));
                return;
            }

            $method = $stageMethods[$stage];
            $migrator->{$method}($status);

            if (Arr::get($migrator->getCurrentStatus(), 'current_stage', $stage) !== $stage) {
                \WP_CLI::log(sprintf('Stage "%s" done.', $stage));
            }

            // Backstop against an unexpected non-advancing stage.
            if (++$guard > 100000) {
                \WP_CLI::error('Migration exceeded the maximum number of batches; aborting to avoid a loop.');
                return;
            }
        }
    }

    public function migrate_from_ultimate_affiliate()
    {
        if (!defined('UAP_PLUGIN_VER')) {
            \WP_CLI::error('Ultimate Affiliate (UAP) is not active. Nothing to migrate.');
            return;
        }

        // Drive the shared, already-verified UltimateAffiliate provider rather
        // than reimplementing each stage.
        $migrator = new \FluentAffiliate\App\Services\Migrator\Providers\UltimateAffiliate();

        // unlike the other commands this one does not truncate, and the provider
        // skips any source id that is already taken, so it needs an empty site
        if (array_sum((new \FluentAffiliate\App\Http\Controllers\MigrationController())->getCurrentDataCounts())) {
            \WP_CLI::error('FluentAffiliate already has data. Wipe the existing data first, then start the migration.');
        }

        \WP_CLI::log('Starting Ultimate Affiliate migration...');

        $this->runMigratorStages($migrator);

        \WP_CLI::success('Ultimate Affiliate migration completed.');
    }

    public function migrate_from_slicewp()
    {
        $db = FluentAffiliate('db');

        $stats = [
            [
                'title' => 'Total Affiliate Groups',
                'count' => $db->table('slicewp_collections')
                    ->where('object_context', 'affiliate')
                    ->where('type', 'group')
                    ->count()
            ],
            [
                'title' => 'Total Affiliates',
                'count' => $db->table('slicewp_affiliates')->count()
            ],
            [
                'title' => 'Total Referrals',
                'count' => $db->table('slicewp_commissions')->count()
            ],
            [
                'title' => 'Total Customers',
                'count' => $db->table('slicewp_customers')->count()
            ],
            [
                'title' => 'Total Payouts',
                'count' => $db->table('slicewp_payouts')->count()
            ],
            [
                'title' => 'Total Visits',
                'count' => $db->table('slicewp_visits')->count()
            ]
        ];

        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);

        \WP_CLI::confirm('Are you sure you want to migrate from SliceWP?');

        \WP_CLI::log('Starting SliceWP migration...');

        // Migrate affiliate groups
        if (AffiliateGroup::count()) {
            fluentAffiliate_update_option('slicewp_migrated_affiliate_groups', 0);
            AffiliateGroup::truncate();
        }
        $this->migrateSliceWPAffiliateGroups();

        // Migrate affiliates
        if (Affiliate::count()) {
            fluentAffiliate_update_option('slicewp_migrated_affiliates', 0);
            FluentAffiliate('db')->table('fa_affiliates')->truncate();
            BaseMigrator::clearAffiliateIdMaps();
        }
        $this->migrateSliceWPAffiliates();

        // Migrate referrals
        if (Referral::count()) {
            fluentAffiliate_update_option('slicewp_migrated_referrals', 0);
            FluentAffiliate('db')->table('fa_referrals')->truncate();
        }
        $this->migrateSliceWPReferrals();

        // Migrate customers
        if (Customer::count()) {
            fluentAffiliate_update_option('slicewp_migrated_customers', 0);
            FluentAffiliate('db')->table('fa_customers')->truncate();
        }
        $this->migrateSliceWPCustomers();

        // Migrate payouts
        if (Payout::count()) {
            fluentAffiliate_update_option('slicewp_migrated_payout_id', 0);
            Payout::truncate();
            Transaction::truncate();
        }
        $this->migrateSliceWPPayouts();

        // Migrate visits
        if (Visit::count()) {
            fluentAffiliate_update_option('slicewp_migrated_visits', 0);
            FluentAffiliate('db')->table('fa_visits')->truncate();
        }
        $this->migrateSliceWPVisits();

        // Migrate creatives (Pro feature — skipped if table does not exist)
        $this->migrateSliceWPCreatives();

        fluentAffiliate_update_option('slicewp_migrated_recount', 0);
        $this->recountSliceWPEarnings();
    }

    private function migrateSliceWPAffiliateGroups()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_affiliate_groups', 0);

        $db = FluentAffiliate('db');

        $groups = $db->table('slicewp_collections')
            ->where('object_context', 'affiliate')
            ->where('type', 'group')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($groups->isEmpty()) {
            \WP_CLI::log('Affiliate groups migration done');
            return;
        }

        $rateTypeMap = ['percentage' => 'percentage', 'flat' => 'flat'];

        $groupIds = $groups->pluck('id')->toArray();

        $allMeta = $db->table('slicewp_collection_meta')
            ->whereIn('slicewp_collection_id', $groupIds)
            ->whereIn('meta_key', ['commission_rate_sale', 'commission_rate_type_sale'])
            ->get();

        $metaMap = [];
        foreach ($allMeta as $meta) {
            $metaMap[$meta->slicewp_collection_id][$meta->meta_key] = $meta->meta_value;
        }

        $existingNames = AffiliateGroup::where('object_type', 'affiliate_group')
            ->whereIn('meta_key', $groups->pluck('name')->toArray())
            ->pluck('meta_key')
            ->toArray();

        $dataToInsert = [];

        foreach ($groups as $group) {
            $lastId = $group->id;

            if (in_array($group->name, $existingNames)) {
                continue;
            }

            $rate     = $metaMap[$group->id]['commission_rate_sale'] ?? null;
            $rateType = $metaMap[$group->id]['commission_rate_type_sale'] ?? null;

            $dataToInsert[] = [
                'meta_key'    => $group->name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Array key, not a DB query argument
                'value'       => maybe_serialize([
                    'status'    => 'active',
                    'notes'     => 'Migrated from SliceWP',
                    'rate_type' => isset($rateTypeMap[$rateType]) ? $rateTypeMap[$rateType] : 'percentage',
                    'rate'      => $rate ?: 0,
                ]),
                'object_type' => 'affiliate_group',
            ];
        }

        if (!empty($dataToInsert)) {
            try {
                AffiliateGroup::insert($dataToInsert);
            } catch (\Exception $e) {
                \WP_CLI::warning('Error migrating affiliate groups: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('slicewp_migrated_affiliate_groups', $lastId);
        \WP_CLI::log(sprintf('Migrated affiliate groups up to #%d.....', $lastId));

        $this->migrateSliceWPAffiliateGroups();
    }

    private function migrateSliceWPAffiliates()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_affiliates', 0);

        $db = FluentAffiliate('db');

        $affiliateStatusMap = [
            'active'   => 'active',
            'pending'  => 'pending',
            'inactive' => 'inactive',
            'rejected' => 'inactive',
        ];

        $rateTypeMap = ['percentage' => 'percentage', 'flat' => 'flat'];

        $affiliates = $db->table('slicewp_affiliates')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            \WP_CLI::log('Affiliates migration done');
            return;
        }

        $affiliateIds = $affiliates->pluck('id')->toArray();

        $groupLinks = $db->table('slicewp_collection_object_relationships')
            ->whereIn('object_id', $affiliateIds)
            ->where('object_context', 'affiliate')
            ->get();

        $affiliateGroupMap = [];
        foreach ($groupLinks as $link) {
            $affiliateGroupMap[$link->object_id] = $link->collection_id;
        }

        $faGroupIdMap = $this->getSliceWPGroupIdMap();

        $customRates = $db->table('slicewp_affiliate_meta')
            ->whereIn('slicewp_affiliate_id', $affiliateIds)
            ->whereIn('meta_key', ['commission_rate_sale', 'commission_rate_type_sale'])
            ->get();

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

            $rate      = null;
            $faRateType = 'default';
            $faGroupId  = null;

            if (isset($rateMap[$affId]['rate'])) {
                $sliceRateType = $rateMap[$affId]['rate_type'] ?? 'percentage';
                $faRateType    = isset($rateTypeMap[$sliceRateType]) ? $rateTypeMap[$sliceRateType] : 'percentage';
                $rate          = $rateMap[$affId]['rate'];
            } elseif (isset($affiliateGroupMap[$affId])) {
                $sliceCollectionId = $affiliateGroupMap[$affId];
                if (isset($faGroupIdMap[$sliceCollectionId])) {
                    $faGroupId  = $faGroupIdMap[$sliceCollectionId];
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

        if (!empty($dataToInsert)) {
            try {
                $db->table('fa_affiliates')->insert($dataToInsert);
            } catch (\Exception $e) {
                \WP_CLI::warning('Error migrating affiliates: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('slicewp_migrated_affiliates', $lastId);
        \WP_CLI::log(sprintf('Migrated affiliates up to #%d.....', $lastId));

        $this->migrateSliceWPAffiliates();
    }

    private function migrateSliceWPReferrals()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_referrals', 0);

        $db = FluentAffiliate('db');

        $commissionStatusMap = [
            'paid'     => 'paid',
            'unpaid'   => 'unpaid',
            'pending'  => 'pending',
            'rejected' => 'rejected',
        ];

        $commissions = $db->table('slicewp_commissions')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($commissions->isEmpty()) {
            \WP_CLI::log('Referrals migration done');
            return;
        }

        $dataToInsert = [];

        foreach ($commissions as $commission) {
            $lastId = $commission->id;

            $dataToInsert[] = [
                'id'              => $commission->id,
                'affiliate_id'    => $commission->affiliate_id ?: null,
                'visit_id'        => $commission->visit_id ?: null,
                'customer_id'     => $commission->customer_id ?: null,
                'parent_id'       => $commission->parent_id ?: null,
                'description'     => null,
                'status'          => isset($commissionStatusMap[$commission->status]) ? $commissionStatusMap[$commission->status] : 'pending',
                'amount'          => $commission->amount,
                'order_total'     => $commission->reference_amount,
                'currency'        => $commission->currency ?: null,
                'type'            => ($commission->type === 'subscription') ? 'recurring_sale' : 'sale',
                'provider'        => $commission->origin ?: null,
                'provider_id'     => is_numeric($commission->reference) ? (int)$commission->reference : null,
                'provider_sub_id' => !is_numeric($commission->reference) ? $commission->reference : null,
                'created_at'      => $commission->date_created,
                'updated_at'      => $commission->date_modified,
            ];
        }

        try {
            $db->table('fa_referrals')->insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::warning('Error migrating referrals: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('slicewp_migrated_referrals', $lastId);
        \WP_CLI::log(sprintf('Migrated referrals up to #%d.....', $lastId));

        $this->migrateSliceWPReferrals();
    }

    private function migrateSliceWPCustomers()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_customers', 0);

        $db = FluentAffiliate('db');

        $customers = $db->table('slicewp_customers')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($customers->isEmpty()) {
            \WP_CLI::log('Customers migration done');
            return;
        }

        $dataToInsert = [];

        foreach ($customers as $customer) {
            $lastId = $customer->id;

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
            Customer::insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::warning('Error migrating customers: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('slicewp_migrated_customers', $lastId);
        \WP_CLI::log(sprintf('Migrated customers up to #%d.....', $lastId));

        $this->migrateSliceWPCustomers();
    }

    private function migrateSliceWPPayouts()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_payout_id', 0);

        $db = FluentAffiliate('db');

        $payoutMethodMap  = ['manual' => 'manual', 'paypal' => 'paypal'];
        $paymentStatusMap = [
            'paid'       => 'paid',
            'unpaid'     => 'processing',
            'processing' => 'processing',
        ];

        $payouts = $db->table('slicewp_payouts')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(50)
            ->get();

        if ($payouts->isEmpty()) {
            \WP_CLI::log('Payouts migration done');
            return;
        }

        $payoutIds = $payouts->pluck('id')->toArray();

        // a failed payment never sent the money, so its commissions stay owed
        $allPayments = $db->table('slicewp_payments')
            ->whereIn('payout_id', $payoutIds)
            ->where('status', '!=', 'failed')
            ->get();

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

            $totalAmount = 0;
            $hasOpenPayment = false;
            foreach ($payments as $payment) {
                $totalAmount += (float) $payment->amount;
                $hasOpenPayment = $hasOpenPayment || ($paymentStatusMap[$payment->status] ?? 'paid') !== 'paid';
            }

            $formattedPayout = [
                'created_by'    => $payout->originator_user_id ?: null,
                'total_amount'  => $totalAmount,
                'payout_method' => isset($payoutMethodMap[$firstPayment->payout_method]) ? $payoutMethodMap[$firstPayment->payout_method] : 'manual',
                'status'        => $hasOpenPayment ? 'processing' : 'paid',
                'currency'      => $firstPayment->currency ?: null,
                'title'         => 'Payout batch at ' . $payout->date_created,
                'description'   => 'Migrated from SliceWP',
                'created_at'    => $payout->date_created,
                'updated_at'    => $payout->date_modified,
            ];

            try {
                $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

                foreach ($payments as $payment) {
                    $transactionId = $db->table('fa_payout_transactions')->insertGetId([
                        'affiliate_id'  => $payment->affiliate_id,
                        'payout_id'     => $payoutId,
                        'total_amount'  => $payment->amount,
                        'payout_method' => isset($payoutMethodMap[$payment->payout_method]) ? $payoutMethodMap[$payment->payout_method] : 'manual',
                        'created_by'    => $payment->originator_user_id ?: null,
                        'status'        => isset($paymentStatusMap[$payment->status]) ? $paymentStatusMap[$payment->status] : 'paid',
                        'currency'      => $payment->currency ?: null,
                        'created_at'    => $payment->date_created,
                        'updated_at'    => $payment->date_modified,
                    ]);

                    $commissionIds = $this->parseSliceWPCommissionIds($payment->commission_ids);

                    if (!empty($commissionIds)) {
                        $db->table('fa_referrals')
                            ->whereIn('id', $commissionIds)
                            ->update([
                                'payout_id'             => $payoutId,
                                'payout_transaction_id' => $transactionId,
                            ]);
                    }
                }
            } catch (\Exception $e) {
                \WP_CLI::warning('Error migrating payout #' . $payout->id . ': ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('slicewp_migrated_payout_id', $lastId);
        \WP_CLI::log(sprintf('Migrated payouts up to #%d.....', $lastId));

        $this->migrateSliceWPPayouts();
    }

    private function migrateSliceWPVisits()
    {
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_visits', 0);

        $db = FluentAffiliate('db');

        $visits = $db->table('slicewp_visits')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(1000)
            ->get();

        if ($visits->isEmpty()) {
            \WP_CLI::log('Visits migration done');
            return;
        }

        $dataToInsert = [];

        foreach ($visits as $visit) {
            $lastId = $visit->id;

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
            $db->table('fa_visits')->insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::warning('Error migrating visits: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('slicewp_migrated_visits', $lastId);
        \WP_CLI::log(sprintf('Migrated visits up to #%d.....', $lastId));

        $this->migrateSliceWPVisits();
    }

    private function migrateSliceWPCreatives()
    {
        $wpdb      = $GLOBALS['wpdb'];
        $tableName = $wpdb->prefix . 'fa_creatives';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP-CLI context; result not worth caching
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($tableName)))) {
            \WP_CLI::log('Skipping creatives: fa_creatives table not found (Pro feature).');
            return;
        }

        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_creatives', 0);

        $db = FluentAffiliate('db');

        $validTypes = ['image', 'qr_code', 'text'];

        $creatives = $db->table('slicewp_creatives')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($creatives->isEmpty()) {
            \WP_CLI::log('Creatives migration done');
            return;
        }

        $existingNames = array_flip(
            $db->table('fa_creatives')
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

        if (!empty($dataToInsert)) {
            try {
                $db->table('fa_creatives')->insert($dataToInsert);
            } catch (\Exception $e) {
                \WP_CLI::warning('Error migrating creatives: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('slicewp_migrated_creatives', $lastId);
        \WP_CLI::log(sprintf('Migrated creatives up to #%d.....', $lastId));

        $this->migrateSliceWPCreatives();
    }

    private function recountSliceWPEarnings()
    {
        // Keyset cursor: stores the last processed affiliate id (not a row
        // offset) so it shares the same semantics as the provider migrators
        // that read/write the same slicewp_migrated_recount option.
        $lastId = (int) fluentAffiliate_get_option('slicewp_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            \WP_CLI::log('Affiliate recount done');
            return;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
            fluentAffiliate_update_option('slicewp_migrated_recount', $lastId);
        }

        \WP_CLI::log(sprintf('Recounted affiliates up to #%d.....', $lastId));

        $this->recountSliceWPEarnings();
    }

    private function getSliceWPGroupIdMap()
    {
        if ($this->sliceWPGroupIdMap !== null) {
            return $this->sliceWPGroupIdMap;
        }

        $this->sliceWPGroupIdMap = [];

        $sliceGroups = FluentAffiliate('db')
            ->table('slicewp_collections')
            ->select(['id', 'name'])
            ->where('object_context', 'affiliate')
            ->where('type', 'group')
            ->get();

        if ($sliceGroups->isEmpty()) {
            return $this->sliceWPGroupIdMap;
        }

        // Key FA groups by name for O(1) lookup instead of O(F) inner scan
        $faGroupsByName = [];
        foreach (AffiliateGroup::where('object_type', 'affiliate_group')->get() as $fg) {
            $faGroupsByName[$fg->meta_key] = $fg->id;
        }

        foreach ($sliceGroups as $sg) {
            if (isset($faGroupsByName[$sg->name])) {
                $this->sliceWPGroupIdMap[$sg->id] = $faGroupsByName[$sg->name];
            }
        }

        return $this->sliceWPGroupIdMap;
    }

    private function parseSliceWPCommissionIds($raw)
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
