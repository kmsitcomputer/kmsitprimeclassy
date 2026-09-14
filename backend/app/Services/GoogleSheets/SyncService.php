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
            $limit = config('google_sheets.max_rows');
            $records = $this->registry->query($config->dataset, $config->destination->agent_id, $config->filters ?? [])
                ->select($fields)->limit($limit + 1)->get();
            $log->update(['rows_processed' => $records->count()]);
            if ($records->count() > $limit) {
                throw new \RuntimeException('Dataset exceeds manual sync limit');
            }
            $rows = [array_column($config->columns, 'label')];
            foreach ($records as $record) {
                $rows[] = array_map(fn ($field) => $record->$field, $fields);
            }
            $this->client->replace($config->destination->spreadsheet_id, $config->tab, $rows);
            $log->update(['status' => 'success', 'rows_success' => $records->count(), 'completed_at' => now()]);
        } catch (\Throwable $e) {
            if (! $log) {
                throw $e;
            }
            $safe = ['Invalid mapping', 'Dataset exceeds manual sync limit'];
            $summary = $e instanceof GoogleSheetsException || in_array($e->getMessage(), $safe, true)
                ? $e->getMessage() : 'Sync failed';
            $log->update(['status' => 'failed', 'completed_at' => now(), 'rows_failed' => $log->rows_processed,
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
}
