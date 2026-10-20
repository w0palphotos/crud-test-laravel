# 10. Deploy ke Vercel

Vercel tidak memiliki runtime PHP bawaan. Proyek ini berjalan sebagai fungsi serverless menggunakan `vercel-php`, sebuah runtime komunitas. Catatan sumber yang menjadi awal proyek ini ada di `docs/test-md/deploy-vercel.md`; dokumen ini adalah versi yang dikoreksi, dengan celah yang dilengkapi dan bagian yang salah diperbaiki.

## Apa yang keliru dari riset, dan apa yang terlewatkan

| Catatan sumber | Kenyataan |
| --- | --- |
| `vercel-php@0.6.2` | `0.9.0` adalah dist-tag `latest` npm saat ini |
| `require __DIR__ . '/../public/index.php';` | Berfungsi, tetapi gunakan `__DIR__` tanpa spasi di sekitar `.` dan lakukan resolve secara eksplisit daripada mengandalkan direktori kerja |
| `SESSION_DRIVER` ke cookie atau Redis | Gunakan `database`. UI web memiliki formulir Masuk yang nyata, sehingga grup web membutuhkan sesi, cookie, dan CSRF. Tabel `sessions` berasal dari migrasi users |
| Migrasi | Tidak disebutkan. Vercel tidak memiliki shell, sehingga `php artisan migrate --force` harus dijalankan dari mesin Anda terhadap database produksi |
| Sistem berkas hanya-baca | Tidak disebutkan, dan hal ini merusak tiga hal. Lihat di bawah |
| `php artisan optimize` saat build | Jangan. Panduan Vercel sendiri memperingatkan agar tidak melakukannya |
| Spesifikasi OpenAPI | Tidak disebutkan, dan hal ini merusak `/api/documentation`. Lihat di bawah |
| Composer berjalan dengan `--no-scripts` | Tidak disebutkan, dan hal ini menghasilkan 500 kosong pada setiap rute. Lihat di bawah |

Tiga di antaranya adalah kegagalan senyap, bukan kegagalan yang berisik, dan itulah mengapa ketiganya penting.

## Tiga kegagalan senyap

**Cache framework tidak pernah ada, dan Laravel tetap bersikeras menulisnya.** Inilah yang mengembalikan 500 putih kosong tanpa pesan kesalahan, jadi bacalah bagian ini terlebih dahulu. `vercel-php` menjalankan composer dengan flag berikut, yang di-hardcode dalam `runComposerInstall`:

```text
install --profile --no-dev --no-interaction --no-scripts --ignore-platform-reqs --no-progress
```

`--no-scripts` berarti `package:discover` tidak pernah berjalan selama build, sehingga `bootstrap/cache/packages.php` tidak pernah dibuat. Berkas itu juga tidak bisa datang dari git, karena `bootstrap/cache/.gitignore` berisi `*`. Pada permintaan pertama, `PackageManifest` menemukan berkas hilang dan mencoba menulisnya:

```php
// Illuminate/Foundation/PackageManifest.php
if (! is_file($this->manifestPath)) {
    $this->build();          // -> write()
}

// write()
if (! is_writable($dirname = dirname($this->manifestPath))) {
    throw new Exception("The {$dirname} directory must be present and writable.");
}
```

Sistem berkas bersifat hanya-baca, sehingga ia melempar pengecualian. Handler kemudian mencoba me-render pengecualian tersebut sebagai view Blade ke direktori yang juga hanya-baca, sehingga handler mati di tengah respons dan PHP mengeluarkan 500 bawaannya sendiri: `text/html`, `content-length: 0`. Setiap rute gagal dengan cara yang sama, termasuk `/up`.

Anda dapat mengenalinya dalam satu permintaan. Laravel mengembalikan JSON untuk path `api/*`, sehingga permintaan ke `/api/products` yang kembali sebagai `text/html` dengan body kosong berarti handler pengecualian tidak pernah berjalan.

Perbaikannya adalah build hook resmi dari runtime, yaitu skrip bernama `vercel` di `composer.json`. `runComposerScripts` memanggilnya *tanpa* `--no-scripts`, sehingga skrip tersebut tetap dieksekusi:

```json
"scripts": {
  "vercel": ["@php artisan package:discover --ansi"]
}
```

Booting artisan juga menulis `bootstrap/cache/services.php` sebagai efek samping melalui `ProviderRepository`, sehingga satu perintah menghasilkan kedua cache. Runtime memanen `glob('**')` dari direktori build *setelah* ini berjalan, sehingga berkas yang dihasilkan ikut dibundel ke dalam fungsi. Verifikasi keberhasilannya dengan mencari `Discovering packages` di log build.

