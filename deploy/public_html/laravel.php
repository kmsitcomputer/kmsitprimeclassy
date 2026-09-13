<?php

// Front controller for the single-domain deployment layout — identical to
// backend/public/index.php, just relocated to sit alongside the frontend's
// own static files in one shared document root, with its require paths
// adjusted to reach the Laravel app one level up in ../backend/ instead of
// the usual ../ (see bootstrap/app.php's single-domain detection block,
// and CARA_DEPLOY.md for the full layout this expects).
//
// Never renamed to index.php: this directory's real index.php is the
// frontend's own SPA shell (index.html is the actual entry, but keeping this
// distinctly named avoids ever colliding with it). The root .htaccess
// rewrites /api, /sanctum, and /up requests here — REQUEST_URI stays intact
// through that rewrite, so Laravel's own router still sees the full
// original path (e.g. /api/v1/products) exactly as it does when deployed
// as its own dedicated document root.

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../backend/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../backend/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../backend/bootstrap/app.php')
    ->handleRequest(Request::capture());
