# 05 — Business Rules (Aturan Bisnis Terkunci)

Aturan bisnis kanonis PrimeClassy V2 — diteruskan dari dokumen `docs/BUSINESS-RULES.md` (reset 2026-11-02) dan diverifikasi terhadap source code. Arsitektur teknis: [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md); peran: [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md); register keputusan: [16-TRACEABILITY](16-TRACEABILITY.md).

> **LOCKED** = invarian yang **tidak boleh diubah developer/agen AI tanpa persetujuan bisnis eksplisit Owner**. Bila source bertentangan dengan aturan LOCKED: laporkan, jangan memihak diam-diam. Baris tanpa tanda = perilaku terimplementasi yang mengikuti aturan terkunci.

---

## §1 Konvensi

- Backend satu-satunya security boundary; frontend menyembunyikan/`PermissionMap`/field request tidak pernah otoritas.
- Uang, harga, fee, SKU, berat, ongkir dan sumber stok **derivasi server**; klien hanya mengirim id & kuantitas.
- Order & item menyimpan **snapshot**; perubahan katalog/fee/nama berikutnya tidak pernah menulis ulang (kecuali nama aktor: nama terkini dari ID stabil, tanpa snapshot nama).
- Kosakata status: order/item `diterima, diproses, dikirim, terkirim, pengembalian, kembali, dibatalkan` (transisi legal hanya tabel `Order::TRANSITIONS`); dokumen gudang snake_case Inggris.

## §2 Matriks role & otoritas

**LOCKED: tepat 11 role** (`super_admin, agen, admin, keuangan, gudang, kurir, koordinator-kurir, korsal, sales, sales-kurir-sub, konsumen`). `sales-kurir` di-rename in-place menjadi `sales-kurir-sub` (slug lama hanya alias kompatibilitas). IMP-003 menambah role ke-11 `koordinator-kurir` (Human-approved 2026-10-05).

Tabel lengkap posisi/pembuat/scope: [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md) §1. Siapa-membuat-siapa: **LOCKED** (`HierarchyRules`) — super_admin→agen; agen→{korsal, sales, admin, keuangan, kurir, gudang, sales-kurir-sub, koordinator-kurir}; korsal→{sales, sales-kurir-sub}; koordinator-kurir→kurir (Agen sama). Tidak ada pembuat lain.

Matriks "aksi → role" lengkap (verifikasi, fulfilment, retur, stok, laporan, WA): lihat [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md) §3 — semua baris di sana enforce route **dan** policy/service.

## §3 Hierarki Agen & pembuatan akun

- `agen` adalah cabangnya sendiri (`agent_id = id`); setiap user non-super_admin wajib `agent_id` (**LOCKED**).
- Field ownership diturunkan dari aktor, bukan request: Sales/SKS buatan Agen harus di bawah Korsal cabang yang sama; buatan Korsal di bawah Korsal itu.
- Memindahkan Sales antar-Korsal / konsumen antar-seller = reassignment eksplisit ter-audit — tidak otomatis, tidak dikira.
- Hanya **Agen pemilik** boleh mengonversi Sales menjadi Sales-Kurir-Sub (target di jaringan aktor, Korsal valid).

## §4 Aturan referral

- Prefix `AG-`, `KO-`, `SA-`, `SS-`. **LOCKED:** kode historis `SA-`/`SK-` tetap sah dan tidak pernah ditulis ulang; Sales yang dikonversi mempertahankan kode lama (`SS-` hanya bila belum punya); SKS baru mendapat `SS-`.
- konsumen/admin/keuangan/kurir/gudang tidak punya kode. User mengelola kode miliknya sendiri.
- Rantai konsumen di-snapshot saat registrasi; referral langsung Agen/Korsal membiarkan `sales_id`/`korsal_id` kosong.

## §5 Produk & katalog

- Katalog global; stok per Agen. Produk sederhana wajib SKU; produk variasi tanpa SKU induk, tiap variasi wajib SKU. **Semua SKU satu namespace** (`catalog_skus`); SKU produk ≠ SKU variasi dalam satu registry.
- Baris order hanya boleh merujuk produk **aktif**; `has_variations=false` ⇒ target produk (id variasi → 422); `has_variations=true` ⇒ wajib variasi aktif produk itu.
- **LOCKED:** Admin boleh Create/Read/Update produk & variasi tetapi **tidak pernah Delete** (route group + policy).
- Fee per unit untuk `agent`, `sales`, `courier` — dikonfigurasi per produk/variasi dan di-snapshot ke item order (× kuantitas).

## §6 Order & checkout

- Server me-resolve setiap baris; harga/fee/SKU/subtotal/status/stock-source dari klien ditolak. Pembuatan order wajib `Idempotency-Key`.
- COD mulai di `diproses`; metode lain mulai `diterima` dan masuk `diproses` hanya setelah pembayaran terverifikasi (paid atau DP partially paid). Pengiriman ekspedisi hanya Manual Bank Transfer.
- Shipment **tidak** diblok hanya karena order belum lunas setelah `diproses`; gerbang pembayaran berlaku saat memasuki `diproses` untuk non-COD.
- Hanya tabel transisi `Order::TRANSITIONS` yang legal — untuk semua role termasuk `super_admin`.

## §7 Keputusan sumber stok (**LOCKED**)

Diresolve server-side oleh `StockSourceResolver`; `stock_source`/`sub_location_id` dari klien hanya dibandingkan, tidak dipercaya.

| Kasus | Sumber stok |
|---|---|
| SKS beli untuk **dirinya** (Sub dipilih) | Sub stock Sub Location miliknya |
| SKS membuat order untuk **konsumen dalam jaringan referralnya** (Sub dipilih) | Sub stock miliknya |
| Checkout **konsumen referral sendiri** | Stok Agen |
| Sales/Korsal/Agen lain, SKS lain, Agen lain, default | Stok Agen (permintaan Sub ⇒ 403) |
| Sub dipilih tanpa Sub Location aktif yang dimiliki | 422 |
| `sub_location_id` palsu/asing | 403 |

Order sumber campuran dan penambahan SC-03 pada order bersumber Sub ditolak.

## §8 Agent Sellable (**LOCKED**)

`Agent Sellable = Transit + (Factory Plan bila aktif) − Agent Reserved`
- Factory Plan = pasokan committed/planned, hanya dipakai saat toggle Admin aktif; bukan sumber fulfilment fisik.
- Stok Sub **tidak** ditambah/dikurangkan ke sellable Agen. Transit → Sub adalah pergerakan fisik antar domain.
- Target tanpa baris warehouse fallback ke legacy `quantity_on_hand − quantity_reserved`.

## §9 Sub Sellable & kepemilikan Sub (**LOCKED**)

`Sub Sellable = Sub Physical − Sub Reserved aktif`
- 1 user SKS ≤ 1 Sub Location aktif (`owner_user_id` UNIQUE). Pemilik harus aktif, SKS, Agen sama. Hanya `SubLocationOwnershipService` yang menulis kepemilikan.
- Tidak ada reassignment senyap; lokasi legacy tanpa pemilik tetap tak berpemilik sampai Agen/Admin assign. Deaktivasi melepas pemilik (audit `previous_owner_user_id`) dan diblok bila masih ada stok.
- Gudang tidak bisa membuat Sub Location atau assign pemilik. Sub Location milik seseorang hanya berpindah via Sub stock request, bukan transfer generik.
- Stok fisik Sub tidak pernah dikurangi di bawah reservasi aktif (retur, transfer, adjustment, opname).

## §10 Siklus reservasi & pergerakan stok (**LOCKED**)

- **Reserve** saat buat order / naik kuantitas / SC-03: Agen → `quantity_reserved += qty` (fisik tidak berubah); Sub → satu baris `sub_stock_reservations` per item order.
- **Pergerakan fisik (Agen):** serah terima Gudang yang **dikonfirmasi penerimaannya oleh Koordinator Kurir** (bukan approval Admin — Keputusan Owner 2026-11-11; approval Admin hanya jalur pengecualian legacy) memindahkan `transit −q`, `shipping +q`, `reserved −q` atomik; unit yang sama tidak pernah dua bucket.
- **Shipment (Sub):** shipment pemilik mengonsumsi reservasi Sub dan mengurangi fisik Sub sekali (movement `out`).
- Pembatalan/pengurangan pra-shipment melepas reservasi, tidak menciptakan stok fisik. Pasca-fulfilment, pembatalan membalik Shipping → Transit sekali (`cancellation_release`); barang terkirim hanya kembali via alur retur.
- Reserve/consume/release idempotent per item; setiap movement menulis baris `stock_movements` immutable dengan aktor + referensi.
- Aturan bucket: `shipping` berubah hanya via fulfilment ter-approve, pembatalan, atau pembalikan retur; `sales` derived (tidak disimpan/diedit); `factory_plan` ditulis hanya saat aktif (via request ter-approve Admin).

## §11 Siklus shipment & model unit kerja (**LOCKED**, Human 2026-10-07)

Shipment **bukan** per OrderItem. Satu Shipment kanonis mewakili pekerjaan pengiriman yang kompatibel dari satu **grouping key**: `Order + requested_delivery_date + delivery_mode kompatibel + pemilik kompatibel (SKS untuk self_sub)`. Item yang berbagi key **harus** konvergen ke SATU Shipment kanonis; tidak ada jalur yang boleh mencetak Shipment same-key kedua. Tanggal beda ⇒ shipment beda; `self_sub` tidak pernah merge dengan `standard`; dua pemilik Sub tidak merge. Semua jalur (checkout, reschedule utuh, split parsial, SC-03) konvergen lewat `ShipmentCanonicalizationService`.

