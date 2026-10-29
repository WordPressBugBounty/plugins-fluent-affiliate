<?php

namespace FluentAffiliate\Framework\Http\Response;

use DateTime;
use WP_Error;
use DateTimeInterface;
use WP_REST_Response;
use WP_HTTP_Response;
use InvalidArgumentException;

class Response
{
    use DownloadsAndRedirectsTrait;

    /**
     * Response Data
     * @var mixed
     */
    protected $data = null;
    
    /**
     * Response Status Code
     * @var integer
     */
    protected $code = 200;
    
    /**
     * Response Headers
     * @var array
     */
    protected $headers = [];
    
    /**
     * Response Cookies
     * @var array
     */
    protected $cookies = [];

    /**
     * Construct the response instance.
     * 
     * @param  array  $data
     * @param  integer $code
     * @param  array $headers
     */
    public function __construct($data = null, $code = 200, $headers = [])
    {
        $this->data = $data;
        $this->code = $code;
        $this->headers = $headers;
    }

    /**
     * Creates an instance of self.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @param  array $headers
     * @return self
     */
    public static function make($data = null, $code = 200, $headers = [])
    {
        return new static($data, $code, $headers);
    }

    /**
     * Send json response.
     * 
     * @param  array  $data
     * @param  integer $code
     * @return string|false JSON encoded string, or false if fails to encode.
     */
    public function json($data = null, $code = 200)
    {    
        return wp_send_json($data, $code);
    }

    /**
     * Send rest response. Without arguments, it sends the response's own
     * data and status, as given to make() or the constructor.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @param  array $headers
     * @return \WP_REST_Response
     */
    public function send($data = null, $code = 200, $headers = [])
    {
        if (func_num_args() === 0) {
            return $this->toArray();
        }

        $response = new WP_REST_Response($data, $code, $headers);

        return $this->maybeMergeHeaders(
            $this->maybeMergeCookies($response)
        );
    }

    /**
     * Send a success rest response.
     * 
     * @param  mixed  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function sendSuccess($data = null, $code = 200, $headers = [])
    {   
         return $this->send($data, $code, $headers);
    }

    /**
     * Send an error json response
     * @param  array  $data
     * @param  integer $code
     * @return \WP_REST_Response
     */
    public function sendError($data = null, $code = 422, $headers = [])
    {
        $code = $code < 400 ? 422 : $code;

        return $this->send($data, $code, $headers);
    }

    /**
     * Convert the WP_Error to WP_REST_Response
     * 
     * @param  \WP_Error $wpError
     * @return \WP_REST_Response
     */
    public function wpErrorToResponse(WP_Error $wpError)
    {
        return $this->maybeMergeHeaders(
            $this->maybeMergeCookies(rest_convert_error_to_response($wpError))
        );
    }

    /**
     * Set response headers
     * @param  string|array $key
     * @param  string|null $value
     * @return self
     */
    public function withHeader($key, $value = null)
    {
        if (is_array($key) && !$value) {
            $this->headers = array_merge($this->headers, (array) $key);
        } else {
            $this->headers[$key] = $value;
        }
        return $this;
    }

    /**
     * Set response cookie
     * 
     * @param  string       $name
     * @param  mixed        $value
     * @param  int          $minutes
     * @param  string       $path
     * @param  string|null  $domain
     * @param  bool         $secure
     * @param  bool         $httpOnly
     * @param string        $samesite
     * @return              self
     */
    public function withCookie(
        $name,
        $value,
        $minutes,
        $path = '/',
        $domain = null,
        $secure = false,
        $httpOnly = true,
        $samesite = 'Lax'
    )
    {
        $cookie = $this->buildCookie(
            $name,
            $value,
            $minutes,
            $path,
            $domain,
            $secure,
            $httpOnly,
            $samesite
        );

        $this->cookies[] = $cookie;

        return $this;
    }

