<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;

/**
 * Safe template engine: `{variable}` tokens from a per-event allow-list only.
 *
 *  - Validation (save time): unknown tokens, unbalanced braces, control characters and over-long text are rejected.
 *  - Rendering: values are sanitized (control chars stripped, `{`/`}` neutralised so a customer name can never inject a
 *    new token or break out, length-capped); a template line whose OPTIONAL variable is unavailable is DROPPED
 *    (we never invent a courier or tracking number); a missing REQUIRED variable aborts the render.
 */
final class MessageTemplates
{
    public const MAX_LENGTH = 1000;

    private const VALUE_MAX = 200;

    /** @return array<int, string> tokens used in the template (without braces) */
    public static function tokens(string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @throws ApiException 422 with the offending detail
     */
    public static function validate(string $event, string $template): void
    {
        $def = WhatsAppEvents::all()[$event] ?? null;
        if ($def === null) {
            throw new ApiException(__('messages.whatsapp.unknown_event'), 422, ['event' => [__('messages.whatsapp.unknown_event')]]);
        }

        $trimmed = trim($template);
        if ($trimmed === '' || mb_strlen($template) > self::MAX_LENGTH) {
            throw new ApiException(__('messages.whatsapp.template_length', ['max' => self::MAX_LENGTH]), 422, ['template' => [__('messages.whatsapp.template_length', ['max' => self::MAX_LENGTH])]]);
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $template)) {
            throw new ApiException(__('messages.whatsapp.template_chars'), 422, ['template' => [__('messages.whatsapp.template_chars')]]);
        }

        // Every brace must belong to a well-formed {token}; anything else (`{{x}}`, `{x`, `}`) is refused.
        $stripped = preg_replace('/\{[a-z_]+\}/', '', $template) ?? '';
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            throw new ApiException(__('messages.whatsapp.template_braces'), 422, ['template' => [__('messages.whatsapp.template_braces')]]);
        }

        $unknown = array_values(array_diff(self::tokens($template), $def['variables']));
        if ($unknown !== []) {
            $msg = __('messages.whatsapp.template_unknown_vars', ['vars' => '{'.implode('}, {', $unknown).'}']);
            throw new ApiException($msg, 422, ['template' => [$msg]]);
        }
    }

    /**
     * @param  array<string, string|null>  $data  null / '' = unavailable
     * @return string|null null when a REQUIRED variable is unavailable
     */
    public static function render(string $event, string $template, array $data): ?string
    {
        $def = WhatsAppEvents::all()[$event];

        foreach ($def['required'] as $required) {
            if (self::clean($data[$required] ?? null) === null) {
                return null;
            }
        }

        $lines = [];
        foreach (preg_split('/\R/', $template) ?: [] as $line) {
            $dropLine = false;
            $rendered = preg_replace_callback('/\{([a-z_]+)\}/', function (array $m) use ($data, &$dropLine) {
                $value = self::clean($data[$m[1]] ?? null);
                if ($value === null) {
                    // nama_pelanggan has a polite generic fallback; any other optional value drops the whole line.
                    if ($m[1] === 'nama_pelanggan') {
                        return 'Pelanggan';
                    }
                    $dropLine = true;

                    return '';
                }

                return $value;
            }, $line);

            if (! $dropLine) {
                $lines[] = $rendered;
            }
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

        return $text === '' ? null : $text;
    }

    /** Sanitize one value: strip control chars, neutralise braces (no token injection), cap the length. */
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = strtr($s, ['{' => '(', '}' => ')']);

        return mb_substr($s, 0, self::VALUE_MAX);
    }

    public static function preview(string $event, string $template): string
    {
        return (string) self::render($event, $template, WhatsAppEvents::sample());
    }
}
