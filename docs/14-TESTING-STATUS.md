# 14 — Testing Status

Harness uji, kebijakan pengujian agen, dan status terakhir yang tercatat. Perilaku aplikasi dijelaskan di dokumen lain; dokumen ini hanya tentang pengujian.

---

## 1. Satu runner per database uji

- Tes serial memakai `primeclassy_testing`; infrastruktur parallel Owner-only mengizinkan tepat `primeclassy_testing_w1..w5` sesuai ID worker (§7). `APP_ENV=testing`, guard eksak `Tests\Support\TestDatabaseGuard` — bukan pola.
- `Tests\Support\TestRunLock` mengambil **MySQL named lock** (`GET_LOCK('primeclassy:testrun:primeclassy_testing', 0)`) sebelum operasi schema pertama. Runner kedua ditolak langsung ("TEST DATABASE ALREADY IN USE …"). Crash: server melepas lock saat koneksi pemiliknya mati — tidak ada lock file untuk dihapus; ulangi saja.
- Runner serial `backend/scripts/run-tests-serialized.php` menambah lock `flock` + menolak DB non-testing.
- **Fail-closed lock loss:** kepemilikan lock dibuktikan ulang (`IS_USED_LOCK = CONNECTION_ID`) di setiap batas kritis (boot tes, pre-rebuild, pre/post actor batch). Kehilangan lock ⇒ "TEST RUN LOCK LOST: refusing …" dan tetap hilang sampai proses berakhir — hentikan run, preflight ulang, mulai run baru.

## 2. Tiga jenis tes, tiga strategi isolasi

1. **Tes biasa** (`RefreshDatabase`): transaction per-tes yang di-rollback.
2. **Tes konkurensi committed** (`RestoresIsolatedTestDatabase`): fixture di-commit agar actor PHP terpisah melihatnya; schema direstore ke "persis hasil `migrate:fresh`" di batas (rebuild ~43 s; fingerprint `information_schema` + `CHECKSUM TABLE` menentukan skip).
3. **Tes kontrak migrasi/DDL**: `$isolatedSchemaAlwaysRebuild = true`.

## 3. Kebijakan pengujian (AGENTS.md §7 — berlaku)

- Agen menjalankan **scoped test saja** (kelas / `--filter`); **Full Test eksklusif Owner**; tidak pernah "full regression/E2E menyeluruh" dengan nama lain.
- **Full Test bukan gerbang review/rilis** (keputusan Owner 2026-11-02). Bukti wajib = scoped test per area perubahan (0 failure) + frontend `type-check`/`build-only` + `git diff --check`.
- Tidak ada dua runner pada satu database. Preflight sebelum test: pastikan tidak ada runner aktif.
- Jangan pernah reset/migrate/wipe `primeclassy_dev` atau produksi; operasi destruktif schema hanya di `primeclassy_testing` oleh harness.
- Klaim PASS selalu dengan bukti: command, jumlah, durasi, database.
- Jangan menambah privilege DB / menghapus / melemahkan tes.

## 4. Prosedur Full Test Owner (PowerShell — ringkas)

```powershell
cd C:\ServBay\www\primeclassy
git status --short; git rev-parse HEAD
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
  Where-Object CommandLine -match 'artisan\s+test|phpunit|pest|run-tests' |
  Select ProcessId,CreationDate,CommandLine   # harus kosong

cd C:\ServBay\www\primeclassy\backend
php scripts/run-tests-serialized.php 2>&1 |
  Tee-Object ..\primeclassy-fulltest-backend-$(Get-Date -Format yyyyMMdd-HHmm).log
```

Baca baris akhir `RUN <uuid> exit=… failures: environment/migration/concurrency/test`; artefak di `backend/storage/framework/testing/runs/` (summary JSON, junit, stats). `environment > 0` = run terganggu (kontensi/schema), bukan aplikasi salah. Filter mengandung `|` → panggil `php.exe` langsung. Comparison antar-run: bandingkan `git_head`/`git.dirty` dulu, lalu `duration/rebuilds/per-class seconds`.

## 5. Skala suite (saat reset ini)

