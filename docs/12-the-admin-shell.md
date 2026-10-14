# 12. Shell administrator

Setiap halaman yang sudah masuk dirender di dalam satu shell: rel tetap di kiri, bar gelap di atas, dan halaman di tengah. Dokumen ini membahas cara menyusunnya dan alasannya, di bagian-bagian di mana jawabannya bukan yang sudah jelas.

## Bentuknya

```
resources/js/
  layouts/AdminLayout.vue      the shell — rail, topbar, page
  components/
    AdminSidebar.vue           the rail
    AdminTopbar.vue            the dark bar
    PanelSection.vue           the card wrapper every page reuses
    Icon.vue                   the icon set
    RevenueChart.vue           the Chart.js line chart
    RichTextEditor.vue         the TipTap note editor, uploads through /admin/uploads
  pages/
    Dashboard/Index.vue
    Sales/{Index,Form,Show}.vue
    Products/{Index,Create,Edit}.vue
    Customers/Index.vue
    Suppliers/Index.vue
    Account/Edit.vue
```

Antarmuka seluruhnya berbahasa Indonesia — lihat [Antarmuka berbahasa Indonesia](#the-interface-is-in-indonesian) di bawah.

`AdminLayout` adalah keseluruhan chrome. Inertia menjaganya tetap ter-mount di seluruh navigasi, sehingga rel bertahan melewati pergantian halaman dan drawer berperilaku seperti shell aplikasi, bukan halaman yang kebetulan punya menu.

## Dua hal tentang halaman yang mudah salah

**Setiap halaman harus mendeklarasikan layout.**

```js
defineOptions({ layout: AdminLayout });
```

Halaman yang melewatkannya tetap ter-build bersih, ter-impor bersih, merender kontennya sendiri dengan sempurna, tidak melempar apa pun dan tidak mencatat apa pun. Ia hanya tampil tanpa rel, tanpa topbar, dan tanpa banner flash. Hal ini pernah terjadi sekali selama pekerjaan ini dan hanya ketahuan dengan melihat halaman yang dirender, itulah mengapa `PageLayoutTest` kini memindai setiap komponen halaman untuk mencari deklarasi itu.

**Shell adalah baris flex; hanya kolom utama yang bergulir.**

```css
.admin-shell { display: flex; min-height: 100vh; }
.admin-main  { display: flex; flex: 1; flex-direction: column; min-width: 0; }
.admin-scroll { flex: 1; overflow-y: auto; }
```

`min-width: 0` pada kolom utama bersifat penopang beban. Tanpanya tabel lebar di dalamnya menetapkan ukuran konten minimum item flex, kolom menolak menyusut, dan rel terdorong keluar layar — itulah overflow flexbox klasik dan terlihat persis seperti layout yang rusak.

## Palet

Dideklarasikan di `resources/css/app.css` di bawah `@theme`, yang mengubah masing-masing menjadi utilitas Tailwind — `bg-admin-primary`, `text-admin-danger`, `shadow-admin-card` — sehingga komponen meminta apa *benda* itu, bukan kode warna, dan palet berubah dalam satu berkas.

| Token | Nilai | Dipakai untuk |
| --- | --- | --- |
| `admin-sidebar` | `#1f1f1f` | Rel |
| `admin-topbar` | `#0d0d0d` | Bar atas |
| `admin-primary` | `#3c8dbc` | Navigasi aktif, tombol primer, ubin dasbor |
| `admin-success` | `#3fb618` | Ubin penjualan |
| `admin-warning` | `#f9b115` | Ubin produk, avatar |
| `admin-danger` | `#dd5600` | Aksi destruktif, ubin pelanggan |
| `admin-navy` | `#324a73` | Ubin supplier |
| `admin-cyan` | `#23b7da` | Status dikirim |
| `admin-canvas` | `#f3f3f3` | Latar halaman |
| `admin-surface` | `#ffffff` | Kartu |
| `admin-tile` | `#333333` | Ubin statistik gelap |

Saturasi hanya muncul pada grid ubin dan chip status. Sisanya — formulir, tabel, teks — tetap pada skala netral, karena formulir administrator yang padat dalam enam warna yang saling bersaing lebih sulit dibaca daripada nilai ubinnya.

Nama-nama hue dipakai ulang oleh chip status sehingga keduanya terbaca sebagai satu sistem: `draft` netral, `pending` warning, `confirmed` primary, `shipped` cyan, `delivered` success, `cancelled` danger.

## Ikon ditulis tangan

`components/Icon.vue` memuat kira-kira dua puluh path SVG inline, bukan menarik paket ikon. Dua puluh glyph hanya pembulatan kecil pada bundle, paket ikon adalah dependensi yang lebih awet daripada keputusan desain yang membutuhkannya, dan setiap path di-stroke dengan `currentColor`, sehingga glyph yang sama mewarnai ulang dirinya sendiri dari apa pun tempatnya berada — begitulah satu set bekerja pada rel gelap maupun ubin putih.

## Rute berasal dari PHP

Rel dan grid ubin menaut melalui `routes.<key>`, peta yang dibagikan oleh `HandleInertiaRequests`. `route()` adalah helper Blade yang tidak bisa dijangkau komponen Vue, dan membangun ulang path di JavaScript akan menaruh setiap rute di dua berkas.

**Kunci-kunci di peta itu bukan nama rute.** `routes.sales`, bukan `routes['sales.index']`. Keduanya terlihat mirip dan tidak sama: pencarian berdasarkan nama rute mengembalikan `undefined`, dan `Link` milik Inertia merender href yang undefined sebagai *halaman tempat ia sudah berada*, sehingga seluruh navigasi terlihat diam, bukan rusak. Tanpa error, tanpa peringatan, tanpa pengujian yang gagal.

`InertiaRoutePropsTest` menegakkannya ke dua arah: setiap kunci yang dinamai komponen secara literal, setiap kunci yang dicari secara dinamis, dan setiap kunci yang dibagikan dibaca oleh sesuatu. Kasus dinamis adalah yang benar-benar menangkapnya — item sidebar adalah array data, sehingga pemindaian `routes.x` tidak menemukan apa pun di dalamnya sama sekali.

## Drawer

Di bawah `lg` rel menjadi overlay. Pada layar lebar ia adalah kolom statis di baris flex dan JavaScript sama sekali tidak terlibat.

Tiga detail yang bukan hiasan:

- Escape di-bind pada **window**, bukan pada rel. Di-bind secara lokal ia hanya menyala saat fokus kebetulan di dalam, dan rel tidak ada dalam urutan tab, sehingga pada ponsel drawer hanya bisa ditutup dengan mengetuk scrim.
- Rel membawa `tabindex="-1"`, yang membuatnya bisa difokuskan secara programatis tanpa menaruhnya dalam urutan tab. Membuka memindahkan fokus ke atasnya sehingga pembaca layar mengumumkannya; tanpanya `focus()` diam-diam diabaikan dan drawer terbuka dengan fokus masih di belakangnya.
- Membuka mengunci halaman di belakangnya, dan menutup mengembalikan fokus ke apa pun yang membukanya.

## Dasbor

`GET /dashboard` → `Inertia::render('Dashboard/Index', …)`. Setiap angka berasal dari `SalesSummary`, yang mengagregasi di SQL, bukan memuat baris untuk direduksi di PHP. Kontroler membentuk dan mengoper; ia tidak menghitung apa pun, sehingga dasbor dan daftar yang diklik dari sana tidak bisa berbeda.

| Prop | Dari |
| --- | --- |
| `headline` | `SalesSummary::headline()` — empat ubin statistik gelap |
| `monthly` | `SalesSummary::monthlySeries()` — grafik |
| `topCustomers`, `slowMovers` | dua panel di bawah grafik |
| `recentSales`, `lowStock` | dua panel terakhir |
| `statusCounts` | bar status bertumpuk |

### Mengapa seri ini punya metode kedua

`monthlyRevenue()` hanya melaporkan bulan-bulan yang punya penjualan, karena itulah bentuk jujur dari agregat berkelompok. Grafik bukan: sumbu yang melompati sebulan membuat kuartal yang sepi terlihat seperti lompatan. Jadi `monthlySeries()` mengisi kekosongan, dan aturan pengisian kekosongan berada di samping agregasi yang mengumpaninya, bukan di setiap grafik yang mungkin menginginkannya.

`DashboardTest` menegaskan keduanya setuju, sehingga grafik tidak akan pernah diam-diam bercerita beda dari angka di bawahnya.

### Mengapa Chart.js

Grafik templat adalah garis dua-seri yang dihaluskan dengan penanda pada setiap titik. Itu berarti penghalusan kurva, dua sumbu, gridline, dan tooltip — geometri sungguhan. Chart.js mengerjakannya dengan benar dan aplikasi pelaporan sudah bergantung pada stack yang sama.

Pendapatan dan jumlah transaksi berbeda tujuh orde besaran, sehingga keduanya mendapat sumbu terpisah. Pendapatan terbaca di kiri dalam rupiah, jumlah di kanan. Tidak ada seri yang bermakna bila dipipihkan ke atas yang lain.

### Ubin-ubin

Grid ubin dan sidebar mengatakan hal yang sama di dua tempat. Itu disengaja, bukan bug duplikasi: rel adalah daftar vertikal sempit untuk memindai dan ubin adalah target besar tempat seseorang mendarat. Dasbor yang hanya menampilkan rel akan membuat pembaca berburu apa pun yang belum mereka temukan.

## Daftar-daftar

`DirectoryController` menangani `/sales`, `/customers`, dan `/suppliers` dalam satu kelas karena ketiganya satu pekerjaan — membaca tabel dimensi. Memecahnya menjadi tiga kontroler akan menghasilkan tiga berkas yang hanya berbeda pada nama tabel dan kolom yang dipilih.

Filter berada di query string, bukan di state komponen, sehingga daftar yang terfilter bisa ditautkan, dimuat ulang, dan dinavigasi mundur. `ListSales` adalah aksi yang sama dengan yang dipakai listing produk, sehingga batas ukuran halaman dan aturan pengurutan tidak bisa menyimpang di antara keduanya.

## Catatan adalah rich text, dan itu membuatnya batas keamanan

`RichTextEditor.vue` adalah TipTap, dan keluarannya disimpan sebagai HTML. Halaman penjualan lalu merender HTML itu dengan `v-html` — dengan sengaja, karena tautan dalam catatan seharusnya menjadi tautan, bukan dinding kurung sudut yang di-escape.

Itu menjadikan catatan satu-satunya tempat di aplikasi ini di mana markup tak tepercaya menjadi markup yang dieksekusi peramban. Dua hal menjaganya aman, dan keduanya dibutuhkan:

**Editor bukan batasnya.** Pengguna yang berperilaku baik hanya bisa menghasilkan apa yang diizinkan toolbar, tetapi markup yang ditempel tiba dalam keadaan sudah terbentuk dan tidak pernah melewati tombol-tombol itu. Batasan sisi klien adalah kegunaan, bukan keamanan.

**`SanitiseRichText` adalah batasnya**, dan ia berjalan pada jalur *tulis*, di dalam `CreateSale`:

```
in :  <p>Catatan <strong>asli</strong></p><script>fetch("…"+document.cookie)</script>
      <img src=x onerror="alert(1)"><a href="javascript:alert(2)">klik</a>
      <iframe src="…"></iframe><svg onload=alert(3)></svg><p style="…">overlay</p>

out:  <p>Catatan <strong>asli</strong></p><img src="x" alt="x" /><a>klik</a><p>overlay</p>
```

Sanitasi saat tulis, bukan saat baca, adalah pilihan yang disengaja: biayanya beberapa milidetik sekali, dan nilai yang tersimpan kemudian sudah aman, sehingga setiap pembaca di masa depan aman secara bawaan — termasuk yang belum terpikirkan. Alternatifnya harus diingat di setiap situs render, dan satu yang terlupa adalah kerentanan, bukan error. Pertukarannya adalah pemurnian dua kali bersifat lossy, sehingga ini tidak boleh berjalan di atas konten yang sudah diketahui bersih pada setiap baca.

Gambar lewat `POST /admin/uploads`, bukan ditempel sebagai data URI, sehingga endpoint yang sama menegakkan tipe mime, 2 MB, dan nama berkas UUID — dan catatan tetap cukup kecil untuk disimpan. `RichTextEditor` membaca token CSRF dari kuki `XSRF-TOKEN`, yaitu header yang dikirim klien HTTP milik Laravel sendiri, sehingga tidak ada token yang ditaruh di halaman.

Berkasnya sendiri selalu ada: unggahan berhasil, tidak ada kode yang bisa menggagalkannya. Yang bisa gagal adalah penyajiannya. Disk `public` mengambil `url`-nya dari `APP_URL`, jadi catatan menyimpan `<img src="http://localhost:8000/storage/uploads/...">` — URL absolut yang ditulis permanen ke dalam HTML. Tiga hal harus benar bersamaan agar gambar itu muncul: symlink `public/storage` ada (`php artisan storage:link`, lihat [01. Menjalankan proyek](01-running-it.md)), `APP_URL` sesuai dengan cara Anda membuka aplikasi, dan berkasnya tidak hilang bersama container. Di produksi `IMAGES_DISK=s3` mengganti ketiganya dengan satu: Supabase yang menalus URL-nya sendiri.

## Antarmuka berbahasa Indonesia, dan hanya Indonesia

`APP_LOCALE=id`, dan `APP_FALLBACK_LOCALE=id`.

Fallback adalah bagian yang penting. Dengan Inggris sebagai fallback, kunci apa pun yang hilang dari `lang/id` teresolusi ke Inggris yang fasih — formulir yang memvalidasi dengan benar lalu melaporkan masalah dalam bahasa yang tidak dituturkan pembacanya, yang terlihat seperti bukan bug sama sekali dari dalam halaman Indonesia. Dengan Indonesia sebagai fallback-nya sendiri, kunci yang hilang dirender sebagai kunci, yang nyaring saat review dan dalam pengujian yang menegaskan pesan.

`lang/en` tidak ada, dan `IndonesianLocaleTest` menegaskan bahwa ia tidak ada — ia hanya sejauh `php artisan lang:publish` untuk kembali, dan memilikinya kembali akan diam-diam memulihkan setiap fallback yang justru ingin dicegah konfigurasi ini.

`lang/id` memuat keempat berkas kamus yang dijangkau framework: `validation`, `auth`, `pagination`, dan `passwords`. `passwords` ada di sana meski alur reset belum ada, karena broker memunculkan kunci-kunci itu begitu rute ditambahkan dan baris Inggris yang muncul dalam formulir Indonesia akan menjadi hal pertama yang disadari siapa pun tentangnya.

Beberapa hal yang mudah terlewat dan semuanya diperiksa:

- **Nama atribut diterjemahkan juga.** Tanpanya pesan terbaca "Kolom price wajib diisi" — bahasa Indonesia dengan kata Inggris tertinggal di tengah.
- **`SaleStatus::label()` berbahasa Indonesia; nilai backing tidak.** Label adalah yang dibaca orang; `'delivered'` adalah yang disimpan, yang dicocokkan migrasi, dan yang dikembalikan API. Menerjemahkan nilai yang tersimpan akan membuat keduanya menyimpang. Opsi filter penjualan dibangun dari enum untuk alasan yang sama — dulu diduplikasi di komponen Vue, sehingga filter bisa menawarkan satu label sementara tabel di bawahnya merender label lain untuk baris yang sama.
- **Tanggal memakai `translatedFormat()`, bukan `format()`.** `format('d M Y')` mengabaikan locale dan mencetak "12 Oct 2026"; `translatedFormat()` mencetak "12 Okt 2026".
- **`SalesSummary::MONTH_LABELS` adalah array lokal dengan sengaja** meski `translatedFormat()` akan berfungsi sekarang. Ia adalah sumbu grafik dua belas elemen yang tetap tanpa hari maupun waktu di atasnya.

## Shell tahu jenis akun apa yang dilihatnya

`auth.user.is_admin` tiba sebagai prop bersama, sehingga sidebar, topbar, dan gelembung asisten semuanya bisa setuju tanpa masing-masing menjangkau ke props halaman.

Untuk akun biasa navigasi meja difilter keluar dan menu buat-cepat serta gelembung asisten tidak dirender. Itu presentasi, bukan keamanan: menyembunyikan tautan yang toh akan ditolak middleware `admin` adalah kesopanan, dan `403` adalah batasnya. `DeskAuthorizationTest` menegaskan penolakan-penolakan itu; tidak ada di sini yang boleh dibaca sebagai hal yang menegakkannya.

## Kontrol mati dibuang, bukan dinonaktifkan

Topbar membawa tombol pencarian global, ikon notifikasi, dan ikon pesan. Tidak ada yang punya query, rute, atau handler di belakangnya: tombol pencarian mengaktifkan flag yang tidak dibaca panel mana pun, dan dua lainnya adalah span.

Mereka dibuang, bukan dinonaktifkan. Kontrol yang terlihat hidup dan tidak berbuat apa pun membebani pembaca satu klik untuk menemukan bahwa fitur yang ditunjukkan tidak ada, dan ikon yang digreykan menyiratkan "tidak tersedia saat ini", bukan "tidak dibangun".

Menu buat-cepat menempuh arah sebaliknya — ia *adalah* kontrol yang punya tujuan, dan tujuannya salah. Ia menawarkan Pelanggan dan Penjualan, yang keduanya membuka halaman listing, bukan formulir, sehingga menu berlabel "buat" mengirim Anda untuk membaca. Kini ia hanya mencantumkan dua rute pembuatan yang ada.

## Menguji lapisan ini

Tidak ada JavaScript test runner di proyek ini, dan menambahkannya adalah keputusan yang lebih besar daripada yang layak untuk pemeriksaan-pemeriksaan ini. Penggantinya:

| Pengujian | Yang dijaga |
| --- | --- |
| `PageLayoutTest` | Setiap halaman mendeklarasikan layout, dan referensinya cocok dengan impor |
| `InertiaRoutePropsTest` | Kunci rute ada ke dua arah, termasuk di dalam array data |
| `DashboardTest` | Bentuk props, seri tanpa celah, setiap status hadir, sudah diinisialisasi vs belum |
| `SalesDeskSeedTest` | Angka-angka, dipatok ke fixture proyek pelaporan |
| `SanitiseRichTextTest` | Dua belas vektor melalui purifier, termasuk `jav&#x09;ascript:` yang diobfuskasi itu |
| `IndonesianLocaleTest` | Locale dan fallback-nya, tanpa kamus Inggris, dan pesan representatif di setiap berkas |
| `DeskAuthorizationTest` | Setiap rute meja ditolak untuk akun biasa dan terbuka untuk administrator |
| `CredentialValidationTest` | Kebijakan email dan kata sandi melalui pintu-pintu asli, termasuk pemeriksaan breach |
| `AdminEnsureCommandTest` | Apa yang dibuat perintah, ditolak, dan tidak pernah dituliskan |

Kekosongannya nyata dan layak dinamai: tidak ada di sini yang membuktikan komponen *ter-render* dengan benar, bahwa nama kelas ada di CSS yang ter-build, atau bahwa grafik tergambar. Itu diperiksa dengan memuat halaman di peramban headless dan membaca DOM, dan perubahan palet atau shell di masa depan harus melakukan hal yang sama. Suite Playwright akan menutup kekosongan itu dengan benar.