    /**
     * Attach a response cookie whose value is base64(json($value)).
     *
     * Pairs with Request::cookie(), which auto-detects this exact format
     * and decodes back to the original value on read. Use this when you
     * need to attach arrays/objects to a Response — strings, numbers, and
     * scalars work fine through plain withCookie() and don't need encoding.
     *
     * @param  string       $name
     * @param  mixed        $value     Anything json_encode can serialize
     * @param  int          $minutes
     * @param  string       $path
     * @param  string|null  $domain
     * @param  bool         $secure
     * @param  bool         $httpOnly
     * @param  string       $samesite
     * @return              self
     */
    public function withEncodedCookie(
        $name,
        $value,
        $minutes,
        $path = '/',
        $domain = null,
        $secure = false,
        $httpOnly = true,
        $samesite = 'Lax'
    )
    {
        return $this->withCookie(
            $name,
            base64_encode(json_encode($value)),
            $minutes,
            $path,
            $domain,
            $secure,
            $httpOnly,
            $samesite
        );
    }

    /**
     * Set response cookie
     *
     * @param  string       $name
     * @param  mixed        $value
     * @param  int          $minutes
     * @param  string       $path
     * @param  string|null  $domain
     * @param  bool         $secure
     * @param  bool         $httpOnly
     * @param string        $samesite
     * @return              string
     */
    protected function buildCookie(
        $name,
        $value,
        $minutes,
        $path,
        $domain,
        $secure,
        $httpOnly,
        $samesite = 'Lax'
    ) {
        $expiration = static::expiresAt($minutes);

        $cookieHeader = "{$name}=" . rawurlencode($value);

        if ($expiration !== 0) {
            $cookieHeader .= "; Expires=" . gmdate(
                'D, d-M-Y H:i:s T', $expiration
            );
        }

        $path = $path ?: '/';
        $cookieHeader .= "; Path=" . $path;

        if ($domain) {
            $cookieHeader .= "; Domain=" . $domain;
        }

        if ($secure) {
            $cookieHeader .= "; Secure";
        }

        if ($httpOnly) {
            $cookieHeader .= "; HttpOnly";
        }

        if ($samesite) {
            $cookieHeader .= "; SameSite=" . ucfirst(strtolower($samesite));
        }

        return $cookieHeader;
    }

    /**
     * Merge additional headers if exist.
     * 
     * @param \WP_REST_Response $response
     */
    protected function maybeMergeHeaders(WP_REST_Response $response)
    {
        if ($this->headers) {
            foreach ($this->headers as $key => $value) {
                $response->header($key, $value);
            }
        }

        $this->headers = [];

        return $response;
    }

    /**
     * Merge cookies if exist.
     * 
     * @param \WP_REST_Response $response
     */
    protected function maybeMergeCookies(WP_REST_Response $response)
    {
        // WordPress sends one value per header name, so two or more
        // cookies are kept as a list and sent by sendEachCookie().
        if (count($this->cookies) === 1) {
            $response->header('Set-Cookie', reset($this->cookies));
        } elseif ($this->cookies) {
            $response->header('Set-Cookie', $this->cookies);
        }

        $this->cookies = [];

        return $response;
    }

    /**
     * Add the headers and cookies queued here to a response built by
     * another object, such as one a controller made or returned. The
     * response's own headers win a clash; its cookies and the queued
     * ones are all kept. The queue is emptied.
     *
     * @param  \WP_HTTP_Response $response
     * @return \WP_HTTP_Response
     */
    public function mergeQueuedInto(WP_HTTP_Response $response)
    {
        $own = array_change_key_case($response->get_headers());

        foreach ($this->headers as $key => $value) {
            if (!array_key_exists(strtolower($key), $own)) {
                $response->header($key, $value);
            }
        }

        $this->headers = [];

        if ($this->cookies) {
            $this->addQueuedCookies($response);
        }

        return $response;
    }

