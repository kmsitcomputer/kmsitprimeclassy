# 17 — Documentation Reset Report (2026-11-02)

Laporan akhir **Full Documentation Reset & Rebuild** PrimeClassy V2 (docs-only, one-pass, lokal, produksi off-limits). Executor: GLM AI Coding Agent (otorisasi Owner).

---

## 1. Baseline

- Branch `main`, HEAD awal `f8445e1dcbe4a40fa4a477797f07306092ae3b68` (REL006) — **identik dengan baseline produksi/main yang diketahui**; tidak ada checkout/pull/merge.
- Working tree awal: `BLUEPRINT.pdf` D (dihapus Owner), `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` M (Owner), 40+ untracked (dokumen historis, log, artifacts).
- Metode: preflight sekali → inventaris → rekonstruksi source (routes penuh 914 baris, 162 migrasi + model, services, policies, middleware, router frontend, katalog notifikasi, PermissionMap, jobs/commands) → penulisan ulang → validasi ringan → satu commit.
- Sumber kebenaran: implementasi → aturan bisnis terkunci → tests → konfigurasi → dokumentasi historis (sekunder).

## 2. Hasil klasifikasi

**Ditemukan:** 59 file dokumentasi (18 tracked di `docs/` + README + AGENTS + 28 untracked `docs/`-root/`06-review` + SECURITY-AUDIT root + `backend/database/data/regions/SOURCE.md` + 3 file `design/` lokal).

| Kategori | Jumlah | Keterangan |
|---|---|---|
| REWRITE (baru kanonis) | 17 | `docs/01-…` s.d. `docs/17-…` + README + AGENTS diperbarui |
| REPLACE → DELETE (tracked) | 17 | dihapus setelah konten dimigrasi (mapping §4) |
| DELETE (untracked, historis) | 28 | checkpoint/audit/laporan historis (mapping §5); **backup aman dibuat** |
| PROTECT (tidak disentuh) | 6+ | `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` (modifikasi Owner, tidak di-stage), `backend/database/data/regions/SOURCE.md`, `design/*` (untracked lokal), plus non-dokumen (`artifacts/`, log Full Test, `frontend/pnpm-lock.yaml` stray, `.bak`, `backend/2048])!`, `backend/holder)`) |

`BLUEPRINT.pdf`: penghapusan oleh Owner dimasukkan ke commit docs-only (tidak dipulihkan; tanpa referensi aktif yang rusak).

