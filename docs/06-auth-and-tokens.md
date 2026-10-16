# 06. Autentikasi dan token

## Apa sebenarnya token itu

Token akses personal Sanctum. Saat kamu memanggil `POST /api/auth/token`, server membuat satu
baris di `personal_access_tokens` dan mengembalikan string berbentuk seperti ini:

```text
3|Ip7Xq7KgAf3nRt8sXq2mB7wLpZ0dYcVnE4hGjKfTu
└┬┘ └──────────────────┬──────────────────┘
 │                    │
 id              random secret
```

Kedua bagian dibutuhkan. Tempel seluruh string, termasuk `3|`.

## Database tidak pernah menyimpan token

Ini bagian yang mengejutkan banyak orang, dan penting jika kamu tergoda menaruh token di
file konfigurasi.

Saya membaca tabel live dari `database/database.sqlite`:

```text
id=2  user_id=1  name=swagger-ui  token col = 64 hex chars, starts b83b171b2afc595e...
id=5  user_id=1  name=cli         token col = 64 hex chars, starts 8085a0775808ae81...
```

64 karakter hex adalah hash SHA-256. Plaintext hanya ada di satu respons HTTP yang
membuatnya. Dua tempat di source Sanctum mengonfirmasinya:

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

Jadi `findToken` meng-hash apa yang kamu kirim dan mencarinya. Dua konsekuensinya:

- Jika kamu kehilangan token, kamu tidak bisa memulihkannya. Terbitkan yang baru.
- Token tetap di `.env` tidak dimungkinkan. Plaintext acak saat pembuatan, jadi nilai
  hardcoded harus salah satu yang sudah lewat pembuatan dan kamu catat. Itu juga kredensial
  pengguna yang live, yang buruk disimpan di file plaintext yang disalin ke mana-mana.

## Mendapatkan token

**Dari browser.** Expand `POST /api/auth/token`, **Try it out**, **Execute**, salin
`token` dari respons, lalu **Authorize** di kanan atas.

**Dari shell.**

```sh
php artisan tinker --execute 'echo App\Models\User::first()->createToken("cli")->plainTextToken;'
```

**Dengan membuat akun baru**, yang mengembalikan token dalam respons yang sama. Butuh
token administrator, jadi jalankan setelah langkah di atas:

```sh
curl -s -X POST http://127.0.0.1:8000/api/account \
  -H 'Authorization: Bearer TOKEN_ADMIN' \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"correct-horse-battery9","password_confirmation":"correct-horse-battery9"}'
```

`POST /api/account` tidak lagi publik. Endpoint ini dulu bisa memulai dari nol —
token yang biasa dipakai mendaftarkan akun adalah justru yang sekarang dibutuhkan
token yang biasa dipakai untuk mendaftarkan akun adalah justru yang sekarang dibutuhkan
untuk mendaftarkan akun, jadi tidak ada jalan lagi untuk memulai dari kosong.
Akun dibuat lewat form `/register` oleh administrator, dan tokennya didapat dari
`POST /api/auth/token` seperti biasa.

## Token tidak kedaluwarsa

```php
// config/sanctum.php:53
'expiration' => null,
```

`null` berarti tidak pernah. Token yang dibuat hari ini masih berfungsi bulan depan, sampai
dicabut atau akun dihapus.

## Tidak perlu mengetik ulang token

Swagger UI menyimpan token di penyimpanan browser, jadi kamu cukup menempel sekali per browser.
Saklarnya sudah menyala:

```php
// config/l5-swagger.php:320
'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', true),
```

View yang dipublikasikan meneruskan itu langsung ke `SwaggerUIBundle`, jadi nilainya live di
halaman. Saya mengonfirmasinya dengan menjalankan app dan mem-grep HTML untuk
`persistAuthorization: "true"`.

Dua batasan. Penyimpanan browser dikunci per origin, jadi `127.0.0.1:8000` dan `127.0.0.1:8001`
menyimpan token terpisah dan kamu menempel ulang sekali per port. Dan JavaScript apa pun pada
origin tersebut bisa membacanya, yang tidak masalah untuk `127.0.0.1` dan bukan pola untuk
ditiru ke production.

Untuk mematikannya tanpa mengedit config, set `L5_SWAGGER_UI_PERSIST_AUTHORIZATION=false`
di `.env`.

## Administrator dan akun biasa

`users.is_admin` adalah satu boolean. Aplikasi ini punya tepat dua jenis akun —
seseorang yang bekerja di meja penjualan dan seseorang yang tidak — jadi flag ini selebar satu
kolom ketimbang tabel roles dengan pivot permissions dan gate. Setiap potongan tambahan dari
struktur itu adalah hal lain yang harus dijaga konsisten, untuk pertanyaan yang jawabannya tidak
membutuhkannya.

