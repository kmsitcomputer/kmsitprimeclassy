# 18 — Local Repository Cleanup Report (2026-11-02)

Laporan **Local Repository Cleanup & Git Hygiene** (one-pass, lokal). Manifest penghapusan lengkap ada di Lampiran A — setiap file tercatat dan dapat ditelusuri.

---

## A. Identitas pekerjaan

| Item | Nilai |
|---|---|
| Tanggal | 2026-11-02 |
| Branch | `main` |
| Baseline commit | `404ba14a928ab2ee424a30c13d936eeffa120e0c` (dokumentasi kanonis reset) |
| Executor | GLM AI Coding Agent (otorisasi Owner) |
| Scope | File lokal di luar Git (untracked + ignored) pada repo lokal; `.gitignore`; dokumentasi manifest. Tanpa perubahan source code, database, migrasi, konfigurasi runtime, secret, VPS produksi, deployment, atau test suite. |

## B. Inventaris

| Kategori | Jumlah |
|---|---|
| File tracked (Git) | ±3.700 (tidak berubah) |
| File untracked terlihat | 15 item root/`backend/`/`frontend/` (13 unik + isi folder) |
| File untracked sebenarnya | 158 file + 2 direktori (`artifacts/` 146 file, `design/` 8 file, 8 log root, 3 file kecil, 1 berkas `.bak`, 2 file sampah CLI) |
| File ignored (tidak disentuh) | `.env*`, service-account, `storage/framework/*`, sessions, `.vscode/settings.json`, `backend/storage/framework/testing/runs/` (168 file bukti uji), dll. — semuanya tetap |
| Ukuran artefak yang dibersihkan | **41.698.639 byte ≈ 39,8 MB** (dari 41.867.633 B inventaris; 94 B log aktif dipertahankan) |

Kelompok temuan: (1) `artifacts/` — visual-QA screenshot rilis tertutup + bundel deploy `.tgz` + skrip deploy sekali-pakai + log dev; (2) salinan log Full Test `Tee-Object` di root; (3) 2 file sampah output CLI di `backend/`; (4) berkas `.bak` duplikat; (5) `design/` referensi lokal; (6) `frontend/pnpm-lock.yaml` stray; (7) `kmsit-routing-smoketest.local.txt`; (8) `tools/` direktori kosong.

## C. KEEP & TRACK

Tidak ada file baru yang perlu di-track. Yang sudah tracked tetap: source, migrasi, tests, `frontend/package-lock.json` (lockfile otoritatif npm — **package manager resmi = npm**, `package.json` tanpa field `packageManager`; keputusan Owner G-14), `backend/.phpunit-concurrency-actor.php` (bagian harness), dokumen kanonis `docs/01–17`, `deploy/`, `.gitignore`.

## D. KEEP & IGNORE (aturan `.gitignore` baru — file tetap ada di disk)

| Pola baru (root `.gitignore`) | Untuk | Konfirmasi file ada lokal |
|---|---|---|
| `/design/` | Referensi mockdesign lokal (8 file, 88 KB; spec + token + logo) — bukan source aplikasi | ✅ `design/README.md` dkk. |
| `/artifacts/` | Folder scratch lokal; kini hanya menyisa log dev **aktif** (dipakai proses `php artisan serve` PID 11380) | ✅ `artifacts/backend-dev.log` (94 B), `artifacts/backend-dev-error.log` (0 B) |
| `/full-test-*.log` dan `/*-fulltest-*.log` | Salinan log Full Test `Tee-Object` Owner di masa depan; bukti mesin tetap di `backend/storage/framework/testing/runs/` (sudah di-ignore backend) | (file saat ini dihapus — kategori E) |
| `/*.local.txt` | Output smoketest lokal | ✅ `kmsit-routing-smoketest.local.txt` (940 B — bukti mentah verifikasi live KMSIT 2026-11-04; hasilnya juga terdokumentasi di [13-DEPLOYMENT-OPERATIONS](13-DEPLOYMENT-OPERATIONS.md) §9) |
| `/frontend/pnpm-lock.yaml` | Stray legacy pnpm — **diperiksa**: npm adalah standar; lockfile reproducible build = `package-lock.json` (tracked). Keputusan Owner G-14 (2026-10-30): file lama ini **dibiarkan di disk, tidak dipakai/di-track/dihapus** | ✅ 215.064 B |

