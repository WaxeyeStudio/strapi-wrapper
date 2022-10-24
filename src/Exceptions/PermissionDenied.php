<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class PermissionDenied extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Strapi returned Permission denied", $code, $message, $previous);
    }
}
