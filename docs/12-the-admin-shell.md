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

Rentang tanggal di `/sales` ikut tinggal di query string sebagai `from` dan `to`, di atas `sold_at` — kolom yang sudah punya indeks gabungan `[sold_at, status]`, jadi menyaring rentang sekaligus status tidak menambah beban baru. Kedua ujungnya boleh kosong: "sejak awal bulan" dan "sampai hari ini" adalah rentang seterbuka satu sisi, dan itu yang biasanya diminta.

Di layar, rentangnya dipilih lewat **satu kontrol** (`DateRangePicker.vue`) yang membuka kalender — bukan dua kotak tanggal berdampingan. Dua kotak terbaca sebagai dua filter yang tidak berhubungan, dan untuk tahu rentang mana yang sedang berlaku orangnya harus membandingkan keduanya sendiri; di sini rentangnya digambar di kalender dan dinamai di tombolnya, termasuk saat hanya satu ujungnya terisi. Kalendernya dari reka-ui, primitif yang sama dengan pillbox tag, sehingga grid bulan, penyorotan rentang, navigasi keyboard, dan semantik kalender untuk pembaca layar tidak ditulis sendiri.

Di kepala kalender ada **caption yang bisa diklik**, dan itu sekaligus satu-satunya kontrol untuk berpindah jauh: panah maju-mundur hanya berguna untuk bulan sebelah dan tidak untuk bulan setahun lalu. Ditekan sekali, gridnya berganti dari hari ke dua belas bulan; sekali lagi jadi sepuluh tahun dalam dekade itu; sekali lagi jadi dekade-dekade dalam abad itu — dan memilih membawa kembali ke tingkat yang sedang dipilih (tahun → bulannya, bulan → harinya). Panahnya ikut berganti satuan mengikuti tingkat, jadi satu gerakan berarti hal yang sama di kedalaman mana pun. Yang ditampilkan selalu *bulan yang sedang dilihat*, bukan rentangnya: menelusuri kalender tidak boleh menjadi cara mengubah filter tanpa sengaja.

Bulan yang ditampilkan disimpan sebagai **hari pertama**, bukan tanggal apa adanya — reka membulatkan alih-alih meluber, jadi melangkah satu bulan dari 31 Januari akan mendarat di tanggal 28, dan dari sana grid membuka bulan yang ujungnya sudah diam-diam tertarik masuk.

Nilai yang menyeberangi batas komponen itu tetap string `Y-m-d`, bukan objek `CalendarDate`. Konversinya terjadi di dalam komponen, jadi halaman tetap memegang nilai biasa yang bisa langsung masuk query string tanpa langkah serialisasi yang mengejutkan pembaca berikutnya.

Satu jebakan yang harus diingat kalau menyentuh berkas ini: **`DateFormatter.format()` hanya menerima `Date`.** Ia meneruskan ke `Intl.DateTimeFormat`, dan diberi `CalendarDate` — yang justru dipegang komponen ini — ia melempar `RangeError: Invalid time value` dari dalam render. Gejalanya menyesatkan: sel kalender tetap menyorot (reka merender selnya sendiri, memakai helper `toDate()` internal), sementara tombolnya membeku dan popover tidak pernah menutup. Karena itu semuanya lewat `parseDate(...).toDate(getLocalTimeZone())`, dan zona waktunya diberikan ke `toDate()` supaya tengah malam lokal adalah hari yang diklik.

**Satu klik berarti hari itu**, bukan "sejak hari itu": nilai yang naik ke halaman menyetel kedua ujungnya ke tanggal itu, jadi filternya satu hari dan tombolnya menyebut satu tanggal. Yang membuat itu tidak mengunci orang pada satu hari adalah pemetaan arah sebaliknya — satu tanggal turun ke kalender sebagai awal *tanpa* akhir, karena reka memperlakukan rentang yang kedua ujungnya terisi sebagai selesai, sehingga klik berikutnya akan memulai rentang baru alih-alih melanjutkan yang sedang tampil. Klik kedua melengkapi rentangnya, dan yang membedakan keduanya hanya pemetaan itu.

Satu asimetri yang tersisa: "sampai X" tanpa awal tetap tidak punya gestur di kalender — parameter `to` di URL tetap menerimanya, karena itu bentuk yang datang dari tautan, bukan dari kalender.

