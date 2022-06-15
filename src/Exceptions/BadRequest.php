<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use Throwable;

class BadRequest extends RuntimeException
{
    #[Pure] public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Strapi return a 400 Bad Request error " . $message, $code, $previous);
    }
}
