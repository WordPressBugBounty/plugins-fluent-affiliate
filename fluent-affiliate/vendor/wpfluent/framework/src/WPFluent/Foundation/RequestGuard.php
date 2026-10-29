<?php

namespace FluentAffiliate\Framework\Foundation;

use FluentAffiliate\Framework\Foundation\App;
use FluentAffiliate\Framework\Validator\ValidationException;
use FluentAffiliate\Framework\Foundation\Exceptions\AuthorizationException;

/**
 * @property \FluentAffiliate\Framework\Http\Request\Request $request
 */
abstract class RequestGuard
{
    /**
     * The request instance.
     * @var \FluentAffiliate\Framework\Http\Request\Request
     */
    protected $request;

    /**
     * Retrive the validation rules
     * @return array
     */
    public function rules()
    {
        return [];
    }

    /**
     * Retrive the validation messages set by the developer
     * @return array
     */
    public function messages()
    {
        return [];
    }

    /**
     * Allow the developer tinker with data before the validation.
     * @return array
     */
    public function beforeValidation()
    {
        return [];
    }

    /**
     * Allow the developer tinker with data after the validation.
     * @return array
     */
    public function afterValidation($validator)
    {
        return [];
    }

    /**
     * Validate the request.
     * 
     * @param  array $rules Optional
     * @param  array $messages Optional
     * @return array Request Data
     * @throws \FluentAffiliate\Framework\Validator\ValidationException
     */
    public function validate($rules = [], $messages = [])
    {
        try {
            return $this->request->validate(
                $rules ?: (array) $this->rules(),
                $messages ?: (array) $this->messages()
            );
        } catch (ValidationException $e) {
            
            $validator = App::make('validator');
            
            $validator->addError($e->errors());

            if (!($errors = $validator->errors())) {
                return $this->afterValidation($validator);
            }

            $e = new ValidationException(
                'Unprocessable Entity!', 422, null, $e->errors()
            );

            if ($this->shouldThrowException()) {
                throw $e;
            }

            App::make()->doCustomAction('handle_exception', $e);

            // A handle_exception listener may answer and end the request.
            // If none did, stop here instead of running on invalid data.
            if (wp_doing_ajax()) {
                wp_send_json_error([
                    'message' => $e->getMessage(),
                    'errors'  => $e->errors(),
                ], 422);
            }

            throw $e;
        }
    }

    /**
     * Refuse the request when authorize() returns false.
     *
     * In admin-ajax the refusal is answered as JSON with its status, as a
     * failed validation is; anywhere else it is thrown.
     *
     * @return void
     * @throws \FluentAffiliate\Framework\Foundation\Exceptions\AuthorizationException
     */
    public function failedAuthorization()
    {
        $e = new AuthorizationException;

        if (wp_doing_ajax() && !$this->isRest()) {
            wp_send_json_error([
                'code'    => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        throw $e;
    }

    /**
     * Check if exceptions should be thrown.
     * 
     * @return bool
     */
    protected function shouldThrowException()
    {
        $isTrue = $this->isRest();
        $isTrue = $isTrue || str_contains(App::make()->env(), 'test');
        $isTrue = $isTrue || str_contains(strtolower(php_sapi_name()), 'cli');
        return $isTrue;
    }

    /**
     * Handles validation including before and after calls
     *
     * @throws \FluentAffiliate\Framework\Validator\ValidationException
     * @return void
     */
    public static function applyValidation()
    {
        $instance = new static;

        $request = App::make('request');

        $request->merge($instance->beforeValidation());

        $instance->validate();

        $request->merge($instance->afterValidation(App::make('validator')));
    }

    /**
     * Get an input element from the request.
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * Set an input element to the request.
     *
     * @param  string $key
     * @return mixed
     */
    public function __set($key, $value)
    {
        return $this->set($key, $value);
    }

    /**
     * Set the request instance.
     * 
     * @param \FluentAffiliate\Framework\Http\Request\Request $request
     */
    public function setRequestInstance($request)
    {
        $this->request = $request;
    }

    /**
     * Handle the dynamic method calls
     * @param  string $method
     * @param  array $params
     * @return mixed
     */
    public function __call($method, $params)
    {
        return call_user_func_array(
            [$this->request, $method], $params
        );
    }
}