Perhatikan bahwa `.vercelignore` **tidak** diterapkan ulang selama pengemasan. Ia hanya mengontrol apa yang diunggah, itulah mengapa mengecualikan `/vendor` sudah benar: runtime menginstal ulang dan memanen hasilnya.

Jangan tambahkan `config:cache` ke skrip tersebut. Ia akan menyerikan config saat `DB_*` dan `APP_KEY` masih kosong, sehingga membekukan config database kosong dan kunci null ke dalam deployment. Variabel environment hanya ada per permintaan di serverless.

`route:cache` aman, dan `Dockerfile.vercel` menjalankannya. Ia menyimpan path, middleware, dan nama controller — tidak ada nilai environment di dalamnya, sehingga middleware `throttle:imports` tetap berupa nama dan pembatasnya masih dibaca dari `AppServiceProvider` pada setiap permintaan. Yang hilang dari boot adalah Assetion parsing ulang seluruh `routes/`; pada container, itu dibayar setiap cold start.

Dulu perintah itu gagal. `routes/web.php` mendaftarkan `/` sebagai closure, dan `route:cache` tidak dapat menyerikan route yang aksinya Closure — satu closure足以整个表表 tidak bisa di-cache. Rute tersebut sekarang menunjuk ke `Web\HomeController`, dan tidak ada lagi closure sebagai aksi route di kedua berkas itu.

**Blade tidak dapat mengompilasi pada sistem berkas hanya-baca.** Pada runtime komunitas, sistem berkas fungsi bersifat hanya-baca saat runtime kecuali `/tmp`, sehingga kompiler Blade Laravel, yang menulis ke `storage/framework/views/`, tidak dapat berjalan. Halaman welcome dan halaman Swagger UI gagal sementara endpoint JSON tetap berfungsi, yang terlihat seperti bug routing.

Runtime container memiliki sistem berkas tulis normal, sehingga mode kegagalan ini hilang secara konstruksi, bukan karena konfigurasi. `VIEW_COMPILED_PATH` tetap tidak berbahaya dan tetap layak dipasang, dan ia tetap menjadi variabel yang tepat jika Anda kembali ke runtime dengan sistem berkas hanya-baca, tetapi ia tidak lagi menjadi penopang utama di sini.

**Spesifikasi OpenAPI tidak ada kecuali ia ada di repositori.** Spesifikasi berada di `storage/api-docs/api-docs.json`, dan tidak ada runtime yang me-regenerasinya secara andal saat build. Jika berkas tidak di-commit, `/api/documentation` mengembalikan 404 sementara `/api/products` tetap berfungsi.

Itulah mengapa `storage/api-docs/api-docs.json` di-commit di proyek ini meskipun awalnya di-gitignore. Biayanya adalah drift, jadi setelah mengubah atribut `#[OA\...]` apa pun:

```sh
php artisan l5-swagger:generate
git add storage/api-docs/api-docs.json
```

**Sesi, dan diagnosis yang keliru.** Versi dokumen ini yang lebih awal mengklaim tabel `sessions` hilang dari proyek dan itulah mengapa `/` mengembalikan 500. Itu keliru, dan koreksinya penting karena penyebab sebenarnya memiliki perbaikan yang berbeda.

Laravel 11 dan versi yang lebih baru membuat tabel `sessions` di dalam migrasi users:

```sh
grep -n "Schema::create" database/migrations/0001_01_01_000000_create_users_table.php
# users, password_reset_tokens, sessions
```

Tabelnya selalu ada. Yang sebenarnya merusak `/` adalah *koneksi* database: `.env.example` membawa `DB_CONNECTION=sqlite`, dan container tidak memiliki `database.sqlite` karena `.dockerignore` memang mengecualikannya. Sehingga `StartSession` berjalan, mencoba mencapai SQLite, dan gagal dengan `Database file at path [/app/database/database.sqlite] does not exist`. Memasang `DB_CONNECTION=pgsql` dan `DB_URL` yang benar memperbaikinya hingga ke akar, dan API mengembalikan 401 untuk tamu yang tidak terautentikasi pada saat yang sama karena `auth:sanctum` menolak sebelum query apa pun.

Saat itu gejalanya juga tertutup karena middleware sesi dan cookie dihapus dari grup `web`, yang membuat `/` berfungsi sementara menyembunyikan penyebabnya. Penghapusan itu telah dikembalikan, karena UI web kini memiliki formulir Masuk yang nyata dan membutuhkan sesi, CSRF, dan cookie. Konfigurasi yang benar adalah variabel `DB_*`, bukan menghilangkan middleware.

`SESSION_DRIVER` harus `database` di host. `array` tidak masalah untuk test suite, yang dipaksa oleh `phpunit.xml`, dan tidak masalah saat aplikasi masih API saja, tetapi ia menghilangkan sesi pada setiap cold start, sehingga login yang di-deploy akan tampak berhasil lalu melupakan Anda. Tabel yang dibutuhkannya sudah ada.

