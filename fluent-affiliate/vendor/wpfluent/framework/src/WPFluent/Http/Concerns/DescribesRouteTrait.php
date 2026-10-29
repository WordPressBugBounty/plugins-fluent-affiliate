<?php

namespace FluentAffiliate\Framework\Http\Concerns;

use Closure;
use Throwable;
use FluentAffiliate\Framework\Support\Str;

trait DescribesRouteTrait
{
    /**
     * Describe the route for the endpoint map: the controller, the action
     * and its URI, methods and policy. A closure route has no controller,
     * so it isn't described.
     *
     * @param  bool  $resolvePolicy  Name the policy method that will run.
     * @return array|null  [controller, action, details]
     */
    public function describe($resolvePolicy = false)
    {
        if ($this->handler instanceof Closure) {
            return null;
        }

        [$controller, $cb] = Str::parseCallback($this->parseAction($this->handler));

        return [str_replace('\\', '.', $controller), "_{$cb}", [
            'uri' => $this->uri,
            'methods' => explode(',', $this->method),
            'policy' => $resolvePolicy
                ? $this->getResolvedPolicyName()
                : $this->getPolicyName(),
        ]];
    }

    /**
     * Get a display name for the route's policy handler.
     *
     * @return string|null
     */
    protected function getPolicyName()
    {
        if (!$this->policyHandler) {
            return null;
        }

        if ($this->policyHandler instanceof Closure) {
            return 'Closure';
        }

        $name = $this->policyHandler;

        if (is_string($name) && !$this->app->hasNamespace($name)) {
            $name = $this->app->__namespace__ . '\\App\\Http\\Policies\\' . $name;
        }

        return $name;
    }

    /**
     * Parse the action from the handler.
     * 
     * @param  mixed $handler
     * @return string
     */
    protected function parseAction($handler)
    {
        $action = $this->app->parseRestHandler($handler, $this->namespace);
        $action = trim($action, '\\');

        if (!str_contains($action, '@')) {
            $action .= '@__invoke';
        }

        return $action;
    }

    /**
     * Get the policy as Class@method, the callable the route will run, or
     * its display name when it doesn't resolve to one.
     *
     * @return string|null
     */
    protected function getResolvedPolicyName()
    {
        if (!$this->policyHandler || $this->policyHandler instanceof Closure) {
            return $this->getPolicyName();
        }

        try {
            $policy = $this->describePolicy($this->callablePolicy());
        } catch (Throwable $e) {
            return $this->getPolicyName();
        }

        return is_array($policy) ? implode('@', $policy) : $this->getPolicyName();
    }
}
