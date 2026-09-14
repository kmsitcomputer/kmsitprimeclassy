<?php

namespace App\Services\GoogleSheets;

use RuntimeException;

class GoogleSheetsException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $googleReason = null,
    ) {
        parent::__construct($message);
    }
}
