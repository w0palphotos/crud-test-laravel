# 02. PHP for JavaScript developers

Everything here is PHP 8.5, which is what this project runs. Code samples are real.

## Variables have a dollar sign

```php
$name = 'Mechanical Keyboard';
$stock = 42;
$price = 129.99;
$description = null;
```

In JavaScript you write `let name = ...`. In PHP the type prefix is gone and you add `$`.
That is the only difference. PHP is not typed by default, the same way JS is not, but
Laravel adds types where it matters and you should too:

```php
private function perPage(Request $request): int
{
    return min(max($request->integer('per_page', 15), 1), 100);
}
```

`$request` is a `Request`, and the return is an `int`. This project has a rule for it:
always type the parameters and the return value.

## There is one array type, and it does two jobs

This is the biggest mental shift. PHP has no separate object type for a key/value
collection. One `array` covers both what JS calls an Array and what JS calls an Object.

```php
$fruits = ['apple', 'banana'];              // a list, like a JS array
$product = ['name' => 'Keyboard', 'stock' => 5];   // a map, like a JS object
```

Rules that follow from this:

```php
$product['name']        // 'Keyboard'
$product['missing']     // null, plus a PHP warning. Use ?? to avoid the warning
$product['missing'] ?? 'fallback'
$fruits[0]              // 'apple'
```

You cannot mix `=>` and bare values in the same array literal. PHP will parse it but you
will get strange results, so do not.

## Strict comparison is the default you want

```php
if ($user->email === $input) { }
```

`===` compares type and value. The loose `==` operator exists and will surprise you,
because PHP casts between types. Use `===` and stop thinking about it.

## null, and the absence of undefined

JavaScript has `null` and `undefined`. PHP has `null`, and reading a key that was never
set gives you `null` with a warning. There is no `undefined`.

```php
$user = null;
$user->name          // fatal error, the object is null
$user?->name         // null, short circuits
$user['name'] ?? null  // map access with a fallback
```

The `?->` operator is PHP 8.0. Use it instead of optional chaining noise, though you can
write `$user?->name` which reads exactly like JS.

## Truthiness is different, and it will bite you

I ran this in this project. PHP 8.5.10, actual output:

```php
if (0) {}       // FALSY
if ("0") {}     // FALSY   <-- this one surprises people
if (0.0) {}     // FALSY
if ("") {}      // FALSY
if ([]) {}      // FALSY   <-- empty array, not truthy like JS
if ("abc") {}   // truthy
if (null) {}    // FALSY
```

The two that catch JavaScript developers:

- `"0"` is **falsy** in PHP and **truthy** in JavaScript. A string of zero breaks a lot of
  copied logic.
- `[]` is **falsy** in PHP. In JavaScript an empty array is truthy. So
  `if ($results) { }` means "if there is at least one row" in PHP, which is the opposite
  of what a JS instinct predicts.

Never write a bare truthiness check on a value whose type you have not looked at. Compare
explicitly: `if ($results !== []) {`, or better, use the count.

## Functions: `function` and `fn`

The long form, used for anything named:

```php
public function toArray(Request $request): array
{
    return parent::toArray($request);
}
```

The short arrow form, used for small inline callbacks:

```php
$query = $request->integer('per_page', 15);
```

Closures capture variables from the surrounding scope automatically, the same as JS arrow
functions. You will see them passed to Eloquent:

```php
User::where('email', $credentials['email'])->first();
```

## Named arguments

PHP lets you pass arguments by name. This project uses it where a function has several
options:

```php
// app/Http/Controllers/Api/ProductController.php:46
Product::query()->latest('id')->paginate(perPage: $this->perPage($request));
```

This is closer to passing an options object in JS. It is safe as long as you do not rename
a parameter, which would break every named call.

## Classes, and the thing Laravel expects

A model is a class. Properties are declared with visibility, and you almost never see a
constructor because Eloquent handles the loading:

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

