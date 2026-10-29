<?php

namespace FluentAffiliate\App\Http\Policies;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\Framework\Foundation\Policy;
use FluentAffiliate\Framework\Http\Request\Request;

class UserPolicy extends Policy
{
    /**
     * The portal page shows a pending or inactive affiliate only a notice, so the
     * portal routes serve active affiliates only
     *
     * @param  \FluentAffiliate\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        return Affiliate::query()
            ->where('user_id', get_current_user_id())
            ->where('status', 'active')
            ->exists();
    }
}
