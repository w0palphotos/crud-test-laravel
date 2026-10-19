# 05. Referensi endpoint

Setiap route di `routes/api.php`, terverifikasi terhadap spec yang digenerate. Base URL dalam
contoh-contoh ini adalah `http://127.0.0.1:8000`.

| Metode | Path | Auth | Body request | Respons |
| --- | --- | --- | --- | --- |
| POST | `/api/auth/token` | publik | ya | 200, 422 |
| DELETE | `/api/auth/token` | bearer | tidak | 204, 401 |
| POST | `/api/account` | bearer, admin | ya | 201, 401, 403, 422 |
| GET | `/api/account` | bearer | tidak | 200, 401 |
| PATCH | `/api/account` | bearer | ya | 200, 401, 422 |
| DELETE | `/api/account` | bearer | tidak | 204, 401 |
| GET | `/api/products` | bearer, admin | tidak | 200, 401, 403 |
| POST | `/api/products` | bearer, admin | ya | 201, 401, 403, 422 |
| GET | `/api/products/{product}` | bearer, admin | tidak | 200, 401, 403, 404 |
| PATCH | `/api/products/{product}` | bearer, admin | ya | 200, 401, 403, 404, 422 |
| DELETE | `/api/products/{product}` | bearer, admin | tidak | 204, 401, 403, 404 |
| GET | `/api/customers` | bearer, admin | tidak | 200, 401, 403 |
| POST | `/api/customers` | bearer, admin | ya | 201, 401, 403, 422 |
| GET | `/api/customers/{customer}` | bearer, admin | tidak | 200, 401, 403, 404 |
| PATCH | `/api/customers/{customer}` | bearer, admin | ya | 200, 401, 403, 404, 422 |
| PATCH | `/api/customers/{customer}/profile` | bearer, admin | ya | 200, 401, 403, 404, 422 |
| DELETE | `/api/customers/{customer}` | bearer, admin | tidak | 204, 401, 403, 404, 422 |
| GET | `/api/suppliers` | bearer, admin | tidak | 200, 401, 403 |
| POST | `/api/suppliers` | bearer, admin | ya | 201, 401, 403, 422 |
| GET | `/api/suppliers/{supplier}` | bearer, admin | tidak | 200, 401, 403, 404 |
| PATCH | `/api/suppliers/{supplier}` | bearer, admin | ya | 200, 401, 403, 404, 422 |
| DELETE | `/api/suppliers/{supplier}` | bearer, admin | tidak | 204, 401, 403, 404, 422 |
| POST | `/api/uploads` | bearer, admin | ya | 201, 401, 403, 422, 429 |

`POST /api/uploads` menerima satu gambar pada field `image` (JPG, PNG, atau
WebP, maksimal 2 MB) dan menjawab `{path, url, name}`. `path` inilah yang dikirim
sebagai `images[].path` saat membuat atau mengubah produk — galeri wajib ada
minimal satu gambar, jadi tanpa endpoint ini tidak ada cara memenuhi aturan itu
dari sini. Dibatasi 30 permintaan per menit dengan alasan yang sama seperti
unggah di peramban: setiap panggilan adalah penulisan ke object storage.

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

Membuat akun, dan butuh token administrator. Endpoint ini dulu publik, dan itu
selamat selama setiap rute meja menolak akun biasa — karena itu tidak berguna.
Begitu katalog bisa dibaca, pendaftaran terbuka berarti siapa pun yang mendaftar
langsung bisa membaca daftar produk, limit kredit setiap pelanggan, dan daftar
referensi. Jadi ia dipindah ke dalam batas admin, tidak dihapus: endpointnya masih
ada dan masih bekerja untuk administrator yang memegang token.

