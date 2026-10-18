# Panduan Evaluasi untuk Naskah

Cara menjalankan evaluasi hold-out pada proyek ini, dan angka apa yang boleh
dikutip. Angka di dokumen ini dihitung dari korpus studi kasus yang sebenarnya:
6 lembaga, 8.393 komentar YouTube.

---

## 1. Mengapa halaman Training tidak boleh dikutip

Halaman `/admin/training` punya grafik "Perkembangan pada Data Koreksi". Grafik
itu dihitung dari `training_items` yang sudah dikoreksi — **baris yang persis
sama dengan yang dikirim ke pelatihan ulang**. Setelah model dilatih pada baris
itu, akurasinya pada baris itu pasti naik, sebagian karena model menghafal data
latihnya sendiri.

Grafik itu berguna untuk memantau proses koreksi. Sebagai bukti kinerja model,
ia tidak sah, dan penguji yang teliti akan menanyakannya.

Yang sah adalah **golden dataset**: teks berlabel manusia yang dibekukan sebelum
active learning dimulai, tidak pernah ikut dilatih, dan diukur ulang pada setiap
iterasi. Itu yang dikerjakan halaman `/admin/evaluation`.

Sistem menjaga pemisahan ini di tiga tempat, bukan lewat disiplin saja:

- `RetrainingService::correctedItems()` menyingkirkan koreksi yang teksnya ada
  di golden dataset **beku** dari muatan pelatihan.
- Halaman koreksi **menyembunyikan** baris yang ada di golden dataset mana pun —
  termasuk yang masih dianotasi — jadi tenaga tidak terbuang pada baris yang
  hasilnya toh dibuang.
- Penyimpanan koreksi menolak baris uji, walau permintaannya dibuat langsung.

Cakupan yang berbeda itu disengaja. Untuk **menahan data latih**, hanya dataset
beku yang mengikat: membuang koreksi yang sah karena ada berkas percobaan
merugikan pelatihan. Untuk **menyembunyikan dari halaman koreksi**, begitu
sebuah baris terpilih menjadi kandidat uji ia tidak ditawarkan lagi — kalau
tidak, ada jendela waktu ketika baris yang sedang dianotasi juga dikoreksi di
halaman lain.

**Baris golden tidak dihapus dari hasil analisis, dan memang tidak boleh.**
Hasil analisis adalah temuan studi kasus — distribusi sentimen 8.393 komentar
per lembaga. Mengeluarkan 500 baris dari sana akan mengubah angka yang justru
dilaporkan di bab hasil, dan test set tidak menjadi lebih terpisah karenanya.
Pemisahan yang benar adalah pemisahan **peran**: baris itu tetap dihitung
sebagai bagian korpus, tetapi tidak pernah dilatihkan dan tidak pernah
dikoreksi.

---

## 2. Korpus studi kasus dan ukuran golden dataset

### Yang ada di berkas

| Lembaga | Baris | Video | Layak dianotasi |
|---|---|---|---|
| Dewan Perwakilan Rakyat | 779 | 10 | 693 |
| Kepolisian Republik Indonesia | 341 | 10 | 235 |
| Komisi Pemberantasan Korupsi | 369 | 11 | 306 |
| Kejaksaan Agung | 1.494 | 10 | 1.328 |
| Presiden | 1.860 | 11 | 1.548 |
| Tentara Nasional Indonesia | 3.550 | 12 | 2.564 |
| **Total** | **8.393** | **64** | **6.674** |

"Layak" = unik lintas seluruh berkas dan sepanjang minimal 20 karakter. 1.719
baris (20,5%) tersaring: sebagian besar komentar sangat pendek, sisanya duplikat.

Korpusnya **sangat timpang**: TNI 38,4% sementara Polri hanya 3,5%. Itu
menentukan seluruh keputusan di bawah.

### Apakah 10% ukuran yang bagus?

10% dari 6.674 adalah **667 baris**. Jawabannya: **ya, dan itu batas atas yang
masuk akal** — tetapi 400–500 sudah hampir sama baiknya dengan sepertiga tenaga
anotasi lebih sedikit.

Margin selang kepercayaan 95% untuk akurasi (dihitung pada akurasi 85%):

| n | Margin | Perkiraan tenaga anotasi |
|---|---|---|
| 300 | ±4,0 poin | ~1,5 jam |
| 400 | ±3,5 poin | ~2 jam |
| **500** | **±3,1 poin** | **~2,5 jam** |
| 667 (10%) | ±2,7 poin | ~3,5 jam |
| 1.000 | ±2,2 poin | ~5 jam |

