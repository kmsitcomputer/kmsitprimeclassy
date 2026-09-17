<?php

/*
|--------------------------------------------------------------------------
| Application Messages
|--------------------------------------------------------------------------
| Every literal string the API ever returns to a client — success messages,
| domain exception messages, system-level errors — lives here instead of
| inline in a controller/service, so switching App::setLocale() (see
| App\Http\Middleware\SetLocale) changes them everywhere at once. Indonesian
| is the source of truth; en/zh/ar mirror the same key structure exactly.
*/

return [

    'system' => [
        'validation_failed' => 'Data yang dikirim tidak valid.',
        'unauthorized_action' => 'Anda tidak memiliki akses untuk melakukan aksi ini.',
        'please_login' => 'Silakan login terlebih dahulu.',
        'not_found' => 'Data yang diminta tidak ditemukan.',
        'endpoint_not_found' => 'Endpoint tidak ditemukan.',
        'generic_error' => 'Terjadi kesalahan.',
        'server_error' => 'Terjadi kesalahan pada server.',
        'agent_not_linked' => 'Akun Anda belum terhubung ke cabang agen manapun. Hubungi Super Admin.',
        'field_required' => 'Wajib diisi.',
        'field_invalid' => 'Tidak valid.',
    ],

    'auth' => [
        'login_failed' => 'Email atau password salah.',
        'register_success' => 'Registrasi berhasil.',
        'login_success' => 'Login berhasil.',
        'logout_success' => 'Logout berhasil.',
    ],

    'order' => [
        'created' => 'Order berhasil dibuat.',
        'status_updated' => 'Status order berhasil diperbarui.',
        'cancelled' => 'Order berhasil dibatalkan.',
        'no_agent_branch' => 'Akun konsumen ini belum terhubung dengan cabang agen manapun.',
        'empty_items' => 'Order harus memiliki minimal satu item.',
        'agent_profile_incomplete' => 'Profil toko agen belum lengkap, order tidak dapat diproses.',
        'invalid_quantity' => 'Jumlah item tidak valid.',
        'insufficient_stock' => 'Stok tidak mencukupi untuk ":item".',
        'invalid_status_transition' => 'Transisi status :entity dari ":from" ke ":to" tidak diperbolehkan.',
        'status_endpoint_required' => 'Gunakan endpoint khusus untuk status ":status".',
        'payment_not_verified' => 'Pembayaran belum diverifikasi, order belum dapat diproses.',
        'invalid_village' => 'Kelurahan/desa yang dipilih tidak valid.',
    ],

    'product' => [
        'deleted' => 'Produk berhasil dihapus.',
        'image_deleted' => 'Gambar berhasil dihapus.',
        'variation_deleted' => 'Varian berhasil dihapus.',
        'category_deleted' => 'Kategori berhasil dihapus.',
        'variation_requires_attribute' => 'Varian harus memiliki minimal satu pasangan atribut-nilai.',
        'variation_not_enabled' => 'Produk ":name" tidak diaktifkan untuk memiliki varian (has_variations = false).',
        'variation_required' => 'Produk ":name" memerlukan pilihan varian.',
        'stock_uses_variation' => 'Produk ":name" memiliki varian — stok harus diatur per varian, bukan pada produk induk.',
    ],

    'fee' => [
        'updated_product' => 'Fee produk berhasil diperbarui.',
        'updated_variation' => 'Fee varian berhasil diperbarui.',
        'product_uses_variation' => 'Produk ini menggunakan varian — fee harus dibaca per varian.',
        'variation_uses_product' => 'Produk ":name" memiliki varian — atur fee per varian, bukan pada produk induk.',
    ],

    'stock' => [
        'adjusted' => 'Stok berhasil disesuaikan.',
        'agent_id_required' => 'agent_id wajib diisi untuk Super Admin.',
        'invalid_agent' => 'agent_id yang diberikan bukan akun Agen yang valid.',
        'negative_result' => 'Penyesuaian ini akan membuat stok menjadi negatif.',
        'insufficient_column' => 'Operasi stok tidak valid: :column tidak mencukupi.',
    ],

    'user' => [
        'created' => 'Akun berhasil dibuat.',
        'role_not_authorized' => 'Role ":role" tidak berwenang membuat akun role ":target".',
        'unsupported_role_combination' => 'Kombinasi role tidak didukung.',
        'agent_id_required_for_role' => 'Field agent_id wajib diisi untuk role ini.',
        'invalid_agent' => 'agent_id yang diberikan bukan akun Agen yang valid.',
        'invalid_korsal' => 'korsal_id yang diberikan tidak berada di cabang Agen ini.',
        'deleted' => 'Akun berhasil dihapus.',
        'cannot_delete_super_admin' => 'Akun Super Admin tidak dapat dihapus.',
    ],

    'referral' => [
        'not_linked_to_agent' => 'Kode referral ini belum terhubung ke cabang agen manapun.',
        'invalid_code' => 'Kode referral tidak valid.',
        'code_not_found' => 'Kode referral tidak ditemukan.',
    ],

    'cms' => [
        'block_deleted' => 'Block berhasil dihapus.',
        'block_activated' => 'Block diaktifkan.',
        'block_deactivated' => 'Block dinonaktifkan.',
        'reorder_saved' => 'Urutan block berhasil disimpan.',
        'unknown_type' => 'Tipe block ":type" tidak dikenal.',
        'type_rejects_image' => 'Tipe block ":type" tidak menerima gambar.',
        'article_deleted' => 'Artikel berhasil dihapus.',
        'page_deleted' => 'Halaman berhasil dihapus.',
    ],

    'payment' => [
        'method_required' => 'Metode pembayaran wajib dipilih.',
        'invalid_method' => 'Metode pembayaran tidak valid atau tidak aktif.',
        'method_not_configured' => 'Metode pembayaran ":name" belum dikonfigurasi. Hubungi Super Admin.',
        'unsupported_method_type' => 'Tipe metode pembayaran ":type" tidak didukung.',
        'order_not_awaiting_proof' => 'Order ini tidak menggunakan transfer bank atau sudah diproses.',
        'proof_submitted' => 'Bukti transfer berhasil dikirim, menunggu verifikasi.',
        'verified' => 'Pembayaran berhasil diverifikasi.',
        'rejected' => 'Pembayaran ditolak.',
        'already_verified' => 'Verifikasi ini sudah diproses sebelumnya.',
        'idempotency_key_required' => 'Header Idempotency-Key wajib disertakan.',
        'order_not_cod' => 'Order ini bukan order Cash on Delivery.',
        'cod_status_updated' => 'Status pembayaran COD berhasil diperbarui.',
        'cod_proof_submitted' => 'Bukti pembayaran COD berhasil dikirim, menunggu konfirmasi Admin.',
        'cod_proof_confirmed' => 'Konfirmasi pembayaran COD berhasil diperbarui.',
        'cod_proof_already_processed' => 'Bukti pembayaran COD ini sudah diproses sebelumnya.',
        'cod_proof_not_found' => 'Belum ada bukti pembayaran COD yang diunggah untuk order ini.',
        'gateway_request_failed' => 'Permintaan ke payment gateway ":name" gagal. Silakan coba lagi.',
        'gateway_config_saved' => 'Konfigurasi payment gateway berhasil disimpan.',
        'gateway_toggled' => 'Status aktif payment gateway berhasil diperbarui.',
        'gateway_environment_updated' => 'Environment payment gateway berhasil diperbarui.',
        'unsupported_gateway' => 'Payment gateway ":code" tidak didukung.',
        'invalid_dp_amount' => 'Nominal DP harus lebih dari 0 dan kurang dari total transaksi.',
        'not_dp_order' => 'Order ini bukan order Down Payment (DP).',
        'nothing_to_settle' => 'Tidak ada sisa pembayaran yang perlu dilunasi.',
        'settlement_requested' => 'Permintaan pelunasan berhasil dibuat. Konsumen perlu mengunggah bukti transfer.',
        'not_fully_paid' => 'Transaksi belum lunas. Refund/pembayaran tambahan hanya dapat diproses setelah transaksi LUNAS.',
        'already_processed' => 'Refund/pembayaran tambahan ini sudah diproses sebelumnya.',
    ],

    'shipping' => [
        'provider_toggled' => 'Status aktif provider shipping berhasil diperbarui.',
        'provider_config_saved' => 'Konfigurasi provider shipping berhasil disimpan.',
        'unsupported_provider' => 'Provider shipping ":code" tidak didukung.',
        'method_not_available' => 'Metode pengiriman yang dipilih sedang tidak tersedia.',
        'couriers_saved' => 'Pengaturan kurir berhasil disimpan.',
        'couriers_save_failed' => 'Pengaturan kurir gagal disimpan.',
        'unsupported_couriers' => 'Tidak didukung oleh provider pengiriman: :couriers.',
    ],

    'region' => [
        'imported' => 'Data wilayah berhasil diimpor: :provinces provinsi, :regencies kota/kabupaten, :districts kecamatan, :villages kelurahan/desa.',
        'invalid_csv' => 'Format file CSV tidak valid.',
        'invalid_row' => 'Baris :row tidak valid: :reason',
    ],

    'address' => [
        'created' => 'Alamat berhasil disimpan.',
        'updated' => 'Alamat berhasil diperbarui.',
        'deleted' => 'Alamat berhasil dihapus.',
    ],

    'fulfillment' => [
        'invalid_quantity' => 'Jumlah pemenuhan tidak valid.',
        'window_closed' => 'Jumlah item hanya dapat diubah selama order berstatus diproses.',
        'adjusted' => 'Jumlah pemenuhan item berhasil diperbarui.',
        'rescheduled' => 'Tanggal pengiriman item berhasil diubah.',
    ],

    'courier' => [
        'invalid_assignment' => 'Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif.',
        'no_shipment' => 'Order ini belum memiliki data pengiriman.',
        'no_profile' => 'Akun Anda belum memiliki profil kurir.',
        'not_your_delivery' => 'Order ini sudah ditugaskan ke kurir lain.',
        'assigned' => 'Kurir berhasil ditugaskan.',
        'return_confirmed' => 'Status pengembalian berhasil diperbarui.',
        'delivery_proof_required' => 'Kurir wajib mengunggah bukti pengiriman untuk menandai order sebagai terkirim.',
    ],

    'return' => [
        'item_not_delivered' => 'Item hanya dapat diajukan pengembalian setelah berstatus terkirim.',
        'invalid_quantity' => 'Jumlah pengembalian melebihi jumlah yang dapat dikembalikan.',
        'requested' => 'Pengajuan pengembalian berhasil dikirim.',
        'reviewed' => 'Pengajuan pengembalian berhasil diproses.',
        'already_reviewed' => 'Pengajuan pengembalian ini sudah diproses sebelumnya.',
        'refund_marked' => 'Status pengembalian dana berhasil diperbarui.',
    ],

    'media' => [
        'unknown_collection' => 'Kategori media tidak dikenal.',
        'upload_failed' => 'Berkas gagal diunggah.',
        'invalid_type' => 'Jenis atau ekstensi berkas tidak diizinkan.',
        'too_large' => 'Ukuran berkas melebihi batas maksimum.',
        'dimensions_too_large' => 'Dimensi gambar melebihi batas maksimum.',
        'not_found' => 'Media tidak ditemukan.',
        'deleted' => 'Media berhasil dihapus.',
        'uploaded' => 'Media berhasil diunggah.',
        'replaced' => 'Media berhasil diganti.',
    ],

    'language' => [
        'cannot_deactivate_default' => 'Bahasa default tidak bisa dinonaktifkan. Pilih bahasa default lain terlebih dahulu.',
        'default_must_be_active' => 'Bahasa harus aktif terlebih dahulu sebelum dijadikan default.',
    ],

    'profile' => [
        'current_password_incorrect' => 'Password saat ini tidak sesuai.',
        'password_updated' => 'Password berhasil diperbarui.',
        'referral_code_updated' => 'Kode referral berhasil diperbarui.',
        'referral_code_format' => 'Kode referral hanya boleh berisi huruf kapital, angka, dan tanda hubung (-).',
    ],

    'settings' => [
        'updated' => 'Pengaturan website berhasil diperbarui.',
    ],

    'agent' => [
        'not_an_agent' => 'User yang dipilih bukan seorang Agen.',
        'profile_already_exists' => 'Agen ini sudah memiliki data kontak.',
        'profile_deleted' => 'Data kontak agen berhasil dihapus.',
        'profile_updated' => 'Profil toko berhasil diperbarui.',
    ],

    'referral' => [
        'unsupported_role' => 'Reassign referral hanya berlaku untuk sales atau konsumen.',
        'invalid_target' => 'Tujuan reassign tidak valid atau berada di luar cabang agen yang sama.',
    ],

    'install' => [
        'connection_success' => 'Koneksi database berhasil.',
        'connection_failed' => 'Tidak dapat terhubung ke database. Periksa kembali host, port, username, dan password.',
        'connection_unknown_database' => 'Database tidak ditemukan. Pastikan nama database sudah benar dan sudah dibuat di server.',
        'database_saved' => 'Konfigurasi database berhasil disimpan.',
        'app_configured' => 'Konfigurasi aplikasi berhasil disimpan.',
        'finalized' => 'Finalisasi berhasil, cache aplikasi sudah dioptimalkan.',
        'cannot_lock_yet' => 'Belum bisa dikunci — akun Super Admin belum dibuat.',
        'locked' => 'Instalasi berhasil dikunci. Halaman installer tidak dapat diakses lagi.',

        'already_installed' => 'Aplikasi sudah terinstall.',
        'migration_success' => 'Migrasi database berhasil dijalankan.',
        'migrate_first' => 'Jalankan migrasi database terlebih dahulu.',
        'admin_already_exists' => 'Akun Super Admin sudah ada.',
        'admin_created' => 'Akun Super Admin berhasil dibuat. Instalasi selesai.',
    ],

];
