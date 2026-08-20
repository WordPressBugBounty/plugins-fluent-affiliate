<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class SuperAdminPolicy extends Policy
{
    /**
     * Gate for site-level operations. Requires the WordPress manage_options
     * capability, never the delegable manage_all_data meta flag that
     * AdminPolicy accepts.
     *
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request): bool
    {
        return current_user_can('manage_options');
    }
}
