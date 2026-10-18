# Pedoman Anotasi Sentimen Komentar YouTube

Dokumen ini adalah acuan kerja anotator sekaligus lampiran metodologi naskah.
Tulis versi dan tanggalnya bila dilampirkan.

**Versi 1.0 — 5 September 2026**
Objek: komentar berbahasa Indonesia pada video YouTube tentang lembaga
pemerintah. Label: `positif`, `netral`, `negatif`.

---

## 1. Apa yang dinilai

Yang dinilai adalah **sikap yang dinyatakan penulis di dalam komentar itu**,
bukan kebenaran isinya, bukan pendapat Anda tentang isinya, dan bukan sikap
terhadap satu sasaran tertentu.

Ini disebut sentimen **tingkat dokumen**: satu komentar mendapat satu label.
Penting disadari karena mudah tergoda bertanya "sentimen terhadap lembaganya
apa?" — pertanyaan itu berbeda dan tidak dipakai di sini.

| Label | Diberikan bila komentar… |
|---|---|
| **positif** | memuji, mendukung, berterima kasih, mengapresiasi, mendoakan yang baik, menyatakan kepuasan atau optimisme |
| **negatif** | mengkritik, marah, kecewa, menuduh, menyindir, mengejek, menyatakan curiga, pesimis, atau menuntut |
| **netral** | menyampaikan informasi, bertanya, mengutip, atau menyatakan sesuatu tanpa sikap yang jelas |

---

## 2. Aturan keputusan

Terapkan berurutan. Berhenti pada aturan pertama yang berlaku.

**A1 — Sarkasme dinilai menurut maksud, bukan kata harfiah.**
> "Mantap sekali kinerjanya, rakyat makin sengsara" → **negatif**

Penanda sarkasme: pujian berlebihan yang diikuti akibat buruk, tanda seru
berlebihan, "wow/hebat" pada konteks yang jelas buruk, emotikon tepuk tangan
pada kalimat kritik.

**A2 — Campuran: pilih yang dominan.**
> "Programnya bagus tapi pelaksanaannya kacau balau" → **negatif** (penekanan
> pada bagian kedua)
> "Awalnya saya ragu, ternyata pelayanannya cepat" → **positif**

Bila benar-benar seimbang dan tidak ada yang dominan → **netral**. Gunakan ini
hemat-hemat; kebanyakan komentar punya kecondongan.

**A3 — Pertanyaan murni → netral. Pertanyaan retoris → ikut maksudnya.**
> "Kapan sidangnya digelar?" → **netral**
> "Kapan negara ini benar-benar adil?" → **negatif**

**A4 — Doa dan harapan.**
> "Semoga lembaga ini makin amanah" → **positif**
> "Semoga cepat kena karma" → **negatif**

**A5 — Salam, takbir, ucapan agama tanpa sikap → netral.**
> "Assalamualaikum" → **netral**
> "Alhamdulillah akhirnya ditangkap juga" → **positif** (ada sikap: lega)

**A6 — Umpatan biasanya negatif, kecuali umpatan akrab yang memuji.**
> "Anjir keren banget aksinya" → **positif**
> "Dasar tidak becus" → **negatif**

**A7 — Kutipan berita atau pernyataan fakta tanpa komentar → netral.**
> "Berdasarkan UU No. 5 tahun 2014 pasal 3" → **netral**

**A8 — Komentar tentang video, host, atau komentator lain tetap dinilai apa
adanya.** Jangan mencoba memisahkan sasaran; label tetap tingkat dokumen.
> "Narasumbernya cerdas sekali" → **positif**

**A9 — Tuntutan dan seruan bertindak biasanya negatif** karena mengandung
ketidakpuasan atas keadaan sekarang.
> "Usut tuntas sampai ke akarnya!" → **negatif**

**A10 — Jangan menilai berdasarkan setuju atau tidak setujunya Anda.**
Komentar yang Anda anggap keliru tetapi disampaikan dengan memuji tetap
**positif**.

**A11 — Bahasa daerah, singkatan, dan campur kode dinilai bila maknanya jelas.**
Bila tidak yakin artinya → **tidak jelas** (lihat §3).

**A12 — Emotikon memperkuat, tidak menentukan sendiri.**
> "Ya begitulah 😂" → **netral** bila tidak ada isyarat lain
> "Kerja bagus 👍" → **positif**

Komentar yang isinya hanya emotikon sudah disaring sistem dan tidak akan muncul.

---

## 3. Bila benar-benar tidak bisa diputuskan

