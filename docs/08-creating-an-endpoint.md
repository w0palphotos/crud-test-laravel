# 08. Creating an endpoint

This is the core task. [04](04-adding-a-field.md) changes something that already exists;
this adds something new.

**Nothing in the worked example has been applied to the project.** It is a walkthrough. Run
the commands yourself and fill in the code as you go.

## First, `/api/documentation` is not yours

It is worth starting here, because it is a useful surprise. `GET /api/documentation` is the
Swagger UI page, and you did not write it. It is not in `routes/api.php`:

```sh
grep -c documentation routes/api.php
# 0
```

It comes from the `darkaonline/l5-swagger` package, which registers its own routes from
inside its service provider:

```php
// vendor/darkaonline/l5-swagger/src/L5SwaggerServiceProvider.php:32
$this->loadRoutesFrom(__DIR__.'/routes.php');
```

And it takes its path straight out of config:

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

So there are two kinds of endpoint in this project. Yours live in `routes/api.php` and
point at controllers in `app/`. Package endpoints arrive on their own, you configure them in
`config/`, and you would only ever edit them through the config file, never the vendor
source.

To confirm where any route comes from:

```sh
php artisan route:list --path=api -v
```

A route pointing at `L5Swagger\Http\Controllers\SwaggerController@api` is the package's. A
route pointing at `Api\ProductController@index` is yours.

## Anatomy of an endpoint in this project

Every endpoint you write touches these five things:

```
route        routes/api.php          which controller method
middleware   auth:sanctum            who is allowed in
request      StoreOrderRequest      is the body valid
controller   OrderController        the logic
resource     OrderResource          the JSON shape and the OpenAPI schema
```

Plus a test. The existing three controllers are the reference implementation, and
`ProductController` is the one to copy.

## Step 1: generate the files

One command does most of the work:

```sh
php artisan make:controller Api/OrderController --api -m Order -R --test --no-interaction
```

I ran exactly this to check what it produces. It created four files:

```
app/Http/Controllers/Api/OrderController.php
app/Http/Requests/StoreOrderRequest.php
app/Http/Requests/UpdateOrderRequest.php
tests/Feature/Http/Controllers/Api/OrderControllerTest.php
```

What each flag does:

| Flag | Effect |
| --- | --- |
| `--api` | Skips the `create` and `edit` methods, which are web-only |
| `-m Order` | Type-hints `App\Models\Order` in the method signatures |
| `-R` | Generates the two FormRequest classes for you |
| `--test` | Generates a test file |

Two warnings based on what I saw:

- The test lands in `tests/Feature/Http/Controllers/Api/`, not this project's
  `tests/Feature/Api/`. Move it, or just create the test by hand:
  `php artisan make:test --phpunit Api/OrderApiTest`
- The generated methods have **no return types**. Add them, since
  `ProductController` has them and Pint will not add them for you.

The generated controller starts like this:

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

### `laravel/pao` will react

Creating PHP files in this project triggers `laravel/pao`, a dev dependency. It added
`prettier` and `@prettier/plugin-php` to `package.json`, created `.prettierrc`, and ran npm
to write a 95KB `package-lock.json`. It did not create `node_modules`.

That is harmless but noisy the first time. If you do not want Prettier in this project, tell
me and we can remove it, or check whether `pao` has a setting for it.

## Step 2: the model and its migration

If `Order` does not exist yet, the generator above did not create it for me, so make it
properly:

```sh
php artisan make:model Order -mf --no-interaction
```

`-m` makes the migration, `-f` makes the factory. See
[04](04-adding-a-field.md) for how to fill them in.

## Step 3: the resource

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

One `#[OA\Schema]` block, serving as both the response shape and the request body shape.
Give every property an `example`, for the reason in step 7.

## Step 4: the request classes

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

`UpdateOrderRequest`, the same rules with `sometimes` and an ignore:

```php
public function rules(): array
{
    return [
        'reference' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('orders', 'reference')->ignore($this->route('order'))],
        'total' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0'],
    ];
}
```

`sometimes` is what makes the update partial. Without it every field would be required on
`PATCH`, which contradicts the endpoint's meaning.

## Step 5: the controller

Copy the shape of `ProductController`. The OpenAPI attribute on each method is what makes
the endpoint appear in Swagger UI:

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

Four rules I would not break, all copied from the existing controllers:

- The tag goes on the **class**, the operation attributes on the **methods**.
- `security: [['bearerAuth' => []]]` on anything authenticated. Omit it for a public route
  rather than writing an empty array, which is what the two public endpoints do.
- Document the status codes you actually return. 201 for create, 204 for delete, 422 for
  validation, 401 for no token, 404 for a missing row.
- Return the `Resource`, never the model. That gets you the `data` wrapper and the 201
  behaviour described in [03](03-request-lifecycle.md).

## Step 6: the route

Add it inside the authenticated group in `routes/api.php`:

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

Declare them one per line, not with `Route::apiResource`. [01](01-running-it.md) explains
why this project avoids `apiResource`, and the short version is that it would add a `PUT`
route that promises full-replacement semantics a partial-update controller does not honour.

The parameter name must match the method argument. `Route::get('/orders/{order}', ...)`
with `public function show(Order $order)` is what lets Laravel bind the row for you.

For a route that is not part of a resource, a single line is enough:

```php
Route::get('/account/summary', [AccountController::class, 'summary'])
    ->middleware('auth:sanctum');
```

## Step 7: regenerate the spec and confirm

```sh
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

Then open `http://localhost:8000/api/documentation`, reload, and look for the `Orders` tag.
Check three things:

1. The endpoint is listed under the right tag.
2. **Click Execute on the create form without editing anything.** The pre-filled body has
   to validate, otherwise you have the example-mismatch problem described in
   [06](06-auth-and-tokens.md).
3. The response shape matches what the Resource returns.

### Where the new tag appears

Swagger UI orders sections by the order tags first appear in the spec, and swagger-php
scans `app/` in filename order. The current order matches the filenames exactly:

```
spec:  Account  ->  Products  ->  Auth
files: AccountController.php,  ProductController.php,  TokenController.php
```

`TokenController` carries the `Auth` tag, which is why authentication is last even though
you need it first in the flow.

Add `OrderController.php` and the new `Orders` tag lands **between** `Account` and
`Products`, because that is where the filename sorts. If you want the section somewhere
else, the tag name is not what controls it, the filename is. Renaming the file is the only
lever, and it is a weak one, so do not fight it.

## Step 8: tests

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

Write the guest test first. It is the cheapest test in the file and it fails the moment
someone forgets `auth:sanctum` on the route.

Do not use a round number like `10.00` in an assertion. JSON encodes `10.0` as `10`, an
int, and `assertJsonPath` compares strictly, so the test fails on a formatting technicality
rather than on behaviour. Use `25.50` and expect `25.5`, or use a price like `19.95`.

## Step 9: before you commit

```sh
php artisan test
vendor/bin/pint --dirty --format agent
php artisan l5-swagger:generate
php artisan route:list --path=api --except-vendor
```

## Checklist for a new endpoint

- [ ] Controller, request classes, resource generated with `make:`
- [ ] Return types on every method
- [ ] Class has `#[OA\Tag]`, every method has an operation attribute
- [ ] `security` declared, or the route is genuinely public
- [ ] Documented status codes match what the code returns
- [ ] Route in the right middleware group, one line per verb, no `PUT`
- [ ] Route parameter name matches the method argument
- [ ] Every `OA\Property` has an `example`, and password fields have matching confirmation examples
- [ ] Guest test, create test, and partial-update test
- [ ] `l5-swagger:generate` run, and Execute works on the first click
- [ ] `php artisan test` and Pint clean