Grain per concern (**LOCKED**):

| Concern | Unit kerja |
|---|---|
| Proposal fulfilment / antrian gudang | per `OrderItem` |
| Progres pickup/delivery kurir | per `OrderItem` |
| Dispatch workspace & assignment kurir | per Shipment kanonis |
| Resi termal | per Shipment kanonis |
| Status `shipment`/`shipped_at`/`delivered_at` | agregat turunan atas item shipment itu sendiri |

- Shipment lahir `standard`, `pending`, tanpa kurir; provider/rute/rate/berat snapshot immutable; snapshot ongkir jatuh di tepat satu shipment kanonis.
- **Progres kurir PER `OrderItem`** — memproses satu item tidak pernah menggerakkan sibling, meski satu order/shipment/tanggal/kurir. Shipment tetap wadah assignment/delivery (otorisasi executor, bukti, agregat); jalur bulk office per-shipment & per-order tetap tersedia untuk `agen`/`admin`/`super_admin`.
- Status/`shipped_at`/`delivered_at` shipment = **agregat turunan**: `delivered` hanya bila SEMUA item sampai; tidak pernah mundur setelah delivered. Shipment terkirim parsial = `in_transit` dan tidak bisa diverifikasi.
- **Kurir assignment-only (Keputusan Owner 2026-11-08, menggantikan aturan self-claim lama):** `kurir` hanya melihat dan memproses shipment `standard` yang **dicatat ke profil Courier miliknya** oleh alur dispatch berwenang (`super_admin`/`agen`/`admin`/`koordinator-kurir`). Shipment tanpa assignment atau milik kurir lain tidak muncul (daftar, filter status, paginasi, counter, riwayat selesai) dan ditolak 403 pada mutasi langsung via API tanpa mengubah state. Tidak ada claim saat pickup. `delivered` (`terkirim`) wajib **bukti foto**.
- **`koordinator-kurir`** = dispatcher cabang: meng-assign kurir (termasuk dirinya lewat jalur dispatch yang ada), melihat **riwayat terkirim** seluruh cabang (`GET /dispatch/history`, read-only, operasional saja, executor tercatat) dan detail order read-only; tidak mendapat akses uang atau mutasi order-wide.
- **Satu-satunya pengecualian self-pickup — SKS:** `sales-kurir-sub` hanya boleh mengambil/mengantar sendiri shipment `self_sub` bila (1) ia pemilik (`self_delivered_by_user_id`), (2) ia **pembuat order** (`orders.placed_by_user_id`, write-once, diisi dari aktor terautentikasi) dan (3) Agen sama. `placed_by_user_id` NULL (data historis) → **fail closed**; tidak pernah diinferensi dari cabang/lokasi stok/`agent_id`/`sales_id`.
- **Retur assignment-only:** pickup retur hanya oleh kurir yang di-assign (`return_items.courier_id`) via `PATCH /return-items/{item}/courier` (`super_admin`/`agen`/`admin`/`koordinator-kurir`, kurir eligible aktif Agen sama, di bawah lock, ter-audit `return_item.courier_assigned`). Dispatch menyediakan tab Penugasan Retur (`GET /dispatch/returns`), termasuk kurir tercatat dan status assignment. Reassignment memakai `expected_courier_id`; konflik perubahan sejak tampilan dimuat → 409 tanpa overwrite. Executor retur harus akun `kurir` aktif (koordinator tetap dispatcher). Retur tanpa assignment tak terlihat oleh kurir mana pun; `pickup`/`confirm` oleh non-assignee = 403. Pengecualian SKS tidak diperluas ke retur.
- Delivery verification hanya untuk shipment yang semua itemnya tiba (append-only; verdict diturunkan ulang dari item, tidak dari kolom tersimpan semata).
- Resi per shipment (pre/post-pickup turunan state), read-only, ter-audit, tidak untuk order batal.

## §12 Delivery, self-delivery & verifikasi

- **LOCKED self-delivery (amendmen CR-SALES-KURIR-SUB-DELIVERY-001, 2026-10-14):** item bersumber Sub → shipment `self_sub`, `self_delivered_by_user_id` = SKS pemilik, `courier_id = NULL` (CHECK + trigger DB). Tidak pernah lewat fulfilment Gudang; tidak pernah di-assign kurir lain. Reservasi Sub dikonsumsi hanya oleh jalur shipment pemiliknya sendiri; endpoint status office generik tidak bisa mengirim/menyelesaikan item Sub.
- **CR-001 standard delivery oleh SKS (2026-10-14):** shipment `standard` (sumber Agen) boleh di-assign ke profil Kurir SKS **hanya oleh dispatcher berwenang** (`super_admin`, `agen`/`admin`/`koordinator-kurir` cabang sama) dan **hanya bila SKS eligible untuk order itu**: Agen sama, akun aktif, dan (pembelian sendiri `konsumen_id` = dia) ATAU (snapshot `sales_id` = dia) ATAU (konsumen saat ini dalam scope referralnya `users.sales_id` = dia, Agen sama) — dicek server-side di bawah lock Order→Shipment. Tanpa assignment otomatis saat buat order; tanpa self-assignment (claim & route assign menolak). Executor yang dinonaktifkan tidak boleh lanjut. Setelah assignment, SKS berprogres seperti Kurir normal (executor tercatat, bukti delivery wajib, satu baris courier-fee saat delivery, tidak pernah digabung dengan sales fee).
- **Interpretasi Human 2026-10-14:** `self_delivered_by_user_id` pada `self_sub` (set saat order dibuat dari kepemilikan Sub) = **atribusi kepemilikan, bukan assignment dispatch** — CHECK DB membuat shipment `self_sub` tanpa pemilik tidak mungkin. Aturan tanpa-assignment-otomatis mengatur jalur dispatch `standard`.
- **LOCKED delivery verification:** catatan append-only (`received`/`not_received`/`return`) dengan aktor, waktu, catatan, `Idempotency-Key`; baris terakhir = outcome berjalan; replay valid hanya untuk `(shipment, outcome, note)` identik, selain itu 409. Admin (Agen sama)/Super Admin saja. Terpisah dari verifikasi pembayaran; tidak otomatis membuat retur.
- Pengelompokan delivery **derived**: `Order + requested_delivery_date`; tidak ada tabel invoice delivery dan pengelompokan tidak pernah menduplikasi kebenaran pembayaran.

## §13 Kebenaran pembayaran, additional payment & refund (**LOCKED**)

- Kebenaran pembayaran **level Order**; `PaymentSummaryService` satu-satunya ringkasan kanonis (grand total, DP, total paid, remaining, overpaid, status additional-payment & refund). View item/invoice/grup tidak membawa state pembayaran independen.
- `orders.total_amount` dihitung ulang hanya oleh `OrderTotalCalculator` (Σ `unit_price_snapshot × fulfilled_quantity` + ongkir + admin − diskon); `paid_amount`/`remaining_amount`/`payment_status` hanya ditulis `PaymentService`.
- `remaining > 0` selalu berarti belum lunas. **Additional payment** hanya muncul bila order yang sudah lunas (remaining ≤ 0) kemudian berutang lebih dari yang sudah dibayar; order unpaid/parsial hanya membawa sisa lebih besar. **Refund** hanya untuk overpayment nyata, inkremental. Pelunasan DP = transaksi `payment`, bukan `additional_payment`.
- Obligation pending membekukan penambahan/pengurangan kuantitas item sampai selesai. Settlement/refund idempotent dan tidak pernah mengedit baris pembayaran historis.
- **"DP Diajukan"/"Menunggu Verifikasi" (UAT-004)** proyeksi triase read-only, bukan uang: nominal submitted-unverified terekspos sebagai `submitted_dp`, TIDAK PERNAH ditambahkan ke `verified_dp`/`total_paid` atau dikurangkan dari `remaining_balance`; dibaca dari transaksi manual/COD **terkini** milik order (transaksi yang sama yang dikenai aksi verifikasi) sehingga baris verifikasi di transaksi tergantikan tidak bisa membuat order lunas tampak menunggu verifikasi.
- **Idempotency request pelunasan (Human):** request pelunasan meminta satu nominal outstanding spesifik. Request ekuivalen (order sama + `dp_settlement` pending sama + nominal = `remaining_amount` terkini) = **replay idempotent**: tidak ada transaksi/ledger baru — pending settlement eksisting dikembalikan 200. Dua request simultan konvergen ke SATU pending settlement kanonis (keputusan di bawah lock Order-first dengan locking read). Bila saldo outstanding **bergeser**, pending lama dipertahankan utuh dan request berbeda ditolak (`messages.payment.already_processed`); penyelesaiannya via reject/verify eksisting.
- Peran verifikasi: `super_admin`, `keuangan`. **Gateway eksternal (Xendit/Tripay/Stripe) dihapus by Owner**; tidak ada webhook gateway. Metode berbayar: manual transfer & COD saja (DP/pelunasan = skema, bukan gateway).

## §14 Retur & pembatalan

- Retur: konsumen mengajukan permintaan per item (bukti) untuk item `terkirim` → pickup/konfirmasi Kurir → review Admin/Agen → inspeksi Gudang (received/good/damaged) → finalisasi Admin. Kapasitas retur = `fulfilled_quantity − returned_quantity`. Kenaikan fulfilment memulihkan kuantitas batal sebelum menghitung tambahan.
- Unit baik masuk **Transit** Agen (`return_restock`), atau — untuk item Sub — ke **Sub Location asal**; bila lokasi itu tidak bisa menerima, retur ditolak, tidak dialihkan diam-diam ke Transit. Unit rusak tidak pernah jadi sellable.
- Pembatalan: COD saat `diterima`/`diproses`; non-COD hanya `diterima`. Stock Request ditandai `cancelled` dengan riwayat terjaga; pembatalan transaksional & idempotent.

