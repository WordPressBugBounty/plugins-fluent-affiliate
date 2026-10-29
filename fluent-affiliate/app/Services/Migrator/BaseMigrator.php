<?php

namespace FluentAffiliate\App\Services\Migrator;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Container\Contracts\BindingResolutionException;

abstract class BaseMigrator {

    protected $migratorPrefix = 'affwp';
    protected $timeLimit = 40;
    protected $startTimeStamp = 0;
    protected $affiliateIdMap = null;
    protected $affiliateIdMapDirty = false;

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
            'migrated_affiliate_groups' => 0,
            'migrated_affiliates'       => 0,
            'migrated_referrals'        => 0,
            'migrated_visits'           => 0,
            'migrated_payout_id'        => 0,
            'migrated_customers'        => 0,
            'migrated_creatives'        => 0,
            'current_stage'             => 'affiliate_groups',
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
            'migrated_affiliate_groups',
            'migrated_affiliates',
            'migrated_referrals',
            'migrated_visits',
            'migrated_payout_id',
            'migrated_customers',
            'migrated_creatives',
            'current_stage'
        ]);

        // A reset (empty payload, no resync) must also clear the paginated
        // recount cursor; otherwise a restarted migration resumes the recount
        // from a stale offset, leaving the first affiliates with zero or stale
        // earnings aggregates.
        if (empty($newData) && !$resync) {
            fluentAffiliate_update_option($this->migratorPrefix . '_migrated_recount', 0);
        }

        update_option('_fla_' . $this->migratorPrefix . '_migrations_status', $newData, 'no');

        return $newData;
    }

    /**
     * Source affiliate id => FluentAffiliate affiliate id, for the affiliates
     * whose source id was already taken in fa_affiliates and so had to be
     * imported under a new one. Empty for the normal case of migrating into an
     * install with no affiliates of its own.
     */
    protected function affiliateIdMap()
    {
        if ($this->affiliateIdMap === null) {
            $stored = get_option($this->affiliateMapOptionKey(), []);
            $this->affiliateIdMap = is_array($stored) ? $stored : [];
        }

        return $this->affiliateIdMap;
    }

    protected function rememberAffiliateId($sourceId, $newId)
    {
        $map = $this->affiliateIdMap();
        $map[(int) $sourceId] = (int) $newId;

        $this->affiliateIdMap = $map;
        $this->affiliateIdMapDirty = true;
    }

    /**
     * An affiliate that could not be imported maps to nothing, so its
     * referrals, visits, customers and payouts arrive unattributed instead of
     * on whichever affiliate holds that id now or is given it later.
     */
    protected function markAffiliateUnimported($sourceId)
    {
        $map = $this->affiliateIdMap();
        $map[(int) $sourceId] = 0;

        $this->affiliateIdMap = $map;
        $this->affiliateIdMapDirty = true;
    }

    protected function forgetAffiliateId($sourceId)
    {
        $map = $this->affiliateIdMap();

        if (!array_key_exists((int) $sourceId, $map)) {
            return;
        }

        unset($map[(int) $sourceId]);

        $this->affiliateIdMap = $map;
        $this->affiliateIdMapDirty = true;
    }

    /**
     * Whether a row already in fa_affiliates is the affiliate about to be
     * imported. The WordPress user decides it: a migration run again finds its
     * own earlier copy this way, and a row that was there first counts as the
     * same affiliate only when it belongs to the same person. Rows without a
     * user fall back to the payment email. When neither row has either there
     * is nothing to compare, so only the migration's own record of having put
     * that row there ($recorded) can vouch for it.
     */
    protected function isSameAffiliate($existing, $row, $recorded = false)
    {
        $existingUser = (int) $existing->user_id;
        $newUser = isset($row['user_id']) ? (int) $row['user_id'] : 0;

        if ($existingUser || $newUser) {
            return $existingUser === $newUser;
        }

        $existingEmail = (string) $existing->payment_email;
        $newEmail = isset($row['payment_email']) ? (string) $row['payment_email'] : '';

        if ($existingEmail !== '' || $newEmail !== '') {
            return $existingEmail === $newEmail;
        }

        return $recorded;
    }

    protected function hasNoIdentity($row)
    {
        return empty($row['user_id']) && empty($row['payment_email']);
    }

    /**
     * The maps describe rows in fa_affiliates, so they mean nothing once that
     * table has been emptied, and a stale one could vouch for a row a later
     * migration put on the same id.
     */
    public static function clearAffiliateIdMaps()
    {
        foreach (['affwp', 'wpam', 'solid_affiliate', 'slicewp', 'ultimate_affiliate'] as $prefix) {
            delete_option('_fla_' . $prefix . '_affiliate_id_map');
        }
    }

    /**
     * Written once per batch rather than per row: the whole map is serialised on
     * every write, so persisting it row by row rewrites a growing array each
     * time. The affiliates cursor is saved just after the batch too, so a run
     * that stops mid-batch re-derives the same mapping either way.
     */
    protected function flushAffiliateIdMap()
    {
        if (!$this->affiliateIdMapDirty) {
            return;
        }

        update_option($this->affiliateMapOptionKey(), $this->affiliateIdMap, 'no');

        $this->affiliateIdMapDirty = false;
    }

    /**
     * Translate a source affiliate id to the one it actually landed on. Child
     * rows must go through this or they would be attributed to whichever
     * affiliate already held that id.
     */
    protected function mapAffiliateId($sourceId)
    {
        $map = $this->affiliateIdMap();

        if (!isset($map[(int) $sourceId])) {
            return $sourceId;
        }

        return $map[(int) $sourceId] ?: null;
    }

    protected function affiliateMapOptionKey()
    {
        return '_fla_' . $this->migratorPrefix . '_affiliate_id_map';
    }

    /**
     * Per-provider source counts. Overridden by every bundled provider; the
     * default keeps a third-party migrator that predates this method working.
     */
    protected function getSourceCounts()
    {
        return [];
    }

    /**
     * The source plugin's tables are only guaranteed to exist while that plugin
     * is installed, and a slug naming an uninstalled one still reaches the
     * migration endpoints. Report zeros in that case instead of failing the
     * whole request, and guarantee every key the callers read.
     */
    public function getCounts()
    {
        $defaults = [
            'affiliate_groups' => 0,
            'affiliates'       => 0,
            'referrals'        => 0,
            'customers'        => 0,
            'payouts'          => 0,
            'visits'           => 0,
            'creatives'        => 0,
        ];

        try {
            $counts = (array) $this->getSourceCounts();
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Migration diagnostics; no PII
            error_log(sprintf(
                '[FluentAffiliate] %s migration could not read source counts: %s',
                $this->migratorPrefix,
                $e->getMessage()
            ));

            $counts = [];
        }

        return array_merge($defaults, $counts);
    }

    /**
     * Insert migrated affiliates. Each row carries the id it has in the source
     * as both id and __source_id. One that an earlier run already imported is
     * left alone, one whose id is free keeps it, and one whose id belongs to a
     * different affiliate is given a new id, recorded so the child stages can
     * follow it.
     */
    protected function insertAffiliates($rows)
    {
        if (empty($rows)) {
            return;
        }

        $map = $this->affiliateIdMap();

        $lookupIds = [];
        foreach ($rows as $row) {
            $sourceId = (int) $row['__source_id'];
            $lookupIds[] = $sourceId;

            if (!empty($map[$sourceId])) {
                $lookupIds[] = (int) $map[$sourceId];
            }
        }

        $existing = [];
        $found = $this->db()->table('fa_affiliates')
            ->whereIn('id', array_unique($lookupIds))
            ->get(['id', 'user_id', 'payment_email']);

        foreach ($found as $affiliate) {
            $existing[(int) $affiliate->id] = $affiliate;
        }

        $keepId = [];
        $assignId = [];

        foreach ($rows as $row) {
            $sourceId = (int) $row['__source_id'];
            unset($row['__source_id']);

            $mappedId = empty($map[$sourceId]) ? 0 : (int) $map[$sourceId];

            if ($mappedId && isset($existing[$mappedId]) && $this->isSameAffiliate($existing[$mappedId], $row, true)) {
                continue;
            }

            if (isset($existing[$sourceId])) {
                if ($this->isSameAffiliate($existing[$sourceId], $row)) {
                    $this->forgetAffiliateId($sourceId);
                    continue;
                }

                unset($row['id']);
                $assignId[] = [$sourceId, $row];
                continue;
            }

            $keepId[] = $row;
        }

        // Claim the ids this batch keeps before any is generated, or a generated
        // one could take an id another affiliate in the batch needs.
        $failedIds = array_flip($this->insertBatch('fa_affiliates', $keepId));

        foreach ($keepId as $row) {
            if (isset($failedIds[$row['id']])) {
                $this->markAffiliateUnimported($row['id']);
            } elseif ($this->hasNoIdentity($row)) {
                // Nothing on the row says whose it is, so a later run can only
                // recognise it from this record.
                $this->rememberAffiliateId($row['id'], $row['id']);
            } else {
                $this->forgetAffiliateId($row['id']);
            }
        }

        foreach ($assignId as $pending) {
            list($sourceId, $row) = $pending;

            try {
                $newId = $this->db()->table('fa_affiliates')->insertGetId($row);

                if ($newId) {
                    $this->rememberAffiliateId($sourceId, $newId);
                }
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Migration audit trail; ids only, no PII
                error_log(sprintf(
                    '[FluentAffiliate] %s migration could not import affiliate (source id: %s)',
                    $this->migratorPrefix,
                    $sourceId
                ));

                $this->markAffiliateUnimported($sourceId);
            }
        }

        $this->flushAffiliateIdMap();
    }

    /**
     * Bulk-insert a batch; on failure fall back to per-row inserts so one bad row
     * (e.g. a zero-date rejected under strict SQL mode) can't drop the whole batch.
     * Skipped source ids are logged (no PII) for auditing and returned.
     */
    protected function insertBatch($table, $rows)
    {
        if (empty($rows)) {
            return [];
        }

        try {
            $this->db()->table($table)->insert($rows);
            return [];
        } catch (\Exception $e) {
            // Bulk insert failed — retry row-by-row below.
        }

        $skipped = [];

        foreach ($rows as $row) {
            try {
                $this->db()->table($table)->insert($row);
            } catch (\Exception $e) {
                $skipped[] = isset($row['id']) ? $row['id'] : '?';
            }
        }

        if (!empty($skipped)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Migration audit trail; ids only, no PII
            error_log(sprintf(
                '[FluentAffiliate] %s migration skipped %d row(s) inserting into %s (source ids: %s)',
                $this->migratorPrefix,
                count($skipped),
                $table,
                implode(',', $skipped)
            ));
        }

        return $skipped;
    }

    protected function getAllowedResetTables()
    {
        return [
            'fa_affiliates', 'fa_referrals', 'fa_customers', 'fa_visits',
        ];
    }

    /**
     * Reset AUTO_INCREMENT to MAX(id)+1 so future organic inserts
     * don't collide with imported IDs.
     */
    protected function resetAutoIncrement($tableName)
    {
        if (!in_array($tableName, $this->getAllowedResetTables(), true)) {
            return;
        }

        $wpdb      = $GLOBALS['wpdb'];
        $table     = $wpdb->prefix . $tableName;
        $safeTable = esc_sql($table);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from allowlist, sanitized via esc_sql()
        $maxId = (int) $wpdb->get_var("SELECT MAX(id) FROM `{$safeTable}`");

        if ($maxId > 0) {
            $next = $maxId + 1;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from allowlist, sanitized via esc_sql()
            $wpdb->query($wpdb->prepare("ALTER TABLE `{$safeTable}` AUTO_INCREMENT = %d", $next));
        }
    }

    protected function hasTable($tableName)
    {
        $wpdb = $GLOBALS['wpdb'];
        $fullName = $wpdb->prefix . $tableName;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check; result not worth caching
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($fullName)));
    }
}
