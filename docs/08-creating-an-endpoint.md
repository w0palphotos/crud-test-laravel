# 08. Membuat endpoint

Ini adalah tugas inti. [04](04-adding-a-field.md) mengubah sesuatu yang sudah ada;
ini menambahkan sesuatu yang baru.

**Tidak ada satu pun dalam contoh yang dikerjakan ini yang telah diterapkan ke proyek.** Ini adalah panduan langkah demi langkah. Jalankan
perintahnya sendiri dan isi kodenya selagi berjalan.

## Pertama, `/api/documentation` bukan milikmu

Ada baiknya mulai dari sini, karena ini kejutan yang berguna. `GET /api/documentation` adalah
halaman Swagger UI, dan kamu tidak menulisnya. Halaman itu tidak ada di `routes/api.php`:

```sh
grep -c documentation routes/api.php
# 0
```

Halaman itu berasal dari paket `darkaonline/l5-swagger`, yang mendaftarkan rutenya sendiri dari
dalam service provider-nya:

```php
// vendor/darkaonline/l5-swagger/src/L5SwaggerServiceProvider.php:32
$this->loadRoutesFrom(__DIR__.'/routes.php');
```

Dan halaman itu mengambil path-nya langsung dari config:

```php
// vendor/darkaonline/l5-swagger/src/routes.php:36
$router->get($config['routes']['api'], [
    'as' => 'l5-swagger.'.$name.'.api',
    'middleware' => $config['routes']['middleware']['api'] ?? [],
    'uses' => '\L5Swagger\Http\Controllers\SwaggerController@api',
]);
```

```php
// config/l5-swagger.php:15
'api' => 'api/documentation',
```

Jadi ada dua jenis endpoint di proyek ini. Milikmu berada di `routes/api.php` dan
menunjuk ke controller di `app/`. Endpoint paket datang dengan sendirinya, kamu mengonfigurasinya di
`config/`, dan kamu hanya boleh mengeditnya melalui file config, jangan pernah dari sumber
vendor.

Untuk memastikan dari mana suatu route berasal:

```sh
php artisan route:list --path=api -v
```

Route yang menunjuk ke `L5Swagger\Http\Controllers\SwaggerController@api` adalah milik paket. Route
yang menunjuk ke `Api\ProductController@index` adalah milikmu.

## Anatomi endpoint di proyek ini

Setiap endpoint yang kamu tulis menyentuh lima hal ini:

```text
route        routes/api.php          which controller method
middleware   auth:sanctum            who is allowed in
request      StoreOrderRequest      is the body valid
controller   OrderController        the logic
resource     OrderResource          the JSON shape and the OpenAPI schema
```

Ditambah sebuah test. Tiga controller yang sudah ada adalah implementasi referensi, dan
`ProductController` adalah yang perlu ditiru.

## Langkah 1: generate file

Satu perintah mengerjakan sebagian besar pekerjaan:

```sh
php artisan make:controller Api/OrderController --api -m Order -R --test --no-interaction
```

Saya menjalankan tepat perintah ini untuk memeriksa apa yang dihasilkannya. Perintah itu membuat empat file:

```text
app/Http/Controllers/Api/OrderController.php
app/Http/Requests/StoreOrderRequest.php
app/Http/Requests/UpdateOrderRequest.php
tests/Feature/Http/Controllers/Api/OrderControllerTest.php
```

Fungsi masing-masing flag:

| Flag | Efek |
| --- | --- |
| `--api` | Melewati method `create` dan `edit`, yang hanya untuk web |
| `-m Order` | Menambahkan type-hint `App\Models\Order` pada signature method |
| `-R` | Membuatkan dua kelas FormRequest untukmu |
| `--test` | Membuat file test |

Dua peringatan berdasarkan apa yang saya lihat:

- Test mendarat di `tests/Feature/Http/Controllers/Api/`, bukan di
  `tests/Feature/Api/` milik proyek ini. Pindahkan, atau buat saja test secara manual:
  `php artisan make:test --phpunit Api/OrderApiTest`
- Method yang di-generate tidak memiliki **return type**. Tambahkan, karena
  `ProductController` memilikinya dan Pint tidak akan menambahkannya untukmu.

Controller yang di-generate berawal seperti ini:

```php
class OrderController extends Controller
{
    public function index()
    {
        //
    }

    public function store(StoreOrderRequest $request)
    {
        //
    }
    // show, update, destroy follow
}
```

### `laravel/pao` akan bereaksi

