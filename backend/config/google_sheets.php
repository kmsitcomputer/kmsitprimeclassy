<?php
return [
    'enabled' => env('GOOGLE_SHEETS_ENABLED', false),
    'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH'),
    'max_rows' => 10000,
    'timeout' => 30,
];