Presisi tumbuh sebagai 1/√n sementara tenaga tumbuh linear, jadi
pengembaliannya menurun cepat: 300 → 500 membeli 0,9 poin, sedangkan 500 → 667
hanya 0,4 poin.

**Yang lebih menentukan daripada margin: daya uji McNemar.** Uji itulah yang
menjawab klaim utama skripsi — apakah active learning memperbaiki model. Dayanya
bergantung pada jumlah baris yang berubah benar/salah, dan itu tumbuh sebanding
dengan n:

| n test | perbaikan bersih minimal | setara poin akurasi |
|---|---|---|
| 300 | 6 baris | 2,00 poin |
| 500 | 6 baris | 1,20 poin |
| 667 | 6 baris | 0,90 poin |

Pada n=300, perbaikan 1–2 poin **tidak akan pernah** signifikan. Pada n=500 ia
bisa. Kalau tenaga anotasi memungkinkan, itu alasan terkuat memilih yang lebih
besar.

### Jumlah baris atau persentase?

Formulir menerima keduanya, tetapi keduanya tidak setara dan perlu dipahami
bedanya.

**Yang menentukan mutu evaluasi adalah n, bukan persentase.** Margin selang
kepercayaan hanya bergantung pada jumlah baris: 500 baris memberi ±3,1 poin baik
diambil dari korpus 5.000 maupun 50.000. Populasi hanya berpengaruh lewat
koreksi populasi terbatas, yang pada rasio sampel di bawah 10% praktis nol.
Karena itu **memilih angka lebih jujur secara statistik** — angka itulah yang
langsung menjawab "seberapa presisi hasilnya".

**Persentase berguna untuk dua hal.** Pertama, lebih mudah dipertahankan di
sidang: "10% korpus" terdengar seperti aturan, sementara "500" terdengar
sembarang kecuali dijelaskan. Kedua, ia ikut menyesuaikan bila korpusnya
bertambah.

Satu hal yang harus diperhatikan bila memakai persentase: **penyebutnya adalah
kolam yang lolos penyaringan**, bukan jumlah baris mentah di berkas. Pada korpus
ini selisihnya 20,5% — 8.393 baris mentah menjadi 6.674 kandidat setelah baris
tanpa isi, terlalu pendek, duplikat, dan yang sudah dikoreksi disingkirkan.
Kalau persentase dihitung dari angka mentah, "10%" akan berarti porsi berbeda
setiap kali komposisi emotikon dan duplikatnya berbeda. Rencana pengambilan
mencatat kedua angka itu.

**Anjuran: 500 baris.** Kalau waktunya ada, 667 (10% bulat) lebih baik dan lebih
mudah dijelaskan di naskah. Yang tidak boleh: mengejar angka besar lalu
menganotasi terburu-buru. Label emas yang buruk menjadi batas atas bagi seluruh
angka di bab evaluasi, dan itu tidak bisa diperbaiki belakangan.

### Rincian per lembaga: baca peringatan ini

Alokasi **proporsional** pada n=500 (bawaan sistem):

| Lembaga | Jatah | Margin per lembaga |
|---|---|---|
| Dewan Perwakilan Rakyat | 52 | ±9,8 poin |
| Kepolisian Republik Indonesia | 18 | ±16,7 poin |
| Komisi Pemberantasan Korupsi | 23 | ±13,8 poin |
| Kejaksaan Agung | 99 | ±7,1 poin |
| Presiden | 116 | ±6,4 poin |
| Tentara Nasional Indonesia | 192 | ±5,1 poin |

Akurasi model pada Polri akan punya margin ±16,7 poin. **Perbedaan akurasi
antar lembaga tidak boleh disimpulkan** dari angka sebesar itu — sajikan sebagai
deskriptif belaka, lengkap dengan n dan selangnya.

Ada opsi jatah minimal per strata (`min_per_stratum`) yang meratakan ini, tetapi
konsekuensinya harus disadari: angka keseluruhan lalu menaksir **rerata
antar-lembaga**, bukan rerata korpus, dan itu wajib disebut di naskah. Untuk
skripsi ini proporsional lebih tepat — temuan sentimen per lembaga yang menjadi
isi studi kasus berasal dari analisis korpus penuh (8.393 komentar), bukan dari
golden dataset.

---

## 3. Menyusun golden dataset

### Pengambilan sampel — pakai menu Ambil Sampel dari Korpus

Empat aturan yang ditegakkan alat itu, dan alasannya:

