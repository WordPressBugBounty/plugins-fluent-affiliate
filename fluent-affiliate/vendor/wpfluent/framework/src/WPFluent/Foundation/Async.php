<?php

namespace FluentAffiliate\Framework\Foundation;

use Exception;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Support\Helper;
use InvalidArgumentException;

/**
 * @property \FluentAffiliate\Framework\Foundation\Config $config
 */
class Async
{
	/**
	 * The dispatched handlers to stop recursion.
	 * 
	 * @var array
	 */
	private $dispatched = [];

	/**
	 * The application instance
	 * 
	 * @var \FluentAffiliate\Framework\Foundation\Application
	 */
	private static $app = null;

	/**
	 * Self instance
	 * 
	 * @var self
	 */
	private static $instance = null;

	/**
	 * The array of async action handlers
	 * 
	 * @var array
	 */
	private static $handlers = [];

	/**
	 * The array of async action handlers in queue
	 * 
	 * @var array
	 */
	private static $queue = [
		'default' => []
	];

	/**
	 * How long a signed job is accepted, in seconds. It only has to cover
	 * the trip from the dispatching request to the async one.
	 * 
	 * @var int
	 */
	const SIGNATURE_LIFETIME = 600;

	/**
	 * Creates the instance
	 * 
	 * @return self
	 */
	public static function init($app = null)
	{
		$app = $app ?: App::make();

		if (is_null(self::$instance)) {
			self::$app = $app;
			self::$instance = new static;
		}

		$action = self::$instance->makeAsyncHookAction();

		self::$app->addAction(
			"admin_post_{$action}", [self::$instance, 'handle']
		);

		self::$app->addAction(
			"admin_post_nopriv_{$action}", [self::$instance, 'handle']
		);

		return self::$instance;
	}

	/**
	 * Makes the async hook action name
	 * 
	 * @return string
	 */
	public function makeAsyncHookAction()
	{
		$slug = self::$app->config->get('app.slug');

		return  "wpfluent_async_hook_{$slug}";
	}

	/**
	 * Handles the incoming async request
	 * 
	 * @return void
	 */
	public function handle()
	{
		$post = self::$app->request->post();

		$this->verifyRequest(self::$app, $post);

		// Every job is checked on arrival, before any of them runs, so a
		// long job never pushes the ones after it past their expiry.
		$jobs = [];

		foreach ((array) Arr::get($post, 'handlers', []) as $handler) {
			try {
				$jobs[] = $this->resolveHandler($handler);
			} catch (Exception $e) {
				error_log($e->getMessage());
			}
		}

		foreach ($jobs as [$class, $params]) {
			try {
				$this->execute(self::$app, $class, $params);
			} catch (Exception $e) {
				error_log($e->getMessage());
			}
		}
	}

	/**
	 * Verify the request by checking the nonce.
	 * 
	 * @param  \FluentAffiliate\Framework\Foundation\Application $app
	 * @param  array $data
	 * @return void
	 */
	protected function verifyRequest($app, $data)
	{
		if (!isset($data['wpfluent_async_nonce'])) {
			exit;
		}

		!wp_verify_nonce(
			$data['wpfluent_async_nonce'],
			$app->config->get('app.slug')
		) && exit;
	}

	/**
	 * Resolve a job this server signed into its handler and params.
	 *
	 * The signature is checked before the handler is looked at, so a job
	 * that did not come from this site never loads or runs any class.
	 * 
	 * @param  mixed $action
	 * @return array [Class@method, params]
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function resolveHandler($action)
	{
		$fields = ['handler', 'params', 'time', 'signature'];

		foreach ($fields as $field) {
			if (!is_string($action[$field] ?? null)) {
				throw new InvalidArgumentException('Invalid async job.');
			}
		}

		[$handler, $params, $time, $signature] = array_map(
			fn ($field) => $action[$field], $fields
		);

		if (!hash_equals($this->signature($handler, $params, $time), $signature)) {
			throw new InvalidArgumentException('Invalid async job signature.');
		}

		if (time() - (int) $time > static::SIGNATURE_LIFETIME) {
			throw new InvalidArgumentException('Expired async job.');
		}

		$handler = base64_decode($handler);

		[$class] = explode('@', $handler);

		if (!class_exists($class)) {
			throw new InvalidArgumentException(
				"Handler {$class} does not exist."
			);
		}

		return [$handler, json_decode($params, true) ?? []];
	}

	/**
	 * Sign a job so the async request can prove this site queued it.
	 *
	 * @param  string $handler
	 * @param  string $params
	 * @param  string $time
	 * @return string
	 */
	protected function signature($handler, $params, $time)
	{
		return hash_hmac(
			'sha256',
			implode('|', [$this->makeAsyncHookAction(), $handler, $time, $params]),
			wp_salt('auth')
		);
	}

