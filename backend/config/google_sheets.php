<?php

return [
    'enabled' => env('GOOGLE_SHEETS_ENABLED', true),
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH', storage_path('app/private/google/service-account.json')),
    'max_rows' => 10000,
    'timeout' => 30,
];