Akibatnya yang jujur dan perlu disebut: Swagger UI tidak bisa lagi memulai diri
dari nol. Token yang biasa digunakan untuk mendaftar adalah justru yang sekarang
diperlukan untuk mendaftar, jadi menguji API butuh akun yang sudah ada lebih dulu
(`POST /api/auth/token`).

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
| `images` | boleh null, array — lihat [galeri](#galeri-gambar-produk) |

`price` menerima `9` dan `9.5`, menolak `9.555` dan nilai negatif. Kolom database adalah
`decimal(14,2)`, sehingga lebih dari dua angka desimal memang tidak bisa disimpan. Rule ada
untuk menolaknya dengan pesan yang berguna alih-alih membulatkan diam-diam.

Respons 201 dengan produk yang dibuat di bawah `data`.

### Galeri gambar produk

`images` ikut dalam payload produk, persis seperti tag ikut payload penjualan: body ini
berarti "produk ini, dengan gambar-gambarnya". Memecahnya menjadi dua request akan membuat satu
kali Simpan punya dua tempat untuk gagal.

```json
{
  "images": [
    { "path": "uploads/8f14.jpg", "alt_text": "Tampak atas" },
    { "path": "uploads/c2b9.jpg", "alt_text": "Tampak samping" }
  ]
}
```

| Field | Rule |
| --- | --- |
| `images` | boleh null atau array. `[]` berarti galeri dikosongkan |
| `images.*.id` | id baris **yang sudah dimiliki produk ini** |
| `images.*.path` | jalur berkas di disk gambar, harus benar-benar ada |
| `images.*.alt_text` | boleh null, maks 255 |

Server **mengganti** seluruh galeri dengan daftar yang dikirim, jadi urutan daftar itu yang
menjadi urutan galeri. `id` dan `path` adalah dua Upsert yang berbeda: `id` menunjuk baris yang
sudah ada (untuk menyusun ulang atau mengganti caption), `path` menunjuk berkas yang baru
diunggah lewat `POST /admin/uploads`. `id` milik produk lain ditolak dengan 422, bukan diam-diam
dipindahkan.

Responsnya selalu menyertakan `images`:

```json
{
  "data": {
    "id": 12,
    "name": "Keyboard Mekanis",
    "images": [
      {
        "id": 88,
        "path": "uploads/8f14.jpg",
        "alt_text": "Tampak atas",
        "sort_order": 0,
        "url": "http://127.0.0.1:8000/storage/uploads/8f14.jpg"
      }
    ]
  }
}
```

Baris pertama adalah gambar utama — galeri adalah sebuah urutan, dan katalog menampilkan
kepalannya. Tidak ada kolom `is_primary`: "yang pertama" adalah jawaban yang sama dengan yang
dipakai form saat memindahkan baris ke atas, jadi tidak ada yang bisa tidaksinkron.

Pada `PATCH`, `images` mengikuti aturan parsial yang sama seperti field lain: dihilangkan berarti
galeri tidak disentuh, `[]` berarti dikosongkan. Endpoint ini dulu mengabaikan `images` sepenuhnya
padahal memvalidasinya, jadi klien bisa mengunggah gambar dan melihat 201 sambil gambarnya hilang.

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

Respons 204, tanpa body. Berkas di balik galeri ikut terhapus — cascade di database menghapus
barisnya, tapi tidak ada skema yang bisa menghapus berkas, jadi tanpa itu setiap produk yang dihapus
meninggalkan salinan gambarnya selamanya.

## Pelanggan

Direktori pelanggan beserta profil komersialnya. Ada **dua** jalur tulis karena itu memang dua
baris dengan dua umur: `PATCH /api/customers/{id}` menyentuh tiga kolom milik pelanggan, dan
`PATCH /api/customers/{id}/profile` menyentuh lima kolom profilnya.

Klien yang mengira satu endpoint melakukan keduanya akan percaya nomor teleponnya sudah
disimpan sambil mengubah limit kredit orang itu menjadi nol. Itu kesalahan yang tidak akan
dilihat sampai tagihan yang salah mendekati batas.

### GET /api/customers

Parameter query:

| Parameter | Tipe | Default | Catatan |
| --- | --- | --- | --- |
| `q` | string | — | Mencocokkan `name` atau `email` |
| `per_page` | integer | 15 | Dibatasi maksimal 100 |
| `page` | integer | 1 | |

Respons, 200:

```json
{
  "data": [
    {
      "id": 3,
      "name": "Budi Santoso",
      "email": "budi@example.com",
      "phone": "08123456789",
      "profile": {
        "city_id": 1,
        "address": "Jl. Merdeka 10",
        "tax_id": "01.234.567.8-901.000",
        "credit_limit": 25000000.0,
        "payment_terms": "Net 30",
        "city": { "id": 1, "name": "Bandung", "province": "Jawa Barat" }
      }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

`profile` bernilai `null` bila pelanggan belum pernah di-onboarding. Kunci `profile` yang **hilang
seluruhnya** berarti respons ini memang tidak memuatnya — dua hal berbeda, dan yang kedua bukan
pertanyaan tentang pelanggan. `city` ditulis inline, bukan `city_id` saja, karena konsumen API ini
tidak punya daftar kota untuk mencari tahu id-nya.

### POST /api/customers

Sekali request menulis pelanggan **dan** profilnya, seperti form Tambah di browser.

```json
{
  "name": "Budi Santoso",
  "email": "budi@example.com",
  "phone": "08123456789",
  "city_id": 1,
  "credit_limit": 25000000,
  "payment_terms": "Net 30"
}
```

| Field | Rule |
| --- | --- |
| `name` | wajib, string, maks 255 |
| `email` | wajib, email, maks 255, unik di tabel `customers` |
| `phone` | boleh null, string, maks 24 |
| `city_id` | boleh null, integer, harus ada di `cities` |
| `address` | boleh null, string, maks 500 |
| `tax_id` | boleh null, maks 32, hanya angka, titik, spasi, dan tanda hubung |
| `credit_limit` | boleh null, numerik, 0 sampai 2 desimal, 0 sampai 2 miliar |
| `payment_terms` | boleh null, string, maks 64 |

**Field profil yang kosong tidak berarti null.** `credit_limit` dan `payment_terms` adalah
`NOT NULL` dengan bawaan (`0` dan `"COD"`), jadi `null` eksplisit akan melanggar batasan kolom
dan jawabannya 500, bukan 422. Field yang ada tapi kosong berarti **pakai nilai bawaan kolom**;
field yang tidak dikirim sama sekali berarti tidak tersentuh. Perbedaan antara "kosong" dan "tidak
ada" itu yang membedakan PATCH dari PUT.

Respons 201.

### GET /api/customers/{customer}

404 jika tidak ada pelanggan dengan id itu. Menyertakan `profile.city`.

### PATCH /api/customers/{customer}

Parsial, dan hanya tiga kolom pelanggan. Mengirim `{ "phone": "0812000000" }` tidak menyentuh
profil — bukan karena sengaja dikunci, melainkan karena field profil tidak punya rule di sini dan
diabaikan.

### PATCH /api/customers/{customer}/profile

Membuat profil bila belum ada, memperbarui bila sudah. Aturan field-nya sama seperti di
`POST /api/customers`, dengan resolusi blank yang sama.

### DELETE /api/customers/{customer}

Respons 204, tanpa body — **kecuali** pelanggan masih punya penjualan, lalu 422 dengan
`{"errors": {"customer": ["Pelanggan ini masih memiliki penjualan dan tidak bisa dihapus."]}}`.

`sales.customer_id` dibatasi (`restrictOnDelete`), jadi membiarkan hapus lewat berarti pelanggaran
foreign key: 500 tanpa kalimat apa pun. Penolakan ini menyebut apa yang menghalanginya.

## Supplier

Direktori pemasok. Tidak ada pairing produk di sini, dan itu bukan kelalaian: harga dan lead time
adalah fakta **pasangan** supplier dan produk tertentu, jadi ia tinggal di `product_supplier` dan
diurus dari form produk.

### GET /api/suppliers

Parameter query sama seperti pelanggan: `q` (mencocokkan `name` atau `contact_email`),
`per_page`, `page`.

```json
{
  "data": [
    {
      "id": 2,
      "name": "PT Sinar Abadi",
      "contact_email": "sales@sinarabadi.co.id",
      "phone": "0215551234",
      "products_count": 3
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

`products_count` ada hanya di daftar, karena itu yang dihitungnya. `GET /api/suppliers/{id}` tidak
menghitung apa pun, jadi kuncinya **hilang** di sana — dan itu lebih jujur daripada `0`, yang akan
berkata supplier ini tidak memasok apa pun.

### POST /api/suppliers

```json
{
  "name": "PT Sinar Abadi",
  "contact_email": "sales@sinarabadi.co.id",
  "phone": "0215551234"
}
```

| Field | Rule |
| --- | --- |
| `name` | wajib, string, maks 255, unik |
| `contact_email` | boleh null, email, maks 255 |
| `phone` | boleh null, string, maks 24 |

Hanya `name` yang wajib. `contact_email` dilipat ke huruf kecil sebelum ditulis dan dibandingkan,
seperti setiap email lain di aplikasi ini.

### GET /api/suppliers/{supplier}

404 jika tidak ada. Tanpa `products_count`; lihat di atas.

### PATCH /api/suppliers/{supplier}

Parsial, tiga kolom saja. Pairing produk dan harga-biayanya tidak tersentuh — mengubah kontak
tidak boleh menulis ulang riwayat pembelian.

### DELETE /api/suppliers/{supplier}

Respons 204, tanpa body — **kecuali** supplier masih memasok produk, lalu 422 dengan
`{"errors": {"supplier": ["Supplier ini masih memasok produk dan tidak bisa dihapus."]}}`.

Kasus ini lebih berbahaya daripada kasus pelanggan. `product_supplier` ber-`cascade`, jadi hapus
akan **berhasil** sambil diam-diam mencabut harga dan lead time yang sudah disepakati dari setiap
produk yang diasoksi supplier itu — penulisan ulang sejarah pembelian yang tidak pernah dilaporkan
ke siapa pun.

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

curl -s "${A[@]}" $BASE/api/customers
curl -s "${A[@]}" -X POST -d '{"name":"Budi","email":"budi@example.com"}' $BASE/api/customers
curl -s "${A[@]}" -X PATCH -d '{"credit_limit":25000000}' $BASE/api/customers/3/profile
curl -s "${A[@]}" -X DELETE $BASE/api/customers/3

curl -s "${A[@]}" $BASE/api/suppliers
curl -s "${A[@]}" -X POST -d '{"name":"PT Sinar Abadi"}' $BASE/api/suppliers
```

Tambahkan `-i` pada salah satunya untuk melihat status code.

## Status code yang dipakai di sini

| Kode | Arti di API ini |
| --- | --- |
| 200 | Baca atau update berhasil |
| 201 | Dibuat. `POST /api/products`, `POST /api/customers`, `POST /api/suppliers`, dan `POST /api/account` mengembalikannya |
| 204 | Dihapus atau dicabut. Tanpa body respons |
| 401 | Tanpa token, atau token tidak valid atau sudah dicabut |
| 403 | Token sah, tetapi bukan administrator |
| 404 | Tidak ada produk, pelanggan, atau supplier dengan id itu |
| 422 | Body gagal divalidasi, **atau** hapus ditolak karena barisnya masih dipakai. Body JSON mencantumkan setiap field yang gagal |
| 429 | Lebih dari 6 request per menit ke endpoint publik |