`tests/Feature/Web/WebAuthTest.php` mencakup login sesi, termasuk bahwa id sesi di-regenerasi saat Masuk dan bahwa API tetap menjawab 401 JSON untuk tamu yang tidak terautentikasi daripada mengarahkan ke formulir Masuk HTML.

## Database

Vercel tidak menyediakan hosting database. Gunakan Neon, yang gratis tanpa kedaluwarsa, tanpa kartu, 0.5GB, dan komputasi yang mengecil ke nol setelah 5 menit.

Buat satu proyek dan satu branch, lalu ambil connection string **pooler**. Anda memasangnya sendiri di Vercel, karena platform tidak menyuntikkan apa pun:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=<neon pooler host>
DB_PORT=5432
DB_DATABASE=<database>
DB_USERNAME=<user>
DB_PASSWORD=<password>
```

`DB_CONNECTION` adalah yang sering menjebak orang. `config/database.php:20` membaca `env('DB_CONNECTION', 'sqlite')`, jadi jika dibiarkan kosong, aplikasi diam-diam menargetkan SQLite, yang tidak bisa berfungsi di sini karena sistem berkas hanya-baca. Kegagalan itu berisik, yang merupakan keberuntungan kecil.

### Supabase

Tidak ada di aplikasi ini yang khusus Neon; `pgsql` hanyalah `pgsql`. Supabase berfungsi, tetapi bukan sekadar tukar string langsung, dan ia membutuhkan **dua connection string berbeda**: satu untuk aplikasi yang di-deploy dan satu lagi yang berbeda untuk migrasi.

| Tujuan | Mode koneksi | Port | Alasan |
| --- | --- | --- | --- |
| Aplikasi yang di-deploy | Pooler bersama, transaksi | 6543 | IPv4, dan fungsi serverless membuka banyak koneksi berumur pendek |
| Migrasi | Langsung | 5432 | Migrasi adalah satu sesi berumur panjang dan membutuhkan fitur Postgres asli |

Ambil masing-masing dari dialog **Connect** di dasbor, dengan memilih mode yang sesuai dan sakelar format **URI**, bukan JDBC atau node.js.

**Koneksi aplikasi.** Koneksi langsung Supabase hanya-IPv6, yang merupakan penyebab paling umum kegagalan koneksi total dari fungsi Vercel. Pooler transaksi bersama adalah IPv4 di semua paket. Ia memiliki username berbeda dari koneksi langsung, `postgres.PROJECTREF` bukan `postgres`, dan host yang tidak dapat diturunkan dari region Anda, karena `[INDEX]` dalam `aws-0-eu-central-1.pooler.supabase.com` adalah indeks klaster pooler, bukan bagian dari nama region. Salin keduanya dari dialog.

Satu parameter query wajib:

- `sslmode=require`. Bawaan yang dikirim adalah `prefer` (`config/database.php`), yang akan jatuh kembali ke plaintext, dan Supabase mewajibkan TLS.

**Jangan tambahkan parameter `options=` ke URL.** Dokumentasi Supabase merekomendasikan `options=--statement_cache_size=0` untuk pooler mode-transaksinya, dan mengikuti saran itu merusak Laravel. Parameter tersebut adalah direktif connection-string libpq, tetapi `ConfigurationUrlParser` menyalin parameter query ke config koneksi secara verbatim, sehingga tiba di `Connector::getOptions()` sebagai string padahal yang diharapkan adalah array atribut PDO:

```php
// Illuminate/Database/Connectors/Connector.php
$options = $config['options'] ?? [];
return array_diff_key($this->options, $options) + $options;   // TypeError: string given
```

Setiap query database kemudian gagal dengan `array_diff_key(): Argument #2 must be of type array, string given`, yang muncul sebagai 500 pada rute apa pun yang menyentuh database. Tidak ada kesalahan koneksi dan tidak ada baris log yang berguna, karena kegagalan terjadi saat membangun koneksi, bukan saat melakukan query.

Prepared statement dinonaktifkan dengan cara Laravel, di `config/database.php`:

```php
'options' => [
    PDO::ATTR_EMULATE_PREPARES => false,
],
```

Itu adalah atribut PDO asli, sehingga tipenya benar, dan emulasi prepare membuat PDO menginterpolasi parameter di sisi klien, yang diterima oleh pooler mode-transaksi. `tests/Unit/PostgresConnectionConfigTest.php` mengunci kedua sisi ini, sehingga string `options=` yang dimasukkan kembali akan gagal di CI, bukan di produksi.

