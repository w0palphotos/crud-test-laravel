# 11. The web UI

The API was built first and the UI came after it, so the UI is a thin server-rendered
layer over the same models. There is no second data path: the web controllers use the same
`Product` model and the same `FormRequest` classes the API does, and the same
`App\Actions\UpdateAccount` handles a password change for both.

## Why Blade and not a SPA

The whole surface is a product list, a product form and an account page. Blade gives
server rendering, real `<form>` posts, CSRF and validation errors without a second build
pipeline or a client-side auth scheme. Alpine covers the only real interactivity, which is
the two confirmation dialogs.

If this grows into something that needs optimistic updates and client-side routing, Inertia
is the next step, and migrating from Blade to Inertia is much easier than migrating between
two SPA frameworks.

## What is installed

| Piece | Role |
| --- | --- |
| Vite 8, `laravel-vite-plugin` | Bundles CSS and JS, resolves `@vite` |
| Tailwind 4 | Utility classes, via the `@tailwindcss/vite` plugin. No config file, the theme is CSS-first in `app.css` |
| Bunny fonts | `@fonts` resolves to locally bundled Instrument Sans |
| Alpine 3 | The only JavaScript, for confirmation dialogs |

Alpine is a runtime `dependency`, not a dev one, because the built bundle contains it.

## Routes

| Method | Path | Name | Auth |
| --- | --- | --- | --- |
| GET | `/` | | redirects to `/products` |
| GET | `/login` | `login` | guest |
| POST | `/login` | | guest |
| GET | `/register` | `register` | guest |
| POST | `/register` | | guest, throttled |
| POST | `/logout` | `logout` | session |
| GET | `/products` | `products.index` | session |
| GET | `/products/create` | `products.create` | session |
| POST | `/products` | `products.store` | session |
| GET | `/products/{product}/edit` | `products.edit` | session |
| PATCH | `/products/{product}` | `products.update` | session |
| DELETE | `/products/{product}` | `products.destroy` | session |
| GET | `/account` | `account.show` | session |
| PATCH | `/account` | `account.update` | session |
| DELETE | `/account` | `account.destroy` | session |

The API route names are prefixed `api.`, for example `api.products.index`. They used to be
`products.index` and collided with the web names, and because the API routes were registered
first, `route('products.index')` silently resolved to `/api/products` and every link in the
UI pointed at the API. The prefix removes the class of problem rather than working around it.

## Two authentication systems, on purpose

| Surface | Guard | Storage |
| --- | --- | --- |
| Web pages | `auth`, the session guard | an encrypted session cookie, rows in `sessions` |
| `api/*` | `auth:sanctum` | a bearer token in the `Authorization` header |

A guest calling the API still gets a `401` with a JSON body, not a redirect to the HTML
login form. That is `shouldRenderJsonWhen` in `bootstrap/app.php` matching `api/*`, and
`WebAuthTest` asserts it so a future change to the redirect target cannot break it silently.

The `api` group does not include `EnsureFrontendRequestsAreStateful`, so no cookie
authentication leaks into the API surface.

## Registration

`GET /register` is a form for the same operation as `POST /api/account`: name, email,
password, confirmation. It creates the account and signs it in.

The two share `RegisterAccountRequest`, so the rules cannot drift, and the web route carries
the same `throttle:6,1` as the API one. Since that limiter keys on the IP for guests, and
every registration request is a guest, both doors draw on one budget per address.

It is the widest door in the deployment either way. A form does not widen the exposure that
`POST /api/account` already had, it just makes it easier to walk through.

Three details worth knowing if you change it:

- The password is hashed by the `hashed` cast on `User`, not by the controller.
- `session()->regenerate()` runs after `Auth::login()`. Registering is a privilege change,
  so it needs a fresh session id for the same reason signing in does.
- Both registration routes sit in the `guest` group, so a signed-in visitor posting to
  `/register` is redirected to the product list instead of creating a second account.

## Shared logic worth knowing about

`App\Actions\UpdateAccount` is used by both the API controller and the web controller. It
fills the validated fields, and if the password was rotated it revokes the account's tokens,
keeping the caller's own when there is one.

Under the session guard there is no current token, so a password change from the UI revokes
**every** token on the account. That is the stricter and correct outcome, and
`WebAccountTest::test_changing_the_password_revokes_every_api_token` pins it.

Verifying `current_password` lives in `UpdateAccountRequest` as a conditional rule:

```php
'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
```

so it is required exactly when a password is present and ignored otherwise. The form has
separate "details" and "password" sections posting to the same endpoint, which is why a
details-only save does not demand a password.

## Building and viewing it

```sh
npm install
npm run build     # or npm run dev for hot reload
php artisan serve
```

Sign in at `/login`, or create an account at `/register`. The UI needs the build; the API
does not, because it never renders a view.

## Tests

| File | Covers |
| --- | --- |
| `tests/Feature/Web/WebAuthTest.php` | Login, logout, guest redirects, session regeneration on login, and the API still answering 401 |
| `tests/Feature/Web/WebRegisterTest.php` | Account creation, the password being hashed, the guest redirect on the form, throttling across separate sessions, and session regeneration |
| `tests/Feature/Web/WebProductTest.php` | Listing, search, pagination, create, update, delete, and that the API validation rules are reused |
| `tests/Feature/Web/WebAccountTest.php` | Detail updates, password rotation requiring the current one, token revocation, and account deletion |