`kmsit-routing-smoketest.local.txt` sebelumnya di-ignore via `.git/info/exclude` (baris 16); aturan kini juga ada di `.gitignore` agar konsisten lintas clone.

## E. DELETE MANIFEST

### E.1 Item level-atas (12)

| Path | Jenis | Ukuran (B) | Alasan | Status |
|---|---|---|---|---|
| `artifacts/` (isi, 144 file) | artefak build/visual-QA/log | 37.213.833 | bundel `.tgz` & skrip deploy sekali-pakai rilis tertutup (REL003 `232ff8f`/`38cc75d`, REL004 `419e2e8` — regenerable via `deploy/build.sh`; catatan rilis di Git history & [16-TRACEABILITY](16-TRACEABILITY.md)); screenshot visual-QA QA selesai; log dev non-aktif | DELETED |
| `full-test-backend.log` | log uji | 367.121 | salinan Tee lama; bukti mesin di `runs/` | DELETED |
| `full-test-frontend.log` | log uji | 12.372 | idem | DELETED |
| `primeclassy-fulltest-backend-20261031.log` | log uji | 3.770.770 | salinan Tee (run 10-31 bermasalah; **digantikan** run 11-01 yang bersih — lihat [14-TESTING-STATUS](14-TESTING-STATUS.md) §5) | DELETED |
| `primeclassy-fulltest-frontend-20261031.log` | log uji | 18.926 | salinan Tee lama | DELETED |
| `primeclassy-fulltest-backend-14480521-0220.log` | log uji | 18.568 | salinan Tee lama | DELETED |
| `primeclassy-fulltest-backend-14480521-2041.log` | log uji | 18.927 | idem | DELETED |
| `primeclassy-fulltest-frontend-14480521-2043.log` | log uji | 10.059 | idem | DELETED |
| `backend-fulltest-remediation-001.log` | log remediasi | 264.973 | log remediasi lama; juga sudah diklasifikasi "generated log, candidate" oleh checkpoint Owner (BL-6) | DELETED |
| `backend/2048])!` | sampah CLI | 95 | output parse-error PHP dari salah ketik perintah (2026-10-30; diklasifikasi checkpoint Owner BL-6 "generated temporary, cleanup candidates") | DELETED |
| `backend/holder)` | sampah CLI | 699 | output error PsySH history-permission (2026-11-01; idem BL-6) | DELETED |
| `frontend/src/api/cms.ts.bak-14480520-092313` | backup duplikat | 2.296 | versi lama (90 baris, lebih tua) dari `frontend/src/api/cms.ts` resmi (132 baris, tracked, lebih baru) — digantikan versi resmi | DELETED |
| `tools/` | direktori kosong | 0 | tanpa isi, tanpa jejak Git | DELETED |

### E.2 Ringkasan `artifacts/` (isi Lampiran A)

| Kelompok | File | Byte | Keterangan |
|---|---|---|---|
| visual-QA `.png` (dashboard/page/public, 375–1440px, 11 role) | 112 | 34.638.942 | bukti QA visual rilis tertutup |
| visual-QA `.json` (results-initial/final/closure/blocker-closure) | 5 | 204.176 | hasil QA visual selesai |
| visual-QA `backend/frontend(.error).log` | 4 | 704 | log sesi QA lama |
| bundel `.tgz` (pc-backend/pc-dist/pc-frontend-dist per rilis) | 6 | 10.828.849 | paket deploy regenerable |
| skrip `.sh` deploy/backup/verify sekali-pakai | 10 | 20.214 | REL003/REL004 selesai & tercatat |
| `sheets-av-{preflight,dryrun,activate}.php` | 3 | 10.733 | skrip tinker sekali-pakai aktivasi Sheets |
| `backend-dev.log` / `backend-dev-error.log` | 2 | 94 | **KEEP (log aktif, ditahan server dev PID 11380)** |