Batasnya adalah middleware, dideklarasikan sekali pada grup route:

```php
// bootstrap/app.php
$middleware->alias(['admin' => EnsureAdmin::class]);

// routes/web.php
Route::middleware('admin')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    // …the whole desk
});
```

Dideklarasikan pada grup ketimbang dicek di dua puluh controller, karena halaman yang ditambahkan
belakangan tidak bisa terjangkau secara tidak sengaja: ia masuk grup itu atau tidak.

Akun biasa adalah **pembaca**: ia membuka katalog dan daftar referensi, dan tidak
menulis apa pun. Tulisannya tetap milik administrator.

| Permukaan | Akun biasa | Administrator |
| --- | --- | --- |
| `/account` dan layanan mandirinya | ya | ya |
| `/products`, `/customers`, `/suppliers`, `/references/*` | ya, hanya baca | ya, baca dan tulis |
| Tulisannya: create, edit, hapus, impor, unggah | `403` | ya |
| `/dashboard`, `/sales`, `/sales/*` | `403` | ya |
| `POST /admin/uploads`, `POST /assistant` | `403` | ya |
| `POST /api/account` | `403` | ya |
| `/api/products/*` | `403` | ya |

Batasnya digambar di sini, bukan di UI: `EnsureAdmin` masih di grup yang sama, jadi
pembaca yang menemukan URL yang tidak ditawarkan templat tetap mendapat `403`.

Layanan mandiri tetap terbuka dengan sengaja: halaman mendaratnya adalah halaman akun,
dan halaman itu tidak pernah di belakang middleware. Dulu akun biasa akan masuk lalu
hanya menemui `403` tanpa tempat tujuan. Kini ia punya tujuan — membaca katalognya.

Membuat akun (`/register` dan `POST /api/account`) ikut tertutup, dan itu bukan detail
tersembunyi: pendaftaran terbuka berarti setiap orang yang mendaftar membaca katalog
beserta harga dan stoknya, limit kredit setiap pelanggan, dan ketiga daftar referensi.

`is_admin` **tidak** ada di `$fillable` model. Pendaftaran tidak bisa memberikannya, begitu juga
`PATCH /api/account` — flag ini tidak termasuk field yang divalidasi dan model menolak
mass-assign. `WebRegisterTest` mengirim `is_admin` bersama payload valid khusus untuk membuktikan
kunci tambahan itu tidak mengubah apa pun.

Model juga mencerminkan default database:

```php
protected $attributes = ['is_admin' => false];
```

Default kolom berlaku saat baris *disisipkan*, bukan saat model *diinstansiasi*. Akun yang
dipegang di session, atau dibuat oleh factory yang tidak menyebut kolom itu, tidak punya atribut
`is_admin` sama sekali sampai dibaca ulang — dan `only('is_admin')` pada model tanpa atribut
menghilangkan kunci ketimbang menghasilkan `false`. Ini bukan teoritis: baik middleware maupun
prop Inertia membaca flag dari model di memori.

## Membuat administrator

Tidak ada seeder admin dan tidak ada environment variable `ADMIN_PASSWORD`. Administrator
dibuat oleh seseorang di shell:

```sh
php artisan admin:ensure
```

```
Email administrator: admin@example.com
Nama tampilan: Administrator
Kata sandi (minimal 12 karakter, ada huruf dan angka): ********
Ulangi kata sandi: ********
Administrator dibuat: admin@example.com
```

Ia membuat akun, atau mempromosikan yang sudah ada setelah bertanya. Dua flag mengubah itu:

- `--force` tidak bertanya sebelum mempromosikan. Pertanyaan dilewati langsung ketimbang
  default ke yes — prompt yang bertanya lalu tetap melakukannya bukanlah
  konfirmasi.
- `--reset` mengganti kata sandi administrator yang sudah ada **dan mencabut setiap token**,
  karena reset diminta saat sesuatu tentang kredensial lama tidak dipercaya, dan
  token yang dibuat sebelumnya sama tidak dipercayanya dengan kata sandi yang digantikannya.

Menjalankannya dua kali tidak mengubah apa pun: run kedua melaporkan bahwa akun sudah menjadi
administrator dan berhenti. Menjalankannya ulang "untuk memastikan" tidak boleh diam-diam memutar
kata sandi yang masih berfungsi.

Kata sandi diminta lewat hidden prompt, tidak pernah diterima sebagai argumen. Argumen adalah
nilai yang mendarat di riwayat shell, di daftar proses, dan di log apa pun yang menjalankan
command. `AdminEnsureCommandTest` menegaskan kedua sisi: bahwa tidak ada opsi pada signature yang
bisa menerimanya, dan bahwa nilainya tidak pernah muncul di output.