**1. Acak di dalam strata, tidak pernah berdasarkan keyakinan model.**
Sangat menggoda memakai antrean tinjauan — baris berkeyakinan rendah — sebagai
test set: datanya sudah tersedia dan tampak menantang. Tetapi sampel semacam itu
memilih justru kasus tersulit, sehingga akurasi yang terukur jauh di bawah
kinerja sebenarnya dan tidak bisa digeneralisasi. Test set harus sampel peluang
dari populasi yang ingin dibicarakan. Alat ini karena itu **tidak menyediakan**
pilihan berbasis keyakinan sama sekali.

**2. Stratifikasi hanya pada sifat data.** Lembaga dan video adalah sifat
dokumen; label prediksi bukan. Menstratifikasi pada label prediksi mengubah
proporsi kelas sehingga akurasi keseluruhan tidak lagi menaksir akurasi korpus.

**3. Baris yang sudah dikoreksi dikecualikan otomatis**, begitu juga baris yang
sudah ada di golden dataset lain, baris kembar setelah normalisasi, dan **teks
yang tidak bisa dinilai sama sekali** — komentar yang isinya hanya emotikon atau
tanda baca. Penyaringan panjang saja tidak cukup untuk yang terakhir:
"❤️❤️👍👍👍❤️❤️👍👍👍" lewat dari ambang 20 karakter tetapi tetap tidak ada
isinya.

**4. Berkas anotasinya tidak memuat prediksi model.** Kolomnya hanya
`id, text, label, label_2, aspects, segment, note`.

Rencana pengambilan (analisis sumber, semai, stratifikasi, jumlah yang ditolak
per alasan, jatah tiap strata) tersimpan bersama datasetnya dan tampil di
halaman detail, siap disalin ke bab metodologi.

### Aplikasi anotasi

`public/anotasi/index.html` — satu halaman statis tanpa backend, dilayani di
`/anotasi` dan bisa disalin ke hosting mana pun atau dikirim langsung ke
anotator sebagai berkas. Pekerjaan tersimpan otomatis di peramban dan **tidak
dikirim ke mana pun**.

Isinya: pedoman lengkap yang bisa dibuka kapan saja, satu komentar per layar,
pintasan papan tik (1/2/3, 0 untuk tidak jelas), penanda kemajuan, mode anotator
pertama/kedua, dan ekspor CSV yang siap diunggah kembali.

**Tebakan model.** Berkas anotasi bawaannya tidak memuat prediksi; tombol
"Unduh + Prediksi Model" menyertakannya. Aplikasi tetap **menyembunyikannya
sampai anotator memilih labelnya sendiri**, lalu menampilkannya dan mencatat
apakah anotator berubah pikiran (kolom `changed_after_reveal` pada berkas
ekspor).

Penundaan itu bukan formalitas. Anotator yang melihat tebakan model lebih dulu
cenderung menyetujuinya; bila itu terjadi, akurasi yang terukur naik tanpa
modelnya berubah, dan yang sebenarnya diukur adalah kesepakatan anotator dengan
model. Dengan penundaan, keduanya tetap dapat: anotator memutuskan sendiri lebih
dulu, lalu tetap bisa mengevaluasi model dan memperbaiki diri — dan jumlah
perubahan setelah melihat menjadi angka yang justru layak dilaporkan.

Pedoman lengkap sebagai dokumen terpisah, siap dilampirkan ke naskah, ada di
`docs/PEDOMAN_ANOTASI.md`.

**Baris "tidak jelas".** Anotator menandai baris yang benar-benar tidak bisa
diputuskan; baris itu pulang tanpa label. Dataset tidak bisa dibekukan selama
masih ada baris tanpa label, jadi buang lewat tombol "Buang N baris tanpa label"
di halaman dataset. Jumlahnya dicatat pada rencana pengambilan dan wajib
dilaporkan: berapa persen korpus yang bahkan manusia tidak bisa memutuskannya
adalah temuan, bukan aib.

### Anotasi

1. **Anotator kedua untuk sebagian baris.** Isi kolom `label_2` untuk minimal
   100 baris. Sistem menghitung Cohen kappa antar-anotator dan menampilkannya.
   Tanpa angka itu, label emas hanyalah pendapat satu orang dan **batas atas
   kinerja model tidak diketahui** — kalau dua manusia hanya sepakat pada kappa
   0,70, model dengan kappa 0,72 sudah berada di batas kebisingan label.
2. **Tulis pedoman anotasi sebelum mulai**, lalu lampirkan. Terutama batas
   netral–negatif pada komentar YouTube, yang paling sering goyah.
3. **Jangan menyunting kolom `id`.** Kolom itu yang memasangkan baris kembali
   saat diunggah; teks yang tersunting masih dikenali lewat sidik jari, tetapi
   id lebih pasti.

### Label aspek: wajib dinormalkan

