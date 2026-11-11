# Parallel Full Test infrastructure (5 workers) — INTEGRATED, NOT ACTIVATED

Owner-run only. Nothing here runs automatically, no tests/database/container operations were executed by the Codex static audit. Current database/container state was not inspected by that audit.
Status: **STATIC AUTO-REMEDIATION COMPLETE — RUNTIME VALIDATION PENDING**. See `docs/CODEX-FINAL-SAFETY-AUDIT-AUTO-REMEDIATION.md`; this is not execution authorization. Runtime behaviour is **UNVERIFIED** (see `docs/KNOWN-LIMITATIONS.md`).

* `bin/pc` → `pctest/cli.py` (python3, stdlib only). Stage A (static, safe): `manifest-generate|manifest-verify|static-validate|plan|aggregate|provision plan`. Stage B (Owner, gated): `run`, `provision create|cleanup`.
* `config/` scheduler + serial-lane rules · `env/worker.env.template` · `php/db-preflight.php` (read-only probe, Stage B) · `manifest/manifest.json` · `schema/` · `tests/` (tooling self-tests; **not run** during integration).
* Docs: `docs/ARCHITECTURE.md` (architecture, guard, isolation matrix, adapters) · `docs/SERIALIZED-LANES.md` · `docs/DB-PROVISIONING-PLAN.md` · `docs/OWNER-RUNBOOK.md` · `docs/SAFETY-AND-ROLLBACK.md` · `docs/KNOWN-LIMITATIONS.md` · `docs/INTEGRATION-REPORT.md`.
* Policy: `AGENTS.md` §7 / `docs/14-TESTING-STATUS.md` — Full Test is Owner-exclusive; agents never run it.
