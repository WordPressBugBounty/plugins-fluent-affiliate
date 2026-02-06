<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class ReferralPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request): bool
    {
        $readAccess = $request->method() === 'GET';
        return PermissionManager::hasReferralAccess($readAccess);
    }
}
