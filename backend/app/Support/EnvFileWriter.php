<?php

namespace App\Support;

/**
 * Safely updates key=value pairs in the real .env file on disk — the ONLY
 * place the installer ever persists database/app configuration (never a
 * database row, never echoed back in an API response). Existing keys are
 * replaced in place; missing keys are appended. Values are quoted when
 * they contain whitespace/quotes/#, matching Dotenv's own parsing rules,
 * so a value round-trips correctly the next time Laravel boots and reads
 * it via env().
 *
 * Writes to a throwaway file under the testing environment instead of the
 * real project .env — an automated test exercising the installer's
 * database/app-config endpoints must never be able to overwrite the real
 * dev/production .env just by running the test suite (same reasoning as
 * InstallLock's testing-only lock path).
 */
class EnvFileWriter
{
    public static function path(): string
    {
        return app()->environment('testing')
            ? storage_path('framework/testing/.env.install-test')
            : base_path('.env');
    }

    /** @param  array<string, string>  $values */
    public static function set(array $values): void
    {
        $path = self::path();
        $content = file_exists($path) ? file_get_contents($path) : '';
        $lines = $content === '' ? [] : explode("\n", rtrim($content, "\n"));

        foreach ($values as $key => $value) {
            $formatted = $key.'='.self::format((string) $value);
            $found = false;

            foreach ($lines as $i => $line) {
                if (preg_match('/^'.preg_quote($key, '/').'=/', $line)) {
                    $lines[$i] = $formatted;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $lines[] = $formatted;
            }
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    private static function format(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
