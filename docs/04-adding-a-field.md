# 04. Menambahkan field

Ini adalah tugas yang paling sering Anda lakukan, jadi layak dilakukan sekali dengan saksama. Kita akan menambahkan
kolom `sku` (stock keeping unit) ke `Product`.

**Tidak ada satu pun dalam dokumen ini yang sudah diterapkan ke proyek.** Ini adalah panduan langkah demi langkah. Jalankan
perintahnya sendiri dan contoh kode adalah hasil akhir yang seharusnya Anda dapatkan.

## Aturan yang perlu diingat

Field baru bukan satu baris. Field baru ada di **lima tempat**, dan yang pertama Anda lupakan adalah
yang diam-diam paling banyak membuang waktu.

```text
1. migration      the column exists
2. #[Fillable]    Eloquent will accept it from a request
3. request rules  the API validates it
4. Resource       it appears in JSON and in Swagger UI
5. a test         you will notice when step 2 is missing
```

Lewati langkah 2 dan field tersebut dibuang **diam-diam**. Tanpa error, tanpa exception, nilainya
tidak pernah sampai. Itulah mode kegagalan yang perlu diwaspadai.

## Langkah 1: migration

```sh
php artisan make:migration add_sku_to_products_table --table=products
```

Itu membuat `database/migrations/<timestamp>_add_sku_to_products_table.php`. Isi kedua
method. `up()` menerapkannya, `down()` membatalkannya, dan `migrate:rollback` memanggil `down()`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropColumn('sku');
        });
    }
};
```

Catatan tentang definisi kolom:

- `nullable()` agar baris yang sudah ada tidak memerlukan nilai. SQLite tidak dapat menambahkan kolom `NOT NULL`
  ke tabel yang sudah memiliki baris tanpa default.
- `unique()` menambahkan jaminan di tingkat database. Rule validasi `unique` pada langkah 3 menyangkut
  pesan error yang dilihat pengguna, sedangkan constraint database menyangkut kebenaran. Anda
  menginginkan keduanya.
- `after('id')` hanya berpengaruh pada MySQL dan MariaDB. SQLite mengabaikannya. Menyimpannya membuat
  urutan kolom sesuai dengan cara Anda membaca model.

Jalankan:

```sh
php artisan migrate
```

Periksa apakah sudah masuk:

```sh
php artisan tinker
>>> Schema::getColumnListing('products')
```

## Langkah 2: tambahkan ke `#[Fillable]`

```php
// app/Models/Product.php
#[Fillable(['sku', 'name', 'description', 'price', 'stock'])]
class Product extends Model
```

Lewatkan ini dan `Product::create($request->validated())` diam-diam membuang `sku`. Tanpa exception.
Inilah langkah yang sering dilupakan orang.

## Langkah 3: rule validasi

Buat request untuk create, dan ubah request untuk update.

```php
// app/Http/Requests/StoreProductRequest.php
public function rules(): array
{
    return [
        'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')],
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:1000'],
        'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        'stock' => ['required', 'integer', 'min:0'],
    ];
}
```

```php
// app/Http/Requests/UpdateProductRequest.php
public function rules(): array
{
    return [
        'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($this->route('product'))],
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        // ... the rest unchanged
    ];
}
```

Perbedaan antara kedua file adalah `sometimes`:

| Rule | Arti |
| --- | --- |
| `required` | wajib ada dan tidak boleh kosong |
| `nullable` | boleh ada sebagai `null` |
| `sometimes` | hanya validasi kunci ini jika request memuatnya. Inilah yang membuat `PATCH` menjadi parsial |
| `decimal:0,2` | antara 0 dan 2 angka desimal, sehingga `9.5` lolos dan `9.555` gagal |
| `Rule::unique(...)` | menolak nilai yang sudah ada di kolom itu |
| `->ignore($id)` | pada update, mengecualikan nilai saat ini milik record itu sendiri, jika tidak menyimpan produk tanpa mengubah sku-nya akan gagal |

`Rule::unique` memerlukan import. Pint mengurutkan import untuk Anda, jadi jalankan setelah mengedit:

```sh
vendor/bin/pint --dirty --format agent
```

## Langkah 4: Resource dan skema OpenAPI

Di proyek ini keduanya adalah file yang sama, dan itulah hal utama yang perlu dipahami tentang
`ProductResource`. Satu blok `#[OA\Schema]` mendeskripsikan bentuk untuk body request maupun
respons.

```php
// app/Http/Resources/ProductResource.php
#[OA\Schema(
    schema: 'Product',
    required: ['id', 'sku', 'name', 'price', 'stock', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'sku' => new OA\Property(property: 'sku', type: 'string', maxLength: 64, nullable: true, example: 'KB-75-HS-001'),
        'name' => new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Mechanical Keyboard'),
        // ... unchanged
    ],
)]
class ProductResource extends JsonResource
```

Tiga hal yang perlu diketahui tentang blok ini:

