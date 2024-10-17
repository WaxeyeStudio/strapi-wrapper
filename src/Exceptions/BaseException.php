<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

abstract class BaseException extends RuntimeException
{
    public function __construct(string|array $message = "", int $code = 0, string|array|null $additionalData = null, ?Throwable $previous = null)
    {
        if (is_array($additionalData)) {
            foreach ($additionalData as $value) {
                Log::error($value);
            }
        } else if (isset($additionalData)) {
            Log::error($additionalData);
        }

        parent::__construct($message, $code, $previous);
    }
}

