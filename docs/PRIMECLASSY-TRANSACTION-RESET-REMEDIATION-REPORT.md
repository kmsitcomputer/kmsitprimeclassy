# Transaction reset remediation — 2026-11-11

## Scope and baseline

Human request: fix the existing transaction reset and complete the previously requested local transaction cleanup.
Branch: `fix/transaction-reset-financial-dependencies`; baseline: `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`.
The checkout already contained extensive V2 source/test/doc changes, including untracked migrations. Those changes are preserved and excluded from this fix's commit. Validation covers that current working tree, including its delivery-refusal migration; this is not standalone validation of the clean baseline.

## Defect and fix

`transactions:reset --force` failed with FK 1451 from `order_financial_snapshot_units.order_item_id`. Its old deletion list omitted financial snapshots/bases, refund ledger, commission adjustments, sales reductions, consent and recipient evidence, invoice/report/due-date snapshots, and delivery refusals. Local execution also reproduced FK 1451 from `whatsapp_messages.order_id`; the FK audit confirmed the same RESTRICT dependency for email logs.

The reset now audits/deletes those transaction children before their parents. Deferred-obligation, recipient-evidence and consent self-references plus the return/consent cycle are broken inside the same DB transaction. Order-related notification logs are removed before orders; unrelated OTP/account/system notifications survive. Existing physical-stock semantics, master data and production confirmation guard are retained. Normal application ledger immutability is unchanged. No migrations or production operations were performed. The separate DEV-only reset command is unchanged.

## Validation

- Initial serialized test attempt without Docker DB host override: 7 connection errors, 0 assertions (`Connection refused` at test-run lock acquisition). No application assertion ran.
- Corrected command: `docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm-test php scripts/run-tests-serialized.php tests/Feature/TransactionResetCommandTest.php`.
- Final result: **8 tests, 262 assertions, 0 failures/errors**, PHPUnit 21.124s; runner 21.7s; DB `primeclassy_testing`; run ID `b6086db4-37d8-4d31-aa6e-4010152f8be1`.
- Tests exercise populated financial/refund/deferred chains, consent cycles, delivery refusals, notification deletion with unrelated records preserved, dry-run, idempotency, master/stock preservation, production guard and injected mid-reset failure. Rollback compares complete rows including restored self/cyclic links and notification logs, and checks restored stock/reservations.
- `docker exec primeclassy-fpm vendor/bin/pint --test app/Console/Commands/ResetTransactions.php tests/Feature/TransactionResetCommandTest.php`: PASS, 2 files.
- PHP lint on both changed PHP files: PASS. `git diff --check`: PASS.
- Frontend unchanged; frontend checks and Full Test were not run.

## Local cleanup outcome

Verified runtime: environment `local`, DB `primeclassy_db`, host `primeclassy-mariadb`; no production connection.
Backup outside Git: `/Users/kmsitcomputer/primeclassy-backups/reset-fixed-20261111-120634/` contains SQL dump, `storage/app` archive, SHA256 manifest, before/after snapshots and reset audit logs. Dump command succeeded; 123 CREATE TABLE statements and dump-completion marker verified. Backup was not restore-tested.

After scoped tests passed, final `transactions:reset --dry-run` and `transactions:reset --force` both exited 0. All 41 audited transaction tables are empty, including 12 orders, 18 order items and 11 payment transactions. Remaining transaction movements, reservations, transaction notifications and file deletion failures are all 0. Audited master counts, Sub ownership, full warehouse-stock snapshot and Agent on-hand checksums match the pre-reset snapshot.

`docker exec primeclassy-fpm php artisan cache:clear`: exit 0. Internal HTTP smoke against `primeclassy-web`: `/up` 200 and `/api/v1/homepage` 200.

## Completion

No further action remains for this bounded fix and local cleanup. No push, merge or deployment. The repository remains dirty from the pre-existing V2 work; only this fix's files are committed.
