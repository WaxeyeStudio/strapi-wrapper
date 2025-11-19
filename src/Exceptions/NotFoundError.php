<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Throwable;

class NotFoundError extends BaseException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        $msg = 'Strapi return a 404 not found error';
        parent::__construct($msg, $code, $message, $previous, false, $context);
    }
}
