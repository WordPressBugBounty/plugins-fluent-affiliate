<?php

namespace FluentAffiliate\Framework\Container\Contracts;

use Exception;
use FluentAffiliate\Framework\Container\Contracts\Psr\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
