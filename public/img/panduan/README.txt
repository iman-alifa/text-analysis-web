Tangkapan layar untuk halaman Pedoman Penggunaan (/panduan).

PENTING soal lokasi folder
--------------------------
Folder ini SENGAJA bernama public/img/panduan/, bukan public/panduan/.

Nama yang kedua sama persis dengan alamat halamannya (/panduan). Web server
selalu memeriksa berkas dan folder lebih dulu sebelum menyerahkan permintaan ke
Laravel, jadi folder bernama "panduan" akan menelan rutenya sendiri:

  - server bawaan PHP  : 404 "The requested resource /panduan was not found"
  - Apache             : rewrite dilewati (RewriteCond %{REQUEST_FILENAME} !-d)
  - nginx              : 403, karena try_files $uri $uri/ cocok ke foldernya

Jangan memindahkan folder ini ke public/panduan/.

Cara menambah tangkapan layar
-----------------------------
Taruh berkas gambar di folder ini dengan nama persis seperti yang tertulis pada
tempat kosong di halaman pedoman. Selama berkasnya belum ada, halaman
menampilkan kotak putus-putus yang menyebutkan nama berkas yang ditunggu, jadi
tata letaknya sudah benar sejak awal.

Nama berkas yang dipakai saat ini:

  01-dashboard.png        Dashboard setelah masuk, dengan bilah menu di kiri
  02-analisis-baru.png    Formulir Analisis Baru
  03-proses.png           Halaman hasil saat analisis masih diproses
  04-hasil.png            Halaman hasil analisis yang sudah selesai
  05-penjelasan-ai.png    Blok penjelasan AI beserta langkah tindak lanjut
  06-koreksi.png          Halaman koreksi

Saran: lebar 1200-1600 px, format PNG, dan potong hanya bagian yang dibahas.
Jangan menampilkan data pribadi pada tangkapan layar.

Menambah gambar baru di tempat lain cukup menulis di Blade:

  <x-panduan-gambar src="07-nama-berkas.png"
                    alt="Penjelasan isi gambar untuk pembaca layar"
                    caption="Keterangan singkat di bawah gambar." />

Video panduan
-------------
Video diputar dari public/video/Pedoman.mp4 di bagian "Tonton dulu: video
panduan" pada /panduan. Bila berkas itu tidak ada, bagiannya tidak tampil.

Opsional: taruh video-poster.jpg di folder ini sebagai gambar sampul sebelum
video diputar. Tanpa itu, kebanyakan peramban menampilkan bingkai pertama video.

Bila videonya diganti, simpan dalam format MP4 (H.264 + AAC) dengan opsi
"faststart" / "web optimized" menyala, supaya peramban bisa mulai memutar
sebelum seluruh berkas terunduh.
