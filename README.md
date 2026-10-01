<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

A product catalogue API with a small server-rendered admin UI, deployed to Vercel as a
single container against a hosted Postgres.

## What it does

| | |
| --- | --- |
| **API** | Token-authenticated CRUD on products, plus self-service accounts. Every operation acts on the caller's own account, so there is no route through which one user reads or rewrites another's. OpenAPI at `/api/documentation`. |
| **Web UI** | Blade and Alpine: create an account, sign in, browse and search products, edit them, manage your own account. |
| **Auth** | Two schemes on purpose. The API uses Sanctum bearer tokens, the UI uses a session cookie. A guest calling the API still gets `401` JSON rather than a redirect to the login form. |

## Project layout

The frontend and backend are separated by directory, by route file, and by controller
namespace, which is the Laravel convention. There is deliberately no `frontend/` or
`backend/` directory: Laravel has no such concept, and inventing one would break the
Artisan generators and every tutorial you read next.

**Backend**

```
app/Http/Controllers/Api/     API controllers, return JSON resources
app/Http/Controllers/Web/     page controllers, return views
app/Http/Requests/            validation, shared by both of the above
app/Http/Resources/           API response shaping
app/Models/                   Product, User
app/Actions/                  rules shared by the API and the UI
routes/api.php                the API surface
routes/web.php                the page surface
```

**Frontend**

```
resources/views/layouts/      the shared page shell
resources/views/{auth,account,products}/
resources/css/app.css         Tailwind entry point, CSS-first theme
resources/js/app.js           Alpine entry point
vite.config.js                build config
```

**Shared, deliberately**

`app/Http/Requests` and `app/Actions` exist so the two surfaces cannot drift apart. The
same `StoreProductRequest` validates a product whether it arrived as JSON or a form post,
and `UpdateAccount` applies a password change once, including revoking the account's tokens
either way. Writing those rules twice is how a security control ends up with a bug in one
copy.

`app/Actions` holds:

| Action | Owns |
| --- | --- |
| `ListProducts` | Ordering, page size, and the optional name filter |
| `UpdateAccount` | Profile changes, and revoking tokens on a password rotation |
| `DeleteAccount` | Revoking tokens, then deleting the account |

## Getting started

```sh
composer install
npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan l5-swagger:generate
npm run build
php artisan serve
```

`npm run build` is needed for the web UI, not the API: the pages render Blade that calls
`@vite`, and Alpine is bundled from `resources/js/app.js`. Use `npm run dev` for hot
reload.

Create an account at `/register` in the browser, which signs you in. The same operation is
available as an API call, and returns a token:

```sh
curl -X POST http://localhost:8000/api/account \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery","password_confirmation":"correct-horse-battery"}'
```

That token is what the Swagger UI's **Authorize** button wants. Both routes share their
validation rules and their rate limit.

## Tests

```sh
php artisan test
vendor/bin/pint
```

110 tests. The API and web suites are separate, and `ListProductsTest` asserts the two
surfaces return the same rows in the same order, which is the drift guard for the shared
action.

## Deployment

Docker and FrankenPHP via Vercel's container runtime, in `sin1` because the database is in
Singapore. See [`docs/10-deploying.md`](docs/10-deploying.md) for the environment variables
and the order to run things in. Two mistakes are documented there in detail because both
present as a blank 500: a `options=` parameter in `DB_URL`, and pasting `.env.example` into
the dashboard.

## Documentation

Written for someone who knows JavaScript and is learning Laravel, so it leads with the
parts that bite rather than with framework marketing.

| | |
| --- | --- |
| [01. Running the project](docs/01-running-it.md) | Setup, and the commands you will use daily |
| [02. PHP for JavaScript developers](docs/02-js-to-php.md) | The syntax differences that actually catch people out |
| [03. The request lifecycle](docs/03-request-lifecycle.md) | What happens between a URL and a JSON response |
| [04. Adding a field](docs/04-adding-a-field.md) | Changing a resource end to end, every file listed |
| [05. Endpoint reference](docs/05-endpoints.md) | Every route, its auth, and its body |
| [06. Auth and tokens](docs/06-auth-and-tokens.md) | The token flow, and why it is scoped tightly |
| [07. Testing](docs/07-testing.md) | Running the suite and writing new tests |
| [08. Creating an endpoint](docs/08-creating-an-endpoint.md) | From nothing to shipped, with the generated files |
| [09. Installing libraries](docs/09-adding-a-library.md) | `require` versus `require-dev`, and publishing config |
| [10. Deploying to Vercel](docs/10-deploying.md) | Container config, the database, and the silent failures |
| [11. The web UI](docs/11-the-web-ui.md) | Blade, Alpine, the two auth systems, and what the UI does not do |

## Deployment status and known gaps

Deployed and working, with these deliberate limits:

- Registration is public on both surfaces, so anyone who finds the site can create an account
  and use every endpoint. The only limit is `throttle:6,1` per IP, shared across `/register`,
  `/api/account`, and `/api/auth/token`. That is coherent for a demo and wrong for anything
  holding real data. There is no password reset, email verification, or CAPTCHA, so a
  scripted flood from one address is the only thing standing between a stranger and a
  usable account.
- There is no rate limiting on the spec route.
- No CSRF on the API, because it is token-authenticated. The web group has it.
- Production has ten seeded products and no user. The seeder's test user is gated out of
  production deliberately, because its password is the literal string `password`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe
development must be an enjoyable and creative experience to be truly fulfilling. Laravel
takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and
video tutorial library of all modern web application frameworks, making it a breeze to get
started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a
range of topics including Laravel, modern PHP, unit testing, and JavaScript.

You can also watch bite-sized lessons with real-world projects on
[Laravel Learn](https://laravel.com/learn), where you will be guided through building a
Laravel application from scratch while learning PHP fundamentals.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can
be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide
by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor
Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities
will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the
[MIT license](https://opensource.org/licenses/MIT).
