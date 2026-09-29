# 06. Auth and tokens

## What the token actually is

A Sanctum personal access token. When you call `POST /api/auth/token` the server creates a
row in `personal_access_tokens` and returns a string shaped like this:

```
3|Ip7Xq7KgAf3nRt8sXq2mB7wLpZ0dYcVnE4hGjKfTu
└┬┘ └──────────────────┬──────────────────┘
 │                    │
 id              random secret
```

Both halves are needed. Paste the whole string, including the `3|`.

## The database never stores the token

This is the part that surprises people, and it matters if you are tempted to put a token in
a config file.

I read the live table from `database/database.sqlite`:

```
id=2  user_id=1  name=swagger-ui  token col = 64 hex chars, starts b83b171b2afc595e...
id=5  user_id=1  name=cli         token col = 64 hex chars, starts 8085a0775808ae81...
```

64 hex characters is a SHA-256 hash. The plaintext exists only in the one HTTP response that
created it. Two places in the Sanctum source confirm it:

```php
// vendor/laravel/sanctum/src/HasApiTokens.php:84
$tokenEntropy = Str::random(40),
```

```php
// vendor/laravel/sanctum/src/PersonalAccessToken.php:58
public static function findToken($token)
{
    if (strpos($token, '|') === false) {
        return static::where('token', hash('sha256', $token))->first();
    }

    [$id, $token] = explode('|', $token, 2);

    if ($instance = static::find($id)) {
        return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
    }
}
```

So `findToken` hashes what you send and looks that up. Two consequences:

- If you lose a token you cannot recover it. Issue a new one.
- A fixed token in `.env` is not possible. The plaintext is random at creation, so a
  hardcoded value would have to be one past creation that you wrote down. It is also a live
  user credential, which is a bad thing to keep in a plaintext file that gets copied around.

## Getting a token

**From the browser.** Expand `POST /api/auth/token`, **Try it out**, **Execute**, copy
`token` from the response, then **Authorize** at the top right.

**From the shell.**

```sh
php artisan tinker --execute 'echo App\Models\User::first()->createToken("cli")->plainTextToken;'
```

**By registering a new account**, which returns a token in the same response:

```sh
curl -s -X POST http://127.0.0.1:8000/api/account \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery","password_confirmation":"correct-horse-battery"}'
```

## Tokens do not expire

```php
// config/sanctum.php:53
'expiration' => null,
```

`null` means never. A token minted today still works next month, until it is revoked or the
account is deleted.

## Not re-typing the token

Swagger UI keeps the token in browser storage, so you paste it once per browser. The switch
is already on:

```php
// config/l5-swagger.php:320
'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', true),
```

The published view feeds that straight into `SwaggerUIBundle`, so the value is live in the
page. I confirmed it by serving the app and grepping the HTML for
`persistAuthorization: "true"`.

Two limits. Browser storage is keyed by origin, so `127.0.0.1:8000` and `127.0.0.1:8001`
keep separate tokens and you re-paste once per port. And any JavaScript on the origin can
read it, which is fine for `127.0.0.1` and is not a pattern to copy to production.

To turn it off without editing the config, set `L5_SWAGGER_UI_PERSIST_AUTHORIZATION=false`
in `.env`.

## Why there is no `/api/users`

This is the design decision worth understanding, because the obvious CRUD version of account
management is a security hole.

The obvious endpoints would be:

```
GET    /api/users          list every account
GET    /api/users/{id}     read any account
PATCH  /api/users/{id}     change any account
DELETE /api/users/{id}     delete any account
```

`users` has no role column in this project, and `auth:sanctum` only proves a token is
valid, not that it is an administrator. So with those four routes, anyone could:

1. call the public `POST /api/account` to register
2. receive a token in the response, no login needed
3. call `PATCH /api/users/1` with `{"password": "..."}`
4. take over the account your test harness logs in as

That is not a theoretical concern, it is one endpoint away. The fix would be a role column
plus a check, and the token alone would not be enough to tell an admin from anyone else.

What this project does instead: all four account endpoints act on the caller and nothing
else. The isolation is structural rather than a check that could be forgotten. I confirmed
it by calling the paths that would be needed and watching them 404:

```
/api/users         -> 404
/api/user          -> 404
/api/accounts      -> 404
/api/account/1     -> 404
```

If you later need admin user management, that is a real feature and it needs a role
column, an authorization check, and tests for the boundary. Do not add it by accident.

## Password changes require the current password

```php
// app/Http/Requests/UpdateAccountRequest.php
'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
```

Without this, anyone holding a leaked token could set a new password and lock the real owner
out permanently. Requiring the current password means a stolen token alone is not enough.

The `Rule::when` is doing real work. The first version used
`['required_with:password', 'current_password']`, and that was a bug: `required_with` only
controls whether the field must be **present**, so a `current_password` sent alongside an
unrelated name change was still checked and failed with 422. `Rule::when` applies no rules
at all when `password` is absent, so the field is skipped.

That is verified from the framework, not assumed. `ValidationRuleParser::filterConditionalRules`
resolves a false condition with no else-branch to an empty rule set:

```php
return [$attribute => $attributeRules->passes($data)
    ? array_filter($attributeRules->rules($data))
    : array_filter($attributeRules->defaultRules($data)), ];
```

I also confirmed the tests catch it. Reverting the one line makes both new tests fail with
`422 "The password is incorrect"`, so the fix is pinned rather than incidental.

## Rotating the password revokes other tokens

```php
private function revokeOtherTokens(Request $request, User $user): void
{
    $currentTokenId = $request->user()->currentAccessToken()?->getKey();

    $user->tokens()
        ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
        ->delete();
}
```

If you rotate your password because you think a token leaked, every other token has to die
with it. Otherwise the attacker's token keeps working, which defeats the point of rotating.

The caller's own token is excluded with `whereKeyNot` so you are not logged out of the
session you just used. Verified over HTTP:

```
device A after rotation -> 200   (the caller keeps working)
device B after rotation -> 401   (revoked)
```

## Deleting an account does not orphan tokens

`personal_access_tokens` is created with `morphs('tokenable')`, which adds no
`ON DELETE CASCADE`, and Sanctum registers no `deleting` hook. I checked both:

```php
// database/migrations/..._create_personal_access_tokens_table.php
$table->morphs('tokenable');
```

So a plain `$user->delete()` would leave live token rows behind. The controller removes them
first:

```php
public function destroy(Request $request): Response
{
    $user = $request->user();

    // personal_access_tokens has no ON DELETE CASCADE and Sanctum registers
    // no deleting hook, so the rows are removed explicitly.
    $user->tokens()->delete();
    $user->delete();

    return response()->noContent();
}
```

A test asserts zero rows remain afterwards, and a second one asserts the token no longer
authenticates. That second test needed `$this->app->make('auth')->forgetGuards()` between
the two requests, because a test shares one application instance and the guard would reuse
the user it already resolved instead of re-checking the token. Without that line the test
passes for the wrong reason, which is worse than a failing test.

## Why guests get 401 and not a redirect

```php
// bootstrap/app.php
$middleware->redirectGuestsTo(null);
```

Laravel's `auth` middleware, when it cannot authenticate, tries to redirect a browser to a
`login` route. This app has no web pages and no `login` route, so that produced:

```
{"message":"Route [login] not defined."}
```

with a **500**, not a 401. Returning `null` from `redirectGuestsTo` makes the middleware
throw a plain `AuthenticationException` instead, and the 401 comes out as JSON because of
the next line:

```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
);
```

The tests did not catch this, because `getJson()` sends `Accept: application/json`, which
made `expectsJson()` true and skipped the redirect path. I found it with a plain `curl`
that sent no `Accept` header. Worth remembering: test with `curl` as well as the suite.

## A test that will trick you

In a Laravel feature test, several HTTP calls in one method share one application instance,
and `AuthManager` caches resolved guards:

```php
// vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php:41
protected $guards = [];
```

So if the first request authenticated, the second can reuse that user without re-reading
the token. A test asserting that a revoked token now fails will pass incorrectly. Add:

```php
$this->app->make('auth')->forgetGuards();
```

between the requests whenever the point of the test is that authentication changed.