Laravel menerima seluruh string dalam satu variabel, dan parameter query menang atas default diskret karena `ConfigurationUrlParser` menggabungkannya terakhir:

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.PROJECTREF:PASSWORD@POOLER-HOST:6543/postgres?sslmode=require
```

`host`, `port`, `database`, `username`, dan `password` dari URL semuanya menimpa default `DB_*`, sehingga lima variabel diskret tidak diperlukan. Lakukan percent-encode untuk karakter khusus apa pun di kata sandi; sakelar URI di dasbor melakukannya untuk Anda.

**Koneksi migrasi.** Gunakan string langsung, dan jangan bawa parameter pooler:

```sh
export DB_URL='postgresql://postgres:PASSWORD@db.PROJECTREF.supabase.co:5432/postgres?sslmode=require'
php artisan migrate --force
```

Ini adalah langkah yang mudah keliru, karena ini adalah string yang *berbeda* dari yang ada di Vercel. Jika mesin Anda berada di jaringan khusus-IPv4, koneksi langsung tidak akan ter-resolve, dan pooler sesi (port 5432, `postgres.PROJECTREF`) berfungsi di sana sebagai gantinya; migrasi tidak membutuhkan mode transaksi.

Satu catatan perilaku: pada tingkat gratis, Supabase menjeda proyek yang menganggur, sehingga permintaan pertama setelah masa tidak aktif dapat menggantung atau 5xx saat ia bangun.

## Variabel environment

Daftarnya ada di `env.vercel.example` di root repository, dengan komentar untuk
setiap variabel yang mudah salah. Salin dari sana, jangan dari `.env.example` —
yang itu templat SQLite lokal, dan menyalinnya ke dasbor mengirim
`DB_CONNECTION=sqlite` tanpa satu pun kredensial.

Empat hal yang tidak ada di daftar mana pun dan harus diketahui:

**`APP_URL` harus sama dengan origin yang benar-benar dibuka.** `route()`
membangun URL dari nilai itu, jadi setiap tautan yang server kirim ke browser
ikut memakainya — termasuk endpoint unggah gambar dan tombol Simpan. Kalau
berbeda, form mem-post ke origin yang salah, cookie sesi tidak ikut terkirim,
dan pengunggahan berhenti dengan 419 tanpa penjelasan.

**`AWS_URL` dan `AWS_ENDPOINT` tidak boleh tertukar, dan keduanya berbeda.** Yang
pertama untuk dibaca browser, yang kedua untuk mengunggah:

```
AWS_ENDPOINT  https://PROJECTREF.storage.supabase.co/storage/v1/s3
AWS_URL       https://PROJECTREF.supabase.co/storage/v1/object/public/BUCKET
```

Tanpa `AWS_URL`, disk mengembalikan endpoint API, yang butuh autentikasi —
unggahan "berhasil" lalu URL-nya tersimpan ke catatan dan tidak bisa dibuka
siapa pun. Cara memastikan: unggah satu gambar, lalu lihat URL di jawabannya.
Berakhiran `/object/public/` berarti benar; berakhiran `/v1/s3/` berarti
`AWS_URL` belum terpakai.

**Kredensial disimpan sebagai Secret, bukan Config.** Vercel menandainya dengan
badge "Needs Attention", dan itu bukanorship: nilainya bisa dibaca dari dasbor
lalu. Secret tidak bisa dibaca kembali, jadi kalau kuncinya bocor, hanya
membuatnya yang menolong. Setelah mengubahnya menjadi Secret, **rotasi** kunci
di Supabase — nilainya masih bisa dibaca selama belum dirotasi.

**Bucket harus publik** kalau gambarnya harus tampil tanpa login. Bucket privat
membalas 400 lewat `/object/public/` meski URL-nya sudah benar.

### Mengubah variabel environment butuh deployment baru

Vercel:nilai environment adalah bagian dari artifact, dan artifact itu immutable.
Mengubah nilai di dasbor **tidak mengubah deployment yang sedang berjalan** —
container yang sudah jalan mempertahankan nilai yang dibawanya waktu start.

Jadi setelah mengubah atau menambah variabel, buat **deployment baru**. Tombol
`Redeploy` di daftar deployment bekerja, tapi centang **"Use existing Build
Cache"** harus dalam keadaan tidak aktif: build yang di-cache tidak menjalankan
build.sh lagi, dan build.sh itulah yang menyuntik environment ke container.

Untuk memastikannya tanpa membaca nilai apa pun, `GET /admin/uploads/status`
menampilkan apa yang **aplikasi** resolve: variabel mana yang sampai, mana yang
tidak. Kalau di dasbor sebuah variabel ada sementara di sana tidak, itu
selisihnya — dan itu yang tidak bisa dilihat dari mana pun selain aplikasi
yang sedang berjalan.

**Jangan menempel `.env.example` ke dasbor.** Ia adalah templat SQLite lokal, sehingga menyediakan `DB_CONNECTION=sqlite` dan tidak satu pun kredensial yang dibutuhkan database ter-hosting. Hasilnya adalah fallback senyap ke berkas database lokal yang tidak ada di host, dan gejala pertamanya membingungkan: `GET /docs` mengembalikan 500 sementara `GET /api/products` mengembalikan 401, karena rute spesifikasi menjalankan pembatas batas permintaan yang membaca tabel cache sebelum controller tercapai.

Gunakan `env.vercel.example` di root repository sebagai gantinya. Namanya tidak diawali `.env` justru supaya bisa ter-commit: pola `.env.*` ada di `.gitignore`, jadi `.env.production` atau `.env.vercel` tidak akan pernah ikut naik dan hilang begitu Anda pindah mesin. Isinya templat lengkap dengan komentar untuk setiap variabel yang mudah salah, dan setiap nilai yang harus diisi ditandai `<...>` sehingga lupa menggantinya gagal keras, bukan diam-diam.

Dua di antaranya tidak opsional dan keduanya tidak muncul dalam catatan sumber.

**Jika rute yang dibatasi mengembalikan 500 sementara rute yang tidak dibatasi mengembalikan 401, maka database tidak dapat dijangkau.** Asimetri itu adalah sinyal yang berguna, dan layak dipelajari cara membacanya. `GET /api/products` tidak memiliki pembatas batas permintaan, sehingga `auth:sanctum` menolak permintaan tidak terautentikasi sebelum query apa pun terjadi dan ia mengembalikan 401 bahkan tanpa database sama sekali. `GET /docs` dan `POST /api/auth/token` keduanya membawa `throttle:`, dan pembatas membaca tabel cache sebagai tindakan pertamanya, sehingga keduanya gagal lebih dulu. `401` yang sehat berdampingan dengan `500` yang rusak berarti aplikasi baik-baik saja dan koneksinyalah yang bermasalah.

Untuk melihat kesalahan sebenarnya, lakukan POST ke rute token. Ia memiliki pembatas, sehingga gagal secara berisik dan mengembalikan body JSON berisi pengecualian, sedangkan rute docs dapat mengembalikan 500 kosong:

```sh
curl -s -X POST $BASE/api/auth/token \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"nobody@example.com","password":"wrong"}'
```

`LOG_CHANNEL=stderr` karena `stack`/`single` menulis ke `storage/logs/laravel.log`, yang hanya-baca. Tanpanya, apa pun yang melakukan log akan gagal. `Dockerfile.vercel` sudah mengaturnya sebagai ENV, jadi tidak perlu di dasbor.

`VIEW_COMPILED_PATH=/tmp/views` hanya relevan untuk runtime komunitas, yang sistem berkasnya hanya-baca. Runtime container punya sistem berkas tulis normal, jadi tidak perlu — dan `Dockerfile.vercel` sudah mengompilasi view saat build. Ia tetap layak dipasang bila Anda kembali ke runtime dengan sistem berkas hanya-baca.

Perhatikan bahwa `key:generate` sendiri hanya menulis ke `.env` lokal Anda. Gunakan `--show` untuk mencetak nilai yang perlu ditempel.

`CACHE_STORE` dan `QUEUE_CONNECTION` dapat tetap `database`. Tidak ada yang mengirim queued jobs, sehingga tidak diperlukan worker. Tidak perlu Redis.

`SESSION_DRIVER=database` di host. UI web membuat pengguna Masuk dengan sesi, dan `array` melupakan sesi pada setiap cold start, sehingga login yang di-deploy akan tampak berhasil lalu kehilangan Anda. Tabel `sessions` yang dibutuhkannya dibuat oleh migrasi users, sehingga tidak diperlukan migrasi tambahan. Lihat "Sesi, dan diagnosis yang keliru" di atas.

`APP_URL` di host. Ia harus sama persis dengan origin yang dibuka di browser — `route()` membangun URL dari nilai ini, jadi setiap form mem-post ke sana. Jika berbeda, cookie sesi tidak ikut terkirim dan unggahan berhenti dengan 419 tanpa penjelasan. Gejalanya jelas dari luar: `curl -sI $BASE/sales | grep -i location` printing `localhost` berarti nilainya belum diganti.

### Gambar, dan dua variabel yang tidak boleh tertukar

`IMAGES_DISK=s3` di host, dengan Storage Supabase. Disk lokal di dalam container hilang saat instance di-recycle: gambar yang diunggah hari ini tidak ada besok, dan catatan yang menunjuk ke sana menampilkan kotak rusak permanen.

Yang paling mudah tertukar adalah `AWS_ENDPOINT` dan `AWS_URL`, dan keduanya tidak sama. Tanpa `AWS_URL`, `AwsS3V3Adapter::url()` jatuh ke `getObjectUrl()` dan mengembalikan **endpoint API**:

```
AWS_ENDPOINT  https://PROYEK.supabase.co/storage/v1/s3                        ← untuk unggah
AWS_URL       https://PROYEK.supabase.co/storage/v1/object/public/BUCKET       ← untuk dibaca browser
```

Yang salah berarti unggahan "berhasil" lalu URL-nya tersimpan ke dalam catatan dan tidak dapat dibuka siapa pun, karena endpoint API meminta autentikasi. Cara memastikan: unggah satu gambar, lalu lihat URL yang dikembalikan `POST /admin/uploads`. Berakhiran `/object/public/` berarti benar.

URL gambar pada disk `public` lokal sengaja dibuat `/storage` tanpa host, bukan dibangun dari `APP_URL`. Browser menyelesaikannya terhadap host yang sedang melayaninya, jadi satu nilai `APP_URL` yang salah tidak lagi bisa menarik seluruh gambar ke host yang keliru. `APP_URL` tetap harus benar untuk endpoint form — yang ini menghapus satu jalan salah, bukan kewajiban mengisinya.

## Migrasi

Vercel tidak memberi Anda shell, jadi jalankan dari mesin Anda terhadap database produksi:

```sh
php artisan migrate --force
```

Lakukan ini sekali, setelah deploy pertama yang berhasil. Panduan Vercel sendiri menegaskan bahwa migrasi harus menjadi langkah rilis terpisah dan tidak pernah berjalan saat startup container, karena instans yang bersamaan akan berlomba.

Gunakan koneksi **langsung** untuk migrasi, bukan pooler transaksi yang digunakan aplikasi. Migrasi adalah satu sesi berumur panjang dan membutuhkan state tingkat-sesi, yang dibuang oleh mode transaksi di antara transaksi. Lihat bagian Supabase di atas untuk kedua string.

`migrate --seed` aman *di produksi*, dan berguna untuk demo. Seeder membuat 10 produk di setiap environment dan membuat pengguna `test@example.com` hanya di luar produksi, karena kata sandi akun tersebut adalah string literal `password` dan `POST /api/account` bersifat publik, sehingga melakukan seeding akan menjadi backdoor kredensial yang diketahui. Tiga pengujian mengunci pemisahan itu, termasuk `test_it_still_seeds_products_in_production`.

**Lakukan seed dengan environment diatur ke produksi, bukan dari laptop Anda.** Inilah yang menggigit. Penjaganya adalah `if (app()->environment('production')) return;` di `DatabaseSeeder`, sehingga menjalankan `db:seed` dari mesin dengan `APP_ENV=local` terhadap database produksi melewati penjaga sepenuhnya dan membuat akun backdoor. Itu sudah pernah terjadi sekali pada deployment ini; akun tersebut dapat dijangkau oleh siapa pun yang menebak dua nilai yang jelas, dan `POST /api/auth/token` akan memberi mereka token bearer dengan CRUD penuh.

```sh
# correct: the guard applies
APP_ENV=production php artisan db:seed --force

