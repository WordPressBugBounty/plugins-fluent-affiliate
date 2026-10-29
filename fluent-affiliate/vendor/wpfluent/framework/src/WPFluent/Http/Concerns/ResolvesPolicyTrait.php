<?php

namespace FluentAffiliate\Framework\Http\Concerns;

use Closure;
use BadMethodCallException;
use InvalidArgumentException;
use FluentAffiliate\Framework\Support\Str;

trait ResolvesPolicyTrait
{
    /**
     * Resolve what the route's policy runs: its default handler, a callable
     * or function as given, an explicit "Policy@method", or the policy
     * method named like the controller method.
     *
     * @param  mixed  $policy
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function getPolicyHandler($policy)
    {
        if (!$policy) {
            return [$this, 'defaultPolicyHandler'];
        }

        if (is_callable($policy)) {
            return $policy;
        }

        if ($function = $this->policyFunction($policy)) {
            return $function;
        }

        if ($this->isPolicyHandlerParseable($policy)) {
            return $policy;
        }

        if (is_string($policy) && $this->handler instanceof Closure) {
            return $this->closureRoutePolicy($policy);
        }

        return $policy . '@' . $this->controllerMethod();
    }

    /**
     * Find the plain function a policy names, if it names one.
     *
     * A bare function name may have been given the plugin's policy
     * namespace when the route was declared, so its last segment is
     * tried as well.
     *
     * @param  mixed  $policy
     * @return string|null
     */
    protected function policyFunction($policy)
    {
        if (!is_string($policy)) {
            return null;
        }

        $function = Str::afterLast($policy, '\\');

        return function_exists($function) ? $function : null;
    }

    /**
     * Resolve the policy for a closure route.
     *
     * A closure has no method name to match a policy method to, so the
     * policy class must answer through verifyRequest().
     *
     * @param  string  $policy
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected function closureRoutePolicy($policy)
    {
        if (class_exists($policy) && method_exists($policy, 'verifyRequest')) {
            return $policy . '@verifyRequest';
        }

        throw new InvalidArgumentException(
            'Explicit policy handler is required while using a closure as route callback.'
        );
    }

    /**
     * Get the name of the controller method the route calls.
     *
     * @return string
     */
    protected function controllerMethod()
    {
        if (is_array($this->handler)) {
            return $this->handler[1] ?? '__invoke';
        }

        return Str::parseCallback($this->handler, '__invoke')[1];
    }

    /**
     * Check if the policy handler is parseable.
     * 
     * @param  string  $policyHandler
     * @return boolean
     */
    protected function isPolicyHandlerParseable($policyHandler)
    {
        return (strpos($policyHandler, '@') !== false
            || strpos($policyHandler, '::') !== false);
    }

    /**
     * Default/Fallback policy handler for the route
     *
     * @return bool
     */
    public function defaultPolicyHandler()
    {
        return true;
    }

    /**
     * Resolve the route's policy into the callable the permission check
     * runs, and return it in the form the endpoint listing shows.
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    protected function resolvePolicyHandler()
    {
        $this->permissionHandler = $this->callablePolicy();

        return $this->describePolicy($this->permissionHandler);
    }

    /**
     * Build the callable the route's policy runs.
     *
     * A policy method the route only implies (NotePolicy on
     * NoteController@find means NotePolicy@find) falls back to
     * verifyRequest() when the policy has no such method. A method named
     * after "@" ("NotePolicy@publish") must exist: it is kept as written,
     * so a missing one fails here instead of quietly running
     * verifyRequest().
     *
     * @return callable
     *
     * @throws \BadMethodCallException
     */
    protected function callablePolicy()
    {
        $handler = $this->app->parsePolicyHandler(
            $this->getPolicyHandler($this->policyHandler)
        );

        $method = $this->explicitPolicyMethod();

        if (is_array($handler) && !is_null($method)) {
            $handler[1] = $method;
        }

        if (!is_callable($handler)) {
            throw $this->invalidPolicyHandlerException($handler);
        }

        return $handler;
    }

    /**
     * Get the policy method the route names after "@", if it names one.
     *
     * @return string|null
     */
    protected function explicitPolicyMethod()
    {
        if (!is_string($this->policyHandler)) {
            return null;
        }

        return explode('@', $this->policyHandler)[1] ?? null;
    }

    /**
     * Get a policy callable in the form the endpoint listing shows, with
     * an object named by its class.
     *
     * @param  mixed  $handler
     * @return mixed
     */
    protected function describePolicy($handler)
    {
        if (is_array($handler) && is_object($handler[0])) {
            $handler[0] = get_class($handler[0]);
        }

        return $handler;
    }

    /**
     * Create the exception for a policy that did not resolve to a callable.
     *
     * @param  mixed  $handler
     * @return \BadMethodCallException
     */
    protected function invalidPolicyHandlerException($handler)
    {
        $name = is_array($handler)
            ? implode('@', $this->describePolicy($handler))
            : $this->policyHandler;

        // A route whose policy can't run is broken: a 500, not a refusal.
        return new BadMethodCallException(
            "The permission callback {$name} is invalid or not callable.", 500
        );
    }
}
