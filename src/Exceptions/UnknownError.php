<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use Throwable;

class UnknownError extends RuntimeException
{
    #[Pure] public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("An Unknown Error has occurred" . $message, $code, $previous);
        abort(503);
    }
}