Kalau golden dataset memuat label aspek, sistem **menolak menghitung metrik
aspek** sampai label emasnya punya bentuk dasar. Ini disengaja.

Bahasa Indonesia aglutinatif. Mode `automatic` mengembalikan bentuk permukaan
seperti yang tertulis di teks (`pelayanannya`), sementara anotator menulis
bentuk dasar (`pelayanan`). Membandingkan keduanya sebagai string persis
menghitung tiap pasangan yang **benar** dua kali salah — sekali sebagai false
positive, sekali sebagai false negative. Terukur pada keluaran yang sama:

| cara mencocokkan | TP | FP | FN | micro-F1 |
|---|---|---|---|---|
| string persis | 1 | 8 | 7 | **0,118** |
| setelah normalisasi bentuk dasar | 8 | 1 | 0 | **0,941** |

Modelnya menemukan aspek yang benar pada kedua hitungan; selisihnya seluruhnya
artefak pengukuran. Normalisasi dikerjakan sekali saat pembekuan lewat
`/api/normalize/aspects`, memakai stemmer Sastrawi yang sama dengan yang dipakai
ekstraksi. Kalau layanan sedang mati saat pembekuan, jalankan
`php artisan evaluation:normalize-aspects` setelahnya.

### Aspek harus ditinjau untuk SETIAP baris

Metrik aspek baru dihitung bila seluruh baris golden sudah ditinjau aspeknya —
termasuk baris yang memang **tidak punya** aspek, yang ditandai `-` pada kolom
`aspects` (aplikasi anotasi punya tombol "Tidak ada aspek").

Tiga keadaan dibedakan, dan perbedaannya menentukan keabsahan angkanya:

| Nilai kolom `aspects` | Artinya | Dinilai? |
|---|---|---|
| kosong | belum ditinjau | tidak |
| `-` | ditinjau, memang tidak ada aspek | **ya** |
| `pelayanan;antrian` | ditinjau, ada aspeknya | ya |

Keadaan kedua wajib bisa dinyatakan. Kalau baris tanpa aspek dibiarkan kosong,
ia tidak ikut dinilai — sehingga aspek yang **dikarang model** pada komentar
seperti "Setuju!" atau "Wkwkwk" tidak pernah terhitung sebagai false positive,
dan presisi yang dilaporkan menjadi lebih tinggi daripada yang sebenarnya.

Halaman dataset menampilkan berapa baris yang belum ditinjau aspeknya selama
anotasi berlangsung, jadi kekurangannya terlihat sebelum evaluasi dijalankan.

**Yang diukur metrik aspek adalah ekstraksi, bukan polaritas.** Micro/macro-F1
aspek menjawab "apakah model menemukan aspek yang benar", bukan "apakah sentimen
per aspek benar". Sentimen per aspek dihasilkan pasca-pemrosesan di sisi layanan
(pemenggalan klausa dan penyelesaian keyakinan rendah pada ambang 0,6) yang
**belum pernah diukur harness mana pun**. Jadi akurasi sentimen tingkat dokumen
juga tidak berlaku untuk angka per aspek yang tampil di halaman hasil. Sebutkan
itu di bagian batasan.

---

## 4. Urutan pengerjaan studi kasus

Urutannya mengikat. Membekukan test set **setelah** active learning berjalan
membuat seluruh perbandingan tidak sah.

```
1. Unggah 6 berkas sebagai 6 analisis terpisah   <- judul = nama lembaga
   jenis "combined", kolom pemisah "Video ID"
2. Ambil Sampel dari Korpus (pilih keenamnya)    -> berkas anotasi kosong
3. Anotasi manual (+ anotator kedua)             -> isi kolom label
4. Unggah anotasi, lalu BEKUKAN                  <- sebelum koreksi apa pun
5. evaluation:run -> Iterasi 0                   <- angka "sebelum"
6. Pengguna mengoreksi baris berkeyakinan rendah
7. Pelatihan ulang terpicu di ambang 10 koreksi baru
8. evaluation:run -> Iterasi 1                   <- DIJALANKAN MANUAL
9. Ulangi 6-8; 2-3 iterasi, jangan lebih
10. evaluation:report                            -> tabel untuk naskah
```

### Mengapa 6 analisis terpisah, bukan satu gabungan

Menggabungkan seluruh 8.393 komentar menjadi satu analisis memerlukan sekitar
**2.230 detik**. Batas waktu job sudah dinaikkan ke 3.600 detik sehingga itu
kini muat, tetapi 6 analisis terpisah tetap lebih baik:

