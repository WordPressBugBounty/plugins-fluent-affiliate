<?php

namespace FluentAffiliate\Framework\Http\Response;

use WP_REST_Response;
use InvalidArgumentException;
use FluentAffiliate\Framework\Foundation\App;
use FluentAffiliate\Framework\Http\Request\File;
use FluentAffiliate\Framework\Foundation\Exceptions\NotFoundHttpException;

/**
 * Responses that leave the usual JSON answer: a file download and a
 * redirect.
 */
trait DownloadsAndRedirectsTrait
{
    /**
     * Send a file as the response.
     *
     * From a route's controller, the file response is returned, so the
     * after-middleware and queued headers and cookies apply, and the file
     * is streamed when WordPress sends the response; it is sent even when
     * the controller doesn't return it. Anywhere else, such as an ajax or
     * admin-post handler, the file is sent at once and the request ends.
     * A path that is not a readable file is answered with a 404.
     *
     * @param string|\FluentAffiliate\Framework\Http\Request\File $filePath
     * @param string|null $fileName The name the browser saves it as.
     * @return \FluentAffiliate\Framework\Http\Response\FileResponse
     * @throws \FluentAffiliate\Framework\Foundation\Exceptions\NotFoundHttpException
     */
    public static function download($filePath, $fileName = null)
    {
        if ($filePath instanceof File) {
            $array = $filePath->toArray();
            $filePath = $array['tmp_name'];
            $fileName = $fileName ?? ($array['name'] ?? null);
        }

        $route = static::dispatchingRoute();

        if (!is_file($filePath) || !is_readable($filePath)) {
            if ($route) {
                throw new NotFoundHttpException('File does not exist or unreadable.');
            }

            wp_die(
                'File does not exist or unreadable.',
                'Not Found',
                ['response' => 404]
            );
        }

        $file = new FileResponse($filePath, $fileName);

        if (!$route) {
            $file->send();
        }

        return $route->keepDownload($file);
    }

    /**
     * Get the route whose controller is running, if any.
     *
     * @return \FluentAffiliate\Framework\Http\Route|null
     */
    protected static function dispatchingRoute()
    {
        $app = App::getInstance();

        if (!$app || !$app->bound('route')) {
            return null;
        }

        $route = $app->make('route');

        return method_exists($route, 'isDispatching') && $route->isDispatching()
            ? $route
            : null;
    }

    /**
     * Send a redirect response.
     *
     * A route path, such as 'orders/5?tab=2', goes to that route of the
     * plugin's REST API. A full URL is kept when WordPress allows its
     * host, as wp_safe_redirect() does, and becomes the admin URL when
     * it doesn't.
     *
     * @param  string  $to
     * @param  integer $status A 3xx status.
     * @return \WP_REST_Response
     * @throws \InvalidArgumentException When the status is not a redirect.
     */
    public static function redirect($to, $status = 303)
    {
        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException(
                'HTTP redirect status code must be a redirection code, 3xx.'
            );
        }

        return new WP_REST_Response(null, $status, [
            'Location' => static::redirectUrl((string) $to),
        ]);
    }

    /**
     * Resolve where a redirect goes.
     *
     * @param  string $to
     * @return string
     */
    protected static function redirectUrl($to)
    {
        // A scheme, or // for the page's own scheme, makes it a full URL.
        if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $to)) {
            return wp_validate_redirect($to, admin_url());
        }

        $config = App::getInstance()->config;

        $parts = explode('?', $to, 2);

        $url = rest_url(sprintf(
            '%s/%s/%s',
            $config->get('app.rest_namespace'),
            $config->get('app.rest_version'),
            ltrim($parts[0], '/')
        ));

        if (isset($parts[1]) && $parts[1] !== '') {
            parse_str($parts[1], $query);

            $url = add_query_arg(urlencode_deep($query), $url);
        }

        return $url;
    }
}