	/**
	 * Execute the action handler.
	 * 
	 * @param  \FluentAffiliate\Framework\Foundation\Application $app
	 * @param  string $class
	 * @param  array  $params
	 * @return void
	 */
	protected function execute($app, $class, $params = [])
	{
		set_time_limit(0);
        ignore_user_abort(true);
        [$class, $method] = explode('@', $class);
		$app->make($class)->{$method}($app, $params);
	}

	/**
	 * Add the async handler and register the shutdown handler
	 * All the handlers will be dispatched in a separate request
	 * 
	 * @param  string $handler (Class@handler or with __invoke method) $handler
	 * @return self
	 * @throws \InvalidArgumentException
	 */
	public static function call($handler, array $params = [])
	{
		if (!self::$instance) {
			static::init();
		}

		self::$handlers[] = self::$instance->validate(
			$handler, $params, static::sign(debug_backtrace(false, 1)[0])
		);
		
		return self::$instance->maybeRegisterShutDownHandler();
	}

	/**
	 * Queue an async handler to be executed during shutdown.
	 *
	 * Queued handlers are grouped by queue name and dispatched
	 * together in a single async HTTP request.
	 *
	 * @param string        $handler The handler 'Class@method'|invokable class.
	 * @param array|string  $params  Array of args or the queue name if a string.
	 * @param string        $name    The name of the queue (default is 'default').
	 * @return self
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function queue(
		$handler, $params = [], $name = 'default'
	) {
		if (!self::$instance) {
			static::init();
		}
		
		if (is_string($params)) {
			$name = $params;
			$params = [];
		}

		self::$queue[$name][] = self::$instance->validate(
			$handler, $params, static::sign(debug_backtrace(false, 1)[0])
		);
		
		return self::$instance->maybeRegisterShutDownHandler();
	}

	/**
	 * Sign the handler to mark as dispatched.
	 * 
	 * @param  array $handler
	 * @return string
	 */
	protected static function sign($handler)
	{
		return md5($handler['file'] . $handler['line']);
	}

	/**
	 * Validate the handler and add a sign to mark as dispatched.
	 * 
	 * @param  string $handler (Class@handler or with __invoke method) $handler
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	public function validate($handler, $params, $sign)
	{
		$method = '__invoke';

		if (is_array($handler)) {
			if (is_object($handler[0])) {
				$handler[0] = get_class($handler[0]);
			}
			$handler = $handler[0] . '@' . $handler[1];
		}

		if (str_contains($handler, '@')) {
			[$handler, $method] = explode('@', $handler);
		}

		if (!class_exists($handler)) {
			throw new InvalidArgumentException(
				"Class {$handler} not found."
			);
		}

		if (!method_exists($handler, $method)) {
			throw new InvalidArgumentException(
				"Class {$handler} must implement __invoke or specify method."
			);	
		}

		if (json_encode($params) === false) {
			throw new InvalidArgumentException(
				'Async params must be JSON encodable: ' . json_last_error_msg()
			);
		}

		$handler = $handler.'@'.$method;

		return [
			'sign' => $sign,
			'params'  => $params,
			'handler' => base64_encode($handler),
		];
	}

	/**
	 * Register the shutdown handler
	 * 
	 * @return self
	 */
	protected function maybeRegisterShutDownHandler()
	{
		$handler = [self::$instance, 'dispatch'];

		if (!self::$app->hasAction('shutdown', $handler)) {
			self::$app->addAction('shutdown', $handler);
		}

		return self::$instance;
	}