- Judul tiap analisis menjadi **nama lembaga**, dan alat pengambilan sampel
  memakai judul itu sebagai strata secara otomatis (kosongkan pilihan kolom
  pemisah). Berkasnya tidak punya kolom "Lembaga", jadi menggabungkannya
  menuntut menambah kolom itu secara manual lebih dulu.
- Kegagalan satu lembaga tidak menjatuhkan lima lainnya.
- Kolom "Video ID" tetap tersedia sebagai pemisah, jadi rincian per video masih
  bisa dibuka di halaman hasil.

Yang terbesar (TNI, 3.548 komentar) memakan sekitar 950 detik.

**Langkah 8 tidak berjalan sendiri.** Evaluasi sepenuhnya manual: kapan sebuah
iterasi diukur adalah keputusan Anda. Konsekuensinya — bobot lama tertimpa
begitu checkpoint diterima, jadi **iterasi yang belum diukur tidak bisa diukur
belakangan**. Ukur sebelum memicu pelatihan berikutnya.

Kalau pelatihan ingin ditahan sampai semua siap, setel
`ANALYSIS_AUTO_RETRAIN=false` di `.env` sampai golden dataset dibekukan dan
Iterasi 0 terukur.

**Jangan mengukur terlalu sering.** Dua sampai tiga iterasi dengan koreksi
banyak jauh lebih baik daripada enam iterasi dengan koreksi sedikit yang
semuanya berakhir "tidak signifikan".

### Perintah

```bash
# 2. ambil sampel (atau pakai menu di /admin/evaluation)
php artisan evaluation:sample --analysis=101 --analysis=102 --analysis=103 \
    --analysis=104 --analysis=105 --analysis=106 \
    --size=500 --seed=42 --name="Hold-out 6 lembaga" --out=anotasi.csv

# lihat rencananya lebih dulu tanpa menyimpan apa pun
php artisan evaluation:sample --analysis=101 --size=500 --dry-run

# 4. impor berkas yang sudah lengkap labelnya (alternatif unggah lewat halaman)
php artisan evaluation:import anotasi.csv --name="Hold-out 6 lembaga" --freeze

# isi ulang bentuk dasar aspek bila layanan mati saat pembekuan
php artisan evaluation:normalize-aspects

# 5 dan 8. ukur
php artisan evaluation:run 1 --label="Iterasi 0 - baseline"
php artisan evaluation:run 1 --label="Iterasi 1" --compare-last

# pemeriksaan kebocoran, untuk dilaporkan apa adanya
php artisan evaluation:leakage

# 10. laporan siap salin
php artisan evaluation:report --out=laporan-evaluasi.md
```

`queue:work` harus berjalan bila evaluasi dijalankan lewat tombol di halaman
admin; lewat perintah artisan, evaluasinya berjalan langsung.

---

## 5. Angka yang dilaporkan di naskah

### Tabel utama

| Iterasi | n | Akurasi (95% CI) | Macro-F1 (95% CI) | Kappa model | Baseline |
|---|---|---|---|---|---|
| Iterasi 0 (baseline) | 500 | 84,3% (81,0–87,3) | 81,7% (77,4–85,5) | 0,762 | 45,0% |
| Iterasi 1 (+38 koreksi) | 500 | … | … | … | 45,0% |

*(bentuk tabel, bukan hasil)*

Lima hal yang wajib ikut:

- **Macro-F1, bukan hanya akurasi.** Komentar YouTube timpang; akurasi bisa
  tinggi sementara kelas minoritas gagal total.
- **Selang kepercayaan.** Angka tunggal tanpa selang menyembunyikan bahwa
  kenaikan 3 poin pada n=500 belum tentu berarti.
- **Lantai tebak-kelas-mayoritas.** Model yang tidak melampauinya belum
  membuktikan apa pun.
- **Uji McNemar** iterasi awal vs akhir.
- **Kappa antar-anotator**, terpisah dari kappa model.

### Dua kappa, jangan tertukar

- **Kappa model** (`metrics.sentiment.cohen_kappa`) — kesepakatan model dengan
  label emas. Ini kinerja.
- **Kappa antar-anotator** (`metrics.annotation.cohen_kappa`) — kesepakatan dua
  manusia. Ini **batas atas** kinerja yang masuk akal.

Yang kedua justru lebih penting. Sebutkan keduanya dengan nama yang jelas,
lengkap dengan n baris beranotasi ganda.

### Uji utama ditetapkan di muka

Tulis kalimat ini di bab metodologi **sebelum** menjalankan evaluasi:

> Uji signifikansi utama adalah perbandingan iterasi pertama dengan iterasi
> terakhir. Perbandingan antar-iterasi lainnya disajikan sebagai deskriptif dan
> tidak diklaim signifikansinya.

