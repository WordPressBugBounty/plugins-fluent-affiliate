<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class ReportPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param  \FluentAffiliate\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::hasAnyPermission();
    }
}
