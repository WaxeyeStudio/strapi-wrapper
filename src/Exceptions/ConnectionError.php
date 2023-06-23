<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class ConnectionError extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Error connecting to strapi ", $code, $message, $previous);
    }
}
