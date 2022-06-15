<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use Throwable;

class PermissionDenied extends RuntimeException
{
    #[Pure] public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Strapi returned Permission denied" . $message, $code, $previous);
    }
}
