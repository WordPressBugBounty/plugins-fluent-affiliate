<?php

namespace FluentAffiliate\Framework\Http;

use Exception;
use BadMethodCallException;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Support\Str;
use FluentAffiliate\Framework\Foundation\App;
use FluentAffiliate\Framework\Http\Request\File;

/**
 * @method mixed get(string $url, array $params = [])     Send a GET request.
 * @method mixed post(string $url, array $params = [])    Send a POST request.
 * @method mixed put(string $url, array $params = [])     Send a PUT request.
 * @method mixed patch(string $url, array $params = [])   Send a PATCH request.
 * @method mixed delete(string $url, array $params = [])  Send a DELETE request.
 * @method mixed head(string $url, array $params = [])    Send a HEAD request.
 * @method mixed options(string $url, array $params = []) Send an OPTIONS request.
 * @method File download(string|File $url)                Download a remote file.
 * @method mixed upload(string $url, string $path, array $fields = [], string $name = 'file') Upload a file to a remote server.
 */


/**
 * Some examples:
 * 
 * // Using an instance:
 * $client = Client::make('https://example.com');
 * 
 * $response1 = $client->get('/users');
 * $response2 = $client->post('/users', ['body' => ['name' => 'Heera']]);
 * $response3 = $client->put('/users/1', ['body' => ['name' => 'Updated']]);
 * $response4 = $client->patch('/users/1', [
 *     'body' => ['email' => 'updated@example.com']]
 * );
 * $response5 = $client->delete('/users/1');
 * $response6 = $client->head('/users');
 * $response7 = $client->options('/users');
 * 
 * // Using statically (default base URL is empty):
 * $response8 = Client::get('https://example.com/users');
 * $response9 = Client::post('https://example.com/users', [
 *     'body' => ['name' => 'Static']]
 * );
 */


class Client
{
	/**
	 * Base URl for the request.
	 * 
	 * @var string
	 */
	protected $baseUrl = '';
	
	/**
	 * Cookies to send with the request.
	 * 
	 * @var array
	 */
	protected $cookies = [];
	
	/**
	 * Headers to send with the request.
	 * 
	 * @var array
	 */
	protected $headers = [];
	
	/**
	 * Options to set for the request.
	 * 
	 * @var array
	 */
	protected $options = [];

	/**
	 * Request body|Data|params to set in the request.
	 * 
	 * @var array
	 */
	protected $body = [];

	/**
	 * Request query params to pass with the url.
	 *
	 * @var array
	 */
	protected $query = [];

	/**
	 * Whether to reject unsafe URLs via wp_safe_remote_request.
	 * When true, dispatch() uses wp_safe_remote_request() instead of
	 * wp_remote_request(), which runs the URL through wp_http_validate_url()
	 * and refuses loopback addresses, private IP ranges, and non-standard
	 * ports. Off by default for backwards compatibility with internal calls.
	 *
	 * @var bool
	 */
	protected $useSafeRemote = false;

	/**
	 * Stores args temporarily for then().
	 *
	 * @var null|array
	 */
	private $args = null;

	/**
	 * Called with each chunk of a streamed response.
	 *
	 * @var callable|null
	 */
	protected $streamCallback = null;