	/**
	 * Dispatches the async requests: one per named queue, carrying all of
	 * its jobs, and one per job added with call().
	 * 
	 * @return void
	 */
	public function dispatch()
	{
		// Every job is marked as dispatched before any request is sent, so
		// a job that queues itself again from its own request is skipped.
		$requests = [];

		foreach (array_filter(self::$queue) as $jobs) {
			if ($jobs = $this->getDispatchables($jobs)) {
				$requests[] = $jobs;
			}
		}

		foreach ($this->getDispatchables(self::$handlers) as $job) {
			$requests[] = [$job];
		}

		foreach ($requests as $jobs) {
			$this->sendAsyncRequest($jobs);
		}
	}

	/**
	 * Keep the jobs that were not already dispatched by the request that
	 * sent this one.
	 * 
	 * @param  array $jobs
	 * @return array
	 */
	protected function getDispatchables($jobs)
	{
		$dispatched = (array) self::$app->request->post('dispatched', []);

		return array_values(array_filter($jobs, function ($job) use ($dispatched) {
			if (!isset($job['sign']) || in_array($job['sign'], $dispatched, true)) {
				return false;
			}

			$this->dispatched[] = $job['sign'];

			return true;
		}));
	}

	/**
	 * Send the real async request
	 * 
	 * @param  array $handler
	 * @return mixed
	 */
	public function sendAsyncRequest(array $handler)
	{
		Helper::retry(3, function () use ($handler) {
			return $this->sendRequest(
				$this->url(),
				$this->data($handler),
				['cookie' => $this->getCookie()]
			);
		}, 2000, function ($e) {
	        return str_contains($e->getMessage(), 'cURL');
	    });
	}

	/**
	 * Prepare the request body/POST data.
	 * 
	 * @param  string|array $handler
	 * @return array
	 */
	protected function data($handler)
	{
		$post = self::$app->request->post();

		$data = [
	        'handlers' => array_map([$this, 'signJob'], $handler),
	        'wpfluent_async_nonce' => wp_create_nonce(
	        	self::$app->config->get('app.slug')
	        ),
	        'dispatched' => array_unique(array_merge(
	        	Arr::get($post, 'dispatched', []),
	        	$this->dispatched,
	        )),
	    ];

		return array_merge($data, Arr::except($post, [
		    'handlers', 'wpfluent_async_nonce', 'dispatched'
		]));
	}

	/**
	 * Add the params as JSON, the time and the signature to a job.
	 *
	 * @param  array $job
	 * @return array
	 */
	protected function signJob(array $job)
	{
		$job['params'] = json_encode($job['params']);
		$job['time'] = (string) time();

		$job['signature'] = $this->signature(
			$job['handler'], $job['params'], $job['time']
		);

		return $job;
	}

	/**
	 * Build the request url.
	 * 
	 * @return string
	 */
	protected function url()
	{
		return admin_url('admin-post.php') . '?' . http_build_query(
			array_merge(
				self::$app->request->query(),
				['action' => $this->makeAsyncHookAction()]
			)
		);
	}

	/**
	 * Send the non-blocking request.
	 * 
	 * @param  string $url
	 * @param  array $body
	 * @param  array  $headers
	 * @return mixed
	 */
	protected function sendRequest($url, $body = [], $headers = [])
	{
		return wp_remote_post($url, [
	        'timeout'   => 0.01,
	        'blocking'  => false,
	        'sslverify' => false,
	        'body'      => $body,
	        'headers'   => $headers,
	    ]);
	}

	/**
	 * Get the cookie to send with the request
	 * @return string Cookie string
	 */
	protected function getCookie()
	{
		$cookies = [];

		foreach ($_COOKIE as $name => $value) {
			$cookies[] = "$name=" . urlencode(
				is_array($value) ? serialize($value) : $value
			);
		}

		return implode('; ', $cookies);
	}
}