Points worth noticing:

- `namespace App\Models;` is the PHP equivalent of an import path. The file lives at
  `app/Models/Product.php` and the namespace mirrors the directory.
- `extends Model` inherits all the database behaviour. `Product::query()`,
  `Product::create()`, `$product->save()` are all inherited, not written anywhere in this
  file.
- `#[Fillable([...])]` is a PHP 8 attribute. It tells Eloquent which fields may be set
  from a request body. Anything not listed is silently ignored on mass assignment. You
  confirmed this is real: `Illuminate\Database\Eloquent\Attributes\Fillable` exists in
  this install.
- `casts()` converts values on the way in and out of the database. `price` is a
  `decimal(10,2)` column but comes back as a JSON number rather than a string, so requests
  and responses agree on the type.

## Traits

`use HasFactory;` inside the class body pulls in methods from a trait. A trait is a way to
share code between classes that are not related by inheritance. `HasFactory` is what gives
you `Product::factory()` and `Product::factory(10)->create()`.

This is not a JavaScript concept. The nearest equivalent is copying methods into a class
from a mixin, except PHP does it properly with a declared contract.

## Constructor property promotion

You will see this in framework code:

```php
public function __construct(public GitHub $github) { }
```

`public GitHub $github` declares the property and assigns it in one step. This project does
not use it, because models get constructed by Eloquent rather than by you.

## Interfaces and type hints

```php
public function register(RegisterAccountRequest $request): JsonResponse
public function destroy(Request $request): Response
```

The type before the parameter name is a hint. PHP will throw a `TypeError` if you pass
something else, which means a large class of JavaScript bug does not exist here. The type
after the colon is the return type.

## Strings

Double quotes interpolate. Single quotes do not.

```php
"Hello, $name"              // interpolates
'Hello, $name'              // literal
"Total: {$order->total}"    // property access inside a string
"Line\nbreak"               // escapes work in double quotes
```

Laravel helper functions you will use more than native string methods:

```php
str_contains($haystack, $needle)    // JS: haystack.includes(needle)
str_starts_with($haystack, $needle)
substr($s, 0, 10)
```

## Collections versus arrays

Eloquent returns a `Collection`, which is an array with methods on it. It is the closest
thing Laravel has to a JS array utility library, and it is already loaded.

```php
$products = Product::all();
$products->count();
$products->map(fn ($p) => $p->name);
$products->filter(fn ($p) => $p->stock > 0);
$products->pluck('name');
```

`pluck` and `map` are the ones you will reach for most. They replace
`products.map(p => p.name)` loops.

## Where the fields actually live

You asked how to write a variable on this project. The direct answer: a "variable" in an
API is a column, and adding one touches five places, not one.

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

Miss step 2 and the field is rejected silently, which is the confusing one. Miss step 3
and the API accepts values the database cannot hold. Miss step 4 and the field is missing
from every response and the Swagger UI body.

[04. Adding a field](04-adding-a-field.md) walks through all five with real commands.

## Things that will not translate

| JavaScript | PHP in this project |
| --- | --- |
| `module.exports` / `export` | `namespace` at the top of the file, plus `use` to import |
| `import x from './x'` | `use App\Models\Product;` |
| `require('x')` | not used, Composer autoloads from `composer.json` |
| `undefined` | does not exist. Missing values are `null` |
| `===` vs `==` | use `===`. PHP also has `==` and it is loose like JS |
| `[]` is truthy | `[]` is falsy |
| `"0"` is truthy | `"0"` is falsy |
| `Array.isArray()` | not needed, there is one array type |
| Prototype methods | traits, or `extends` |
| `JSON.parse(JSON.stringify(x))` | `$resource->toArray($request)` for models, `json_encode` for anything |
| `try { } catch { }` | `try { } catch (Throwable $e) { }`, and the class is named |
| `async` / `await` | not used in request handling. Queues handle background work |
