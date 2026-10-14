# 05. Referensi endpoint

Setiap route di `routes/api.php`, terverifikasi terhadap spec yang digenerate. Base URL dalam
contoh-contoh ini adalah `http://127.0.0.1:8000`.

| Metode | Path | Auth | Body request | Respons |
| --- | --- | --- | --- | --- |
| POST | `/api/auth/token` | publik | ya | 200, 422 |
| DELETE | `/api/auth/token` | bearer | tidak | 204, 401 |
| POST | `/api/account` | publik | ya | 201, 422 |
| GET | `/api/account` | bearer | tidak | 200, 401 |
| PATCH | `/api/account` | bearer | ya | 200, 401, 422 |
| DELETE | `/api/account` | bearer | tidak | 204, 401 |
| GET | `/api/products` | bearer, admin | tidak | 200, 401, 403 |
| POST | `/api/products` | bearer, admin | ya | 201, 401, 403, 422 |
| GET | `/api/products/{product}` | bearer, admin | tidak | 200, 401, 403, 404 |
| PATCH | `/api/products/{product}` | bearer, admin | ya | 200, 401, 403, 404, 422 |
| DELETE | `/api/products/{product}` | bearer, admin | tidak | 204, 401, 403, 404 |

"bearer" berarti `Authorization: Bearer <token>` wajib ada. Lihat
[06. Autentikasi dan token](06-auth-and-tokens.md).

Tidak ada route `PUT` di mana pun. Update bersifat parsial, sehingga `PATCH` adalah satu-satunya verb yang
menggambarkan apa yang dilakukan controller.

## Auth

### POST /api/auth/token

Publik. Dibatasi 6 request per menit.

Request:

```json
{
  "email": "test@example.com",
  "password": "password"
}
```

Respons, 200:

```json
{
  "token": "3|Ip7Xq7KgAf3nRt8s...",
  "user": { "id": 1, "name": "Test User", "email": "test@example.com" }
}
```

422 berarti kredensial tidak cocok. Error ada pada kunci `email` bahkan ketika
kata sandi yang salah, sehingga respons tidak mengungkapkan apakah suatu alamat
terdaftar.

### DELETE /api/auth/token

Mencabut token yang dipakai untuk melakukan panggilan. Akun dan token lain tidak tersentuh.

## Akun

Keempatnya bekerja pada akun yang terautentikasi. Tidak ada endpoint yang membaca atau menulis
akun lain, sehingga tidak ada jalur ke data pengguna lain.

### POST /api/account

Publik, mendaftarkan akun. Dibatasi 6 request per menit.

Request:

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "correct-horse-battery9",
  "password_confirmation": "correct-horse-battery9"
}
```

Respons, 201:

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

Endpoint ini mengembalikan token, sehingga Anda dapat Daftar dan langsung memanggil endpoint terautentikasi tanpa
Masuk terpisah. Itu menjadikannya cara tercepat untuk mendapatkan token baru.

Kasus 422: email sudah dipakai (dibandingkan dalam bentuk folded, sehingga varian huruf besar-kecil dari alamat yang sudah dipakai
tetap terhitung), `password_confirmation` tidak cocok, atau kata sandi yang lebih pendek dari
12 karakter, tidak memiliki huruf atau tidak memiliki angka, atau muncul dalam data breach yang diketahui. Email
dipangkas dan di-lowercase sebelum semua itu, dan disimpan seperti itu.

### GET /api/account

Akun yang terautentikasi. Respons, 200:

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

`password` dan `remember_token` tidak pernah ada. Itulah atribut `#[Hidden]` pada
model `User` yang menjalankan tugasnya, dan ada test yang menegaskan kata sandi tidak pernah muncul
di body respons mana pun.

### PATCH /api/account

Parsial. Apa pun yang Anda lewatkan mempertahankan nilainya saat ini.

Hanya ubah email:

```json
{ "email": "ada@newdomain.test" }
```

Ubah kata sandi, yang memerlukan kata sandi saat ini:

```json
{
  "current_password": "correct-horse-battery9",
  "password": "brand-new-password9",
  "password_confirmation": "brand-new-password9"
}
```

Aturan:

- `current_password` wajib ada setiap kali `password` ada, dan harus cocok. Nilai yang salah atau
  hilang menghasilkan 422 dengan error pada `current_password`.
- `current_password` diabaikan sepenuhnya ketika `password` tidak ada, sehingga mengirimnya bersama
  perubahan nama tidak berbahaya.
- Merotasi kata sandi mencabut setiap token lain untuk akun itu. Token yang Anda pakai untuk memanggil
  tetap berfungsi, sehingga Anda tidak Keluar dari sesi Anda sendiri.
- Email duplikat menghasilkan 422 pada `email`.

### DELETE /api/account

Respons 204 tanpa body. Akun dan seluruh token-nya dihapus.

## Produk

### GET /api/products

Parameter query:

| Parameter | Tipe | Default | Catatan |
| --- | --- | --- | --- |
| `per_page` | integer | 15 | Dibatasi maksimal 100. Nilai yang lebih besar dijepit ke bawah, bukan ditolak |
| `page` | integer | 1 | |

```sh
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  'http://127.0.0.1:8000/api/products?per_page=2&page=1'
```

Respons, 200:

```json
{
  "data": [ { "id": 12, "name": "...", "price": 9.5, "stock": 3, "...": "..." } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 2, "to": 2, "total": 10 }
}
```

Pembungkus `data`, `links`, `meta` adalah output paginator standar Laravel. `prev` bernilai null
pada halaman 1 dan `next` bernilai null pada halaman terakhir.

### POST /api/products

```json
{
  "name": "Mechanical Keyboard",
  "description": "Hot-swappable 75% board.",
  "price": 129.99,
  "stock": 42
}
```

| Field | Rule |
| --- | --- |
| `name` | wajib, string, maks 255 |
| `description` | boleh null, string, maks 1000 |
| `price` | wajib, numerik, 0 sampai 2 angka desimal, min 0 |
| `stock` | wajib, integer, min 0 |

`price` menerima `9` dan `9.5`, menolak `9.555` dan nilai negatif. Kolom database adalah
`decimal(14,2)`, sehingga lebih dari dua angka desimal memang tidak bisa disimpan. Rule ada
untuk menolaknya dengan pesan yang berguna alih-alih membulatkan diam-diam.

Respons 201 dengan produk yang dibuat di bawah `data`.

### GET /api/products/{product}

```sh
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/products/12
```

404 jika tidak ada produk dengan id itu.

### PATCH /api/products/{product}

Parsial, rule sama seperti saat pembuatan tetapi setiap field opsional.

```json
{ "stock": 17 }
```

Field yang dihilangkan mempertahankan nilainya saat ini. Mengirim `{ "stock": 17 }` membiarkan `name` dan
`price` tidak berubah, dan perilaku inilah yang dikunci oleh test
`test_it_updates_only_the_supplied_fields`.

### DELETE /api/products/{product}

Respons 204, tanpa body.

## Pengujian dengan curl

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

Tambahkan `-i` pada salah satunya untuk melihat status code.

## Status code yang dipakai di sini

| Kode | Arti di API ini |
| --- | --- |
| 200 | Baca atau update berhasil |
| 201 | Dibuat. `POST /api/products` dan `POST /api/account` mengembalikannya |
| 204 | Dihapus atau dicabut. Tanpa body respons |
| 401 | Tanpa token, atau token tidak valid atau sudah dicabut |
| 404 | Tidak ada produk dengan id itu |
| 422 | Body gagal divalidasi. Body JSON mencantumkan setiap field yang gagal |
| 429 | Lebih dari 6 request per menit ke endpoint publik |
