<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\SheetsSyncLog;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\GoogleSheetsException;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\GoogleSheets\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SheetsController extends Controller
{
    public function index(Request $request, DatasetRegistry $registry)
    {
        $this->authorize('viewAny', SheetsConfig::class);
        $destinations = SheetsDestination::query();
        if (! $request->user()->isRole('super_admin')) {
            $destinations->where('agent_id', $request->user()->agent_id);
        }
        $destinations = $destinations->get();
        $configs = SheetsConfig::with('destination')->whereIn('destination_id', $destinations->pluck('id'))->get();
        $datasets = $registry->definitions();
        if ($request->user()->isRole('admin')) {
            $configs = $configs->reject(fn (SheetsConfig $config) => $config->dataset === 'financial_summary')->values();
            unset($datasets['financial_summary']);
        }
        $logs = SheetsSyncLog::query();
        if (! $request->user()->isRole('super_admin')) {
            $logs->where('agent_scope', $request->user()->agent_id);
        }

        $credential = app(SheetsClient::class)->credentialInfo();

        return $this->ok(['destinations' => $destinations, 'configs' => $configs, 'datasets' => $datasets,
            'dataset_defaults' => $registry->defaults(),
            'logs' => $logs->orderByDesc('id')->limit(100)->get([
                'id', 'config_id', 'dataset', 'tab', 'actor_id', 'actor_role', 'agent_scope',
                'started_at', 'completed_at', 'status', 'rows_processed', 'rows_success', 'rows_failed', 'error_summary',
            ]), 'enabled' => (bool) config('google_sheets.enabled'),
            'service_account_email' => $credential['client_email'],
            'connection_status' => config('google_sheets.enabled') ? 'not_checked' : 'disabled']);
    }

    public function connection(Request $request, SheetsClient $client)
    {
        $this->authorize('viewAny', SheetsConfig::class);
        $validated = $request->validate(['destination_id' => ['required', 'integer', 'exists:sheets_destinations,id']]);
        $destination = SheetsDestination::findOrFail($validated['destination_id']);
        abort_unless($request->user()->isRole('super_admin') || $destination->agent_id === $request->user()->agent_id, 403);
        $credential = $client->credentialInfo();
        $context = [
            'actor_user_id' => $request->user()->id,
            'actor_role' => $request->user()->role->slug,
            'agent_scope' => $destination->agent_id,
            'spreadsheet_id' => $this->maskSpreadsheetId($destination->spreadsheet_id),
            'service_account_email' => $credential['client_email'],
        ];
        Log::info('google_sheets.connection_test.started', $context);

        try {
            $metadata = $client->metadata($destination->spreadsheet_id);
            $testedAt = now();
            $destination->update([
                'spreadsheet_title' => $metadata['title'], 'connection_status' => 'connected',
                'last_tested_at' => $testedAt, 'last_error_code' => null,
            ]);
            Log::info('google_sheets.connection_test.succeeded', $context);

            return $this->ok([
                'status' => 'connected', 'spreadsheet_title' => $metadata['title'],
                'spreadsheet_id' => $destination->spreadsheet_id,
                'service_account_email' => $credential['client_email'],
                'last_tested_at' => $testedAt,
            ]);
        } catch (GoogleSheetsException $e) {
            $destination->update([
                'connection_status' => 'failed', 'last_tested_at' => now(),
                'last_error_code' => $e->errorCode,
            ]);
            Log::warning('google_sheets.connection_test.failed', $context + [
                'http_status' => $e->httpStatus,
                'reason' => $e->googleReason,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return $this->fail($e->getMessage(), [
                'code' => [$e->errorCode],
                'credential' => [[
                    'resolved_path' => $credential['resolved_path'],
                    'file_found' => $credential['file_found'],
                    'readable' => $credential['readable'],
                    'json_valid' => $credential['json_valid'],
                    'type' => $credential['type'],
                    'project_id' => $credential['project_id'],
                    'client_email' => $credential['client_email'],
                    'private_key_present' => $credential['private_key_present'],
                ]],
            ], 422);
        }
    }

    /** A non-super-admin can register only a spreadsheet for their authenticated Agent scope. */
    public function destination(Request $request)
    {
        $normalized = $this->normalizeSpreadsheetId((string) $request->input('spreadsheet_id'));
        $request->merge(['spreadsheet_id' => $normalized]);
        $data = $request->validate([
            'spreadsheet_id' => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:sheets_destinations,spreadsheet_id'],
            'agent_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role_id', Role::where('slug', 'agen')->value('id'))->whereNull('deleted_at'))],
        ]);
        if (! $request->user()->isRole('super_admin')) {
            $data['agent_id'] = $request->user()->agent_id;
        }

        return $this->created(SheetsDestination::create($data));
    }

    public function store(Request $request, DatasetRegistry $registry)
    {
        $this->authorize('viewAny', SheetsConfig::class);
        $data = $this->validated($request, $registry);

        return $this->created(SheetsConfig::create($data)->load('destination'));
    }

    public function update(Request $request, SheetsConfig $config, DatasetRegistry $registry)
    {
        $this->authorize('manage', $config);
        $data = $this->validated($request, $registry, $config);

        return $this->mutate($config, function () use ($config, $data) {
            $config->update($data);

            return $this->ok($config->fresh('destination'));
        });
    }

    public function destroy(Request $request, SheetsConfig $config)
    {
        $this->authorize('manage', $config);

        return $this->mutate($config, function () use ($config) {
            $config->delete();

            return $this->ok(null);
        });
    }

    public function sync(Request $request, SheetsConfig $config, SyncService $sync)
    {
        $this->authorize('manage', $config);

        return $this->ok($sync->sync($request->user(), $config));
    }

    private function mutate(SheetsConfig $config, callable $callback)
    {
        $lock = Cache::lock('sheets-sync-'.$config->id, 180);
        abort_unless($lock->get(), 409, 'Sinkronisasi sedang berjalan.');
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function validated(Request $request, DatasetRegistry $registry, ?SheetsConfig $config = null): array
    {
        $data = $request->validate([
            'agent_id' => ['prohibited'],
            'destination_id' => ['required', 'integer', 'exists:sheets_destinations,id'],
            'name' => ['required', 'string', 'max:150'],
            'tab' => ['required', 'string', 'max:100', 'regex:/^[^\[\]:*?\\\\\/]+$/', Rule::unique('sheets_configs')->where('destination_id', $request->integer('destination_id'))->ignore($config)],
            'dataset' => ['required', Rule::in(array_keys($registry->definitions()))],
            'columns' => ['required', 'array', 'min:1', 'max:30'],
            'columns.*' => ['array:field,label'],
            'columns.*.field' => ['required', 'string', 'distinct', Rule::in($registry->definitions()[is_string($request->input('dataset')) ? $request->input('dataset') : ''] ?? [])],
            'columns.*.label' => ['required', 'string', 'max:100'],
            'filters' => ['nullable', 'array:status'],
            'filters.status' => ['nullable', 'string', 'max:40'],
        ]);
        $destination = SheetsDestination::findOrFail($data['destination_id']);
        abort_unless($request->user()->isRole('super_admin') || $destination->agent_id === $request->user()->agent_id, 403);

        return $data;
    }

    private function normalizeSpreadsheetId(string $value): string
    {
        $value = trim($value);
        if (preg_match('~docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)~', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }

    private function maskSpreadsheetId(string $id): string
    {
        return strlen($id) <= 8 ? '***' : substr($id, 0, 4).'…'.substr($id, -4);
    }
}
