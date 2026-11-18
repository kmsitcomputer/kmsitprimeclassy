# RECON-07 — OWNER DECISION REGISTER

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Keputusan yang sudah locked — tidak dibuka ulang

Authority: instruksi FULL RECON Owner (lampiran sesi 2026-11-11) di atas asumsi existing; docs/05 untuk invariants existing yang tidak diubah. Perbedaan source dilaporkan sebagai gap, bukan diperbaiki.

| Lock ID | Decision | Consequence |
|---|---|---|
| L01 | Scope hanya REM-01…11; read-only dan stop laporan | Tidak coding/DB mutation/migration/test suite/transaksi/commit/push/deploy |
| L02 | REM-04 Opsi A hard delete | Hanya tanpa dependency kritis; otherwise block + inactive; bukan soft-delete substitusi; Admin delete tetap dilarang |
| L03 | REM-05 semua/active/draft/inactive, default Semua | Storefront hanya active layak jual; tanpa status tambahan |
| L04 | REM-09 Opsi A SKS dua source | Own Sub dan parent Agent; source di catalogue/cart/checkout; satu order satu source |
| L05 | REM-10 approved invoice design | White/maroon, official logo/wordmark kiri, invoice/date/status kanan; unchanged financial/workflow |
| L06 | REM-11 POS actor | Admin/SuperAdmin sesuai authorization dan Agen saja; bukan Korsal/Sales/SKS/Kurir |
| L07 | REM-11 Agen network | Own Agent Korsal/Sales/SKS; direct Agen referral tanpa Sales palsu |
| L08 | REM-11 consumer optional | Filtered referral, invalidated when changed, API rejects mismatch; no auto referral reassignment |
| L09 | REM-11 requester Opsi A | Required account existing atau manual; no auto login; distinct consumer/recipient |
| L10 | REM-11 source Opsi B | POS Agen own Agent stock only; no Sub/foreign Agent/automatic fallback |
| L11 | REM-11 pricing/payment | System price+server totals; discount existing permissions; valid payment truth; no automatic COD paid |
| L12 | REM-11 shipping | planned date/status/courier covered; existing dispatch authority/state machine; date bukan shipped proof |
| L13 | REM-11 one order | Auto List Order sesuai scope; creator bukan personal seller; no double fee/order ledger |
| L14 | REM-11 design | Modern Dark Edition one page, no sidebar/dashboard, two columns; tokens existing maroon |
| L15 | REM-11 architectural | No new POS table; reuse order/inventory/payment/referral/invoice/audit/RBAC; column proposals only if evidence |
| L16 | Preserved A/B/C invariants | 11 roles, stock ordering/source, partial fulfilment, self_sub, verification, Order-level payment truth, SC-03 Admin-only |

## Lima keputusan baru yang benar-benar diperlukan

Semua rekomendasi berikut bersifat teknis, bukan keputusan final. Kelengkapan bisnis yang sudah locked tidak ditawarkan ulang.

| Decision ID | Question/scope | Evidence | Options | Impact | Technical recommendation (not Owner decision) | Status |
|---|---|---|---|---|---|---|
| ODR-01 | REM-11 POS Admin stock ownership/source | EV-11-001/006; source Agent selalu buyer agent existing; Admin branch scoped, SuperAdmin global | A: Admin fixed own branch Agent; SuperAdmin pilih Agen yang valid. B: explicit approved branch selector sesuai scope (Admin tidak otomatis cross-Agent). | A lebih sederhana, B memerlukan selector dan scope validation; keduanya tidak membuat Sub/fallback diam-diam | A untuk Admin branch; SuperAdmin explicit selected Agent | Open — Owner menetapkan mekanisme source Admin, bukan memperluas Agen POS |
| ODR-02 | REM-11 requester identity retention dan relasi optional consumer | EV-11-002/003; origin XOR account/CRM sekarang; required requester account/manual locked | A: aditif requester account/manual snapshot + optional consumer relation pada orders tanpa CRM wajib. B: reuse buyer-origin fields dengan perubahan kontrak readers dan relasi consumer aditif yang jelas. | A lebih eksplisit; B berisiko conflating historical buyer. Manual tidak boleh wajib dibuat akun atau dimasukkan CRM tanpa keputusan. Idempotency scope harus non-null efektif | A; nullable additions menjaga data lama, writer/request validation baru terpisah | Open — menyetujui kontrak identity/storage/visibility, bukan mempertanyakan optional consumer/manual requester |
| ODR-03 | REM-01 duplicate/update dan stock import policy | EV-01-002; stock per Agen/Sub dan reservations existing | A: preview membedakan create/update by global SKU; no overwrite stock existing, stock snapshot export saja sampai stock write explicit. B: permitted stock update melalui existing inventory adjustment/request approval dengan owner+reason, tidak direct ledger overwrite. | A lebih kecil tapi stock-write requirement perlu batas resmi; B perlu permission/approval dan conservation reserved. Pilih juga reject-vs-update duplicate, batch atomic-vs-row atomic, format ID/path category dan batas row/file | A tahap catalogue + keputusan stock operation explicit; tidak klaim stock import compliant sebelum policy lengkap | Open — requirement stok tetap dalam scope, semantik penulisannya belum dikunci |
| ODR-04 | REM-07 valid net-sale ranking/time/scope definition | EV-07-003/004; returned_quantity dimulai request, refunded kemudian; financial dan operational metrics berbeda | A: units delivered dan eligible payment state dikurangi approved/processed physical returns sekali. B: units fulfilled/committed yang eligible dikurangi canonical cancellation/return stage. Tentukan apakah DP/COD unpaid termasuk, event date (created/delivered/verified), WIB window, global vs viewer-Agent scope, dan restatement refunds di luar periode. | A lagging actual delivery; B memasukkan commitment yang belum delivered. Tidak satu pun ditetapkan recon. Jangan SUM paid status atau item rows dengan shipment fanout | Utamakan actual delivered net dengan tahap return/refund disepakati, namun finance eligibility dan date harus Owner pilih | Open — bukan mengubah “actual net sales” menjadi views/random/manual |
| ODR-05 | REM-11 input tanggal transaksi dan konsekuensi historical pricing/reporting | EV-11-004; created_at adalah commit timestamp; tidak ada business-date input meaningful | A: business transaction date tersendiri, default hari ini WIB, backdate dibatasi role/range. B: tanggal input hanya hari berjalan dengan aturan eksplisit, tetap simpan semantik terpisah bila dibutuhkan. | Backdate memengaruhi report windows dan apakah harga memakai katalog saat commit atau effective date; jangan backdate invoice/paid_at atau snapshots otomatis | Business-date terpisah, harga server saat commit kecuali Owner menentukan lain; batas backdate eksplisit | Open — tanggal POS tetap tercakup, tidak menghilangkan field locked |

