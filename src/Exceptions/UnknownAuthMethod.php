<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class UnknownAuthMethod extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Invalid Authentication method selected, please check method", $code, $message, $previous);
    }
}
