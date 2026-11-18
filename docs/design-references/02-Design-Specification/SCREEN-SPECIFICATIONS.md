# Screen Specifications — Phase 5 Visual Baseline

## S-1 Landing
Filter dossier, status ringkas, pencarian, daftar kartu POS–CO parity, pagination, aksi berbasis role/Agent; readiness tidak sama dengan izin admission.

## S-2 Dossier Workspace
Identitas, timeline, item, pembayaran, komisi, bukti, review, verifikasi, admission, log; action edit/revisi/print/PDF hanya bila policy dan API mendukung; tanggal historis, penerimaan pembayaran, pencatatan berbeda.

## S-3 Financial Verification
Khusus keuangan sesuai policy; stock claim, payment recognition, commission settlement, opening treatment; validasi bukti opname tanpa mutasi stok fisik; satu transaksi atomik, tanpa partial save yang tidak didukung. Status review dan status finance terpisah.

## S-4 Admission Confirmation
Eligibility/read-only, canonical Order preview tanpa nomor Order terbit sebelum sukses; dry-run dan final disabled selama flag OFF; admission oleh Admin/Agen berwenang dalam jaringan yang benar dan bukan submitter; atomik satu Order.

## S-5 Report Registry
Filter, periode, draft/issued/closed, detail, immutable issued/closed, hash/artefak bila endpoint tersedia; issue/close khusus keuangan; API PDF/CSV, delete, hash perlu dibuktikan, bukan diasumsikan.

## S-6 Evidence Viewer
Daftar dan preview privat melalui endpoint terautentikasi; Cache-Control private, no-store; verifikasi hash oleh backend, mismatch menghentikan preview; immutable/versioning/revisi sesuai kontrak; download/new-tab/history/thumbnail hanya bila didukung API.

## Acceptance
Setiap layar harus dipetakan ke route, policy, role, scope Agent, endpoint, state, kontrak dan acceptance criteria aktual sebelum implementasi diizinkan.
