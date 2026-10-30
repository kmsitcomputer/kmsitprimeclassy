<?php

namespace App\Services\GoogleSheets;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class SheetsClient
{
    public function metadata(string $spreadsheet, ?string $token = null): array
    {
        $token ??= $this->token();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet);
        try {
            $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))
                ->get($base, ['fields' => 'properties.title,sheets.properties']);
            $this->check($response);

            return [
                'title' => (string) $response->json('properties.title'),
                'sheets' => collect($response->json('sheets', []))->map(fn ($sheet) => [
                    'id' => $sheet['properties']['sheetId'],
                    'title' => $sheet['properties']['title'],
                    'column_count' => (int) ($sheet['properties']['gridProperties']['columnCount'] ?? 26),
                ])->values()->all(),
            ];
        } catch (ConnectionException) {
            throw new GoogleSheetsException('NETWORK_ERROR', 'Tidak dapat terhubung ke Google Sheets API.');
        }
    }

    /** Return only non-secret credential diagnostics suitable for the dashboard. */
    public function credentialInfo(): array
    {
        $path = config('google_sheets.credentials_path');
        $info = [
            'resolved_path' => is_string($path) ? $path : null,
            'file_found' => is_string($path) && is_file($path),
            'readable' => is_string($path) && is_readable($path),
            'json_valid' => false,
            'type' => null,
            'project_id' => null,
            'client_email' => null,
            'private_key_present' => false,
        ];
        if (! $info['readable']) {
            return $info;
        }
        try {
            $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $info['json_valid'] = is_array($json);
            $info['type'] = $json['type'] ?? null;
            $info['project_id'] = $json['project_id'] ?? null;
            $info['client_email'] = $json['client_email'] ?? null;
            $info['private_key_present'] = isset($json['private_key']) && trim((string) $json['private_key']) !== '';
        } catch (JsonException) {
            // The dashboard needs only the validity flag, never parser details or JSON contents.
        }

        return $info;
    }

    public function token(): string
    {
        if (! config('google_sheets.enabled')) {
            throw new GoogleSheetsException('INTEGRATION_DISABLED', 'Integrasi Google Sheets belum diaktifkan.');
        }
        $path = config('google_sheets.credentials_path');
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            throw new GoogleSheetsException('CREDENTIAL_NOT_FOUND', 'File credential Google tidak ditemukan atau tidak dapat dibaca.');
        }
        try {
            $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($json['type'] ?? null) !== 'service_account'
                || empty($json['client_email']) || empty($json['private_key']) || empty($json['project_id'])) {
                throw new GoogleSheetsException('INVALID_CREDENTIAL', 'Credential Service Account tidak valid.');
            }
            $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/spreadsheets', $json);
            $http = new Client(['timeout' => config('google_sheets.timeout'), 'connect_timeout' => 10]);
            $token = $credentials->fetchAuthToken(HttpHandlerFactory::build($http));
            if (empty($token['access_token'])) {
                throw new GoogleSheetsException('AUTHENTICATION_ERROR', 'Google menolak autentikasi Service Account.');
            }

            return $token['access_token'];
        } catch (GoogleSheetsException $e) {
            throw $e;
        } catch (JsonException) {
            throw new GoogleSheetsException('INVALID_CREDENTIAL', 'Credential Service Account bukan JSON yang valid.');
        } catch (Throwable) {
            throw new GoogleSheetsException('AUTHENTICATION_ERROR', 'Google menolak autentikasi Service Account.');
        }
    }

    /**
     * Replace only the configured dedicated tab, atomically, using literal cell values.
     *
     * $protectedColumnIndexes (0-based, positions within the written rows) marks
     * staff-owned columns: their DATA cells are never cleared and never written —
     * only their header cell (row 1) receives the configured label, and then only
     * when the column already exists in the destination. This is what keeps
     * DP/cicilan/pelunasan/keterangan notes safe across syncs (SV2 adaptation §6).
     */
    public function replace(string $spreadsheet, string $tab, array $rows, array $protectedColumnIndexes = [], array $rowKeys = [], array $validationColumns = []): void
    {
        $token = $this->token();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet);
        try {
            $totalCols = max(count($rows[0] ?? []), 1);
            $metadata = $this->metadata($spreadsheet, $token);
            $sheet = collect($metadata['sheets'])->firstWhere('title', $tab);
            if (! $sheet) {
                $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->post($base.':batchUpdate', [
                    'requests' => [['addSheet' => ['properties' => [
                        'title' => $tab,
                        'gridProperties' => ['columnCount' => $totalCols],
                    ]]]],
                ]);
                $this->check($response);
                $sheet = [
                    'id' => $response->json('replies.0.addSheet.properties.sheetId'),
                    'title' => $tab,
                    'column_count' => $totalCols,
                ];
            }
            $capacityRequests = [];
            $columnCount = (int) ($sheet['column_count'] ?? 26);
            if ($columnCount < $totalCols) {
                $capacityRequests[] = ['appendDimension' => [
                    'sheetId' => $sheet['id'],
                    'dimension' => 'COLUMNS',
                    'length' => $totalCols - $columnCount,
                ]];
            }
            $protected = array_values(array_unique(array_map('intval', $protectedColumnIndexes)));
            if (! $protected) {
                $data = $this->encodeRows($rows);
                // Both operations are one Sheets batch: clear stale cells, then write
                // the current export. A dedicated tab never retains rows from an
                // older, larger dataset after a successful sync.
                $requests = array_merge($capacityRequests, [
                    [
                        'repeatCell' => [
                            'range' => ['sheetId' => $sheet['id']],
                            'cell' => ['userEnteredValue' => null],
                            'fields' => 'userEnteredValue',
                        ],
                    ],
                    [
                        'updateCells' => [
                            'range' => ['sheetId' => $sheet['id'], 'startRowIndex' => 0, 'startColumnIndex' => 0],
                            'rows' => $data,
                            'fields' => 'userEnteredValue',
                        ],
                    ],
                ]);
                $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->post($base.':batchUpdate', ['requests' => $requests]);
                $this->check($response);

                return;
            }

            // ---- Row-anchored merge protection (Owner directive 2026-10-29 §10.2) ----
            // Identity = rowKeys[i] (the worksheet 'No' column: order_items.id).
            // Guarantees: staff columns are NEVER cleared and NEVER rewritten for
            // existing rows; rows are NEVER reordered (existing rows update in place,
            // unseen rows append); unmatched old rows keep every cell verbatim;
            // repeated syncs are idempotent; new rows are written complete (their
            // manual cells start empty because they are new).
            $keys = array_values(array_map(fn ($k) => trim((string) $k), $rowKeys ?? []));
            $dataRows = array_slice($rows, 1);
            if (count($keys) !== count($dataRows)) {
                throw new GoogleSheetsException('PROTECT_IDENTITY', 'Identitas baris tidak cocok dengan payload — sinkronisasi aman dibatalkan.');
            }
            $header = $rows[0] ?? [];

            $existing = $this->values($spreadsheet, sprintf('%s!A1:%s', $this->quoteTab($tab), $this->columnLetter($totalCols)));
            $oldRows = array_slice($existing, 1);
            $oldIndexOf = [];
            foreach ($oldRows as $i => $oldRow) {
                $k = trim((string) ($oldRow[0] ?? ''));
                if ($k !== '' && ! isset($oldIndexOf[$k])) {
                    $oldIndexOf[$k] = $i;
                }
            }

            // New data aligned onto the OLD row order (stability), or appended.
            $aligned = [];
            $consumed = [];
            foreach ($oldRows as $i => $oldRow) {
                $k = trim((string) ($oldRow[0] ?? ''));
                $newIdx = $k !== '' && isset($oldIndexOf[$k]) ? $this->newRowIndexOf($keys, $k) : null;
                if ($newIdx !== null) {
                    $aligned[$i] = $dataRows[$newIdx];
                    $consumed[$newIdx] = true;
                } else {
                    // Unmatched old row: keep its system cells verbatim (rewriting
                    // identical values) — nothing is ever lost or shifted.
                    $aligned[$i] = array_pad(array_slice($oldRow, 0, $totalCols), $totalCols, '');
                }
            }
            $appended = [];
            foreach ($dataRows as $j => $row) {
                if (! isset($consumed[$j])) {
                    $appended[] = $row;
                }
            }

            $protectedSet = array_flip($protected);
            $requests = $capacityRequests;
            // 1) Header (all columns — labels are ours to own; the row-1 header
            //    text for manual columns is stable and contains no data).
            $requests[] = ['updateCells' => [
                'range' => ['sheetId' => $sheet['id'], 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $totalCols],
                'rows' => $this->encodeRows([$header]),
                'fields' => 'userEnteredValue',
            ]];
            // 2) System column runs over the existing block (protected runs excluded
            //    entirely: their data cells are never part of any request here).
            foreach ($this->systemRuns($totalCols, $protectedSet) as [$from, $to]) {
                if ($aligned) {
                    $slice = array_map(fn ($row) => array_slice($row, $from, $to - $from), array_values($aligned));
                    $requests[] = ['updateCells' => [
                        'range' => ['sheetId' => $sheet['id'], 'startRowIndex' => 1, 'endRowIndex' => 1 + count($slice), 'startColumnIndex' => $from, 'endColumnIndex' => $to],
                        'rows' => $this->encodeRows($slice),
                        'fields' => 'userEnteredValue',
                    ]];
                }
                if ($appended) {
                    $slice = array_map(fn ($row) => array_slice($row, $from, $to - $from), $appended);
                    $requests[] = ['updateCells' => [
                        'range' => ['sheetId' => $sheet['id'], 'startRowIndex' => 1 + count($aligned), 'startColumnIndex' => $from, 'endColumnIndex' => $to],
                        'rows' => $this->encodeRows($slice),
                        'fields' => 'userEnteredValue',
                    ]];
                }
            }
            // Real Google-Sheets dropdowns for manual columns (e.g. money
            // custody): strict ONE_OF_LIST from row 2 down, custom UI shown.
            foreach ($validationColumns as $col => $allowed) {
                $requests[] = ['setDataValidation' => [
                    'range' => ['sheetId' => $sheet['id'], 'startRowIndex' => 1, 'startColumnIndex' => (int) $col, 'endColumnIndex' => ((int) $col) + 1],
                    'rule' => [
                        'condition' => [
                            'type' => 'ONE_OF_LIST',
                            'values' => array_map(fn ($v) => ['userEnteredValue' => (string) $v], $allowed),
                        ],
                        'strict' => true,
                        'showCustomUi' => true,
                    ],
                ]];
            }
            $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->post($base.':batchUpdate', ['requests' => $requests]);
            $this->check($response);
        } catch (ConnectionException) {
            throw new GoogleSheetsException('NETWORK_ERROR', 'Tidak dapat terhubung ke Google Sheets API.');
        }
    }

    /** Read a values range (empty sheet => empty array). */
    public function values(string $spreadsheet, string $range): array
    {
        $token = $this->token();
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet).'/values/'.rawurlencode($range);
        try {
            $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->get($url);
            $this->check($response);

            return $response->json('values') ?: [];
        } catch (ConnectionException) {
            throw new GoogleSheetsException('NETWORK_ERROR', 'Tidak dapat terhubung ke Google Sheets API.');
        }
    }

    private function newRowIndexOf(array $keys, string $key): ?int
    {
        $i = array_search($key, $keys, true);

        return $i === false ? null : $i;
    }

    /** Contiguous non-protected column runs within [0, $totalCols). */
    private function systemRuns(int $totalCols, array $protectedSet): array
    {
        $runs = [];
        $start = null;
        for ($col = 0; $col < $totalCols; $col++) {
            if (isset($protectedSet[$col])) {
                if ($start !== null) {
                    $runs[] = [$start, $col];
                    $start = null;
                }
            } elseif ($start === null) {
                $start = $col;
            }
        }
        if ($start !== null) {
            $runs[] = [$start, $totalCols];
        }

        return $runs;
    }

    private function quoteTab(string $tab): string
    {
        return str_contains($tab, ' ') ? "'".$tab."'" : $tab;
    }

    /** 1-based A1 column letters (47 => AU). */
    private function columnLetter(int $count): string
    {
        $letter = '';
        while ($count > 0) {
            $mod = ($count - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $count = intdiv($count - 1, 26);
        }

        return $letter;
    }

    private function encodeRows(array $rows): array
    {
        return array_map(fn ($row) => ['values' => array_map(fn ($value) => [
            'userEnteredValue' => is_int($value) || is_float($value)
                ? ['numberValue' => $value] : ['stringValue' => (string) ($value ?? '')],
        ], $row)], $rows);
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        $reason = (string) ($response->json('error.details.0.reason')
            ?? $response->json('error.errors.0.reason')
            ?? $response->json('error.status')
            ?? '');
        $apiDisabled = $status === 403 && in_array(strtoupper($reason), [
            'SERVICE_DISABLED', 'ACCESS_NOT_CONFIGURED', 'API_DISABLED',
        ], true);
        [$code, $message] = match (true) {
            $status === 401 => ['AUTHENTICATION_ERROR', 'Google menolak autentikasi Service Account.'],
            $apiDisabled => ['API_DISABLED', 'Google Sheets API belum aktif pada Google Cloud Project.'],
            $status === 403 => ['PERMISSION_DENIED', 'Spreadsheet belum dibagikan ke Service Account atau aksesnya ditolak.'],
            $status === 404 => ['SPREADSHEET_NOT_FOUND', 'Spreadsheet tidak ditemukan atau tidak dapat diakses oleh Service Account.'],
            $status === 429 => ['RATE_LIMIT', 'Batas permintaan Google Sheets API tercapai.'],
            $status === 400 => ['INVALID_REQUEST', 'Google Sheets menolak format atau jangkauan permintaan sinkronisasi.'],
            default => ['UNKNOWN_GOOGLE_API_ERROR', 'Google Sheets API mengembalikan kegagalan yang tidak dikenal.'],
        };

        throw new GoogleSheetsException($code, $message, $status, $reason ?: null);
    }
}
