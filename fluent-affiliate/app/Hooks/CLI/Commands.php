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

        $this->recount_earnings();
    }

    public function recount_earnings()
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_recount', 0);

        $affiliates = Affiliate::orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            \WP_CLI::log(sprintf("Total %d affiliates recount done", $migratedCount));
            return $migratedCount;
        }

        foreach ($affiliates as $affiliate) {
            $affiliate->recountEarnings();
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_recount', $migratedCount);
        }

        \WP_CLI::log(sprintf("Recounted %d affiliates.....", $migratedCount));

        $this->recount_earnings();

    }

    private function migrateAffiliateWpAffiliates()
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_affiliates', 0);

        $affiliates = FluentAffiliate('db')->table('affiliate_wp_affiliates')
            ->orderBy('affiliate_id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get();

        if ($affiliates->isEmpty()) {
            \WP_CLI::log(sprintf("Total %d affiliates migration done", $migratedCount));
            return $migratedCount;
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
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_affiliates', $migratedCount);
        }

        \WP_CLI::log(sprintf("Migrated %d affiliates.....", $migratedCount));

        $this->migrateAffiliateWpAffiliates();
    }

    private function migrateAffiliateWpReferrals()
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_referrals', 0);

        $referrals = FluentAffiliate('db')->table('affiliate_wp_referrals')
            ->orderBy('referral_id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get();

        if ($referrals->isEmpty()) {
            \WP_CLI::log(sprintf("Total %d referrals migration done", $migratedCount));
            return $migratedCount;
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
            $products = maybe_unserialize($referral->products);

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
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_referrals', $migratedCount);
        }

        \WP_CLI::log(sprintf("Migrated %d referrals.....", $migratedCount));

        $this->migrateAffiliateWpReferrals();

    }

    private function migrateAffiliateWpCustomers()
    {
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_customers', 0);

        $customers = FluentAffiliate('db')->table('affiliate_wp_customers')
            ->orderBy('customer_id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get();

        if ($customers->isEmpty()) {
            \WP_CLI::log(sprintf("Total %d customers migration done", $migratedCount));
            return $migratedCount;
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
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_customers', $migratedCount);
        }

        \WP_CLI::log(sprintf("Migrated %d customers.....", $migratedCount));

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
        $migratedCount = fluentAffiliate_get_option('affwp_migrated_visits', 0);

        $visits = FluentAffiliate('db')->table('affiliate_wp_visits')
            ->orderBy('visit_id', 'ASC')
            ->offset($migratedCount)
            ->limit(1000)
            ->get();

        if ($visits->isEmpty()) {
            \WP_CLI::log(sprintf("Total %d visits migration done", $migratedCount));
            return $migratedCount;
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
            $migratedCount = $migratedCount + 1;
            fluentAffiliate_update_option('affwp_migrated_visits', $migratedCount);
        }

        \WP_CLI::log(sprintf("Migrated %d visits.....", $migratedCount));

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
            AffiliateGroup::query()->truncate();
        }
        $this->migrateSolidAffiliateGroups();

        // Migrate affiliates
        if (Affiliate::count()) {
            fluentAffiliate_update_option('solid_migrated_affiliates', 0);
            Affiliate::query()->truncate();
        }
        $this->migrateSolidAffiliateAffiliates();

        // Migrate referrals
        if (Referral::count()) {
            fluentAffiliate_update_option('solid_migrated_referrals', 0);
            Referral::query()->truncate();
        }
        $this->migrateSolidAffiliateReferrals();

        // Migrate customers
        if (Customer::count()) {
            fluentAffiliate_update_option('solid_migrated_customers', 0);
            Customer::query()->truncate();
        }
        $this->migrateSolidAffiliateCustomers();

        // Migrate payouts
        if (Payout::count()) {
            fluentAffiliate_update_option('solid_migrated_payout_id', 0);
            Payout::query()->truncate();
            Transaction::query()->truncate();
        }
        $this->migrateSolidAffiliatePayouts();

        // Migrate visits
        if (\FluentAffiliate\App\Models\Visit::count()) {
            fluentAffiliate_update_option('solid_migrated_visits', 0);
            Visit::query()->truncate();
        }
        $this->migrateSolidAffiliateVisits();
        $this->recount_earnings();
    }

    private function migrateSolidAffiliateGroups()
    {
        $migratedCount = fluentAffiliate_get_option('solid_migrated_affiliate_groups', 0);

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
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get()
        ;

        if ($affiliateGroups->isEmpty()) {
            \WP_CLI::log(sprintf('Total %d affiliate groups migration done', $migratedCount));
            return $migratedCount;
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
            $migratedCount++;
        }

        try {
            AffiliateGroup::insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating affiliate groups: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_affiliate_groups', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d affiliate groups.....', $migratedCount));

        $this->migrateSolidAffiliateGroups();
    }

    private function migrateSolidAffiliateAffiliates()
    {
        $migratedCount = fluentAffiliate_get_option('solid_migrated_affiliates', 0);

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
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get()
        ;

        if ($affiliates->isEmpty()) {
            \WP_CLI::log(sprintf('Total %d affiliates migration done', $migratedCount));
            return $migratedCount;
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
            $migratedCount++;
        }

        try {
            FluentAffiliate('db')->table('fa_affiliates')->insert($dataToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating affiliates: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_affiliates', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d affiliates.....', $migratedCount));

        $this->migrateSolidAffiliateAffiliates();
    }

    private function migrateSolidAffiliateReferrals()
    {
        $migratedCount = fluentAffiliate_get_option('solid_migrated_referrals', 0);

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
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get();

        if ($referrals->isEmpty()) {
            \WP_CLI::log(sprintf('Total %d referrals migration done', $migratedCount));
            return $migratedCount;
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
            $migratedCount++;
        }

        try {
            FluentAffiliate('db')->table('fa_referrals')->insert($referralToInsert);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating referrals: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_referrals', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d referrals.....', $migratedCount));

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
        $migratedCount = fluentAffiliate_get_option('solid_migrated_payout_id', 0);

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
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get()
        ;

        if ($payoutGroups->isEmpty()) {
            \WP_CLI::log(sprintf('Total %d payouts migration done', $migratedCount));
            return $migratedCount;
        }

        $db = FluentAffiliate('db');
        $existingPayoutIds = $db->table('fa_payouts')
            ->whereIn('id', array_column((array)$payoutGroups, 'id'))
            ->pluck('id')
            ->toArray()
        ;

        foreach ($payoutGroups as $payout) {
            if (in_array($payout->id, $existingPayoutIds)) {
                $migratedCount++;
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
                $migratedCount++;
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

                $migratedCount++;
            } catch (\Exception $e) {
                \WP_CLI::error('Error migrating payouts: ' . $e->getMessage());
            }
        }

        fluentAffiliate_update_option('solid_migrated_payout_id', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d payouts.....', $migratedCount));

        $this->migrateSolidAffiliatePayouts();
    }

    private function migrateSolidAffiliateVisits()
    {
        $migratedCount = fluentAffiliate_get_option('solid_migrated_visits', 0);

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
            ->orderBy('id', 'ASC')
            ->offset($migratedCount)
            ->limit(100)
            ->get()
        ;

        if ($visits->isEmpty()) {
            \WP_CLI::log(sprintf('Total %d visits migration done', $migratedCount));
            return $migratedCount;
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
            $migratedCount++;
        }

        try {
            FluentAffiliate('db')->table('fa_visits')->insert($visitItems);
        } catch (\Exception $e) {
            \WP_CLI::error('Error migrating visits: ' . $e->getMessage());
        }

        fluentAffiliate_update_option('solid_migrated_visits', $migratedCount);
        \WP_CLI::log(sprintf('Migrated %d visits.....', $migratedCount));

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
}
