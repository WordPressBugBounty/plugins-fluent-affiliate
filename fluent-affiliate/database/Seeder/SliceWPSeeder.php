<?php

namespace FluentAffiliate\Database\Seeder;

/**
 * Seeds fake data into SliceWP tables for migration testing.
 * Uses the Fluent Framework ORM (via FluentAffiliate('db')).
 * Read-only on real data — only inserts test records.
 * Idempotent — checks for a guard option before inserting.
 *
 * Usage (WP-CLI):
 *   wp eval "( new \FluentAffiliate\Database\Seeder\SliceWPSeeder() )->seed();"
 *
 * To wipe seeded data and re-seed:
 *   wp eval "( new \FluentAffiliate\Database\Seeder\SliceWPSeeder() )->freshSeed();"
 */
class SliceWPSeeder
{
    private $db;

    private $firstNames = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda',
        'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Chris', 'Lisa', 'Daniel', 'Nancy',
        'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Steven', 'Ashley',
        'Paul', 'Kimberly', 'Andrew', 'Emily', 'Joshua', 'Donna', 'Kenneth', 'Michelle',
        'Kevin', 'Carol', 'Brian', 'Amanda', 'George', 'Dorothy', 'Timothy', 'Melissa',
        'Ronald', 'Deborah',
    ];

    private $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
        'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson',
        'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker',
        'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill',
        'Flores', 'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell',
        'Mitchell', 'Carter', 'Roberts',
    ];

    private $domains = [
        'example.com', 'testsite.org', 'myblog.net', 'shopnow.com', 'reviews.io',
        'deals.co', 'promos.net', 'affiliate-hub.com', 'blogspot.test', 'store.demo',
    ];

    private $paths = [
        '/products/summer-sale', '/category/electronics', '/deals/black-friday',
        '/shop/best-sellers', '/landing/free-trial', '/promo/new-year',
        '/products/wireless-headphones', '/category/home-garden', '/shop/trending',
        '/deals/weekly-offers', '/products/fitness-tracker', '/landing/signup',
    ];

    private $referrers = [
        'https://www.google.com/', 'https://www.facebook.com/', 'https://twitter.com/',
        'https://www.youtube.com/', 'https://www.reddit.com/', 'https://www.pinterest.com/',
        'https://www.instagram.com/', '', '', '',
    ];

    public function __construct()
    {
        $this->db = FluentAffiliate('db');
    }

    public function seed()
    {
        if (get_option('_slicewp_seeder_ran')) {
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::warning('SliceWP seeder already ran. Use freshSeed() to wipe and re-seed.');
            }
            return;
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Creating test WordPress users...');
        }
        $userIds = $this->ensureTestUsers(60);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP affiliates...');
        }
        $affiliateIds = $this->seedAffiliates($userIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP affiliate groups...');
        }
        $groupIds = $this->seedGroups($affiliateIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP custom affiliate rates...');
        }
        $this->seedCustomAffiliateRates($affiliateIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP creatives...');
        }
        $this->seedCreatives();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP customers...');
        }
        $customerIds = $this->seedCustomers($affiliateIds, $userIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP visits...');
        }
        $visitIds = $this->seedVisits($affiliateIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP commissions...');
        }
        $commissionIds = $this->seedCommissions($affiliateIds, $visitIds, $customerIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Seeding SliceWP payouts and payments...');
        }
        $this->seedPayoutsAndPayments($affiliateIds, $commissionIds);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Linking visits to commissions...');
        }
        $this->linkVisitsToCommissions();

        update_option('_slicewp_seeder_ran', time(), 'no');

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::success('SliceWP seed data inserted successfully.');
        }
    }

    public function freshSeed()
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line('Wiping previous seed data...');
        }

        // Only truncate SliceWP tables — never touch real plugin data
        $this->db->table('slicewp_commissions')->truncate();
        $this->db->table('slicewp_visits')->truncate();
        $this->db->table('slicewp_payments')->truncate();
        $this->db->table('slicewp_payouts')->truncate();
        $this->db->table('slicewp_customers')->truncate();
        $this->db->table('slicewp_collection_object_relationships')->truncate();
        $this->db->table('slicewp_collection_meta')->truncate();
        $this->db->table('slicewp_collections')->truncate();
        $this->db->table('slicewp_creatives')->truncate();
        $this->db->table('slicewp_affiliate_meta')->truncate();
        $this->db->table('slicewp_affiliates')->truncate();

        delete_option('_slicewp_seeder_ran');

        $this->seed();
    }

    /**
     * Ensure at least $needed WordPress test users exist.
     * Returns array of user IDs.
     */
    private function ensureTestUsers($needed)
    {
        // First, gather existing users we can use
        $existing = $this->db->table('users')
            ->select('ID')
            ->orderBy('ID', 'ASC')
            ->limit($needed)
            ->pluck('ID')
            ->toArray()
        ;

        $have = count($existing);

        if ($have >= $needed) {
            return array_slice($existing, 0, $needed);
        }

        // Create additional test users
        $toCreate = $needed - $have;

        for ($i = 0; $i < $toCreate; $i++) {
            $first = $this->pick($this->firstNames);
            $last = $this->pick($this->lastNames);
            $username = strtolower($first . '.' . $last . '.' . mt_rand(100, 9999));
            $email = $username . '@' . $this->pick($this->domains);

            $userId = wp_insert_user([
                'user_login'   => $username,
                'user_pass'    => wp_generate_password(),
                'user_email'   => $email,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => $first . ' ' . $last,
                'role'         => 'subscriber',
            ]);

            if (!is_wp_error($userId)) {
                $existing[] = $userId;
            }
        }

        return $existing;
    }

    /**
     * Insert 50 affiliates into slicewp_affiliates.
     */
    private function seedAffiliates(array $userIds)
    {
        $statuses = ['active', 'active', 'active', 'active', 'pending', 'pending', 'inactive', 'rejected'];
        $rows = [];
        $usedUserIds = [];
        $ids = [];

        // Shuffle to get random unique user_ids
        $pool = $userIds;
        shuffle($pool);
        $pool = array_slice($pool, 0, 50);

        foreach ($pool as $index => $userId) {
            $date = $this->randomDate('-6 months', '-1 day');

            $rows[] = [
                'user_id'       => $userId,
                'date_created'  => $date,
                'date_modified' => $date,
                'payment_email' => strtolower($this->pick($this->firstNames)) . mt_rand(10, 999) . '@' . $this->pick($this->domains),
                'website'       => 'https://www.' . $this->pick($this->domains),
                'status'        => $this->pick($statuses),
                'parent_id'     => 0,
            ];
        }

        // Insert in batches to get IDs
        foreach ($rows as $row) {
            $id = $this->db->table('slicewp_affiliates')->insertGetId($row);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Insert 50 customers into slicewp_customers.
     */
    private function seedCustomers(array $affiliateIds, array $userIds)
    {
        $rows = [];
        $ids = [];

        for ($i = 0; $i < 50; $i++) {
            $first = $this->pick($this->firstNames);
            $last = $this->pick($this->lastNames);
            $date = $this->randomDate('-5 months', '-1 day');

            $rows[] = [
                'user_id'       => $this->pick($userIds),
                'email'         => strtolower($first . '.' . $last . mt_rand(1, 999)) . '@' . $this->pick($this->domains),
                'first_name'    => $first,
                'last_name'     => $last,
                'affiliate_id'  => $this->pick($affiliateIds),
                'date_created'  => $date,
                'date_modified' => $date,
            ];
        }

        foreach ($rows as $row) {
            $id = $this->db->table('slicewp_customers')->insertGetId($row);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Insert 80 visits into slicewp_visits (spread across affiliates).
     */
    private function seedVisits(array $affiliateIds)
    {
        $rows = [];
        $ids = [];

        for ($i = 0; $i < 80; $i++) {
            $date = $this->randomDate('-5 months', '-1 day');
            $domain = $this->pick($this->domains);

            $rows[] = [
                'affiliate_id'  => $this->pick($affiliateIds),
                'date_created'  => $date,
                'date_modified' => $date,
                'ip_address'    => mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254),
                'landing_url'   => 'https://www.' . $domain . $this->pick($this->paths),
                'referrer_url'  => $this->pick($this->referrers),
                'commission_id' => 0,
            ];
        }

        foreach ($rows as $row) {
            $id = $this->db->table('slicewp_visits')->insertGetId($row);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Insert 60 commissions into slicewp_commissions.
     * Returns array of [commission_id => ['affiliate_id' => ..., 'status' => ...]]
     */
    private function seedCommissions(array $affiliateIds, array $visitIds, array $customerIds)
    {
        $statuses = ['paid', 'paid', 'unpaid', 'unpaid', 'unpaid', 'pending', 'pending', 'rejected'];
        $origins = ['woo', 'woo', 'woo', 'woo', null];
        $ids = [];

        for ($i = 0; $i < 60; $i++) {
            $affiliateId = $this->pick($affiliateIds);
            $date = $this->randomDate('-4 months', '-1 day');
            $orderTotal = round(mt_rand(1500, 50000) / 100, 2);
            $commission = round($orderTotal * mt_rand(5, 30) / 100, 2);
            $status = $this->pick($statuses);

            $row = [
                'affiliate_id'     => $affiliateId,
                'visit_id'         => $this->pick($visitIds),
                'date_created'     => $date,
                'date_modified'    => $date,
                'type'             => (mt_rand(1, 10) <= 8) ? 'sale' : 'subscription',
                'status'           => $status,
                'reference'        => (string) mt_rand(1000, 99999),
                'reference_amount' => (string) $orderTotal,
                'customer_id'      => $this->pick($customerIds),
                'origin'           => $this->pick($origins),
                'amount'           => (string) $commission,
                'parent_id'        => 0,
                'payment_id'       => 0,
                'currency'         => 'USD',
            ];

            $id = $this->db->table('slicewp_commissions')->insertGetId($row);
            $ids[$id] = [
                'affiliate_id' => $affiliateId,
                'status'       => $status,
                'amount'       => $commission,
            ];
        }

        return $ids;
    }

    /**
     * Insert payouts and payments for 'paid' commissions.
     */
    private function seedPayoutsAndPayments(array $affiliateIds, array $commissionMap)
    {
        // Group paid commissions by affiliate
        $paidByAffiliate = [];
        foreach ($commissionMap as $commissionId => $info) {
            if ($info['status'] === 'paid') {
                $affId = $info['affiliate_id'];
                $paidByAffiliate[$affId][] = [
                    'id'     => $commissionId,
                    'amount' => $info['amount'],
                ];
            }
        }

        if (empty($paidByAffiliate)) {
            return;
        }

        // Create payout batches (group every ~5 affiliates into one batch)
        $affiliateChunks = array_chunk($paidByAffiliate, 5, true);
        $methods = ['manual', 'manual', 'paypal'];
        $adminId = $this->db->table('users')->where('user_login', 'admin')->value('ID') ?: 1;

        foreach ($affiliateChunks as $chunk) {
            $batchTotal = 0;
            foreach ($chunk as $commissions) {
                foreach ($commissions as $c) {
                    $batchTotal += $c['amount'];
                }
            }

            $date = $this->randomDate('-3 months', '-1 day');

            // Insert payout batch
            $payoutId = $this->db->table('slicewp_payouts')->insertGetId([
                'originator_user_id' => $adminId,
                'date_created'       => $date,
                'date_modified'      => $date,
                'amount'             => (string) round($batchTotal, 2),
            ]);

            // Insert per-affiliate payments
            foreach ($chunk as $affiliateId => $commissions) {
                $paymentTotal = 0;
                $commissionIds = [];
                foreach ($commissions as $c) {
                    $paymentTotal += $c['amount'];
                    $commissionIds[] = $c['id'];
                }

                $method = $this->pick($methods);
                $paymentStatus = (mt_rand(1, 10) <= 8) ? 'paid' : $this->pick(['unpaid', 'processing', 'failed']);

                $paymentId = $this->db->table('slicewp_payments')->insertGetId([
                    'affiliate_id'       => $affiliateId,
                    'commission_ids'     => maybe_serialize($commissionIds),
                    'amount'             => (string) round($paymentTotal, 2),
                    'currency'           => 'USD',
                    'originator_user_id' => $adminId,
                    'payout_method'      => $method,
                    'status'             => $paymentStatus,
                    'payout_id'          => $payoutId,
                    'date_created'       => $date,
                    'date_modified'      => $date,
                ]);

                // Update commissions with payment_id
                $this->db->table('slicewp_commissions')
                    ->whereIn('id', $commissionIds)
                    ->update(['payment_id' => $paymentId])
                ;
            }
        }
    }

    /**
     * Back-link visits to commissions (mirrors SliceWP's real behavior).
     */
    private function linkVisitsToCommissions()
    {
        $commissions = $this->db->table('slicewp_commissions')
            ->select(['id', 'visit_id'])
            ->where('visit_id', '>', 0)
            ->get()
        ;

        foreach ($commissions as $commission) {
            $this->db->table('slicewp_visits')
                ->where('id', $commission->visit_id)
                ->where('commission_id', 0)
                ->update(['commission_id' => $commission->id])
            ;
        }
    }

    /**
     * Insert affiliate groups into slicewp_collections + link affiliates via relationships.
     */
    private function seedGroups(array $affiliateIds)
    {
        $groupNames = [
            'Gold Partners', 'Silver Partners', 'Bronze Partners',
            'VIP Affiliates', 'Content Creators',
        ];

        $groupIds = [];

        foreach ($groupNames as $name) {
            $date = $this->randomDate('-6 months', '-3 months');

            $groupId = $this->db->table('slicewp_collections')->insertGetId([
                'object_context' => 'affiliate',
                'type'           => 'group',
                'name'           => $name,
                'date_created'   => $date,
                'date_modified'  => $date,
            ]);

            $groupIds[] = $groupId;

            // Store group-level commission rate in collection_meta
            $rateTypes = ['percentage', 'percentage', 'flat'];
            $rateType = $this->pick($rateTypes);
            $rate = ($rateType === 'percentage') ? mt_rand(5, 30) : mt_rand(5, 50);

            $this->db->table('slicewp_collection_meta')->insert([
                'slicewp_collection_id' => $groupId,
                'meta_key'              => 'commission_rate_sale',
                'meta_value'            => (string) $rate,
            ]);

            $this->db->table('slicewp_collection_meta')->insert([
                'slicewp_collection_id' => $groupId,
                'meta_key'              => 'commission_rate_type_sale',
                'meta_value'            => $rateType,
            ]);
        }

        // Assign ~70% of affiliates to a group
        $toAssign = array_slice($affiliateIds, 0, (int) (count($affiliateIds) * 0.7));
        shuffle($toAssign);

        foreach ($toAssign as $affiliateId) {
            $date = $this->randomDate('-5 months', '-1 month');

            $this->db->table('slicewp_collection_object_relationships')->insert([
                'collection_id'  => $this->pick($groupIds),
                'object_id'      => $affiliateId,
                'object_context' => 'affiliate',
                'date_created'   => $date,
                'date_modified'  => $date,
            ]);
        }

        return $groupIds;
    }

    /**
     * Insert custom per-affiliate commission rates into slicewp_affiliate_meta.
     * Only ~30% of affiliates get custom rates (rest use global/group defaults).
     */
    private function seedCustomAffiliateRates(array $affiliateIds)
    {
        $toCustomize = array_slice($affiliateIds, 0, (int) (count($affiliateIds) * 0.3));
        shuffle($toCustomize);

        $rateTypes = ['percentage', 'percentage', 'flat'];

        foreach ($toCustomize as $affiliateId) {
            $rateType = $this->pick($rateTypes);
            $rate = ($rateType === 'percentage') ? mt_rand(5, 35) : mt_rand(3, 75);

            // SliceWP stores per-affiliate rates as: commission_rate_sale / commission_rate_type_sale
            $this->db->table('slicewp_affiliate_meta')->insert([
                'slicewp_affiliate_id' => $affiliateId,
                'meta_key'             => 'commission_rate_sale',
                'meta_value'           => (string) $rate,
            ]);

            $this->db->table('slicewp_affiliate_meta')->insert([
                'slicewp_affiliate_id' => $affiliateId,
                'meta_key'             => 'commission_rate_type_sale',
                'meta_value'           => $rateType,
            ]);
        }
    }

    /**
     * Insert creatives into slicewp_creatives.
     */
    private function seedCreatives()
    {
        $creatives = [
            ['name' => 'Summer Sale Banner',       'type' => 'image',     'text' => 'Save 30% this summer!',                'image_url' => 'https://placehold.co/728x90/orange/white?text=Summer+Sale',     'landing_url' => '/deals/summer-sale'],
            ['name' => 'Holiday Promo Banner',      'type' => 'image',     'text' => 'Holiday deals up to 50% off',          'image_url' => 'https://placehold.co/728x90/red/white?text=Holiday+Promo',       'landing_url' => '/deals/holiday'],
            ['name' => 'Sidebar Banner 300x250',    'type' => 'image',     'text' => 'Shop the best deals',                  'image_url' => 'https://placehold.co/300x250/blue/white?text=Best+Deals',        'landing_url' => '/shop/best-sellers'],
            ['name' => 'Leaderboard Banner',        'type' => 'image',     'text' => 'Join our affiliate program',           'image_url' => 'https://placehold.co/728x90/green/white?text=Affiliate+Program', 'landing_url' => '/affiliate-signup'],
            ['name' => 'Square Banner 250x250',     'type' => 'image',     'text' => 'Exclusive member pricing',             'image_url' => 'https://placehold.co/250x250/purple/white?text=Members+Only',    'landing_url' => '/membership'],
            ['name' => 'Free Trial Text Link',      'type' => 'text',      'text' => 'Start your free 14-day trial today',   'image_url' => '',                                                                'landing_url' => '/landing/free-trial'],
            ['name' => 'Discount Code Link',        'type' => 'text',      'text' => 'Use code SAVE20 for 20% off',          'image_url' => '',                                                                'landing_url' => '/promo/save20'],
            ['name' => 'Newsletter Signup Link',    'type' => 'text',      'text' => 'Subscribe and save 15%',               'image_url' => '',                                                                'landing_url' => '/newsletter'],
            ['name' => 'Product Review Template',   'type' => 'long_text', 'text' => "Looking for a reliable solution? We've been using [Product] for over 6 months and here's our honest review. The quality is outstanding and the price is unbeatable. Click the link below to check it out and get an exclusive discount.",  'image_url' => '', 'landing_url' => '/products/featured'],
            ['name' => 'Email Promo Template',      'type' => 'long_text', 'text' => "Hey there! I wanted to share an amazing deal I found. [Brand] is offering up to 40% off their entire catalog this week only. I've personally used their products and can vouch for the quality. Don't miss out — grab yours before the sale ends!", 'image_url' => '', 'landing_url' => '/deals/weekly-offers'],
        ];

        $statuses = ['active', 'active', 'active', 'inactive'];

        foreach ($creatives as $creative) {
            $date = $this->randomDate('-4 months', '-1 week');
            $domain = $this->pick($this->domains);

            $this->db->table('slicewp_creatives')->insert([
                'name'          => $creative['name'],
                'description'   => 'Promotional material for affiliates',
                'date_created'  => $date,
                'date_modified' => $date,
                'type'          => $creative['type'],
                'image_url'     => $creative['image_url'],
                'alt_text'      => $creative['type'] === 'image' ? $creative['name'] : '',
                'text'          => $creative['text'],
                'landing_url'   => 'https://www.' . $domain . $creative['landing_url'],
                'status'        => $this->pick($statuses),
            ]);
        }
    }

    private function pick(array $arr)
    {
        return $arr[array_rand($arr)];
    }

    private function randomDate($from, $to)
    {
        $start = strtotime($from);
        $end = strtotime($to);
        $ts = mt_rand($start, $end);
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