Satu detail yang menentukan bentuk kodenya: **tanggal penutup berarti sehari penuh.** `sold_at` itu timestamp, jadi `sold_at <= '2026-03-01'` hanya menyisakan detik pertama hari itu dan membuang seluruh hari penutup rentang — tanpa jejak di layar, karena hasilnya tampak seperti bulan yang memang sepi. Perbandingannya karena itu dilakukan terhadap tengah malam hari berikutnya, yang juga tidak berasumsi apa pun soal presisi kolom (Postgres menyimpan timestamp sampai mikrodetik).

Pasangan yang terbalik diurutkan, bukan dijadikan hasil kosong: hasil kosong adalah satu-satunya jawaban yang salah di situ, karena tidak bisa dibedakan dari periode tanpa penjualan. Pasangan yang sudah urut ikut dilaporkan sebagai nilai filter, sehingga tombolnya membetulkan dirinya sendiri. Nilai yang bukan tanggal diabaikan, sementara `2026-3-1` — yang orang mungkin ketik langsung di address bar — dinormalkan ke bentuk yang dipakai aplikasi di tempat lain.

## Daftar yang bisa ditambah sendiri

Kota, tim sales, dan tag sebelumnya hanya bisa diisi oleh `SalesDeskSeeder` — itu satu-satunya sumbernya, dan tidak ada satu pun tempat di aplikasi yang menulis ke ketiga tabel itu. Kekurangannya tidak hanya terasa di layar: `ImportSales::lookup()` menolak nama yang belum terdaftar, jadi mengimpor spreadsheet yang memuat kota atau sales baru gagal dengan `"Jakarta" tidak ada di daftar.` Menambah vocabularinya sendiri adalah prasyarat impor, bukan fitur sampingan.

Ketiganya satu fitur, bukan tiga. Masing-masing punya dua sampai empat kolom dan tidak satu pun yang layak mendapat tabelnya sendiri, jadi semuanya dilayani satu `ReferenceController`, dua form request, dan dua halaman Vue yang digerakkan `App\Support\ReferenceRegistry` — peta yang menjelaskan tiap daftar: kolomnya, labelnya, aturan validasinya, field uniknya, dan apa saja yang masih mereferensikannya. Daftar ketujuh berarti satu entri di sana, bukan berkas baru di enam tempat.

**Peta itu adalah batasnya, bukan konvensinya.** `{reference}` datang dari URL, sehingga mengubahnya menjadi nama tabel adalah tepat tempatnya aplikasi yang ceroboh membiarkan request memilih sendiri target query-nya. `ReferenceRegistry::get()` hanya mengenal tiga kunci itu; sisanya 404. Kuncinya tidak pernah sampai ke query builder sebagai nama tabel dan kelas modelnya diambil dari entri yang ditemukan, bukan dari URL — sehingga `/references/users` menjawab 404 dan tidak pernah menjadi jalan menulis akun, dan `/references/sales` — tabel yang ada, hanya bukan referensi — 404 juga.

Halaman daftarnya membaca deskripsi dari server dan tidak tahu tabel mana yang sedang dilihat; tombol "Tambah" mengarah ke `createUrl` yang ikut dikirim, bukan ke path yang dirangkai di komponen. Relasi navigasinya ditambahkan ke peta bersama di `HandleInertiaRequests` dengan mengiterasi registry, jadi mendaftarkan daftar baru tidak bisa lupa di salah satu dari dua tempat.

Dua hal yang menentukan bentuk tulisnya. **"Opsional" tidak berarti "boleh null".** `commission_rate` dan `colour` NOT NULL dengan default, jadi kolom yang dikosongkan tidak boleh disimpan sebagai null — ia menyimpan default kolomnya sendiri. Lapisan yang sama mengubah string kosong menjadi null, karena `nullable` menerima `""` dan kolomnya juga menerimanya; tanpa itu "tidak ada provinsi" akan tersimpan sebagai provinsi yang isinya string kosong, dan dua hal berbeda terlihat sama.

