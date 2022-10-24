<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class BadRequest extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Strapi return a 400 Bad Request error ", $code, $message, $previous);
    }
}
