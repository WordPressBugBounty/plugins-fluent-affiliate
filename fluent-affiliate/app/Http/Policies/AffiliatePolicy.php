<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class AffiliatePolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request): bool
    {
        $readAccess = $request->method() === 'GET';

        return PermissionManager::hasAffiliateAccess($readAccess);
    }

    public function getReferrals(Request $request): bool
    {
        return PermissionManager::hasReferralAccess(true);
    }

    public function getVisits(Request $request): bool
    {
        return PermissionManager::hasVisitAccess(true);
    }

    public function getTransactions(Request $request): bool
    {
        return PermissionManager::hasPayoutAccess(true);
    }
}
