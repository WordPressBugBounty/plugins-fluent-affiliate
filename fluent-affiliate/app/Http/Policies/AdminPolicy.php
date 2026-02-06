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

    public function getUsersOptions(Request $request): bool
    {
        $hasAccess = PermissionManager::hasAccess();

        return $hasAccess['affiliate'] || $hasAccess['referral'] || $hasAccess['payout'];
    }

    public function getAffiliatesOptions(Request $request): bool
    {
        $hasAccess = PermissionManager::hasAccess();

        return $hasAccess['affiliate'] || $hasAccess['referral'] || $hasAccess['payout'];
    }
}