Pilih **tidak jelas**. Jangan menebak.

Pakai untuk: teks yang maknanya tidak terbaca, campur kode yang tidak Anda
pahami, komentar terpotong, atau kalimat yang bisa dibaca dua arah tanpa
petunjuk apa pun.

Baris bertanda "tidak jelas" **tidak masuk** ke test set dan jumlahnya
dilaporkan apa adanya di naskah. Menebak justru lebih merusak: satu label asal
menjadi kesalahan permanen yang dihitung sebagai kesalahan model.

Target: di bawah 5% dari seluruh baris. Bila jauh lebih tinggi, pedoman ini yang
perlu diperbaiki, bukan anotatornya.

---

## 4. Anotasi aspek

Selain sentimen, tandai **aspek** yang dibicarakan komentar. Aspek dipakai untuk
mengukur modul ekstraksi aspek, dan ikut dilatihkan pada active learning.

### Apa yang dihitung sebagai aspek

Aspek adalah **objek yang dinilai** — kata benda yang menjadi sasaran pendapat,
bukan sifat yang diberikan kepadanya.

| Komentar | Aspek | Bukan |
|---|---|---|
| "Pelayanannya cepat sekali" | `pelayanan` | ~~cepat~~ |
| "Antriannya lama, petugasnya jutek" | `antrian`, `petugas` | ~~lama~~, ~~jutek~~ |
| "Sistem online-nya sering error" | `sistem` | ~~error~~ |
| "Pak Kapolri harus tegas" | `kapolri` | ~~tegas~~ |
| "Anggarannya tidak transparan" | `anggaran` | ~~transparan~~ |

### Aturan

**B1 — Tulis bentuk dasar.** `pelayanan`, bukan `pelayanannya`. Kalau ragu,
sorot kata di komentarnya; sistem akan menyamakan bentuknya sendiri lewat
stemmer yang sama dengan yang dipakai model, jadi `pelayanannya` dan `pelayanan`
akan bertemu di token yang sama. Konsistensi Anda tetap membantu.

**B2 — Satu komentar boleh punya beberapa aspek.** Tandai semuanya.

**B3 — Aspek harus benar-benar disebut di teksnya.** Jangan menyimpulkan aspek
yang hanya tersirat. "Negara ini kacau" tidak punya aspek; "Hukum di negara ini
kacau" punya aspek `hukum`.

**B4 — Jangan terlalu halus, jangan terlalu kasar.** `pelayanan` bukan
`pelayanan di loket dua`; tetapi `antrian` dan `petugas` tetap dua aspek
berbeda, bukan digabung menjadi `kantor`.

**B5 — Nama orang atau jabatan boleh menjadi aspek** bila memang yang dinilai.
"Pak Kapolri harus tegas" → `kapolri`.

**B6 — Konsisten menamai.** Kalau sudah memakai `pelayanan`, jangan berganti ke
`layanan` di baris lain. Aplikasi menampilkan aspek yang sudah pernah Anda pakai
sebagai usulan — pakai itu.

### Komentar tanpa aspek: tekan "Tidak ada aspek"

Ini **wajib**, bukan opsional, dan bukan sekadar kerapian.

Banyak komentar memang tidak membicarakan aspek apa pun — "Setuju!", "Wkwkwk",
"Semoga amanah". Kalau baris itu dibiarkan kosong, sistem menganggapnya *belum
ditinjau* dan tidak ikut dinilai. Akibatnya: aspek yang **dikarang model** pada
komentar semacam itu tidak pernah terhitung sebagai kesalahan, dan presisi yang
dilaporkan menjadi lebih tinggi daripada yang sebenarnya.

Tiga keadaan yang dibedakan sistem:

| Keadaan | Artinya |
|---|---|
| dibiarkan kosong | belum ditinjau — baris tidak dinilai |
| **Tidak ada aspek** | sudah ditinjau, memang tidak ada — baris **ikut dinilai** |
| ada daftar aspek | sudah ditinjau, ada aspeknya |

Metrik aspek baru dihitung bila **seluruh** baris sudah ditinjau aspeknya.

---

## 5. Prediksi model: kapan boleh dilihat

Aplikasi anotasi **menyembunyikan** tebakan model sampai Anda memilih label
sendiri. Setelah itu tebakan model muncul dan Anda boleh mengubah pilihan.