- `readOnly: true` memberi tahu Swagger UI untuk menyembunyikan field dari body request namun tetap
  menampilkannya di respons. `id` dan timestamp ditandai seperti ini, itulah sebabnya
  formulir create di Swagger UI tidak menanyakan id kepada Anda.
- Sebuah field hanya **required** di UI jika ada dalam array `required`. Saya menaruh `sku` di
  sana meskipun rule-nya `nullable`, yang merupakan inkonsistensi kecil. Entah keluarkan dari
  `required`, atau buat rule-nya `required`. Pilih satu dan buat keduanya selaras.
- Beri setiap field sebuah `example`. Swagger UI mengisi awal body request dari itu, dan body
  dengan example yang tidak cocok atau hilang akan gagal pada klik pertama `Execute`. Itu adalah
  masalah kecil yang nyata di proyek ini, lihat [06](06-auth-and-tokens.md).

Jika field memerlukan validasi berbeda pada create dan update, satu skema bersama menjadi
canggung. Pada titik itu deklarasikan skema kedua untuk payload tulis. Jangan memelintir
satu skema untuk memalsukannya.

## Langkah 5: factory

```php
// database/factories/ProductFactory.php
public function definition(): array
{
    return [
        'sku' => fake()->unique()->bothify('KB-???-####'),
        'name' => fake()->unique()->words(3, true),
        'description' => fake()->optional()->sentence(),
        'price' => fake()->randomFloat(2, 5, 500),
        'stock' => fake()->numberBetween(0, 250),
    ];
}
```

Factory memberi makan seeder maupun setiap test. Melewatkan `sku` akan membuat
`Product::factory()->create()` menyisipkan null, lalu test `unique` Anda tidak punya apa-apa untuk
dikerjakan.

## Langkah 6: test

```php
// tests/Feature/Api/ProductApiTest.php
public function test_it_creates_a_product_with_a_sku(): void
{
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/products', [
        'sku' => 'KB-75-HS-001',
        'name' => 'Mechanical Keyboard',
        'price' => 129.99,
        'stock' => 42,
    ])
        ->assertCreated()
        ->assertJsonPath('data.sku', 'KB-75-HS-001');

    $this->assertDatabaseHas('products', ['sku' => 'KB-75-HS-001']);
}

public function test_it_rejects_a_duplicate_sku(): void
{
    Sanctum::actingAs(User::factory()->create());
    Product::factory()->create(['sku' => 'KB-75-HS-001']);

    $this->postJson('/api/products', [
        'sku' => 'KB-75-HS-001',
        'name' => 'Another Board',
        'price' => 99.00,
        'stock' => 5,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sku');
}
```

Test pertama juga merupakan pendeteksi langkah 2 Anda. Jika Anda lupa `#[Fillable]`, respons tidak akan
memiliki `sku` dan test gagal. Itulah gunanya menulis test itu.

## Langkah 7: generate ulang spec dan periksa format

```sh
php artisan l5-swagger:generate
php artisan test
vendor/bin/pint --dirty --format agent
```

File spec di-gitignore, jadi `generate` bukan opsional. Lewati dan browser terus
menampilkan skema lama tanpa `sku`.

## Langkah 8: konfirmasi di browser

```sh
php artisan serve
```

Buka `http://localhost:8000/api/documentation`, muat ulang, dan perluas
`POST /api/products`. `sku` seharusnya muncul di body dengan nilai `KB-75-HS-001`.

## Reseeding

Jika Anda menambahkan kolom non-nullable, baris seed yang sudah ada tidak akan memiliki nilai:

```sh
php artisan migrate:fresh --seed
```

Ini menghapus setiap tabel, menjalankan ulang semua migration, dan menjalankan ulang seeder. Ini juga menghapus
pengguna `test@example.com` dan membuatnya ulang dengan kata sandi yang sama, sehingga Masuk Swagger UI
Anda tetap berfungsi. Ini menghapus token apa pun yang telah Anda terbitkan, sehingga Anda memerlukan token baru
dari `POST /api/auth/token`.

## Checklist sebelum commit

```sh
php artisan test
vendor/bin/pint --test
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

Jika Anda menambahkan route, generate ulang saja tidak cukup, route-nya juga harus ada. Dan
jika Anda mengubah apa pun di bawah `#[OA\...]`, ingat spec tidak di-commit, sehingga siapa pun yang
meng-clone repo harus menjalankan `generate` sendiri. Sampaikan itu di pesan commit Anda.

## Menghapus field di kemudian hari

Kebalikannya, dalam urutan ini, agar aplikasi tidak pernah berada dalam keadaan di mana kode mengharapkan kolom
yang sudah hilang:

1. hapus test
2. hapus `OA\Property` dan entri `required`
3. hapus rule dari kedua class request
4. hapus dari `#[Fillable]` dan factory
5. jalankan `php artisan migrate` dengan migration `dropColumn`

Membalik urutan itu merusak aplikasi pada setiap langkah, itulah sebabnya urutan ini layak diikuti.