### Administrator terakhir tidak bisa dihapus

Pemulihan setelah kehilangan administrator terakhir benar-benar mahal — promosi butuh shell, dan
deployment tanpa administrator tidak punya siapa pun yang bisa login dan meminta — jadi
`DeleteAccount` menolak penghapusan yang akan menyisakan nol. Penghitungan, keputusan, dan
penghapusan berjalan dalam satu transaksi dengan `lockForUpdate`, karena membaca hitungan dalam
transaksi sendiri melepas lock sebelum delete berjalan, yang merupakan celah yang akan dipakai dua
administrator yang menghapus dirinya bersamaan.

Semua yang lain tetap biasa: nama, email, dan kata sandi administrator diubah seperti milik orang
lain, dan akun biasa bisa menghapus dirinya sendiri dengan bebas.

## Kata sandi

Satu kebijakan, di `App\Rules\AccountValidation`, dipakai oleh setiap pintu yang menerima kata
sandi:

```text
minimum 12 characters
at least one letter
at least one number
confirmation required
rejected if it appears in known breach data
```

Sengaja tidak ada aturan simbol. Persyaratan simbol adalah yang diakali orang ketimbang
dipenuhi — ia menghasilkan `Password1!` dengan andal, yang lebih buruk daripada frasa panjang
tanpa simbol.

Pengecekan breach adalah k-anonymity: hanya lima karakter pertama dari hash SHA-1 yang keluar
dari proses, dan `CredentialValidationTest` menegaskan itu pada request keluar ketimbang pada
respons.

Dua hal yang sengaja **tidak** dikenai kebijakan ini:

- **Masuk.** `LoginRequest` dan `IssueTokenRequest` hanya memvalidasi *bentuk* kredensial.
  Menerapkan kebijakan pembuatan saat Masuk akan mengunci setiap akun yang dibuat di bawah
  kebijakan lama, dengan alasan bahwa kata sandi yang tidak bisa mereka ubah adalah masalah yang
  mereka diminta Masuk untuk melaporkannya.
- **Keterkiriman email.** Aturan `dns` membuktikan domain punya mail exchanger, yang merupakan
  fakta tentang domain ketimbang tentang apakah akun ini ada, dan itu membuat form bergantung pada
  jawaban DNS. `email:rfc` adalah seluruhnya.

## Email disimpan terlipat

Setiap pintu yang membaca atau menulis email memangkas dan menghurufkecilkan dulu:

```php
protected function prepareForValidation(): void
{
    $this->merge(['email' => AccountValidation::normaliseEmail($this->input('email'))]);
}
```

Tanpa ini, unique index ditanya apakah `Ada@example.com` bebas sementara baris disimpan sebagai
`ada@example.com`, dan jawabannya bergantung pada collation database — begitulah satu orang
mendaftarkan alamat yang sama dua kali lalu tidak bisa Masuk ke kedua akun.

## Kegagalan hanya berkata satu hal

Kata sandi yang salah dan alamat yang tidak dikenal menghasilkan pesan yang sama, baik pada form
maupun endpoint token:

```php
throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
```

Membedakannya akan mengubah form login menjadi cara menemukan alamat mana yang punya akun.
`CredentialValidationTest` menegaskan kedua respons *sama*, karena kegagalan yang dijaga adalah
perbedaan.

## Masih belum ada `/api/users`

Endpoint yang jelas akan menjadi:

```text
GET    /api/users          list every account
GET    /api/users/{id}     read any account
PATCH  /api/users/{id}     change any account
DELETE /api/users/{id}     delete any account
```

Dengan flag administrator yang kini ada, eskalasinya lebih pendek tetapi tidak tertutup oleh
tidak adanya route: administrator bisa mengenumerasi dan menulis ulang kata sandi siapa pun. Jadi
tidak ada yang ditambahkan. Keempat endpoint akun bertindak pada pemanggil dan tidak pada yang
lain:

```text
/api/users         -> 404
/api/user          -> 404
/api/accounts      -> 404
/api/account/1     -> 404
```

Isolasi ini struktural ketimbang cek yang bisa dilupakan. Mengelola akun lain adalah fitur nyata,
dan harus dibangun sebagai satu fitur — bukan tercapai dengan menghapus cek middleware `admin`.

## Perubahan kata sandi membutuhkan kata sandi saat ini

```php
// app/Http/Requests/UpdateAccountRequest.php
'current_password' => [Rule::when($this->filled('password'), ['required', 'current_password'])],
```

