<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Schema::hasTable() assumes a working database connection — before the
 * installer has configured one (or with wrong/placeholder credentials
 * still sitting in a fresh .env.example copy), it throws a connection
 * exception instead of returning false. Every pre-install safety check in
 * this app (SetLocale, EnsurePreInstallSafeDrivers, InstallController
 * itself) needs "no table" and "no connection at all" to behave
 * identically — both simply mean "not ready yet".
 */
class SafeSchema
{
    public static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