## §15 Komisi

- Baris komisi Agen & sales dibuat saat order dibuat (+ baris SC-03), per item, dari snapshot fee. Beneficiary sales: pembeli bila pembeli adalah `agen`/`korsal`/`sales`/`sales-kurir-sub` (self-purchase); selain itu `sales_id` pembeli, else `korsal_id`, else Agen. Agent fee & sales fee baris terpisah walau orang sama.
- Komisi kurir ditulis per item saat delivery (`terkirim`), idempotent, ke kurir shipment (atau `self_delivered_by_user_id` untuk `self_sub`). **LOCKED:** sales fee & courier fee tidak pernah digabung; SKS bisa mendapat keduanya.
- Courier fee mengikuti fulfilled aktif (diskalakan saat reduce/increase, terkonservasi saat split, selalu dari snapshot historis). Snapshot agent/sales & baris komisi eksisting tidak disentuh adjustment.
- Visibilitas fee: `agent_fee` hanya `super_admin`/`agen`; `courier_fee` hanya `super_admin`/`agen`/`admin`/`keuangan` + beneficiary kurirnya (`kurir`, `koordinator-kurir`, `sales-kurir-sub`); sales fee ke rantai beneficiary + finance; konsumen tidak pernah melihat field fee/harga internal.
- **Model fee (Owner 2026-10-29):** setiap fee dihitung & dicatat PER ORDER ITEM dan PER TYPE (`agent`, `sales`, `courier` baris terpisah; unik hanya per item+type courier sehingga replay delivery tidak membayar dua kali). Satu orang boleh memegang beberapa hak pada item yang sama (SKS: sales + courier fee; Agen: agent fee + sales fee bila perujuk langsung). Self-purchase TIDAK meniadakan fee. Hak mengikuti FUNGSI yang dijalankan.
- **Pending → final:** setiap baris fee lahir `pending`; menjadi final (`approved`) saat item ordernya mencapai `terkirim` — per item (`CommissionFinalizer` dari semua jalur delivery). Finalisasi idempotent; tidak menyentuh baris `paid`/`reversed`. `paid` = state payout eksternal tanpa writer di aplikasi (§15a OD-05e).

### §15a Kebijakan adjustment komisi refund (OD-05a…g framework disetujui 2026-10-28; **pembatas WS-8 FINAL diimplementasikan 2026-10-30**)

> **Status WS-8 (Owner FINAL 2026-10-30, IMPLEMENTED):** *fee sales* atas refund **sudah** disesuaikan otomatis — `SalesFeeRefundAdjuster` (`backend/app/Services/Commission/SalesFeeRefundAdjuster.php`, policy version `ws8-owner-2026-10-30`) di-hook pada satu titik refund terbukti (`ReturnService::markItemRefunded`). Aturan final: fee dibatalkan = Σ(unit yang direfund × snapshot fee sales per unit dari transaksi — konfigurasi fee produk saat ini tidak pernah dibaca); refund penuh membatalkan seluruh fee sales item, parsial membatalkan tepat unit × fee per unit; **nominal uang refund tidak pernah dipakai** (bukan persentase); tanpa double adjustment (UNIQUE(commission_id, return_item_id) + firstOrCreate); ledger historis (baris komisi asli immutable; pembatalan = baris **signed negatif** di `commission_adjustments`, sehingga Σcommission + Σadjustment = entitlement neto untuk payout akhir periode); refund yang ditolak tidak pernah membatalkan fee; clamp mencegah fee negatif; audit `commission.sales_fee_refund_adjusted`; migrasi additive `2026_10_30_105000` (DEV Ran 2026-10-30). **HANYA fee sales** — kontrak fee agent/courier tidak diubah (Owner: "jangan mengubah kontrak fee role lain"); entri matriks tanggung jawab lain (OD-05c) tetap PENDING_OWNER_APPROVAL dan `paid` tetap tanpa writer di aplikasi (OD-05e).

Framework (Owner 2026-10-28): **OD-05a** hybrid — deterministik dari snapshot komisi per-unit asli; pengecualian hanya lewat entri policy ter-approve. **OD-05b** eligibility berbasis snapshot; aturan alokasi diskon = kebutuhan desain terdokumentasi (belum ada). **OD-05c** matriks tanggung jawab berdimensi (reason × pihak × beneficiary × treatment × evidence × approver + versi) — **entri individual selain WS-8 sales-fee belum disetujui**; komisi kurir tidak pernah clawback otomatis. **OD-05d** trigger = obligation refund ter-approve (§26)/baris retur terproses, bukan request; adjustment idempotent; entri kompensasi jurnal append-only; tidak ada mutasi baris komisi asli. **OD-05e** recovery balance terpisah untuk komisi yang dibayar DI LUAR aplikasi — tanpa deduksi otomatis; caveat: aplikasi tidak melacak payout. **OD-05f** alokasi desimal 2-digit deterministik dengan pembulatan/remainder eksplisit; bounded oleh entitlement asli. **OD-05g** kesiapan schema (persyaratan; migrasi tambahan apa pun butuh otorisasi Owner).

Invarian finansial terpelihara: komisi dari snapshot per-unit, bukan persen bruto · entri agent/sales/courier tetap terdistingui · laporan Korsal = agregasi downline tanpa ledger komisi Korsal · entitlement refund ≠ payout tunai · entitlement komisi ≠ payout · baris komisi asli immutable · jurnal adjustment append-only · ledger refund tidak ditulis ulang · akses lintas-Agen dilarang · Sales/SKS tidak bisa menyetujui/mengeksekusi refund (OD-02) · tanpa backfill historis tanpa otorisasi Owner terpisah · kebijakan receivable OD-K5 independen dari OD-05.

## §16 Permintaan gudang & fulfilment

- **LOCKED chain:** order masuk `diproses` → satu Stock Request (+ item untuk baris Agent) → **Gudang menyerahkan** kuantitas (≤ remaining, bukti + alasan bila kurang) → **Koordinator Kurir mengonfirmasi penerimaan** → pergerakan fisik + pelepasan reservasi (§10). *(Rantai lama "Admin approve" digantikan — Keputusan Owner 2026-11-11, lihat §12 BUSINESS-WORKFLOWS dan baris Handover fisik dua pihak di bawah; approve/reject Admin hanya tersisa sebagai pengecualian backend.)* Fulfilment langsung oleh Gudang dinonaktifkan. Fulfilment parsial menyimpan sisa di request/item yang sama; status derived `pending/partial/fulfilled`.
- **IMP-001 Gap 2:** fitur user-facing "Stock Request" mandiri tidak ada lagi. Gudang mengerjakan antrian **"Order Diproses"** (`GET /warehouse/orders/diproses`): order terlihat **hanya saat** status tepat `diproses` **dan** belum ada kurir (tanpa shipment ber-`courier_id`, tanpa `self_sub` ber-`self_delivered_by_user_id`). Invarian di-enforce **server-side** (query antrian, `OrderPolicy::view`, `StockRequestProposalService::propose`) — begitu salah satu salah, order hilang dari antrian dan akses API/detail langsung ditolak. Tabel & endpoint proposal/approval tetap persistensi kanonis di balik alur ini.
- Stock Request dibuat tepat sekali (idempotent); item Sub dikecualikan; order seluruh-Sub tidak punya request.
- Gudang tidak mengubah stok sendiri: penambahan stok (Transit/Factory Plan) & Sub adjustment adalah **request yang di-approve Admin**; Super Admin dan Agen bukan approver inventory.

## §17 Sub stock request (**LOCKED chain**)

- **Replenish:** SKS meminta → Admin approve → Gudang execute (Transit → Sub: transfer + movements + handover) → SKS konfirmasi **receive**.
- **Return:** SKS meminta (≤ Sub sellable) → Admin approve → Gudang execute (Sub → Transit).
- Request = permintaan saja; tidak ada pergerakan sebelum `execute`; execute idempotent dan gagal 422 (request tetap `approved`) bila Transit/kapasitas kurang. Target: produk/variasi aktif apa pun (tanpa perlu Transit > 0 untuk meminta).

## §18 Transfer & handover

- Gudang membuat transfer `pending`; **Admin approve** untuk eksekusi. Bucket yang diizinkan: Transit ↔ Sub, Sub ↔ Sub (lokasi beda), Factory Plan → Transit (toggle aktif, approve Admin). `shipping` tertutup untuk transfer; Sub Location milik orang ditolak di sini.
- Penyelesaian atomik & idempotent, menulis movement `transfer_out`/`transfer_in` berpasangan, dan membuat tepat satu dokumen handover. Cetak handover (A4) read-only selain audit cetak.

## §19 Opname

- Jenis: fisik (Transit/Shipping/Sub) dan rekonsiliasi plan; sellable hanya diagnostik read-only. **Gudang menghitung & submit; Admin approve/reject**; Gudang tidak bisa self-approve; Super Admin bukan approver default.
- Approval menerapkan kebenaran fisik terhitung secara atomik, menulis movement `opname_adjustment` immutable, mempertahankan reservasi (kekurangan = defisit komitmen yang terlihat), menolak snapshot basi, idempotent.

## §20 Laporan & visibilitas

