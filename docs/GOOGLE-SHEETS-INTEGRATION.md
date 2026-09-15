# Google Sheets Integration

## Current Runtime Audit — 14 September 2026

The active Laravel configuration was checked without printing credential contents:

| Check | Result |
|---|---|
| Integration flag | Enabled locally after audit; it was previously absent from `.env` and resolved to `false` |
| Resolved credential path | `/Applications/ServBay/www/primeclassy/backend/storage/app/private/google/service-account.json` |
| Credential file | Found and readable |
| JSON/type | Valid / `service_account` |
| Google project | `diksi-507423` |
| Service Account | `primeclassy-sheets@diksi-507423.iam.gserviceaccount.com` |
| Private key field | Present; value was never printed or logged |
| Laravel config cache | No stale config cache was present |
| Stored destination | `1X2r…VsHk` (masked) |
| Live `spreadsheets.get` | `HTTP 403 PERMISSION_DENIED` |

Authentication reached Google successfully. The remaining connection blocker is access to the stored spreadsheet. Open that exact spreadsheet, share it with `primeclassy-sheets@diksi-507423.iam.gserviceaccount.com`, select **Editor**, and then use **Periksa koneksi** again. If it is already listed, verify that the stored Spreadsheet ID belongs to the same file and remove/re-add the Service Account share.

Runtime status is currently **INCOMPLETE** until that Google-side permission is granted. The application implementation and credential loading are operational.

## 1. Overview

Prime Classy exports approved MySQL datasets to Google Sheets. MySQL remains the source of truth. Editing a spreadsheet never changes application data, and a failed sync never rolls back orders, payments, shipments, or financial postings.

## 2. Architecture

```text
MySQL → Dataset Registry → Role/Agent Scope → Column Mapping
      → Laravel Sync Service → Google Sheets API v4 → Spreadsheet Tab
```

A central Google service account authenticates server-to-server. Spreadsheet IDs, dataset configuration, and sanitized sync logs are stored in MySQL. The credential JSON is stored only in a private server path.

## 3. Requirements

- A Google Cloud project with Google Sheets API enabled.
- A service account JSON key stored outside the public web root.
- A Google Spreadsheet shared with the service account as Editor.
- PHP dependencies installed through Composer, including `google/auth`.

## 4. Create Google Cloud Project

Open Google Cloud Console, create or select a project, and confirm that billing and organization policies permit Google Workspace API access.

## 5. Enable Google Sheets API

Open **APIs & Services → Library**, search for **Google Sheets API**, and select **Enable**. Drive API is not required by this integration because the application works with an existing Spreadsheet ID.

## 6. Create Service Account

Open **IAM & Admin → Service Accounts**, choose **Create Service Account**, enter a clear name, and finish creation. Domain-wide delegation is not required for spreadsheets explicitly shared with the service account.

## 7. Create JSON Credential

Open the service account, select **Keys → Add Key → Create new key → JSON**, and download the generated file. Google does not retain another downloadable copy of the private key.

## 8. Store Credential on Server

Recommended application path:

```text
backend/storage/app/private/google/service-account.json
```

An absolute path outside the repository is also supported. Never place the file below `public/`. On Linux, restrict the file to the deployment user or the web/PHP worker group; do not make it world-readable.

## 9. Environment Configuration

```env
GOOGLE_SHEETS_ENABLED=true
GOOGLE_SHEETS_CREDENTIALS_PATH=/absolute/private/path/service-account.json
```

After changing production environment values, rebuild Laravel's configuration cache. The credential contents, private key, and access tokens do not belong in `.env`, MySQL, logs, or frontend code.

## 10. Create Google Spreadsheet

Create one spreadsheet for global reporting or for an Agent. A single spreadsheet can contain several dataset tabs. The sync service creates a configured tab if it does not exist and replaces that tab's values on every successful sync.

## 11. Get Spreadsheet ID

For this URL:

```text
https://docs.google.com/spreadsheets/d/1ABCxyz123/edit
```

