<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Illuminate\Support\Facades\Log;
use Throwable;

class NotFoundError extends BaseException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        $msg = "Strapi return a 404 not found error";
        Log::debug($msg . " " . $message);
        parent::__construct($msg, $code, $message, $previous, false);
    }
}
