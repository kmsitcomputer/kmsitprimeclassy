# Serialized-lane rules
Routing is by **static** text rules in `config/serialized-rules.json` (conservative, not proofs). A file matching any rule runs in PHASE S, alone, on `primeclassy_testing`, through the existing `scripts/run-tests-serialized.php`. Each manifest entry lists `serial_reasons`.

| Rule | Why | Files (current manifest) |
|---|---|---|
| SER-ACTOR | spawns real PHP actors; harness hard-codes the canonical DB and a shared runtime dir | 28 (22 actor-only) |
| SER-GUARD-LOCK | asserts the exact canonical DB name / run-lock / runner semantics | 5 |
| SER-HARDCODED-DB | non-comment literal `primeclassy_testing` | 4 |
| SER-SHARED-STORAGE | fixed `installed.lock` / `framework/testing/concurrency` | 3 |
| SER-FINGERPRINT | schema-fingerprint internals | 2 |
(counts overlap; 30 distinct files in total, est. 4,339 s from recorded timings)

`path_overrides` forces a lane per file. Moving a file to `parallel` is a reviewed config change followed by `manifest-generate`; the approval digest changes with it.
Schema-rebuild/DDL classes that only use their *own* connection (e.g. `RefundBackfillTest`, `DevSchemaForeignKey*` unless they match a rule) stay parallel-eligible because each worker owns its own database — **unverified at runtime**.

## Re-verification after Codex's remediation (2026-11-11, static)
Inventory regenerated against HEAD `d7997c4` + dirty tree: 208 PHP files, identical lane split (178 parallel / 30 serial; the 28 actor files were re-derived by rule, not copied: includes new `PartialCancellationConcurrencyTest`). Codex's edits to `ConcurrencyHarness.php` / `.phpunit-concurrency-actor.php` (overlap check `&&`→`||`, `dispatch-race-expected` option) do not change routing. No test outside the rules was found unsafe in the hazard scan (process spawn, shared storage paths, DB-name literals, Redis, un-faked HTTP/Storage). Serial lane never overlaps with a worker (`exclusive_after_parallel`).
