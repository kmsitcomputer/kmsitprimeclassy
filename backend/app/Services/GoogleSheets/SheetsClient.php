<?php

namespace App\Services\GoogleSheets;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SheetsClient
{
    public function token(): string
    {
        if (! config('google_sheets.enabled')) {
            throw new RuntimeException('Integration disabled');
        }
        $path = config('google_sheets.credentials_path');
        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('Invalid credentials');
        }
        try {
            $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($json['type'] ?? null) !== 'service_account') {
                throw new RuntimeException;
            }
            $credentials = new ServiceAccountCredentials('https://www.googleapis.com/auth/spreadsheets', $json);
            $http = new Client(['timeout' => config('google_sheets.timeout'), 'connect_timeout' => 10]);
            $token = $credentials->fetchAuthToken(HttpHandlerFactory::build($http));
            if (empty($token['access_token'])) {
                throw new RuntimeException;
            }

            return $token['access_token'];
        } catch (\Throwable) {
            throw new RuntimeException('Invalid credentials');
        }
    }

    /** Replace only the configured dedicated tab, atomically, using literal cell values. */
    public function replace(string $spreadsheet, string $tab, array $rows): void
    {
        $token = $this->token();
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheet);
        try {
            $response = Http::withToken($token)->timeout(config('google_sheets.timeout'))->get($base, ['fields' => 'sheets.properties']);
            $this->check($response);
            $sheet = collect($response->json('sheets'))->first(fn ($s) => ($s['properties']['title'] ?? null) === $tab);
            if (! $sheet) {
                throw new RuntimeException('Sheet not found');
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
                        'range' => ['sheetId' => $sheet['properties']['sheetId']],
                        'cell' => ['userEnteredValue' => null],
                        'fields' => 'userEnteredValue',
                    ],
                ],
                [
                    'updateCells' => [
                        'range' => ['sheetId' => $sheet['properties']['sheetId'], 'startRowIndex' => 0, 'startColumnIndex' => 0],
                        'rows' => $data,
                        'fields' => 'userEnteredValue',
                    ],
                ],
            ]]);
            $this->check($response);
        } catch (ConnectionException) {
            throw new RuntimeException('Timeout or network error');
        }
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        throw new RuntimeException(match ($response->status()) {
            401 => 'Invalid credentials', 403 => 'Permission denied', 404 => 'Spreadsheet not found',
            429 => 'API quota', 400 => 'Invalid spreadsheet or mapping', default => 'Google API unavailable',
        });
    }
}
