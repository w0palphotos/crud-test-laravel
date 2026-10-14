# Dokumentasi proyek

API JSON untuk katalog produk, dibangun di atas Laravel 13 dengan auth token Sanctum dan
spec OpenAPI yang menggerakkan Swagger UI.

Ditulis untuk seseorang yang berasal dari JavaScript. Setiap contoh kode disalin dari file
asli di repositori ini, dan setiap perintah telah dijalankan di lingkungan ini.

## Mulai di sini

| Dokumen | Apa yang dicakup |
| --- | --- |
| [00. Topologi proyek](topology/00-project-topology.md) | Peta runtime, routes, kode, data, dan deployment saat ini |
| [01. Menjalankan proyek](01-running-it.md) | Setup, memulai server, perintah yang akan Anda gunakan sehari-hari |
| [02. PHP untuk pengembang JavaScript](02-js-to-php.md) | Perbedaan sintaks yang benar-benar akan menjebak Anda |
| [03. Siklus hidup request](03-request-lifecycle.md) | Apa yang terjadi antara URL dan respons JSON |
| [04. Menambahkan field](04-adding-a-field.md) | Mengubah resource ujung ke ujung, dengan setiap file didaftar |
| [05. Referensi endpoint](05-endpoints.md) | Setiap route, auth-nya, dan body-nya |
| [06. Autentikasi dan token](06-auth-and-tokens.md) | Cara kerja alur token dan mengapa cakupannya ketat |
| [07. Pengujian](07-testing.md) | Menjalankan suite dan menulis tes baru |
| [08. Membuat endpoint](08-creating-an-endpoint.md) | Menambah resource baru dari nol, dari file yang digenerasi hingga terkirim |
| [09. Menginstal dan menggunakan library](09-adding-a-library.md) | Composer dan npm, `require` versus `require-dev`, penerbitan config |
| [10. Deploy ke Vercel](10-deploying.md) | Config fungsi serverless, database eksternal, kegagalan diam-diam |
| [11. UI web](11-the-web-ui.md) | Inertia dan Vue, props bersama, mengapa SSR mati, dan dua sistem auth |
| [12. Shell administrator](12-the-admin-shell.md) | Sidebar dan topbar, palet admin, dasbor, dan grafik |
| [13. Verifikasi database SQLite](13-database-verification.md) | Verifikasi integritas, relasi, dan keunikan hanya-baca untuk database lokal |
| [14. Diagram database](14-database-diagram.md) | Penjelasan tabel demi tabel, diagram relasi, kardinalitas, dan perilaku delete |
| [15. Migrasi JS-ke-PHP](js-to-php-migration/15-js-to-php-migration.md) | Perilaku reporting-app yang di-port, perbedaan yang disengaja, dan kesenjangan tersisa |

## Mana yang dibaca berikutnya

Jika Anda belum menjalankan proyek, [01](01-running-it.md) dulu.

Jika Anda baru di PHP, [02](02-js-to-php.md) sebelum yang lain. Sebagian besar kebingungan
di codebase Laravel adalah kebingungan PHP yang memakai kostum framework.

Jika Anda akan membangun sesuatu, [08](08-creating-an-endpoint.md). Ini tugas inti dan ia
menyentuh setiap dokumen lain. [04](04-adding-a-field.md) adalah saudara yang lebih kecil
untuk saat hal yang Anda ubah sudah ada.

## Aturan dasar untuk codebase ini

Ini adalah keputusan yang dibuat proyek ini yang tidak akan Anda temukan di tutorial
Laravel umum, karena dibuat dengan sengaja.

**Gunakan `php artisan make:` untuk membuat file.** Ia menyiapkan namespace, imports, dan
stub factory atau migration. Membuat class manual biasanya berarti baris `use` yang hilang.

**Model memakai atribut PHP, bukan docblock.** Laravel 13 membaca `#[Fillable([...])]` di
atas class. Anda juga akan melihat proyek lama memakai properti
`protected $fillable = [...]`. Keduanya berfungsi. Proyek ini memakai atribut.

**Route untuk resource dideklarasikan satu per baris, bukan via `apiResource`.** Pembaruan
di sini bersifat parsial, sehingga route `PUT` akan menjanjikan semantik penggantian penuh
yang tidak dipenuhi controller. Lihat [01](01-running-it.md) untuk perintah yang
menampilkan routes.

**Tidak ada route `PUT`.** Hanya `PATCH`, yang berarti pembaruan parsial.

**Jalankan Pint sebelum Anda commit.** `vendor/bin/pint --dirty --format agent`

**Generasi ulang spec OpenAPI setelah mengedit atribut `#[OA\...]` mana pun.** File spec
di-gitignore, sehingga browser akan menampilkan output basi sampai Anda melakukannya. Lihat
[01](01-running-it.md).

## Versi di proyek ini

Dibaca dari checkout ini, bukan dari rentang `composer.json`.

| Hal | Terpasang | Dibutuhkan di composer.json |
| --- | --- | --- |
| PHP | 8.5.10 | `^8.3` |
| laravel/framework | 13.33.0 | `^13.17` |
| laravel/sanctum | 4.3.3 | `^4.0` |
| darkaonline/l5-swagger | 11.1.0 | `^11.1` (dev) |
| phpunit/phpunit | 12.5.36 | `^12.5.12` (dev) |
| laravel/boost | 2.10 | `^2.10` (dev) |
| Database | SQLite di `database/database.sqlite` | `DB_CONNECTION=sqlite` |

Sanctum dan l5-swagger keduanya ada di `require`, sehingga `composer install --no-dev`
menjaga API dan route `/api/documentation` tetap berfungsi. Swagger dipindah dari
`require-dev` dengan sengaja: halaman dokumentasi disajikan di production di sini, dan
rusak saat tidak demikian.
