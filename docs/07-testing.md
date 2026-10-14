# 07. Pengujian

## Kondisi saat ini

```sh
php artisan test
```

```text
all passing
```

Jalankan suite untuk hitungan terkini ketimbang memercayai angka yang tertulis di sini.

Rinciannya:

| File | Test | Mencakup |
| --- | --- | --- |
| `tests/Feature/Api/ProductApiTest.php` | 16 | CRUD produk, paginasi, validasi |
| `tests/Feature/Api/AccountApiTest.php` | 19 | Pendaftaran, pembaruan layanan mandiri, pencabutan token |
| `tests/Feature/Api/TokenApiTest.php` | 8 | Penerbitan, pemakaian, dan pencabutan token |
| `tests/Feature/ExampleTest.php` | 1 | Bahwa `GET /` mengembalikan 200 |
| `tests/Unit/ExampleTest.php` | 1 | Placeholder dari skeleton |

## Menjalankan sebagian suite

```sh
php artisan test                                   # everything
php artisan test --compact                         # terse output
php artisan test tests/Feature/Api                 # one directory
php artisan test tests/Feature/Api/AccountApiTest.php
php artisan test --filter=test_it_creates_a_product # one test by name
php artisan test --filter=stray_current_password   # substring match
```

`--filter` adalah yang paling sering kamu pakai. Nama method test di proyek ini sengaja panjang
dan deskriptif, jadi substring biasanya cukup.

## Apa yang tidak disentuh test

`phpunit.xml` menimpa environment untuk suite:

```xml
<env name="APP_ENV" value="testing"/>
<env name="BCRYPT_ROUNDS" value="4"/>
<env name="CACHE_STORE" value="array"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

Dua yang paling penting:

- `:memory:` berarti setiap run test mendapat database in-memory sekali pakai. `database/database.sqlite`
  aslimu tidak pernah diubah oleh suite.
- `BCRYPT_ROUNDS=4` ketimbang default 12. Hashing kata sandi sengaja lambat, dan
  pada 12 round suite dengan banyak operasi kata sandi menjadi cukup lambat hingga orang berhenti
  menjalankannya. Empat round cepat dan cukup untuk test.

## Dua hal yang dibutuhkan setiap test API

### `RefreshDatabase` untuk database bersih

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;
}
```

Trait menjalankan migrasimu sekali dan membungkus setiap test dalam transaksi yang di-rollback di
akhir. Setiap test mulai dari kosong, yang membuatnya independen dan tidak bergantung urutan.

### `Sanctum::actingAs` agar terautentikasi

```php
use Laravel\Sanctum\Sanctum;

Sanctum::actingAs(User::factory()->create());
```

Itu membuat request berikutnya dalam test terautentikasi sebagai pengguna itu, tanpa butuh token
asli.

Pakai itu saat test tentang logika bisnis. Pakai token asli saat test tentang token. Kedua pola
muncul di `AccountApiTest`:

```php
// business logic: actingAs is fine
Sanctum::actingAs(User::factory()->create(['password' => 'original-password']));

// token behaviour: needs a real one, and the actingAs user needs a token to own
$user = User::factory()->create();
$token = $user->createToken('swagger-ui')->plainTextToken;

$this->withToken($token)->patchJson('/api/account', [...])->assertOk();
```

## Menulis test

Mulai dengan generator:

```sh
php artisan make:test --phpunit Api/OrderApiTest
```

Itu membuat `tests/Feature/Api/OrderApiTest.php`. Jangan taruh `Feature/` atau `Api/` dalam nama
yang kamu teruskan ke `make:test`, ia menambahkan path itu sendiri.

