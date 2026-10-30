<?php

namespace App\Services\GoogleSheets;

use RuntimeException;

/**
 * Raised when a dataset produces more rows than one manual sync may export.
 *
 * The sync always fails as a whole — the tab is never written with a silently
 * truncated subset, and the message tells the operator to narrow the period.
 */
class GoogleSheetsRowLimitException extends RuntimeException {}
