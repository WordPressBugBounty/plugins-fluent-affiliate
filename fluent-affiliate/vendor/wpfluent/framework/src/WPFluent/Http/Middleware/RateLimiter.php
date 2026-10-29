<?php

namespace FluentAffiliate\Framework\Http\Middleware;

use Throwable;
use FluentAffiliate\Framework\Foundation\App;

/**
 * Class RateLimiter
 *
 * Handles per-IP and per-endpoint rate limiting for REST requests.
 *
 * Features:
 * - IP + endpoint aware
 * - Retry-After header for 429 responses
 * - X-RateLimit-Limit and X-RateLimit-Remaining headers
 * - Optional bypass for admin users
 * - Transient-based storage, safe for PHP 7.4+
 */
class RateLimiter
{
    /**
     * Maximum requests allowed per interval.
     *
     * @var int
     */
    protected $limit;

    /**
     * Interval in seconds for rate limiting.
     *
     * @var int
     */
    protected $interval;

    /**
     * Constructor.
     *
     * @param int $limit
     * @param int $interval
     */
    public function __construct($limit, $interval)
    {
        $this->limit = (int) $limit;
        $this->interval = (int) $interval;
    }

    /**
     * Handle incoming request.
     *
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        // OPTIONS, and the site's own screens (cookie + REST nonce), are free
        if ($this->shouldAllow($request)) {
            return $next($request);
        }

        // WordPress runs the permission check again for the Allow header,
        // after the request was answered; that run isn't a request.
        if (doing_filter('rest_post_dispatch')) {
            return $next($request);
        }

        $currentTime = time();
        $settings = $this->getSettings($request, $currentTime);

        // Reset interval if expired, otherwise increment
        if ($this->isIntervalExpired($settings, $currentTime)) {
            $settings = $this->resetRateLimit($currentTime);
        } else {
            $settings['count']++;
        }

        // Update transient with correct TTL
        $this->updateSettings($request, $settings, $currentTime);

        // Check if limit exceeded
        if ($this->isRateLimitExceeded($settings)) {
            $retryAfter = $this->interval - ($currentTime - $settings['firstTime']);
            $response = $request->abort(429, 'Too many requests.');
            $response->header('Retry-After', max(1, $retryAfter));
            $response->header('X-RateLimit-Limit', $this->limit);
            $response->header('X-RateLimit-Remaining', 0);
            return $response;
        }

        // Inject rate limit headers into the actual route response via WP hook,
        // because $next() in a before-middleware returns bool (permission check
        // result), not the WP_REST_Response produced by the route callback.
        $limit = $this->limit;
        $remaining = max(0, $this->limit - $settings['count']);

        add_filter('rest_post_dispatch', function ($response) use ($limit, $remaining) {
            $response->header('X-RateLimit-Limit', $limit);
            $response->header('X-RateLimit-Remaining', $remaining);
            return $response;
        });

        return $next($request);
    }

    /**
     * Determine if request should bypass rate limiting.
     *
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     * @return bool
     */
    protected function shouldAllow($request)
    {
        // HEAD isn't free: WordPress answers it with the GET route, whose
        // controller runs. OPTIONS never reaches the controller.
        return $this->isCookieAuthenticated() || $request->method() === 'OPTIONS';
    }

    /**
     * Check if user is authenticated in admin (optional bypass).
     *
     * @return bool
     */
    protected function isCookieAuthenticated()
    {
        return is_user_logged_in() && !empty($GLOBALS['wp_rest_auth_cookie']);
    }

    /**
     * Get current rate limit settings from transient.
     *
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     * @param int $currentTime
     * @return array
     */
    protected function getSettings($request, $currentTime)
    {
        $settings = get_transient($this->makeTransientKey($request));
        return $settings ?: ['count' => 0, 'firstTime' => $currentTime];
    }

    /**
     * Check if interval expired.
     *
     * @param array $settings
     * @param int $currentTime
     * @return bool
     */
    protected function isIntervalExpired($settings, $currentTime)
    {
        return ($currentTime - $settings['firstTime']) > $this->interval;
    }

    /**
     * Reset rate limit for a new interval.
     *
     * @param int $currentTime
     * @return array
     */
    protected function resetRateLimit($currentTime)
    {
        return ['count' => 1, 'firstTime' => $currentTime];
    }

    /**
     * Check if limit exceeded.
     *
     * @param array $settings
     * @return bool
     */
    protected function isRateLimitExceeded($settings)
    {
        return $settings['count'] > $this->limit;
    }

    /**
     * Update transient with proper TTL.
     *
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     * @param array $settings
     * @param int $currentTime
     */
    protected function updateSettings($request, $settings, $currentTime)
    {
        $ttl = $this->interval - ($currentTime - $settings['firstTime']);
        set_transient(
            $this->makeTransientKey($request),
            $settings,
            max(1, $ttl)
        );
    }

    /**
     * The route the request matched, by method and pattern, so all the
     * URLs of a route, such as /orders/1 and /orders/2, share its limit.
     * Outside a route, the request's own path.
     *
     * @param  \FluentAffiliate\Framework\Http\Request\Request  $request
     * @return string
     */
    protected function endpoint($request)
    {
        // The route the permission check bound in the request's own app,
        // not the App facade's, which may be another plugin's.
        try {
            $route = $request->route();
        } catch (Throwable $e) {
            $route = null;
        }

        if (!is_object($route) || !method_exists($route, 'getUrl')) {
            return $request->getRoute() ?: 'unknown';
        }

        // A HEAD request runs the GET route, so it shares its count.
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();

        return $method . ' ' . $route->getUrl();
    }

    /**
     * Generate a unique transient key per IP + endpoint.
     *
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     * @return string
     */
    protected function makeTransientKey($request)
    {
        $slug = App::config()->get('app.slug');

        return "{$slug}_rate_limit_" . md5(
            $request->getIp() . '|' . $this->endpoint($request)
        );
    }
}
