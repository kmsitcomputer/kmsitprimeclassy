<?php

namespace App\Services\GoogleSheets;

use App\Models\SheetsConfig;
use App\Models\SheetsSyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SyncService
{
    public function __construct(private DatasetRegistry $registry, private SheetsClient $client) {}

    public function sync(User $actor, SheetsConfig $config): SheetsSyncLog
    {
        Gate::forUser($actor)->authorize('manage', $config);
        $lock = Cache::lock('sheets-sync-'.$config->id, 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['sync' => 'Sinkronisasi sedang berjalan.']);
        }
        $log = null;
        try {
            $config->load('destination');
            $log = SheetsSyncLog::create([
                'config_id' => $config->id, 'dataset' => $config->dataset,
                'spreadsheet_id' => $this->maskSpreadsheetId($config->destination->spreadsheet_id), 'tab' => $config->tab,
                'actor_id' => $actor->id, 'actor_role' => $actor->role->slug,
                'agent_scope' => $config->destination->agent_id, 'started_at' => now(), 'status' => 'running',
            ]);
            $fields = array_column($config->columns, 'field');
            if (! $fields || array_diff($fields, $this->registry->definitions()[$config->dataset] ?? [])) {
                throw new \RuntimeException('Invalid mapping');
            }
            $limit = (int) config('google_sheets.max_rows');
            $records = $this->registry->query($config->dataset, $config->destination->agent_id, $config->filters ?? [])
                ->select($fields)->limit($limit + 1)->get();
            $log->update(['rows_processed' => $records->count()]);
            if ($records->count() > $limit) {
                // No silent truncation: the whole sync fails and the tab keeps its
                // previous content until the operator narrows the period.
                throw new GoogleSheetsRowLimitException(sprintf(
                    'Dataset melebihi batas sinkronisasi (%d baris). Persempit periode filter lalu sinkronkan ulang.',
                    $limit,
                ));
            }
            $rows = [array_column($config->columns, 'label')];
            foreach ($records as $record) {
                $rows[] = array_map(
                    fn ($field) => $this->sheetValue($config->dataset, $field, $record->$field),
                    $fields,
                );
            }
            // Staff-owned columns (Laporan C geometry) keep whatever the sheet
            // already holds: the merge path rewrites system columns in place
            // (row-anchored by the 'No' identity column) and NEVER touches the
            // protected data cells — DP-era corrections aside, staff notes,
            // update-status and cash-position entries survive every sync.
            $manual = array_flip($this->registry->manualColumns($config->dataset));
            $protectedIndexes = [];
            foreach ($fields as $i => $field) {
                if (isset($manual[$field])) {
                    $protectedIndexes[] = $i;
                }
            }
            $rowKeys = [];
            if ($protectedIndexes) {
                $idIdx = array_search('no', $fields, true);
                if ($idIdx === false) {
                    throw new \RuntimeException('Invalid mapping'); // identity column required for protected datasets
                }
                foreach ($records as $record) {
                    $rowKeys[] = (string) $record->{$fields[$idIdx]};
                }
            }
            $validationColumns = [];
            $rules = $this->registry->validationRules($config->dataset);
            foreach ($fields as $i => $field) {
                if (isset($rules[$field])) {
                    $validationColumns[$i] = $rules[$field];
                }
            }
            $this->client->replace($config->destination->spreadsheet_id, $config->tab, $rows, $protectedIndexes, $rowKeys, $validationColumns);
            $log->update(['status' => 'success', 'rows_success' => $records->count(), 'completed_at' => now()]);
        } catch (\Throwable $e) {
            if (! $log) {
                throw $e;
            }
            $safe = ['Invalid mapping'];
            $summary = $e instanceof GoogleSheetsException
                || $e instanceof GoogleSheetsRowLimitException
                || $e instanceof ValidationException
                || in_array($e->getMessage(), $safe, true)
                ? $e->getMessage() : 'Sync failed';
            // A failure BEFORE the first count (config mapping, unknown dataset —
            // e.g. WP-04 retired Laporan A/B) has no rows_processed yet; never
            // crash the failure bookkeeping itself with a null write.
            $log->update(['status' => 'failed', 'completed_at' => now(), 'rows_failed' => (int) $log->rows_processed,
                'error_summary' => $summary]);
        } finally {
            $lock->release();
        }

        return $log->fresh();
    }

    private function maskSpreadsheetId(string $id): string
    {
        return strlen($id) <= 8 ? '***' : substr($id, 0, 4).'…'.substr($id, -4);
    }

    private function sheetValue(string $dataset, string $field, mixed $value): mixed
    {
        if (in_array($dataset, ['transactions', 'transaction_items'], true)
            && in_array($field, ['unit_price', 'quantity', 'subtotal'], true)) {
            return $field === 'quantity' ? (int) $value : (float) $value;
        }

        if (in_array($field, $this->registry->numericFields($dataset), true)) {
            // Money and counters must land in the sheet as numbers so the sheet
            // owner can SUM them. An empty cell stays empty (NULL), never 0.
            if ($value === null || $value === '') {
                return null;
            }

            return in_array($field, ['no', 'quantity', 'agn'], true) ? (int) $value : round((float) $value, 2);
        }

        return $value;
    }
}
