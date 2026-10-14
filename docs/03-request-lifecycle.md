# 03. Siklus hidup request

Ini menelusuri request nyata: `POST /api/products` dengan bearer token dan body JSON. Setiap
file yang dirujuk di sini ada di repositori ini.

## Versi sesingkat mungkin

```text
URL
  -> routes/api.php          which controller method
  -> middleware              who is allowed in
  -> FormRequest             is the body valid (and rejected here if not)
  -> controller method       the actual logic
  -> model                   the database
  -> Resource                the JSON shape
  -> response
```

Jika ada satu langkah yang menolak, langkah sisanya tidak akan berjalan.

## Langkah 0: boot

`public/index.php` adalah satu-satunya entry point. Setiap request HTTP masuk ke aplikasi
di sana, baik yang datang dari Apache, Nginx, maupun `php artisan serve`.

`bootstrap/app.php` adalah tempat aplikasi dikonfigurasi. Tiga bagiannya yang penting di sini:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',      // this line loads the API routes
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Guests on the web guard are sent to the login form; the api group is
        // unaffected and still answers 401 JSON, via shouldRenderJsonWhen below.
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('products.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

Dua baris tersebut ada karena bug nyata yang ditemukan saat membangun proyek ini:

- `redirectGuestsTo`. Middleware `auth`, ketika tidak dapat mengautentikasi, mengarahkan
  browser ke route `login`. Saat proyek ini masih khusus API, route tersebut tidak ada dan
  middleware melempar `RouteNotFoundException`, mengembalikan **500** alih-alih 401. UI web
  menambahkan route tersebut, sehingga redirect kini terresolve dan API mengandalkan baris berikutnya.
- `shouldRenderJsonWhen`. Tanpanya, error pada route API mencoba merender halaman error
  HTML. Bagian `$request->is('api/*')` berarti setiap route yang diawali `api/` otomatis mendapat error
  JSON, tanpa pekerjaan per-route. Inilah yang menjaga panggilan API tamu tetap berupa 401
  JSON meskipun tamu kini diarahkan saat mengunjungi halaman web.

## Langkah 1: route menentukan controller

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    // ...
});
```

`[ProductController::class, 'store']` adalah array PHP yang menyimpan string nama class dan
nama method. Bentuknya seperti `['ProductController', 'store']` JavaScript dalam tabel route,
dan kurang lebih memang begitu idenya.

Lihat route-nya sendiri kapan saja:

```sh
php artisan route:list --path=api --except-vendor
php artisan route:list -v --path=api          # adds the middleware column
```

## Langkah 2: middleware berjalan

`php artisan route:list -v --path=api` menunjukkan apa yang benar-benar berjalan untuk `POST /api/products`:

```text
POST api/products .. Api\ProductController@store
  ⇂ api
  ⇂ Illuminate\Auth\Middleware\Authenticate:sanctum
