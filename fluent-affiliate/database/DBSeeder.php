<?php

namespace FluentAffiliate\Database;

use FluentAffiliate\Database\Seeder\AffiliateSeeder;

class DBSeeder
{
    public static function run()
    {
        (new AffiliateSeeder())->seed();
    }
}