Total dihapus: **155 file + 1 direktori kosong = 41.698.639 byte (≈ 39,8 MB)**.

## F. Proteksi

| File | Status |
|---|---|
| `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` | modifikasi Owner belum di-commit — **dipertahankan, tidak di-stage**, tidak disentuh |
| `artifacts/backend-dev.log`, `artifacts/backend-dev-error.log` | log **aktif** (proses `php artisan serve` PID 11380 menahan) — dipertahankan & di-ignore |
| `design/` (8 file) | referensi lokal — dipertahankan & di-ignore |
| `frontend/pnpm-lock.yaml` | stray legacy — dipertahankan sesuai keputusan Owner G-14, di-ignore eksplisit |
| `kmsit-routing-smoketest.local.txt` | bukti smoketest lokal — dipertahankan & di-ignore |
| `backend/storage/framework/testing/runs/` (168 file) | bukti uji mesin (summary/junit/stats) — tidak disentuh (ignored) |
| `.env*`, service-account, sessions, cache, `.vscode/settings.json` | tidak disentuh (ignored, sesuai aturan lama) |
| Semua file tracked | tidak ada `git rm --cached`; tidak ada yang di-untrack |

File ambigu yang dipertahankan (REVIEW REQUIRED): tidak ada — seluruh klasifikasi dapat diputuskan dari bukti (checkpoint Owner BL-6, tanggal file, perbandingan isi, catatan rilis di Git).

## G. Validasi

| Pemeriksaan | Hasil |
|---|---|
| `git status --porcelain` | hanya ` M .gitignore`, ` M docs/18-*` (baru), 3 dokumen kanonis diperbarui (01/14/15), dan ` M docs/PRIMECLASSY-WA-MGMT…` (Owner, tidak di-stage) — **nol untracked tersisa** |
| `git diff --check` | bersih |
| `git check-ignore -v design/README.md artifacts/backend-dev.log frontend/pnpm-lock.yaml kmsit-routing-smoketest.local.txt` | keempatnya match aturan baru (`.gitignore:41/46/61/55`) |
| Perbandingan file tracked sebelum/sesudah | identik (tidak ada file tracked berubah/dihapus) |
| Manifest ↔ file terhapus | Lampiran A cross-checked terhadap inventaris pra-hapus (146 entri; 2 KEEP) |
| File KEEP & IGNORE masih ada | ✅ (kolom terakhir §D) |
| Source code / runtime / production | tidak berubah; tidak ada akses VPS; tidak ada test suite dijalankan |
| Koreksi terkait | [14-TESTING-STATUS](14-TESTING-STATUS.md) §5 diperbarui dengan angka run penuh otoritatif terakhir (2026-11-01: 1.721 test / 12.702 assertion / 0 failure — run `5ca38852`, bukti mesin tetap di `runs/`); [01-PROJECT-OVERVIEW](01-PROJECT-OVERVIEW.md) §3 & [15-KNOWN-LIMITATIONS](15-KNOWN-LIMITATIONS.md) §5 diperbarui ke kondisi pasca-cleanup |

---

## Lampiran A — Manifest lengkap `artifacts/` (146 entri; path relatif | byte)

Status: `DELETED` kecuali dua entri pertama `KEEP-ACTIVE` (log dev aktif, ditahan server dev).

