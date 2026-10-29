<?php

namespace FluentAffiliate\Framework\Foundation\Exceptions;

use Throwable;

/**
 * The caller may not do this: 401 for a guest, 403 for a logged-in user,
 * with WordPress's own code and message for a refused permission check.
 *
 * A refusal, not an error: a route answers it without reporting it on
 * fluent_exception, as it does a policy that returns false.
 */
class AuthorizationException extends HttpException
{
    /**
     * @param string|null     $message  WordPress's message when null.
     * @param string          $errorCode
     * @param array           $data
     * @param array           $headers
     * @param \Throwable|null $previous
     */
    public function __construct(
        $message = null,
        $errorCode = 'rest_forbidden',
        array $data = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(
            rest_authorization_required_code(),
            $message ?? __('Sorry, you are not allowed to do that.'),
            $errorCode,
            $data,
            $headers,
            $previous
        );
    }
}
