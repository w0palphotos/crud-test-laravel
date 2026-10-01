# 03. The request lifecycle

This traces a real request: `POST /api/products` with a bearer token and a JSON body. Every
file referenced here exists in this repository.

## The shortest possible version

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

If any step refuses, the rest never runs.

## Step 0: boot

`public/index.php` is the single entry point. Every HTTP request enters the application
there, whether it came from Apache, Nginx, or `php artisan serve`.

`bootstrap/app.php` is where the application is configured. Three parts of it matter here:

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

Two of those lines exist because of real bugs found while building this project:

- `redirectGuestsTo`. The `auth` middleware, when it cannot authenticate, redirects a
  browser to the `login` route. While this project was API-only there was no such route and
  the middleware threw `RouteNotFoundException`, returning **500** instead of 401. The web UI
  added the route, so the redirect now resolves and the API relies on the next line instead.
- `shouldRenderJsonWhen`. Without it, errors on an API route try to render an HTML error
  page. The `$request->is('api/*')` part means every route starting with `api/` gets JSON
  errors automatically, with no per-route work. This is what keeps a guest API call at 401
  JSON even though guests are now redirected when they visit a web page.

## Step 1: the route decides the controller

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    // ...
});
```

`[ProductController::class, 'store']` is a PHP array holding a class name string and a
method name. It looks like JavaScript's `['ProductController', 'store']` in a route table,
which is roughly the idea.

See the routes yourself at any time:

```sh
php artisan route:list --path=api --except-vendor
php artisan route:list -v --path=api          # adds the middleware column
```

## Step 2: middleware runs

`php artisan route:list -v --path=api` shows what actually runs for `POST /api/products`:

```text
POST api/products .. Api\ProductController@store
 ⇂ api
 ⇂ Illuminate\Auth\Middleware\Authenticate:sanctum
```

The `api` group in this app resolves to exactly one middleware. I confirmed it at runtime:

```php
app('router')->getMiddlewareGroups()['api'];
// ["Illuminate\Routing\Middleware\SubstituteBindings"]
```

That is worth understanding. Laravel's default `api` group is often throttled, but
`$apiLimiter` defaults to `null` here, so there is no global throttle. The only global API
throttle in this project is the explicit `throttle:6,1` on the two public endpoints.

Notice what the `api` group does **not** include, and what the `web` group does:

| Middleware | `api` | `web` |
| --- | --- | --- |
| `SubstituteBindings` | yes | yes |
| `StartSession` | no | yes |
| `PreventRequestForgery` (CSRF) | no | yes |
| `EncryptCookies` | no | yes |
| `InjectBoost` (Laravel Boost) | no | yes |

No CSRF check on the API, because there are no cookies to forge a request with. Tokens in
an `Authorization` header are not attached automatically by a browser, which is why this
project does not need CSRF protection on `api/*`.

### `auth:sanctum`

This middleware is what turns a bearer token into an authenticated user. It reads the
`Authorization` header, SHA-256 hashes the value, and looks for a matching row in
`personal_access_tokens`. If it finds one, it loads the `User` and makes that the default
guard for the rest of the request.

If it does not, the request stops here with 401 and your controller method never runs.

## Step 3: the FormRequest validates before your code runs

This is the part that surprises people coming from Express. In Express you would write the
validation inside your handler. Here, the controller signature does it for you:

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

Laravel sees the `StoreProductRequest` type hint, instantiates it, and runs `rules()`
**before** calling `store()`. If validation fails you get a 422 with a JSON body and the
controller body never executes:

```json
{
  "message": "The price field must be at least 0.",
  "errors": { "price": ["The price field must be at least 0."] }
}
```

If it passes, `$request->validated()` returns **only the keys that have rules and passed**.
That is why this controller can pass the result straight into `create()` without worrying
about extra fields in the request body.

`$request->safe()` is the version that does not throw on failure. You use it where the
request has already been validated and you just want the values.

## Step 4: the controller runs

```php
public function store(StoreProductRequest $request): ProductResource
{
    return new ProductResource(Product::create($request->validated()));
}
```

`Product::create()` is inherited from Eloquent. It builds an INSERT from the given array,
but only for columns listed in `#[Fillable]` on the model:

```php
// app/Models/Product.php
#[Fillable(['name', 'description', 'price', 'stock'])]
class Product extends Model
```

A key not in that list is dropped without an error. This is the second safety net after
validation, and it is why the fillable list has to be kept in sync with the migration and
the rules.

## Step 5: the model reads and writes

`Product::query()->latest('id')->paginate(perPage: 15)` builds a SELECT. `latest('id')`
adds `ORDER BY id DESC`. `paginate()` runs a count query as well and returns a paginator,
not a plain array.

Route model binding is what makes this work in the `show` method:

```php
public function show(Product $product): ProductResource
{
    return new ProductResource($product);
}
```

The URL says `/api/products/12` and the method asks for a `Product`. `SubstituteBindings`
loads row 12 and hands it over. If there is no row 12, Laravel returns 404 before the
method body runs, and thanks to `shouldRenderJsonWhen` that 404 is JSON. There is no
`findOrFail` call anywhere in this controller because the framework does it for you.

## Step 6: the Resource shapes the JSON

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

`parent::toArray()` is inherited from `JsonResource` and converts the model to an array,
respecting the `#[Hidden]` attributes on `User` that keep `password` out of output.

Returning a resource instead of a raw model gets you two things for free, both handled in
`Illuminate\Http\Resources\Json\ResourceResponse`:

- the payload is wrapped in a `data` key
- the status code is calculated for you

I confirmed the status logic in the framework source:

```php
protected function calculateStatus()
{
    return $this->resource->resource instanceof Model &&
           $this->resource->resource->wasRecentlyCreated ? 201 : 200;
}
```

So `store` returns **201** and `update` returns **200**, with no status code written
anywhere. The controller reads better without it, but you should know it is happening
implicitly rather than by accident.

## Step 7: the response

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

`price` is a JSON number, not a string. That is the `casts()` entry on the model doing its
job, and it matters: Laravel's `decimal:2` cast would have produced `"129.99"` as a string,
which would mean requests send a number and responses return a string.

## Where errors come from

| Situation | Who handles it | Status |
| --- | --- | --- |
| Body fails validation | FormRequest | 422 |
| No token, or bad token | `auth:sanctum` middleware | 401 |
| No such product id | Route model binding | 404 |
| Email already registered | `Rule::unique` | 422 |
| Throttled | `throttle:6,1` | 429 |

None of these are handled in a `try`/`catch` in the controllers. Laravel converts them to
JSON because of `shouldRenderJsonWhen` in `bootstrap/app.php`.

## A full example, start to finish

```sh
TOKEN=$(php artisan tinker --execute 'echo App\Models\User::first()->createToken("cli")->plainTextToken;' | tr -d '\n')

curl -s -X POST http://127.0.0.1:8000/api/products \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"name":"Mechanical Keyboard","price":129.99,"stock":42}'
```

Read that request left to right and you have just traced the whole lifecycle above.
