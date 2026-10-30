<?php

namespace App\Services\GoogleSheets;

use Illuminate\Support\Carbon;

/**
 * Resolves the stored period filter of a sheet configuration into a concrete
 * UTC query window.
 *
 * The business calendar is Asia/Jakarta (WIB) because the reports are read by
 * Indonesian operators; the database keeps every timestamp in UTC
 * (`APP_TIMEZONE=UTC`). A period is therefore resolved on the WIB calendar and
 * each boundary is converted to UTC before it is compared with `created_at`,
 * so no stored timestamp is ever converted twice.
 *
 * Relative periods ("harian", "mingguan", "bulanan") are resolved at SYNC time,
 * not at configuration time, so "harian" always means the WIB day the export
 * runs. Weeks start on Monday. `custom` uses the explicit from/to WIB dates
 * stored on the configuration.
 *
 * The returned `to` value is EXCLUSIVE (start of the day after the last WIB
 * date, in UTC), so a custom end date covers that whole WIB day.
 */
class ReportPeriodFilter
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const CUSTOM = 'custom';

    public const PERIODS = [self::DAILY, self::WEEKLY, self::MONTHLY, self::CUSTOM];

    /** Business calendar for report periods. Database timestamps stay UTC. */
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * @param  array{period?:?string,from?:?string,to?:?string}  $filters
     * @return array{from:?string,to:?string}  UTC datetimes; `to` is exclusive
     */
    public function resolve(array $filters): array
    {
        $period = $filters['period'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if (! in_array($period, self::PERIODS, true)) {
            return $this->window($from, $to);
        }

        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return match ($period) {
            self::DAILY => $this->window($today, $today),
            self::WEEKLY => $this->window($today->copy()->startOfWeek(Carbon::MONDAY), $today),
            self::MONTHLY => $this->window($today->copy()->startOfMonth(), $today),
            default => $this->window($from, $to),
        };
    }

    /**
     * Builds a UTC window from inclusive WIB dates (or Carbon days already on
     * the WIB calendar). `to` points at the start of the next WIB day.
     *
     * @return array{from:?string,to:?string}
     */
    private function window(mixed $from, mixed $to): array
    {
        $fromDate = $this->toLocalDay($from);
        $toDate = $this->toLocalDay($to);

        return [
            'from' => $fromDate?->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
            'to' => $toDate?->copy()->addDay()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    private function toLocalDay(mixed $day): ?Carbon
    {
        if ($day instanceof Carbon) {
            return $day->copy()->startOfDay();
        }

        if (! is_string($day) || $day === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $day, self::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            // The request layer validates the format; an unparsable stored value
            // must not silently widen the export.
            return null;
        }
    }
}