- Laporan transaksi operasional: satu baris per `order_item`, tepat 14 kolom (Order No, Tanggal, SKU, Produk, Harga, Qty, Status Item, Subtotal, Konsumen, Tgl Kirim, Kurir, Status Order, Sales, Korsal). `Kurir` untuk `self_sub` = SKS yang mengantar. Kolom pembayaran level-order berulang per item dan **tidak boleh** dijumlahkan.
- Laporan finansial: satu baris kanonis per order dari `PaymentSummaryService`; Keuangan tanpa akses laporan operasional.
- Gudang & Kurir menerima **proyeksi operasional** (tanpa DP/paid/remaining/refund/bukti pembayaran/harga/komisi); Kurir hanya melihat shipment yang di-assign kepadanya (detail operasional penuh; proyeksi minimal tetap sebagai defence-in-depth). SKS mempertahankan proyeksi finansial ordernya sendiri.
- Scope selalu di-query backend; nama resolve live dari ID stabil.

### §20a Laporan A "Transaksi Awal" & Laporan B "Posisi Pembayaran Terkini" (**LOCKED**, Owner 2026-10-21)

- **Laporan A** — 24 kolom, satu baris per item **saat order dibuat**. Immutable: ditulis dari snapshot dalam transaksi checkout yang sama; hanya order baru setelah snapshot aktif (order lama tidak direkonstruksi). Kolom tingkat faktur (Ongkir, Diskon, Total Pembayaran, Total Bayar, Sisa) **hanya di baris item pertama** per faktur (anti double-count). `AGN` = `orders.agent_id`; nama aktor pakai profil terkini dari ID hierarki historis. Penerima/telepon/alamat dari snapshot order; kategori produk dari snapshot kategori. "Sisa" = derivasi `max(0, total − paid)` saat commit — bukan `orders.remaining_amount`.
- **Laporan B** — 15 kolom, satu baris per faktur, order lama & baru; nilai keuangan membaca **keadaan terkini**. "Sisa Piutang" = `max(0, total_amount − paid_amount)`, **bukan** `orders.remaining_amount`.
- Keduanya **tanpa kolom "Posisi Uang"** (OQ-8 ditutup Owner).
- Isolasi per Agen via `agent_id` order/snapshot. Filter periode (harian/mingguan/bulanan/kustom) mengikuti **kalender bisnis Asia/Jakarta (WIB)**: minggu mulai Senin; DB tetap UTC. Google Sheets hanya tujuan ekspor satu arah. Batas 10.000 baris per sinkronisasi — lebih dari itu gagal total dengan pesan mempersempit periode; tanpa pemotongan senyap.

### §20b Format parity SimpleV2 & Sheets (OD-06 disetujui — **NOT IMPLEMENTED**)

Scope: semua format presentasi laporan & Sheets SimpleV2 diadaptasi **dalam scope role & Agen**. SimpleV2 otoritas format presentasi saja; PrimeClassy tetap otoritatif harga/pembayaran/stok/komisi/refund/tenant-security. Baseline SimpleV2: export Excel 43-kolom + payload Sheets 47-kolom `A:AU` (COOKIES_2026/CAKE_2026) — workbook asli tidak bisa diverifikasi dari source ⇒ `EXTERNAL_EVIDENCE_REQUIRED` sebelum klaim parity visual. Dataset PrimeClassy (17 key) di `DatasetRegistry`. Implementasi format harus mulai dari inventaris per-kolom; gate parity struktural+fungsional+visual — inspeksi source saja tidak pernah menghasilkan PASS. Kolom manual tidak boleh ditimpa export satu arah (separasi SYSTEM/MANUAL/PRESENTATION masih proposal). Inkonistensi lama Laporan A/B vs dataset `financial_summary`/`payment_status` diambil keputusan: kontrak AR kanonis OD-K5 (§20c); unifikasi konsumen laporan tetap APPROVED_DESIGN/NOT_IMPLEMENTED dengan rollout reconciliation-first. "Posisi Uang" tetap ditutup (§20a). Laporan/export/dataset Sheets tidak boleh menjatuhkan order CRM-origin lewat inner join ke `users` (§27).

### §20c Kontrak receivable collectible kanonis (OD-K5-1…5 disetujui — **IMPLEMENTED di source 2026-10-29 (DEV)**; WS-7)

`App\Support\CollectibleReceivable` = derivasi tunggal: 0 untuk order batal, selain itu `GREATEST(total_amount − COALESCE(paid_amount,0), 0)` (M-2). Menggantikan `remaining_amount` tersimpan di `financeOrders`, `networkSummary`, outstanding per-Agen, dataset D17 `financial_summary` & D13 `payment_status` (header kolom SAMA — hanya nilai). Kolom tersimpan, lifecycle pembayaran, snapshot historis, ledger refund tak tersentuh (OD-K5-5: tanpa backfill). `primeclassy:ar-reconciliation-report` = bukti read-only OD-K5-4. **Sign-off Keuangan tetap direkomendasikan sebelum rollout PRODUCTION.**

Tiga metrik finansial berbeda (jangan dicampur):

| Metrik | Definisi | Otoritas |
|---|---|---|
| M-1 saldo tersimpan | `orders.remaining_amount` — state lifecycle pembayaran | Valid sebagai state API/lifecycle; bukan sumber tunggal AR |
| M-2 saldo running derivasi | `GREATEST(total_amount − COALESCE(paid_amount,0),0)` live | Basis AR collectible kanonis + aturan eligibilitas OD-K5-1 |
| M-3 snapshot awal immutable | `order_initial_report_items.initial_remaining_snapshot` | Posisi historis checkout; tidak pernah dihitung ulang |

**OD-K5-1** AR collectible = jumlah yang masih legally & operationally collectible; order **batal tidak pernah** masuk AR (kewajiban refund/settlementnya tetap terlapor via permukaan sendiri). **OD-K5-2** pending verification ≠ pembayaran — tetap masuk AR sampai diverifikasi (otorisasi verifikasi tak berubah). **OD-K5-3** overpayment = kategori sendiri (`GREATEST(paid−total,0)`; read-model usulan; tidak netted terhadap AR; ledger refund tetap kebenaran uang keluar). **OD-K5-4** rekonsiliasi sebelum rollout (inventaris konsumen; bandingkan; atribusi; pisahkan defect KF-1/2/3 `G-15`; sign-off Keuangan; approval Owner; audit + rollback) — *superseded untuk DEV oleh instruksi WS-7 2026-10-29; sign-off (5) & approval rollout (6) tetap wajib pra-produksi*. **OD-K5-5** tanpa backfill historis; koreksi data butuh keputusan Owner baru.

## §21 Penyesuaian order existing

- Penyesuaian kuantitas & reschedule hanya dalam jendela fulfilment — status order `diproses` (dan `diterima` untuk reschedule); `super_admin`/`agen`/`admin`. Jendela di-enforce pada **Order** di bawah lock-nya (status item tidak lagi mencerminkan status order). Penyesuaian Sub merekonsiliasi reservasi Sub; kuantitas pasca-shipment tidak ditulis ulang (pakai retur / item tambahan).
- **LOCKED (Human final 2026-10-07):** tanggal pengiriman hanya bisa dijadwalkan-ulang untuk KURIR ONLINE (`shipping_provider_code = 'openroute'`) dan SELF DELIVERY/`self_sub`. EKSPEDISI/RajaOngkir dan PICKUP **terlarang**. Allowlist oleh `Shipment::isDeliveryDateReschedulable()` dari field kanonis, enforce server-side dalam lock Order (gating frontend hanya kenyamanan).
- Split parsial membuat item anak (`split_from_order_item_id`) dengan shipment sendiri; untuk item Agen Stock Request di-split konservatif, atau ditolak 422 bila request sudah maju melampaui yang bisa di-split deterministik.

## §22 SC-03 — tambah baris ke order existing

**KEPUTUSAN BISNIS HUMAN (disetujui 2026-10-06):** penambahan baru dilarang setelah ada kurir/executor self-delivery di mana pun pada order. Tanpa unassignment otomatis, tanpa pengecualian Gudang. Bila SC-03 dimenangkan sebelum assignment, permintaan tambahan harus dipenuhi sebelum assignment eksplisit / pickup implisit menutup akses Gudang. Replay request identik tetap dipertahankan.

**LOCKED:** hanya **`admin`** (Agen sama) boleh menambah baris produk/variasi BARU ke order existing. `super_admin`, `agen`, dan role lain ditolak; `OrderPolicy::manageFulfillment` tidak digunakan ulang.

| Aturan | Perilaku |
|---|---|
| Eligibilitas | Order tepat `diproses`; tidak ada shipment ber-`courier_id`/`self_delivered_by_user_id`; stok Agen saja; order Sub-sourced ditolak 422 |
| Resolusi | `resolveLine` bersama; harga/fee/SKU/snapshot server (jalur kode sama dengan checkout); field harga/stock-source klien ditolak |
| Kuantitas/tanggal | `quantity` integer ≥ 1; `requested_delivery_date` opsional (default estimasi order), tidak boleh lampau untuk tambahan baru |
| Fulfilment | Baris HARUS bergabung ke Shipment kanonis kompatibel eksisting (§11); shipment `standard` pending baru hanya bila belum ada unit untuk key itu. Stock Request order bertambah satu item (re-open ke `pending`/`partial` bila sudah `fulfilled`; riwayat terjaga); request hilang/batal ⇒ 422 |
| Uang | Total via `OrderTotalCalculator`; pembayaran via `PaymentService::reconcileTotals`; unpaid/parsial ⇒ remaining membesar; lunas ⇒ tepat satu `OrderAdditionalPayment` pending untuk delta terkait item baru; tidak ada yang ditandai lunas diam-diam |
| Komisi | Baris agent+sales seperti checkout; courier fee saat delivery |
| Idempotency | `Idempotency-Key` wajib (non-blank ≤ 100 char); unik per order; replay request **asli** identik (produk, variasi, kuantitas, tanggal, metode pembayaran; `reason` dikecualikan) → `200` tanpa efek baru — bahkan setelah baris diadjust/split/order maju/tanggal lewat; request berbeda material di key sama ⇒ `409` |
| Konkurensi | Urutan lock Order → Stock Request → inventory (§ [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md) §6); eligibilitas executor via locking read dalam batas Order; aman terhadap assignment kurir, approval gudang, checkout, dan penambahan lain |
| Respons | `201` (replay `200`) dengan `OrderResource` kanonis ter-persist |
| Audit | activity log `order_item.added` |

