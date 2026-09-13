<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Presentation-only date formatting for reports/exports. Produces the
 * Indonesian "13 September 2026" shape (no time-of-day) so exported reports
 * match the dashboard. Never used for storage — database timestamps stay
 * datetime columns untouched.
 */
class HumanDate
{
    public static function date(CarbonInterface|string|int|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->locale('id')->translatedFormat('j F Y');
    }
}
