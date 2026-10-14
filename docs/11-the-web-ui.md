# 11. UI web

API dibangun lebih dulu dan UI menyusul setelahnya, sehingga UI adalah lapisan tipis di atas model yang sama. Tidak ada jalur data kedua: kontroler web memakai model `Product` yang sama dan kelas `FormRequest` yang sama seperti API, dan `App\Actions\UpdateAccount` yang sama menangani perubahan kata sandi untuk keduanya.

## Inertia

Setiap halaman adalah komponen Vue. Kontroler mengembalikan props halaman dan nama komponen untuk merendernya:

```php
return Inertia::render('Products/Index', ['products' => $products, 'search' => $search]);
```

`resources/js/app.js` mem-boot-nya. Hanya ada satu panggilan `createInertiaApp`, dan resolver halaman tidak ditulis di sana — `@inertiajs/vite` menyuntikkannya saat build, melakukan globbing `./pages` untuk berkas `.vue`, sehingga daftar halaman hanya ada di tepat satu tempat.

`resources/views` terdiri dari tiga berkas Blade:

| Berkas | Alasan masih memakai Blade |
| --- | --- |
| `app.blade.php` | Tampilan root. Inertia merender komponen halaman ke dalamnya, sehingga sesuatu harus menyediakan dokumen HTML. Sebelas baris konten. |
| `auth/login.blade.php` | Halaman Masuk. Lihat di bawah. |
| `auth/register.blade.php` | Sama. |

Ditambah `resources/views/vendor/l5-swagger`, yang diterbitkan oleh paket Swagger dan diregenerasi pada setiap `l5-swagger:publish`.

### Mengapa Masuk bukan komponen halaman

Kedua formulir autentikasi memuat stylesheet dan **tanpa JavaScript sama sekali**. Halaman Masuk adalah satu tempat di mana pengunjung tidak seharusnya menunggu bundle, dan tempat di mana skrip yang gagal mengunci mereka sepenuhnya. Di sinilah pula pengelola kata sandi dan teknologi asistif paling sensitif terhadap markup. Kedua formulir tidak membutuhkan bundle Inertia, dan titik masuk Inertia akan mencoba me-mount ke elemen `#app` yang tidak dimiliki halaman-halaman ini.

Ini adalah pengecualian yang disengaja, bukan pekerjaan yang belum selesai. Mengonversinya nanti adalah satu perubahan yang mandiri; tidak ada bagian lain di aplikasi yang bergantung padanya.

## SSR mati, dan tidak bisa aktif di sini

Inertia 3 mengaktifkan SSR secara bawaan. `config/inertia.php` mematikannya, dan alasannya adalah deployment: SSR membutuhkan proses Node yang mendengarkan pada setiap permintaan, dan `Dockerfile.vercel` membangun aset dalam tahap `node:22` lalu hanya menyalin `public/build` ke image runtime yang berupa `dunglas/frankenphp` — PHP dan tidak ada yang lain.

Membiarkannya aktif akan membuat Inertia mencari bundle yang tidak akan pernah ditemukan dan runtime yang tidak pernah menjawab. `INERTIA_SSR_ENABLED=false` juga ada di `.env.example` untuk alasan yang sama.

Jadi halaman tiba sebagai HTML asli pada permintaan pertama dan setiap navigasi setelahnya adalah XHR yang menukar komponen. Itu pertukaran yang bagus untuk permukaan administrator yang terautentikasi: tidak ada SEO yang perlu dimenangkan, dan sebagai gantinya ada satu paradigma rendering, bukan dua.

## Props bersama

`HandleInertiaRequests` menyediakan semua yang dibutuhkan shell, alih-alih setiap kontroler mengopernya:

| Prop | Isi |
| --- | --- |
| `auth.user` | Id, nama, dan email pengguna yang masuk. `only()`, dengan sengaja: prop halaman diserialisasi ke dalam HTML peramban, sehingga apa pun yang sensitif di atasnya akan dapat dibaca oleh siapa pun yang bisa membaca halaman. |
| `status` | Pesan sesi yang di-flash. Inertia tidak mengenal konsep nilai yang di-flash. |
| `appName` | `config('app.name')` |
| `routes` | Setiap URL yang dibutuhkan frontend (di bawah) |
| `errors` | Dari `parent::share()` |

### Peta rute

`route()` adalah helper Blade, sehingga komponen Vue tidak punya cara menjangkaunya. Alih-alih membangun ulang path di JavaScript, middleware membagikannya dan `resources/js/shared/routes.js` membacanya:

```js
const routes = useRoutes();
form.post(routes.productsStore);
```

Rute yang menerima model tidak ada di peta — rute tersebut bersifat per-baris, sehingga kontroler yang memiliki baris-barisnya yang membangunnya (`edit_url`, `destroy_url` pada setiap baris produk).

`useRoutes()` mengembalikan objek biasa, bukan ref. Nilainya sudah reaktif, dan ref akan memaksa `.value` di script sementara templat membukanya secara gratis: dua ejaan untuk satu akses, di mana ejaan script diam-diam menghasilkan `undefined`.

