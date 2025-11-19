<?php

namespace SilentWeb\StrapiWrapper\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

abstract class BaseException extends RuntimeException
{
    protected array $context = [];

    public function __construct(string|array $message = '', int $code = 0, string|array|null $additionalData = null, ?Throwable $previous = null, bool $writeToLog = true, array $context = [])
    {
        $this->context = $context;

        if ($writeToLog) {
            if (is_array($additionalData)) {
                foreach ($additionalData as $value) {
                    Log::error($value);
                }
            } elseif (isset($additionalData)) {
                Log::error($additionalData);
            }
        }

        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