```
artifacts/backend-dev.log|94                                    KEEP-ACTIVE
artifacts/backend-dev-error.log|0                               KEEP-ACTIVE
artifacts/frontend-dev.log|57796                                DELETED
artifacts/frontend-dev-error.log|157060                         DELETED
artifacts/pc-backend-232ff8f.tgz|1867747                        DELETED
artifacts/pc-backend-38cc75d.tgz|1828942                        DELETED
artifacts/pc-backend-419e2e8.tgz|1919397                        DELETED
artifacts/pc-backup-stage.sh|1635                               DELETED
artifacts/pc-deploy-4a.sh|2186                                  DELETED
artifacts/pc-deploy-4a-fix.sh|936                               DELETED
artifacts/pc-deploy-4b.sh|3138                                  DELETED
artifacts/pc-deploy-4b-continue.sh|2567                         DELETED
artifacts/pc-deploy-4b-fix.sh|1108                              DELETED
artifacts/pc-dist-232ff8f.tgz|2060096                           DELETED
artifacts/pc-dist-419e2e8.tgz|2068334                           DELETED
artifacts/pc-frontend-dist-38cc75d.tgz|1125433                  DELETED
artifacts/pc-rel004-backup.sh|1385                              DELETED
artifacts/pc-rel004-continue.sh|1426                            DELETED
artifacts/pc-rel004-deploy.sh|3755                              DELETED
artifacts/pc-rel004-verify.sh|1986                              DELETED
artifacts/sheets-av-activate.php|3570                           DELETED
artifacts/sheets-av-dryrun.php|3981                             DELETED
artifacts/sheets-av-preflight.php|3182                          DELETED
artifacts/visual-qa/app-entry-1440.png|28444                    DELETED
artifacts/visual-qa/backend.log|332                             DELETED
artifacts/visual-qa/backend-error.log|0                         DELETED
artifacts/visual-qa/dashboard-dashboard-admin-admin-1024.png|425421      DELETED
artifacts/visual-qa/dashboard-dashboard-admin-admin-1440.png|389420      DELETED
artifacts/visual-qa/dashboard-dashboard-admin-admin-375.png|211331       DELETED
artifacts/visual-qa/dashboard-dashboard-admin-admin-768.png|316822       DELETED
artifacts/visual-qa/dashboard-dashboard-agen-agen-1024.png|428351        DELETED
artifacts/visual-qa/dashboard-dashboard-agen-agen-1440.png|392575        DELETED
artifacts/visual-qa/dashboard-dashboard-agen-agen-375.png|208378         DELETED
artifacts/visual-qa/dashboard-dashboard-agen-agen-768.png|317257         DELETED
artifacts/visual-qa/dashboard-dashboard-gudang-gudang-1024.png|421065    DELETED
artifacts/visual-qa/dashboard-dashboard-gudang-gudang-1440.png|384257    DELETED
artifacts/visual-qa/dashboard-dashboard-gudang-gudang-375.png|218169     DELETED
artifacts/visual-qa/dashboard-dashboard-gudang-gudang-768.png|324037     DELETED
artifacts/visual-qa/dashboard-dashboard-keuangan-keuangan-1024.png|433072  DELETED
artifacts/visual-qa/dashboard-dashboard-keuangan-keuangan-1440.png|387102  DELETED
artifacts/visual-qa/dashboard-dashboard-keuangan-keuangan-375.png|217428   DELETED
artifacts/visual-qa/dashboard-dashboard-keuangan-keuangan-768.png|325484   DELETED
artifacts/visual-qa/dashboard-dashboard-koordinator-kurir-koordinator-kurir-1024.png|392391   DELETED
artifacts/visual-qa/dashboard-dashboard-koordinator-kurir-koordinator-kurir-1440.png|346413  DELETED
artifacts/visual-qa/dashboard-dashboard-koordinator-kurir-koordinator-kurir-375.png|194669   DELETED
artifacts/visual-qa/dashboard-dashboard-koordinator-kurir-koordinator-kurir-768.png|296569   DELETED
artifacts/visual-qa/dashboard-dashboard-korsal-korsal-1024.png|405567     DELETED
artifacts/visual-qa/dashboard-dashboard-korsal-korsal-1440.png|365441     DELETED
artifacts/visual-qa/dashboard-dashboard-korsal-korsal-375.png|197860      DELETED
artifacts/visual-qa/dashboard-dashboard-korsal-korsal-768.png|301746      DELETED
artifacts/visual-qa/dashboard-dashboard-kurir-kurir-1024.png|364130       DELETED
artifacts/visual-qa/dashboard-dashboard-kurir-kurir-1440.png|320001       DELETED
artifacts/visual-qa/dashboard-dashboard-kurir-kurir-375.png|184327        DELETED
artifacts/visual-qa/dashboard-dashboard-kurir-kurir-768.png|276350        DELETED
artifacts/visual-qa/dashboard-dashboard-sales-kurir-sub-sales-kurir-sub-1024.png|427464  DELETED
artifacts/visual-qa/dashboard-dashboard-sales-kurir-sub-sales-kurir-sub-1440.png|391952 DELETED
artifacts/visual-qa/dashboard-dashboard-sales-kurir-sub-sales-kurir-sub-375.png|212213   DELETED
artifacts/visual-qa/dashboard-dashboard-sales-kurir-sub-sales-kurir-sub-768.png|320830   DELETED
artifacts/visual-qa/dashboard-dashboard-sales-sales-1024.png|403164       DELETED
artifacts/visual-qa/dashboard-dashboard-sales-sales-1440.png|363457       DELETED
artifacts/visual-qa/dashboard-dashboard-sales-sales-375.png|202489        DELETED
artifacts/visual-qa/dashboard-dashboard-sales-sales-768.png|303554        DELETED
artifacts/visual-qa/dashboard-dashboard-super_admin-super_admin-1024.png|438648   DELETED
artifacts/visual-qa/dashboard-dashboard-super_admin-super_admin-1440.png|395863  DELETED
artifacts/visual-qa/dashboard-dashboard-super_admin-super_admin-375.png|216828    DELETED
artifacts/visual-qa/dashboard-dashboard-super_admin-super_admin-768.png|323925     DELETED
artifacts/visual-qa/frontend.log|372                            DELETED
artifacts/visual-qa/frontend-error.log|0                        DELETED
artifacts/visual-qa/mockdesign-1440.png|433541                  DELETED
artifacts/visual-qa/page-adminadditionalpaymentsview-admin-1440.png|104917        DELETED
artifacts/visual-qa/page-admingoogleauthsettingsview-super_admin-1440.png|302994   DELETED
artifacts/visual-qa/page-adminrefundsview-keuangan-1440.png|92482                  DELETED
artifacts/visual-qa/page-adminreturnsview-admin-1440.png|102575                    DELETED
artifacts/visual-qa/page-adminshippingsettingsview-super_admin-1440.png|272340      DELETED
artifacts/visual-qa/page-agentcontactsview-super_admin-1440.png|158085             DELETED
artifacts/visual-qa/page-agentgoogleauthsettingsview-agen-1440.png|282415          DELETED
artifacts/visual-qa/page-agentpaymentmethodsview-agen-1440.png|217152              DELETED
artifacts/visual-qa/page-agentshippingprovidersview-agen-1440.png|447765           DELETED
artifacts/visual-qa/page-agentstoreprofileview-agen-1440.png|183590                DELETED
artifacts/visual-qa/page-auditlogview-super_admin-1440.png|258465                   DELETED
artifacts/visual-qa/page-cmsarticleformview-super_admin-1440.png|261631             DELETED
artifacts/visual-qa/page-cmsarticlesview-super_admin-1440.png|115326                DELETED
artifacts/visual-qa/page-cmshomepageblockformview-super_admin-1440.png|171776       DELETED
artifacts/visual-qa/page-cmshomepageblocksview-super_admin-1440.png|213796          DELETED
artifacts/visual-qa/page-cmspageformview-super_admin-1440.png|223392                DELETED
artifacts/visual-qa/page-cmspagesview-super_admin-1440.png|115047                   DELETED
artifacts/visual-qa/page-commissionsview-sales-1440.png|147356                     DELETED
artifacts/visual-qa/page-courierreportview-super_admin-1440.png|166563              DELETED
artifacts/visual-qa/page-customersreportview-super_admin-1440.png|161405            DELETED
artifacts/visual-qa/page-dashboardhomeview-super_admin-1440.png|406005              DELETED
artifacts/visual-qa/page-dispatchview-koordinator-kurir-1440.png|189875             DELETED
artifacts/visual-qa/page-financeview-keuangan-1440.png|182521                      DELETED
artifacts/visual-qa/page-fulfillmentproposalsview-admin-1440.png|147018             DELETED
artifacts/visual-qa/page-googlesheetsview-super_admin-1440.png|275418               DELETED
artifacts/visual-qa/page-invoiceconfigview-agen-1440.png|190213                     DELETED
artifacts/visual-qa/page-korsalreportview-super_admin-1440.png|166195                DELETED
artifacts/visual-qa/page-kurirdashboardview-kurir-1440.png|86173                    DELETED
artifacts/visual-qa/page-languagesview-super_admin-1440.png|127799                  DELETED
artifacts/visual-qa/page-medialibraryview-super_admin-1440.png|201338               DELETED
artifacts/visual-qa/page-orderdiprosesview-gudang-1440.png|154422                   DELETED
artifacts/visual-qa/page-orderreportview-super_admin-1440.png|209035                DELETED
artifacts/visual-qa/page-productformview-super_admin-1440.png|321552                DELETED
artifacts/visual-qa/page-productmanagementview-super_admin-1440.png|180749          DELETED
artifacts/visual-qa/page-promomanagementview-super_admin-1440.png|204988            DELETED
artifacts/visual-qa/page-reportsview-super_admin-1440.png|212466                    DELETED
artifacts/visual-qa/page-salescustomersreportview-sales-1440.png|175246             DELETED
artifacts/visual-qa/page-salesreportview-super_admin-1440.png|170294                 DELETED
artifacts/visual-qa/page-stockmanagementview-super_admin-1440.png|109825            DELETED
artifacts/visual-qa/page-substockapprovalsview-admin-1440.png|111069                DELETED
artifacts/visual-qa/page-substockexecutionview-gudang-1440.png|100025               DELETED
artifacts/visual-qa/page-substockview-sales-kurir-sub-1440.png|167239               DELETED
artifacts/visual-qa/page-substockview-sales-kurir-sub-1440-final.png|189725         DELETED
artifacts/visual-qa/page-substockview-sales-kurir-sub-375-final.png|84245           DELETED
artifacts/visual-qa/page-substockview-sales-kurir-sub-768-final.png|104220          DELETED
artifacts/visual-qa/page-usermanagementview-super_admin-1440.png|396387             DELETED
artifacts/visual-qa/page-warehouseopnamesview-gudang-1440.png|209967                DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-1440.png|101197                DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-1440-closure.png|131962        DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-1440-empty.png|114497          DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-1440-error.png|103509          DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-1440-final.png|98207           DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-375-closure.png|47410          DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-375-empty.png|26330            DELETED
artifacts/visual-qa/page-warehousereturnsview-gudang-375-final.png|19022            DELETED
artifacts/visual-qa/page-warehousestockview-gudang-1440.png|317268                  DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-admin-1440.png|157037       DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-admin-1440-final.png|189282 DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-admin-375-final.png|109133  DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-admin-768-final.png|124698  DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-gudang-1440-final.png|280455 DELETED
artifacts/visual-qa/page-warehousesublocationdetailview-gudang-375-final.png|167946 DELETED
artifacts/visual-qa/page-warehousesublocationsview-admin-1440.png|172258            DELETED
artifacts/visual-qa/page-warehousesublocationsview-admin-1440-final.png|185953      DELETED
artifacts/visual-qa/page-warehousetransfersview-gudang-1440.png|196300              DELETED
artifacts/visual-qa/page-websitesettingsview-super_admin-1440.png|483824            DELETED
artifacts/visual-qa/public-catalog-guest-1440.png|41255                            DELETED
artifacts/visual-qa/public-checkout-guest-1440.png|28915                            DELETED
artifacts/visual-qa/public-consumer-account-guest-1440.png|28444                    DELETED
artifacts/visual-qa/public-home-guest-1440.png|126013                              DELETED
artifacts/visual-qa/public-login-guest-1440.png|28444                              DELETED
artifacts/visual-qa/public-register-guest-1440.png|32848                           DELETED
artifacts/visual-qa/results-blocker-closure-empty.json|1686                        DELETED
artifacts/visual-qa/results-blocker-closure-main.json|3296                         DELETED
artifacts/visual-qa/results-closure.json|24355                                    DELETED
artifacts/visual-qa/results-final.json|54942                                      DELETED
artifacts/visual-qa/results-initial.json|119897                                   DELETED
```