`tests/Unit/InertiaRoutePropsTest.php` menegakkan peta ke dua arah — setiap kunci yang dibaca komponen dibagikan, dan setiap kunci yang dibagikan dibaca oleh sesuatu. Kunci yang diganti namanya tidak terlihat oleh pemeriksa tipe, build, maupun pengujian HTTP mana pun: tidak ada yang error dan setiap respons adalah 200.

## Validasi

Aturannya tidak berpindah. `StoreProductRequest` memvalidasi produk baik saat tiba sebagai JSON dari API maupun dari formulir Vue, karena keduanya mem-posting ke kontroler yang sama.

Kegagalan mengarahkan kembali dengan error; Inertia menyampaikannya sebagai prop `errors` bersama dan `useForm` menyalinnya ke `form.errors`, yang dibaca oleh `ProductFields.vue`. Peramban bukan batas kepercayaan, sehingga tidak ada yang dilonggarkan agar formulir sisi klien berfungsi — harga `1.234` ditolak oleh aturan `decimal:0,2` di server, dan pesannya muncul pada kolomnya.

## Rute

| Metode | Path | Nama | Auth |
| --- | --- | --- | --- |
| GET | `/` | | Dashboard untuk administrator, halaman akun sendiri bila bukan, Masuk untuk tamu |
| GET | `/login` | `login` | guest |
| POST | `/login` | | guest |
| GET | `/register` | `register` | guest |
| POST | `/register` | | guest, throttled |
| POST | `/logout` | | session |
| GET | `/products` | `products.index` | admin |
| GET | `/products/create` | `products.create` | admin |
| POST | `/products` | `products.store` | admin |
| GET | `/products/{product}/edit` | `products.edit` | admin |
| PATCH | `/products/{product}` | `products.update` | admin |
| DELETE | `/products/{product}` | `products.destroy` | admin |
| GET | `/account` | `account.show` | session |
| PATCH | `/account` | `account.update` | session |
| DELETE | `/account` | `account.destroy` | session |
| POST | `/assistant` | `assistant.store` | admin, throttled |

Nama rute API diawali `api.`, misalnya `api.products.index`. Dulu namanya `products.index` dan bertabrakan dengan nama web, dan karena rute API didaftarkan lebih dulu, `route('products.index')` diam-diam mengarah ke `/api/products` dan setiap tautan di UI menunjuk ke API. Awalan ini menghilangkan jenis masalahnya, bukan sekadar mengakalinya.

## Dua sistem autentikasi, dengan sengaja

| Permukaan | Guard | Penyimpanan |
| --- | --- | --- |
| Halaman web | `auth`, guard sesi | kuki sesi terenkripsi, baris-baris di `sessions` |
| `api/*` | `auth:sanctum` | token bearer di header `Authorization` |

Tamu yang memanggil API tetap mendapat `401` dengan bodi JSON, bukan pengalihan ke formulir Masuk HTML. Itulah `shouldRenderJsonWhen` di `bootstrap/app.php` yang mencocokkan `api/*`, dan `WebAuthTest` mengujinya sehingga perubahan target pengalihan di masa depan tidak bisa merusaknya diam-diam.

Grup `api` tidak menyertakan `EnsureFrontendRequestsAreStateful`, sehingga autentikasi kuki tidak bocor ke permukaan API. Inilah juga alasan halaman membawa datanya sebagai props alih-alih mengambilnya: halaman sesi yang memanggil `/api/products` akan mendapat `401`.

Inertia membawa kuki `XSRF-TOKEN`, sehingga tidak ada meta tag `csrf-token` dan tidak ada token CSRF yang diuntai melalui frontend.

## Daftar

`GET /register` adalah formulir untuk operasi yang sama seperti `POST /api/account`: nama, email, kata sandi, konfirmasi. Ia membuat akun lalu Masuk.

Ia selalu membuat akun **biasa** (akun biasa). `is_admin` tidak mass-assignable maupun termasuk kolom yang divalidasi, sehingga tidak ada bodi permintaan yang menghasilkan administrator melalui pintu ini — satu-satunya jalan masuk adalah `php artisan admin:ensure` dari shell. Lihat [06. Autentikasi dan token](06-auth-and-tokens.md).

Karena akun baru tidak bisa membuka Dashboard, pendaftaran mendarat di halaman akun alih-alih menjawab pendaftaran yang berhasil dengan `403`.

Keduanya berbagi `RegisterAccountRequest`, sehingga aturan tidak bisa menyimpang, dan rute web membawa `throttle:6,1` yang sama seperti rute API. Karena pembatas itu memakai IP untuk tamu, dan setiap permintaan pendaftaran adalah tamu, kedua pintu memakai satu anggaran per alamat.

Apapun, ini pintu terlebar di deployment. Formulir tidak memperlebar eksposur yang sudah dimiliki `POST /api/account`, ia hanya membuatnya lebih mudah dilalui.

Tiga detail yang perlu diketahui jika Anda mengubahnya:

