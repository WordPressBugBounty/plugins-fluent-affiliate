<?php

namespace FluentAffiliate\Database\Seeder;

use DateTime;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Models\Visit;

class AffiliateSeeder extends Seeder
{
    public function seed()
    {
        try {
			\WP_CLI::line('Seeding Users');
            $this->createWPUser();
			\WP_CLI::line('Seeding Affiliates');
            $this->createAffiliate();
			\WP_CLI::line('Seeding Customers');
            $this->createCustomer();
			\WP_CLI::line('Seeding Visits');
            $this->createVisit();
			\WP_CLI::line('Seeding Referrals');
            $this->createReferrals();
			\WP_CLI::line('Finalizing Seeds');
			$this->syncVisit();
			$this->syncAffiliate();
        } catch (\Exception $e) {
            throw $e;
        }

	    \WP_CLI::line('Seeding Completed');
    }

    public function freshSeed()
    {
	    \WP_CLI::line('Truncating Tables');
        try {
            Affiliate::query()->truncate();
            Customer::query()->truncate();
            Referral::query()->truncate();
            Visit::query()->truncate();
	        Transaction::query()->truncate();
	        Payout::query()->truncate();
			User::query()->truncate();
	        \WP_CLI::line('Seeding Tables');
            $this->seed();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function createWPUser($count = 100)
    {
        $faker = \Faker\Factory::create();
        $users = [];

		$admin = [
			'user_login'          => 'admin',
			'user_pass'           => md5('admin'),
			'user_nicename'       => 'admin',
			'user_email'          => 'admin@admin.com',
			'user_registered'     => $faker->dateTimeThisYear->format('Y-m-d H:i:s'),
			'user_activation_key' => $faker->md5,
			'user_status'         => $faker->numberBetween(0, 1),
			'display_name'        => 'admin',
			'url'            => $faker->url,
		];

		$users[] = $admin;

        for ($i = 0; $i < $count; $i++) {
            $users[] = [
                'user_login'          => $faker->userName,
                'user_pass'           => md5(0022),
                'user_nicename'       => $faker->name,
                'user_email'          => $faker->email,
                'url'            => $faker->url,
                'user_registered'     => $faker->dateTimeThisYear->format('Y-m-d H:i:s'),
                'user_activation_key' => $faker->md5,
                'user_status'         => $faker->numberBetween(0, 1),
                'display_name'        => $faker->name,
            ];
        }

        User::query()->insert($users);
    }

    public function createAffiliate($state = [])
    {
        $faker = \Faker\Factory::create();

	    $startDate = (new DateTime('-30 days'))->format('Y-m-d');
	    $endDate = (new DateTime('now'))->format('Y-m-d');

        $affiliates = [];
        $group = null;


	    if ( username_exists( 'admin' ) ) {

			$adminUser =  get_user_by('user_login', 'admin' );

			if($adminUser){
				$adminUser->set_role( 'administrator' );

				$payload = [
					'user_id'         => $adminUser->ID,
					'rate'            => $faker->randomFloat(2, 0, 100),
					'rate_type'       => $faker->randomElement(['flat', 'percentage']),
					'payment_email'   => $faker->email,
					'status'          => $faker->randomElement(['active', 'cancelled']),
					'settings'        => maybe_serialize(['send_email_notification' => $faker->numberBetween(0, 1)]),
					'note'            => $faker->text(100),
					'created_at'      => $faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s'),
				];

				$affiliates[] = $payload;
			}
	    }

        for ($i = 0; $i < 100; $i++) {

            $affiliates[] = [
                'contact_id'      => null,
                'user_id'         => $faker->randomElement(User::all()->pluck('ID')->toArray()),
                'group_id'        => null,//$group,
                'rate'            => $faker->randomFloat(2, 0, 100),
                'total_earnings'  => 0,//$faker->randomFloat(2, 0, 100),
                'unpaid_earnings' => 0,//$faker->randomFloat(2, 0, 100),
                'referrals'       => $faker->numberBetween(0, 100),
                'visits'          => $faker->numberBetween(0, 100),
                'rate_type'       => $faker->randomElement(['flat', 'percentage']),
                'custom_param'    => null,
                'payment_email'   => $faker->email,
                'status'          => $faker->randomElement(['active', 'cancelled']),
                'settings'        => maybe_serialize(['send_email_notification' => $faker->numberBetween(0, 1)]),
                'note'            => $faker->text(100),
                'created_at'      => $faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s'),
            ];
        }

        Affiliate::query()->insert($affiliates);
    }

    public function createReferrals()
    {

        $faker = \Faker\Factory::create();

	    $startDate = (new DateTime('-30 days'))->format('Y-m-d');
	    $endDate = (new DateTime('now'))->format('Y-m-d');

        $referrals = [];

		foreach (Affiliate::all() as $affiliate) {

			$range = $faker->numberBetween(10, 25);

			foreach (range(1, $range) as $i) {

				$visit = $faker->randomElement(Visit::where('affiliate_id', $affiliate->id)->get());

				if(!$visit) continue;

				$referrals[] = [
					'affiliate_id'          => $visit->affiliate_id,
					'parent_id'             => null,
					'customer_id'           => $faker->randomElement(Customer::all()->pluck('id')->toArray()),
					'visit_id'              => $visit->id,
					'description'           => $faker->text(100),
					'status'                => $faker->randomElement(['pending', 'unpaid', 'rejected']),
					'amount'                => $faker->randomFloat(2, 0, 100),
					'order_total'           => $faker->randomFloat(2, 0, 100),
					'currency'              => $faker->currencyCode,
					'utm_campaign'          => $faker->word,
					'provider'              => $faker->word,
					'provider_id'           => $faker->randomNumber(),
					'provider_sub_id'       => $faker->word,
					'products'              => null,
					'payout_transaction_id' => null,
					'payout_id'             => null,
					'type'                  => $faker->randomElement(['sale', 'lead', 'opt-in']),
					'settings'              => null,
					'created_at'            => $faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s'),
				];
			}
		}

        Referral::query()->insert($referrals);

    }

    public function createCustomer()
    {
        $faker = \Faker\Factory::create();

	    $startDate = (new DateTime('-30 days'))->format('Y-m-d');
	    $endDate = (new DateTime('now'))->format('Y-m-d');

        $customers = [];

        foreach (range(1, 20) as $i) {
            $customers[$i] = [
                'user_id'         => $faker->randomElement(User::all()->pluck('ID')->toArray()),
                'by_affiliate_id' => $faker->randomElement(Affiliate::all()->pluck('id')->toArray()),
                'email'           => $faker->email,
                'first_name'      => $faker->firstName,
                'last_name'       => $faker->lastName,
                'ip'              => $faker->ipv4,
                'created_at'      => $faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s'),
            ];
        }

        Customer::query()->insert($customers);
    }

    public function createVisit()
    {
        $visits = [];
        $faker = \Faker\Factory::create();

	    $startDate = (new DateTime('-30 days'))->format('Y-m-d');
	    $endDate = (new DateTime('now'))->format('Y-m-d');
		foreach (Affiliate::all() as $affiliate) {
			$range = $faker->numberBetween(10, 25);
			foreach (range(1, $range) as $i) {
				$visits[] = [
					'affiliate_id' => $affiliate->id,
					'user_id'      => $affiliate->user_id,
					'referral_id'  => null,
					'url'          => $faker->url,
					'referrer'     => $faker->url,
					'ip'           => $faker->ipv4,
					'created_at'   => $faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s')
				];
			}
		}

        Visit::query()->insert($visits);
    }

	public function syncVisit()
	{
		foreach (Referral::all() as $referral) {
			$visit = Visit::query()->where('affiliate_id', $referral->visit_id)->first();

			if($visit) {
				$visit->referral_id = $referral->id;
				$visit->save();
			}
		}
	}

	public function syncAffiliate()
	{
		Affiliate::all()->each(function($affiliate){
			$affiliate->recountEarnings();
		});
	}
}
