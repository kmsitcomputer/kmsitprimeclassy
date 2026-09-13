<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\SheetsSyncLog;
use App\Services\GoogleSheets\DatasetRegistry;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\GoogleSheets\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $logs = SheetsSyncLog::query();
        if (! $request->user()->isRole('super_admin')) {
            $logs->where('agent_scope', $request->user()->agent_id);
        }

        return $this->ok(['destinations' => $destinations, 'configs' => $configs, 'datasets' => $registry->definitions(),
            'logs' => $logs->orderByDesc('id')->limit(100)->get(), 'enabled' => (bool) config('google_sheets.enabled'),
            'connection_status' => config('google_sheets.enabled') ? 'not_checked' : 'disabled']);
    }

    public function connection(Request $request, SheetsClient $client)
    {
        $this->authorize('viewAny', SheetsConfig::class);
        try {
            $client->token();

            return $this->ok(['status' => 'connected']);
        } catch (\Throwable) {
            return $this->ok(['status' => 'unavailable']);
        }
    }

    /** Central credential access does not grant agents the right to claim arbitrary spreadsheets. */
    public function destination(Request $request)
    {
        abort_unless($request->user()->isRole('super_admin'), 403);
        $data = $request->validate([
            'spreadsheet_id' => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:sheets_destinations,spreadsheet_id'],
            'agent_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role_id', Role::where('slug', 'agen')->value('id'))->whereNull('deleted_at'))],
        ]);

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
}
