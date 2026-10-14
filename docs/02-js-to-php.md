# 02. PHP untuk pengembang JavaScript

Panduan ini mengajarkan perbedaan bahasa PHP. Ini bukan catatan status migrasi; perilaku
reporting-app yang di-port dan kesenjangan tersisa ada di [15. Migrasi JS-ke-PHP](js-to-php-migration/15-js-to-php-migration.md).

Semua di sini adalah PHP 8.5, yang dipakai proyek ini. Contoh kode asli.

## Variabel memakai tanda dolar

```php
$name = 'Mechanical Keyboard';
$stock = 42;
$price = 129.99;
$description = null;
```

Di JavaScript Anda menulis `let name = ...`. Di PHP awalan tipe hilang dan Anda menambah
`$`. Hanya itu bedanya. PHP tidak bertipe secara default, sama seperti JS, tetapi Laravel
menambah tipe di tempat yang penting dan Anda sebaiknya juga:

```php
private function perPage(Request $request): int
{
    return min(max($request->integer('per_page', 15), 1), 100);
}
```

`$request` adalah `Request`, dan kembaliannya adalah `int`. Proyek ini punya aturan untuk
itu: selalu beri tipe pada parameter dan nilai kembalian.

## Ada satu tipe array, dan ia mengerjakan dua tugas

Ini pergeseran mental terbesar. PHP tidak punya tipe objek terpisah untuk koleksi
key/value. Satu `array` mencakup baik yang disebut JS sebagai Array maupun yang disebut JS
sebagai Object.

```php
$fruits = ['apple', 'banana'];              // a list, like a JS array
$product = ['name' => 'Keyboard', 'stock' => 5];   // a map, like a JS object
```

Aturan yang mengikuti dari ini:

```php
$product['name']        // 'Keyboard'
$product['missing']     // null, plus a PHP warning. Use ?? to avoid the warning
$product['missing'] ?? 'fallback'
$fruits[0]              // 'apple'
```

Anda tidak dapat mencampur `=>` dan nilai polos dalam literal array yang sama. PHP akan
mem-parse-nya tetapi Anda akan mendapat hasil aneh, jadi jangan.

## Perbandingan ketat adalah default yang Anda inginkan

```php
if ($user->email === $input) { }
```

`===` membandingkan tipe dan nilai. Operator longgar `==` ada dan akan mengejutkan Anda,
karena PHP melakukan cast antar tipe. Gunakan `===` dan berhenti memikirkannya.

## null, dan tidak adanya undefined

JavaScript memiliki `null` dan `undefined`. PHP memiliki `null`, dan membaca key yang
tidak pernah diset memberi Anda `null` dengan peringatan. Tidak ada `undefined`.

```php
$user = null;
$user->name          // fatal error, the object is null
$user?->name         // null, short circuits
$user['name'] ?? null  // map access with a fallback
```

Operator `?->` adalah PHP 8.0. Gunakan itu alih-alih keributan optional chaining, meskipun
Anda dapat menulis `$user?->name` yang terbaca persis seperti JS.

## Truthiness berbeda, dan akan menggigit Anda

Saya menjalankan ini di proyek ini. PHP 8.5.10, output aktual:

```php
if (0) {}       // FALSY
if ("0") {}     // FALSY   <-- this one surprises people
if (0.0) {}     // FALSY
if ("") {}      // FALSY
if ([]) {}      // FALSY   <-- empty array, not truthy like JS
if ("abc") {}   // truthy
if (null) {}    // FALSY
```

Dua yang menjebak pengembang JavaScript:

- `"0"` adalah **falsy** di PHP dan **truthy** di JavaScript. String nol merusak banyak
  logika yang disalin.
- `[]` adalah **falsy** di PHP. Di JavaScript array kosong adalah truthy. Jadi
  `if ($results) { }` berarti "jika ada setidaknya satu baris" di PHP, yang merupakan
  kebalikan dari yang diprediksi insting JS.

Jangan pernah menulis pemeriksaan truthiness polos pada nilai yang tipenya belum Anda
lihat. Bandingkan secara eksplisit: `if ($results !== []) {`, atau lebih baik, gunakan
count.

## Fungsi: `function` dan `fn`

Bentuk panjang, dipakai untuk apa pun yang bernama:

```php
public function toArray(Request $request): array
{
    return parent::toArray($request);
}
```

Bentuk panah pendek, dipakai untuk callback inline kecil:

```php
$query = $request->integer('per_page', 15);
```

Closure menangkap variabel dari scope sekitarnya secara otomatis, sama seperti fungsi panah
JS. Anda akan melihatnya diteruskan ke Eloquent:

```php
User::where('email', $credentials['email'])->first();
```

## Argumen bernama

PHP memungkinkan Anda meneruskan argumen berdasarkan nama. Proyek ini memakainya di mana
fungsi memiliki beberapa opsi:

```php
// app/Http/Controllers/Api/ProductController.php:46
Product::query()->latest('id')->paginate(perPage: $this->perPage($request));
```

Ini lebih dekat ke meneruskan objek opsi di JS. Aman selama Anda tidak mengganti nama
parameter, yang akan merusak setiap panggilan bernama.

## Class, dan hal yang diharapkan Laravel

Model adalah class. Properti dideklarasikan dengan visibility, dan Anda hampir tidak pernah
melihat constructor karena Eloquent menangani loading:

```php
// app/Models/Product.php
namespace App\Models;

#[Fillable(['name', 'description', 'price', 'stock'])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'float',
        ];
    }
}
```

