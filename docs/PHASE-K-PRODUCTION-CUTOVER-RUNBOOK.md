# Phase K Production Cutover Runbook

## Safety gate

Production migration is not automatic. A verified database backup and explicit
human authorization are required before running the non-dry command.

`BACKUP VERIFIED BY HUMAN: YES / NO`

## Sequence

1. Deploy the Phase K code and migrations.
2. Verify application health and database connectivity.
3. Verify a restorable database backup. Do not proceed when this is `NO`.
4. Run the read-only preflight/reconciliation command.
5. Run `php artisan warehouse:migrate-legacy-stock --dry-run` and inspect all
   conflicts, negative quantities, missing Agents, duplicate targets, and
   pre-existing warehouse rows.
6. Resolve blockers or stop. Do not guess an Agent, bucket, Plan, Sub, or
   Shipping destination.
7. Schedule a write freeze for checkout reservations, warehouse receipt,
   transfer, opname approval, fulfillment, cancellation, and return restock if
   the deployment cannot guarantee deterministic row-level serialization.
8. With human authorization, run the command with a stable `--run-id` and an
   appropriate `--batch-size`.
9. Run `php artisan warehouse:reconcile` and compare legacy baseline totals,
   Transit baseline movements, reservations, physical totals, and sellable
   calculations.
10. Enable warehouse-authoritative reads only through the existing Phase H
    compatibility behavior after reconciliation passes. This tooling does not
    execute that production switch automatically.
11. Smoke-test storefront stock, checkout reservation, warehouse reads,
    fulfillment, cancellation, and good-return restock.
12. Resume writes and monitor migration markers/reconciliation output.

## Rollback boundary

Before warehouse writes resume, restore the verified backup or revert the
migration run as an operational database procedure. Do not blindly subtract
Transit after legitimate warehouse movements have occurred. After live
warehouse activity begins, use reconciliation and a new controlled correction
workflow rather than automatic rollback arithmetic.

## Command examples

```text
php artisan warehouse:migrate-legacy-stock --dry-run --batch-size=500
php artisan warehouse:migrate-legacy-stock --dry-run --agent=123
php artisan warehouse:migrate-legacy-stock --run-id=legacy-20260917-a1b2c3 --batch-size=250
php artisan warehouse:reconcile
```

Production execution remains: **NOT EXECUTED — HUMAN STAGE GATE REQUIRED**.