<?php

namespace App\Services\Sanitizer;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * The one place any CKEditor 5-authored HTML gets cleaned before it ever
 * touches the database — "Jangan langsung percaya HTML yang dikirim frontend."
 * Every CMS body/description field (articles, pages, homepage blocks,
 * product descriptions) is sanitized through here, never trusted verbatim
 * just because only an internal role could have submitted it.
 */
class HtmlSanitizerService
{
    private ?HTMLPurifier $purifier = null;

    public function sanitize(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        // Matches exactly what the dashboard's CKEditor 5 toolbar can produce
        // (Blueprint: bold, italic, strikethrough, heading, paragraph, list,
        // link, image, quote, alignment) — nothing else survives,
        // script/style/iframe included. CKEditor wraps block images in a
        // <figure> the allowlist below doesn't include; HTMLPurifier just
        // unwraps it and keeps the inner <img>, same end result as before.
        $config->set('HTML.Allowed', implode(',', [
            'p[style]', 'br', 'strong', 'b', 'em', 'i', 's', 'u',
            'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]',
            'ul', 'ol', 'li',
            'a[href|title|target|rel]',
            'img[src|alt|width|height]',
            'blockquote', 'code', 'pre',
        ]));
        $config->set('CSS.AllowedProperties', ['text-align']);
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // No on-disk definition cache — sanitizing a CMS save isn't a hot
        // path, and this avoids needing a writable cache directory at all.
        $config->set('Cache.DefinitionImpl', null);

        return $this->purifier = new HTMLPurifier($config);
    }
}