# wrong: creates test@example.com with the password "password"
php artisan db:seed --force
```

Perhatikan juga bahwa `Product::factory(10)->create()` berjalan *sebelum* penjaga, sehingga run kedua yang gagal meninggalkan sekumpulan produk duplikat meskipun insert pengguna-lah yang memicu pelanggaran unik.

Jika itu terjadi, akun tersebut dapat dihapus tanpa menyentuh skema:

```sh
php artisan tinker --execute 'App\Models\User::where("email","test@example.com")->delete();'
```

Dan jauhkan kata sandi produksi dari `.env` lokal Anda. `phpunit.xml` memaksa `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` dan `DB_URL` kosong, sehingga test suite tidak dapat mencapai produksi, tetapi `php artisan` di luar pengujian akan dengan senang hati menulis ke apa pun yang ditunjuk `.env`. Ekspor connection string untuk perintah migrasi sebagai gantinya:

```sh
export DB_URL='postgresql://postgres:PASSWORD@db.PROJECTREF.supabase.co:5432/postgres?sslmode=require'
php artisan migrate --force
unset DB_URL
```

## Melakukan Deploy

Push kode terlebih dahulu, lalu impor repositori di `https://vercel.com` melalui **Add New**, lalu **Project**.

| Pengaturan | Nilai |
| --- | --- |
| Framework Preset | Other |
| Install Command | kosongkan |
| Build Command | kosongkan |
| Output Directory | kosongkan |