Membuat file PHP di proyek ini memicu `laravel/pao`, sebuah dev dependency. Ia menambahkan
`prettier` dan `@prettier/plugin-php` ke `package.json`, membuat `.prettierrc`, dan menjalankan npm
untuk menulis `package-lock.json` berukuran 95KB. Ia tidak membuat `node_modules`.

Itu tidak berbahaya tetapi berisik pada pertama kali. Jika kamu tidak menginginkan Prettier di proyek ini, beri tahu
saya dan kita bisa menghapusnya, atau periksa apakah `pao` memiliki pengaturan untuk itu.

## Langkah 2: model dan migration-nya

Jika `Order` belum ada, generator di atas tidak membuatnya untuk saya, jadi buat
dengan benar:

```sh
php artisan make:model Order -mf --no-interaction
```

`-m` membuat migration, `-f` membuat factory. Lihat
[04](04-adding-a-field.md) tentang cara mengisinya.

## Langkah 3: resource

```sh
php artisan make:resource OrderResource --no-interaction
```

```php
// app/Http/Resources/OrderResource.php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    required: ['id', 'reference', 'total', 'created_at', 'updated_at'],
    properties: [
        'id' => new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        'reference' => new OA\Property(property: 'reference', type: 'string', example: 'ORD-0001'),
        'total' => new OA\Property(property: 'total', type: 'number', format: 'float', minimum: 0, example: 259.98),
        'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time', readOnly: true),
        'updated_at' => new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', readOnly: true),
    ],
)]
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
```

Satu blok `#[OA\Schema]`, berfungsi sebagai bentuk response sekaligus bentuk request body.
Beri setiap property sebuah `example`, dengan alasan yang dijelaskan di langkah 7.

## Langkah 4: kelas request

`StoreOrderRequest`:

```php
public function rules(): array
{
    return [
        'reference' => ['required', 'string', 'max:64', Rule::unique('orders', 'reference')],
        'total' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
    ];
}
```

`UpdateOrderRequest`, rule yang sama dengan `sometimes` dan pengecualian:

```php
public function rules(): array
{
    return [
        'reference' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('orders', 'reference')->ignore($this->route('order'))],
        'total' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
    ];
}
```

`sometimes` adalah yang membuat update menjadi parsial. Tanpanya setiap field akan menjadi wajib pada
`PATCH`, yang bertentangan dengan makna endpoint tersebut.

## Langkah 5: controller

Tiru bentuk `ProductController`. Atribut OpenAPI pada setiap method adalah yang membuat
endpoint muncul di Swagger UI:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Orders', description: 'CRUD operations on customer orders.')]
class OrderController extends Controller
{
    #[OA\Get(
        path: '/api/orders',
        summary: 'List orders',
        tags: ['Orders'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'A page of orders.', content: new OA\JsonContent(ref: '#/components/schemas/Order')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return OrderResource::collection(
            Order::query()->latest('id')->paginate(perPage: 15),
        );
    }

    #[OA\Post(
        path: '/api/orders',
        summary: 'Create an order',
        tags: ['Orders'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Order', example: [
                'reference' => 'ORD-0001',
                'total' => 259.98,
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The created order.', content: new OA\JsonContent(ref: '#/components/schemas/Order')),
            new OA\Response(response: 401, description: 'Unauthenticated.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ],
    )]
    public function store(StoreOrderRequest $request): OrderResource
    {
        return new OrderResource(Order::create($request->validated()));
    }
    // show, update, destroy follow the same pattern
}
```

Empat aturan yang tidak akan saya langgar, semuanya ditiru dari controller yang sudah ada:

- Tag dipasang pada **class**, atribut operation pada **method**.
- `security: [['bearerAuth' => []]]` pada apa pun yang terautentikasi. Hilangkan untuk route publik
  daripada menulis array kosong, seperti yang dilakukan dua endpoint publik.
- Dokumentasikan status code yang benar-benar kamu kembalikan. 201 untuk create, 204 untuk delete, 422 untuk
  validasi, 401 untuk tanpa token, 404 untuk baris yang hilang.
- Kembalikan `Resource`, jangan pernah model. Itu memberimu wrapper `data` dan perilaku 201
  yang dijelaskan di [03](03-request-lifecycle.md).

## Langkah 6: route

Tambahkan di dalam grup terautentikasi di `routes/api.php`:

```php
Route::middleware('auth:sanctum')->group(function (): void {
    // ...
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
});
```

Deklarasikan satu per baris, bukan dengan `Route::apiResource`. [01](01-running-it.md) menjelaskan
mengapa proyek ini menghindari `apiResource`, dan versi singkatnya adalah bahwa itu akan menambah route `PUT`
yang menjanjikan semantik penggantian penuh yang tidak dipenuhi oleh controller partial-update.

Nama parameter harus cocok dengan argumen method. `Route::get('/orders/{order}', ...)`
dengan `public function show(Order $order)` adalah yang membuat Laravel melakukan binding baris untukmu.

Untuk route yang bukan bagian dari resource, satu baris saja cukup:

```php
Route::get('/account/summary', [AccountController::class, 'summary'])
    ->middleware('auth:sanctum');
