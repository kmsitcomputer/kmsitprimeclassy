# SKU and hierarchy audit — 2026-09-13

This report was generated from read-only queries against the configured MySQL
database before the new migrations were applied.

| Check | Result | Action |
|---|---:|---|
| Simple products | 3 | Require SKU backfill after migration review |
| Simple products missing SKU | 3 | Preview `products:backfill-sku`, then apply explicitly |
| Duplicate variant SKU groups | 0 | No correction required |
| Product/variant SKU collisions | Not applicable before product SKU column exists | Enforced by the new global registry |
| Sales with missing, invalid, foreign, or inconsistent Korsal/parent hierarchy | 0 | No manual assignment required |

The backfill format is deterministic: `PRD-` plus the product ID padded to ten
digits. If that value is already reserved, a deterministic numeric suffix is
added. The command locks and rechecks each product, leaves every valid SKU
unchanged, and does not touch historical order-item snapshots.

```bash
php artisan system:audit-catalog-hierarchy
php artisan products:backfill-sku
php artisan products:backfill-sku --apply
```

The first backfill command invocation is dry-run mode. Applying database
migrations or the backfill to the existing database was outside this
implementation run and has not occurred.
