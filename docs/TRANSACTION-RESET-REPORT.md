# Transaction reset report — 2026-09-14

## Scope and environment

- Environment: `production`
- Database: `primeclassy` (MySQL 8.4.11)
- Operation: transaction data reset only; no table, migration, user, catalog, hierarchy, region, or integration configuration was removed.
- Command: `php artisan transactions:reset --force`

## Dependency map and deletion order

```text
orders
├── order_items
│   ├── order_item_adjustments
│   ├── return_items ── returns
│   └── commissions
├── shipments
├── payment_transactions
│   ├── bank_transfer_verifications
│   ├── cod_payment_proofs
│   └── order_additional_payments
├── stock_movements (reference: order/order_item_adjustment/return_restock)
├── activity_logs (transaction subjects/events only)
└── media/files (transaction collections/directories only)

payment_webhook_logs and sheets_sync_logs are derived integration logs and do
not own master configuration.
```

Deletion ran child-to-parent inside one database transaction. Foreign-key
checks remained enabled. Reports and dashboards are calculated from the
transaction tables, so they require no persisted zero rows. Cache invalidation
was limited to transaction/dashboard/report key patterns.

## Inventory decision

The audit found five transaction stock movements, all reservation movements.
Their combined `quantity_on_hand` delta was zero. The reset therefore preserved
current on-hand stock and set transactional `quantity_reserved` to zero. Eleven
manual/master stock movements were retained. The command stops before mutation
if it encounters an unsupported transaction movement or if restoration could
produce negative stock.

## Recovery artifacts

| Artifact | Size | SHA-256 |
|---|---:|---|
| `backend/storage/app/backups/primeclassy-before-transaction-reset-20260914.sql` | 3.3 MB | `8ca321746e7683e7142315ce0ff7cc01e9a0a9285bb24edffc708ef4b5ac0763` |
| `backend/storage/app/backups/transaction-files-before-reset-20260914.tar.gz` | 14 MB | `f3d8a837b07eed9877a76e0095e4a1d3b35d520e43270474a53bcf9622db5a34` |

Both artifacts were created and read back before the destructive command.

## Before and after

| Data | Before | After |
|---|---:|---:|
| Orders | 1 | 0 |
| Order items | 5 | 0 |
| Payment transactions | 1 | 0 |
| Shipments | 5 | 0 |
| Commissions | 10 | 0 |
| Returns / return items | 0 / 0 | 0 / 0 |
| Additional payments | 0 | 0 |
| Bank/COD proof records | 0 / 0 | 0 / 0 |
| Payment webhook logs | 0 | 0 |
| Transaction activity logs | 12 | 0 |
| Transaction stock movements | 5 | 0 |
| Reserved-stock rows | 5 | 0 |
| Google Sheets sync logs | 0 | 0 |
| Transaction media rows | 5 | 0 |
| Files in transaction-only directories | present | 0 |

## Master data verification

| Master data | Preserved count |
|---|---:|
| Users | 9 |
| Roles | 8 |
| User hierarchy closures | 18 |
| Products | 10 |
| Product variations | 31 |
| Global catalog SKUs | 31 |
| Product fee rows | 9 |
| Variation fee rows | 93 |
| Product / variation stock rows | 3 / 8 |
| Provinces / regencies / districts / villages | 38 / 514 / 7,265 / 83,202 |
| Payment methods | 6 |
| Shipping providers | 2 |
| Settings | 18 |
| Agent payment gateway configs | 1 |
| Agent shipping provider configs | 1 |

The second dry run reported zero rows in every transaction table, zero
transaction activity/stock movements, zero reservations, and zero transaction
media. This also confirms that the operation is idempotent.

## Tests

- Reset command test: passed, including dry run, master preservation, stock
  reservation restoration, and two consecutive reset executions.
- Dashboard/report/reset selection: 35 tests, 298 assertions passed on the
  isolated MySQL testing database.
- Full backend regression: 432 tests, 2,056 assertions passed on the isolated
  MySQL testing database.