**Penghapusan dijaga, bukan diserahkan ke database.** `sales` memegang customer, salesperson, dan kota dengan `RESTRICT`, jadi menghapus salah satunya tanpa penjaga adalah pelanggaran foreign key yang muncul sebagai 500 tanpa kalimat apa pun di dalamnya. Tag dan supplier justru `CASCADE` — lebih berbahaya justru karena delete-nya terlihat berhasil sambil diam-diam melepas label dari penjualan lama dan harga beli dari produk. Karena itu ketiganya ditolak selama masih direferensikan, dan alasannya ditulis di baris yang ditolaknya, bukan di halaman lain.

Pola yang sama berlaku untuk endpoint direktori baru. `DELETE /api/customers/{id}` menolak 422 selama pelanggan punya penjualan — `sales.customer_id` `RESTRICT`, jadi menghilangkannya akan jadi 500. `DELETE /api/suppliers/{id}` menolak selama supplier masih memasok produk, dan itu kasus yang lebih berbahaya: `product_supplier` `CASCADE`, jadi hapus akan **berhasil** sambil mencabut harga dan lead time yang sudah disepakati dari setiap produk yang diasoksi supplier itu. Penulisan ulang sejarah pembelian, tanpa laporan ke siapa pun.
## Galeri gambar produk

Produk sudah punya tabel `product_images` sejak skema sales desk dibuat — `path`, `alt_text`, `sort_order`, cascade, indeks `(product_id, sort_order)` — tetapi tidak ada apa pun yang menulis ke sana sampai form produk mendapat galerinya. Yang ditambahkan hanya jalur tulis dan tampilannya; tabelnya sendiri tidak berubah.

Gambar ikut **payload produk**, seperti tag ikut payload penjualan: form itu berarti "produk ini beserta gambarnya", dan memecahnya menjadi dua request akan membuat satu kali Simpan punya dua tempat untuk gagal. `SyncProductImages` mengganti seluruh galeri dengan daftar yang dikirim — daftar berurutan, dan indeksnya menjadi `sort_order` — sehingga menghapus gambar di layar benar-benar menghapus barisnya, bukan sekadar berhenti menambah.

Bedanya dengan gambar catatan penjualan cuma satu, dan itu yang membuat tabel ini layak: **berkasnya ikut dihapus**. Namanya UUID yang dibuat server, jadi satu baris memiliki satu berkas dan tidak ada rujukan lain yang bisa menunjuk ke sana. Gambar catatan adalah HTML yang ditulis orang, di mana sebuah URL adalah rujukan yang tidak bisa dihitung — itulah kenapa menghapus penjualan meninggalkan berkasnya di disk.

Di browser, berkas diunggah begitu dipilih, bukan saat Simpan: endpoint unggah mengembalikan URL yang dibutuhkan thumbnail, dan galeri yang tidak bisa dilihat bukan galeri. Urutan digeser dengan tombol naik/turun, bukan drag — drag butuh library, dan aplikasi ini belum punya drag-and-drop di mana pun.

**Gambar utama adalah baris pertama, dan itu fakta tentang daftar bukan flag pada baris.** Galeri adalah sebuah urutan dan katalog menampilkan kepalanya, jadi memilih gambar utama baru adalah operasi yang sama dengan memindahkan baris ke atas. Karena itu tombolnya berbunyi "Jadikan utama" dan melakukan pemindahan, bukan menyalakan sakelar yang menyimpan sesuatu yang memang tidak disimpan. Kolom `is_primary` akan menjadi satu sumber kebenaran kedua yang bisa tidaksinkron dengan `sort_order` — dan tidak sinkronnya tidak akan terlihat dari mana pun.

**Berkasnya ikut terhapus ketika produknya dihapus.** Barisnya memang sudah hilang sendiri karena `product_images.product_id` ber-`CASCADE`, tapi tidak ada skema yang bisa menghapus berkas, jadi tanpa itu setiap produk yang dihapus meninggalkan salinan gambarnya selamanya: tak terlihat, tak terbatas, dan tetap ditagih. `DeleteProduct` membaca daftar jalur **sebelum** hapus — sesudahnya baris dan jalannya sudah pergi — lalu menghapus berkasnya. Dan ini ACTION, bukan event model, karena ada dua pintu yang menghapus produk (browser dan API) dan yang biasanya terlupakan adalah pintu kedua.

## Menambah pelanggan dan supplier

Keduanya boleh ditambahkan, dan keduanya pernah hanya bisa diubah. Yang tidak pernah ada adalah jalannya.