Alasannya: menguji tiap pasangan iterasi adalah pengujian berganda. Dengan empat
uji pada α = 0,05, peluang setidaknya satu "signifikan" palsu naik ke sekitar
19%. Menetapkan satu uji di muka menutup pertanyaan itu tanpa perlu koreksi
Bonferroni. `evaluation:report` memang hanya menguji awal-vs-akhir;
`--compare-last` bersifat deskriptif dan mencetak peringatan itu sendiri.

### Menulis hasil McNemar

Bila signifikan:

> Setelah satu putaran active learning (38 koreksi), 19 baris uji berubah dari
> salah menjadi benar dan 6 baris berubah sebaliknya. Uji McNemar dua sisi
> (binomial eksak) memberi p = 0,015.

Bila **tidak** signifikan, sertakan daya ujinya — sistem sudah menghitungnya dan
menuliskannya sendiri:

> Perbaikan 2,3 poin, tidak signifikan (p = 0,09). Dengan 4 baris yang memburuk,
> uji ini baru mendeteksi perubahan bila minimal 13 baris membaik (perbaikan
> bersih 9 baris, setara 1,8 poin pada n = 500).

Kalimat kedua jauh lebih kuat daripada sekadar melaporkan p: ia menunjukkan
batas kemampuan uji itu sendiri, bukan kegagalan metodenya.

### Populasi yang diukur

Bukan "akurasi pada korpus", melainkan **"akurasi pada komentar unik sepanjang
minimal 20 karakter"**. Pada korpus ini 1.719 dari 8.393 baris (20,5%) tersaring.

**Arah biasnya ke atas.** Komentar pendek justru tempat model paling lemah
karena konteksnya sedikit — di layanan ini terukur `"Pajak naik"` terbaca
positif pada 0,53 sementara `"Pajak naik terus"` terbaca negatif pada 0,73.
Membuang teks terpendek menyingkirkan sebagian kasus tersulit, jadi akurasi
hold-out sedikit melebih-lebihkan kinerja pada korpus utuh. Sebutkan ini
sendiri; jauh lebih kuat daripada menunggu ditanya. Angkanya tersimpan pada
rencana pengambilan dan dicetak `evaluation:report`.

### Koherensi topik bukan F1

Koherensi **tidak memakai label emas**: ia mengukur keterkaitan kata di dalam
korpus itu sendiri, bukan kecocokan dengan jawaban manusia.

**Diukur pada korpus studi kasus penuh, bukan pada test set.** Golden dataset
adalah sampel acak, sehingga kalimat pembentuk satu topik sebagian besar tidak
ikut tersampel, dan pada beberapa ratus dokumen pendek matriks ko-okurensinya
jarang. Terukur pada korpus dan konfigurasi yang sama, hanya jumlah teksnya
berbeda:

| korpus | c_v | rambu |
|---|---|---|
| 300 teks (seukuran golden dataset) | 0,3597 | lemah |
| 1.139 teks (korpus penuh) | 0,4498 | baik untuk teks pendek |

Selisih 0,09 melintasi batas kategori penafsiran. Sistem karena itu membaca blok
`quality` yang tersimpan pada hasil analisis korpusnya — angka yang benar-benar
dihasilkan sistem untuk pengguna, tanpa permintaan baru ke layanan.

Model topik **tidak ikut dilatih ulang** oleh active learning, jadi koherensi
tidak diharapkan berubah antar iterasi. Justru itu yang membuatnya berguna
sebagai **kontrol**: bila F1 naik sementara koherensi diam, kenaikannya memang
dari pelatihan ulang. Karena sifatnya konstan, koherensi tidak digambar pada
grafik per iterasi — cukup dilaporkan sekali.

Rambu penafsiran (untuk teks pendek dan tidak baku seperti komentar): di bawah
0,30 tidak koheren; 0,30–0,40 lemah; 0,40–0,55 baik untuk teks pendek;
0,55–0,70 sangat baik; di atas 0,70 patut dicurigai — pada korpus komentar,
angka setinggi itu lebih sering menandakan topik terlalu sedikit atau dokumen
kembar.

**Jumlah topik tidak stabil terhadap seed, koherensinya stabil.** Terukur di
sisi layanan pada lima seed: c_v 0,5023 ± 0,0221 (sangat stabil), tetapi jumlah
topik 13,6 ± 4,98 dengan rentang 6–19. Artinya kalimat "pemodelan topik
menghasilkan K topik" bergantung pada seed, dan penguji yang menjalankan ulang
akan mendapat angka jauh berbeda. **Selalu sebutkan seed-nya, dan sertakan
rentang antar-seed setiap kali menyebut jumlah topik.** Ini justru temuan yang
menarik dibahas: koherensi mengukur mutu kata kunci, bukan kestabilan partisi.

