#!/usr/bin/env python3
"""Compare Laravel and Go Sheets API requests against deterministic local fakes."""
import json
import pathlib
import subprocess
import tempfile
import uuid
from urllib.parse import parse_qsl

import compare_fulfillment as f

LARAVEL_SCRIPT = r'''<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\SheetsConfig;
use App\Models\SheetsDestination;
use App\Models\User;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\GoogleSheets\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

$lock = DB::selectOne("SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0) AS acquired");
if ((int) $lock->acquired !== 1 || DB::getDatabaseName() !== 'primeclassy_testing') {
    fwrite(STDERR, "refusing to run without the isolated test database lock\n"); exit(2);
}
$userId = $destinationId = $configId = null;
$createdRoleId = null;
$fixtureSku = '__FIXTURE_SKU__';
try {
    $roleId = DB::table('roles')->where('slug', 'super_admin')->value('id');
    if (! $roleId) {
        $createdRoleId = DB::table('roles')->insertGetId(['slug' => 'super_admin', 'name' => 'Super Admin', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = $createdRoleId;
    }
    $userId = DB::table('users')->insertGetId([
        'role_id' => $roleId, 'name' => 'Sheets payload parity',
        'email' => 'sheets-payload-'.bin2hex(random_bytes(8)).'@imp010.test',
        'password' => Hash::make(bin2hex(random_bytes(20))), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('products')->insert([
        'name' => 'IMP010 Sheets payload fixture', 'slug' => $fixtureSku, 'sku' => $fixtureSku,
        'base_price' => 1234.50, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $destination = SheetsDestination::create(['spreadsheet_id' => 'sheets-sync-test-cross-runtime', 'agent_id' => null]);
    $destinationId = $destination->id;
    $config = SheetsConfig::create([
        'destination_id' => $destinationId, 'name' => 'Cross Runtime', 'tab' => 'Products',
        'dataset' => 'products', 'columns' => [['field' => 'sku', 'label' => 'SKU'], ['field' => 'price', 'label' => 'Price']],
    ]);
    $configId = $config->id;
    config(['google_sheets.enabled' => true, 'google_sheets.max_rows' => 10000]);
    $writes = 0;
    Http::fake(function ($request) use (&$writes) {
        if ($request->method() === 'GET') {
            return Http::response(['properties' => ['title' => 'Test'], 'sheets' => [['properties' => ['sheetId' => 8, 'title' => 'Products', 'gridProperties' => ['columnCount' => 2]]]]]);
        }
        $writes++;
        if ($writes === 2) return Http::response(['error' => ['errors' => [['reason' => 'PERMISSION_DENIED']]]], 403);
        return Http::response(['replies' => [[], []]]);
    });
    $client = new class extends SheetsClient { public function token(): string { return 'stub-token'; } };
    app()->instance(SheetsClient::class, $client);
    $log = app(SyncService::class)->sync(User::findOrFail($userId), $config);
    $captureRequests = function ($records) {
        $requests = [];
        foreach ($records as [$request, $response]) {
            $url = parse_url($request->url());
            $entry = ['method' => $request->method(), 'path' => $url['path'] ?? '', 'query' => $url['query'] ?? ''];
            if ($request->method() === 'POST') $entry['body'] = $request->data();
            $requests[] = $entry;
        }
        return $requests;
    };
    $firstRecords = Http::recorded()->all();
    $successRequests = $captureRequests($firstRecords);
    if ($log->status !== 'success') throw new RuntimeException('Laravel sync failed: '.$log->error_summary);
    $failedLog = app(SyncService::class)->sync(User::findOrFail($userId), $config);
    $allRequests = Http::recorded()->all();
    $failureRequests = $captureRequests(array_slice($allRequests, count($firstRecords)));
    if ($failedLog->status !== 'failed') throw new RuntimeException('Laravel provider failure was not recorded');
    $out = [
        'success' => ['requests' => $successRequests, 'log' => [$log->status, (int) $log->rows_processed, (int) $log->rows_success, (int) $log->rows_failed, '']],
        'failure' => ['requests' => $failureRequests, 'log' => [$failedLog->status, (int) $failedLog->rows_processed, (int) $failedLog->rows_success, (int) $failedLog->rows_failed, $failedLog->error_summary]],
    ];
} finally {
    if ($configId) DB::table('sheets_sync_logs')->where('config_id', $configId)->delete();
    if ($configId) DB::table('sheets_configs')->where('id', $configId)->delete();
    if ($destinationId) DB::table('sheets_destinations')->where('id', $destinationId)->delete();
    DB::table('products')->where('sku', $fixtureSku)->where('slug', $fixtureSku)->delete();
    if ($userId) DB::table('users')->where('id', $userId)->delete();
    if ($createdRoleId) DB::table('roles')->where('id', $createdRoleId)->delete();
    DB::selectOne("SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')");
}
echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''


def normalize_scenario(capture):
    normalized = []
    for request in capture["requests"]:
        item = {"method": request["method"], "path": request["path"]}
        item["query"] = sorted(parse_qsl(request.get("query", ""), keep_blank_values=True))
        if "body" in request:
            item["body"] = request["body"]
        normalized.append(item)
    return {"requests": normalized, "log": capture["log"]}


def normalize(capture):
    return {name: normalize_scenario(value) for name, value in capture.items()}


def main():
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    handle = tempfile.NamedTemporaryFile(prefix="pc-go-sheets-", suffix=".json", delete=False)
    handle.close()
    capture_path = pathlib.Path(handle.name)
    try:
        remote_capture = "/tmp/" + capture_path.name
        fixture_sku = "imp010-sheets-" + uuid.uuid4().hex
        command = "cd /src && PATH=/usr/local/go/bin:$PATH GOOGLE_SHEETS_CAPTURE_FILE=" + remote_capture + " GOOGLE_SHEETS_FIXTURE_SKU=" + fixture_sku + " go test ./internal/api -run '^TestGoogleSheetsSyncWritesAndCompletesLog$' -count=1"
        result = subprocess.run(
            ["docker", "exec", "pc_go_imp010", "sh", "-lc", command],
            capture_output=True, text=True, timeout=180,
        )
        if result.returncode:
            raise RuntimeError("Go Sheets integration failed:\n" + result.stdout[-1500:] + result.stderr[-1500:])
        copied = subprocess.run(["docker", "cp", f"pc_go_imp010:{remote_capture}", str(capture_path)], capture_output=True, text=True, timeout=30)
        if copied.returncode:
            raise RuntimeError("Go Sheets integration did not produce its capture:\n" + copied.stderr[-1000:])
        go_capture = json.loads(capture_path.read_text())
        php_script = LARAVEL_SCRIPT.replace("__FIXTURE_SKU__", fixture_sku)
        php = subprocess.run(
            ["docker", "exec", "-i", "pc_laravel_imp010", "sh", "-lc", "cd /app && APP_ENV=testing php"],
            input=php_script, capture_output=True, text=True, timeout=90,
        )
        if php.returncode:
            raise RuntimeError("Laravel Sheets capture failed:\n" + php.stderr[-2000:] + php.stdout[-1000:])
        try:
            laravel_capture = json.loads(php.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError("Laravel Sheets capture emitted non-JSON output:\n" + php.stdout[-2000:] + php.stderr[-1000:]) from exc
        if int(go_capture["success"]["log"][1]) < 1 or int(laravel_capture["success"]["log"][1]) < 1:
            raise AssertionError("provider differential did not export the tagged product fixture")
        go_norm, laravel_norm = normalize(go_capture), normalize(laravel_capture)
        if go_norm != laravel_norm:
            print("Laravel:", json.dumps(laravel_norm, ensure_ascii=False, sort_keys=True))
            print("Go:", json.dumps(go_norm, ensure_ascii=False, sort_keys=True))
            raise AssertionError("Laravel and Go Sheets API requests or sync log outcomes differ")
        print("Sheets provider payload differential: PASS (success and provider-denied metadata/batchUpdate requests and sync logs match)")
        print(json.dumps({name: data["log"] for name, data in go_norm.items()}, ensure_ascii=False))
        return 0
    finally:
        capture_path.unlink(missing_ok=True)


if __name__ == "__main__":
    raise SystemExit(main())
