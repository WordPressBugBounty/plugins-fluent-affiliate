<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Http\Request\Request;

class AdminPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request): bool
    {
        return PermissionManager::userCan('manage_all_data');
    }

    /**
     * manage_all_data is a plugin permission that can be delegated, and
     * publishing a page on the site is a WordPress capability of its own.
     */
    public function createPage(Request $request): bool
    {
        return $this->verifyRequest($request) && current_user_can('publish_pages');
    }

    public function getUsersOptions(Request $request): bool
    {
        // only the affiliate form and the manager settings pick WordPress users
        return PermissionManager::hasAffiliateAccess();
    }

    public function getAffiliatesOptions(Request $request): bool
    {
        $hasAccess = PermissionManager::hasAccess();

        return $hasAccess['affiliate'] || $hasAccess['referral'] || $hasAccess['payout'];
    }

    public function getAffiliateGroupsOptions(Request $request): bool
    {
        $hasAccess = PermissionManager::hasAccess();

        return $hasAccess['affiliate'] || $hasAccess['referral'] || $hasAccess['payout'];
    }
}
