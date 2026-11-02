# 15 — Known Limitations & Gap Ledger

Keterbatasan, gap, dan item yang disetujui-desain-tapi-belum-diimplementasikan, diteruskan dari gap ledger historis (`G-xx`) dan diverifikasi terhadap source saat reset dokumentasi (2026-11-02). Item CLOSED dipertahankan ringkas untuk konteks.

---

## 1. NOT IMPLEMENTED / PENDING (disetujui sebagai desain, belum ada di kode)

| ID | Item | Status | Catatan |
|---|---|---|---|
| OD-05c (non-sales) | Entri matriks adjustment komisi refund selain **fee sales** (courier/agent fee, kategori reason) | PENDING_OWNER_APPROVAL | WS-8 sales-fee sudah implement (§15a [05-BUSINESS-RULES](05-BUSINESS-RULES.md)); entri lain belum |
| OD-05e | Payout tracking komisi (state `paid` writer) + recovery balance | NOT IMPLEMENTED | Aplikasi tidak melacak komisi yang dibayar di luar sistem; payout view akhir periode butuh kontrak Owner |
| OD-06 / §20b | Format parity SimpleV2 laporan & Google Sheets (43/47 kolom) | APPROVED_DESIGN / NOT IMPLEMENTED | Butuh `EXTERNAL_EVIDENCE_REQUIRED` (workbook asli) sebelum klaim parity |
| OD-06 | Unifikasi konsumen laporan (dataset D13/D17 vs derivasi Laporan A/B) | APPROVED_DESIGN / NOT IMPLEMENTED | Gated rollout reconciliation-first (OD-K5-4) |
| — | Reassignment kepemilikan CRM antar-pemilik, export CRM massal, transfer CRM lintas-Agen | OUT OF SCOPE / NOT IMPLEMENTED | Keputusan Owner: transfer leaf-level audited tetap; transfer massal cabang out-of-scope (G-20) |
| — | Eksekusi retensi WhatsApp / consent / OTP (penghapusan fisik) | NOT IMPLEMENTED (report-only) | Planner + dry-run tersedia; eksekusi butuh approval Owner + backup terverifikasi (G-21/G-24) |
| — | Kolom tanggal-akhir hubungan untuk evidence berbasis akun (retensi consent) | Owner schema decision pending | Tanpa kolom itu baris account-bound tidak di-period-compute (G-21) |
| — | Net-payout / surfacing adjustment komisi di laporan payout | Owner contract pending | Adjustment bukan state `paid` (G-16) |
| — | Payment gateway eksternal & webhook | **DIHAPUS by Owner** (bukan gap) | §13 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |
| — | Guest checkout publik | DITOLAK by design (D-06/D-07) | Assisted order hanya oleh seller berwenang |

## 2. Item DEV vs PRODUCTION (butuh langkah Owner sebelum produksi)

| Item | Status |
|---|---|
| Package C (SC-03) + migrasi `2026_10_02_100000/110000` | Diimplementasi; DEV UAT PASS, stage gate CLOSED; **deployment & migrasi produksi PENDING** (additive, nullable) |
| AR kanonis `CollectibleReceivable` (G-15/KF-1..3) | IMPLEMENTED di source (DEV); **rollout produksi butuh sign-off Keuangan + approval Owner**; laporan rekonsiliasi read-only tersedia |
| Migrasi REL005/REL006 di produksi | Rilis ter-deploy (laporan Owner); verifikasi `migrate:status` pada preflight berikutnya |

## 3. Keterbatasan perilaku (by design — bukan defek)

