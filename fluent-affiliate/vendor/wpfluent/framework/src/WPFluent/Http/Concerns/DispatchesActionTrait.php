<?php

namespace FluentAffiliate\Framework\Http\Concerns;

use Closure;
use Throwable;
use WP_REST_Response;
use FluentAffiliate\Framework\Support\Str;
use FluentAffiliate\Framework\Support\Pipeline;
use FluentAffiliate\Framework\Validator\ValidationException;
use FluentAffiliate\Framework\Database\Orm\ModelNotFoundException;
use FluentAffiliate\Framework\Foundation\Exceptions\HttpException;
use FluentAffiliate\Framework\Foundation\Exceptions\AuthorizationException;
use FluentAffiliate\Framework\Foundation\Exceptions\ExceptionHandler;
use FluentAffiliate\Framework\Http\Response\FileResponse;
use FluentAffiliate\Framework\Http\Response\Response as WPFluentResponse;

trait DispatchesActionTrait
{
    /**
     * Route handler
     *
     * @return \WP_REST_Response
     */
    public function callback()
    {
        try {
            $this->response = $this->handleAfterMiddleware(
                $this->dispatchRouteAction()
            );

            return $this->handleResponse($this->response);

        } catch (ValidationException $e) {
            return $this->app->response->sendError(
                $e->errors(), $e->getCode()
            );
        } catch (ModelNotFoundException $e) {
            return $this->app->response->sendError([
                'message' => $this->modelNotFoundMessage($e)
            ], 404);
        } catch (HttpException $e) {
            return $this->renderHttpException($e);
        } catch (Throwable $e) {
            $headers = $this->response ? $this->response->get_headers() : [];

            // Consult the plugin's ExceptionHandler registry BEFORE the
            // production sanitizer. A registered renderable may return
            // either an HttpException (rendered with full status + safe
            // message) or a WP_REST_Response (returned verbatim). Null /
            // no-match falls through to handleUnknownException — the
            // sanitization default is preserved for any exception not
            // explicitly opted in.
            if ($mapped = $this->mapToHandlerResponse($e)) {
                return $mapped;
            }

            return $this->handleUnknownException($e, $headers);
        }
    }

