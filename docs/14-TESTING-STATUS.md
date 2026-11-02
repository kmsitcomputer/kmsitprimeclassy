# 14 — Testing Status

Harness uji, kebijakan pengujian agen, dan status terakhir yang tercatat. Perilaku aplikasi dijelaskan di dokumen lain; dokumen ini hanya tentang pengujian.

---

## 1. Satu runner per database uji

- Semua tes otomatis memakai tepat satu database: `primeclassy_testing` (`APP_ENV=testing`; guard eksak `Tests\Support\TestDatabaseGuard` — bukan pola).
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
- Run penuh terakhir yang tercatat: 855 passed / 6075 assertions / 0 failures (~12–15 menit) — **selalu jalankan ulang untuk angka terkini**.
- Bukti rilis REL005: scoped backend 283 passed / 2485 assertions (33 kelas, `primeclassy_testing`) + frontend type-check/build + Vitest dashboard 79/79.

## 6. Keanehan lingkungan yang diketahui

- Runner CLI butuh `OPENSSL_CONF` untuk `GoogleAuthTest` (`$env:OPENSSL_CONF='C:\ServBay\packages\php\8.3\extras\ssl\openssl.cnf'`).
- Noise CLI DEV: "Cannot load Zend OPcache", "Module mbstring already loaded" — jinak.
- Tes konkurensi men-spawn proses PHP kedua nyata (`.phpunit-concurrency-actor.php`), ~10–20 s per suite.