- `backend/tests/Feature`: 191 file; `tests/Unit`: 6; `tests/Support`: 9 (harness).
- Frontend: tidak ada test runner sebagai gerbang; `npm run type-check` (vue-tsc) + `npm run build-only`. (Ada `__tests__` Vitest bersebar di komponen/views — dijalankan ad-hoc saat mengubah area terkait, contoh REL005: `views/dashboard` 79/79.)
- Run penuh terakhir yang tercatat (runner serial `run-tests-serialized.php`, 2026-11-01, PHP 8.3.33, HEAD `1f510e6` dirty): **1721 tests / 12.702 assertions / 0 failures / 0 errors** (klasifikasi environment/migration/concurrency/test = 0; exit 0; durasi 7.507,8 s ≈ 2j05m). Bukti mesin: `backend/storage/framework/testing/runs/5ca38852-…` (git-ignored). Angka "855 passed / 6075 assertions" dari dokumentasi lama berasal dari era suite yang lebih kecil; salinan log Tee di root dihapus saat cleanup 2026-11-02 ([18-LOCAL-REPOSITORY-CLEANUP](18-LOCAL-REPOSITORY-CLEANUP.md)) — bukti kanonik tetap di `runs/`. **Selalu jalankan ulang untuk angka terkini.**
- Bukti rilis REL005: scoped backend 283 passed / 2485 assertions (33 kelas, `primeclassy_testing`) + frontend type-check/build + Vitest dashboard 79/79.

## 6. Keanehan lingkungan yang diketahui

- Runner CLI butuh `OPENSSL_CONF` untuk `GoogleAuthTest` (`$env:OPENSSL_CONF='C:\ServBay\packages\php\8.3\extras\ssl\openssl.cnf'`).
- Noise CLI DEV: "Cannot load Zend OPcache", "Module mbstring already loaded" — jinak.
- Tes konkurensi men-spawn proses PHP kedua nyata (`.phpunit-concurrency-actor.php`), ~10–20 s per suite.

## 7. Parallel Full Test (5 worker) — terintegrasi, BELUM diaktifkan

Infrastruktur Owner-only di `tools/parallel-test/` (README di sana). **Status terbaru (2026-11-13): dijalankan dan terverifikasi** — run `0299a90e4e6c` PASS, 2408/2408 tes, 15.098 asersi, 0 gagal/error/skip, inventori RECONCILED (lihat `PRIMECLASSY-FULL-TEST-PRODUCTION-RELEASE-20261113.md`; Owner mengotorisasi Full Test untuk rilis ini). Catatan operasional: perintah tooling (type-check/lint/build) **jangan** memakai mount `parallel-node-linux/node_modules` milik harness — guard `FRONTEND_DEPS_CONTENT_CHANGED` akan menolak run; pakai salinan terpisah.

- `Tests\Support\TestDatabaseGuard` kini menerima, selain `primeclassy_testing`, hanya `primeclassy_testing_w1..w5` (peta eksplisit) untuk worker `PRIMECLASSY_PARALLEL_WORKER=1..5`; host DB harus `127.0.0.1|localhost|::1|primeclassy-mariadb`; `primeclassy_db`/DEV/production tetap ditolak. Tanpa variabel itu perilaku lama (database kanonis) tidak berubah.
- Tes konkurensi/aktor, tes yang membaca nama DB kanonis, dan tes yang memakai path storage bersama berjalan di *serial lane* (`primeclassy_testing`) setelah fase paralel; harness konkurensi tidak diubah.
- Runner: PHP via `docker exec primeclassy-fpm`, Node via `docker run --rm` sementara; aktivasi hanya manual oleh Owner (runbook: `tools/parallel-test/docs/OWNER-RUNBOOK.md`). Kebijakan agen tetap: scoped test saja; Full Test (serial maupun paralel) eksklusif Owner.


### Audit statis lanjutan Codex

Lihat `tools/parallel-test/docs/CODEX-FINAL-SAFETY-AUDIT-AUTO-REMEDIATION.md`. Remediasi testing-only menambah guard sebelum provider boot dan saat pemilihan koneksi/query, pidfd ownership controller, heartbeat lease, recovery markers, discovery dinamis wajib serta agregasi setelah shutdown terbukti. **Seluruh regresi baru hanya source; tidak dijalankan sesuai instruksi Owner STATIC ONLY.** Runtime termasuk prasyarat Python/pidfd container, PHP lint, grants, provisioning, dependency preparation dan Full Test tetap belum diverifikasi/dijalankan. Tidak ada perubahan business logic atau produksi.
