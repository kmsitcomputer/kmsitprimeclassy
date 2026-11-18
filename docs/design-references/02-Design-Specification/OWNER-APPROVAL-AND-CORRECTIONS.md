# Owner Approval & Corrections

Tanggal keputusan: 12 November 2026 (Asia/Jakarta). Owner menyetujui baseline visual S-1..S-6 secara bertahap, dengan koreksi yang disepakati. **Bukan formal Phase 5 Design Lock.**

- **S-1:** kartu sesuai POS–CO parity, readiness bukan izin admission saat flag OFF.
- **S-2:** action edit/revisi/cetak/PDF harus sesuai policy dan API; flag OFF; tiga kategori tanggal terpisah; total ilustrasi harus konsisten.
- **S-3:** tanpa angka penyesuaian yang mengesankan mutasi stok fisik; binding finansial atomik; tidak ada partial draft tanpa API; pisahkan status review/finance dan tanggal.
- **S-4:** dry-run dan final disabled saat flag OFF; nomor Order hanya setelah admission sukses; tidak menampilkan hasil dry-run fiktif; Admin/Agen bukan submitter; satu Order atomik.
- **S-5:** issued/closed immutable; issue/close oleh keuangan; verifikasi keberadaan API, hash, PDF/CSV dan aksi hapus.
- **S-6:** authenticated private viewer no-store; hash backend, mismatch stop preview; evidence immutable dengan revisi/versioning sah; action hanya jika backend mendukung.

**Rekonsiliasi tambahan:** status awal Order historis sesuai Phase 4 adalah `diterima`, bukan `terkirim`; hak create dossier harus dibuktikan dari policy backend, tidak diasumsikan. Jangan menganggap mockup membuktikan implementasi.

**Batas:** Phase 5 final design reconciliation/documentation only; no code, tests, migrations, seeds, deployment, commit, push, feature activation. Historical Admission remains DISABLED / FAIL-CLOSED.