Contoh lengkap yang menunjukkan gaya di sini:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Valid payload shared by the create/update tests.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mechanical Keyboard',
            'description' => 'Hot-swappable 75% board.',
            'price' => 129.99,
            'stock' => 42,
        ], $overrides);
    }

    public function test_it_creates_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/products', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.price', 129.99);

        $this->assertDatabaseHas('products', [
            'name' => 'Mechanical Keyboard',
            'price' => 129.99,
        ]);
    }
}
```

Konvensi di proyek ini:

- Nama test dibaca sebagai kalimat: `test_it_creates_a_product`, `test_it_rejects_a_duplicate_email`
- `postJson` / `patchJson` / `getJson` / `deleteJson`, jangan versi biasa. Varian `Json`
  menyetel header `Accept`, yang membuat respons error kembali sebagai JSON
- assert pada respons dan database saat terjadi penulisan. 200 tanpa ada yang tertulis adalah
  mode kegagalan nyata
- helper private membangun payload valid agar test yang memvariasikan satu field tetap pendek

## Aserti yang berguna

| Aserti | Memeriksa |
| --- | --- |
| `assertOk()` / `assertCreated()` / `assertNoContent()` | kode status |
| `assertUnauthorized()` / `assertNotFound()` / `assertUnprocessable()` | 4xx yang kamu harapkan |
| `assertJsonPath('data.name', 'X')` | satu nilai tepat di body |
| `assertJsonCount(3, 'data')` | panjang array |
| `assertJsonStructure([...])` | kunci ada, di kedalaman mana pun |
| `assertJsonValidationErrors(['price'])` | 422 dengan error validasi pada kunci itu |
| `assertJsonMissing([...])` | nilai tidak ada, mis. email pengguna lain |
| `assertDatabaseHas('products', [...])` | baris ada |
| `assertDatabaseMissing('products', [...])` | tidak ada baris |
| `assertDatabaseCount('products', 0)` | hitungan baris |
| `->assertHeader('X-Foo', 'bar')` | header respons |

## Test yang menangkap bug nyata

Layak dibaca, karena masing-masing adalah bug yang pernah live di kode pada suatu titik.

### Jebakan `#[Fillable]`

`test_it_creates_a_product` menegaskan `assertJsonPath('data.price', 129.99)`. Jika seseorang
menambah kolom ke migrasi dan lupa `#[Fillable]`, nilai dibuang diam-diam dan asersi ini gagal.
Itulah test menjalankan tugasnya sebagai penjaga regresi untuk kegagalan diam-diam.

### Jebakan cache guard

```php
public function test_a_deleted_account_token_no_longer_authenticates(): void
{
    $user = User::factory()->create();
    $token = $user->createToken('swagger-ui')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/account')->assertNoContent();

    // The application instance is shared across calls in a test, so the guard
    // would otherwise reuse the user it already resolved and never re-check
    // the token. Forgetting guards forces a real re-authentication.
    $this->app->make('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/account')->assertUnauthorized();
}
```

Tanpa baris `forgetGuards()` test ini mengembalikan 200 dan gagal, padahal token benar-benar
mati. Penyebabnya adalah test berbagi satu instance aplikasi, dan `AuthManager` meng-cache guard
yang sudah di-resolve di `protected $guards = []`. Tambahkan baris itu setiap kali inti test
adalah bahwa autentikasi berubah.

### Membuktikan perbaikan itu nyata

Saat aturan `current_password` diperbaiki, test baru dicek terhadap kode lama dengan mengembalikan
satu baris itu dan menjalankan ulang. Keduanya gagal dengan `422 "The password is incorrect"`,
yang membuktikan test tidak vakum:

```sh
# temporarily revert, confirm failure, restore
php artisan test --filter=stray_current_password
```

Test yang lolos baik sebelum maupun sesudah perbaikan tidak sedang menguji perbaikan.

## Jalankan suite di Postgres juga

`php artisan test` memakai SQLite, dan SQLite sepakat dengan Postgres jauh lebih sedikit daripada
kelihatannya. Empat bug di proyek ini lolos seluruh suite SQLite dan gagal pada database yang
benar-benar dipakai production:

| Bug | SQLite | Postgres |
| --- | --- | --- |
| `whereColumn('stock', '<=', 5)` membandingkan dua *kolom*, jadi `5` dibaca sebagai nama kolom | diam-diam mengembalikan baris | `column "5" does not exist` |
| `where('name', 'like', ...)` — SQLite melipat case untuk ASCII, Postgres tidak | mencocokkan `kopi` → `Kopi` | tidak mengembalikan apa pun |
| `products.price` adalah `decimal(10,2)`, membatasi di ~100 juta; motor dari seeder adalah 245 juta | diterima | `numeric field overflow` |
| `assertDatabaseMissing('users', ['current_password' => …])` mengkueri kolom yang memang tidak ada | lolos | `column does not exist` |