```

## Langkah 7: generate ulang spec dan konfirmasi

```sh
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

Kemudian buka `http://localhost:8000/api/documentation`, muat ulang, dan cari tag `Orders`.
Periksa tiga hal:

1. Endpoint terdaftar di bawah tag yang benar.
2. **Klik Execute pada form create tanpa mengedit apa pun.** Body yang sudah terisi
   harus lolos validasi, jika tidak kamu mengalami masalah example-mismatch yang dijelaskan di
   [06](06-auth-and-tokens.md).
3. Bentuk response cocok dengan yang dikembalikan Resource.

### Di mana tag baru muncul

Swagger UI mengurutkan section berdasarkan urutan kemunculan pertama tag di spec, dan swagger-php
memindai `app/` berdasarkan urutan nama file. Urutan saat ini persis cocok dengan nama file:

```text
spec:  Account  ->  Products  ->  Auth
files: AccountController.php,  ProductController.php,  TokenController.php
```

`TokenController` membawa tag `Auth`, itulah sebabnya autentikasi berada terakhir meskipun
kamu membutuhkannya pertama dalam alur.

Tambahkan `OrderController.php` dan tag `Orders` yang baru akan mendarat **di antara** `Account` dan
`Products`, karena di situlah nama file terurut. Jika kamu menginginkan section di tempat
lain, nama tag bukanlah yang mengendalikannya, nama file-lah yang mengendalikan. Mengganti nama file adalah satu-satunya
tuas, dan itu tuas yang lemah, jadi jangan dilawan.

## Langkah 8: test

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_reach_any_order_endpoint(): void
    {
        $order = Order::factory()->create();

        $this->getJson('/api/orders')->assertUnauthorized();
        $this->postJson('/api/orders', ['reference' => 'ORD-1', 'total' => 10])->assertUnauthorized();
        $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
        $this->patchJson("/api/orders/{$order->id}", ['total' => 20])->assertUnauthorized();
        $this->deleteJson("/api/orders/{$order->id}")->assertUnauthorized();
    }

    public function test_it_creates_an_order(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/orders', ['reference' => 'ORD-0001', 'total' => 259.98])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'ORD-0001');

        $this->assertDatabaseHas('orders', ['reference' => 'ORD-0001']);
    }

    public function test_it_updates_only_the_supplied_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $order = Order::factory()->create(['reference' => 'ORD-OLD', 'total' => 10.00]);

        $this->patchJson("/api/orders/{$order->id}", ['total' => 25.50])
            ->assertOk()
            ->assertJsonPath('data.reference', 'ORD-OLD')
            ->assertJsonPath('data.total', 25.5);
    }
}
```

Tulis guest test terlebih dahulu. Ini adalah test termurah di file tersebut dan akan gagal begitu
seseorang lupa `auth:sanctum` pada route.

Jangan gunakan angka bulat seperti `10.00` dalam assertion. JSON meng-encode `10.0` sebagai `10`, sebuah
int, dan `assertJsonPath` membandingkan secara strict, sehingga test gagal karena hal teknis formatting
bukan karena perilaku. Gunakan `25.50` dan harapkan `25.5`, atau gunakan harga seperti `19.95`.

## Langkah 9: sebelum kamu commit

```sh
php artisan test
vendor/bin/pint --dirty --format agent
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

## Checklist untuk endpoint baru

- [ ] Controller, kelas request, resource di-generate dengan `make:`
- [ ] Return type pada setiap method
- [ ] Class memiliki `#[OA\Tag]`, setiap method memiliki atribut operation
- [ ] `security` dideklarasikan, atau route memang publik
- [ ] Status code yang didokumentasikan cocok dengan yang dikembalikan kode
- [ ] Route di grup middleware yang benar, satu baris per verb, tanpa `PUT`
- [ ] Nama parameter route cocok dengan argumen method
- [ ] Setiap `OA\Property` memiliki `example`, dan field kata sandi memiliki example konfirmasi yang cocok
- [ ] Guest test, create test, dan partial-update test
- [ ] `l5-swagger:generate` dijalankan, dan Execute berfungsi pada klik pertama
- [ ] `php artisan test` dan Pint bersih