Ketiganya harus kosong, dan itu bukan sekadar kemudahan. `vercel.json` mendeklarasikan entri `services` dengan `"runtime": "container"` dan `"entrypoint": "Dockerfile.vercel"`, sehingga Vercel membangun image dan sepenuhnya mengabaikan perintah install dan build. Memasang perintah build akan menimpa langkah build milik Dockerfile sendiri, dan begitulah aset Vite akhirnya hilang.

Npm dan Composer keduanya berjalan *di dalam* image: tahap Node membangun aset dan tahap Composer menginstal dependensi. Urutan tahap penting. `install` Composer berjalan dari berkas lock dengan `--no-scripts`, dan autoloader di-dump hanya setelah aplikasi disalin, karena autoloader otoritatif yang dibuat sebelum pohon vendor ada hanya berisi kelas milik aplikasi ini saja dan image kemudian gagal boot.

Lalu Deploy. Dua hal layak dibaca di log build daripada diasumsikan:

- `Discovering packages` mengonfirmasi hook `vercel` berjalan. Jika tidak ada, cache framework tidak dibuat dan setiap rute akan 500.
- `Installing dependencies from lock file` mengonfirmasi composer melakukan resolve dari `composer.lock`. Karena runtime meneruskan `--ignore-platform-reqs`, ketidakcocokan versi PHP atau ekstensi *tidak* akan menggagalkan build; ia muncul sebagai kesalahan runtime.

