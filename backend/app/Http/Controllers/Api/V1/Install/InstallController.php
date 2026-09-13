<?php

namespace App\Http\Controllers\Api\V1\Install;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Install\ConfigureAppRequest;
use App\Http\Requests\Install\CreateSuperAdminRequest;
use App\Http\Requests\Install\SaveDatabaseConfigRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\EnvFileWriter;
use App\Support\InstallLock;
use App\Support\SafeSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * The full first-run wizard: requirements check, database connection
 * (test, then persist to .env), application configuration (.env again),
 * running migrations + seeders + creating the Super Admin, a cache-warming
 * finalize step, and finally the permanent lock. Every write endpoint here
 * is sealed off forever by EnsureNotInstalled (see routes/api_v1.php) the
 * moment InstallLock::markInstalled() runs — this class must never be
 * reachable again after that, on a production deployment or otherwise.
 */
class InstallController extends Controller
{
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo',
    ];

    public function status()
    {
        return $this->ok([
            'installed' => InstallLock::isInstalled(),
            'has_tables' => SafeSchema::hasTable('roles'),
            'has_super_admin' => $this->hasSuperAdmin(),
        ]);
    }

    public function requirements()
    {
        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');

        $extensions = collect(self::REQUIRED_EXTENSIONS)->map(fn ($ext) => [
            'label' => $ext,
            'ok' => extension_loaded($ext),
        ])->all();

        $permissions = [
            ['label' => 'storage/', 'ok' => is_writable(storage_path())],
            ['label' => 'bootstrap/cache/', 'ok' => is_writable(base_path('bootstrap/cache'))],
            ['label' => '.env', 'ok' => is_writable(base_path('.env')) || (! file_exists(base_path('.env')) && is_writable(base_path()))],
        ];

        $allOk = $phpOk
            && collect($extensions)->every(fn ($e) => $e['ok'])
            && collect($permissions)->every(fn ($p) => $p['ok']);

        return $this->ok([
            'php' => ['version' => PHP_VERSION, 'ok' => $phpOk, 'minimum' => '8.2.0'],
            'extensions' => $extensions,
            'permissions' => $permissions,
            'all_ok' => $allOk,
        ]);
    }

    public function testDatabaseConnection(SaveDatabaseConfigRequest $request)
    {
        [$ok, $message] = $this->attemptConnection($request);

        if (! $ok) {
            throw new ApiException($message, 422);
        }

        return $this->ok(null, __('messages.install.connection_success'));
    }

    public function saveDatabaseConfig(SaveDatabaseConfigRequest $request)
    {
        // Extra guard beyond the route's 'not.installed' (lock-file) check:
        // a Super Admin already existing means a real install has already
        // run in this database, regardless of whether the lock file is
        // still on disk — never let this endpoint repoint a live site's
        // database out from under it.
        if ($this->hasSuperAdmin()) {
            throw new ApiException(__('messages.install.already_installed'), 403);
        }

        [$ok, $message] = $this->attemptConnection($request);

        if (! $ok) {
            throw new ApiException($message, 422);
        }

        EnvFileWriter::set([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $request->string('host')->toString(),
            'DB_PORT' => (string) $request->integer('port'),
            'DB_DATABASE' => $request->string('database')->toString(),
            'DB_USERNAME' => $request->string('username')->toString(),
            'DB_PASSWORD' => $request->string('password')->toString(),
        ]);

        // APP_KEY itself is guaranteed to already exist by this point —
        // EnsurePreInstallSafeDrivers generates it on literally the first
        // request the app ever receives (see that class's docblock for why
        // it can't wait until this step).
        return $this->ok(null, __('messages.install.database_saved'));
    }

    public function configureApp(ConfigureAppRequest $request)
    {
        if ($this->hasSuperAdmin()) {
            throw new ApiException(__('messages.install.already_installed'), 403);
        }

        $frontendUrl = rtrim($request->string('frontend_url')->toString(), '/');
        $frontendHost = parse_url($frontendUrl, PHP_URL_HOST);
        $frontendPort = parse_url($frontendUrl, PHP_URL_PORT);
        $statefulDomain = $frontendPort ? "{$frontendHost}:{$frontendPort}" : $frontendHost;

        $values = [
            'APP_NAME' => $request->string('app_name')->toString(),
            'APP_URL' => rtrim($request->string('app_url')->toString(), '/'),
            'FRONTEND_URLS' => $frontendUrl,
            // Single-domain app: the browser origin and the API origin are the
            // same host, so Sanctum's stateful list is exactly that one host.
            'SANCTUM_STATEFUL_DOMAINS' => $statefulDomain,
        ];

        EnvFileWriter::set($values);

        return $this->ok(null, __('messages.install.app_configured'));
    }

    /** Migrate + seed + create the one and only Super Admin — all in one action, matching the wizard's "Install Database" step. */
    public function run(CreateSuperAdminRequest $request)
    {
        if (InstallLock::isInstalled()) {
            throw new ApiException(__('messages.install.already_installed'), 403);
        }

        Artisan::call('migrate', ['--force' => true]);

        // Keyed on the super_admin role actually existing, NOT on roles being
        // empty: some migrations (e.g. add_keuangan_role) insert their own
        // reference row, so a freshly migrated database is no longer empty
        // even though the base taxonomy was never seeded. RoleSeeder is
        // idempotent (updateOrInsert), so re-running it is safe.
        if (SafeSchema::hasTable('roles') && ! Role::query()->where('slug', 'super_admin')->exists()) {
            Artisan::call('db:seed', ['--force' => true]);
        }

        // Best-effort — a handful of very restrictive shared hosts disable
        // PHP's symlink() function outright. Uploaded media simply won't
        // resolve to a public URL until an operator creates the link
        // manually in that case; it must never block the rest of install.
        try {
            Artisan::call('storage:link');
        } catch (\Throwable) {
            // swallow — see comment above.
        }

        $role = Role::query()->where('slug', 'super_admin')->first();

        if (! $role) {
            throw new ApiException(__('messages.install.migrate_first'), 422);
        }

        if ($this->hasSuperAdmin()) {
            throw new ApiException(__('messages.install.admin_already_exists'), 422);
        }

        $user = DB::transaction(function () use ($request, $role) {
            return User::create([
                'role_id' => $role->id,
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'phone' => $request->string('phone'),
                'password' => $request->string('password'),
                'status' => 'active',
            ]);
        });

        return $this->created([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ], __('messages.install.admin_created'));
    }

    public function finalize()
    {
        if (InstallLock::isInstalled()) {
            throw new ApiException(__('messages.install.already_installed'), 403);
        }

        // Deliberately does NOT run config:cache/route:cache here — caching
        // from inside a live HTTP request (rather than a CLI deploy step)
        // can leave the current process's config/router in a half-cached
        // state for the rest of its lifetime, which is exactly the kind of
        // thing a first-run installer must never risk. This is a pure
        // summary/verification step; production operators should run
        // `php artisan config:cache && php artisan route:cache` themselves
        // as a normal deploy step (see README.md).
        return $this->ok([
            'has_tables' => SafeSchema::hasTable('roles'),
            'has_super_admin' => $this->hasSuperAdmin(),
            'storage_linked' => is_link(public_path('storage')),
        ], __('messages.install.finalized'));
    }

    public function lock()
    {
        if (InstallLock::isInstalled()) {
            throw new ApiException(__('messages.install.already_installed'), 403);
        }

        if (! $this->hasSuperAdmin()) {
            throw new ApiException(__('messages.install.cannot_lock_yet'), 422);
        }

        InstallLock::markInstalled();

        return $this->ok(null, __('messages.install.locked'));
    }

    private function hasSuperAdmin(): bool
    {
        if (! SafeSchema::hasTable('users') || ! SafeSchema::hasTable('roles')) {
            return false;
        }

        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'super_admin')
            ->exists();
    }

    /** @return array{0: bool, 1: string} */
    private function attemptConnection(SaveDatabaseConfigRequest $request): array
    {
        $host = $request->string('host')->toString();
        $port = $request->integer('port');
        $database = $request->string('database')->toString();
        $username = $request->string('username')->toString();
        $password = $request->string('password')->toString();

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            new PDO($dsn, $username, $password, [PDO::ATTR_TIMEOUT => 5]);

            return [true, ''];
        } catch (PDOException $e) {
            // Never echo the driver's raw message back verbatim — it can
            // include the DSN/username in some PDO error strings. Map to a
            // generic, translated reason instead.
            $reason = Str::contains($e->getMessage(), 'Unknown database')
                ? __('messages.install.connection_unknown_database')
                : __('messages.install.connection_failed');

            return [false, $reason];
        }
    }
}
