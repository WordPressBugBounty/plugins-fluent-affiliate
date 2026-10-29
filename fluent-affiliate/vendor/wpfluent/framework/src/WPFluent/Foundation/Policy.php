<?php

namespace FluentAffiliate\Framework\Foundation;

use FluentAffiliate\Framework\Http\Request\Request;

abstract class Policy
{
    /**
     * Answer for a policy that does not decide itself: deny. A policy
     * grants access only by overriding this method or the route's method.
     *
     * @param  \FluentAffiliate\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return false;
    }
}