**Satu form, dua tabel.** `customers` dan `customer_profiles` sengaja terpisah karena umurnya berbeda: pelanggan ada sejak pesanan pertama, profilnya ditulis saat meja memutuskan hubungan itu layak. Tapi form Tambah tetap satu, karena orang yang sedang mengetik pelanggan baru punya nama, kota, dan syarat bayarannya di depan mata sekaligus, dan meminta mereka menyimpan sekali lalu mengisi profil di langkah kedua adalah cara memastikan ada pelanggan di pembukuan tanpa ada yang pernah memastikan berapa boleh ia utang. Jadi satu request menulis dua baris (`StoreCustomerRequest`), dan `CustomerProfileValidation` adalah satu definisi lima field itu untuk **tiga** pintu yang menuliskannya — profil di form Ubah, form Tambah, dan dua endpoint API.

Field-nya sendiri tidak ditulis dua kali: `CustomerFields` dan `CustomerProfileFields` dipakai oleh form Tambah dan form Ubah. Yang membedakan keduanya cuma endpoint yang dipakai, dan menduplikasi 140 baris markup demi perpetusan perbedaan itu adalah cara memastikan labelnya pindah di satu tempat sementara kunci errornya tertinggal di tempat lain.

**"Kosong" bukan berarti null, dan ini dulu adalah 500.** `credit_limit` dan `payment_terms` adalah `NOT NULL` dengan bawaan (`0` dan `COD`), sementara `ConvertEmptyStringsToNull` mengubah kotak kosong menjadi null — jadi menyimpan profil dengan limit dikosongkan menulis NULL eksplisit dan jawabannya `SQLSTATE[23000] ... NOT NULL constraint failed`, bukan pesan validasi yang bisa dibaca orang. Field yang ada tapi kosong berarti pakai bawaan kolomnya; field yang tidak dikirim sama sekali berarti tidak tersentuh. Perbedaan itu yang membedakan PATCH dari PUT, dan ia diuji untuk keduanya.

Form Tambah memakai **satu** `useForm` untuk kedua bagian, bukan satu per bagian. Dua form berarti dua error bag, dan error profil akan milik objek yang tidak pernah dikirim — jadi kode `credit_limit` yang ditolak muncul di mana pun kecuali di sebelah kotak limit kredit.

**API-nya lebih luas dari yang perlu**, dan itu mengikuti UI: browser bisa menambah pelanggan dan supplier, jadi API yang hanya bisa membaca produk akan jadi gambaran kedua yang lebih sempit tentang meja yang sama. Yang tidak ikut adalah `DELETE` tanpa penjaga — lihat catatan penghapusan di atas.

## Impor penjualan: dibaca dulu, diperiksa, baru disimpan

`/sales/import` punya tiga langkah, dan urutannya yang menentukan bentuk fitur ini. Berkas diunggah dan dibaca menjadi **draf**; draf ditampilkan untuk diperiksa dan dikoreksi; hanya draf yang dikonfirmasi yang ditulis. Langkah tengah itu bukan hiasan — ia satu-satunya hal yang berdiri di antara pembacaan sebuah dokumen dan buku besar.

**Dua jalur membaca, satu validasi.** Berkas yang bertabel — `.txt` berdelimiter atau `.xlsx` — dibaca persis: kolomnya dicocokkan berdasarkan nama dan tidak ada yang ditafsirkan. Selain itu, teksnya diekstrak secara lokal (`ReadSalesDocument`: ZIP untuk `.docx`/`.xlsx`, `smalot/pdfparser` untuk `.pdf`) lalu diserahkan ke model (`SalesDocumentExtractor`, Gemini lewat `config/ai.php`), yang mengembalikan draf baris. Apa pun jalurnya, baris yang tiba divalidasi kode yang sama, sehingga nama yang tidak ada di katalog ditolak entah ia dibaca dari spreadsheet atau ditebak model. Endpoint pembacaan dibatasi `throttle:imports` (10 per menit per akun), karena ia memanggil API berbayar.

