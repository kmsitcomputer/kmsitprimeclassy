# PrimeClassy V2 — Parallel Full Test Final

**Verdict: PASS.** Seluruh suite resmi selesai dengan exit code 0, tanpa failure, error, skip, risky, atau incomplete.

## 1. Identitas

| | |
|---|---|
| Run ID | `4b6f27f7775b` |
| Waktu (UTC) | 05:52:01 → 06:26:42 (34,7 menit); WIB 12:52 → 13:26, 2026-11-11 |
| Environment | Local DEV macOS, 36 CPU, RAM dan disk lega; PHP 8.3.36 / PHPUnit 11.5.56 / Vitest 5.0.4 / MariaDB 10.11.19 |
| Branch / HEAD | `fix/transaction-reset-financial-dependencies` / `fc8e340` (satu commit di depan `origin/main` = `d7997c4`) |
| Working tree | dirty, 158 entri (perubahan REM-001..006, remediasi workflow pemenuhan parsial, invoice COD, dan tooling parallel-test); dipertahankan |
| Manifest | digest `7a723a1d1400cd27441a13dda6202646a9758e59f8e2fa868223af3692d28c98` |

Catatan pre-flight: branch dan HEAD berubah dari `main`/`d7997c4` karena commit `fc8e340` (12:09) oleh pemilik repo, bukan bagian dari run ini. Tidak ada proses test/agen lain aktif saat run; repo tenang 3 menit sebelum mulai.

## 2. Perintah yang dijalankan

```
python3 -B tools/parallel-test/pctest/cli.py run --manifest tools/parallel-test/manifest/manifest.json \
  --out-dir backend/storage/framework/testing/parallel --i-am-the-owner \
  --stage-b-confirm "RUN PRIMECLASSY PARALLEL FULL TEST" --approve-manifest 7a723a1d1400cd27
```
Runner resmi existing; tidak ada konfigurasi baru, tidak ada filter, tidak ada rerun. Pre-flight lain: `manifest-verify` (cocok dengan inventaris langsung), snapshot repo sebelum dan sesudah.

## 3. Isolasi database (dibuktikan sebelum run)

Akun `primeclassy_test` hanya punya grant pada `primeclassy_testing` (kanonik) dan `primeclassy_testing_w1..w5` (nama dengan `_` di-escape); `primeclassy_db` ditolak (error 1044). Setiap DB mengembalikan `select database()` yang benar (masing-masing 123 tabel). Runner mengulang pemeriksaan guard/privilege/lease sebelum menyalakan worker.

| Job | Database | Exit | Durasi | Test |
|---|---|---|---|---|
| worker-1 PHPUnit | `primeclassy_testing_w1` | 0 | 493,2 s | 9 |
| worker-2 PHPUnit | `primeclassy_testing_w2` | 0 | 515,7 s | 344 |
| worker-3 PHPUnit | `primeclassy_testing_w3` | 0 | 340,0 s | 496 |
| worker-3 Vitest (36 spec) | tanpa DB (kontainer Node efemeral, jaringan none) | 0 | 51,1 s | 314 |
| worker-4 PHPUnit | `primeclassy_testing_w4` | 0 | 370,8 s | 384 |
| worker-5 PHPUnit | `primeclassy_testing_w5` | 0 | 336,7 s | 444 |
| serial PHPUnit (31 berkas) | `primeclassy_testing` | 0 | 1.563,5 s | 236 |

## 4. Hasil

| | Nilai |
|---|---|
| Discovered (runtime) = executed | **2.227 = 2.227** (PHPUnit 1.913 + Vitest 314) |
| PASS / FAIL / ERROR / SKIPPED / RISKY / INCOMPLETE | 2.227 / 0 / 0 / 0 / 0 / 0 |
| Assertions | **14.348** (serial 2.302, w1 51, w2 2.322, w3 3.388, w4 3.028, w5 3.257; Vitest tidak melaporkan asersi) |
| Rekonsiliasi | `inventory_completeness: RECONCILED`; identitas `Class::method[#dataset]` terhadap JUnit: missing/unexpected/duplicate 0; setiap job `discovered == executed` |
| Problems berat `fail` | tidak ada (hanya informasi: batas bawah statis vs aktual) |
| Penghentian | `STOPPED.json` `verified_stopped: true` (06:26:43Z); tidak ada kontainer `pc-*`, proses PHPUnit, atau direktori `concurrency-*` tersisa |

Perbandingan baseline (run `56f76cae391a`): 2.180 test / 14.010 asersi → 2.227 / 14.348. Selisih +47 test dan +338 asersi berasal dari cakupan baru (test pemenuhan parsial/kekurangan, antrean Gudang, invoice COD, form Gudang, panel kekurangan, test pendukung); tidak ada test hilang.

## 5. Regresi kritis (semua lulus dalam run ini, tanpa perubahan implementasi)

- **UAT-01 pemenuhan parsial** — `FulfillmentShortageWorkflowTest` (12 test, worker-5): 3 produk × 1 pada satu shipment → Gudang menyerahkan 2 → Koordinator menerima 2 → penugasan 2 berhasil, produk ketiga di shipment pending terpisah tanpa kurir (assign ditolak), tidak ada approval Admin, tanpa stok negatif atau gerakan ganda. Pendukung: `CourierAssignmentReceiptGateTest` (4), `ExpeditionDispatchGateTest` (6, RajaOngkir tidak berubah), `DispatchAssignmentConcurrencyTest` (10, serial).
- **UAT-02 kekurangan** — test yang sama: satu notifikasi PEMENUHAN KURANG tertaut order untuk Admin dan Agen cabang saja, rincian per produk akurat, Reschedule dan Cancel Sisa hanya atas sisa, unit terkirim tidak terganggu, SC-03 tambah item tetap berfungsi. Frontend: `FulfillmentShortagePanel` (6), `OrderDetailView` (29), `OrderDiprosesView` (11), semuanya OK.
- **UAT-03 invoice COD** — `InvoiceTest` (20, worker-3): Admin, Agen, Keuangan, Pemesan, Sales, dan Korsal dalam lingkup berhasil; Sales/Korsal luar lingkup, Koordinator, Gudang, dan pembeli lain 403; cabang lain 404; satu snapshot, tidak digandakan; COD tetap "Belum Dibayar"; flag tombol `viewer_can_download_invoice` konsisten.

## 6. Kegagalan

Tidak ada.

## 7. Bukti dan log

`backend/storage/framework/testing/parallel/run-4b6f27f7775b/` (`summary.json`, `summary.md`, `STOPPED.json`, `junit/*.xml`, `discovery/*`, `worker-*/logs`, `serial/logs`, `plan.json`) dan log runner di scratchpad sesi (`run-final.log`). Snapshot repo sebelum/sesudah run identik (status, diff, untracked, dan aset yang dilayani `public_html`).

## 8. Keterbatasan

- Browser UAT otomatis tidak dijalankan (alat tidak tersedia); suite Vitest memverifikasi komponen dengan API ter-mock, bukan aplikasi aktif.
- Mesin uji MariaDB 10.11; produksi MySQL 8.0.46. Jalur fingerprint MySQL tidak dieksekusi di server MySQL (migrasi diuji terpisah pada MySQL 8.4, lihat laporan release).
- Suite tidak menguji data nyata DEV.

## 9. Konfirmasi

`primeclassy_db` dan produksi tidak disentuh oleh run ini (akun uji tidak punya akses ke `primeclassy_db`; hanya `primeclassy_testing*` dipakai). Tidak ada perubahan source, commit, push, merge, atau deploy selama run; Woodpecker CI tidak digunakan.