    /**
     * Add the queued cookies after the response's own, one value when
     * there is a single cookie and a list for sendEachCookie() otherwise.
     *
     * @param  \WP_HTTP_Response $response
     * @return void
     */
    protected function addQueuedCookies(WP_HTTP_Response $response)
    {
        $headers = $response->get_headers();

        $name = 'Set-Cookie';

        foreach (array_keys($headers) as $key) {
            if (strtolower($key) === 'set-cookie') {
                $name = $key;
            }
        }

        $cookies = array_merge(
            array_values((array) ($headers[$name] ?? [])),
            array_values($this->cookies)
        );

        $response->header($name, count($cookies) === 1 ? reset($cookies) : $cookies);

        $this->cookies = [];
    }

    /**
     * Send a list of cookies one Set-Cookie header each.
     *
     * Runs on rest_post_dispatch, just before WordPress sends the headers,
     * and takes the list off the response so WordPress never sees it.
     *
     * @param  mixed $response
     * @return mixed
     */
    public function sendEachCookie($response)
    {
        if (!$response instanceof WP_HTTP_Response) {
            return $response;
        }

        $headers = $response->get_headers();

        if (is_array($cookies = $headers['Set-Cookie'] ?? null)) {
            unset($headers['Set-Cookie']);

            $response->set_headers($headers);

            foreach ($cookies as $cookie) {
                $this->sendCookieHeader($cookie);
            }
        }

        return $response;
    }

    /**
     * Add one Set-Cookie header without replacing the others.
     *
     * @param  string $cookie
     * @return void
     */
    protected function sendCookieHeader($cookie)
    {
        if (!headers_sent()) {
            header('Set-Cookie: ' . $cookie, false);
        }
    }

    /**
     * Prepares expiration time in minutes.
     * 
     * @param  int|DateTimeInterface $minutes
     * @return int
     */
    protected static function expiresAt($minutes = 0)
    {
        if ($minutes === 0) {
            return 0;
        }

        if (is_int($minutes)) {
            $minutes = new DateTime("+{$minutes} minutes");
        }

        if ($minutes instanceof DateTimeInterface) {
            return $minutes->getTimestamp();
        }

        throw new InvalidArgumentException('Invalid expiration time provided.');
    }

    /**
     * Send the response
     * 
     * @return \WP_REST_Response
     */
    public function toArray()
    {
        if ($this->data instanceof WP_HTTP_Response) {
            // The response's own cookies, such as the controller's, stay;
            // the queued ones are added after them.
            if ($this->cookies) {
                $this->addQueuedCookies($this->data);
            }

            return $this->maybeMergeHeaders($this->data);
        }

        $response = new WP_REST_Response(
            $this->data, $this->code
        );

        return $this->maybeMergeHeaders(
            $this->maybeMergeCookies($response)
        );
    }

    /**
     * Get data from response.
     * 
     * @return mixed
     */
    public function getData()
    {
        if ($this->data instanceof WP_HTTP_Response) {
            return $this->data->get_data();
        }

        return $this->data;
    }

    /**
     * Set the response's data. When it wraps a response, such as the
     * controller's in an after-middleware, only that response's data
     * changes; its status and headers stay. A whole response given here
     * replaces the one it wraps.
     * 
     * @param  mixed $data
     * @return $this
     */
    public function setData($data)
    {
        if ($data instanceof WP_HTTP_Response) {
            $this->data = $data;
        } elseif ($this->data instanceof WP_HTTP_Response) {
            $this->data->set_data($data);
        } else {
            $this->data = $data;
        }

        return $this;
    }

    /**
     * Get the response's status code.
     *
     * @return int
     */
    public function getStatus()
    {
        if ($this->data instanceof WP_HTTP_Response) {
            return $this->data->get_status();
        }

        return $this->code;
    }

    /**
     * Set the response's status code, on the response it wraps if any.
     *
     * @param  int $code
     * @return $this
     */
    public function setStatus($code)
    {
        if ($this->data instanceof WP_HTTP_Response) {
            $this->data->set_status($code);
        } else {
            $this->code = $code;
        }

        return $this;
    }
}
