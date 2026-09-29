# 05. Endpoint reference

Every route in `routes/api.php`, verified against the generated spec. The base URL in
these examples is `http://127.0.0.1:8000`.

| Method | Path | Auth | Request body | Responses |
| --- | --- | --- | --- | --- |
| POST | `/api/auth/token` | public | yes | 200, 422 |
| DELETE | `/api/auth/token` | bearer | no | 204, 401 |
| POST | `/api/account` | public | yes | 201, 422 |
| GET | `/api/account` | bearer | no | 200, 401 |
| PATCH | `/api/account` | bearer | yes | 200, 401, 422 |
| DELETE | `/api/account` | bearer | no | 204, 401 |
| GET | `/api/products` | bearer | no | 200, 401 |
| POST | `/api/products` | bearer | yes | 201, 401, 422 |
| GET | `/api/products/{product}` | bearer | no | 200, 401, 404 |
| PATCH | `/api/products/{product}` | bearer | yes | 200, 401, 404, 422 |
| DELETE | `/api/products/{product}` | bearer | no | 204, 401, 404 |

"bearer" means `Authorization: Bearer <token>` is required. See
[06. Auth and tokens](06-auth-and-tokens.md).

There is no `PUT` route anywhere. Updates are partial, so `PATCH` is the only verb that
describes what the controller does.

## Auth

### POST /api/auth/token

Public. Limited to 6 requests per minute.

Request:

```json
{
  "email": "test@example.com",
  "password": "password"
}
```

Response, 200:

```json
{
  "token": "3|Ip7Xq7KgAf3nRt8s...",
  "user": { "id": 1, "name": "Test User", "email": "test@example.com" }
}
```

422 means the credentials did not match. The error is on the `email` key even when the
password was the wrong part, so the response does not reveal whether an address is
registered.

### DELETE /api/auth/token

Revokes the token used to make the call. The account and other tokens are untouched.

## Account

All four act on the authenticated account. There is no endpoint that reads or writes a
different account, so there is no path to another user's data.

### POST /api/account

Public, registers an account. Limited to 6 requests per minute.

Request:

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "correct-horse-battery",
  "password_confirmation": "correct-horse-battery"
}
```

Response, 201:

```json
{
  "data": {
    "id": 2,
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "email_verified_at": null,
    "created_at": "2026-09-28T14:20:00.000000Z",
    "updated_at": "2026-09-28T14:20:00.000000Z"
  },
  "token": "4|wun..."
}
```

It returns a token, so you can register and start calling authenticated endpoints without
logging in separately. That makes it the quickest way to get a fresh token.

422 cases: email already taken, `password_confirmation` does not match, password shorter
than 8 characters.

### GET /api/account

The authenticated account. Response, 200:

```json
{
  "data": {
    "id": 1,
    "name": "Test User",
    "email": "test@example.com",
    "email_verified_at": null,
    "created_at": "2026-09-28T13:20:00.000000Z",
    "updated_at": "2026-09-28T13:20:00.000000Z"
  }
}
```

`password` and `remember_token` are never present. That is the `#[Hidden]` attribute on
the `User` model doing its job, and there is a test asserting the password never appears
in any response body.

### PATCH /api/account

Partial. Anything you leave out keeps its current value.

Change the email only:

```json
{ "email": "ada@newdomain.test" }
```

Change the password, which needs the current one:

```json
{
  "current_password": "correct-horse-battery",
  "password": "brand-new-password",
  "password_confirmation": "brand-new-password"
}
```

Rules:

- `current_password` is required whenever `password` is present, and must match. A wrong or
  missing one gives 422 with the error on `current_password`.
- `current_password` is ignored entirely when `password` is absent, so sending it alongside
  a name change is harmless.
- Rotating the password revokes every other token for that account. The token you called
  with keeps working, so you are not logged out of your own session.
- A duplicate email gives 422 on `email`.

### DELETE /api/account

Response 204 with no body. The account and all of its tokens are removed.

## Products

### GET /api/products

Query parameters:

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `per_page` | integer | 15 | Capped at 100. A larger value is clamped down, not rejected |
| `page` | integer | 1 | |

```sh
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  'http://127.0.0.1:8000/api/products?per_page=2&page=1'
```

Response, 200:

```json
{
  "data": [ { "id": 12, "name": "...", "price": 9.5, "stock": 3, "...": "..." } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 2, "to": 2, "total": 10 }
}
```

The `data`, `links`, `meta` wrapper is Laravel's standard paginator output. `prev` is null
on page 1 and `next` is null on the last page.

### POST /api/products

```json
{
  "name": "Mechanical Keyboard",
  "description": "Hot-swappable 75% board.",
  "price": 129.99,
  "stock": 42
}
```

| Field | Rules |
| --- | --- |
| `name` | required, string, max 255 |
| `description` | nullable, string, max 1000 |
| `price` | required, numeric, 0 to 2 decimal places, min 0 |
| `stock` | required, integer, min 0 |

`price` accepts `9` and `9.5`, rejects `9.555` and negative values. The database column is
`decimal(10,2)`, so more than two decimal places cannot be stored anyway. The rule exists
to reject it with a useful message instead of silently rounding.

Response 201 with the created product under `data`.

### GET /api/products/{product}

```sh
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/products/12
```

404 if no product has that id.

### PATCH /api/products/{product}

Partial, same rules as create but every field optional.

```json
{ "stock": 17 }
```

Omitted fields keep their current value. Sending `{ "stock": 17 }` leaves `name` and
`price` alone, which is the behaviour the test
`test_it_updates_only_the_supplied_fields` pins down.

### DELETE /api/products/{product}

Response 204, no body.

## Testing with curl

```sh
BASE=http://127.0.0.1:8000

TOKEN=$(curl -s -X POST $BASE/api/auth/token \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"test@example.com","password":"password"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')

A=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json')

curl -s "${A[@]}" $BASE/api/products
curl -s "${A[@]}" -X POST -d '{"name":"Widget","price":9.5,"stock":3}' $BASE/api/products
curl -s "${A[@]}" -X PATCH -d '{"stock":99}' $BASE/api/products/12
curl -s "${A[@]}" -X DELETE $BASE/api/products/12
```

Add `-i` to any of those to see the status code.

## Status codes used here

| Code | Meaning in this API |
| --- | --- |
| 200 | Read or update succeeded |
| 201 | Created. `POST /api/products` and `POST /api/account` return it |
| 204 | Deleted or revoked. No response body |
| 401 | No token, or the token is invalid or revoked |
| 404 | No product with that id |
| 422 | Body failed validation. The JSON body lists every failing field |
| 429 | More than 6 requests per minute to a public endpoint |
