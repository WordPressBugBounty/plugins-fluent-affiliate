<?php

namespace FluentAffiliate\Database;

use FluentAffiliate\App\App;
use FluentAffiliate\Database\Seeder\AffiliateSeeder;

class DBSeeder
{
    public static function run()
    {
        (new AffiliateSeeder())->seed();
        //App::make(AffiliateSeeder::class)->seed();
    }
}
