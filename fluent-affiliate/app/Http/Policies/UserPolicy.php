<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class UserPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param  \FluentAffiliate\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return is_user_logged_in();
    }

    /**
     * Check user permission for any method
     * @param  \FluentAffiliate\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function create(Request $request)
    {
        return is_user_logged_in();
    }
}
