# Visual Design System — Baseline, bukan Design Lock

- Identitas: Modern Dark Edition — Maroon; warna dasar gelap, aksen maroon, kontras status jelas.
- Layout: standalone two-column, **no sidebar**; komposisi kolom boleh mengikuti kebutuhan tiap layar.
- UI: kartu informatif, tabel hanya bila sesuai kontrak, hierarki jelas, status, feedback, loading/empty/error/forbidden/disabled, responsif, aksesibilitas.
- POS–CO parity untuk presentasi item/order, bukan tabel generik yang melanggar kontrak.
- Warna dan nilai CSS final perlu diekstrak/ditetapkan pada rekonsiliasi; jangan menganggap pixel gambar sebagai token resmi.
- Satu canonical Order, tidak ada ledger kedua, tidak ada alur checkout POS biasa.
- Feature flag Historical Admission OFF harus terlihat dan dipaksakan oleh backend; tombol terkait disabled.
