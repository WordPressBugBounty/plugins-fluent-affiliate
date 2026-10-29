<?php

namespace FluentAffiliate\Framework\Http;

/**
 * Class Response
 *
 * Handles HTTP responses for HTTP Client class,
 * providing methods to access response data.
 */
class Response implements \JsonSerializable
{
    /**
     * The HTTP response data.
     *
     * @var array
     */
    protected $response;

    /**
     * Create a new Response instance.
     *
     * @param array $response The response data.
     */
    public function __construct($response)
    {
        $this->response = $response;
    }

    /**
     * Convert the response to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->response;
    }

    /**
     * Convert the response object to an array.
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Get the HTTP response object.
     *
     * @return mixed
     */
    public function getHttpResponseObject()
    {
        return $this->response['http_response'];
    }

    /**
     * Determine if the response is successful (2xx status code).
     *
     * @return bool
     */
    public function isOkay()
    {
        return str_starts_with($this->getCode(), '2');
    }

    /**
     * Determine if the response is a redirect (3xx status code).
     *
     * @return bool
     */
    public function isRedirect()
    {
        $code = $this->getCode();

        $isRedirect = in_array(
        	$code, [300, 301, 302, 303, 307], true
        ) || ($code > 307 && $code < 400);

        return $isRedirect || $this->getHttpResponseObject()
        	->get_response_object()
        	->redirects > 0;
    }

    /**
     * Throw WordPress's exception for a 4xx or 5xx status, or when the
     * request got no answer: such as \WpOrg\Requests\Exception\Http\Status404, or
     * StatusUnknown for a status WordPress has no class for. The exception
     * carries the response: $e->getData() is the Requests response, with its
     * status_code, headers and body.
     *
     * @return $this The response, when its status is below 400.
     *
     * @throws \WpOrg\Requests\Exception\Http
     */
    public function throw()
    {
        $failed = is_wp_error($this->response);
        $code = $failed ? 0 : (int) $this->getCode();

        if ($code > 0 && $code < 400) {
            return $this;
        }

        // WordPress before 6.2 names the class Requests_Exception_HTTP.
        $class = class_exists('WpOrg\Requests\Exception\Http')
            ? \WpOrg\Requests\Exception\Http::get_class($code)
            : \Requests_Exception_HTTP::get_class($code);

        throw new $class(
            $failed ? $this->response->get_error_message() : null,
            $failed ? null : $this->requestsResponse()
        );
    }

    /**
     * Throw as throw() does when the condition holds.
     *
     * @param  bool|callable  $condition  A callable gets the response.
     * @return $this
     *
     * @throws \WpOrg\Requests\Exception\Http
     */
    public function throwIf($condition)
    {
        $holds = is_callable($condition) ? $condition($this) : $condition;

        return $holds ? $this->throw() : $this;
    }

    /**
     * The Requests response WordPress's HTTP API was given, if any.
     *
     * @return \WpOrg\Requests\Response|\Requests_Response|null
     */
    protected function requestsResponse()
    {
        $http = is_array($this->response) ? ($this->response['http_response'] ?? null) : null;

        return is_object($http) && method_exists($http, 'get_response_object')
            ? $http->get_response_object()
            : null;
    }

    /**
     * Get the HTTP response status code.
     *
     * @return int
     */
    public function getCode()
    {
        return wp_remote_retrieve_response_code($this->response);
    }

    /**
     * Get the HTTP response status code (alias for getCode).
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->getCode();
    }

    /**
     * Get the HTTP response status message.
     *
     * @return string
     */
    public function getMessage()
    {
        return wp_remote_retrieve_response_message($this->response);
    }

    /**
     * Get the HTTP response body.
     *
     * @return string
     */
    public function getBody()
    {
        return wp_remote_retrieve_body($this->response);
    }

    /**
     * Determine if the response body is JSON.
     *
     * @return bool
     */
    public function isJson()
    {
        $header = $this->getHeader('content-type');
        return str_contains($header, 'application/json');
    }

    /**
     * Get the decoded JSON body.
     *
     * @return array|null
     */
    public function getJson()
    {
        if ($this->isJson()) {
            return json_decode($this->getBody(), true);
        }

        return null;
    }

    /**
     * Get the headers from the response.
     *
     * @return array
     */
    public function getHeaders()
    {
        return wp_remote_retrieve_headers($this->response);
    }

    /**
     * Get a specific header from the response.
     *
     * @param string $key The header key.
     * @return string|null
     */
    public function getHeader($key)
    {
        return wp_remote_retrieve_header($this->response, $key);
    }

    /**
     * Get the cookies from the response.
     *
     * @return array
     */
    public function getCookies()
    {
        return wp_remote_retrieve_cookies($this->response);
    }

    /**
     * Get a specific cookie from the response.
     *
     * @param string $name The cookie name.
     * @param bool $isObject Whether to return the \WP_Http_Cookie object.
     * @return mixed
     */
    public function getCookie($name, $isObject = false)
    {
        if ($isObject) {
            // Return the \WP_Http_Cookie object
            return wp_remote_retrieve_cookie($this->response, $name);
        }

        return wp_remote_retrieve_cookie_value($this->response, $name);
    }
}
