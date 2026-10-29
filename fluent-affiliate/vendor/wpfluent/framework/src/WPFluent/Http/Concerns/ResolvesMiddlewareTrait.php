<?php

namespace FluentAffiliate\Framework\Http\Concerns;

use InvalidArgumentException;
use FluentAffiliate\Framework\Support\Arr;

trait ResolvesMiddlewareTrait
{
    /**
     * Collect the middleware to run for the given phase: the global ones
     * first, then the route's own, in the order they were added.
     *
     * A route entry written twice, or already listed globally, runs once:
     * the same alias or class name, or the same object. Two separate
     * instances, even equal ones, both run.
     *
     * @param  string  $type  "before" or "after".
     * @return array
     */
    protected function collectMiddleware($type = 'before')
    {
        $stack = Arr::get($this->middlewareConfig(), "global.{$type}", []);

        $written = $stack;

        foreach ($this->middleware[$type] as $middleware) {
            if (in_array($middleware, $written, true)) {
                continue;
            }

            $written[] = $middleware;

            $stack[] = $this->resolveRouteMiddleware($middleware, $type);
        }

        return $stack;
    }

    /**
     * Get the plugin's middleware configuration.
     *
     * @return array
     */
    protected function middlewareConfig()
    {
        return $this->app->bound('http.middleware')
            ? $this->app['http.middleware']
            : [];
    }

    /**
     * Turn a route middleware entry into something the pipeline can run.
     *
     * @param  mixed  $middleware  An object or closure, a class name, or an
     *                             alias with optional ":params".
     * @param  string  $type  "before" or "after".
     * @return mixed
     */
    protected function resolveRouteMiddleware($middleware, $type)
    {
        if (is_object($middleware)) {
            return $middleware;
        }

        if (class_exists($middleware)) {
            return $this->resolveMiddlewareFrom($middleware);
        }

        return $this->resolveMiddlewareAlias($middleware, $type);
    }

    /**
     * Resolve an alias such as "can:edit_posts" from the route aliases.
     *
     * @param  string  $middleware
     * @param  string  $type  "before" or "after".
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function resolveMiddlewareAlias($middleware, $type)
    {
        $pieces = explode(':', $middleware);

        $handler = Arr::get($this->middlewareAliases($type), $pieces[0]);

        if (is_null($handler)) {
            throw new InvalidArgumentException(
                "No middleware is assigned for the key: {$pieces[0]} in app/Http/middleware.php route.{$type} array."
            );
        }

        return isset($pieces[1])
            ? $this->resolveMiddleware($handler, $pieces)
            : $handler;
    }

    /**
     * Get the route middleware aliases, per phase when the config splits
     * them into "before" and "after".
     *
     * @param  string  $type  "before" or "after".
     * @return array
     */
    protected function middlewareAliases($type)
    {
        $aliases = Arr::get($this->middlewareConfig(), 'route', []);

        return $aliases[$type] ?? $aliases;
    }

    /**
     * Resolve a middleware from a class.
     *
     * The class is built by the container when the middleware runs, so its
     * constructor dependencies are injected the same way as for an alias.
     *
     * @param  string  $class
     * @return \Closure
     */
    protected function resolveMiddlewareFrom($class)
    {
        return function ($request, $next, ...$params) use ($class) {
            return $this->app->make($class)->handle($request, $next, ...$params);
        };
    }

    /**
     * Resolve the middleware
     *
     * @param mixed $handler
     * @param array $pieces
     * @return object
     */
    protected function resolveMiddleware($handler, $pieces)
    {
        if (is_object($handler)) {
            $handler = $this->wrapMiddleware($handler, $pieces);
        } elseif (is_string($handler)) {
            $handler = $handler . ':' . str_replace(' ', '', end($pieces));
        }

        return $handler;
    }

    /**
     * Bind the ":params" of an alias to the middleware it names, so the
     * pipeline can run it with just the request and $next.
     *
     * @param  callable|object  $handler
     * @param  array  $pieces  The alias split at ":"; the last part holds
     *                         the comma-separated params.
     * @return \Closure
     */
    protected function wrapMiddleware($handler, $pieces)
    {
        $params = explode(',', str_replace(' ', '', end($pieces)));

        return function ($request, $next) use ($handler, $params) {
            if (is_callable($handler)) {
                return $handler($request, $next, ...$params);
            }

            if (!method_exists($handler, 'handle')) {
                throw new InvalidArgumentException(
                    'The ' . get_class($handler) . ' must implement the handle method.'
                );
            }

            return $handler->handle($request, $next, ...$params);
        };
    }
}