## Bukti/aset yang masih diperlukan — bukan keputusan ulang

| ID | Evidence needed | Reason / status | Safe next evidence |
|---|---|---|---|
| ODR-E1 | REM-03 representative product dengan gambar di local + read-only view/payload | BLOCKED audit reproduction; DB lokal images=0, EV-DB-001 | Owner menunjuk local representative dataset/product yang sudah ada dan sanitized payload admin/storefront; jangan upload/create real transaction untuk recon |
| ODR-E2 | Approved invoice mockup terakhir | BLOCKED visual parity; dashboard general bukan invoice approval | Owner menyediakan referensi approved; pakai official bundled logo, tidak gambar substitusi |
| ODR-E3 | Approved POS Modern Dark Edition mockup terakhir | BLOCKED visual parity; tokens existing sudah ada, layout locked tetap | Owner menyediakan referensi spesifik; layout dua kolom/no sidebar dan maroon tetap |
| ENV-PROD | Jika perlu bukti kasus hanya ada production | BLOCKED — PRODUCTION EVIDENCE REQUIRES OWNER AUTHORIZATION | Tidak ada akses production diminta/dijalankan otomatis; cukup local evidence dahulu |

## Evidence gaps yang tidak perlu dijadikan keputusan bisnis

Concurrent runtime verification, actual browser/PDF/print parity, duplicate file references, volume/query plans, complete historical polymorphic deletion dependencies harus dibuktikan pada tahap scoped implementation validation atau local read-only representative audit; bukan mengarang kebijakan baru. Current safe report tidak menyatakan test PASS atau files hilang.

## Final compliance

| Check | Result |
|---|---|
| 11 pekerjaan diperiksa; semua 117 unit requirement berstatus | Ya — RECON-04: 97 verified,15 partial,5 blocked; 0 not started; coverage82.9% |
| Temuan evidence-backed atau unknown terpisah | Ya — 13 GAP IDs; 4 evidence/feasibility hypotheses terpisah |
| Owner rules/design locks dipertahankan | Ya — proposal tidak final; missing mockup dinyatakan |
| POS maroon dua kolom/no dashboard/sidebar | Dipertahankan dalam proposal; actual UI belum ada |
| POS hanya Admin/Agen; own Agent stock | Dipertahankan; source Admin pending ODR-01 |
| SKS dua source/satu order satu source | Dipertahankan terpisah POS; frontend gap dilaporkan |
| Consumer optional/manual requester/no auto login | Dipertahankan; identity gap/ODR-02 jelas |
| Referral/List Order/fee/history/shipping/payment | Dipetakan RECON-02/03/06; no parallel truth |
| No POS new table | 0 tabel dibuat/diusulkan; complete feasibility partial sampai ODR-01/02/05 |
| No source/environment/data mutation | Hanya tujuh dokumen laporan baru; source dan existing Owner checkpoint tidak diubah |
| No full/scoped test/build/transaction/migration | 0 run; read-only metadata/aggregate SELECT only |
| No production/commit/push/merge/PR/deploy | Tidak dilakukan |
| Architecture Lock belum final | Ya — next only OWNER REVIEW → ARCHITECTURE LOCK |

## Penutupan sesi

**Verdict: RECON PARTIAL — EVIDENCE GAPS REMAIN.** Tujuh dokumen selesai sebagai laporan audit transparan; hasil partial berkaitan bukti dan keputusan di atas, bukan instruksi melanjutkan coding. Tidak membuat prompt implementasi atau remediation otomatis. Next recommended stage hanya **OWNER REVIEW → ARCHITECTURE LOCK**.