**Backup rollback** (syarat untracked): 29 file historis tersalin ke `C:\Users\KMSIT Komputer\AppData\Local\Temp\opencode\primeclassy-docs-backup-20261102\` (di luar repo). Tracked files dapat dipulihkan via Git history.

## 3. Dokumen kanonis baru

`README.md` (pintu masuk) · `AGENTS.md` (tata kelola, referensi diperbarui) · `docs/01-PROJECT-OVERVIEW` · `02-SYSTEM-ARCHITECTURE` · `03-FEATURE-CATALOG` · `04-ROLES-PERMISSIONS` · `05-BUSINESS-RULES` · `06-BUSINESS-WORKFLOWS` · `07-FRONTEND-GUIDE` · `08-BACKEND-API` · `09-DATABASE-MODEL` · `10-NOTIFICATIONS` · `11-INTEGRATIONS` · `12-SECURITY` · `13-DEPLOYMENT-OPERATIONS` · `14-TESTING-STATUS` · `15-KNOWN-LIMITATIONS` · `16-TRACEABILITY` · `17-DOCUMENTATION-RESET-REPORT` (dokumen ini).

## 4. Mapping dokumen tracked yang dihapus

| File lama | Migrasi ke |
|---|---|
| `docs/MASTER-SYSTEM.md` | 01, 02, 03, 06, 09, 11 (per bagian) |
| `docs/BUSINESS-RULES.md` | `05-BUSINESS-RULES.md` (§1–§30 diteruskan utuh; LOCKED dipertahankan) |
| `docs/ARCHITECTURE.md` | `02-SYSTEM-ARCHITECTURE.md` + `10-NOTIFICATIONS.md` + `15-KNOWN-LIMITATIONS.md` (§15a/§16/§17/§18 → status terkini) |
| `docs/OPERATIONS.md` | `13-DEPLOYMENT-OPERATIONS.md` (§1–§10; catatan GOWA/KMSIT/WA → 10/11/13) |
| `docs/TESTING.md` | `14-TESTING-STATUS.md` |
| `docs/PRIMECLASSY-GAP-DEPENDENCY-LEDGER.md` | `15-KNOWN-LIMITATIONS.md` (G-01…G-24 dipetakan status terakhir) |
| `docs/PRIMECLASSY-MASTER-NOTIFICATION-CONTRACT.md` | `10-NOTIFICATIONS.md` (implementasi aktual REL005/REL006) |
| `docs/OWNER-DECISION-TRACEABILITY.md` | `16-TRACEABILITY.md` (register ringkas; detail tetap di Git history) |
| `docs/CR-SALES-KURIR-SUB-DELIVERY-001-CHECKPOINT.md` | §12 `05-BUSINESS-RULES.md` |
| `docs/MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md` | §29 `05-BUSINESS-RULES.md` + 03-FEATURE-CATALOG |
| `docs/PRIMECLASSY_CHANGE_REQUEST_STOCK_PAYMENT_ORDER_FILTERS.md` | §24a `05-BUSINESS-RULES.md` |
| `docs/PC-V2-CLAUDE-UNIFIED-REMEDIATION-001-CHECKPOINT.md` | Git history (proses remediation selesai) |
| `docs/PC-V2-FINAL-RELEASE-READINESS-001.md` | Git history (rilis sudah terjadi) |
| `docs/PRIMECLASSY-IMP-REM-BLUEPRINT.md` | Git history (implementasi selesai) |
| `docs/PRODUCT-RESET-REMEDIATION-CHECKPOINT.md` | `13-DEPLOYMENT-OPERATIONS.md` §8 (prosedur command) |
| `docs/adaptation/PC-SV2-DOCS-ONLY-APPLY-INSTRUCTIONS.md` | Git history (tugas docs-only selesai) |
| `docs/adaptation/PC-SV2-DOCS-ONLY-CONSOLIDATION-20261028.md` | Git history (konsolidasi terlanjur terserap dokumen kanonis) |

## 5. Mapping dokumen untracked yang dihapus (backup tersedia)

Semua checkpoint/audit/laporan historis — konten keputusannya telah terserap ke `05-BUSINESS-RULES` (§27 status, keputusan 2026-10-30/31), `15-KNOWN-LIMITATIONS` (ledger G-xx), `16-TRACEABILITY`, dan `10-NOTIFICATIONS` (status REL005/006):

`SECURITY-AUDIT-20261004.md` (ringkasan ke `12-SECURITY.md` §6) · `docs/06-review/HUMAN-UAT-REMEDIATION-004-008.md` (otoritas verifikasi → §24) · `docs/{AUDIT-PC-GS-REPORTING-001, CLAUDE-CHANGE-VERIFICATION, CODEX-FINAL-INTEGRATED-AUDIT, CODEX-INTEGRATED-AUDIT-1(-REMEDIATION), DOC-INVENTORY-CLEANUP-PROPOSAL-001, FIX-COURIER-ASSIGNMENT-422-CHECKPOINT, LOCAL-STABILIZATION-CHECKPOINT, PC-GS-REPORTING-CHECKPOINT, PC-MIGRATION-CHECKPOINT, PHASE2-ACCEPTANCE-PACKAGE, PHASE2-CHECKPOINT, PHASE2-INDEPENDENT-REVIEW-CHECKPOINT, PHASE2-REPORT-PARITY-AND-KPI-MATRIX, PRIMECLASSY-AUD01-13-MUSE-CHECKPOINT, PRIMECLASSY-DEV-QUEUE-ACTIVATION-CHECKPOINT, PRIMECLASSY-GLM-CLAUDE-REMEDIATION-CHECKPOINT, PRIMECLASSY-IMP-008-OWNER-DECISION-HANDOFF, PRIMECLASSY-SIMPLEV2-FEATURE-MATRIX, RECON-002-CHECKPOINT, RECON-PC-POST-PULL-RECOVERY-001, RECON-PRIMECLASSY-002, REFUND-LEDGER-CHECKPOINT, SALES-PARITY-CHECKPOINT, SPACEBUNNY-HUMAN-UAT-004-008-FINAL-AUDIT, SPACEBUNNY-UAT-004-TO-LATEST-FINAL-AUDIT, TEST-HARNESS-REMEDIATION-CHECKPOINT}.md`

## 6. Ringkasan temuan penting rekonstruksi

- **Koreksi fakta vs dokumen lama** (diverifikasi source):
  1. AU-01/G-07 **FIXED** (REM-004): `AuthController::register` kini HTTP 503 + `registration_unavailable` (dokumen lama masih menyebut 500) — `backend/app/Http/Controllers/Api/V1/Auth/AuthController.php:61-66`.
  2. **WS-8 sales-fee refund adjustment IMPLEMENTED** (2026-10-30): `SalesFeeRefundAdjuster` + migrasi `2026_10_30_105000` (BUSINESS-RULES lama §15a masih "NOT IMPLEMENTED") — hanya fee sales; entri OD-05c lain tetap pending.
  3. Docblock `RoleSeeder` menyebut "10 roles" namun array faktual 11 — sesuai LOCKED 11 role.
- **Struktur aktual terpetakan**: ±200 endpoint (`api_v1.php`), 162 migrasi (±100 tabel bisnis), 17 policy, 11 role + `HierarchyRules`, 16 event notifikasi + 3 kanal + ledger idempotent, 60+ route SPA, 44 modul API frontend, 2 queue job + worker systemd, 16+ command artisan.
- **REL006 `payment_proof_uploaded`**: trigger after-commit dari transaksi upload, `FinanceRecipientResolver` (keuangan cabang aktif saja), 3 kanal dengan gate masing-masing (email wajib terverifikasi), idempotency `txn:…:{verificationId}:{proofHash}:u{userId}`, tidak mengubah status pembayaran — `OrderNotifier.php:91-145`.
- **Status runtime NOT VERIFIED** (dipertahankan dari laporan Owner, tidak di-upgrade): E2E browser In-App & ledger bukti pembayaran; email Keuangan ID 18 (sebelumnya belum terverifikasi).

## 7. Validasi ringan (hasil)

| Pemeriksaan | Hasil |
|---|---|
| 17 dokumen kanonis + README + AGENTS tersedia | ✅ |
| Link internal dokumen kanonis valid (nama target ada) | ✅ |
| Referensi path source di dokumen valid | ✅ (diperiksa manual terhadap path yang ada) |
| Tidak ada dokumen otoritas ganda | ✅ (dokumen lama dihapus; Git history = arsip) |
| Tidak ada secret baru di dokumentasi | ✅ (hanya nama host/layanan yang memang non-rahasia) |
| Source code / migrasi / konfigurasi runtime / `.env` tidak berubah | ✅ |
| File Owner tidak tertimpa | ✅ (`docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` utuh, tidak di-stage) |
| Matriks fitur/role/API punya evidence path | ✅ |
| Git diff hanya dokumentasi + penghapusan `BLUEPRINT.pdf` | ✅ |

**Residual yang diketahui (disengaja):** 7 komentar source code dan 1 komentar `.gitignore` masih merujuk nama dokumen lama (`docs/OPERATIONS.md`, `docs/BUSINESS-RULES.md`, `docs/TESTING.md`, `docs/ARCHITECTURE.md`) — **tidak diubah** karena mandat docs-only melarang menyentuh kode. Mapping: `OPERATIONS.md` → `13-DEPLOYMENT-OPERATIONS.md`, `BUSINESS-RULES.md` → `05-BUSINESS-RULES.md`, `TESTING.md` → `14-TESTING-STATUS.md`, `ARCHITECTURE.md` → `02-SYSTEM-ARCHITECTURE.md`. File terdampak: `.gitignore:33`, `backend/app/Services/Report/OrderInitialReportService.php:18`, `backend/database/migrations/2026_10_08_110000…:50`, `2026_10_08_111000…:36`, `backend/tests/Support/IsolatedSchemaFingerprint.php:13`, `backend/tests/Support/TestRunLock.php:235`, `deploy/production/primeclassy-queue.service:2`, `frontend/scripts/build-pwa.mjs:37`. Perbaikan menunggu perubahan kode yang diotorisasi.

## 8. Komit & keamanan

- Satu commit lokal docs-only (staging selektif; tanpa `git add -A`).
- Tidak ada push, merge, deploy, produksi, test suite, atau perubahan database.
- Full Test tidak dijalankan (eksklusif Owner; perubahan docs-only tidak memerlukannya).
