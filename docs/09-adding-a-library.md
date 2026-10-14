# 09. Menginstal dan menggunakan library

Dua package manager, satu aturan: **utamakan apa yang sudah terinstal, dan standard
library, daripada dependency baru.** Dokumen ini sebagian besar tentang aturan itu, beserta perintah
ketika paket baru memang jawaban yang tepat.

## Paralel JavaScript

Kamu sudah mengenal bentuk ini, hanya namanya berbeda:

| JavaScript | PHP | Composer |
| --- | --- | --- |
| `npm install lodash` | menambah paket | `composer require vendor/package` |
| `dependencies` | paket runtime | `require` |
| `devDependencies` | tooling build dan test | `require-dev` |
| `node_modules/` | paket terinstal | `vendor/` |
| `package-lock.json` | versi resolved yang tepat | `composer.lock` |
| `npm ci` | menginstal tepat apa yang dikunci | `composer install` |
| `node_modules` di-gitignore | sama | `vendor` di-gitignore |

Jadi kesetaraan yang kamu inginkan adalah:

```sh
npm install some-package   ->  composer require some-package
npm install -D some-tool   ->  composer require --dev some-tool
npm ci                     ->  composer install
```

**`composer install` dan `npm ci` bukanlah hal yang sama.** `composer install` menginstal
tepat apa yang disematkan `composer.lock` dan tidak pernah menulis ulang lock. Jika `composer.json` telah
berubah sejak lock ditulis, ia memperingatkan bahwa lock sudah basi dan tetap menginstal versi
lama. `composer update` adalah perintah yang melakukan resolve ulang dan menulis ulang
`composer.lock`. Memahami perbedaannya menghemat banyak kebingungan.

## Apa yang sudah terinstal di proyek ini

Dibaca dari `composer.lock`, bukan tebakan:

| | Jumlah |
| --- | --- |
| Langsung, `require` | 4 |
| Langsung, `require-dev` | 9 |
| Terkunci, runtime | 77 |
| Terkunci, dev | 44 |
| **Total terkunci** | **121** |

Empat paket runtime langsung, seratus dua puluh satu sebenarnya. Rasio itu normal. Menambah
satu paket sering menarik selusin, karena paket PHP bergantung pada komponen `symfony/*`.

13 paket langsung:

| Paket | Bagian | Alasan ada di sini |
| --- | --- | --- |
| `laravel/framework` | require | Framework-nya |
| `laravel/tinker` | require | `php artisan tinker` |
| `laravel/sanctum` | require | Auth token. Ditambahkan oleh `install:api` |
| `darkaonline/l5-swagger` | require | Swagger UI dan generator OpenAPI, disajikan di production |
| `laravel/boost` | require-dev | Panduan agen, ditambahkan karena `AGENTS.md` memintanya |
| `laravel/pint` | require-dev | Formatter |
| `phpunit/phpunit` | require-dev | Runner pengujian |
| `fakerphp/faker` | require-dev | Data palsu untuk factory |
| `mockery/mockery` | require-dev | Mocking |
| `nunomaduro/collision` | require-dev | Error CLI yang cantik |
| `laravel/pail` | require-dev | Melihat log |
| `laravel/pao` | require-dev | Formatting output untuk agen. Lihat peringatan di langkah 5 |

`laravel/sanctum` adalah satu-satunya yang ditambahkan untuk API itu sendiri. `fakerphp/faker`,
`phpunit/phpunit`, dan `mockery/mockery` sudah ada di skeleton Laravel.

## Bagian mana, require atau require-dev

Aturannya sederhana. Jika aplikasi membutuhkannya saat melayani request, itu adalah `require`. Jika hanya
untuk building, testing, atau tooling lokal, itu adalah `require-dev`.

```sh
composer require vendor/package           # runtime
composer require --dev vendor/tool        # build, test, or local only
```

Keduanya diinstal oleh `composer install` biasa. Perbedaannya adalah `composer install
--no-dev`, yang melewati bagian dev. Jalankan secara dry untuk melihat apa yang akan dilakukannya di sini:

```sh
composer install --no-dev --dry-run
```

