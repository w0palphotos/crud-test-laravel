# 01. Menjalankan proyek

## Persiapan awal

```sh
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan storage:link
php artisan migrate --seed
php artisan l5-swagger:generate
php artisan serve
```

Apa yang dilakukan setiap baris:

| Perintah | Alasan |
| --- | --- |
| `composer install` | Menginstal paket yang terdaftar di `composer.lock` |
| `cp .env.example .env` | Membuat file config lokal Anda. `.env` di-gitignore, milik Anda tetap privat |
| `php artisan key:generate` | Menggenerasi `APP_KEY` yang dipakai untuk mengenkripsi cookie dan token. Tanpa itu aplikasi melempar error |
| `touch database/database.sqlite` | Membuat file SQLite kosong. `config/database.php` default-nya `DB_CONNECTION=sqlite` |
| `php artisan storage:link` | Menyambungkan `public/storage` ke `storage/app/public`. Tanpa symlink ini, gambar yang diunggah tersimpan dengan benar tetapi `/storage/...` membalas 404, sehingga catatan penjualan tampil dengan gambar rusak |
| `php artisan migrate` | Menjalankan migration, yang membuat tabel |
| `php artisan migrate --seed` | Juga menjalankan `database/seeders/DatabaseSeeder.php`, yang menyisipkan 10 produk dan satu pengguna |
| `php artisan l5-swagger:generate` | Memindai `app/` untuk atribut `#[OA\...]` dan menulis spec |

`public/storage` di-gitignore, jadi symlink itu harus dibuat ulang di setiap checkout. Di
produksi tidak perlu: `IMAGES_DISK=s3` membuat gambar dilayani oleh Supabase Storage, bukan
oleh filesystem container.

## Kredensial yang di-seed

`DatabaseSeeder` membuat satu pengguna. Email di-hardcode; kata sandi berasal dari
`UserFactory`, di mana ia diset ke `password`:

```php
// database/seeders/DatabaseSeeder.php
Product::factory(10)->create();

User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@example.com',
]);
```

```php
// database/factories/UserFactory.php
'password' => static::$password ??= Hash::make('password'),
```

Jadi login-nya adalah `test@example.com` / `password`.

## Membuat administrator

Meja penjualan khusus administrator, dan user uji milik seeder sengaja **bukan** salah
satunya — akun dengan kredensial yang diketahui di database akan menjadi jalan masuk yang
diketahui ke pembukuan. Pendaftaran juga tidak dapat membuatnya: `is_admin` tidak
mass-assignable.

Buat administrator sendiri:

```sh
php artisan admin:ensure
```

Ia meminta email, nama tampilan dan kata sandi (dua kali, tersembunyi), membuat akun, atau
mempromosikan yang sudah ada setelah bertanya. Aman dijalankan dua kali — jalankan kedua
melaporkan akun sudah menjadi administrator dan tidak mengubah apa pun.

Jalankan terhadap database asli untuk deployment yang harus bertahan setelah boot baru. Di
Vercel filesystem bersifat ephemeral, sehingga administrator di database yang di-seed tetapi
tidak dipersistensi bukan administrator pada cold start berikutnya.

## Buka Swagger UI

```text
http://localhost:8000/api/documentation
```

## Dua langkah di Swagger UI

1. Buka `POST /api/auth/token`, klik **Try it out**, klik **Execute**, dan salin nilai
   `token` dari respons.
2. Klik **Authorize** di kanan atas, tempel token, klik **Authorize**.

Setiap endpoint lain membutuhkan langkah 2 dulu.

Token Sanctum terlihat seperti ini:

```text
3|Ip7Xq7KgAf3nRt8s...
```

Tempel semuanya, termasuk awalan `3|`. Itu satu string opaque, dan bagian sebelum pipe
adalah bagiannya. Menghilangkan awalan memberi Anda 401 yang terlihat seperti kredensial
buruk.

## Perintah yang akan sering Anda gunakan

```sh
php artisan serve                          # start the dev server on :8000
php artisan test                           # run the whole test suite
php artisan test --filter=test_it_lists    # run tests matching a name
php artisan test tests/Feature/Api         # run one directory
php artisan route:list --path=api          # see every API route
php artisan route:list --path=api --except-vendor
php artisan l5-swagger:generate            # regenerate the OpenAPI spec
php artisan migrate:fresh --seed          # wipe the DB and reseed
php artisan tinker                         # interactive PHP shell with the app loaded
vendor/bin/pint --dirty --format agent     # format the files you changed
vendor/bin/pint --test                     # check formatting without changing anything
```

## Jebakan yang akan Anda temui

### `php artisan dev` belum berfungsi

Runner dev gabungan Laravel 13 memulai empat proses: `serve`, `queue:listen`, `pail`
untuk log, dan `vite`. Ia menjalankannya melalui `@laravel/multiplex`, yaitu paket Node
dan berada di `node_modules`. Direktori itu tidak ada di proyek ini, sehingga perintah
gagal. Gunakan `php artisan serve` kecuali Anda sudah menjalankan `npm install`.

### Web UI membutuhkan build

Halaman selamat datang bawaan scaffold dulu menjaga panggilan Vite-nya dan fallback ke
stylesheet inline, sehingga `GET /` berfungsi tanpa `npm install`. UI asli tidak, karena
`resources/views/app.blade.php` memanggil `@vite` dan halaman Vue di-bundle dari
`resources/js/app.js`. Tanpa build halaman tersebut gagal karena manifest hilang.

Dua pengecualiannya adalah `/login` dan `/register`. Keduanya formulir yang di-render di
server yang sengaja hanya memuat stylesheet, sehingga berfungsi tanpa build dan tanpa
JavaScript sama sekali.

Untuk web UI, jalankan `npm install && npm run build`. `npm run dev` hanya untuk mengedit
frontend dengan hot reload, dan API berfungsi tanpa semua ini selama Anda tidak mengunjungi
halaman web.

### Spec terlihat basi di browser

`storage/api-docs/api-docs.json` di-gitignore dengan sengaja, karena salinan yang
di-commit akan drift dari atribut tempat ia digenerasi. Jika Anda mengedit atribut
`#[OA\...]` dan tidak ada yang berubah di browser, Anda melewatkan ini:

```sh
php artisan l5-swagger:generate
```

### 429 dari endpoint token

`POST /api/auth/token` dan `POST /api/account` dibatasi hingga 6 permintaan per menit.
Tunggu 60 detik setelah mengenainya.

### `composer install` gagal dengan error parse lock file

Jika Anda pernah melihat `"./composer.lock" does not contain valid JSON`, periksa baris
pertama file untuk karakter liar sebelum `content-hash`. Itu pernah terjadi sekali di repo
ini dan perbaikannya satu karakter. `composer validate --check-lock` menangkapnya.

## Di mana semuanya berada

Peta repositori saat ini ada di [00. Topologi proyek](topology/00-project-topology.md). Ia
disimpan di sana bukan diulang di sini, sehingga instruksi setup dan dokumentasi struktural
tidak bisa saling drift.
