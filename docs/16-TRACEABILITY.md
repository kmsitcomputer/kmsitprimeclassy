# 16 — Traceability (Register Keputusan)

Register keputusan bisnis/arsitektur Owner yang mengikat implementasi. Detail keputusan lengkap tersimpan di Git history (dokumen sumber historis dihapus dari working tree sesuai konvensi repo — `git log -- docs/`). Aturan terkunci lengkap: [05-BUSINESS-RULES](05-BUSINESS-RULES.md).

---

## 1. Keputusan peran & otoritas

| ID/Tanggal | Keputusan | Dampak |
|---|---|---|
| 2026-09 (seed) | 10 role awal + rename | `RoleSeeder` |
| 2026-09-22 | Role `keuangan` ditambah | Migrasi `2026_09_22_090000` |
| 2026-09-29 (R-01) | `sales-kurir` → `sales-kurir-sub` in-place | Migrasi `2026_09_29_090000`; prefix `SS-` |
| 2026-10-05 (IMP-003) | Role ke-11 `koordinator-kurir` (dispatcher cabang) | Migrasi `2026_10_05_100004` |
| Human (UAT) | Model unit kerja kurir per `OrderItem`; shipment = wadah assignment | §11 LOCKED |
| A1-01/A1-02/A1-03 (2026-10-14) | KK executor-tercatat; KK boleh membuat kurir; assignment idempotent | §12 |
| CR-SALES-KURIR-SUB-DELIVERY-001 (2026-10-14) | Self-delivery SKS + CR-001 standard delivery oleh SKS eligible | §12 |
| Human (supersede UAT-008) | korsal/sales/SKS hanya submit bukti — verifikasi tetap keuangan/super_admin | §13/§24 |
| 2026-10-30 | Korsal tanpa fee role; komisi tim = sales fee per-penerima; KPI contract | §28a |

## 2. Keputusan stok & fulfilment

| ID/Tanggal | Keputusan |
|---|---|
| 2026-10-07 (Human final) | Shipment grouping key kanonis; reschedule hanya KURIR ONLINE (`openroute`) & `self_sub` (§21) |
| IMP-001 Gap 2 | Fitur "Stock Request" user-facing dihapus → antrian "Order Diproses" Gudang (§16) |
| SC-03 (2026-10-06) | Add-line Admin-only; dilarang setelah ada executor (§22) |
| R-02/R-03/R-04 | Sub stock request chain; split lineage; Admin tanpa delete produk (§5) |
| L-003 | Warehouse-authoritative — legacy `/stock/adjust` 403 |

## 3. Keputusan finansial

| ID/Tanggal | Keputusan | Status |
|---|---|---|
| 2026-10-22 (Owner mandate) | Refund ledger 360° + snapshot finansial immutable (§26) | IMPLEMENTED |
| OD-K5-1..5 (2026-10-28) | Kontrak AR collectible kanonis `CollectibleReceivable` (M-1/M-2/M-3) | IMPLEMENTED di source (DEV); rollout produksi butuh sign-off Keuangan |
| WS-7 (2026-10-29) | Instruksi Owner: derivasi M-2 di-apply di source dengan header kolom sama | IMPLEMENTED |
| WS-8 (2026-10-30, FINAL) | Fee sales dibatalkan saat refund = Σ(unit direfund × snapshot per-unit); hanya fee sales | IMPLEMENTED (SalesFeeRefundAdjuster) |
| OD-05a..g (2026-10-28) | Framework adjustment komisi refund | Framework approved; entri non-sales pending |
| 2026-10-31 (Master V2 / §29) | Metode per role (konsumen transfer saja), cicilan/kas, invoice = `order_no` terbit di pembayaran terverifikasi pertama, due date + audit, bukti disk privat, batas upload | IMPLEMENTED |
| 2026-10-21 (§20a LOCKED) | Laporan A (immutable) & B (live) 24/15 kolom tanpa "Posisi Uang" | IMPLEMENTED |
| Owner | Gateway pembayaran eksternal dihapus | IMPLEMENTED (removal) |