## §23 Google sign-in (IMP-001, konsumen saja)

- Google = **identity provider saja**; tidak mem-bypass aturan referral. Registrasi email/password tidak berubah (referral opsional).
- Identitas Google sudah ter-link: langsung login (identitas provider menang atas tabrakan email). Akun lokal **auto-link hanya bila email lokal terverifikasi** dan sama dengan email Google terverifikasi; akun lokal belum terverifikasi **tidak pernah** auto-link (`local_account_exists`, tidak dibuat/di-link) — mencegah perampasan email pre-registered.
- Linking eksplisit aman: konsumen ter-autentikasi boleh men-link akun Google dari *My Account* (`mode=link`, perlu web session; email Google tak harus sama). Identitas milik orang lain (`identity_in_use`) atau identitas kedua (`already_linked`) ditolak. Linking tidak menyentuh referral/ownership.
- Field referral/ownership akun existing (`agent_id`, `korsal_id`, `sales_id`, `parent_id`) **tidak pernah** ditulis jalur mana pun; link referral dibuka bersamaan dicatat diabaikan di audit. Reassignment tetap eksklusif `ReferralReassignmentService`.
- **Login Google tidak pernah membuat konsumen.** Konsumen BARU hanya dari `register` mode dengan kode referral valid yang di-revalidasi server-side saat callback (`ReferralService::resolveChainByCode`); tanpa referral valid ⇒ tanpa akun.
- Email milik akun non-konsumen/inaktif/soft-deleted ditolak (`email_in_use`).
- OAuth state: session server-side (`state`, `nonce`, PKCE verifier, mode, referral code), sekali pakai, TTL 10 menit; `state`/`nonce`/`aud`/`iss`/`exp`/`email_verified` dicek. Token Google tidak disimpan/di-log; hanya `provider`, `provider_user_id`, `provider_email`, `last_login_at` (`user_social_identities`, UNIQUE ganda).

## §24 Pembayaran konsumen berbantu (IMP-001)

- `korsal`/`sales`/`sales-kurir-sub` boleh submit **bukti** pembayaran untuk order konsumen yang **saat ini** dalam scope referralnya (`OrderPolicy::payOnBehalf`: Agen sama; Korsal ⇒ `konsumen.korsal_id`; Sales/SKS ⇒ `konsumen.sales_id`). Mengetahui id order tidak memberi apa pun; lintas-Agen/lintas-referral ditolak; pasca reassignment ter-audit scope mengikuti rantai terkini.
- `orders.konsumen_id` tidak pernah ditulis ulang. Aktor pembayar dicatat pada bukti (`submitted_by_user_id`, `submitted_on_behalf`) + audit; bukti historis tetap NULL (tidak di-backfill).
- Pembayar **tidak bisa** menandai lunas: `PaymentService` tetap satu-satunya penulis. **Otoritas (perubahan Human, men-supersede UAT-008):** korsal/sales/SKS hanya boleh SUBMIT bukti dalam scope `payOnBehalf`; `verify`, request pelunasan DP, konfirmasi bukti COD = `super_admin`/`keuangan` (gate route + `assertMayVerifyPayment`). **Trio audit pembayaran** di `bank_transfer_verifications`/`cod_payment_proofs`: *Pembayar* `paid_by_role` (SKS hanya `sales`; Korsal hanya `korsal`; selain itu `konsumen`; default `konsumen`), *Di-upload oleh* `submitted_by_user_id`+`submitted_at`, *Diverifikasi oleh* `verified_by`/`confirmed_by`+waktu. Historis NULL tetap NULL.
- **Invariant pengeras (semua submitter, termasuk konsumen):** bukti ditolak (422) setelah transfer verified/paid, bukti COD confirmed, atau order batal — mencegah re-upload mereset bukti terverifikasi ke pending sementara `paid_amount` tetap teraplikasi (double count). Bukti *rejected* boleh di-submit ulang. Writer lock baris verifikasi → transaksi → order.
- **IMP-004 (2026-10-05):** `OrderPolicy::view` juga memberi akses baca ke Sales yang konsumennya **saat ini** dalam scope referralnya (mirror `payOnBehalf`); snapshot `sales_id` historis tidak ditulis ulang — Sales lama tetap punya akses snapshot; Sales yang bukan keduanya tetap ditolak.
- Bukti ditolak (422) bila kondisi di atas terpenuhi. Metode gateway eksternal dihapus (Owner); transfer manual satu-satunya metode berbasis bukti.

## §24a Halaman stok & filter order (change request)

- `GET /stock/products|variations` menampilkan semua target Agen yang dilihat (legacy ATAU warehouse) dengan ADA/DITAHAN/TERSEDIA dari `SellableStockService` — kebenaran yang sama dengan katalog/storefront/checkout. `StockService::reserveFor*` menyediakan baris komitmen kuantitas-NOL untuk target warehouse-only agar tetap orderable; availabilitas tetap diputuskan kanonis.
- `GET /orders` menerima `status`, `payment_status` (state settlement kanonis: unpaid, pending_verification, partially_paid, paid, failed, refunds), `search`, plus padanan Dispatch: `delivery_date`, `province_id…village_id`, `paid` (satu `OrderFilterService` bersama); `GET /orders/regions` memberi opsi bertingkat dari order ter-scope pemanggil. Filter hanya mempersempit scope role (scope = nested group); role operasional tidak bisa memakai filter pembayaran.

## §25 Observasi dikonfirmasi

- **Self-purchase sales fee SKS (Owner):** SKS membeli untuk dirinya memperoleh sales fee sendiri, seperti Sales; agent & courier fee tetap ledger terpisah.
- **Fallback legacy stok:** mode warehouse-authoritative default, tetapi target tanpa baris warehouse fallback ke legacy on-hand (§8).

## §26 Invarian ledger refund (**LOCKED**, mandate Owner 2026-10-22)

- Satu snapshot finansial immutable per Order. Setelah event finansial pertama, perubahan kuantitas/add-line ditolak kecuali trigger snapshot `fulfillment_shortfall` dan order masih `diproses` (perilaku Package A/B preserved); SC-03 selalu ditolak.
- `paid_amount` tepat satu writer (`PaymentService`). Payout ledger membalik melaluinya; pembalikan historis legacy dicatat `legacy_unverified` dan **tidak pernah** membalik `paid_amount` dua kali.
- Uang refund keluar hanya lewat attempt sukses + payout-nya; obligations/attempts/payouts tidak pernah dihapus; duplikat = no-op idempotent.
- Arus kas refund laporan = R_c (proven & affecting). Refund legacy unverified = R_u, ditampilkan terpisah; obligation pembatalan `min(P, C)` independen dari R_c.
- Container deferred bernilai nol tidak membawa uang dan tidak bisa menerima attempt; materialisasi & reinstatement Owner-authorized, berbasis bukti, dan dibatasi (capped).
- Backfill insert-or-skip dengan kunci stabil `legacy:{source_type}:{source_id}`; tidak pernah mengarang deferral; tidak pernah auto-refund retur legacy (tetap `needs_verification`); apply hanya di test-DB.

## §27 CRM Customer — kepemilikan, linking & bantuan CRM-origin (OD-01…OD-06 **LOCKED**; implementasi IMP-006 slice 1+2 di source, migrasi DEV `2026_10_30_*` disetujui 2026-10-30/31)

