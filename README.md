<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

API katalog produk dengan UI admin kecil, di-deploy ke Vercel sebagai satu kontainer
terhadap Postgres yang di-hosting.

## Apa yang dilakukan

| | |
| --- | --- |
| **API** | CRUD produk dengan autentikasi token, plus akun layanan mandiri. Setiap operasi bekerja pada akun pemanggil sendiri, sehingga tidak ada route yang memungkinkan satu pengguna membaca atau menulis ulang milik pengguna lain. OpenAPI di `/api/documentation`. |
| **Web UI** | Inertia + Vue 3: jelajahi dan cari produk, ubah produk, kelola akun Anda sendiri, dan tanyakan ke asisten. Formulir Masuk dan Daftar di-render di server dengan sengaja, sehingga berfungsi tanpa JavaScript sama sekali. |
| **Auth** | Dua skema dengan sengaja. API memakai bearer token Sanctum, UI memakai cookie sesi. Tamu yang memanggil API tetap mendapat JSON `401` bukan redirect ke formulir login. |
| **Roles** | Satu boolean. Akun biasa mendaftar sendiri dan hanya mengelola akunnya sendiri; administrator mengakses meja penjualan. Dibuat dengan `php artisan admin:ensure`, tidak pernah via seeder atau body pendaftaran. |
| **Language** | Hanya bahasa Indonesia. `lang/id` adalah satu-satunya kamus, dan juga fallback, sehingga string yang hilang tampil sebagai key-nya bukan diam-diam kembali ke bahasa Inggris. |

## Tata letak proyek

Peta kanonis runtime, routes, backend, frontend, data, dan topologi deployment ada di [00. Project topology](docs/topology/00-project-topology.md). Bagian ini hanya menyimpan poin desain yang mencegah drift keamanan.

**Dipakai bersama, dengan sengaja**

`app/Http/Requests` dan `app/Actions` ada agar kedua permukaan tidak saling drift.
`StoreProductRequest` yang sama memvalidasi produk baik saat datang sebagai JSON maupun
form post, dan `UpdateAccount` menerapkan perubahan kata sandi sekali, termasuk mencabut
token akun dengan cara apa pun. Menulis aturan tersebut dua kali adalah cara kontrol
keamanan berakhir dengan bug di satu salinan.

`app/Actions` berisi:

| Action | Tanggung jawab |
| --- | --- |
| `ListProducts` | Pengurutan, ukuran halaman, dan filter nama opsional |
| `UpdateAccount` | Perubahan profil, dan pencabutan token saat rotasi kata sandi |
| `DeleteAccount` | Pencabutan token, lalu penghapusan akun |

## Memulai

```sh
composer install
npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan l5-swagger:generate
npm run build
php artisan serve
```

`npm run build` dibutuhkan untuk web UI, bukan API: API tidak pernah me-render view,
sedangkan setiap halaman adalah komponen Vue yang di-bundle dari `resources/js/app.js` dan
dipasang oleh Inertia. Gunakan `npm run dev` untuk hot reload.

Meja penjualan khusus administrator, jadi buat dulu:

```sh
php artisan admin:ensure
```

Lalu Masuk di `/login`. Daftar di `/register` menghasilkan akun biasa, yang hanya dapat
mengelola detailnya sendiri dan tidak yang lain. Pendaftaran juga tersedia sebagai
panggilan API, dan mengembalikan token:

```sh
curl -X POST http://localhost:8000/api/account \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery9","password_confirmation":"correct-horse-battery9"}'
```

Token tersebutlah yang diminta tombol **Authorize** di Swagger UI. Kedua route berbagi
aturan validasi dan batas permintaan mereka.

## Pengujian

```sh
php artisan test
vendor/bin/pint
```

Suite API dan web terpisah, dan `ListProductsTest` memastikan kedua permukaan mengembalikan
baris yang sama dalam urutan yang sama, yang menjadi penjaga drift untuk action bersama.
Jalankan suite untuk jumlah saat ini daripada memercayai paragraf ini.

## Penerapan

Docker dan FrankenPHP via runtime kontainer Vercel, di `sin1` karena database ada di
Singapura. Lihat [`docs/10-deploying.md`](docs/10-deploying.md) untuk variabel environment
dan urutan menjalankannya. Dua kesalahan didokumentasikan di sana secara detail karena
keduanya tampil sebagai 500 kosong: parameter `options=` di `DB_URL`, dan menempel
`.env.example` ke dasbor.

## Dokumentasi

Ditulis untuk seseorang yang mengenal JavaScript dan sedang belajar Laravel, sehingga
dimulai dari bagian yang menjebak bukan dari pemasaran framework.