**Tidak ada yang ditulis sebelum dikonfirmasi.** Draf tidak disimpan di server — ia bolak-balik sebagai props Inertia, dan endpoint konfirmasi memvalidasi ulang semuanya. Konsekuensinya halaman periksa adalah respons POST, jadi URL-nya tidak bisa dilayani GET: memuat ulang halaman itu (F5, bookmark, atau maju dari riwayat) dialihkan kembali ke form dengan pesan yang menjelaskan bahwa drafnya tidak disimpan, bukan dengan 405. Membaca ulang dokumennya satu unggahan lagi, dan kedua kalinya itu pun tidak menulis apa pun.

Aturan lama tetap berlaku dan tinggal di `ImportSales`, dipakai kedua jalur. Seluruh draf diperiksa **sepenuhnya lebih dulu**; penulisan baru terjadi setelah tidak ada satu pun baris yang bermasalah, dan semuanya berjalan dalam satu transaksi. Impor setengah jadi adalah hasil terburuk yang mungkin di sini — penjualan adalah invoice, ia menghitung pendapatan, dan dasbor mereconciliasi terhadap itemnya. Lima puluh baris dari dua ratus bukan lima puluh penjualan yang benar, melainkan berkas yang bilang dua ratus dan buku besar yang bilang lima puluh, tanpa apa pun di layar yang menentukan yang mana benar.

Karena itu satu nama yang tidak dikenal menolak seluruh draf dan melaporkan **setiap** masalahnya sekaligus. Kalau baru ketahuan setelah dokumen diperbaiki dan diunggah ulang, orangnya mengoreksi satu baris, mengunggah, dan menemukan baris berikutnya — satu putaran untuk setiap kesalahan.

Nama dicocokkan ke database dan tidak pernah ditebak. "Yamaha" adalah lima produk di katalog ini, harganya dari 900 ribu sampai 33 juta; memilih satu secara diam-diam menulis invoice yang tidak pernah disepakati. Nol cocok dan lebih dari satu cocok sama-sama ditolak, dan yang kedua menyebut kandidat yang bertabrakan. Pola angka ribuan juga ditolak **secara bernama** (`15.000`), karena "harga bukan angka" itu benar sekaligus tidak berguna: angkanya tampak bagus sekali bagi orang yang mengetiknya, dan aturan yang sebenarnya dilanggar adalah dokumen ini tidak memakai pemisah ribuan.

Kontrak kolomnya tinggal di `config/imports.php`, dan halaman mengaturnya dari sana. Kolom yang tidak dikenal membuat berkas gagal jadi tabel — dan karena itu ia jatuh ke jalur dokumen, bukan ditolak di pintu masuk. Itu satu-satunya hal yang hilang bersama CSV, dan bagian berikutnya menjelaskan apa penggantinya.

Setiap penjualan hasil impor membawa catatan yang menyebut asalnya — `Diimpor dari berkas tabel.` atau `Diimpor dari dokumen (draf AI).` Penulisan massal adalah satu-satunya kasus penjualan ada tanpa ada yang mengetiknya, dan catatan adalah satu-satunya tempat di record yang mengatakan hal itu.

Pemilih status tidak ada di sini, dan itu disengaja: form penjualan manual mengirim status kosong dan membiarkan `CreateSale` memakai default `Pending`, jadi penjualan hasil impor tiba di status yang sama dan bergerak lewat form status yang sama. Menawarkan status hanya di layar impor membuatnya menjadi satu-satunya jalan membuat penjualan pada keadaan yang tidak bisa dicapai form — bukan keputusan yang layak diambil layar impor sendiri.

### Yang menghilang bersama CSV, dan penggantinya

Kontrak header dulu menolak apa pun yang bukan daftar nota, dan itulah yang menghilang saat CSV dibuang. Berkas rekap agregat adalah kasus yang paling penting: setiap barisnya punya nama sales, kota dan produk, jadi ia bisa dipetakan baris demi baris menjadi sesuatu yang tampak seperti penjualan — dengan rata-rata disimpan sebagai harga satuan dan total sebagai jumlah. Tidak ada lagi kolom yang bisa menangkap itu.

Penggantinya dua lapis. Pertama, instruksi agent mewajibkan menolak dokumen yang bukan daftar transaksi dan mengembalikan daftar kosong; endpoint pembacaan lalu menjawab bahwa tidak ada baris yang dikenali, dan tidak ada yang ditulis. Kedua, halaman periksa — dan lapis kedua itulah yang membuat pembacaan oleh model layak dipercaya sama sekali.

