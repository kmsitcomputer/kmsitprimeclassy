# PrimeClassy V2 — REM-001…006 Manual Browser UAT Checklist

Lingkungan: DEV `https://primeclassy.kmsit.localhost/` (bundle `index-BAQQwrr7.js`). Gunakan data uji; jangan produksi.
Status: **BELUM dijalankan** — tidak ada tooling browser pada sesi implementasi. Isi kolom Hasil saat UAT; kegagalan = catat langkah + pesan error.

| # | Peran | Langkah | Hasil yang diharapkan | Hasil |
|---|---|---|---|---|
| 1 | Admin | Buka order `diproses` → "Ubah jumlah" turunkan QTY (sebelum dispatch) | Total, sisa, demand Gudang turun; refund pending hanya bila kelebihan bayar | |
| 2 | Admin | Tambah produk SC-03 | Item baru muncul; demand Gudang naik; additional payment hanya bila order sudah lunas | |
| 3 | Keuangan | Tandai additional payment / proses refund | Saldo & ringkasan konsisten; "Pembayaran berhasil X dari maks. 3" tidak bertambah | |
| 4 | Koordinator | Dispatch → baris: "Minta ke Gudang" | Teks "diminta …" muncul; tidak ada request ganda | |
| 5 | Gudang | Antrean Order Diproses → siapkan proposal | Order terlihat; proposal pending | |
| 6 | Koordinator | Tab "Penerimaan Gudang": isi diterima/kurang/rusak (jumlah harus = disiapkan) → Konfirmasi | Hanya "diterima" menaikkan stok Shipping; sisa tetap outstanding | |
| 7 | Koordinator | Antrean: "Pisah batch" jumlah sisa belum diterima | Batch baru (baris baru); batch lama tetap dapat ditugaskan | |
| 8 | Koordinator | Tugaskan kurir (hanya batch ber-accepted) | Berhasil; batch tanpa accepted ditolak dengan pesan | |
| 9 | Admin/Agen | Buka Dispatch | Tidak ada kontrol penugasan (hanya Koordinator) | |
| 10 | Kurir | Ambil → Terkirim (foto bukti) sebagian item | Item terkirim; sisa tetap | |
| 11 | Kurir | "Ditolak" dengan jumlah 2 dari 5 + alasan | Item terpecah: 3 dapat dikirim, 2 ditolak; "Serahkan ke Koordinator" muncul | |
| 12 | Kurir→Koordinator | Serahkan → Koordinator tab "Penolakan": Konfirmasi Terima → Kirim Ulang / Kembalikan ke Gudang | Dua konfirmasi tercatat; stok/demand hanya untuk 2 unit | |
| 13 | Admin | Order `dikirim`: "Batalkan sisa yang belum dikirim" pada item `diproses` | Form kuantitas+alasan+konfirmasi; setelah submit sisa berkurang, refund pending bila overpaid; item `dikirim`/terkirim tidak menawarkan tombol | |
| 14 | Admin | Ulangi pembatalan dari tab lama (stale) | Pesan 409 "jumlah aktif sudah berubah" | |
| 15 | Koordinator | Tab "Ekspedisi": order lunas+lengkap → resi + foto | Hanya aktif bila 3 gerbang ✓; ekspedisi harus sama dengan checkout | |
| 16 | Koordinator | Ubah status ekspedisi ke dikirim sebelum resi | Ditolak | |
| 17 | Konsumen/Agen | COD: kirim bukti dengan nominal parsial | Order `partially_paid`; sisa tetap; pembayaran ke-3 wajib lunas | |
| 18 | SKS | Alur self_sub (buat order, pickup, kirim) | Tidak berubah | |
| 19 | Kurir | Retur pickup yang ditugaskan | Tidak berubah; tidak melewati receipt gate | |
| 20 | Lintas cabang | Admin/Koordinator cabang lain mengakses ID order/shipment | 403/404 | |
| 21 | Koordinator | Tab "Ekspedisi": Minta ke Gudang → handover → "Tandai Dikirim (seluruh pengiriman)"; coba Next/Prev bila >15 | Alur lengkap tanpa API manual; tombol dikirim aktif hanya bila 3 gerbang ✓ + resi tercatat | |
| 22 | Koordinator | Penerimaan dengan rusak ≥1, lalu Admin batalkan sisa | Unit rusak tetap tertahan; hanya unit lain yang dilepas | |
| 23 | Admin | Antrean Proposal: Setujui tanpa alasan | Tombol nonaktif; dengan alasan ≥5 huruf berhasil (pengecualian) | |
| 24 | Keuangan | Additional payment transfer: verifikasi bukti, lalu coba "paid" langsung | Ditolak 422; saldo bertambah sekali | |
| 25 | Keuangan | COD parsial lalu "batalkan status COD" | Ditolak (cod_undo_not_allowed); saldo & transaksi tetap | |
| 26 | SKS | Self_sub: tidak ada tombol "Ditolak"; fitur lain tidak berubah | Sesuai | |

