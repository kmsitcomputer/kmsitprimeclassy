<?php

namespace App\Exceptions;

use Exception;

/**
 * Base for domain-level exceptions that should surface as a specific HTTP
 * status + message through the API's standard error envelope, instead of
 * bubbling up as a generic 500. Catch this type in the exception handler.
 */
class ApiException extends Exception
{
    protected int $status = 422;

    protected array $errors = [];

    public function __construct(string $message, int $status = 422, array $errors = [])
    {
        parent::__construct($message);

        $this->status = $status;
        $this->errors = $errors;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