## Memverifikasi bahwa ia benar-benar berfungsi

Sebelum push, jalankan gate deploy lokal (`composer test:deploy`) — ia memeriksa spec yang
di-commit, manifest Vite, lock npm, contoh env, dan `migrate:fresh --seed` di Postgres.
Detailnya di [07. Pengujian](07-testing.md). Yang di bawah ini berjalan sesudah deploy,
terhadap URL produksi.

Bekerjalah dalam urutan ini, karena setiap langkah mengisolasi kegagalan yang berbeda. Berhentilah pada kegagalan pertama daripada men-debug yang setelahnya.

```sh
BASE=https://your-app.vercel.app

# 1. JSON works at all
curl -s -o /dev/null -w "products: %{http_code}\n" -H 'Accept: application/json' $BASE/api/products
#    expect 401, not 500. 401 proves routing, boot, and error handling are fine

# 2. the database is not SQLite
curl -s -H 'Accept: application/json' $BASE/api/account
#    expect 401. A 500 here means DB_CONNECTION is wrong

# 3. Blade renders, which is the read-only-filesystem test
curl -s -o /dev/null -w "docs: %{http_code}\n" $BASE/api/documentation
#    expect 200. Anything else means the views were not compiled

# 4. the web UI, which needs a session rather than a token
curl -s -o /dev/null -w "login page:  %{http_code}\n" $BASE/login
#    expect 200. A 500 here means SESSION_DRIVER is wrong or the database is down.

# 5. the full API flow, including the Authorization header
curl -s -X POST $BASE/api/account -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery9","password_confirmation":"correct-horse-battery9"}'
#    expect 201 and a token

curl -s -H "Authorization: Bearer <token>" -H 'Accept: application/json' $BASE/api/products
#    expect 200

# 6. APP_URL names this host, not somewhere else
curl -sI $BASE/sales | grep -i location
#    expect the Location to start with $BASE. A "localhost" here is the one
#    setting that quietly breaks every form on the site.

# 7. images live where the browser can reach them
#    upload one through the browser, then look at the URL the response carried.
#    it must end /object/public/ — a URL ending /v1/s3/ is the API endpoint and
#    will 403 for every reader. See "Gambar, dan dua variabel yang tidak boleh tertukar".

# 8. the storage configuration reached the running container
#    needs a session, so open it in a browser while signed in
#    $BASE/admin/uploads/status
#    every variable under "variables" must be true. A false one is a value that
#    is in the dashboard and not in the application — which is invisible from
#    every other place.
```

Langkah 4 adalah yang menangkap header `Authorization` yang terlucuti. Jika langkah 1 memberi 401 tetapi langkah 4 juga memberi 401 dengan token yang valid, berarti header tidak mencapai PHP. Berkas `public/.htaccess` memiliki aturan rewrite yang menyuntikkannya kembali untuk Apache, dan itu tidak relevan di sini, karena Vercel tidak membaca `.htaccess`. Jika Anda mengalaminya, perbaikannya ada di penanganan header runtime, bukan di Laravel.

Langkah 6 dan 7 menangkap dua kesalahan yang gejalanya mirip tapi sebabnya tidak ada hubungannya. Keduanya muncul sebagai kotak gambar rusak di halaman, dan keduanya sudah terlihat di produksi: `APP_URL` masih `localhost:8000` membuat setiap tautan form menunjuk ke sana, sementara `AWS_URL` yang kosong membuat URL gambar yang tidak bisa dibuka siapa pun. Langkah 6 membedakannya dari satu sisi, langkah 7 dari sisi lain.

