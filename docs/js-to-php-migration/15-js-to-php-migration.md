# 15. Migrasi JS-ke-PHP

Ini mencatat apa yang benar-benar telah dibawa dari aplikasi pelaporan JavaScript lama ke aplikasi Laravel ini. Ia adalah catatan paritas dan status migrasi, bukan tutorial bahasa PHP. Untuk sintaks PHP yang ditujukan bagi pengembang JavaScript, lihat [02. PHP untuk pengembang JavaScript](../02-js-to-php.md).

## Batas bukti

Hanya klaim yang dapat diverifikasi di dalam repositori ini yang layak ada di tabel paritas di bawah. Aplikasi lama itu sendiri berada di luar checkout ini, sehingga dokumen ini tidak merekonstruksi arsitektur, rute, atau perilaku yang belum diporting dari ingatan.

Bukti migrasi yang dapat diverifikasi saat ini terdiri dari:

- Angka penjualan kanonis yang disalin ke `database/seeders/SalesDeskSeeder.php:44-58`
- Aserti paritas di `tests/Feature/SalesDeskSeedTest.php:35-104`
- Pemformatan rupiah yang identik di `resources/js/shared/rupiah.js:1-9`
- Stack Chart.js bersama di `resources/js/components/RevenueChart.vue:10-13`
- Agregasi level database di `app/Actions/SalesSummary.php:38-55`
- Data grafik bulanan yang diisi kekosongannya di `app/Actions/SalesSummary.php:68-94`

## Paritas yang selesai

| Perilaku yang dimigrasi | Bukti aplikasi lama di repo ini | Implementasi Laravel | Verifikasi |
|---|---|---|---|
| Tujuh penjualan kanonis | `SalesDeskSeeder::CANONICAL` dan `CANONICAL_TOTAL` | Di-seed tanpa perubahan sebagai `SLV-CANONICAL-01` hingga `SLV-CANONICAL-07` | `SalesDeskSeedTest` menegaskan ketujuhnya dan total Rp615,000,000-nya |
| Pengelompokan wiraniaga dan kota | Entri Andi, Budi, Citra; Jakarta, Bandung, Surabaya; Honda, Yamaha, Suzuki | Nama yang sama di-seed sebagai dimensi dan entri katalog | Pengujian menegaskan nama wiraniaga/kota dan total Jakarta Rp195,000,000 milik Andi |
| Rata-rata kanonis | 87,857,142.86 | Disimpan dan dirata-ratakan sebagai rupiah desimal | Pengujian menegaskan rata-rata dua desimal |
| Rekonsiliasi level baris | Setiap nominal fixture adalah total penjualan | Setiap penjualan kanonis memiliki item yang `line_total`-nya sama dengan `grand_total` | Pengujian merekonsiliasi setiap baris kanonis |
| Agregasi pendapatan | Penjualan yang dibatalkan dikecualikan dari pendapatan | `SalesSummary` mengecualikan `SaleStatus::Cancelled` sebelum menjumlahkan | Pengujian menegaskan perilaku bulan berjalan, bulan sebelumnya, dan pengecualian |
| Pengelompokan bulanan | Pendapatan per bulan kalender | Ekspresi bulan spesifik-driver dengan seri dua belas bulan bersama yang diisi kekosongannya | `DashboardTest` dan pengujian seed mencakup pengelompokan, kesinambungan, dan kesepakatan grafik |
| Tampilan rupiah | Pemformatan aplikasi pelaporan yang identik | `formatRupiah` dan `formatCompact` | Angka yang sama terbaca sama di kedua aplikasi |
| Grafik pendapatan/transaksi | Stack Chart.js bersama | `RevenueChart.vue` dengan sumbu rupiah dan jumlah transaksi terpisah | Pengujian dasbor memverifikasi seri bulanan di balik grafik |

Dua perintah pengujian yang relevan dijalankan saat dokumen ini ditulis:

```sh
php artisan test tests/Feature/SalesDeskSeedTest.php --compact
php artisan test tests/Feature/Web/DashboardTest.php --compact
```

Keduanya lolos: 13 pengujian dan 90 asersi untuk seed, serta 14 pengujian dan 197 asersi untuk dasbor.

## Perbedaan yang disengaja

Ini bukan pekerjaan migrasi yang belum selesai.

- Penjualan kanonis memakai pajak nol karena fixture mencatat total bruto aplikasi pelaporan. Menambah PPN akan mengubah total terpaku Rp615,000,000.
- Seeder menambahkan volume yang digenerasi di luar tujuh kanonis agar grafik dan paginasi punya riwayat.
- Harga produk memakai `decimal(14,2)` di seluruh kolom uang karena harga katalog Rp245,000,000 tidak muat di langit-langit `decimal(10,2)` lama pada Postgres.
- Label bulan memakai array singkatan Indonesia yang tetap di samping pemformatan tanggal penuh yang sadar-locale.
- Otorisasi administrator, unggahan, sanitasi rich-text, RLS, akun biasa, dan provisi administrator interaktif adalah urusan baru aplikasi Laravel, bukan porting aplikasi lama.
- `docs/02-js-to-php.md` tetap tentang belajar PHP, bukan tentang kelengkapan migrasi.

## Belum terverifikasi terhadap aplikasi lama

Jangan perlakukan berikut sebagai telah dimigrasi hingga perbandingan langsung tersedia:

- Inventaris rute aplikasi lama yang lengkap
- Perilaku autentikasi atau otorisasi aplikasi lama
- Pesan validasi dan kasus tepi aplikasi lama
- Pekerjaan latar, notifikasi, penanganan berkas, atau integrasi aplikasi lama
- Perilaku interaksi grafik aplikasi lama yang persis di luar stack bersama dan angka yang ditampilkan
- Aturan bisnis apa pun yang tidak diwakili fixture kanonis atau pengujian

## Definisi selesai untuk pekerjaan paritas mendatang

Perilaku dihitung telah dimigrasi hanya bila memiliki:

1. Ekspektasi aplikasi lama yang dinyatakan dan dapat dilacak ke bukti repositori.
2. Implementasi Laravel.
3. Pengujian otomatis yang menegaskan hasil teramati yang sama.
4. Dokumentasi di berkas ini, bukan komentar yang tersebar.

Pustaka bersama atau kemiripan visual saja bukan kelengkapan migrasi.