- Kata sandi di-hash oleh cast `hashed` pada `User`, bukan oleh kontroler.
- `session()->regenerate()` berjalan setelah `Auth::login()`. Mendaftar adalah perubahan hak akses, sehingga butuh id sesi baru dengan alasan yang sama seperti saat Masuk.
- Kedua rute pendaftaran berada di grup `guest`, sehingga pengunjung yang sudah masuk yang mem-posting ke `/register` dialihkan ke daftar produk alih-alih membuat akun kedua.

## Logika bersama yang perlu diketahui

`App\Actions\UpdateAccount` dipakai oleh kontroler API maupun kontroler web. Ia mengisi kolom yang tervalidasi, dan jika kata sandi diputar, ia mencabut token-token akun, sambil mempertahankan milik pemanggil bila ada.

Di bawah guard sesi tidak ada token saat ini, sehingga perubahan kata sandi dari UI mencabut **setiap** token pada akun. Itu hasil yang lebih ketat dan benar, dan `WebAccountTest::test_changing_the_password_revokes_every_api_token` menguncinya.

Verifikasi `current_password` berada di `UpdateAccountRequest` sebagai aturan kondisional:

```php
'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
```

sehingga ia wajib tepat saat kata sandi ada dan diabaikan bila tidak. Halaman akun memiliki formulir "detail" dan "kata sandi" terpisah yang mem-posting ke endpoint yang sama, itulah mengapa penyimpanan khusus detail tidak meminta kata sandi. Menggabungkannya menjadi satu formulir akan membuat setiap penyimpanan meminta kata sandi saat ini hanya untuk mengubah nama tampilan.

## Asisten

Gelembungnya adalah `resources/js/components/AssistantBubble.vue`, dirender di dalam `layouts/AdminLayout.vue`, yang dijaga tetap ter-mount oleh Inertia di seluruh navigasi. Lihat [12. Shell administrator](12-the-admin-shell.md) untuk shell itu sendiri.

Ia mem-posting melalui `useHttp`, bukan `fetch`. `useHttp` adalah klien milik Inertia: ia membawa kuki XSRF, dan ia sengaja tidak memicu kunjungan halaman, yang memang tidak boleh dilakukan pesan obrolan. `fetch` rakitan sendiri akan mengimplementasikan ulang keduanya, itulah mengapa `resources/js/shared/http.js` sudah tidak ada.

Perilaku endpoint itu sendiri — autentikasi, pembatasan per pengguna, memori percakapan, kegagalan penyedia — dicakup oleh `AssistantEndpointTest`.

## Membangun dan melihatnya

```sh
npm install
npm run build     # or npm run dev for hot reload
php artisan serve
```

Meja penjualan membutuhkan administrator, jadi buat dulu:

```sh
php artisan admin:ensure
```

Lalu Masuk di `/login`. Mendaftar di `/register` menghasilkan akun biasa, yang hanya bisa mengelola detailnya sendiri dan tidak yang lain. Halaman-halaman membutuhkan hasil build; API tidak, karena ia tidak pernah merender tampilan.

## Pengujian

| Berkas | Cakupan |
| --- | --- |
| `tests/Feature/Web/WebAuthTest.php` | Masuk, Keluar, pengalihan tamu, regenerasi sesi saat Masuk, dan API yang tetap menjawab 401 |
| `tests/Feature/Web/WebRegisterTest.php` | Pembuatan akun, kata sandi yang di-hash, pengalihan tamu pada formulir, pembatasan di seluruh sesi terpisah, dan regenerasi sesi |
| `tests/Feature/Web/WebProductTest.php` | Listing, pengurutan, pencarian, paginasi, batas ukuran halaman, pembuatan, pembaruan, penghapusan, bahwa setiap baris membawa URL-nya, dan bahwa aturan validasi API dipakai ulang |
| `tests/Feature/Web/WebAccountTest.php` | Pembaruan detail, rotasi kata sandi yang membutuhkan kata sandi saat ini, pencabutan token, bahwa tidak ada kata sandi yang sampai ke props halaman, dan penghapusan akun |
| `tests/Feature/Web/AssistantWidgetTest.php` | Endpoint asisten dibagikan pada halaman yang sudah masuk, dan tamu tidak pernah diberi halaman yang membawa layout |
| `tests/Feature/ListProductsTest.php` | Halaman web dan listing JSON mengembalikan baris yang sama, dibaca dari JSON kedua sisi |
| `tests/Unit/InertiaRoutePropsTest.php` | Setiap kunci `routes.*` yang dibaca komponen dibagikan, dan setiap kunci yang dibagikan dibaca oleh sesuatu |

Aserti halaman memakai `assertInertia()`, bukan `assertSee()`. Komponen halaman dirender di peramban, sehingga tidak ada baris yang dirender server untuk dicocokkan, dan pemeriksaan berpindah ke props yang diserialisasi Inertia. Yang benar-benar hilang adalah jaminan bahwa halaman tiba sebagai HTML asli — yang masih benar untuk permintaan pertama setiap halaman, dan tidak dapat diuji dari PHPUnit.