```text
Package operations: 0 installs, 0 updates, 44 removals
  - Removing zircote/swagger-php (6.11.0)
  - Removing swagger-api/swagger-ui (v5.33.0)
  - ...
```

Empat puluh empat paket hilang, dan di antaranya `zircote/swagger-php` dan `swagger-api/swagger-ui`.
Jadi instalasi production tidak memiliki Swagger UI dan tidak ada route `/api/documentation`. Itu adalah
trade-off yang benar untuk proyek ini, dan itulah sebabnya l5-swagger adalah dev dependency: API adalah
deliverable, halaman docs adalah alat development.

Jika kamu suatu saat menginginkan `/api/documentation` di production, pindahkan l5-swagger ke
`require`. Jangan lakukan secara tidak sengaja, karena halaman docs tidak memiliki autentikasi di
proyek ini.

## Perintah scaffolding versus instal manual

Ada dua cara paket masuk, dan perbedaannya penting.

### Manual, cara Composer

```sh
composer require --dev darkaonline/l5-swagger --no-interaction
php artisan vendor:publish --provider "L5Swagger\L5SwaggerServiceProvider"
```

Itu adalah dua perintah dan kamu tahu persis apa yang terjadi. `vendor:publish` menyalin dua hal
ke proyekmu:

```text
vendor/darkaonline/l5-swagger/config/l5-swagger.php  ->  config/l5-swagger.php
vendor/darkaonline/l5-swagger/resources/views        ->  resources/views/vendor/l5-swagger
```

Kamu sekarang memiliki file-file itu. Laravel tidak menimpanya saat upgrade, jadi jika versi
l5-swagger di masa depan mengubah config-nya, kamu tidak mendapatkan perubahan itu. Itulah biaya publishing,
dan keuntungannya adalah kamu bisa mengubah pengaturan.

### Scaffolding, cara Artisan

```sh
php artisan install:api --without-migration-prompt
```

Satu perintah itu melakukan empat hal sekaligus: menambahkan `laravel/sanctum` ke `composer.json`,
mempublish migration `personal_access_tokens`, membuat `routes/api.php`, dan menambahkan baris ini
ke `bootstrap/app.php`:

```php
api: __DIR__.'/../routes/api.php',
```

Lalu ia mencetak pengingat untuk melakukan satu hal yang tidak dilakukannya untukmu:

```text
Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.
```

Ini layak direnungkan. **Perintah scaffolding bisa menginstal kode dan tetap membiarkan fitur
rusak.** Ia tidak menambahkan trait, tidak menambah auth route, dan tidak membatasi endpoint mana pun. API tidak dapat digunakan sampai hal-hal itu dikerjakan manual.

Jadi setelah perintah `install:*` apa pun, baca apa yang dicetaknya dan periksa apakah fiturnya benar-benar
berfungsi. Jangan berasumsi perintah itu menyelesaikan pekerjaan.

## Tag yang dapat dipublish

Setiap paket menentukan apa yang boleh kamu salin. Untuk melihat apa yang tersedia, jalankan tanpa
argumen:

```sh
php artisan vendor:publish
```

Tanpa `--provider` maupun `--tag`, ia membuka picker interaktif yang mendaftar setiap
provider dan setiap tag, yang merupakan cara andal untuk menemukan nama. Untuk mempublish satu
hal spesifik:

```sh
php artisan vendor:publish --tag=sanctum-config --no-interaction
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider" --no-interaction
```

Tag yang relevan di sini, dibaca dari `ServiceProvider::$publishGroups`:

| Tag | Menyalin |
| --- | --- |
| `config` (l5-swagger) | `config/l5-swagger.php` |
| `sanctum-config` | `config/sanctum.php` |
| `sanctum-migrations` | migration `personal_access_tokens` |
| `boost-config` | `config/boost.php` |
| `laravel-assets` | aset front-end. Melaporkan "no publishable resources" di proyek ini |

Atau baca grup tanpa prompt interaktif:

```sh
php artisan tinker --execute 'print_r(array_keys(\Illuminate\Support\ServiceProvider::$publishGroups));'
```