| | |
| --- | --- |
| [00. Project topology](docs/topology/00-project-topology.md) | Peta runtime, routes, kode, data, dan deployment saat ini |
| [01. Running the project](docs/01-running-it.md) | Setup, dan perintah yang akan Anda gunakan sehari-hari |
| [02. PHP for JavaScript developers](docs/02-js-to-php.md) | Perbedaan sintaks yang benar-benar menjebak orang |
| [03. The request lifecycle](docs/03-request-lifecycle.md) | Apa yang terjadi antara URL dan respons JSON |
| [04. Adding a field](docs/04-adding-a-field.md) | Mengubah resource ujung ke ujung, setiap file didaftar |
| [05. Endpoint reference](docs/05-endpoints.md) | Setiap route, auth-nya, dan body-nya |
| [06. Auth and tokens](docs/06-auth-and-tokens.md) | Alur token, dan mengapa cakupannya ketat |
| [07. Testing](docs/07-testing.md) | Menjalankan suite dan menulis tes baru |
| [08. Creating an endpoint](docs/08-creating-an-endpoint.md) | Dari nol hingga terkirim, dengan file yang digenerasi |
| [09. Installing libraries](docs/09-adding-a-library.md) | `require` versus `require-dev`, dan penerbitan config |
| [10. Deploying to Vercel](docs/10-deploying.md) | Config kontainer, database, dan kegagalan diam-diam |
| [11. The web UI](docs/11-the-web-ui.md) | Inertia dan Vue, props bersama, mengapa SSR mati, dan dua sistem auth |
| [12. The admin shell](docs/12-the-admin-shell.md) | Sidebar, palet, dasbor, dan grafik |
| [13. SQLite database verification](docs/13-database-verification.md) | Verifikasi integritas, relasi, dan keunikan hanya-baca untuk database lokal |
| [14. Database diagram](docs/14-database-diagram.md) | Primary key, foreign key, kardinalitas relasi, dan perilaku delete |
| [15. JS-to-PHP migration](docs/js-to-php-migration/15-js-to-php-migration.md) | Perilaku reporting-app yang di-port, perbedaan yang disengaja, dan kesenjangan tersisa |

## Status deployment dan keterbatasan yang diketahui

Sudah di-deploy dan berjalan, dengan batasan yang disengaja ini:

- Pendaftaran bersifat publik di kedua permukaan, sehingga siapa pun yang menemukan situs
  dapat membuat akun dan memakai setiap endpoint. Satu-satunya batas adalah `throttle:6,1`
  per IP, dipakai bersama di `/register`, `/api/account`, dan `/api/auth/token`. Itu masuk
  akal untuk demo dan salah untuk apa pun yang menyimpan data asli. Tidak ada reset kata
  sandi, verifikasi email, atau CAPTCHA, sehingga banjir skrip dari satu alamat adalah
  satu-satunya penghalang antara orang asing dan akun yang bisa dipakai.
- Tidak ada batas permintaan pada route spec.
- Tidak ada CSRF pada API, karena diautentikasi dengan token. Grup web memilikinya.
- Production memiliki sepuluh produk bawaan seed dan tanpa user. User uji milik seeder
  sengaja dijauhkan dari production, karena kata sandinya adalah string literal `password`.

## Tentang Laravel

Laravel adalah framework aplikasi web dengan sintaks yang ekspresif dan elegan. Kami percaya
pengembangan harus menjadi pengalaman yang menyenangkan dan kreatif agar benar-benar
memuaskan. Laravel menghilangkan rasa sakit dari pengembangan dengan memudahkan tugas umum
yang dipakai di banyak proyek web, seperti:

- [Mesin route yang sederhana dan cepat](https://laravel.com/docs/routing).
- [Container dependency injection yang kuat](https://laravel.com/docs/container).
- Beberapa back-end untuk penyimpanan [sesi](https://laravel.com/docs/session) dan [cache](https://laravel.com/docs/cache).
- [ORM database](https://laravel.com/docs/eloquent) yang ekspresif dan intuitif.
- [Migrasi skema](https://laravel.com/docs/migrations) yang tidak bergantung pada database.
- [Pemrosesan background job yang kokoh](https://laravel.com/docs/queues).
- [Broadcast event waktu nyata](https://laravel.com/docs/broadcasting).

Laravel mudah diakses, kuat, dan menyediakan alat yang dibutuhkan untuk aplikasi besar dan
tangguh.

## Belajar Laravel

Laravel memiliki [dokumentasi](https://laravel.com/docs) dan pustaka tutorial video yang
paling luas dan menyeluruh dari semua framework aplikasi web modern, sehingga mudah untuk
memulai dengan framework ini.

Selain itu, [Laracasts](https://laracasts.com) berisi ribuan tutorial video tentang berbagai
topik termasuk Laravel, PHP modern, pengujian unit, dan JavaScript.

Anda juga dapat menonton pelajaran singkat dengan proyek dunia nyata di [Laravel Learn](https://laravel.com/learn), di mana Anda akan dipandu membangun aplikasi Laravel dari nol sambil mempelajari dasar PHP.

## Berkontribusi

Terima kasih telah mempertimbangkan kontribusi ke framework Laravel! Panduan kontribusi
dapat ditemukan di [dokumentasi Laravel](https://laravel.com/docs/contributions).

## Kode Etik

Agar komunitas Laravel ramah bagi semua, harap tinjau dan patuhi [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Kerentanan Keamanan

Jika Anda menemukan kerentanan keamanan dalam Laravel, harap kirim email ke Taylor Otwell
via [taylor@laravel.com](mailto:taylor@laravel.com). Semua kerentanan keamanan akan segera
ditangani.

## Lisensi

Framework Laravel adalah perangkat lunak open-source yang dilisensikan di bawah [MIT license](https://opensource.org/licenses/MIT).