the Spreadsheet ID is `1ABCxyz123`. The dashboard accepts either the ID or the full URL and stores the canonical ID.

## 12. Share Spreadsheet With Service Account

Open the downloaded JSON locally and copy only its `client_email` value. In Google Sheets select **Share**, add that service-account email, choose **Editor**, and save. Do not paste the private key anywhere. Missing sharing normally results in `403 Permission denied`.

## 13. Configure Prime Classy Dashboard

Open **Dashboard → Google Sheets Sync**. Register a destination spreadsheet, select its scope, then create one or more dataset configurations. Super Admin may select global or Agent scope. Agent and Admin are automatically restricted to their authenticated Agent network.

## 14. Test Connection

Select the registered spreadsheet and click **Periksa koneksi**. Laravel loads the service account, requests an OAuth access token, calls `spreadsheets.get`, and returns the actual spreadsheet title. A token-only result is not considered a successful connection.

The result is persisted per destination as `connection_status`, `last_tested_at`, `last_error_code`, and `spreadsheet_title`. The dashboard displays the Service Account email so the spreadsheet owner can share with the exact principal used at runtime. The connection test is read-only and never writes spreadsheet cells.

## 15. Dataset Configuration

Each configuration contains a display name, destination, dataset key, tab name, optional whitelisted filter, and at least one selected column. Dataset keys come exclusively from the backend registry; raw table names and SQL are rejected.

## 16. Column Mapping

Select columns from the displayed whitelist. The backend validates every canonical key again when saving and syncing. Unlisted model or database fields cannot be exported.

## 17. Custom Header

Edit the display title beside each selected column. A custom header changes only the first spreadsheet row; it never changes the database projection or query identifier.

## 18. Sheet Tab Configuration

Enter a unique tab name per spreadsheet. Characters rejected by Google Sheets (`[ ] : * ? \ /`) are rejected by Laravel. If the valid tab does not exist, the sync service creates it.

## 19. Sync Now

Click **Sync now** on a saved configuration. Manual sync reads every matching record up to the configured 10,000-row safety limit, clears the dedicated tab, and writes the current header and rows. It does not append duplicates. Numeric money values remain numeric. Text values use `stringValue`, so customer input beginning with `=`, `+`, `-`, or `@` is stored as literal text rather than a formula.

## 20. Super Admin Scope

Super Admin can register global or Agent destinations, configure any allowed dataset, test connections, run sync, and inspect all sanitized sync logs.

## 21. Agent Scope

Agent can register and manage spreadsheets only for its own Agent ID. The backend derives that ID from the authenticated user and overwrites any submitted Agent scope. Agent can sync and read logs only for its network.

## 22. Available Datasets

| Dataset | Scope | Columns |
|---|---|---|
| `products` | Global/Agent | id, sku, product_name, price, status |
| `stock` | Global/Agent | id, sku, product_name, quantity, reserved_quantity |
| `transactions` | Global/Agent | order_no, order_date, sku, product, unit_price, quantity, item_status, subtotal, customer, delivery_date, courier, order_status, sales, korsal |
| `transaction_items` | Global/Agent | Sama dengan `transactions`; alias kompatibilitas untuk dataset item-level kanonis |
| `transaction_report` | Global/Agent | total_orders, paid_orders, pending_orders, cancelled_orders, gross_revenue |
| `financial_summary` | Global/Agent | transaction_count, gross_revenue, paid_amount, outstanding_amount, refund_amount, additional_payment_amount, agent_fee, sales_fee, courier_fee |
| `payment_status` | Global/Agent | id, order_no, payment_status, paid_amount, remaining_amount |
| `refunds` | Global/Agent | id, order_no, refund_amount, refund_status |
| `additional_payments` | Global/Agent | id, order_no, amount, method, status |
| `sales_fees` | Global/Agent | id, order_no, beneficiary_id, amount, status, earned_at |
| `korsal_fees` | Global/Agent | korsal_id, korsal_name, amount |
| `courier_fees` | Global/Agent | id, order_no, beneficiary_id, amount, status, earned_at |
| `courier_deliveries` | Global/Agent | id, order_no, courier_id, tracking_number, status, delivered_at |
| `sales` | Global/Agent | id, name, korsal_id, status |
| `korsal` | Global/Agent | id, name, status |