**c_npmi negatif itu wajar, dan perlu dijelaskan.** Pada korpus ini c_npmi
berkisar −0,19 sampai −0,49 sementara c_v positif 0,43–0,55. Itu persis kritik
klasik terhadap c_v: konfirmasi tak-langsung lewat kosinus antar-vektor NPMI
dapat menaikkan skor walau pasangan katanya tidak benar-benar ko-okuren.
Melaporkan keduanya sudah sikap yang benar; siapkan satu paragraf penjelasan
supaya tidak terbaca sebagai kejanggalan.

### Provenance

Setiap run menyimpan model, suhu kalibrasi, apakah checkpoint hasil pelatihan
ulang dimuat, konfigurasi preprocessing, jumlah putaran dan semai bootstrap,
serta **sidik jari isi golden dataset** (SHA-256 atas seluruh sidik jari baris
yang diurutkan). Sidik jari itu membuktikan kepada pembaca bahwa iterasi 0 dan
iterasi terakhir mengukur baris yang sama, tanpa perlu mempercayai aplikasinya.
`evaluation:report` mencetak semuanya di bagian "Reproduksi".

### Ambang tinjauan

Ambang dinyatakan sebagai **toleransi galat**, bukan sebagai angka keyakinan
yang disetel. Bedanya menentukan apakah angkanya bisa dipertanggungjawabkan.

Model sentimen dikalibrasi suhu (T = 2,7748) dengan **ECE 0,0209** pada set uji
SmSA (n = 500). Pada model terkalibrasi, skor keyakinan adalah taksiran peluang
prediksi itu benar. Ambang karena itu punya arti langsung:

> Toleransi galat 10% → ambang 0,90 → *tinjau setiap prediksi yang peluang
> benarnya di bawah 90%.*

Itu **pernyataan risiko**, bukan hasil penyetelan pada data uji. Ambang yang
dipilih dari tabel yield pada set uji yang juga dilaporkan — seperti 0,94 di
layanan — menimbulkan masalah *selection-on-test*; ambang yang diturunkan dari
toleransi galat tidak. Ini yang membuatnya bisa dipertahankan tanpa syarat.

**Terkonfirmasi empiris** oleh tabel reliabilitas model yang sama:

| rentang keyakinan | n | porsi | akurasi nyata |
|---|---|---|---|
| 0,90–0,99 | 455 | 91,0% | **95,4%** |
| 0,70–0,90 | 28 | 5,6% | **57,1%** |
| 0,50–0,70 | 15 | 3,0% | 53,3% |
| 0,00–0,50 | 2 | 0,4% | 0,0% |

Ada jurang **38 poin** tepat di 0,90. Yang lebih penting untuk dituliskan: di
bawah 0,90 model **terlalu percaya diri** — akurasi nyatanya 57% padahal
keyakinannya menyebut 70–90%. ECE yang bagus secara keseluruhan didominasi 91%
sampel di pita teratas dan menutupi hal itu. Justru karena keyakinan modelnya
sendiri tidak bisa dipercaya di bawah 0,90, di sanalah manusia perlu melihat.

**Satu angka, dipakai di mana-mana.** Toleransi galat menentukan sekaligus:
ambang tinjauan, batas pita pada sebaran keyakinan, dan ambang penilaian
ringkas. Sebelumnya ketiganya punya angka sendiri (0,80 / 0,85 / 0,70 / 0,90),
sehingga satu panel bisa menyebut sebuah teks "cukup yakin" sekaligus "perlu
ditinjau". Kini pita teratas **selalu tepat sama** dengan ambang tinjauan, jadi
"di luar pita teratas" dan "perlu ditinjau" adalah himpunan yang sama persis.

**Diuji ulang pada korpus Anda sendiri.** Tabel di atas berasal dari SmSA, bukan
dari komentar YouTube. Setiap evaluasi hold-out menghitung ulang tabel yang sama
dari golden dataset (`metrics.reliability`, tampil di halaman evaluasi dan
`evaluation:report`), lengkap dengan ECE-nya. Itu yang membenarkan — atau
menyanggah — ambang yang berlaku pada domain studi kasus. Laporkan tabel itu di
naskah berdampingan dengan pernyataan toleransinya.

**Angka capaian jangan dikutip dulu.** "0,94 menangkap 63,6% kesalahan dengan
meninjau 17,2% korpus" dihitung pada set uji yang sama yang dipakai memilih
0,94. Sebut saja ambangnya beserta dasarnya; capaiannya baru boleh dilaporkan
setelah dihitung ulang pada set validasi di sisi layanan.