- CRM Customer = kontak bisnis/prospek — **bukan** `User` fabrikasi, bukan identitas guest-checkout publik (D-06/D-07 tetap: tanpa guest checkout publik; assisted order hanya oleh seller berwenang).
- Milik tepat satu Agen dan satu Sales/SKS pemilik. Sales/SKS create/view/update miliknya dengan audit immutable untuk perubahan sensitif. Korsal read/monitoring downline.
- **OD-01** — Agen: CRM **read** cabang; Admin: CRM **read** operasional cabang. Read ≠ reassign/export/verifikasi.
- Kosakata stage (prospek 0/25/50/75/90/100; status visit `N,1…7`) hanya dari bukti terverifikasi. **FU/D diputuskan Owner 2026-10-30: `FU` = Follow Up, `D` = Deal (alias status `7`; follow-up via `plan_next_date`).**
- Assisted order boleh berasal dari CRM Customer milik tanpa login. Order service tetap otoritatif harga/stok/idempotency/snapshot finansial/audit — sama dengan order account-origin.
- **OD-02** — untuk Customer CRM-only, Sales/SKS pemilik boleh **menginisiasi** retur dengan bukti consent terverifikasi + bukti aktor. Hanya inisiasi: approval/pickup/inspeksi/finalisasi/settlement refund tetap otoritas lama (§14, §26; uang tetap `super_admin`/`keuangan`).
- **OD-03** — linking CRM↔Konsumen butuh bukti kontrol akun + OTP ke channel terverifikasi + audit; rate limit/expiry/replay protection/uniqueness. Link tidak pernah disimpulkan dari nama/telepon/email yang sama.
- **OD-04** — revokasi link oleh aktor berwenang, ter-audit; hanya mengubah otorisasi masa depan; historis tidak pernah ditulis ulang.
- **OD-05** — lihat §15a (framework refund commission adjustment; implementasi terpisah).
- **OD-06** — parity format laporan/Sheets: lihat §20b.
- Pelestarian alur kerja: 29 grup alur kerja existing mempertahankan otoritasnya; tepat 11 role; visibility frontend tidak pernah memberi otoritas backend; verifikasi pembayaran tetap `super_admin`/`keuangan`; hanya manual transfer + COD.
- Keputusan Owner 2026-10-31 (**LOCKED; implemented in source, DEV-migrated, browser-verified**): (1) **Assisted Order (D3)**: hanya Sales/SKS pemilik, Korsal pemilik, dan Agen cabang; semua cek server-side; tanpa pelebaran RBAC; tanpa akses lintas cabang; tanpa takeover identitas konsumen. (2) **Consent untuk CRM tanpa akun**: foto+catatan **tidak cukup**; wajib OTP di channel terverifikasi — email terverifikasi akun konsumen yang ter-link; tanpa channel itu aksi **diblokir**; tanpa bypass otomatis. (3) **Unlink**: seller pemilik, Agen berwenang, Admin dalam scope cabang; alasan+otorisasi+audit wajib; tidak pernah mengubah order/pembayaran/refund/komisi/snapshot historis.
- ~~Defek AU-01~~ — **FIXED (REM-004, terverifikasi source 2026-10-29/30):** registrasi tanpa Agen aktif kini mengembalikan **HTTP 503 + `registration_unavailable`** (`AuthController::register`, `backend/app/Http/Controllers/Api/V1/Auth/AuthController.php:61-66`). Kontrak 503 BUSNES-RULES §27 terpenuhi.

### §27a Consent, identity linking & FU/D (CI-01…CI-07 disetujui; sebagian besar **terimplementasi** lewat IMP-006 slice 2, 2026-10-30)

- **CI-01** verified email + OTP sekali-pakai (expire, attempt limit, rate limit, replay protection, binding challenge, audit) — **IMPLEMENTED** (`identity_otp_challenges`, `ConsentController::issueOtp/verifyOtp`, `CrmAssistedController::linkOtp`). Email/telepon yang cocok saja **tidak pernah** mengotorisasi.
- **CI-02** bukti consent digital terikat: customer aktual · order & retur · item/kuantitas/scope · purpose · media · aktor · waktu · Agen · provenance — **IMPLEMENTED** (`consent_events`, `captureConsent`). Checkbox/detail cocok/pernyataan lisan tidak pernah cukup.
- **CI-03** Sales/SKS menangkap consent hanya dalam scope; customer tetap pemberi consent; retur berbantu hanya setelah bukti consent valid — **IMPLEMENTED**. Tanpa approval review/inspeksi/refund.
- **CI-04** withdrawal/dispute: event append-only ter-audit; aksi bergantung-consent ditangguhkan; historis tidak dibalik senyap — **IMPLEMENTED** (`withdraw`, `dispute`, `reinstate`).
- **CI-05** linking lintas-Agen **ditolak** — **IMPLEMENTED** (isolasi tenant di semua gate).
- **CI-06** retensi consent: aktif + 5 tahun setelah hubungan berakhir (floor; kewajiban statuter lebih lama menang; legal hold menangguhkan) — **IMPLEMENTED read-only** (`RetentionPlanner`); tanpa mekanisme penghapusan; `consent_events` menolak UPDATE/DELETE di boundary model.
- **CI-07** semantik FU/D — **DIPUTUSKAN 2026-10-30** (FU = Follow Up; D = Deal/alias `7`); jangan menamai ulang/menghapus tanpa approval Owner baru.

## §28 Komunikasi WhatsApp (**OWNER-DIRECTED 2026-10-31; implemented in source**)

- **Purpose terpisah:** `transaction` (fakta order/pembayaran/retur/refund), `otp` (kode verifikasi), `test` (nomor owner-authorised). **Promosi/marketing out of scope** dan tidak boleh memakai ulang penerima/consent transaksional.
- **Siapa boleh dimessage:** hanya pembeli AKUN order, di nomor milik akun itu. Customer CRM **tidak** dimessage (nomor diketik Sales, unverified) — event dicatat `skipped`, bukan didiamkan.
- **Nomor terverifikasi wajib:** notifikasi transaksional hanya ke pembeli akun dengan bukti kepemilikan valid (kode WhatsApp yang dimasukkan benar, terikat nomor itu). Sukses delivery ≠ verifikasi; nomor unverified/salah-ketik di-skip (`number_not_verified`). Ganti nomor akun membatalkan proof. Kode tidak pernah disimpan plaintext/di-log/di-return. Kegagalan tidak membocorkan eksistensi akun/nomor.
- Verifikasi nomor ≠ consent ≠ linking CRM — hanya bukti kontrol channel.
- **Default:** gateway OFF, semua event OFF, OTP-WA OFF. Hanya Super Admin menyalakan; setiap perubahan ter-audit.
- **Scope manajemen (WA-DEC-01..05, 2026-11-04):** device pairing/reconnect/logout/replace, saklar event+template, allow-list nomor uji — dikelola per Agen oleh **Agen** (miliknya) dan **Admin Agen** (Agen cabang, resolusi server-side; `agent_id` klien di luar scope → 403). **Super Admin**: global ON/OFF, koneksi GOWA pusat, kebijakan OTP, batas volume. Baris event scoped meng-override flag/template global legacy; tanpa baris, nilai global dipakai. OTP/verifikasi/recovery/registrasi bukan event configurable. Pesan uji hanya dari slot Agen target ke nomor terdaftar Agen itu (rate-limited).
- **Fakta saja:** data order nyata; resi/kurir yang belum ada dihilangkan, tidak diarang. Tidak ada notifikasi "dikemas" (status itu tidak ada).
- **Bisnis dulu:** outage gateway/kesalahan template/transaksi rollback tidak pernah menggagalkan operasi bisnis.
- **Tanpa duplikat senyap:** satu event bisnis = satu pesan (idempotency key). Hasil tidak-pasti tidak di-retry otomatis (operator menerima risiko duplikat eksplisit). OTP tidak pernah dikirim ulang.
- **OTP channel untuk consent & linking (Owner 2026-10-29):** WhatsApp alternatif email terverifikasi, hanya bila (a) akun konsumen telah membuktikan nomornya (`whatsapp_number` challenge), (b) nomor masih nomor akun dengan proof valid, (c) kode adalah challenge BARU terikat purpose/akun/order atau CRM customer/aktor. Kode verifikasi nomor tidak bisa dipakai ulang sebagai kode consent/link. Email OTP tetap fallback aman. Delivery WA bukan verifikasi; bila kode gagal terkirim aksi tetap diblokir. Tanpa bypass manual.
- **Volume & retry:** kap per-penerima (6/jam) & gateway (20/menit), hanya bisa diubah Super Admin dalam batas keras; throttle menunggu (bounded), bukan burst; `unknown` tidak pernah di-retry otomatis; OTP tidak pernah di-retry; semua throttle ter-audit. Penerima di-cek ulang proof-nya saat kirim.
- **Retensi (kontrak; BELUM dieksekusi):** `whatsapp_messages` 90 hari operasional (minimize, baris tetap); OTP challenge expired/unused/void 30 hari; consent evidence aktif + 5 tahun (CI-06). Legal hold menangguhkan semuanya. Eksekusi butuh approval Owner + backup terverifikasi + pembacaan ulang dry-run.

## §28a Komisi Korsal/Agen (Owner 2026-10-30, KPI Phase 2)

- **Tidak ada fee role-Korsal**: role ledger hanya `agent`, `sales`, `courier`. Korsal memperoleh sales fee hanya saat menjadi beneficiary sales (penjualan sendiri/perujukan langsung); penjualan downline tidak membuat baris Korsal.
- Korsal memiliki seluruh permission fungsional Sales dalam scope sendiri + read/koordinasi tim; tidak mewarisi permukaan stok SKS (`/sub-stock/*`) atau kurir.
- "Komisi Cabang" (dashboard Agen) = fee Agen sendiri; "Komisi Tim" (Korsal) = rincian sales fee per-penerima. Angka transaksi dari order ter-atribusi; komisi hanya dari ledger; `GET /dashboard/kpis` adalah kontraknya.

## §29 Master V2 — metode pembayaran, cicilan, invoice, due date, bukti (Owner 2026-10-31)