Mempublish file config adalah cara mengubah perilaku paket tanpa mengedit `vendor/`.
Edit `config/`, jangan pernah `vendor/`, karena `composer install` akan mengganti apa pun di
`vendor/` dan editanmu hilang tanpa peringatan.

### Jangan jalankan `vendor:publish` dengan tag kosong

`--tag` adalah opsi variadic, jadi `--tag=` menjadi `[""]`, yang dihitung sebagai "a tag was
given". Itu melewati prompt dan mempublish **semua yang disediakan setiap paket**, tanpa
konfirmasi.

Saya melakukan ini secara tidak sengaja saat menulis bagian ini. Ia membuat 17 file yang seharusnya
tidak ada di proyek ini, termasuk salinan kedua dari migration `personal_access_tokens`. Karena
dua migration kemudian membuat tabel yang sama, 43 dari 45 test gagal dengan:

```text
SQLSTATE[HY000]: General error: 1 table "personal_access_tokens" already exists
```

Pemulihannya adalah `rm` pada file yang dipublish dan menjalankan ulang suite. Tidak ada yang di-commit, jadi biayanya waktu bukan history. Jika kamu mengalaminya, periksa `git status` terlebih dahulu,
karena setiap file yang dipublish adalah untracked dan rollback gaya `git clean` aman.

`--all` melakukan hal yang sama secara sengaja. Gunakan hanya ketika kamu sudah memutuskan menginginkan aset
setiap paket.

## Paket Node

```sh
npm install
npm install --save-dev some-package
npm run build
```

`node_modules` tidak terinstal di proyek ini. API dan Swagger UI tidak membutuhkannya. Satu-satunya
pengecualian adalah front end: `php artisan dev` menjalankan `vite`, dan `npm run build` menghasilkan
`public/build/manifest.json` yang diperiksa oleh `resources/views/welcome.blade.php`.

`package.json` di proyek ini mendeklarasikan Vite, Tailwind, dan `concurrently`. `concurrently`
dideklarasikan tetapi tidak ada skrip `postinstall`, jadi tidak ada yang menginstalkan paket untukmu.

## Peringatan tentang `laravel/pao`

Membuat file PHP di proyek ini dapat membuat `laravel/pao`, sebuah dev dependency, memodifikasi setup
Node-mu. Saya memicunya secara tidak sengaja saat menulis docs ini. Menjalankan
`make:controller` menyebabkannya:

- menambahkan `prettier` dan `@prettier/plugin-php` ke devDependencies `package.json`
- membuat `.prettierrc` dengan override PHP `singleQuote: true` dan `printWidth: 1000`
- menjalankan npm dan menulis `package-lock.json` 95KB

Ia tidak membuat `node_modules`. `.prettierrc` yang ditulisnya mengatur `printWidth` ke 1000 untuk
PHP, yang merupakan kebalikan dari `laravel/pint` pada 100. Jika kamu menginstalnya, kamu kini memiliki dua
formatter dengan pendapat bertentangan dan Pint adalah yang benar-benar dijalankan proyek.

Periksa `git status` setelah perintah apa pun yang membuat file PHP, dan putuskan apa yang kamu inginkan:

```sh
git checkout -- package.json && rm -f .prettierrc package-lock.json
```

## Memverifikasi paket benar-benar berfungsi

Jebakan yang akan menghabiskan sore harimu jika kamu tidak mengetahuinya. Ini adalah **false
negative**:

```sh
php -r 'echo class_exists("Illuminate\Database\Eloquent\Attributes\Fillable") ? "exists" : "MISSING";'
# MISSING
```

Class itu ada. Saya mengalaminya saat menulis docs ini dan hampir melaporkan class hilang.
`php -r` mandiri tidak memiliki autoloader Composer yang terdaftar, sehingga `class_exists` tidak dapat
menemukan apa pun di `vendor/`. Muat autoloader terlebih dahulu:

```sh
php -r 'require "vendor/autoload.php"; echo class_exists("Illuminate\Database\Eloquent\Attributes\Fillable") ? "exists" : "MISSING";'
# exists
```