```

Grup `api` di aplikasi ini hanya terresolve ke tepat satu middleware. Saya mengonfirmasinya saat runtime:

```php
app('router')->getMiddlewareGroups()['api'];
// ["Illuminate\Routing\Middleware\SubstituteBindings"]
```

Itu layak dipahami. Grup `api` bawaan Laravel sering diberi throttle, tetapi
`$apiLimiter` secara default bernilai `null` di sini, sehingga tidak ada throttle global. Satu-satunya throttle API
global di proyek ini adalah `throttle:6,1` eksplisit pada dua endpoint publik.

Perhatikan apa yang **tidak** termasuk dalam grup `api`, dan apa yang termasuk dalam grup `web`:

| Middleware | `api` | `web` |
| --- | --- | --- |
| `SubstituteBindings` | ya | ya |
| `StartSession` | tidak | ya |
| `PreventRequestForgery` (CSRF) | tidak | ya |
| `EncryptCookies` | tidak | ya |
| `InjectBoost` (Laravel Boost) | tidak | ya |

Tidak ada pemeriksaan CSRF pada API, karena tidak ada cookie yang bisa dipalsukan permintaannya. Token dalam
header `Authorization` tidak dilampirkan otomatis oleh browser, itulah sebabnya proyek ini
tidak memerlukan proteksi CSRF pada `api/*`.

### `auth:sanctum`

Middleware inilah yang mengubah bearer token menjadi pengguna yang terautentikasi. Middleware ini membaca
header `Authorization`, melakukan hash SHA-256 terhadap nilainya, dan mencari baris yang cocok di
`personal_access_tokens`. Jika menemukannya, middleware memuat `User` dan menjadikannya guard default
untuk sisa request.

Jika tidak menemukannya, request berhenti di sini dengan 401 dan method controller Anda tidak akan berjalan.

## Langkah 3: FormRequest memvalidasi sebelum kode Anda berjalan

Inilah bagian yang mengejutkan orang yang datang dari Express. Di Express Anda akan menulis
validasi di dalam handler. Di sini, tanda tangan controller melakukannya untuk Anda:

```php
// app/Http/Controllers/Api/ProductController.php
public function store(StoreProductRequest $request): ProductResource
{
    return new ProductResource(Product::create($request->validated()));
}
```

```php
// app/Http/Requests/StoreProductRequest.php
class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

Laravel melihat type hint `StoreProductRequest`, menginstansiasinya, dan menjalankan `rules()`
**sebelum** memanggil `store()`. Jika validasi gagal, Anda mendapat 422 dengan body JSON dan
badan controller tidak akan dieksekusi:

```json
{
  "message": "The price field must be at least 0.",
  "errors": { "price": ["The price field must be at least 0."] }
}
```

Jika lolos, `$request->validated()` hanya mengembalikan **kunci yang memiliki rule dan lolos**.
Itulah sebabnya controller ini dapat meneruskan hasilnya langsung ke `create()` tanpa mengkhawatirkan
field tambahan dalam body request.

`$request->safe()` adalah versi yang tidak melempar exception saat gagal. Anda menggunakannya ketika
request sudah divalidasi dan Anda hanya ingin nilainya.

## Langkah 4: controller berjalan

```php
public function store(StoreProductRequest $request): ProductResource
{
    return new ProductResource(Product::create($request->validated()));
}
```

`Product::create()` diwarisi dari Eloquent. Method ini membangun INSERT dari array yang diberikan,
tetapi hanya untuk kolom yang tercantum dalam `#[Fillable]` pada model:

```php
// app/Models/Product.php
#[Fillable(['name', 'description', 'price', 'stock'])]
class Product extends Model
```

Kunci yang tidak ada dalam daftar itu dibuang tanpa error. Ini adalah jaring pengaman kedua setelah
validasi, dan itulah sebabnya daftar fillable harus selalu sinkron dengan migration dan
rule.

## Langkah 5: model membaca dan menulis

`Product::query()->latest('id')->paginate(perPage: 15)` membangun SELECT. `latest('id')`
menambahkan `ORDER BY id DESC`. `paginate()` juga menjalankan query count dan mengembalikan paginator,
bukan array biasa.

Route model binding inilah yang membuat method `show` ini bekerja:

```php
public function show(Product $product): ProductResource
{
    return new ProductResource($product);
}
```

URL-nya berbunyi `/api/products/12` dan method meminta sebuah `Product`. `SubstituteBindings`
memuat baris 12 dan menyerahkannya. Jika tidak ada baris 12, Laravel mengembalikan 404 sebelum
badan method berjalan, dan berkat `shouldRenderJsonWhen`, 404 itu berupa JSON. Tidak ada
panggilan `findOrFail` di mana pun dalam controller ini karena framework melakukannya untuk Anda.

## Langkah 6: Resource membentuk JSON

```php
// app/Http/Resources/ProductResource.php
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
```

`parent::toArray()` diwarisi dari `JsonResource` dan mengubah model menjadi array,
dengan menghormati atribut `#[Hidden]` pada `User` yang menjaga `password` keluar dari output.

Mengembalikan resource alih-alih model mentah memberi Anda dua hal secara gratis, keduanya ditangani di
`Illuminate\Http\Resources\Json\ResourceResponse`:

- payload dibungkus dalam kunci `data`
- status code dihitung untuk Anda

Saya mengonfirmasi logika status tersebut di source framework:

```php
protected function calculateStatus()
{
    return $this->resource->resource instanceof Model &&
           $this->resource->resource->wasRecentlyCreated ? 201 : 200;
}
```

Jadi `store` mengembalikan **201** dan `update` mengembalikan **200**, tanpa status code tertulis
di mana pun. Controller terbaca lebih baik tanpanya, tetapi Anda harus tahu bahwa itu terjadi
secara implisit dan bukan karena kebetulan.

## Langkah 7: respons

```http
HTTP/1.1 201 Created
content-type: application/json
```

```json
{
  "data": {
    "id": 12,
    "name": "Mechanical Keyboard",
    "description": "Hot-swappable 75% board.",
    "price": 129.99,
    "stock": 42,
    "created_at": "2026-09-28T14:03:11.000000Z",
    "updated_at": "2026-09-28T14:03:11.000000Z"
  }
}
```

`price` adalah angka JSON, bukan string. Itulah entri `casts()` pada model yang menjalankan tugasnya,
dan itu penting: cast `decimal:2` Laravel akan menghasilkan `"129.99"` sebagai string,
yang berarti request mengirim angka sedangkan respons mengembalikan string.

## Dari mana error berasal

| Situasi | Yang menangani | Status |
| --- | --- | --- |
| Body gagal divalidasi | FormRequest | 422 |
| Tanpa token, atau token salah | Middleware `auth:sanctum` | 401 |
| Tidak ada id produk seperti itu | Route model binding | 404 |
| Email sudah terdaftar | `Rule::unique` | 422 |
| Terkena throttle | `throttle:6,1` | 429 |

Tak satu pun dari ini ditangani dalam `try`/`catch` di controller. Laravel mengubahnya menjadi
JSON karena `shouldRenderJsonWhen` di `bootstrap/app.php`.

## Contoh lengkap, dari awal sampai akhir

```sh
TOKEN=$(php artisan tinker --execute 'echo App\Models\User::first()->createToken("cli")->plainTextToken;' | tr -d '\n')

curl -s -X POST http://127.0.0.1:8000/api/products \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"name":"Mechanical Keyboard","price":129.99,"stock":42}'
```

Baca request itu dari kiri ke kanan dan Anda baru saja menelusuri seluruh alur di atas.
