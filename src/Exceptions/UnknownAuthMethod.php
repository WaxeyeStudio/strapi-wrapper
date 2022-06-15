<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use Throwable;

class UnknownAuthMethod extends RuntimeException
{
    #[Pure] public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Invalid Authentication method selected, please check method" . $message, $code, $previous);
    }
}
