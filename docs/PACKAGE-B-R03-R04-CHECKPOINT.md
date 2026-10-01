# Package B (R-03 + R-04) — Checkpoint

**Status:** READY FOR IMPLEMENTATION  
**Created:** 2026-10-01  
**Active spec:** `docs/PACKAGE-B-R03-R04.md`  
**Repository protocol:** `AGENTS.md`

## Objective

Implement Package B in one branch, in strict sequence:

1. R-03 — fulfillment/delivery lifecycle, Sales-Kurir-Sub self-delivery, final Admin delivery verification, delivery-date invoice grouping, safe Sub-order adjustment/split/return.
2. R-04 — role authority/security, operational-vs-financial projections, Admin Product/Variation CRU-no-Delete, operational and finance reports, current/latest actor names.

## Starting state

- Package A R-01/R-02: CLOSED.
- Package A final code baseline before this documentation package: `b9ed09b60b1d2cb3d739031c40bd87e79b0f95ed`.
- Package A production deployment: PASS.
- Production migrations `2026_09_29_090000` through `2026_09_29_120000`: Ran in batch 7.
- Production reconciliation after Package A:
  - roles = 10;
  - canonical `sales-kurir-sub` present, legacy `sales-kurir` absent;
  - Nida user id 21 remains role_id 10 / agent_id 11;
  - historical referral code remains `SA-4QHJDQ`;
  - 3 historical OrderItems remain `stock_source=agent`, `sub_location_id=NULL`;
  - new Sub reservation/request tables initially empty;
  - warehouse stocks/movements/transfers preserved;
  - Nida owns active Sub Location id 3, code `Cibar`, name `Sub Cibarengkok`;
  - Admin and Gudang production UAT passed;
  - unauthenticated Package A endpoint correctly returns 401 rather than 404.

Legacy production Sub Locations id 1 (`TUTI`) and id 2 (`tina`) remain unowned. Do not silently map/delete/deactivate them in Package B.

## Locked decisions

Read `docs/PACKAGE-B-R03-R04.md`. Key invariants:

- R-03 first, R-04 second.
- Payment truth remains Order-level.
- Delivery invoice grouping = Order + delivery date.
- Sales-Kurir-Sub self-delivers Sub-sourced items.
- Admin final delivery verification is separate from payment verification.
- Gudang/Kurir must not receive unnecessary financial data.
- Admin Product/Variation = Create + Read + Update, no Delete.
- Reports show current/latest actor names via stable IDs.
- Preserve Package A stock-source and lock-order invariants.

## Baseline / branch

- Package B branch: `feat/package-b-r03-r04`
- Documentation baseline / branch base: `8b53f78a9368dfd551e12f4c15a84ad9ed821ff3`
- Base branch: `main`
- Implementation has not started yet.

Before coding, the implementing agent must record:

- `git status`;
- current HEAD (must initially equal or descend from the base above);
- baseline backend/full test result;
- baseline frontend type-check/build result.

Do not begin implementation with an unclean working tree.

## Files read

Not yet recorded. Implementer performs RECON ONCE and adds the relevant file map here.

## Files modified

None yet.

## Migrations

None yet.

Any migration added by Package B must be additive and production-data-safe. Record migration intent, historical-row default, rollback limitations, and reconciliation plan here before final review.

## Progress

- [x] Package A production deployment closed.
- [x] Package B business scope documented.
- [x] AI workflow / compaction / production safety documented in `AGENTS.md`.
- [x] Package B branch baseline recorded.
- [ ] Baseline tests recorded.
- [ ] R-03 implemented.
- [ ] R-03 focused tests pass.
- [ ] R-04 implemented.
- [ ] R-04 focused tests pass.
- [ ] Full backend regression passes.
- [ ] Frontend type-check/build passes.
- [ ] Claude independent final review complete.
- [ ] DEV manual UAT complete.
- [ ] Human Stage Gate approved.

## Open findings

None at Package B start.

Known pre-existing technical debt that must not be confused with a Package B regression:

- Package A role migration `down()` is not a perfect inverse after the rare dual-row fold path.
- Checkout's global unique temporary `orders.order_no='TEMP'` behavior was documented during Package A concurrency review; do not change it opportunistically unless Package B introduces a concrete failure that requires it.
- CLI duplicate OPcache/mbstring warnings are environmental and non-blocking.
- Historical production "MAC is invalid" log entries pre-date Package B; investigate separately if reproducible, not as an assumed Package B defect.

## Production state

Production is LIVE after Package A. Package B development must not mutate production.

No Package B production deployment/migration is authorized until after implementation, review, DEV/UAT, and Human Stage Gate.

## EXACT NEXT ACTION

1. Fetch origin and switch local worktree to `feat/package-b-r03-r04`.
2. Read `AGENTS.md`, `docs/PACKAGE-B-R03-R04.md`, and this checkpoint.
3. Run `git status`, `git branch --show-current`, and `git rev-parse HEAD`; verify a clean tree and record the current HEAD.
5. Perform RECON ONCE focused on R-03:
   - Order / OrderItem state and adjustment flow;
   - Shipment / courier delivery lifecycle and proof;
   - Return flow;
   - PaymentSummaryService boundary;
   - current R-03 422 guards for Sub-sourced adjustment/split/return;
   - frontend Order/Shipment views;
   - existing migrations/tests.
6. Update this checkpoint with the file map and baseline test results.
7. Implement R-03 only. Do not start R-04 until R-03 is internally green.