    /**
     * Run the bound `ExceptionHandler` over `$e` and convert its result
     * to a `WP_REST_Response`, or `null` if the handler has nothing for
     * this exception (in which case the caller falls through to the
     * sanitizer).
     *
     * Returns an `HttpException` result through `renderHttpException()`
     * so observability + headers + the `{code, message, data}` shape
     * stay consistent with the dedicated `HttpException` catch arm.
     * A `WP_REST_Response` is returned verbatim — the renderer claimed
     * full control over the response shape; we still fire
     * `fluent_exception` so observability listeners see the original
     * exception.
     *
     * @param  \Throwable $e
     * @return \WP_REST_Response|null
     */
    protected function mapToHandlerResponse(Throwable $e)
    {
        if (!$this->app->bound(ExceptionHandler::class)) {
            return null;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if (!$handler instanceof ExceptionHandler) {
            return null;
        }

        $result = $handler->render($e, $this->app);

        if ($result instanceof HttpException) {
            return $this->renderHttpException($result);
        }

        if ($result instanceof WP_REST_Response) {
            $this->fireExceptionEvent($e);
            return $result;
        }

        return null;
    }

    /**
     * Handle response from route.
     * 
     * @param  \WP_REST_Response $response
     * @return \WP_REST_Response
     */
    protected function handleResponse($response)
    {
        return $response;
    }

    /**
     * Handle exception and send error response.
     * 
     * @param  Throwable $e
     * @return \WP_REST_Response
     */
    protected function handleUnknownException(Throwable $e, $headers = [])
    {
        $data = [];

        $this->fireExceptionEvent($e);

        // Production sanitization: client-facing message must not leak
        // PDO / HTTP-client / file-system internals. The real message
        // ships to fluent_exception listeners (Night Watcher / bridge)
        // via fireExceptionEvent above, so observability is preserved.
        if ($this->app->isDebugOn()) {
            $data = [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ];

            $message = $e->getMessage();
        } else {
            $message = 'An internal error occurred.';
        }

        return $this->app->response->sendError([
            'code'    => 'plugin_exception',
            'data'    => $data,
            'message' => $message,
        ], $this->httpStatusOf($e, 500), $headers);
    }

    /**
     * The HTTP status an exception's code asks for.
     *
     * Only an integer below 600 can be one; anything else, such as a
     * database error's code, is a server error.
     *
     * @param  \Throwable  $e
     * @param  int  $default  For an exception without a code.
     * @return int
     */
    protected function httpStatusOf(Throwable $e, $default)
    {
        $code = $e->getCode();

        if (!is_int($code) || $code >= 600) {
            return 500;
        }

        return $code ?: $default;
    }

    /**
     * The message for a model that wasn't found.
     *
     * The framework's own message names the model class, so outside debug
     * it is only shown when the plugin wrote the message itself.
     *
     * @param  \FluentAffiliate\Framework\Database\Orm\ModelNotFoundException  $e
     * @return string
     */
    protected function modelNotFoundMessage(ModelNotFoundException $e)
    {
        if (!$e->getModel() || $this->app->isDebugOn()) {
            return $e->getMessage();
        }

        return 'Not found.';
    }

    /**
     * Render an HttpException to a sanitization-free response.
     *
     * HttpException is the opt-in contract for "I authored this message,
     * it is safe to ship to the client". Bypasses handleUnknownException's
     * production sanitization but still fires fluent_exception for
     * observability so listeners see every thrown HttpException.
     *
     * @param  HttpException $e
     * @return \WP_REST_Response
     */
    protected function renderHttpException(HttpException $e)
    {
        $this->fireExceptionEvent($e);

        return $this->app->response->sendError([
            'code'    => $e->getErrorCode(),
            'message' => $e->getMessage(),
            'data'    => $e->getData(),
        ], $e->getStatusCode(), $e->getHeaders());
    }

    /**
     * Dispatch the route action.
     *
     * @return \WP_REST_Response
     */
    protected function dispatchRouteAction()
    {
        $this->download = null;
        $this->dispatching = true;

        try {
            $response = $this->app->call(
                $this->action, $this->getControllerParameters()
            );
        } finally {
            $this->dispatching = false;
        }

        // A controller that calls download() without returning it still
        // sends the file.
        if (is_null($response) && $this->download) {
            $response = $this->download;
        }

        $this->download = null;

        if ($response instanceof WPFluentResponse) {
            $response = $response->toArray();
        } elseif (!($response instanceof WP_REST_Response)) {
            $response = !is_wp_error($response) ?
                $this->app->response->sendSuccess($response) :
                $this->app->response->wpErrorToResponse($response);
        }

        // Headers and cookies queued on the app's response reach the
        // client whatever response object the controller returned.
        return $this->app->response->mergeQueuedInto($response);
    }

    /**
     * Whether the route's action is running.
     *
     * @return bool
     */
    public function isDispatching()
    {
        return $this->dispatching;
    }

    /**
     * Remember the file the action asked to download.
     *
     * @param  \FluentAffiliate\Framework\Http\Response\FileResponse $file
     * @return \FluentAffiliate\Framework\Http\Response\FileResponse
     */
    public function keepDownload(FileResponse $file)
    {
        return $this->download = $file;
    }

    /**
     * Handle after middleware if any.
     *
     * @param mixed $response
     * @return mixed
     */
    protected function handleAfterMiddleware($response)
    {
        if (!$this->skipMiddleware) {
            $response = $this->app->make(Pipeline::class)
                ->send(new WPFluentResponse($response))
                ->through($this->collectMiddleware('after'))
                ->then(function($response) {
                    return $this->normalize($response);
                });

            if (!$response) {
                $response = $this->app->request->abort();
            }
        }

        return $response;
    }

    /**
     * Normalize the response.
     * 
     * @param  mixed $response
     * @return mixed
     */
    protected function normalize($response)
    {
        if ($response instanceof WPFluentResponse) {
            $response = $response->toArray();
        }

        if (!$response instanceof WP_REST_Response) {
            return new WP_REST_Response($response);
        }

        return $response;
    }

    /**
     * Fire exception action hook.
     * 
     * @param  Exception $exception
     * @return void
     */
    protected function fireExceptionEvent($exception)
    {
        // A refusal isn't an error, as a policy that returns false isn't.
        if (!$this->reportExceptions || $exception instanceof AuthorizationException) {
            return;
        }

        // Reentrancy guard: a fluent_exception listener that itself triggers
        // an exception path must not re-enter this method and recurse. Reset
        // in finally so subsequent (sequential) calls proceed normally.
        static $firing = false;

        if ($firing) {
            return;
        }

        if ($this->app->isDebugOn() || defined('FLUENT_BRIDGE_SECRET')) {
            $message = sprintf(
                "%s in %s:%d\nStack trace:\n%s\n",
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );

            error_log($message);
        }

        $firing = true;

        try {
            $this->app->doAction('fluent_exception', $exception);
        } catch (Throwable $listenerError) {
            // Listener-throw isolation: a buggy fluent_exception listener
            // (DB down, disk full) must not escape and crash the response.
            // Log under the same gate; never re-fire fluent_exception here
            // — that would be the cascade we are protecting against.
            if ($this->app->isDebugOn() || defined('FLUENT_BRIDGE_SECRET')) {
                error_log(
                    'fluent_exception listener failed: ' . $listenerError->getMessage()
                );
            }
        } finally {
            $firing = false;
        }
    }

    /**
     * Gether route params after substituted the params
     *
     * @return array
     */
    protected function getControllerParameters()
    {
        // Models are looked up on first use, after the before-middleware,
        // and once for both the policy and the controller.
        if (is_null($this->substitutedParameters)) {
            $routeParameters = $this->getParameter();

            $this->substitutedParameters = $routeParameters
                ? $this->substituteParameters($routeParameters)
                : [];
        }

        return $this->substitutedParameters;
    }

    /**
     * Parse the rest and permission/policy handlers
     *
     * @param \WP_REST_Request $request
     * @return null
     * @throws \BadMethodCallException
     */
    public function prepareCallbacks($request)
    {
        $handler = $this->app->parseRestHandler($this->handler, $this->namespace);

        [$action, $controller] = $this->resolveHandlerDetails($handler);

        $policyHandler = $this->resolvePolicyHandler();

        $this->actionInfo = [
            'handler'             => is_object($handler) ? $action : $handler,
            'controller'          => $controller,
            'method'              => $this->getMethodName($action, $handler),
            'path'                => $this->uri,
            'http_method'         => $request->get_method(),
            'full_uri'            => $request->get_route(),
            'permission_callback' => $policyHandler,
            'compiled_url'        => $this->compiled
        ];

        $this->action = $handler;

        return $this->action;
    }

    /**
     * Get the method name to build action info.
     * 
     * @param  mixed $action
     * @param  mixed $handler
     * @return string|null
     */
    protected function getMethodName($action, $handler)
    {
        $method = is_array($action) ? $action[1] ?? '__invoke' : null;

        if (is_null($method) && is_object($handler)) {
            $method = '__invoke';
        }

        return $method;
    }

    /**
     * Resolve the handler details.
     * 
     * @param  mixed $handler
     * @return array
     */
    protected function resolveHandlerDetails($handler)
    {
        if ($handler instanceof Closure) {
            return ['Closure', null];
        }

        if (is_object($handler)) {
            $class = get_class($handler);
            return [$class, $class];
        }

        $handler = trim($handler, '\\');
        [$controller, $method] = Str::parseCallback($handler, '__invoke');
        $controllerName = $this->extractControllerName($controller);

        return [[$controller, $method], $controllerName];
    }

    /**
     * Extract the controller name from the FQCN.
     * 
     * @param  string $fqcn
     * @return string      
     */
    protected function extractControllerName($fqcn)
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