Tanpa ini, siapa pun yang memegang token bocor bisa menetapkan kata sandi baru dan mengunci
pemilik asli secara permanen. Mensyaratkan kata sandi saat ini berarti token curian saja tidak
cukup.

`Rule::when` melakukan kerja nyata. Versi pertama memakai
`['required_with:password', 'current_password']`, dan itu bug: `required_with` hanya mengontrol
apakah field harus **ada**, jadi `current_password` yang dikirim bersama perubahan nama yang tidak
terkait tetap dicek dan gagal dengan 422. `Rule::when` tidak menerapkan aturan sama sekali saat
`password` tidak ada, jadi field dilewati.

Itu diverifikasi dari framework, bukan diasumsikan.
`ValidationRuleParser::filterConditionalRules` menyelesaikan kondisi false tanpa cabang else
menjadi set aturan kosong:

```php
return [$attribute => $attributeRules->passes($data)
    ? array_filter($attributeRules->rules($data))
    : array_filter($attributeRules->defaultRules($data)), ];
```

Saya juga mengonfirmasi test menangkapnya. Mengembalikan satu baris itu membuat kedua test baru
gagal dengan `422 "The password is incorrect"`, jadi perbaikan ini dipatok ketimbang kebetulan.

## Memutar kata sandi mencabut token lain

```php
private function revokeOtherTokens(Request $request, User $user): void
{
    $currentTokenId = $request->user()->currentAccessToken()?->getKey();

    $user->tokens()
        ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
        ->delete();
}
```

Jika kamu memutar kata sandimu karena mengira token bocor, setiap token lain harus ikut mati.
Kalau tidak, token penyerang tetap berfungsi, yang menggagalkan tujuan pemutaran.

Token milik pemanggil sendiri dikecualikan dengan `whereKeyNot` sehingga kamu tidak Keluar dari
sesi yang baru dipakai. Terverifikasi lewat HTTP:

```text
device A after rotation -> 200   (the caller keeps working)
device B after rotation -> 401   (revoked)
```

## Menghapus akun tidak meninggalkan token yatim

`personal_access_tokens` dibuat dengan `morphs('tokenable')`, yang tidak menambahkan
`ON DELETE CASCADE`, dan Sanctum tidak mendaftarkan hook `deleting`. Saya memeriksa keduanya:

```php
// database/migrations/..._create_personal_access_tokens_table.php
$table->morphs('tokenable');
```

Jadi `$user->delete()` biasa akan menyisakan baris token yang live. Controller menghapusnya
dulu:

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

Satu test menegaskan nol baris tersisa setelahnya, dan yang kedua menegaskan token tidak lagi
mengautentikasi. Test kedua membutuhkan `$this->app->make('auth')->forgetGuards()` di antara dua
request, karena test berbagi satu instance aplikasi dan guard akan memakai ulang pengguna yang
sudah di-resolve ketimbang memeriksa ulang token. Tanpa baris itu test lolos karena alasan yang
salah, yang lebih buruk daripada test yang gagal.

## Mengapa tamu mendapat 401 bukan pengalihan

```php
// bootstrap/app.php
$middleware->redirectGuestsTo(fn (Request $request) => route('login'));
```

Middleware `auth` Laravel, saat tidak bisa mengautentikasi, mencoba mengarahkan browser ke route
`login`. UI web kini memilikinya, jadi itu teratasi secara normal:

```php
$middleware->redirectGuestsTo(fn (Request $request) => route('login'));
```

Yang penting untuk API adalah tamu yang memanggil `api/*` mendapat body JSON 401 bukan redirect
HTML. Itu berasal dari baris berikutnya, bukan dari target redirect:

```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
);
```

Test tidak menangkap ini, karena `getJson()` mengirim `Accept: application/json`, yang membuat
`expectsJson()` bernilai true dan melewati jalur redirect. Saya menemukannya dengan `curl` biasa
yang tidak mengirim header `Accept`. Layak diingat: uji dengan `curl` selain suite.

## Test yang akan mengecohmu

Dalam test fitur Laravel, beberapa panggilan HTTP dalam satu method berbagi satu instance
aplikasi, dan `AuthManager` meng-cache guard yang sudah di-resolve:

```php
// vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php:41
protected $guards = [];
```

Jadi jika request pertama terautentikasi, yang kedua bisa memakai ulang pengguna itu tanpa membaca
ulang token. Test yang menegaskan bahwa token yang dicabut kini gagal akan lolos secara keliru.
Tambahkan:

```php
$this->app->make('auth')->forgetGuards();
```

di antara request setiap kali inti test adalah bahwa autentikasi berubah.