## 4. Keputusan CRM, consent & identitas

| ID/Tanggal | Keputusan | Status |
|---|---|---|
| OD-01..OD-06 (2026-10-28) | CRM ownership, read Agen/Admin, assisted order, OD-02 inisiasi retur, OD-03 linking OTP, OD-04 unlink, OD-05/06 | IMP-006 slice 1+2 IMPLEMENTED (DEV migrated) |
| CI-01..CI-07 (2026-10-28; final 2026-10-30) | OTP + email terverifikasi; bukti consent; scope capture; withdraw/dispute; anti lintas-Agen; retensi 5 tahun; FU/D | CI-01..CI-05, CI-07 IMPLEMENTED; CI-06 read-only |
| G17-D1..D4 (2026-10-31) | Assisted order hanya owner chain; consent OTP wajib; unlink audited | IMPLEMENTED; browser QA PASS |
| IMP-001 (§23) | Google sign-in = identity provider; registrasi Google wajib referral; tanpa auto-link unverified | IMPLEMENTED |
| 2026-10-30 | FU = Follow Up; D = Deal (alias visit status 7) | IMPLEMENTED (filter) |

## 5. Keputusan notifikasi & WhatsApp

| ID/Tanggal | Keputusan | Status |
|---|---|---|
| 2026-10-31 (Owner-directed) | Arsitektur notifikasi 3 kanal; purpose terpisah; default OFF; nomor ber-proof; idempotency; retensi kontrak | REL005 IMPLEMENTED & produksi |
| WA-DEC-01..05 (2026-11-04) | Manajemen WA per Agen oleh Agen/Admin Agen; Super Admin global saja; scoped settings override | IMPLEMENTED |
| 2026-10-29 | WhatsApp sebagai kanal OTP alternatif (syarat proof nomor) | IMPLEMENTED (default OFF) |
| 2026-11-02 (Owner rule) | Resipien `payment_proof_uploaded` = hanya Keuangan cabang aktif | REL006 IMPLEMENTED & produksi |
| NTF-030/033/034 | In-app notification center; delivery failure alert; gateway status | IMPLEMENTED |
| 2026-11-02 | Aktivasi queue worker produksi (systemd) | ACTIVE |
| RT-DEC-01..05 (2026-11-04) | KMSIT routing fallback (jarak saja; satu tarif; endpoint guard; cache isolasi; seed nonaktif) | IMPLEMENTED |

## 6. Keputusan pengujian & proses

| ID/Tanggal | Keputusan |
|---|---|
| 2026-11-02 (Owner) | **Full Test bukan gerbang review/rilis**; bukti = scoped tests per area + type-check/build; Full Test diskresi Owner |
| G-09 (2026-10-28) | Penomoran IMP global; IMP-001..007 frozen; IMP-008 next; REM-004 = AU-01 |
| 2026-10-30 (G-13/G-14/G-20) | Tanpa backfill self-purchase; npm standar; tanpa transfer massal CRM |
| Konvensi repo | Git history = arsip; checkpoint historis dihapus dari working tree |
| 2026-10-30 |BLUEPRINT.pdf dihapus Owner (tidak relevan; tidak dipulihkan) |

## 7. Riwayat rilis (ringkas)

| Rilis | Commit | Isi |
|---|---|---|
| REL003 | `232ff8f`/`1f510e6` | Konsolidasi V2 produksi + cache ownership Docker |
| REL004 | `419e2e8` | WA branch management, KMSIT routing fallback, account recovery |
| REL005 | `87ef993` (merge `38fee8f`) | Notification system: WhatsApp, email, in-app, queue worker |
| REL006 | `f8445e1` | `payment_proof_uploaded` → Keuangan cabang |
