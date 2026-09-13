<?php

namespace App\Support;

/**
 * The single source of truth for "has this deployment been installed yet."
 * Deliberately a plain file under storage/app (private disk, never
 * web-reachable — see MediaService/config/media.php for the same
 * public-vs-private disk distinction) rather than a database row: the
 * whole point of the installer is to work when the database has ZERO
 * tables, so the lock cannot itself live in the database.
 *
 * This is intentionally the ONLY signal EnsureNotInstalled checks — it
 * must stay false for the whole multi-step wizard (run -> finalize ->
 * lock), even after the Super Admin already exists, so those later steps
 * stay reachable. The "can't take over an already-installed app" guarantee
 * instead comes from each individual write endpoint's own check
 * (InstallController::hasSuperAdmin() — run()/lock() both refuse to
 * proceed if a Super Admin already exists), which holds regardless of
 * whether this lock file happens to be present.
 *
 * Uses a distinct path under the testing environment — this file otherwise
 * aliases the exact same real dev/production install marker, and a test
 * run must never be able to mark a real deployment as installed (or read
 * one as already installed) just by sharing a filesystem checkout.
 */
class InstallLock
{
    private static function path(): string
    {
        return app()->environment('testing')
            ? storage_path('framework/testing/installed.lock')
            : storage_path('app/installed.lock');
    }

    public static function isInstalled(): bool
    {
        return file_exists(self::path());
    }

    public static function markInstalled(): void
    {
        file_put_contents(self::path(), now()->toIso8601String());
    }
}
