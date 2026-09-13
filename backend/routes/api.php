<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Versioning
|--------------------------------------------------------------------------
| All application endpoints live under /api/v1/... in routes/api_v1.php.
| A future breaking change ships as routes/api_v2.php mounted alongside this
| one — v1 controllers/routes are never edited to accommodate v2.
*/

Route::prefix('v1')->group(base_path('routes/api_v1.php'));