### Berkas contoh

`docs/samples/` berisi berkas yang bisa langsung dicoba di `/sales/import`:

| Berkas | Isi |
|---|---|
| `contoh-tabel.txt` | Empat baris, pemisah koma. Dibaca sebagai tabel, tanpa AI. |
| `contoh-tabel-titik-koma.txt` | Tiga baris, pemisah titik koma, koma desimal (`175000,50`). |
| `contoh-ditolak-nama-tidak-dikenal.txt` | Dua baris baik dan satu nama produk yang tidak ada. |
| `contoh-ditolak-laporan-agregat.txt` | Bentuk laporan ringkasan, bukan daftar nota. |

Dua yang terakhir sengaja ditolak, tetapi ditolak di langkah yang berbeda — dan perbedaan itu bagian yang menarik. Berkas dengan satu nama asing tetap terbaca sebagai tabel, lalu ditolak saat drafnya dikonfirmasi. Laporan agregat bukan tabel sama sekali, jadi ia sampai ke model dan ditolak saat dibaca. Keduanya berakhir sama: seluruh berkas ditolak, termasuk baris yang tidak bermasalah, karena setengah impor lebih buruk daripada tidak sama sekali.

`SaleImportSampleFilesTest` menjalankan keempat berkas itu lewat endpoint sungguhan dan menjaga nama-nama di dalamnya tetap cocok dengan katalog. Berkas contoh yang diam-diam rusak lebih buruk daripada tidak ada: orang menyalinnya, dan hal pertama yang dipelajarinya adalah aplikasi ini menolak berkas yang didokumentasikan sah.

### Yang sengaja ditolak

**PDF hasil scan tidak didukung.** Scan adalah gambar di dalam PDF: tidak ada lapisan teks di dalamnya, dan mengeluarkan teksnya butuh OCR, yang butuh biner yang tidak ada di image deploy. Aplikasi menyebutkannya daripada mengembalikan draf kosong yang tidak bisa dibedakan orang dari draf yang rusak. Ekstensi alaminya adalah mengirim berkasnya langsung ke model — `laravel/ai` sudah mendukung lampiran — dan itu belum dikerjakan.

**`.doc` dan `.xls` ditolak** dari byte-nya (header berkas gabungan OLE2), dan pesannya menyuruh menyimpan ulang sebagai `.docx` atau `.xlsx`. **`.csv` tidak ditolak berdasarkan namanya**, dan itu perlu penjelasan: `libmagic` memutuskan sebuah berkas teks adalah `text/csv` dari isinya, jadi setiap `.txt` berdelimiter koma tiba dengan tipe itu juga — dan berkas yang isinya memang tabel dibaca sebagai tabel, apa pun namanya. Ekstensi dipilih oleh yang mengunggah, jadi ia petunjuk, bukan gerbang; `ReadSalesDocument` yang memutuskan, dan ia memutuskan dari byte-nya. Yang dihapus bersama CSV adalah berkas contoh, dokumentasinya, dan janji bahwa kolomnya akan dicocokkan di pintu masuk — bukan baris teks yang kebetulan dipisah koma.

**Pelanggan tetap satu per berkas**, bukan per baris: kolomnya tidak ada di dalam berkas, jadi ia ditanyakan di form dan bisa dikoreksi di layar periksa. **Dokumennya tidak disimpan** — dibaca, jadi draf, lalu dilepas; tidak ada tabel draf dan tidak ada berkas arsip. **Tanpa queue** juga disengaja: image deploy memakai `QUEUE_CONNECTION=sync`, jadi pembacaan dan panggilan model berjalan di dalam request, dijaga batas `imports.max_rows` dan `imports.text_limit`. Mengelompokkan beberapa baris menjadi satu nota bukan usaha di sini; itu tugas form manual, dan setiap baris adalah penjualan tersendiri.

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

## Akun biasa adalah pembaca, dan ini keputusan dua sisi

