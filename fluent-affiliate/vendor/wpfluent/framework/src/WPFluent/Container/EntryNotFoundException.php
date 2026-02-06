<?php

namespace FluentAffiliate\Framework\Container;

use Exception;
use FluentAffiliate\Framework\Container\Contracts\Psr\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