Untuk kedua dataset transaksi, mapping default menggunakan header **Order No, Tanggal, SKU, Produk, Harga, Qty, Status Item, Subtotal, Konsumen, Tgl Kirim, Kurir, Status Order, Sales, Korsal**. Memilih dataset tersebut pada form baru langsung mengisi mapping ini. Tombol **Reset ke Default** mengembalikan mapping dan urutan baku; konfigurasi tetap dapat menghapus kolom, mengubah label, atau menyusun ulang kolom setelahnya.

## 23. Financial Data Security

Every Agent query is filtered in MySQL by authenticated Agent scope. Admin retains existing operational Sheets access but cannot list, configure, or sync `financial_summary`, because it contains Agent commission. Other financial datasets follow the same visibility rules as the existing dashboard. Keuangan, Korsal, Sales, Kurir, and Konsumen have no Google Sheets configuration access.

## 24. Sync Log

Every attempt records dataset, tab, actor, role, Agent scope, start/completion time, status, row counts, and a sanitized error. Spreadsheet IDs are masked. Credential JSON, private keys, tokens, request authorization headers, and raw Google exceptions are never stored.

## 25. Troubleshooting

| Problem | Resolution |
|---|---|
| `AUTHENTICATION_ERROR` | Check credential validity, Service Account state, and server clock. |
| `PERMISSION_DENIED` | Share the exact stored spreadsheet with the displayed Service Account email as Editor. |
| `API_DISABLED` | Enable Google Sheets API in the displayed credential project. |
| `SPREADSHEET_NOT_FOUND` | Verify the stored ID, file existence, and Service Account access. |
| `INVALID_SPREADSHEET_ID` | Store only the canonical ID or paste a standard Google Sheets URL. |
| `CREDENTIAL_NOT_FOUND` | Correct the resolved private path and PHP filesystem permission. |
| `INVALID_CREDENTIAL` | Replace malformed/non-Service Account JSON with a valid private key file. |
| `RATE_LIMIT` | Wait and retry after the Google quota window recovers. |
| `NETWORK_ERROR` | Verify DNS, TLS, outbound HTTPS, proxy, and timeout settings. |
| `UNKNOWN_GOOGLE_API_ERROR` | Inspect the sanitized connection log for HTTP status and Google reason. |
| Dataset has no columns | Select at least one whitelisted column and save. |
| Dataset returned no rows | Verify scope, filters, and source data; header-only output is valid. |
| Dataset exceeds limit | Narrow the dataset/filter or implement a reviewed batch export extension. |

## 26. Security Best Practices

- Never commit `service-account.json`.
- Never put a private key or access token in Vue, browser storage, database configuration, logs, tickets, or chat.
- Grant spreadsheet Editor access only to the required service account.
- Restrict credential filesystem permissions and rotate the key if exposure is suspected.
- Keep `GOOGLE_SHEETS_ENABLED=false` in environments that do not use the integration.

## 27. Backup / Recovery

Back up MySQL configuration and application data through the normal database backup process. Back up destination spreadsheets through Google Workspace controls if required. Do not treat Sheets as a database backup. If a credential is lost, create a new key, replace the private server file, share spreadsheets with the new service account when applicable, and revoke the old key.

## 28. Developer Notes

`DatasetRegistry` owns dataset and column whitelists and applies Agent filters before export. `SyncService` validates mapping again, applies the row limit, and records results. `SheetsClient` handles service-account OAuth, metadata checks, tab creation, and API v4 batch replacement. Add a dataset only with an explicit projection, reviewed role/scope rules, tests for cross-Agent isolation, and documentation updates.