- Laporan A hanya memuat order setelah snapshot aktif (order lama tidak direkonstruksi; tidak ada nilai dikarang).
- Event WhatsApp/email **default OFF** — tidak ada event aktif hanya karena terdaftar di kode; aktivasi = keputusan runtime per Agen + global.
- Order CRM-origin tidak menerima WA/email (penerima tidak terverifikasi); In-app tidak terpengaruh.
- Bukti pembayaran legacy tetap di disk publik dengan URL lama (tanpa migrasi massal).
- Verifikasi `unknown` WA tidak pernah di-retry otomatis (risiko duplikat > keterlambatan).
- OTP tidak pernah dikirim ulang (challenge baru harus diterbitkan).
- Fulfilment tidak pernah digerbangi pembayaran setelah `diproses` (piutang tetap terbuka).
- Kurir/Gudang menerima proyeksi operasional tanpa data finansial.
- Overdue due date hanya flag tampilan (tidak memblokir).
- KMSIT routing coverage Bandung Raya (bbox kontrak); di luar itu fail-closed 422.
- Retensi: `whatsapp_messages` 90 hari (minimize belum dieksekusi), OTP 30 hari, consent aktif+5 tahun — kontrak, bukan jadwal aktif.
- Laporan A memakai `ROW_NUMBER()` → butuh MariaDB ≥ 10.2 / MySQL ≥ 8.0.
- Admin tidak melihat fee agen; network-summary tanpa Admin (by design).
- E2E browser In-App notification & ledger `payment_proof_uploaded` di produksi belum dikonfirmasi (status NOT VERIFIED runtime; kode terverifikasi).
- Email Keuangan ID 18 sebelumnya belum terverifikasi — jangan dianggap PASS tanpa bukti baru (riwayat laporan Owner REL006).

## 4. Item CLOSED (konteks — tidak perlu tindakan)

| ID | Item | Penyelesaian |
|---|---|---|
| G-01 | Full suite pernah gagal saat tree diedit bersamaan | Disiplin runner tunggal (lock MySQL) mengatasi; full suite 855/0 berikutnya |
| G-02/G-04/G-05/G-06 | Tes REM/Sheets/Refund ledger terverifikasi PASS scoped | Selesai 2026-10-28..30 |
| G-07 / AU-01 | Registrasi tanpa Agen aktif → 503 | **FIXED (REM-004, 2026-10-29)** — `AuthController` 503 + `registration_unavailable` (diverifikasi source) |
| G-08 | CRM slice 1+2 (customer/visit/follow-up/assisted/linking/consent) | IMPLEMENTED; DEV migrated 2026-10-30; browser QA PASS |
| G-09 | Penomoran IMP/REM | Policy resolved: urutan global; IMP-001..007 frozen; IMP-008 next |
| G-11 | i18n paymentGateway sisa | Key dihapus dari 4 locale |
| G-12 | `gateway_reference` `MT-/DP-/AP-/ST-` untuk transaksi manual | Naming-only; opsional rename |
| G-13 | SKS self-purchase historis | FINAL: tanpa backfill; laporan read-only `self-purchase:attribution-report` (DEV: 0 mismatch) |
| G-14 | pnpm lockfile | FINAL: npm standar; `pnpm-lock.yaml` stray dibiarkan |
| G-16 | Adjustment fee sales refund (WS-8) | IMPLEMENTED 2026-10-30 (lihat §15a) |
| G-17 | Buyer-origin CRM assisted checkout | IMPLEMENTED; DEV migrated + reconciled; browser QA PASS |
| G-18/G-19 | Consent OTP + verified email + link/unlink | IMPLEMENTED (REM-005, 2026-10-30) |
| G-20 | Batas transfer lintas-Agen | CLOSED: transfer leaf audited only |
| G-21 | Retensi consent (CI-06) | POLICY IMPLEMENTED read-only |
| G-22 | FU/D | DECIDED: FU=Follow Up, D=Deal(alias 7); filter saja |
| G-23 | Gerbang otoritas retur/refund | CONFIRMED_SOURCE (gate untuk refinemen SoD masa depan) |
| G-24 | WhatsApp Gateway (GOWA) | IMPLEMENTED; DEV migrated; produksi REL005+; real E2E per laporan Owner REL006: WA & email terverifikasi PASS, In-app E2E belum |

## 5. Keterbatasan platform/dokumentasi

- Frontend tidak punya test runner sebagai gerbang; verifikasi = type-check + build + browser manual.
- Full suite angka terakhir 855/6075/0 — selalu jalankan ulang untuk angka terkini.
- Artefak lokal telah dibersihkan (2026-11-02); yang tersisa di-disk dan di-ignore: `design/` (referensi lokal), `artifacts/backend-dev*.log` (log server dev aktif), `frontend/pnpm-lock.yaml` (stray G-14), `kmsit-routing-smoketest.local.txt`. Manifest: [18-LOCAL-REPOSITORY-CLEANUP](18-LOCAL-REPOSITORY-CLEANUP.md).
- `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` berisi modifikasi Owner belum di-commit — sengaja tidak disentuh.
