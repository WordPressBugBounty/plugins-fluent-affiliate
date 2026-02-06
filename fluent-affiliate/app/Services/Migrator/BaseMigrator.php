<?php

namespace FluentAffiliate\App\Services\Migrator;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Container\Contracts\BindingResolutionException;

abstract class BaseMigrator {

    protected $migratorPrefix = 'affwp';
    protected $timeLimit = 40;
    protected $startTimeStamp = 0;

    public function setTimeLimit($timeLimit)
    {
        $this->timeLimit = $timeLimit;
        $this->startTimeStamp = time();
    }

    public function isTimeLimitExceeded($offset = 10)
    {
        $timeLimit = $this->timeLimit - $offset;
        $currentTime = time();
        $timeElapsed = $currentTime - $this->startTimeStamp;

        return $timeElapsed >= $timeLimit;
    }

    /**
     * @throws BindingResolutionException
     */
    public function truncateAffiliates() {
        if ( ! \FluentAffiliate\App\Models\Affiliate::count() ) {
            return;
        }

        $this->updateCurrentStatus([
            'migrated_affiliates' => 0,
        ]);

        FluentAffiliate( 'db' )->table( 'fa_affiliates' )->truncate();
    }

    /**
     * @throws BindingResolutionException
     */
    public function truncateVisits() {
        if ( ! \FluentAffiliate\App\Models\Visit::count() ) {
            return;
        }

        $this->updateCurrentStatus([
            'migrated_visits' => 0,
        ]);

        FluentAffiliate( 'db' )->table( 'fa_visits' )->truncate();
    }

    /**
     * @throws BindingResolutionException
     */
    public function truncateCustomers() {
        if ( ! \FluentAffiliate\App\Models\Customer::count() ) {
            return;
        }

        $this->updateCurrentStatus([
            'migrated_customers' => 0,
        ]);

        FluentAffiliate( 'db' )->table( 'fa_customers' )->truncate();
    }

    /**
     * @throws BindingResolutionException
     */
    public function truncatePayouts() {
        if ( ! \FluentAffiliate\App\Models\Payout::count() ) {
            return;
        }

        $this->updateCurrentStatus([
            'migrated_payout_id' => 0,
        ]);

        FluentAffiliate( 'db' )->table( 'fa_payouts' )->truncate();
        FluentAffiliate( 'db' )->table( 'fa_payout_transactions' )->truncate();
    }

    /**
     * @throws BindingResolutionException
     */
    public function truncateReferrals() {
        if ( ! \FluentAffiliate\App\Models\Referral::count() ) {
            return;
        }

        $this->updateCurrentStatus([
            'migrated_referrals' => 0,
        ]);

        FluentAffiliate( 'db' )->table( 'fa_referrals' )->truncate();
    }


    /**
     * get the database connection
     * for better IDE support
     * @return \FluentAffiliate\Framework\Database\ConnectionInterface
     * @throws BindingResolutionException
     */
    public function db()
    {
        return FluentAffiliate( 'db' );
    }

    abstract public function migrateAffiliates();

    abstract public function migrateReferrals();

    abstract public function migrateCustomers();

    abstract public function migratePayouts();

    abstract public function migrateVisits();

    public function getCurrentStatus()
    {
        $defults = [
            'migrated_affiliates' => 0,
            'migrated_referrals'  => 0,
            'migrated_visits'     => 0,
            'migrated_payout_id'  => 0,
            'migrated_customers'  => 0,
            'current_stage'       => 'affiliates',
        ];

        $status = (array)get_option('_fla_' . $this->migratorPrefix . '_migrations_status', $defults);

        $status = wp_parse_args($status, $defults);

        return $status;
    }

    public function updateCurrentStatus($newData, $resync = true)
    {
        if ($resync) {
            $status = $this->getCurrentStatus();
            $newData = wp_parse_args($newData, $status);
        }

        $newData = Arr::only($newData, [
            'migrated_affiliates',
            'migrated_referrals',
            'migrated_visits',
            'migrated_payout_id',
            'migrated_customers',
            'current_stage'
        ]);

        update_option('_fla_' . $this->migratorPrefix . '_migrations_status', $newData, 'no');

        return $newData;
    }
}