Dua pemeriksaan yang lebih baik, keduanya melalui framework:

```sh
php artisan tinker --execute 'echo class_exists(App\Models\Product::class) ? "ok" : "no";'
php artisan about
```

Dan jika paket mendaftarkan route, konfirmasi bahwa route itu tiba:

```sh
php artisan route:list --path=api -v
```

`route:list` adalah pemeriksaan sinyal tertinggi untuk paket web. Route yang menunjuk ke
`L5Swagger\Http\Controllers\SwaggerController@api` adalah milik paket. Route yang menunjuk ke
`Api\ProductController@index` adalah milikmu. [08](08-creating-an-endpoint.md) membahas
perbedaannya.

## Menghapus paket

```sh
composer remove --dev darkaonline/l5-swagger --no-interaction
```

Kemudian bersihkan apa yang ditinggalkannya, karena `composer remove` tidak melakukan ini:

- [ ] `config/l5-swagger.php`, jika kamu pernah mempublish-nya
- [ ] `resources/views/vendor/l5-swagger/`
- [ ] setiap atribut `use OpenApi\Attributes as OA;` dan `#[OA\...]` di kodemu
- [ ] route, jika kamu mendaftarkannya manual
- [ ] `vendor/bin/pint --dirty`, karena menghapus import menyisakan baris kosong

Periksa apa yang dibawanya secara transitif sebelum kamu berasumsi semuanya hilang:

```sh
composer why zircote/swagger-php
# darkaonline/l5-swagger 11.1.0 requires zircote/swagger-php (^6.0)
```

`composer why` menelusuri tree mundur dari sebuah paket ke siapa pun yang membutuhkannya. Gunakan sebelum
menghapus apa pun, untuk mengetahui apakah sesuatu yang lain bergantung padanya.

## Sebelum menambah dependency, periksa ini dulu

Secara berurutan:

1. **Apakah sudah terinstal?** `composer show --direct` mendaftar 13 yang langsung. Paket
   transitif sudah ada di disk dan bisa kamu gunakan, meskipun bergantung padanya rapuh, karena tidak ada yang menjamin ia tetap ada.
2. **Apakah PHP sendiri bisa melakukannya?** PHP 8.5 memiliki `array_find`, `array_any`, `array_all`,
   `json_validate`, dan sintaks pipe. Periksa sebelum berasumsi paket diperlukan.
3. **Apakah Laravel bisa melakukannya?** Eloquent, Collections, validator, scheduler, dan queue
   mencakup banyak hal yang kalau tidak akan memerlukan paket.
4. **Apakah skeleton framework bisa melakukannya?** Perintah `php artisan make:`, perintah `install:*`,
   dan `php artisan list` mencakup banyak scaffolding.
5. **Hanya setelah itu**, tambah paket.

Alasan ini penting bukanlah kemurnian. Setiap dependency adalah kode yang tidak kamu tulis, yang terus
membutuhkan update, dan yang bisa berkonflik dengan berikutnya. CRUD API di proyek ini hanya membutuhkan tepat satu paket runtime baru. Itu rasio yang bagus.

## Perintah umum

```sh
composer require vendor/package
composer require --dev vendor/tool
composer remove --dev vendor/tool
composer install
composer install --no-dev
composer update vendor/package
composer update --lock
composer show
composer show --direct
composer show vendor/package          # one package in detail
composer why vendor/package            # who needs this
composer why-not vendor/package        # why is it not installed
composer outdated                      # newer versions available
composer validate --check-lock         # is composer.json sane and the lock in sync
composer audit                         # known security advisories
composer dump-autoload                 # regenerate the autoloader after adding files by hand
```

Dua di antaranya layak ditekankan. `composer audit` adalah alasan untuk menjalankannya, karena ia memeriksa versi terinstal terhadap database advisory. Dan `composer validate --check-lock` adalah
one-liner yang memberi tahu apakah `composer.json` dan `composer.lock` cocok.

Perhatikan penamaannya: `composer show` adalah `npm list`, `composer why` adalah `npm why`, dan
`composer outdated` adalah `npm outdated`.