Setiap satunya adalah bug production yang dilaporkan SQLite sebagai baik-baik saja. Yang ketiga
akan gagal saat deploy: production adalah Supabase Postgres, dan seeder adalah yang dijalankan
instalasi baru pertama kali.

Jadi jalankan keduanya sebelum kamu memercayai suite:

```sh
php artisan test                                    # SQLite, the fast loop

# Postgres, for anything touching SQL or the schema
initdb -D /tmp/pgtest -U postgres --auth=trust
pg_ctl -D /tmp/pgtest -o "-p 5499 -k /tmp" -l /tmp/pg.log start
psql -h /tmp -p 5499 -U postgres -c "CREATE DATABASE test OWNER postgres"
psql -h /tmp -p 5499 -U postgres -d test -c "CREATE ROLE anon LOGIN PASSWORD 'anon'"

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5499 DB_DATABASE=test \
DB_USERNAME=postgres DB_PASSWORD= DB_URL= APP_ENV=testing \
CACHE_STORE=array SESSION_DRIVER=array \
APP_TEST_ANON_DSN="pgsql:host=127.0.0.1;port=5499;dbname=test" \
APP_TEST_ANON_USER=anon APP_TEST_ANON_PASSWORD=anon \
php artisan test
```

Dua hal hanya dicek saat database adalah Postgres, dan keduanya skip dengan pesan ketimbang gagal
saat bukan:

- **grup `pgsql`** — perilaku `LIKE`-vs-`ILIKE` dan presisi di atas.
- **`RowLevelSecurityTest`** — RLS tidak ada di SQLite, jadi test yang lolos di sana akan
  menegaskan bahwa statement yang ditolak driver tidak berjalan.

Run SQLite yang hijau itu perlu dan tidak cukup.

## Test bukan seluruh cerita

Dua bug nyata di proyek ini tidak terlihat oleh suite:

1. Request browser yang tidak terautentikasi mengembalikan **500** ketimbang 401, karena
   `getJson()` mengirim `Accept: application/json` dan itu melewati jalur redirect yang melempar.
   Ditemukan dengan `curl` biasa yang tidak mengirim header `Accept`.
2. Swagger UI mengisi awal body request dengan `password` dan `password_confirmation` yang tidak
   cocok, jadi Execute gagal pada klik pertama. Ditemukan dengan melihat browser, bukan oleh asersi
   mana pun.

Jadi jalankan suite, dan juga klik melalui hal itu. Mereka menangkap kelas masalah yang berbeda.

## Sebelum kamu commit

```sh
php artisan test
vendor/bin/pint --dirty --format agent
```

`vendor/bin/pint --test` memeriksa tanpa menulis, yang kamu inginkan di CI.

## Gate deploy

Suite biasa membuktikan aplikasi benar; gate deploy membuktikan artefak yang di-deploy
tidak basi. Spec OpenAPI yang di-commit, manifest Vite, lock npm, dan contoh env adalah
file yang bisa kedaluwarsa sementara semua test hijau — dan masing-masing kedaluwarsa
dengan cara yang hanya terlihat di produksi.

```sh
composer test:deploy
```

Dua tier. Tier Unit jalan di mana saja tanpa database (±0,3 detik): spec vs route, manifest,
lock npm, dan kunci `.env.example`. Tier Postgres (`DeployFreshDatabaseTest`) hanya jalan
dengan koneksi pgsql dan skip di SQLite — ia melakukan `migrate:fresh --seed` sungguhan
untuk membuktikan rantai migrasi bekerja di engine produksi:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5499 DB_DATABASE=rlscheck \
DB_USERNAME=postgres DB_PASSWORD= DB_URL= \
APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array \
composer test:deploy
```

Jalankan sebelum push yang berujung deploy. Detail tiap cek ada di docblock
`tests/Unit/DeployReadinessTest.php`; langkah verifikasi [10. Deploy ke Vercel](10-deploying.md).