Poin yang layak diperhatikan:

- `namespace App\Models;` adalah padanan PHP dari import path. File berada di
  `app/Models/Product.php` dan namespace mencerminkan direktori.
- `extends Model` mewarisi semua perilaku database. `Product::query()`,
  `Product::create()`, `$product->save()` semuanya diwarisi, tidak ditulis di mana pun di
  file ini.
- `#[Fillable([...])]` adalah atribut PHP 8. Ia memberi tahu Eloquent field mana yang boleh
  diset dari body permintaan. Apa pun yang tidak terdaftar diam-diam diabaikan pada mass
  assignment. Anda mengonfirmasi ini asli:
  `Illuminate\Database\Eloquent\Attributes\Fillable` ada di instalasi ini.
- `casts()` mengonversi nilai saat masuk dan keluar database. `price` adalah kolom
  `decimal(10,2)` tetapi kembali sebagai angka JSON bukan string, sehingga permintaan dan
  respons sepakat tentang tipe.

## Trait

`use HasFactory;` di dalam badan class menarik metode dari trait. Trait adalah cara berbagi
kode antar class yang tidak terkait secara inheritance. `HasFactory` adalah yang memberi
Anda `Product::factory()` dan `Product::factory(10)->create()`.

Ini bukan konsep JavaScript. Padanan terdekat adalah menyalin metode ke dalam class dari
mixin, kecuali PHP melakukannya dengan benar dengan kontrak yang dideklarasikan.

## Promosi properti constructor

Anda akan melihat ini di kode framework:

```php
public function __construct(public GitHub $github) { }
```

`public GitHub $github` mendeklarasikan properti dan menetapkannya dalam satu langkah.
Proyek ini tidak memakainya, karena model dikonstruksi oleh Eloquent bukan oleh Anda.

## Interface dan petunjuk tipe

```php
public function register(RegisterAccountRequest $request): JsonResponse
public function destroy(Request $request): Response
```

Tipe sebelum nama parameter adalah petunjuk. PHP akan melempar `TypeError` jika Anda
meneruskan hal lain, yang berarti sekelas besar bug JavaScript tidak ada di sini. Tipe
setelah titik dua adalah tipe kembalian.

## String

Kutip ganda menginterpolasi. Kutip tunggal tidak.

```php
"Hello, $name"              // interpolates
'Hello, $name'              // literal
"Total: {$order->total}"    // property access inside a string
"Line\nbreak"               // escapes work in double quotes
```

Fungsi helper Laravel yang akan lebih sering Anda pakai daripada metode string bawaan:

```php
str_contains($haystack, $needle)    // JS: haystack.includes(needle)
str_starts_with($haystack, $needle)
substr($s, 0, 10)
```

## Collection versus array

Eloquent mengembalikan `Collection`, yaitu array dengan metode di atasnya. Ini hal terdekat
yang dimiliki Laravel dengan pustaka utilitas array JS, dan sudah dimuat.

```php
$products = Product::all();
$products->count();
$products->map(fn ($p) => $p->name);
$products->filter(fn ($p) => $p->stock > 0);
$products->pluck('name');
```

`pluck` dan `map` adalah yang paling sering Anda raih. Keduanya menggantikan loop
`products.map(p => p.name)`.

## Di mana field sebenarnya berada

Anda bertanya cara menulis variabel di proyek ini. Jawaban langsung: "variable" di API
adalah kolom, dan menambahkannya menyentuh lima tempat, bukan satu.

```php
// 1. the column itself
// database/migrations/..._create_products_table.php
$table->decimal('price', 10, 2);

// 2. the model's allow list
// app/Models/Product.php
#[Fillable(['name', 'description', 'price', 'stock'])]

// 3. the validation rules
// app/Http/Requests/StoreProductRequest.php
'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],

// 4. the JSON shape and the OpenAPI schema
// app/Http/Resources/ProductResource.php
'price' => new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0, example: 129.99),

// 5. a test that fails if you forget
```

Lewatkan langkah 2 dan field ditolak diam-diam, yang membingungkan. Lewatkan langkah 3
dan API menerima nilai yang tidak dapat ditampung database. Lewatkan langkah 4 dan field
hilang dari setiap respons dan body Swagger UI.

[04. Menambahkan field](04-adding-a-field.md) memandu kelimanya dengan perintah asli.

## Hal yang tidak akan diterjemahkan

| JavaScript | PHP di proyek ini |
| --- | --- |
| `module.exports` / `export` | `namespace` di atas file, plus `use` untuk import |
| `import x from './x'` | `use App\Models\Product;` |
| `require('x')` | tidak dipakai, Composer melakukan autoload dari `composer.json` |
| `undefined` | tidak ada. Nilai yang hilang adalah `null` |
| `===` vs `==` | pakai `===`. PHP juga punya `==` dan longgar seperti JS |
| `[]` is truthy | `[]` adalah falsy |
| `"0"` is truthy | `"0"` adalah falsy |
| `Array.isArray()` | tidak perlu, hanya ada satu tipe array |
| Prototype methods | trait, atau `extends` |
| `JSON.parse(JSON.stringify(x))` | `$resource->toArray($request)` untuk model, `json_encode` untuk apa pun |
| `try { } catch { }` | `try { } catch (Throwable $e) { }`, dan class-nya bernama |
| `async` / `await` | tidak dipakai dalam penanganan permintaan. Queue menangani pekerjaan latar belakang |