	/**
	 * Create a new HTTP client.
	 *
	 * @param string $baseUrl
	 * @param array $args
	 */
	public function __construct($baseUrl = '', $args = [])
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->cookies = $args['cookies'] ?? [];
		$this->headers = $args['headers'] ?? [];
		$this->options = $args['options'] ?? [];
	}

	/**
	 * Create a new HTTP client.
	 * 
	 * @param string $baseUrl
	 * @param array $args
	 */
	public static function make($baseUrl = '', $args = [])
	{
		$args['cookies'] = $args['cookies'] ?? [];
		$args['headers'] = $args['headers'] ?? [];
		$args['options'] = $args['options'] ?? [];
		return new static($baseUrl, $args);
	}

	/**
	 * Sets one or more options.
	 * 
	 * @return self
	 */
	public function withOption($key, $value = null)
	{
		$options = is_array($key) ? $key : [$key => $value];

		foreach ($options as $key => $value) {
			$this->options[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets the blocking option to false (non-blocking).
	 * 
	 * @return self
	 */
	public function async()
	{
		return $this->withOption('blocking', false);
	}

	/**
	 * Sets the sslverify option.
	 *
	 * @return self
	 */
	public function secure($verify = true)
	{
		return $this->withOption('sslverify', $verify);
	}

	/**
	 * Route dispatch through wp_safe_remote_request() instead of
	 * wp_remote_request() — mirrors WordPress's wp_safe_remote_* family.
	 *
	 * Runs the URL through wp_http_validate_url() first, which refuses
	 * loopback addresses, private IP ranges, and non-standard ports. Use
	 * when the target URL is user/admin-configurable and you need SSRF
	 * protection. Standard external HTTPS endpoints on ports 80/443/8080
	 * are unaffected.
	 *
	 * @param  bool $enabled
	 * @return self
	 */
	public function safe($enabled = true)
	{
		$this->useSafeRemote = $enabled;
		return $this;
	}

	/**
	 * Sets one or more headers.
	 * 
	 * @return self
	 */
	public function withHeader($key, $value = null)
	{
		$headers = is_array($key) ? $key : [$key => $value];

		foreach ($headers as $key => $value) {
			$this->headers[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more headers.
	 * 
	 * @param  array $headers
	 * @return self
	 */
	public function withHeaders(array $headers)
	{
		return $this->withHeader($headers);
	}

	/**
	 * Sets the Authorization header.
	 * 
	 * @param  string $token
	 * @param  string $type
	 * @return self
	 */
	public function withToken($token, $type = 'Bearer')
	{
	    return $this->withHeader([
	        'Authorization' => $type . ' ' . $token,
	    ]);
	}

	/**
	 * Sets one or more cookies.
	 * 
	 * @return self
	 */
	public function withCookie($key, $value = null)
	{
		$cookies = is_array($key) ? $key : [$key => $value];

		foreach ($cookies as $key => $value) {
			$this->cookies[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withData($key, $value = null)
	{
		$data = is_array($key) ? $key : [$key => $value];

		foreach ($data as $key => $value) {
			$this->body[$key] = $value;
		}

		return $this;
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withBody($key, $value = null)
	{
		return $this->withData($key, $value);
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withParam($key, $value = null)
	{
		return $this->withData($key, $value);
	}

	/**
	 * Sets one or more request body param.
	 * 
	 * @return self
	 */
	public function withQuery($key, $value = null)
	{
		$data = is_array($key) ? $key : [$key => $value];
		
		foreach ($data as $key => $value) {
			$this->query[$key] = $value;
		}

		return $this;
	}

	/**
     * Allows users to enable streaming on their requests.
     *
     * @return self
     */
    public function withStreaming($callback = null)
    {
    	$this->streamCallback = $callback;

        return $this->withOption('stream', true);
    }

	/**
	 * Build the request arguments.
	 * 
	 * @param  array  $params
	 * @param  string $method 
	 * @return array
	 */
	protected function buildRequestArgs($params, $method)
	{
		$defaultParams = [
	        'body' => [],
	        'cookies' => [],
	        'headers' => [],
	    ];

	    $params = wp_parse_args($params[0] ?? [], $defaultParams);

	    $options = array_merge($this->options, $params['options'] ?? []);

	    $isJson = Str::isJson($params['body']);

	    if ($isJson) {
	    	$params['body'] = json_decode($params['body'], true);
	    }

	    $params = [
	        'method' => strtoupper($method),
	        'body' => array_merge($this->body, $params['body']),
	        'cookies' => array_merge($this->cookies, $params['cookies']),
	        'headers' => array_merge($this->headers, $params['headers']),
	    ];

	    // For this request only: the client's own headers are its state.
	    if ($isJson) {
	    	$params['headers']['Content-Type'] = 'application/json';
	    }

	    foreach($options as $key => $value) {
	        $params[$key] = $value;
	    }

	    return $params;
	}

	/**
	 * Send the request.
	 * 
	 * @param  string $url
	 * @param  array  $args
	 * @return \FluentAffiliate\Framework\Http\Response
	 */
	protected function request($url, $args = [])
	{
		return $this->dispatch(
			$this->resolveUrl($url),
			$this->filterArgs($args)
		);
	}

	/**
	 * Build the URL.
	 * 
	 * @param  string $url
	 * @return string
	 */
	protected function resolveUrl($url)
	{
		$q = $this->query;

		$parsedUrl = parse_url($url);
		
		$delimiter = isset($parsedUrl['query']) ? '&' : '?';
		
		return $url . ($q ? $delimiter . http_build_query($q) : '');

	}

	/**
	 * Filter the args before sending the request.
	 * 
	 * @param  array $args
	 * @return array
	 */
	protected function filterArgs($args)
	{
		if ($this->shouldBeJson($args)) {
	        $args['body'] = json_encode($args['body']);
	    }

	    return $args;
	}

	/**
	 * Encode the body if Content-Type is JSON.
	 * 
	 * @param  array $params
	 * @return bool
	 */
	protected function shouldBeJson($params)
	{
		return isset(
			$params['headers']['Content-Type']
		) && $params['headers']['Content-Type'] === 'application/json';
	}

	/**
	 * Dispatch the request.
	 * 
	 * @param  string $url
	 * @param  array $args
	 * @return array (response)
	 */
	protected function dispatch($url, $args)
	{
		$response = $this->dispatchRaw($url, $args);

	    if ($this->isStreamEnabled($args)) {
	    	// WordPress picked the file when the request named none.
	    	if (empty($args['filename']) && !empty($response['filename'])) {
	    		$this->deleteAtShutdown($response['filename']);
	    	}

	    	$this->sendChunksTo($this->streamCallback, $response['filename'] ?? null);

            return $this->makeStreamResponse($response);
        }

	    return $this->makeResponse($response);
	}

	/**
	 * Send the request and keep the cookies it brought back, so the next
	 * request carries them, a login's session among them.
	 *
	 * @param  string $url
	 * @param  array $args
	 * @return array WordPress's response.
	 * @throws \Exception When the request got no answer.
	 */
	protected function dispatchRaw($url, $args)
	{
		$fn = $this->useSafeRemote ? 'wp_safe_remote_request' : 'wp_remote_request';
		$response = $fn($url, $args);

	    if (is_wp_error($response)) {
	        throw new Exception($response->get_error_message(), 500);
	    }

	    $this->mergeCookies($response);

	    return $response;
	}

	/**
	 * Merge the coolkies (useful for stateful request).
	 * 
	 * @return void
	 */
	protected function mergeCookies($response)
	{
		$this->cookies = array_merge(
	        $this->cookies,
	        wp_remote_retrieve_cookies($response)
	    );
	}

	/**
	 * Hand a streamed response's file to the callback in chunks. WordPress
	 * writes the whole response to the file first; it has no way to pass
	 * chunks while they arrive.
	 *
	 * @param  callable|null  $callback
	 * @param  string|null  $file
	 * @return void
	 */
	protected function sendChunksTo($callback, $file)
	{
		if (!$callback || !$file || !is_readable($file)) {
			return;
		}

		$source = fopen($file, 'rb');

		try {
			while (!feof($source)) {
				$chunk = fread($source, 8192);

				if ($chunk !== '' && $chunk !== false) {
					$callback($chunk);
				}
			}
		} finally {
			fclose($source);
		}
	}

	/**
	 * Delete a temporary file when the request ends.
	 *
	 * @param  string  $file
	 * @return void
	 */
	protected function deleteAtShutdown($file)
	{
		add_action('shutdown', function () use ($file) {
			@unlink($file);
		});
	}

	/**
	 * Check if the stream is enabled.
	 * @return boolean
	 */
	protected function isStreamEnabled($args)
	{
		return isset($args['stream']) && $args['stream'];
	}

	/**
	 * Build a response object from an anonymous class.
	 * 
	 * @param  array $response
	 * @return \FluentAffiliate\Framework\Http\Response
	 */
	protected function makeResponse($response)
	{
		return new Response($response);
	}

	/**
     * Handle the streaming response and process chunks.
     *
     * @param  array $response
     * @return \FluentAffiliate\Framework\Http\Response|null
     */
    protected function makeStreamResponse($response)
	{
	    $response['body'] = $response['filename'];

	    return new class($response) extends Response implements \ArrayAccess {
	    	public function flush() {
				$source = fopen($this->response['body'], 'rb');
			    
			    $fp = fopen('php://output', 'wb');

			    while (!feof($source)) {
			        $data = fread($source, 8192);
			        fwrite($fp, $data);
			        ob_flush();
			        flush();
			    }

			    fclose($source);
			    fclose($fp);
			}

			#[ReturnTypeWillChange]
		    public function offsetGet($offset) {
		        return $this->response[$offset] ?? null;
		    }
		    #[ReturnTypeWillChange]
		    public function offsetSet($offset, $value) {
		    	$this->response[$offset] = $value;
		    }
		    #[ReturnTypeWillChange]
			public function offsetExists($offset) {
				return isset($this->response[$offset]);
			}
		    #[ReturnTypeWillChange]
		    public function offsetUnset($offset) {
		    	unset($this->response[$offset]);
		    }
		};
	}

	/**
	 * Download a remote file.
	 * 
	 * @param  string $url
	 * @return \FluentAffiliate\Framework\Http\Request\File
	 * @throws \Exception
	 */
	public function downloadFile($url)
	{
		$parsed = parse_url($url);

		if (!isset($parsed['scheme'])) {
			$url = trim($this->baseUrl, '/') . '/' . trim($url, '/');
		}

		$pathName = basename((string) parse_url($url, PHP_URL_PATH));

		// wp_tempnam() is only loaded in wp-admin.
		if (!function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if (!$file = wp_tempnam($pathName)) {
			throw new Exception('Could not create temporary file.', 500);
		}

		$args = $this->buildRequestArgs([], 'get');
		$args['stream'] = true;
		$args['filename'] = $file;
		$args['timeout'] = $this->options['timeout'] ?? 300;

		// Like WordPress's download_url(): never an internal address.
		$safe = $this->useSafeRemote;
		$this->useSafeRemote = true;

		try {
			$response = $this->dispatchRaw($this->resolveUrl($url), $args);
		} catch (Exception $e) {
			@unlink($file);
			throw $e;
		} finally {
			$this->useSafeRemote = $safe;
		}

		if (200 !== (int) wp_remote_retrieve_response_code($response)) {
			@unlink($file);

			throw new Exception(
				trim(wp_remote_retrieve_response_message($response)), 500
			);
		}

		$this->deleteAtShutdown($file);

		return new File(
			$file,
			$this->downloadName($response, $pathName),
			mime_content_type($file) ?: 'application/octet-stream',
			filesize($file),
			UPLOAD_ERR_OK
		);
	}

	/**
	 * The name of a downloaded file: the one its Content-Disposition gives,
	 * else the last part of the URL's path, without the query.
	 *
	 * @param  array  $response
	 * @param  string  $pathName
	 * @return string
	 */
	protected function downloadName($response, $pathName)
	{
		$disposition = (string) wp_remote_retrieve_header($response, 'content-disposition');

		if (preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $m)) {
			$name = rawurldecode(trim($m[1]));
		} elseif (preg_match('/filename="([^"]*)"/i', $disposition, $m)
			|| preg_match('/filename=([^;]+)/i', $disposition, $m)
		) {
			$name = trim($m[1]);
		} else {
			$name = rawurldecode($pathName);
		}

		// Never a path: only the name's last part.
		$name = basename(str_replace('\\', '/', $name));

		return $name !== '' ? $name : 'download';
	}

	/**
	 * Upload a file to a remote server.
	 *
	 * Sent like every other request, so safe(), the cookies, the query and
	 * the options apply, and a failed request throws.
	 * 
	 * @param  string $url
	 * @param  string|\FluentAffiliate\Framework\Http\Request\File $path
	 * @param  array  $fields
	 * @param  string $name
	 * @return \FluentAffiliate\Framework\Http\Response
	 * @throws \Exception
	 */
	public function uploadFile($url, $path, $fields = [], $name = 'file')
	{
		$fileName = $path instanceof File
			? $path->getClientOriginalName()
			: basename($path);

	    $path = $path instanceof File ? $path->getPathname() : $path;

	    if (!file_exists($path)) {
	        throw new Exception('File does not exist.', 500);
	    }

	    if (!isset(parse_url($url)['scheme'])) {
	        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
	    }

	    $boundary = wp_generate_password(24, false);

	    $args = $this->buildRequestArgs([], 'post');

	    $args['headers'] = array_merge($args['headers'], [
	        'Accept'       => '*/*',
	        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
	    ]);

	    $args['body'] = $this->multipartBody(
	    	$boundary, $fields, $name, $path, $fileName
	    );

	    $args['timeout'] = $this->options['timeout'] ?? 60;

	    return $this->request($url, $args);
	}

	/**
	 * Build a multipart/form-data body the way a browser does.
	 *
	 * @param  string $boundary
	 * @param  array  $fields
	 * @param  string $name
	 * @param  string $path
	 * @param  string $fileName
	 * @return string
	 */
	protected function multipartBody($boundary, $fields, $name, $path, $fileName)
	{
	    $body = '';

	    foreach ($this->flattenFields($fields) as $key => $value) {
	        $body .= "--" . $boundary . "\r\n";
	        $body .= 'Content-Disposition: form-data; name="'
	        	. $this->escapeName($key) . '"' . "\r\n\r\n";
	        $body .= $value . "\r\n";
	    }

	    $mime = mime_content_type($path) ?: 'application/octet-stream';

	    $body .= "--" . $boundary . "\r\n";
	    $body .= 'Content-Disposition: form-data; name="' . $this->escapeName($name)
	    	. '"; filename="' . $this->escapeName($fileName) . '"' . "\r\n";
	    $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
	    $body .= file_get_contents($path) . "\r\n";
	    $body .= "--" . $boundary . "--\r\n";

	    return $body;
	}

	/**
	 * Turn nested fields into form names, such as tags[0] and meta[owner].
	 *
	 * @param  array  $fields
	 * @param  string|null $prefix
	 * @return array
	 */
	protected function flattenFields(array $fields, $prefix = null)
	{
		$flat = [];

		foreach ($fields as $key => $value) {
			$key = $prefix === null ? $key : $prefix . '[' . $key . ']';

			if (is_array($value)) {
				$flat += $this->flattenFields($value, $key);
			} else {
				$flat[$key] = $value;
			}
		}

		return $flat;
	}

	/**
	 * Escape a name or file name as browsers do, so a quote or a line
	 * break can't end the header it's in.
	 *
	 * @param  string $name
	 * @return string
	 */
	protected function escapeName($name)
	{
		return str_replace(
			["\r", "\n", '"'], ['%0D', '%0A', '%22'], (string) $name
		);
	}

	protected function checkIfValidHttpMethod($method)
	{
		$validHttpMethods = [
			'get', 'post', 'put', 'delete', 'patch', 'options', 'head'
		];

		if (!in_array(strtolower($method), $validHttpMethods)) {
			throw new BadMethodCallException("Method $method does not exist.");
		}
	}

	/**
	 * Handles the dynamic calls.
	 * 
	 * @param  string $method
	 * @param  array  $args
	 * @return mixed
	 */
	public function __call($method, $args)
	{
		if ($method === 'download') {
			return $this->downloadFile(...$args);
		}

		if ($method === 'upload') {
			return $this->uploadFile(...$args);
		}
		
		// Handles dynamic method calls like:
		// get, post and so on.
		$url = array_shift($args);

		$parsed = parse_url($url);

		if (!isset($parsed['scheme'])) {
			$url = trim($this->baseUrl, '/') . '/' . trim($url, '/');
		}
		
		$this->checkIfValidHttpMethod($method);

		return $this->request(
			$url, $this->buildRequestArgs($args, $method)
		);
	}

	/**
	 * Handle the static dynamic calls.
	 * 
	 * @param  string $method
	 * @param  array  $args
	 * @return self
	 */
	public static function __callStatic($method, $args)
	{
		return static::make()->$method(...$args);
	}
}
