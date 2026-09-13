<?php

namespace App\Exceptions;

class InsufficientStockException extends ApiException
{
    public function __construct(string $itemLabel, int $available, int $requested)
    {
        parent::__construct(
            message: __('messages.order.insufficient_stock', ['item' => $itemLabel]),
            status: 422,
            errors: [
                'item' => $itemLabel,
                'available' => $available,
                'requested' => $requested,
            ]
        );
    }
}