- **Metode checkout per role:** `konsumen` hanya ditawarkan & diterima **Bank Transfer** (tanpa COD, tanpa DP) — enforce `AvailablePaymentMethodService` di daftar langkah **dan** otoritatif di `OrderService` (`cod`/`down_payment` palsu → 422). Role staf yang boleh membuat order tetap COD + Bank Transfer; DP sub-opsi Bank Transfer (*Bayar Lunas*/*Bayar DP*), didukung metode `down_payment`. §6 tidak berubah.
- **Cicilan/pelunasan/kas** (`POST /orders/{order}/payment/installment`): diizinkan saat order sudah `diproses`/`dikirim`/`terkirim`, tidak untuk COD, tidak batal, `0 < amount ≤ remaining`. Maksimal satu catatan manual belum-selesai per order (request pending identik = replay idempotent; pending lain ditolak). Pemanggil: konsumen (miliknya), staf cabang, Korsal/Sales/SKS scope `payOnBehalf`. Catatan pending/rejected tidak mengurangi piutang. **Fulfilment tidak pernah digerbangi pembayaran setelah `diproses`**; piutang tetap terbuka setelah order selesai.
- **Kas:** `channel = cash` di pipeline verifikasi yang sama: pencatat penerima (`cash_received_by_user_id`), waktu, referensi/foto kuitansi. Konsumen tidak bisa mencatat kas.
- **Otoritas verifikasi tidak berubah** (`super_admin`, `keuangan`). Keuangan mengonfirmasi uang benar diterima: `payment_transactions.amount`/`submitted_amount` tidak pernah ditimpa; `verified_amount` disimpan terpisah; selisih wajib `amount_adjustment_reason`; tidak boleh melebihi saldo outstanding. Hanya `verified_amount` masuk `PaymentService::applyPaymentToOrder`. **Tanpa self-approval:** yang submit/menerima kas tidak bisa memverifikasi.
- **Invoice:** nomor = `orders.order_no` (tanpa penomoran baru). COD: terbit saat order dibuat. Transfer/DP: terbit di **pembayaran terverifikasi pertama** (parsial/DP/lunas); pembayaran pertama pending/rejected tidak menerbitkan apa pun dan `GET /orders/{order}/invoice` menjawab 409. `orders.invoice_issued_at` sekali setel, tidak pernah digeser; `order_invoice_snapshots` menyimpan state saldo immutable berversi (v1 = penerbitan, versi berikutnya saat saldo berubah). Cicilan lanjutan tidak membuat invoice utama baru. Order legacy elegan distempel lazily saat akses pertama.
- **Due date per order:** `orders.due_date`, hanya oleh `admin`/`keuangan`/`super_admin` (cabang sama) dengan alasan wajib; tiap perubahan ter-audit di `order_due_date_changes` (lama, baru, alasan, aktor, waktu); tidak pernah menggeser tanggal terbit invoice. Overdue hanya flag tampilan — tidak memblokir apa pun.
- **Bukti pembayaran:** bukti transfer/kas baru disimpan di disk **privat** (`proof_disk = local`), disajikan hanya via `GET /payment-proofs/{id}` (pemilik, role finance/office cabang sama, Sales/Korsal/SKS dalam scope, super_admin). Legacy tetap `public` dengan URL-nya; tanpa migrasi massal.
- **Batas upload (backend otoritatif, frontend mirror):** gambar produk 5 MiB, foto profil 2 MiB, bukti pembayaran/pengiriman/COD 5 MiB, banner promosi 10 MiB, logo toko/situs 2 MiB, gambar lain 5 MiB; hanya JPEG/PNG/WebP (tanpa SVG/GIF/HEIC/BMP/TIFF). 5 MiB = 5.242.880 byte (`max:5120` KiB). Prasyarat deployment (tidak diterapkan di repo): PHP `upload_max_filesize ≥ 10M`, `post_max_size ≥ 12M`; nginx `client_max_body_size ≥ 12m`.

## §30 KMSIT Routing Server — kebijakan transport Kurir Toko (Owner RT-DEC-01..05, 2026-11-04)

- **KMSIT adalah sumber jarak/rute, bukan kebijakan harga dan bukan metode pengiriman customer-facing.** "Kurir Toko" tetap satu metode berbasis jarak (`provider_code = openroute`); ORS & KMSIT hanya menyuplai `distance`/`duration` terverifikasi.
- **Fallback transparan, fail closed:** ORS sehat → ORS. ORS gagal/tidak dikonfigurasi, OFF global, atau OFF oleh Agen → endpoint KMSIT aktif Agen itu. Tanpa hasil routing valid dari sumber mana pun → 422 (tanpa jarak/durasi/harga fabrikasi; tanpa substitusi gratis-ongkir saat gagal). RajaOngkir (Ekspedisi) tidak pernah fallback ke KMSIT dan tarifnya tak tersentuh bagian ini.
- **Satu tarif:** tarif Kurir Toko per-Agen (`shipping_configurations` ber-anchor provider `openroute`: `price_per_km`, `minimum_distance_km`, `minimum_charge`, override gratis-ongkir) adalah satu kebenaran harga untuk kedua sumber routing; KMSIT tidak boleh mengubahnya; formula/pembulatan tidak berubah.
- **Endpoint per-Agen, jaringan operator-guarded:** tiap Agen mengonfigurasi endpoint KMSIT GATEWAY (`base_url` + API key write-only, terenkripsi, tidak pernah di-echo). Setiap save/test/use melewati `EndpointGuard`: hanya http(s), tanpa kredensial/query/fragment tersemat, alamat publik saja kecuali host tepat ter-list operator di `KMSIT_TRUSTED_HOSTS` (metadata/link-local selalu diblok), redirect dinonaktifkan, IP ter-vet di-pin untuk request aktual. Error hanya alasan mesin yang aman.
- **Isolasi cache:** hasil rute di-cache per Agen + endpoint + profil + pasangan koordinat (5 menit); tidak pernah lintas Agen/provider.
- **Registrasi:** baris provider `kmsit` ditambah nonaktif oleh migrasi `2026_11_04_100000` (seed tidak pernah menimpa status aktivasi); aktivasi = saklar global Super Admin, sama seperti ORS/RajaOngkir.


## §31 REM-001…REM-006 — fulfilment, handover ganda, assignment, pembayaran (Owner-locked 2026-11-10; **IMPLEMENTED di source DEV, backend; UI sebagian**)

Sumber: `PRIMECLASSY-V2-REM-001-006-ARCHITECTURE-TECHNICAL-SPEC.md`. Mengubah/menyempurnakan §16, §11 dan §13 di atas bila bertentangan.

- **Handover fisik dua pihak (menggantikan "Admin approve rutin" di §16):** Gudang menyiapkan/menyerahkan (proposal = jumlah disiapkan) → **Koordinator Kurir menerima** (`POST /warehouse/fulfillment-proposals/{id}/receive`): per item `accepted + short + damaged = jumlah disiapkan` (accepted tak boleh > disiapkan). **Hanya accepted** memindahkan stok (Transit → Shipping), mengonsumsi reservasi dan menaikkan `fulfilled_qty` — tepat sekali (status proposal `pending → approved` sebagai gerbang tunggal; retry/replay tidak menggandakan). Short/damaged tetap di Transit dengan reservasi utuh, tetap *outstanding* (Gudang dapat menyiapkan ulang) dan **tidak** dianggap siap kirim; penyelesaiannya keputusan Admin (tidak ada penghapusan otomatis). `POST …/approve` (Admin) tetap ada **hanya sebagai jalur pengecualian** = seluruh jumlah disiapkan diterima; gerbang statusnya sama.
- **Lingkup Gudang (REM-001):** aturan lama (diproses & belum ada kurir) tetap; ditambah — order tetap terlihat (status `diproses`/`dikirim`) selama Stock Request punya demand Agent *outstanding* (`pending/partial`), agar batch lanjutan dapat disiapkan. Order dengan shipment `self_sub` (SKS) tidak pernah diperluas.
- **Assignment Kurir Toko (REM-003):** **hanya Koordinator Kurir** cabang itu (route, `ShipmentPolicy::assignCourier`, `CourierService::assignCourier`) — Super Admin/Agen/Admin ditolak 403. Shipment provider `rajaongkir`/`pickup` (data persisted) tidak dapat ditugaskan dan tidak muncul di antrean dispatch. Assignment hanya untuk kuantitas yang sudah *accepted*: per target produk, `accepted ≥ kuantitas pada shipment lain yang sudah ber-kurir + shipment ini`; sehingga batch ke-2/3 mengikuti penerimaan berikutnya. Baris Sub (SKS) di luar gerbang ini. *Belum diimplementasi:* pemecahan kuantitas satu item ke beberapa batch setelah batch pertama `dikirim` (batch = shipment yang sudah ada), refusal/reverse-custody dua konfirmasi, pembatalan parsial sisa — lihat §15 Known Limitations/laporan REM.
- **RajaOngkir (REM-003):** dispatch (`dikirim`, semua jalur termasuk bulk Admin/Agen) hanya bila **lunas penuh incl. ongkir**, seluruh barang accepted (tanpa demand outstanding), seluruh item order dalam **satu** shipment (tanpa parsial), dan serah terima ekspedisi tercatat. Koordinator mencatat via `PATCH /shipments/{id}/expedition-handover` (`carrier` harus = ekspedisi pilihan checkout di `provider_meta.courier`, `tracking_number` token 6–40 `[A-Za-z0-9-]`, `evidence` foto wajib; idempoten untuk nomor sama, 409 untuk nomor berbeda). Format resi spesifik per-ekspedisi **tidak** didefinisikan di source dan tidak dikarang. Tanpa COD/DP/cicilan/Kurir Toko; kuote/fee tak berubah.
- **Pembayaran Kurir Toko (REM-004, melengkapi §13):** maksimal **3 pembayaran berhasil** per order (transaksi `paid` + baris additional-payment `paid` yang tak diwakili transaksi `paid`; pending/failed/expired/cancelled tidak dihitung; refund tidak menghapus histori), gabungan COD/DP/transfer. Pembayaran ketiga **wajib melunasi** seluruh sisa; nominal > sisa ditolak; pembayaran keempat ditolak — diputuskan di `PaymentService::assertPaymentAllowed` di bawah lock Order (verifikasi transfer, konfirmasi COD, `markCod`, additional payment) dan sebagai pre-check saat request (installment/settlement/bukti COD). Klasifikasi dari shipment persisted (bukan `delivery_mode` saja); SKS/pickup/tanpa shipment tidak dibatasi. **COD parsial:** bukti COD membawa `amount` (nominal nyata, kolom `cod_payment_proofs.collected_amount`); satu pengambilan = satu transaksi `paid` bernominal nyata + satu transaksi COD pending untuk sisa; order tak otomatis lunas. Receipt shipment menampilkan **sisa tagihan order** (bukan total berulang). RajaOngkir hanya boleh pembayaran penuh sekaligus.
- **Perubahan order Admin setelah pembayaran (Owner Decision 01, 2026-11-10):** mekanisme *existing* dipakai ulang, tanpa ledger paralel. Kenaikan nilai (QTY/SC-03) pada order yang sudah lunas → `OrderAdditionalPayment` (+ transaksi `additional_payment` bila transfer) — **bukan** cicilan reguler: tidak dihitung dalam batas 3 pembayaran dan tidak ditolak oleh batas itu (`assertPaymentAllowed(..., additional: true)`); pembayaran reguler hanya menagih saldo reguler (sisa dikurangi additional pending) sehingga tidak ada double collection. Penurunan nilai → refund existing (`order_item_adjustments`/refund ledger) hanya bila timbul kelebihan bayar. Perubahan QTY juga menyinkronkan demand Stock Request: reduce melepas reservasi hanya untuk bagian belum-accepted, bagian accepted dikembalikan Shipping → Transit (reservasi sudah terkonsumsi); increase menaikkan requested/remaining.
- **Batch lanjutan (REM-003):** Koordinator memisah bagian belum-dispatch suatu baris ke batch sendiri (`POST /dispatch/order-items/{item}/split-batch`) — memakai split item kanonis (demand belum-accepted ikut pindah, harga/fee dibagi, tanpa reservasi ganda, tanpa subsistem baru), boleh setelah batch lain `dikirim`; baris dispatched/tertugaskan/SKS/ekspedisi/pickup ditolak. Assignment & antrean dispatch menerima order `dikirim` yang masih punya shipment pending.
- **Penolakan pengantaran (REM-003):** Kurir mencatat (item tetap `dikirim`, tak bisa ditandai terkirim) → Kurir serah-balik → Koordinator konfirmasi terima (dua konfirmasi) → **redelivery** (item `diproses` pada shipment baru, stok accepted tetap) atau **kembali ke Gudang** (Shipping → Transit, reservasi & demand outstanding dipulihkan; idempoten). Uang tidak disentuh — pembatalan/refund tetap lewat adjustment Admin. Retur pickup pelanggan (`return_items`) **tidak berubah** dan tidak melewati gerbang receipt.
- **Pembatalan parsial sisa belum-dikirim (Owner Option A, 2026-11-11 — IMPLEMENTED backend+UI):** operasi domain khusus `OrderFulfillmentService::cancelUndeliveredQuantity` (`POST /orders/{order}/items/{item}/cancel-undelivered`, `GET …/cancel-eligibility`; otoritas = `manageFulfillment` existing: super_admin/agen/admin cabang). Eligible hanya bila order `diproses`/`dikirim`, item `diproses` (bukan `dikirim` = custody kurir, bukan `terkirim`), Agent-sourced (SKS tidak), tanpa penolakan terbuka dan tanpa additional payment pending. Idempotensi: klien mengirim `expected_quantity` (jumlah aktif yang dilihat); beda → 409, tanpa efek ganda. **Tidak** melewati guard dengan melonggarkan `assertQuantityAdjustable` — Admin QTY biasa tetap beku setelah snapshot handover. Efek memakai reduksi kanonis: snapshot handover hanya *dikonsumsi* (unit ditandai, alokasi/checksum tidak berubah — §26), reservasi dilepas untuk bagian belum-accepted, bagian accepted kembali Shipping → Transit, demand outstanding menyusut, total dihitung ulang, kelebihan bayar → satu baris refund existing (`order_item_adjustments`), transaksi pembayaran tak diubah, status order direkonsiliasi (semua sisa delivered → `terkirim`), shipment kosong keluar dari antrean dispatch. Catatan: baris hasil split setelah freeze mengonsumsi unit snapshot leluhur; tidak memakai fallback refund legacy (§31a F01).
- **Penolakan sebagian quantity:** `POST /shipments/{s}/items/{item}/refuse` menerima `quantity` opsional; bila < jumlah item, unit ditolak dipisah menjadi item saudara pada shipment yang sama (tetap `dikirim`, demand accepted ikut pindah — tidak ada demand baru, nilai/fee dibagi), sisa unit dikirim normal; rantai custody dua konfirmasi dan disposition hanya atas unit yang ditolak.

### §31a Remediasi audit independen (F01–F09, 2026-11-12)
- **F01 Refund Ledger (split child):** unit snapshot beku milik item yang ada saat freeze; child hasil split *setelah* freeze (batch/penolakan sebagian) membaca unit dari leluhur terdekat lewat `split_from_order_item_id` (`OrderFinancialSnapshotService::unitOwnerItemId`) — baris beku tidak diubah/dipindah; konsumsi hanya menandai unit. Setelah snapshot non-shortfall berlaku, unit habis ⇒ 409 `snapshot_units_exhausted`; tidak ada lagi fallback legacy yang membayar refund di luar ledger.
- **F02 Bypass status:** `CourierService::assertKurirTokoDispatchReady` (ditugaskan + accepted per baris) dipakai pada SEMUA jalur `dikirim` (kurir per-shipment/per-item dan bulk office); bulk `terkirim` ditolak bila ada penolakan terbuka.
- **F03 Additional payment:** satu urutan lock untuk semua entry point — verifikasi bukti → Order → transaksi → obligation. Verifikasi transfer menyelesaikan obligation terkait; "paid" langsung menutup transaksi + bukti pending; yang kedua selalu 422. Tidak ada payment truth baru.
- **F03 verifikasi parsial additional payment (keputusan Owner, sesi 2026-11-11):** pengajuan Rp25.000 dengan dana terverifikasi Rp10.000 hanya menambah paid_amount Rp10.000. Obligation asal tetap pending dengan sisa Rp15.000 dan tautan item tetap; satu transaksi additional_payment pending baru menagih sisa. Bagian Rp10.000 disimpan sebagai baris additional payment paid yang terhubung ke transaksi/bukti semula, sehingga total riwayat obligation tetap Rp25.000. Nominal pengajuan transaksi/bukti tidak ditimpa. Perubahan dan audit dilakukan atomik; replay bukti ditolak. Pembayaran sisa melalui transfer atau status paid langsung tetap di luar batas tiga cicilan reguler.
- **F04 COD undo:** `markCod(false)` hanya untuk koleksi tunggal penuh (transaksi paid yang sama kembali pending); setiap koleksi parsial ditolak (`cod_undo_not_allowed`).
- **F05 RajaOngkir:** shipment ekspedisi hanya berangkat utuh; status per-item `dikirim` ditolak bila item lain belum berangkat.
- **F06 Alokasi accepted:** gerbang assignment/dispatch memakai `StockRequestItem.fulfilled_qty` **per baris** — angka yang sama yang dibalik oleh pembatalan/penolakan; tidak ada pooling antar baris.
- **F07 Short/damaged:** `stock_request_items.damaged_hold_qty` menahan unit rusak (tidak dapat disiapkan ulang, dilepas, atau dibatalkan). Short tetap outstanding dan dapat disiapkan ulang. Admin menyelesaikan lewat `POST /warehouse/stock-request-items/{id}/resolve-discrepancy` (`restock` diblokir sampai Owner menyetujui prosedur inspeksi fisik yang dapat diverifikasi; `write_off` = Transit berkurang lewat movement `adjustment`, hold dicabut, baris tetap berutang unit itu). Resolusi wajib membawa UUID `idempotency_key`; pengulangan kunci pada item yang sama ditolak 409 tanpa mutasi. Proposal antre diperiksa ulang terhadap remaining dikurangi hold saat settlement. Persetujuan Admin atas proposal = **pengecualian**: alasan wajib + audit `stock_request.admin_exception_approval`. Catatan interpretasi: `write_off` sebagai hasil keputusan Admin atas unit rusak adalah pilihan implementasi minimal; disposition fisik lain belum didefinisikan Owner.
- **F08 UI ekspedisi:** panel Koordinator: daftar + gerbang kesiapan, minta ke Gudang, handover, "Tandai Dikirim (seluruh pengiriman)", pagination, state loading/error.
- **Akses invoice (UAT-03):** satu invoice per order (snapshot yang sama, tidak digandakan per dashboard) dapat dilihat Pemesan, Admin/Agen/Keuangan/Super Admin cabang, serta Sales dan Korsal yang order-nya dalam kewenangan `OrderPolicy::view`; salinan Pemesan tidak memuat identitas referral internal; melihat invoice tidak mengubah status/pembayaran (COD tetap "Belum Dibayar" sampai dibayar).
- **F09 Invoice:** snapshot saldo pada koleksi COD, perubahan total (`reconcileTotals`), pelunasan additional payment dan refund diproses; idempoten (hanya bila saldo berubah), satu invoice utama.
- **Isolasi SKS:** penolakan/serah-balik, minta Gudang, split batch, dan pembatalan sisa menolak shipment/baris `self_sub`; tombol "Ditolak" tidak ditawarkan untuk `self_sub`.
