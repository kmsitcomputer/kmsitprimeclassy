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

    /** Replace only the configured dedicated tab, atomically, using literal cell values. */
    public function replace(string $spreadsheet, string $tab, array $rows): void
    {
        $token = $this->token();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet);
        try {
            $metadata = $this->metadata($spreadsheet, $token);
            $sheet = collect($metadata['sheets'])->firstWhere('title', $tab);
            if (! $sheet) {
                $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->post($base.':batchUpdate', [
                    'requests' => [['addSheet' => ['properties' => ['title' => $tab]]]],
                ]);
                $this->check($response);
                $sheet = [
                    'id' => $response->json('replies.0.addSheet.properties.sheetId'),
                    'title' => $tab,
                ];
            }
            $data = array_map(fn ($row) => ['values' => array_map(fn ($value) => [
                'userEnteredValue' => is_int($value) || is_float($value)
                    ? ['numberValue' => $value] : ['stringValue' => (string) ($value ?? '')],
            ], $row)], $rows);
            // Both operations are one Sheets batch: clear stale cells, then write
            // the current export. A dedicated tab never retains rows from an
            // older, larger dataset after a successful sync.
            $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->post($base.':batchUpdate', ['requests' => [
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
            ]]);
            $this->check($response);
        } catch (ConnectionException) {
            throw new GoogleSheetsException('NETWORK_ERROR', 'Tidak dapat terhubung ke Google Sheets API.');
        }
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
            $status === 400 => ['INVALID_SPREADSHEET_ID', 'Spreadsheet ID tidak valid.'],
            default => ['UNKNOWN_GOOGLE_API_ERROR', 'Google Sheets API mengembalikan kegagalan yang tidak dikenal.'],
        };

        throw new GoogleSheetsException($code, $message, $status, $reason ?: null);
    }
}
