<?php

namespace App\Exceptions;

class InvalidStateTransitionException extends ApiException
{
    public function __construct(string $from, string $to, string $entity = 'order')
    {
        parent::__construct(
            message: __('messages.order.invalid_status_transition', ['entity' => $entity, 'from' => $from, 'to' => $to]),
            status: 422,
            errors: ['from' => $from, 'to' => $to]
        );
    }
}