Dulu satu aturan: **seluruh meja hanya untuk administrator.** Akun biasa — yang lahir
dari `/register` yang terbuka untuk siapa pun — praktis tidak melakukan apa-apa. Itu
memang benar saat itu, dan bukan kebetulan: katalog dan daftar referensi sudah ada, yang belum ada adalah
**jalur tulisnya**, sehingga satu-satunya cara mengisi tabel `cities`, `salespeople`,
dan `tags` adalah `SalesDeskSeeder`. Akun yang tidak bisa melakukan apa-apa juga tidak
memang terlalu mahal untuk dibiarkan tidak terlihat, sampai katalognya bisa dibaca.

Sekarang batasnya dipindah: `/products`, `/customers`, `/suppliers`, dan
`/references/{key}` terbuka untuk setiap akun yang sudah masuk. Tulisannya tetap
administrator. Yang tidak ikut terbuka adalah `/dashboard` dan `/sales`, karena
keduanya membawa uang — pendapatan, pelanggan teratas, dan seluruh buku besar.

**Dua sisinya adalah satu keputusan, bukan dua.** pendaftaran ditutup pada saat yang
sama, dan itu yang membuatnya aman. Pendaftaran yang terbuka berarti setiap orang yang
mendaftar membaca katalog beserta harga dan stoknya, limit kredit dan syarat bayar
setiap pelanggan, dan ketiga daftar referensi — jadi "menjaga read" tanpa "menutup
pendaftaran" bukan kebijakan yang lebih ketat, melainkan tidak bermakna. `./register` pindah
ke dalam grup `admin`; `POST /api/account` ikut, karena mengembalikan token Sanctum
dan meninggalkan pintu itu terbuka hanya membuat batas web terasa ada.

Akibat yang tidak enak dan sebaiknya disebut terang-terangan: **Swagger UI tidak lagi
bisa memulai diri sendiri.** Endpoint yang biasa dipakai mendaftarkan akun dari nol
sekarang justru butuh token administrator, jadi menguji API butuh akun yang sudah ada.
Itu benar secara keamanan, dan itu tetap hilangnya kemampuan, bukan kemenangan gratis.

Form daftarnya sendiri tidak berubah — hanya pintunya. `RegisterAccountRequest`,
pengecekan breach, hashing kata sandi, dan pengembalian isian setelah gagal semuanya
sudah ada dan sudah diuji; yang berubah adalah siapa yang boleh mengetuknya, dan
siapa yang masuk sesudahnya. Dulu orang yang mengisi formulir itu *menjadi* akunnya.
Sekarang orang itu administrator yang menyerahkan kata sandi kepada orang lain, dan
memasukkan orang itu sebagai akun barunya akan mengeluarkan administrator dari
sesinya sendiri demi melakukan hal yang tidak diminta. Akun baru masuk sendiri dari
halaman Masuk.

Tombol yang tidak bisa dipakai untuk sebuah akun **dibuang, bukan dinonaktifkan** —
kontrol yang terlihat hidup dan tidak berbuat apa pun membebani pembaca satu klik
untuk menemukan bahwa fitur yang ditunjukkan tidak ada, dan ikon yang digreykan
menyiratkan "tidak tersedia saat ini", bukan "tidak dibangun". Jadi halaman yang bisa
dibaca tetapi tidak bisa ditulis mengirim `can.write` dari server, dan tombol Tambah,
Ubah, dan Hapus menghilang begitu juga — kolom Aksinya ikut hilang, karena kolom kosong
terbaca sebagai data yang hilang, bukan sebagai akun yang tidak boleh menulis.

Yang menentukan batasnya tetap middleware, bukan `v-if`. Membunyikan tombol adalah
kehatan sopan; kalau ada pembaca yang menemukan URL yang tidak ditawarkan templat,
`EnsureAdmin` tetap menjawab 403 — dan itu diuji per verb di
`tests/Feature/DeskAuthorizationTest.php`.

Di rail, bedanya adalah dua flag: `readable` untuk yang boleh dibuka setiap akun, dan
`adminOnly` untuk yang juga butuh administrator. Yang tidak memakai keduanya terbuka
untuk semua, itulah halaman akun. Filternya hanya menyaring `adminOnly`, jadi item
`readable` tidak perlu penanganan apa pun di situ — ia menandai maksudnya di item-nya
sendiri. Dan sebuah kelompok yang seluruh isinya terfilter tetap dibuang, supaya
akun biasa tidak melihat judul "Utama" dengan apa pun di bawahnya; itu yang dulunya
membuat mereka melihat satu butir menu saja.

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