Mengubahnya: `ANALYSIS_ERROR_TOLERANCE` di `.env` (0.10 = ambang 0,90).

---

## 6. Kesalahan yang membatalkan angka

| Kesalahan | Akibat |
|---|---|
| Membekukan test set setelah koreksi dimulai | Sebagian baris uji mungkin sudah dilatihkan; seluruh perbandingan batal |
| Menambah baris ke dataset di tengah rangkaian | Iterasi 0 dan 1 mengukur hal yang berbeda |
| Mengambil sampel dari baris berkeyakinan rendah | Test set berisi kasus tersulit; akurasinya tidak menaksir apa pun |
| Membandingkan aspek tanpa normalisasi bentuk dasar | micro-F1 0,118 padahal seharusnya 0,941 |
| Mengukur koherensi pada test set | c_v tertekan; "lemah" padahal sebenarnya "baik" |
| Mengklaim signifikansi pada tiap pasangan iterasi | Pengujian berganda; peluang temuan palsu naik ke ~19% |
| Melaporkan akurasi saja | Kegagalan pada kelas minoritas tersembunyi |
| Menyimpulkan perbedaan antar lembaga | Margin ±5 sampai ±17 poin; hampir semua selisih tidak bermakna |
| Lupa mengukur sebelum pelatihan berikutnya | Bobot lama tertimpa; titik iterasi itu hilang selamanya |

Sistem mencegah lima yang pertama secara struktural: dataset beku tidak bisa
diubah, dataset yang sudah punya run tidak bisa dibuka pembekuannya, tidak ada
pilihan pengambilan berbasis keyakinan, metrik aspek menolak dihitung tanpa
bentuk dasar, dan koherensi dibaca dari korpus penuh. Sisanya disiplin
penulisnya — termasuk yang terakhir, karena evaluasi sengaja dibuat manual.

---

## 7. Batasan yang sebaiknya disebut sendiri di naskah

- Golden dataset diambil dari domain yang sama dengan studi kasus (komentar
  YouTube tentang lembaga pemerintah), jadi angkanya **tidak** menggambarkan
  kinerja pada domain lain.
- Populasinya komentar unik ≥ 20 karakter, dengan bias ke atas (lihat §5).
- Margin ±3,1 poin pada n=500 membuat perubahan kecil tidak terdeteksi; uji
  McNemar butuh perbaikan bersih minimal 6 baris.
- Rincian per lembaga bersifat deskriptif; margin per lembaga ±5 sampai ±17 poin.
- Bila hanya sebagian baris dianotasi ganda, kappa yang dilaporkan berlaku untuk
  bagian itu saja.
- Koreksi active learning berasal dari baris berkeyakinan rendah (uncertainty
  sampling), bukan sampel acak — perbaikannya paling terasa pada kasus ambigu,
  dan itu memang tujuannya.
- Koherensi topik tidak sebanding antar korpus dan tidak punya label emas;
  jumlah topiknya bergantung pada seed (13,6 ± 4,98 pada lima seed).
- Metrik aspek mengukur **ekstraksi**, bukan polaritas per aspek. Pasca-pemrosesan
  sentimen per aspek belum pernah diukur harness mana pun, jadi akurasi tingkat
  dokumen tidak berlaku untuk angka per aspek di halaman hasil.
- Angka capaian ambang tinjauan (berapa persen kesalahan tertangkap) dihitung
  pada set uji yang sama dengan yang dilaporkan; jangan dikutip sebelum dihitung
  ulang pada set validasi.

---

## 8. Apa yang diukur di mana

| Pertanyaan | Tempat | Sah dikutip? |
|---|---|---|
| Seberapa baik model pada kasus ini? | `/admin/evaluation` | **Ya** |
| Apakah active learning memperbaiki model? | `/admin/evaluation`, McNemar awal vs akhir | **Ya** |
| Ekstraksi aspek (micro/macro-F1) | `/admin/evaluation` | **Ya**, bila seluruh baris ditinjau aspeknya |
| Mutu pemodelan topik | `/admin/evaluation` (dari korpus penuh) | Ya, dengan catatan tak sebanding |
| Sentimen per lembaga (temuan studi kasus) | halaman hasil analisis | **Ya** |
| Prosedur pengambilan sampel | `/admin/evaluation/{id}` → Rencana pengambilan | Ya, untuk bab metodologi |
| Berapa banyak koreksi terkumpul | `/admin/training` | Ya, sebagai proses |
| Metrik atas data koreksi | `/admin/training` | **Tidak** — data latih |
