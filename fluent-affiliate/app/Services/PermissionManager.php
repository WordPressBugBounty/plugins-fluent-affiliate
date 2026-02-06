<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Models\Meta;

class PermissionManager
{
    public static function allPermissionSets()
    {
        return [
            'read_all_affiliates'   => __('Read Access to All Affiliates', 'fluent-affiliate'),
            'manage_all_affiliates' => __('Read & Write Access to All Affiliates', 'fluent-affiliate'),
            'read_all_referrals'    => __('Read Access to All Referrals', 'fluent-affiliate'),
            'manage_all_referrals'  => __('Read & Write Access to All Referrals', 'fluent-affiliate'),
            'read_all_visits'       => __('Read Access to All Visits', 'fluent-affiliate'),
            'read_all_payouts'      => __('Read Access to All Payouts', 'fluent-affiliate'),
            'manage_all_payouts'    => __('Read & Write Access to All Payouts', 'fluent-affiliate'),
            'manage_all_data'       => __('Manage All Data and Settings', 'fluent-affiliate')
        ];
    }

    public static function setPermissions($permissions = [])
    {
        if (empty($permissions)) {
            return [];
        }

        $allPermissions = static::allPermissionSets();

        $allKeys = array_keys($allPermissions);
        $permissionSet = array_unique(array_intersect($permissions, $allKeys));

        if (in_array('manage_all_data', $permissionSet)) {
            return ['manage_all_data'];
        }

        $overrides = [
            'manage_all_affiliates' => 'read_all_affiliates',
            'manage_all_referrals'  => 'read_all_referrals',
            'manage_all_payouts'    => 'read_all_payouts',
            'manage_all_visits'     => 'read_all_visits'
        ];

        $toRemove = [];
        foreach ($permissionSet as $permission) {
            if (array_key_exists($permission, $overrides)) {
                $toRemove[] = $overrides[$permission];
            }
        }

        $permissionSet = array_values(array_diff($permissionSet, $toRemove));

        return $permissionSet;
    }

    /**
     * Check if user has access to all affiliate data
     *
     * @param bool $readAccess Whether to check for read access only
     * @return bool
     */
    public static function hasAffiliateAccess($readAccess = false)
    {
        $hasAffiliateAccess = static::userCan(['manage_all_affiliates', 'manage_all_data']);

        if ($readAccess) {
            $hasAffiliateAccess = $hasAffiliateAccess || static::userCan('read_all_affiliates');
        }

        return apply_filters('fluent_affiliate/has_all_affiliate_access', $hasAffiliateAccess);
    }

    /**
     * Check if user has access to all referral data
     *
     * @param bool $readAccess Whether to check for read access only
     * @return bool
     */
    public static function hasReferralAccess($readAccess = false)
    {
        $hasReferralAccess = static::userCan(['manage_all_referrals', 'manage_all_data']);

        if ($readAccess) {
            $hasReferralAccess = $hasReferralAccess || static::userCan('read_all_referrals');
        }

        return apply_filters('fluent_affiliate/has_all_referral_access', $hasReferralAccess);
    }

    /**
     * Check if user has access to all visit data
     *
     * @param bool $readAccess Whether to check for read access only
     * @return bool
     */
    public static function hasVisitAccess()
    {
        $hasVisitAccess = static::userCan(['read_all_visits', 'manage_all_data']);

        return apply_filters('fluent_affiliate/has_all_visit_access', $hasVisitAccess);
    }

    /**
     * Check if user has access to all payout data
     *
     * @param bool $readAccess Whether to check for read access only
     * @return bool
     */
    public static function hasPayoutAccess($readAccess = false)
    {
        $hasPayoutAccess = static::userCan(['manage_all_payouts', 'manage_all_data']);

        if ($readAccess) {
            $hasPayoutAccess = $hasPayoutAccess || static::userCan('read_all_payouts');
        }

        return apply_filters('fluent_affiliate/has_all_payout_access', $hasPayoutAccess);
    }

    public static function hasAccess($readAccess = false) {
        return [
            'affiliate' => static::hasAffiliateAccess($readAccess),
            'referral'  => static::hasReferralAccess($readAccess),
            'payout'    => static::hasPayoutAccess($readAccess),
            'visit'     => static::hasVisitAccess()
        ];
    }

    public static function hasAnyPermission()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return !!static::getUserPermissions();
    }

    public static function isLoggedIn()
    {
        return is_user_logged_in();
    }

    public static function userCan($permissions)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $userPermissions = static::getUserPermissions();

        if (!$userPermissions) {
            return false;
        }

        if (is_string($permissions)) {
            return in_array($permissions, $userPermissions);
        }

        if (is_array($permissions)) {
            foreach ($permissions as $permission) {
                if (in_array($permission, $userPermissions)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getUserPermissions($user = null, $formatted = false)
    {
        if ($user === null) {
            $user = wp_get_current_user();
        }

        if (!$user || !$user->ID) {
            return [];
        }

        $allPermissions = static::allPermissionSets();

        if (user_can($user, 'manage_options')) {
            $permissions = array_merge(['admin' => __('All Access (Administrator)', 'fluent-affiliate')], $allPermissions);
            if ($formatted) {
                return $permissions;
            }

            return array_keys($permissions);
        }

        $permissions = static::getMetaPermissions($user->ID);

        if (!$permissions) {
            $hasAffiliateAccess = apply_filters('fluent_affiliate/user_has_affiliate_access', false, $user->ID);

            if (!$hasAffiliateAccess) {
                return [];
            }
        }

        if (!$formatted) {
            return $permissions;
        }

        $formattedPermissions = [];

        foreach ($permissions as $permission) {
            if (isset($allPermissions[$permission])) {
                $formattedPermissions[$permission] = $allPermissions[$permission];
            }
        }

        return $formattedPermissions;
    }

    public static function getMetaPermissions($userId = null)
    {
        static $cache = [];

        if ($userId === null) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return [];
        }

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $meta = Meta::where('object_type', 'user_meta')
            ->where('object_id', $userId)
            ->where('meta_key', '_fa_access_permissions')
            ->first();

        if ($meta) {
            $cache[$userId] = $meta->value;
            return $cache[$userId];
        }

        $cache[$userId] = [];
        return $cache[$userId];
    }

    public static function getMenuCapabilities()
    {
        $hasAccess = self::hasAccess(true);
        $hasAccess['dashboard'] = self::hasAnyPermission();
        $hasAccess['settings'] = self::isAdmin();

        $user = wp_get_current_user();
        $roles = (array) ($user->roles ?? []);
        $userRole = Arr::get(array_values($roles), 0, '');

        $menuCapabilities = [];
        $capabilities = array_keys($hasAccess);
        foreach ($capabilities as $capability) {
            $menuCapabilities[$capability] = !empty($hasAccess[$capability]) ? $userRole : '';
        }

        return $menuCapabilities;
    }

    public static function isAdmin()
    {
        return self::userCan('manage_all_data');
    }
}
