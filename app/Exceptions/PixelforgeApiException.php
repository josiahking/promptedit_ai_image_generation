<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfterSeconds,
        public readonly bool $retryable,
        public readonly bool $acceptanceUncertain,
        string $message,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