Alasannya bukan formalitas. Anotator yang melihat tebakan model lebih dulu
cenderung menyetujuinya — dan bila itu terjadi, angka akurasi yang terukur naik
tanpa modelnya berubah sedikit pun. Seluruh bab evaluasi lalu mengukur
kesepakatan anotator dengan model, bukan kemampuan model.

Urutan yang benar:

1. Baca komentarnya.
2. Putuskan label Anda.
3. Baru lihat tebakan model.
4. Ubah **hanya bila Anda menemukan sesuatu yang tadi terlewat** — bukan karena
   model kelihatan lebih yakin.

Sistem mencatat berapa banyak label yang berubah setelah tahap 3. Angka itu
dilaporkan di naskah, jadi tidak ada yang disembunyikan.

---

## 6. Anotator kedua

Sebagian baris (minimal 100) dianotasi dua orang secara **terpisah** — tanpa
berdiskusi lebih dulu dan tanpa melihat label satu sama lain. Dari situ dihitung
**Cohen's kappa**, yaitu tingkat kesepakatan setelah memperhitungkan kebetulan.

Angka itu penting karena menjadi **batas atas** yang masuk akal bagi model: bila
dua manusia hanya sepakat pada kappa 0,70, model dengan kappa 0,72 sudah berada
di batas kebisingan label, dan selisih di atasnya tidak bermakna.

Rambu penafsiran (Landis & Koch, 1977): <0,21 sangat lemah · 0,21–0,40 lemah ·
0,41–0,60 sedang · 0,61–0,80 kuat · >0,80 sangat kuat.

Perbedaan boleh didiskusikan **setelah** kedua anotasi selesai dan kappa
dihitung. Hasil diskusi boleh memperbaiki pedoman untuk putaran berikutnya,
tetapi kappa yang dilaporkan adalah kappa sebelum diskusi.

---

## 7. Cara kerja yang disarankan

- **Batch 50–100 baris**, lalu istirahat. Anotasi yang lelah tidak konsisten,
  dan ketidakkonsistenan itu langsung menurunkan kappa.
- **Jangan tergesa.** Rata-rata wajar 10–15 detik per komentar. 500 baris
  berarti sekitar dua sampai tiga jam kerja terfokus, bukan setengah jam.
- **Konsisten lebih penting daripada benar.** Bila menemui kasus yang tidak
  diatur pedoman ini, putuskan sekali lalu perlakukan kasus serupa dengan cara
  yang sama, dan **catat keputusannya** di kolom catatan.
- Kemajuan tersimpan otomatis di peramban. Menutup tab tidak menghilangkan
  pekerjaan, tetapi tetap **unduh hasilnya** sebelum berhenti lama.

---

## 8. Kasus sulit yang sudah diputuskan

Daftar ini tumbuh selama anotasi berjalan. Tambahkan setiap keputusan baru
supaya anotator kedua memutuskan dengan cara yang sama.

| Kasus | Keputusan | Alasan |
|---|---|---|
| "Wkwkwk" sendirian | netral | tertawa tanpa sasaran, tidak menyatakan sikap |
| "Setuju!" | positif | mendukung isi video |
| "Up" / "Hadir" / "Absen" | netral | penanda kehadiran, bukan sikap |
| "Semoga amanah" | positif | doa baik |
| "Ini baru namanya negara" | positif | pujian, bukan sarkasme, kecuali ada isyarat sebaliknya |
| "Katanya negara hukum" | negatif | sindiran |
| Menyebut nama tokoh tanpa predikat | netral | tidak ada sikap |
| Nomor telepon, promosi, spam | tidak jelas | bukan opini terhadap apa pun |
| Komentar penuh huruf kapital tanpa isi jelas | ikut isinya | kapital bukan penanda sentimen |

---

## 9. Yang dilaporkan di naskah

Salin bagian ini ke bab metodologi:

- Versi dan tanggal pedoman ini.
- Jumlah anotator, dan berapa baris yang dianotasi ganda.
- Cohen's kappa antar-anotator beserta n.
- Jumlah baris bertanda "tidak jelas" dan bagaimana diperlakukan.
- Bahwa prediksi model disembunyikan sampai anotator memutuskan, beserta jumlah
  label yang berubah setelah prediksi ditampilkan.
- Bahwa komentar tanpa huruf atau angka disaring sistem sebelum anotasi.
- Untuk aspek: berapa baris yang ditinjau aspeknya, berapa yang ditandai tidak
  punya aspek, dan bahwa metrik aspek mengukur **ekstraksi** — apakah model
  menemukan aspek yang benar — bukan polaritas per aspek.
