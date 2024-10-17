<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class UnknownError extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("An Unknown Error has occurred", $code, $message, $previous);
    }
}
