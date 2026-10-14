# 13. Verifikasi database SQLite

Snapshot verifikasi untuk database pengembangan lokal:

```text
database/database.sqlite
```

Database diperiksa secara read-only. Dokumen ini mencatat hasilnya; dokumen ini tidak mengubah database.

## Identitas berkas

- Format: database SQLite 3.x
- Pengodean: UTF-8
- Ukuran saat pemeriksaan: 360,448 bytes
- Tabel, tidak termasuk tabel internal SQLite: 28
- Migrasi tercatat: 14

Berkas SQLite dibuka dengan URI koneksi read-only:

```sh
sqlite3 "file:database/database.sqlite?mode=ro" "PRAGMA integrity_check;"
```

## Integritas dan status migrasi

- `PRAGMA integrity_check` mengembalikan `ok`.
- Seluruh 14 migrasi repositori ada di tabel `migrations`.
- Tabel migrasi dengan demikian cocok dengan set migrasi yang di-checkout; hal ini tidak membuktikan bahwa database produksi yang terpisah memiliki riwayat yang sama.

## Pemeriksaan relasi dan orphan

`PRAGMA foreign_key_check` tidak mengembalikan baris.

Pemeriksaan orphan berikut juga mengembalikan nol:

- Penjualan tanpa pelanggan yang cocok: `0`
- Item penjualan tanpa penjualan atau produk yang cocok: `0`
- Profil pelanggan tanpa pelanggan yang cocok: `0`
- Event status tanpa penjualan yang cocok: `0`
- Pembayaran tanpa penjualan yang cocok: `0`

## Pemeriksaan keunikan

Pemeriksaan duplikat berikut juga mengembalikan nol:

- Referensi penjualan duplikat: `0`
- Email pengguna duplikat: `0`
- Email pelanggan duplikat: `0`
- Profil pelanggan duplikat per pelanggan: `0`
- Pembayaran duplikat per penjualan: `0`

## Snapshot data pengembangan saat ini

| Tabel | Baris | Catatan |
|---|---:|---|
| `users` | 3 | Termasuk 2 akun admin |
| `customers` | 6 | — |
| `customer_profiles` | 6 | Satu profil untuk setiap pelanggan saat ini |
| `products` | 14 | — |
| `sales` | 67 | — |
| `sale_items` | 139 | Antara satu dan tiga baris per penjualan saat ini |
| `payments` | 19 | Paling banyak satu pembayaran per penjualan saat ini |
| `sale_status_events` | 67 | Satu event untuk setiap penjualan saat ini |

Jumlah ini menggambarkan data seed pengembangan yang diperiksa. Jumlah ini bukan jaminan skema:

- Seorang pelanggan dapat ada tanpa profil.
- Sebuah penjualan dapat ada tanpa pembayaran.
- Sebuah penjualan dapat mengumpulkan lebih dari satu event status seiring waktu.
- Sebuah penjualan dapat memiliki lebih dari tiga item.

## Hal yang tidak dibuktikan oleh pemeriksaan SQLite ini

- Pemeriksaan ini tidak memverifikasi penegakan presisi `decimal(14,2)` Postgres.
- Pemeriksaan ini tidak memverifikasi perilaku Row Level Security Postgres.
- Pemeriksaan ini tidak memverifikasi status migrasi produksi.
- Pemeriksaan ini tidak menetapkan bahwa jumlah seed akan tetap sama setelah reseeding.

Untuk perilaku skema produksi, gunakan prosedur Postgres di [07. Pengujian](07-testing.md).
