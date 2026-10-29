<?php

namespace FluentAffiliate\Framework\Http;

/**
 * The policy's answer on its way back out through the before-middleware.
 *
 * Only Route::checkPolicy() creates one, so a middleware that returns
 * anything other than what $next() gave it cannot pass for the policy.
 *
 * @internal
 */
class PolicyVerdict
{
    /**
     * The policy's result: a bool, a user id or null.
     *
     * @var mixed
     */
    protected $result;

    /**
     * Create a verdict holding the policy's result.
     *
     * @param  mixed  $result
     * @return void
     */
    public function __construct($result)
    {
        $this->result = $result;
    }

    /**
     * Get the policy's result.
     *
     * @return mixed
     */
    public function result()
    {
        return $this->result;
    }
}