## Alternatif resmi

Vercel menerbitkan panduan Laravel-nya sendiri pada 2026-09-03, tiga minggu sebelum ini ditulis, dan panduan itu tidak menggunakan runtime komunitas. Ia menggunakan Docker dengan FrankenPHP:

| | Runtime komunitas `vercel-php` | Docker + FrankenPHP, panduan Vercel |
| --- | --- | --- |
| Versi PHP | Dibaca dari `require.php`, tidak diverifikasi | Disematkan di Dockerfile |
| Ekstensi PHP | Di luar kendali Anda | Anda menginstalnya, misalnya `pdo_pgsql` |
| View terkompilasi | variabel env `VIEW_COMPILED_PATH` | `view:cache` selama build |
| Build hook untuk spesifikasi | Tidak ada, sehingga spesifikasi di-commit | Penuh, sehingga spesifikasi boleh tetap diabaikan |
| Dukungan resmi | Tidak ada | Ya |

Rute Docker memerlukan lebih banyak berkas dan lebih banyak untuk dipahami, dan itulah yang harus dituju jika runtime komunitas melawan Anda. Vercel memiliki plugin untuk itu:

```sh
npx plugins add vercel/vercel-plugin
```

Jika Anda menempuh cara itu, tiga berkasnya adalah `Dockerfile.vercel`, `Caddyfile`, dan `vercel.json` yang menggunakan `"services"` dengan `"runtime": "container"`. Baca https://vercel.com/kb/guide/laravel-php-with-vercel terlebih dahulu, dan perhatikan bahwa Dockerfile-nya menginstal `pdo_pgsql` atau `pdo_mysql` secara eksplisit, yang dilakukan runtime komunitas untuk Anda.

## Hal yang layak dilakukan sebelum ini menghadap internet

**Di mana batas permintaan berada, dan mengapa `/docs` bukan salah satunya.** Hanya `POST /api/account` dan `POST /api/auth/token` yang membawa batas, `throttle:6,1` per IP klien. Itu per IP karena `bootstrap/app.php` memanggil `trustProxies(at: '*')`; tanpanya Laravel mengabaikan `X-Forwarded-For` di belakang load balancer Vercel, setiap pengunjung ter-resolve ke alamat proxy yang sama, dan semua lalu lintas runtuh ke satu bucket bersama. `X-Forwarded-For` sudah ada dalam set header tepercaya default Laravel, sehingga tidak diperlukan daftar header.

`GET /docs` sengaja tidak dibatasi. Ia adalah `throttle:60,1` hingga diukur, dan pembatas batas permintaan adalah alasan halaman dokumentasi membutuhkan enam detik untuk terbuka:

| Rute | TTFB pada fungsi yang di-deploy | Menyentuh database |
| --- | --- | --- |
| `/up` | 0.35s | tidak |
| `/api/documentation` | 0.32s | tidak |
| `/docs/asset/*` | 0.11s | tidak |
| `/docs` (spesifikasi) | **6.4s**, enam permintaan warm berurutan | ya |

Pembatas batas permintaan didukung oleh `CACHE_STORE`, yaitu database, sehingga setiap permintaan membayar dua perjalanan pulang-pergi melalui pooler transaksi sebelum controller membaca berkas 27KB-nya. Itu juga berarti database yang tidak terjangkau membuat dokumentasi publik offline, dan begitulah sebagian besar deployment ini didiagnosis. Spesifikasi adalah berkas yang di-commit dan dibuat dari sumber di repositori publik, sehingga batas permintaan tersebut menjaga sesuatu yang sudah dipublikasikan.

`tests/Feature/Api/DocumentationApiTest.php` menegaskan middleware yang terdaftar, bukan sekadar bahwa rute menjawab, karena `phpunit.xml` menyematkan `CACHE_STORE=array`, yang akan meloloskan pembatas batas permintaan berbasis-cache di suite sementara membebankan perjalanan pulang-pergi nyata di produksi.

Jika Anda menginginkan batas atas di sini nanti, jauhkan dari pembatas batas permintaan berbasis-cache. Menaikkan angka tidak menghilangkan perjalanan pulang-pergi per permintaan.

**Pahami postur yang Anda pilih.** Pendaftaran publik plus halaman docs publik berarti siapa pun dapat membaca spesifikasi penuh, Daftar, dan menggunakan setiap endpoint. Itu koheren untuk API demo dan keliru untuk apa pun yang menyimpan data nyata.

**Pertahankan penjaga pengeluaran.** Paket gratis Supabase tidak pernah menagih melampaui batasnya, tetapi jika Anda kemudian memasang sumber daya berbayar, pasang peringatan.
