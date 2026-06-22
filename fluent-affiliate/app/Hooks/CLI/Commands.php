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

        $db = FluentAffiliate('db');

        $stats = [
            [
                'title' => 'Total Affiliates',
                'count' => $db->table('affiliate_wp_affiliates')->count()
            ],
            [
                'title' => 'Total Referrals',
                'count' => $db->table('affiliate_wp_referrals')->count()
            ],
            [
                'title' => 'Total Payouts',
                'count' => $db->table('affiliate_wp_payouts')->count()
            ],
            [
                'title' => 'Total Visits',
                'count' => $db->table('affiliate_wp_visits')->count()
            ],
            [
                'title' => 'Total Affiliated Customers',
                'count' => $db->table('affiliate_wp_customers')->count()
            ]
        ];

        // show as cli table
        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);

        // Ask if user wants to continue with WP CLI
        \WP_CLI::confirm("Are you sure you want to migrate?");

        // Show start migrating message
        \WP_CLI::log("Starting migration...");

        // Migrate affiliates
        if (\FluentAffiliate\App\Models\Affiliate::count()) {
            fluentAffiliate_update_option('affwp_migrated_affiliates', 0);
            FluentAffiliate('db')->table('fa_affiliates')->truncate();
        }

        $this->migrateAffiliateWpAffiliates();

        // Migrate referrals
        if (\FluentAffiliate\App\Models\Referral::count()) {
            fluentAffiliate_update_option('affwp_migrated_referrals', 0);
            FluentAffiliate('db')->table('fa_referrals')->truncate();
        }

        $this->migrateAffiliateWpReferrals();

        // Let's Migrate Customers
        if (\FluentAffiliate\App\Models\Customer::count()) {
            fluentAffiliate_update_option('affwp_migrated_customers', 0);
            FluentAffiliate('db')->table('fa_customers')->truncate();
        }
        $this->migrateAffiliateWpCustomers();

        // Let's Migrate Payouts
        if (\FluentAffiliate\App\Models\Payout::count()) {
            fluentAffiliate_update_option('affwp_migrated_payout_id', 0);
            FluentAffiliate('db')->table('fa_payouts')->truncate();
            FluentAffiliate('db')->table('fa_payout_transactions')->truncate();
        }

        $this->migrateAffiliateWpPayouts();

        // Let's Migrate Visits
        if (\FluentAffiliate\App\Models\Visit::count()) {
            fluentAffiliate_update_option('affwp_migrated_visits', 0);
            FluentAffiliate('db')->table('fa_visits')->truncate();
        }

        $this->migrateAffiliateWpVisits();

        // Reset the shared keyset cursor so the recount starts from the first
        // affiliate id, matching migrate_from_slicewp and the web provider.
        fluentAffiliate_update_option('affwp_migrated_recount', 0);
        $this->recount_earnings();
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

    private function migrateAffiliateWpAffiliates()
    {
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_affiliates', 0);

        $affiliates = FluentAffiliate('db')->table('affiliate_wp_affiliates')
            ->where('affiliate_id', '>', $lastId)
            ->orderBy('affiliate_id', 'ASC')
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            \WP_CLI::log("Affiliate migration done");
            return $lastId;
        }

        foreach ($affiliates as $affiliate) {

            $rateType = ($affiliate->rate_type) ? $affiliate->rate_type : 'default';

            $data = [
                'id'              => $affiliate->affiliate_id,
                'user_id'         => $affiliate->user_id,
                'rate'            => $rateType === 'default' ? NULL : $affiliate->rate,
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

            FluentAffiliate('db')->table('fa_affiliates')->insert($data);
            $lastId = $affiliate->affiliate_id;
            fluentAffiliate_update_option('affwp_migrated_affiliates', $lastId);
        }

        \WP_CLI::log(sprintf("Migrated affiliates up to #%d.....", $lastId));

        $this->migrateAffiliateWpAffiliates();
    }

    private function migrateAffiliateWpReferrals()
    {
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_referrals', 0);

        $referrals = FluentAffiliate('db')->table('affiliate_wp_referrals')
            ->where('referral_id', '>', $lastId)
            ->orderBy('referral_id', 'ASC')
            ->limit(100)
            ->get();

        if ($referrals->isEmpty()) {
            \WP_CLI::log("Referral migration done");
            return $lastId;
        }

        foreach ($referrals as $referral) {

            $oderTotal = 0;

            if ($mata = FluentAffiliate('db')->table('affiliate_wp_sales')->where('referral_id', $referral->referral_id)->first()) {
                $oderTotal = $mata->order_total;
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
                    $formattedProducts[] = array_filter([
                        'item_id'  => (int)Arr::get($product, 'id'),
                        'title'    => Arr::get($product, 'name', $referral->description),
                        'subtotal' => isset($product['price']) ? (float)$product['price'] : 0.00,
                        'price'    => $price,
                        'total'    => $price
                    ]);
                }
            } else {
                $formattedProducts[] = [
                    'item_id'  => NULL,
                    'title'    => $referral->description,
                    'subtotal' => $oderTotal,
                    'price'    => $oderTotal,
                    'total'    => $oderTotal
                ];
            }


            $data = array_filter([
                'id'              => $referral->referral_id,
                'affiliate_id'    => $referral->affiliate_id,
                'visit_id'        => $referral->visit_id,
                'description'     => $referral->description,
                'status'          => $referral->status,
                'amount'          => $referral->amount,
                'order_total'     => $oderTotal,
                'currency'        => $referral->currency,
                'provider'        => $referral->context,
                'provider_id'     => $providerId,
                'provider_sub_id' => $provider_sub_id,
                'products'        => maybe_serialize($formattedProducts),
                'payout_id'       => $referral->payout_id,
                'customer_id'     => $referral->customer_id,
                'created_at'      => $referral->date,
                'updated_at'      => $referral->date,
            ]);

            FluentAffiliate('db')->table('fa_referrals')->insert($data);
            $lastId = $referral->referral_id;
            fluentAffiliate_update_option('affwp_migrated_referrals', $lastId);
        }

        \WP_CLI::log(sprintf("Migrated referrals up to #%d.....", $lastId));

        $this->migrateAffiliateWpReferrals();

    }

    private function migrateAffiliateWpCustomers()
    {
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_customers', 0);

        $customers = FluentAffiliate('db')->table('affiliate_wp_customers')
            ->where('customer_id', '>', $lastId)
            ->orderBy('customer_id', 'ASC')
            ->limit(100)
            ->get();

        if ($customers->isEmpty()) {
            \WP_CLI::log("Customer migration done");
            return $lastId;
        }

        foreach ($customers as $customer) {
            $data = array_filter([
                'id'         => $customer->customer_id,
                'user_id'    => $customer->user_id,
                'email'      => $customer->email,
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name,
                'created_at' => $customer->date_created,
                'updated_at' => $customer->date_created,
            ]);

            $firstRef = FluentAffiliate('db')->table('affiliate_wp_customermeta')
                ->where('affwp_customer_id', $customer->customer_id)
                ->where('meta_key', 'affiliate_id')
                ->orderBy('meta_id', 'ASC')
                ->first();

            if ($firstRef && is_numeric($firstRef->meta_value)) {
                $data['by_affiliate_id'] = $firstRef->meta_value;
            }

            FluentAffiliate('db')->table('fa_customers')->insert($data);
            $lastId = $customer->customer_id;
            fluentAffiliate_update_option('affwp_migrated_customers', $lastId);
        }

        \WP_CLI::log(sprintf("Migrated customers up to #%d.....", $lastId));

        $this->migrateAffiliateWpCustomers();
    }

    private function migrateAffiliateWpPayouts()
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_payout_id', 0);

        $affWPSettings = get_option('affwp_settings');

        $currency = isset($affWPSettings['currency']) ? $affWPSettings['currency'] : 'USD';

        $payoutGroups = FluentAffiliate('db')->table('affiliate_wp_payouts')
            ->select([
                FluentAffiliate('db')->raw('DATE(date) as date_group'),
                'owner',
                'payout_method'
            ])
            ->orderBy('date', 'ASC')
            ->groupBy('date_group')
            ->where('payout_id', '>', $migratedCount)
            ->limit(5)
            ->get();

        if ($payoutGroups->isEmpty()) {
            $total = FluentAffiliate('db')->table('affiliate_wp_payouts')->count();
            \WP_CLI::log(sprintf("Total %d payouts migration done", $total));
            return $total;
        }

        foreach ($payoutGroups as $payoutGroup) {
            $payouts = FluentAffiliate('db')->table('affiliate_wp_payouts')
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
                $transactions[] = array_filter([
                    'created_by'    => $payout->owner,
                    'affiliate_id'  => $payout->affiliate_id,
                    'total_amount'  => $payout->amount,
                    'currency'      => $currency,
                    'payout_method' => $payout->payout_method,
                    'status'        => $payout->status,
                    'created_at'    => $payout->date,
                    'updated_at'    => $payout->date,
                    'aff_wp_id'     => $payout->payout_id,
                    'referrals_ids' => explode(',', $payout->referrals),
                ]);
                $totalPayoutAmount += $payout->amount;
            }

            $formattedPayout['total_amount'] = $totalPayoutAmount;

            $payoutId = FluentAffiliate('db')
                ->table('fa_payouts')
                ->insertGetId($formattedPayout);

            foreach ($transactions as $transaction) {
                $affWpId = $transaction['aff_wp_id'];
                $referralIds = $transaction['referrals_ids'];
                $transaction['payout_id'] = $payoutId;
                unset($transaction['aff_wp_id']);
                unset($transaction['referrals_ids']);
                $payoutTransactionId = FluentAffiliate('db')->table('fa_payout_transactions')->insertGetId($transaction);

                FluentAffiliate('db')->table('fa_referrals')
                    ->whereIn('id', $referralIds)
                    ->update([
                        'payout_id'             => $payoutId,
                        'payout_transaction_id' => $payoutTransactionId
                    ]);

                fluentAffiliate_update_option('affwp_migrated_payout_id', $affWpId);
            }
        }

        $migratedCount = Payout::count();

        \WP_CLI::log(sprintf("Migrated %d payouts.....", $migratedCount));

        $this->migrateAffiliateWpPayouts();
    }

    private function migrateAffiliateWpVisits()
    {
        $lastId = (int) fluentAffiliate_get_option('affwp_migrated_visits', 0);

        $visits = FluentAffiliate('db')->table('affiliate_wp_visits')
            ->where('visit_id', '>', $lastId)
            ->orderBy('visit_id', 'ASC')
            ->limit(1000)
            ->get();

        if ($visits->isEmpty()) {
            \WP_CLI::log("Visit migration done");
            return $lastId;
        }

        foreach ($visits as $visit) {

            $data = array_filter([
                'id'           => $visit->visit_id,
                'affiliate_id' => $visit->affiliate_id,
                'referral_id'  => $visit->referral_id,
                'url'          => $visit->url,
                'referrer'     => $visit->referrer,
                'utm_campaign' => $visit->campaign,
                'ip'           => $visit->ip,
                'created_at'   => $visit->date,
                'updated_at'   => $visit->date,
            ]);

            FluentAffiliate('db')->table('fa_visits')->insert($data);
            $lastId = $visit->visit_id;
            fluentAffiliate_update_option('affwp_migrated_visits', $lastId);
        }

        \WP_CLI::log(sprintf("Migrated visits up to #%d.....", $lastId));

        $this->migrateAffiliateWpVisits();
    }

    /**
     * Returns Seed FluentAffiliate fake data to view.
     * @return void
     */
    public function seed()
    {
        DBSeeder::run();
    }

    public function freshSeed()
    {
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


    public function migrate_from_solid_affiliate()
    {
        $db = FluentAffiliate('db');

        // Display migration stats
        $stats = [
            [
                'title' => 'Total Affiliate Groups',
                'count' => $db->table('solid_affiliate_affiliate_groups')->count()
            ],
            [
                'title' => 'Total Affiliates',
                'count' => $db->table('solid_affiliate_affiliates')->count()
            ],
            [
                'title' => 'Total Referrals',
                'count' => $db->table('solid_affiliate_referrals')->count()
            ],
            [
                'title' => 'Total Customers',
                'count' => $db->table('wc_orders')
                    ->where('type', 'shop_order')
                    ->whereNotNull('customer_id')
                    ->distinct()
                    ->count('customer_id')
            ],
            [
                'title' => 'Total Payouts',
                'count' => $db->table('solid_affiliates_bulk_payouts')->count()
            ],
            [
                'title' => 'Total Visits',
                'count' => $db->table('solid_affiliate_visits')->count()
            ]
        ];

        \WP_CLI\Utils\format_items('table', $stats, ['title', 'count']);

        \WP_CLI::confirm('Are you sure you want to migrate from Solid Affiliate?');

        \WP_CLI::log('Starting Solid Affiliate migration...');

        // Migrate affiliate groups
        if (AffiliateGroup::count()) {
            fluentAffiliate_update_option('solid_migrated_affiliate_groups', 0);
            AffiliateGroup::truncate();
        }
        $this->migrateSolidAffiliateGroups();

        // Migrate affiliates
        if (Affiliate::count()) {
            fluentAffiliate_update_option('solid_migrated_affiliates', 0);
            Affiliate::truncate();
        }
        $this->migrateSolidAffiliateAffiliates();

        // Migrate referrals
        if (Referral::count()) {
            fluentAffiliate_update_option('solid_migrated_referrals', 0);
            Referral::truncate();
        }
        $this->migrateSolidAffiliateReferrals();

        // Migrate customers
        if (Customer::count()) {
            fluentAffiliate_update_option('solid_migrated_customers', 0);
            Customer::truncate();
        }
        $this->migrateSolidAffiliateCustomers();

        // Migrate payouts
        if (Payout::count()) {
            fluentAffiliate_update_option('solid_migrated_payout_id', 0);
            Payout::truncate();
            Transaction::truncate();
        }
        $this->migrateSolidAffiliatePayouts();

        // Migrate visits
        if (\FluentAffiliate\App\Models\Visit::count()) {
            fluentAffiliate_update_option('solid_migrated_visits', 0);
            Visit::truncate();
        }
        $this->migrateSolidAffiliateVisits();

        // Reset the keyset recount cursor so a stale value from a prior run
        // cannot skip affiliates, then recount through the Solid Affiliate
        // cursor (the same option the web Solid provider uses).
        fluentAffiliate_update_option('solid_affiliate_migrated_recount', 0);
        $this->recountSolidAffiliateEarnings();
    }

    private function recountSolidAffiliateEarnings()
    {
        // Keyset cursor: stores the last processed affiliate id (not a row
        // offset) so it shares the same semantics as the Solid Affiliate
        // provider migrator that reads/writes solid_affiliate_migrated_recount.
        $lastId = (int) fluentAffiliate_get_option('solid_affiliate_migrated_recount', 0);

        $affiliates = Affiliate::where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            \WP_CLI::log('Affiliate recount done');
            return;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $lastId = $affiliate->id;
            fluentAffiliate_update_option('solid_affiliate_migrated_recount', $lastId);
        }

        \WP_CLI::log(sprintf('Recounted affiliates up to #%d.....', $lastId));

        $this->recountSolidAffiliateEarnings();
    }

    private function migrateSolidAffiliateGroups()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_migrated_affiliate_groups', 0);

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

        $affiliateGroups = FluentAffiliate('db')
            ->table('solid_affiliate_affiliate_groups')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliateGroups->isEmpty()) {
            \WP_CLI::log('Affiliate groups migration done');
            return $lastId;
        }

        $dataToInsert = [];

        foreach ($affiliateGroups as $group) {
            $data = [];
            $valueData = $adjustments['value'];

            foreach ($affiliateGroupColumnsMap as $solidColumn => $fluentColumn) {
                if (strpos($fluentColumn, 'value.') === 0) {
                    $valueKey = substr($fluentColumn, 6);
                    if (isset($group->$solidColumn)) {
                        $valueData[$valueKey] = $group->$solidColumn;
                    }
                } elseif (isset($group->$solidColumn)) {
                    $data[$fluentColumn] = $group->$solidColumn;
                }
            }

            $data['value'] = maybe_serialize($valueData);
            $data['object_type'] = 'affiliate_group';
            $dataToInsert[] = $data;
            $lastId = $group->id;
        }

        try {
            AffiliateGroup::insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating affiliate groups: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_affiliate_groups', $lastId);
        \WP_CLI::log(sprintf('Migrated affiliate groups up to #%d.....', $lastId));

        $this->migrateSolidAffiliateGroups();
    }

    private function migrateSolidAffiliateAffiliates()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_migrated_affiliates', 0);

        $affiliateStatusMap = [
            'approved' => 'active',
            'pending'  => 'pending',
            'rejected' => 'inactive',
        ];

        $affiliateColumnsMap = [
            'id'                       => 'id',
            'user_id'                  => 'user_id',
            'affiliate_group_id'       => 'group_id',
            'commission_type'          => 'rate_type',
            'commission_rate'          => 'rate',
            'payment_email'            => 'payment_email',
            'registration_notes'       => 'note',
            'status'                   => 'status',
            'custom_registration_data' => 'custom_param',
            'created_at'               => 'created_at',
            'updated_at'               => 'updated_at',
        ];

        $affiliates = FluentAffiliate('db')
            ->table('solid_affiliate_affiliates')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            \WP_CLI::log('Affiliates migration done');
            return $lastId;
        }

        $dataToInsert = [];

        foreach ($affiliates as $affiliate) {
            $data = [];

            foreach ($affiliateColumnsMap as $solidColumn => $fluentColumn) {
                if ($solidColumn === 'status' && isset($affiliate->status)) {
                    $data[$fluentColumn] = isset($affiliateStatusMap[$affiliate->status]) ? $affiliateStatusMap[$affiliate->status] : 'active';
                } elseif (isset($affiliate->$solidColumn)) {
                    $data[$fluentColumn] = $affiliate->$solidColumn;
                }
            }

            $data = array_merge($data, [
                'total_earnings'  => 0,
                'unpaid_earnings' => 0,
                'referrals'       => 0,
                'visits'          => 0
            ]);

            $dataToInsert[] = $data;
            $lastId = $affiliate->id;
        }

        try {
            FluentAffiliate('db')->table('fa_affiliates')->insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating affiliates: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_affiliates', $lastId);
        \WP_CLI::log(sprintf('Migrated affiliates up to #%d.....', $lastId));

        $this->migrateSolidAffiliateAffiliates();
    }

    private function migrateSolidAffiliateReferrals()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_migrated_referrals', 0);

        $referralStatusMap = [
            'unpaid'   => 'unpaid',
            'paid'     => 'paid',
            'rejected' => 'rejected',
            'draft'    => 'pending',
        ];

        $referralColumnsMap = [
            'id'                          => 'id',
            'affiliate_id'                => 'affiliate_id',
            'order_amount'                => 'order_total',
            'commission_amount'           => 'amount',
            'visit_id'                    => 'visit_id',
            'customer_id'                 => 'customer_id',
            'referral_type'               => 'type',
            'description'                 => 'description',
            'order_id'                    => 'provider_id',
            'created_at'                  => 'created_at',
            'updated_at'                  => 'updated_at',
            'payout_id'                   => 'payout_transaction_id',
            'serialized_item_commissions' => 'products',
            'affiliate_customer_link_id'  => 'customer_id',
            'status'                      => 'status', // Added status mapping
        ];

        $referrals = FluentAffiliate('db')->table('solid_affiliate_referrals')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get();

        if ($referrals->isEmpty()) {
            \WP_CLI::log('Referrals migration done');
            return $lastId;
        }

        $referralToInsert = [];

        foreach ($referrals as $referral) {
            $data = [];
            foreach ($referralColumnsMap as $solidColumn => $fluentColumn) {
                if ($fluentColumn === null) {
                    continue;
                }
                if ($solidColumn === 'status' && isset($referral->status)) {
                    $data['status'] = isset($referralStatusMap[$referral->status]) ? $referralStatusMap[$referral->status] : 'pending';
                } elseif ($solidColumn === 'affiliate_customer_link_id' && isset($referral->affiliate_customer_link_id)) {
                    $data['customer_id'] = $referral->affiliate_customer_link_id;
                } elseif (isset($referral->$solidColumn) && $solidColumn !== 'order_source') {
                    $data[$fluentColumn] = $referral->$solidColumn;
                }
            }

            $data = array_merge($data, [
                'provider' => 'woo',
                'currency' => null,
            ]);

            $referralToInsert[] = $data;
            $lastId = $referral->id;
        }

        try {
            FluentAffiliate('db')->table('fa_referrals')->insert($referralToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating referrals: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_referrals', $lastId);
        \WP_CLI::log(sprintf('Migrated referrals up to #%d.....', $lastId));

        $this->migrateSolidAffiliateReferrals();
    }

    private function migrateSolidAffiliateCustomers()
    {
        $migratedCount = fluentAffiliate_get_option('solid_migrated_customers', 0);

        $userIds = FluentAffiliate('db')->table('wc_orders')
            ->select('customer_id')
            ->where('type', 'shop_order')
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id')
            ->toArray()
        ;

        if (empty($userIds)) {
            \WP_CLI::log(sprintf('Total %d customers migration done', $migratedCount));
            return $migratedCount;
        }

        $userIds = array_slice($userIds, $migratedCount, 100);

        if (empty($userIds)) {
            \WP_CLI::log(sprintf('Total %d customers migration done', $migratedCount));
            return $migratedCount;
        }

        $dataToInsert = [];
        $existingCustomerUserIds = Customer::whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->toArray()
        ;

        foreach ($userIds as $userId) {
            if (in_array($userId, $existingCustomerUserIds)) {
                $migratedCount++;
                continue;
            }

            $user = get_userdata($userId);
            if (!$user) {
                $migratedCount++;
                continue;
            }

            $data = [
                'user_id'    => $user->ID,
                'email'      => $user->user_email,
                'first_name' => get_user_meta($user->ID, 'first_name', true) ?: null,
                'last_name'  => get_user_meta($user->ID, 'last_name', true) ?: null,
                'created_at' => $user->user_registered,
                'updated_at' => null,
            ];

            $firstRef = FluentAffiliate('db')->table('solid_affiliate_referrals')
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
                \WP_CLI::error('Error migrating customers: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('solid_migrated_customers', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d customers.....', $migratedCount));

        $this->migrateSolidAffiliateCustomers();
    }

    private function migrateSolidAffiliatePayouts()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_migrated_payout_id', 0);

        $payoutTransactionsColumnsMap = [
            'affiliate_id'       => 'affiliate_id',
            'amount'             => 'total_amount',
            'payout_method'      => 'payout_method',
            'created_by_user_id' => 'created_by',
            'status'             => 'status',
            'created_at'         => 'created_at',
            'updated_at'         => 'updated_at',
        ];

        $payoutsColumnsMap = [
            'currency'           => 'currency',
            'method'             => 'payout_method',
            'total_amount'       => 'total_amount',
            'status'             => 'status',
            'created_by_user_id' => 'created_by',
            'created_at'         => 'created_at',
            'updated_at'         => 'updated_at',
        ];

        $payoutGroups = FluentAffiliate('db')->table('solid_affiliates_bulk_payouts')
            ->select([
                'id',
                'date_range_start',
                'date_range_end',
                'created_by_user_id',
                'method',
                'currency',
                'status'
            ])
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($payoutGroups->isEmpty()) {
            \WP_CLI::log('Payouts migration done');
            return $lastId;
        }

        $db = FluentAffiliate('db');
        $existingPayoutIds = $db->table('fa_payouts')
            ->whereIn('id', array_column((array)$payoutGroups, 'id'))
            ->pluck('id')
            ->toArray()
        ;

        foreach ($payoutGroups as $payout) {
            $lastId = $payout->id;

            if (in_array($payout->id, $existingPayoutIds)) {
                continue;
            }

            $formattedPayout = [];
            foreach ($payoutsColumnsMap as $solidColumn => $fluentColumn) {
                if (isset($payout->$solidColumn)) {
                    $formattedPayout[$fluentColumn] = $payout->$solidColumn;
                }
            }

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

            try {
                $payoutId = $db->table('fa_payouts')->insertGetId($formattedPayout);

                foreach ($transactions as $transaction) {
                    $existingTransaction = $db->table('fa_payout_transactions')
                        ->where('payout_id', $payoutId)
                        ->where('affiliate_id', $transaction->affiliate_id)
                        ->where('total_amount', $transaction->amount)
                        ->first()
                    ;

                    if ($existingTransaction) {
                        continue;
                    }

                    $mappedTransaction = [];
                    foreach ($payoutTransactionsColumnsMap as $solidColumn => $fluentColumn) {
                        if (isset($transaction->$solidColumn)) {
                            $mappedTransaction[$fluentColumn] = $transaction->$solidColumn;
                        }
                    }
                    $mappedTransaction['payout_id'] = $payoutId;

                    $payoutTransactionId = $db->table('fa_payout_transactions')->insertGetId($mappedTransaction);

                    $referrals = $db->table('solid_affiliate_referrals')
                        ->where('payout_id', $transaction->id)
                        ->pluck('id')
                        ->toArray()
                    ;

                    foreach ($referrals as $referral) {
                        $db->table('fa_referrals')
                            ->where('id', $referral)
                            ->update([
                                'payout_id'             => $payoutId,
                                'payout_transaction_id' => $transaction->id
                            ])
                        ;
                    }
                }

            } catch (\Exception $e) {
                \WP_CLI::error('Error migrating payouts: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('solid_migrated_payout_id', $lastId);
        \WP_CLI::log(sprintf('Migrated payouts up to #%d.....', $lastId));

        $this->migrateSolidAffiliatePayouts();
    }

    private function migrateSolidAffiliateVisits()
    {
        $lastId = (int) fluentAffiliate_get_option('solid_migrated_visits', 0);

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

        $visits = FluentAffiliate('db')
            ->table('solid_affiliate_visits')
            ->where('id', '>', $lastId)
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get()
        ;

        if ($visits->isEmpty()) {
            \WP_CLI::log('Visits migration done');
            return $lastId;
        }

        $visitItems = [];
        foreach ($visits as $visit) {
            $data = [];
            foreach ($visitsColumnsMap as $solidColumn => $fluentColumn) {
                if ($fluentColumn === null) {
                    continue;
                }
                if (isset($visit->$solidColumn)) {
                    $data[$fluentColumn] = $visit->$solidColumn;
                }
            }

            $data = array_merge([
                'utm_campaign' => null,
            ], $data);

            $visitItems[] = $data;
            $lastId = $visit->id;
        }

        try {
            FluentAffiliate('db')->table('fa_visits')->insert($visitItems);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating visits: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_visits', $lastId);
        \WP_CLI::log(sprintf('Migrated visits up to #%d.....', $lastId));

        $this->migrateSolidAffiliateVisits();
    }


    /**
     * Migrate from Affiliate Manager to FluentAffiliate
     *
     * This command uses the temporary CLI migration class to handle the complete
     * migration process with all critical fixes applied.
     *
     * ## EXAMPLES
     *
     *     wp fluent_affiliate migrate_from_affiliate_manager
     *
     * @when after_wp_load
     */
    public function migrate_from_affiliate_manager()
    {
        // Use the new temporary CLI migration class
        $migrator = new \FluentAffiliate\App\Services\Migrator\CLI\AffiliateManagerMigrationCLI();
        $migrator->migrate();
    }

    public function migrate_from_ultimate_affiliate()
    {
        if (!defined('UAP_PLUGIN_VER')) {
            \WP_CLI::error('Ultimate Affiliate (UAP) is not active. Nothing to migrate.');
            return;
        }

        // Drive the shared, already-verified UltimateAffiliate provider rather
        // than reimplementing each stage. The provider deduplicates by source
        // id, so re-running is idempotent without truncating fa_* tables.
        $migrator = new \FluentAffiliate\App\Services\Migrator\Providers\UltimateAffiliate();

        // Start a fresh run: clear the staged cursors and the recount cursor,
        // then begin at the first stage.
        $migrator->updateCurrentStatus([], false);
        fluentAffiliate_update_option('ultimate_affiliate_migrated_recount', 0);

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

        \WP_CLI::log('Starting Ultimate Affiliate migration...');

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
            'failed'     => 'processing',
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

        $allPayments = $db->table('slicewp_payments')
            ->whereIn('payout_id', $payoutIds)
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

            $formattedPayout = [
                'created_by'    => $payout->originator_user_id ?: null,
                'total_amount'  => $payout->amount,
                'payout_method' => isset($payoutMethodMap[$firstPayment->payout_method]) ? $payoutMethodMap[$firstPayment->payout_method] : 'manual',
                'status'        => 'paid',
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
