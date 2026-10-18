# Dataset

Folder ini menjelaskan seluruh data yang dipakai penelitian: dari mana asalnya,
di mana letaknya di repositori, dan lisensinya. Data pengembangan model
(dataset publik, korpus, hasil eksperimen) berada di
[`nlp-api-service/data/`](../nlp-api-service/data/) karena dibaca langsung oleh
skrip layanan NLP. Folder ini memuat data studi kasus dan contoh data.

| Berkas / folder | Isi |
|---|---|
| [`daftar-video-sumber.csv`](daftar-video-sumber.csv) | 68 video YouTube sumber korpus studi kasus |
| [`studi-kasus/`](studi-kasus/) | Hasil analisis korpus studi kasus per lembaga (ekspor CSV aplikasi) |
| [`contoh/contoh-komentar.csv`](contoh/contoh-komentar.csv) | 30 komentar **sintetis** untuk mencoba aplikasi |

---

## 1. Data studi kasus

**Sumber.** Komentar publik pada video YouTube hasil pencarian *top search*
dengan nama lembaga sebagai kata kunci, diurutkan menurut relevansi, diambil
melalui [YouTube Data API v3](https://developers.google.com/youtube/v3/docs/commentThreads/list)
pada awal Juni 2026. Yang diambil hanya komentar utama (bukan balasan).

**Daftar video.** [`daftar-video-sumber.csv`](daftar-video-sumber.csv) sama
dengan Lampiran 1 skripsi. Kolomnya:

| Kolom | Isi |
|---|---|
| `lembaga` | Lembaga yang dijadikan kata kunci pencarian |
| `no` | Nomor urut video dalam kelompok lembaga |
| `judul` | Judul video saat data diambil |
| `video_id` | ID video YouTube |
| `url` | Tautan video |
| `komentar` | Jumlah komentar utama yang diperoleh |

| Lembaga | Video | Komentar |
|---|---:|---:|
| Tentara Nasional Indonesia | 12 | 3.549 |
| Presiden | 11 | 1.859 |
| Kejaksaan Agung | 11 | 1.493 |
| Komisi Pemberantasan Korupsi | 12 | 368 |
| Kepolisian Republik Indonesia | 12 | 340 |
| Dewan Perwakilan Rakyat | 10 | 778 |
| **Jumlah** | **68** | **8.387** |

Satu video (`AYol4I260b0`) muncul pada pencarian dua lembaga (Kepolisian dan
DPR), sehingga ada 67 video unik. Korpus yang dianalisis berjumlah 8.385
komentar sebagaimana dilaporkan di skripsi. Daftar ini adalah potret hasil
pencarian saat pengambilan data; video dapat dihapus atau komentarnya berubah
sesudahnya.

**Hasil analisis per komentar.** Folder [`studi-kasus/`](studi-kasus/) memuat
berkas ekspor CSV aplikasi untuk keenam analisis lembaga, dengan kolom `no`,
`teks`, `teks_preprocessed`, `sentimen`, `confidence`, dan `aspek`. Berkas
ekspor ini tidak memuat nama akun, ID komentar, maupun foto profil pemberi
komentar.

**Ringkasan agregat.** Angka per lembaga (distribusi sentimen, aspek teratas,
topik, PMI) dan metrik evaluasi per iterasi tersedia dalam bentuk JSON di
[`nlp-api-service/docs/skripsi/`](../nlp-api-service/docs/skripsi/):
`studi-kasus-per-lembaga.json`, `metrik-evaluasi.json`, `metrik-topik.json`.

**Etika dan ketentuan.** Komentar YouTube bersifat publik, tetapi tetap tunduk
pada [Ketentuan Layanan YouTube](https://www.youtube.com/t/terms) dan
[Kebijakan Developer YouTube API](https://developers.google.com/youtube/terms/developer-policies).
Data dibagikan untuk keperluan replikasi penelitian, tanpa identitas pemberi
komentar. Gunakan hanya untuk tujuan penelitian dan pendidikan.

## 2. Dataset publik untuk pengembangan model

Letak: [`nlp-api-service/data/external/`](../nlp-api-service/data/external/).
Berkas `*_gold.json` adalah data asli yang diubah ke format JSON; berkas
`*_retrain.json` adalah bentuk yang siap dikirim ke endpoint retraining.

| Dataset | Folder | Ukuran (train / valid / test) | Dipakai untuk | Sumber |
|---|---|---|---|---|
| SmSA (IndoNLU) | `smsa/` | 11.000 / 1.260 / 500 | Evaluasi dan kalibrasi model sentimen | [IndoNLU – SmSA](https://github.com/IndoNLP/indonlu/tree/master/dataset/smsa_doc-sentiment-prosa) |
| TermA (IndoNLU) | `terma/` | 1.964 / 1.000 / 1.000 | Data latih ekstraksi aspek | [IndoNLU – TermA](https://github.com/IndoNLP/indonlu/tree/master/dataset/terma_term-extraction-airy) |
| KEPS (IndoNLU) | `keps/` | 799 / 200 / 247 | Eksperimen ekstraksi aspek | [IndoNLU – KEPS](https://github.com/IndoNLP/indonlu/tree/master/dataset/keps_keyword-extraction-prosa) |
| NusaX-senti (Indonesia) | `nusax/` | – / 100 / 400 | Pemeriksaan tumpang tindih dengan SmSA dan uji generalisasi pada 57 dokumen yang bersih | [NusaX](https://github.com/IndoNLP/nusax) |

Kamus kata tidak baku dan singkatan untuk normalisasi disusun sendiri di
`nlp-api-service/app/preprocessing/text_cleaner.py`, bukan diambil dari kamus
luar.

Lisensi setiap dataset mengikuti repositori sumbernya; periksa tautan di atas
sebelum mendistribusikan ulang. Bila memakai data ini, kutip makalah aslinya
(IndoNLU: Wilie dkk., 2020; NusaX: Winata dkk., 2023).

## 3. Data yang disusun dalam penelitian

Letak: [`nlp-api-service/data/`](../nlp-api-service/data/).

| Berkas | Isi |
|---|---|
| `corpus.json` | 1.159 komentar YouTube untuk penyusunan dataset aspek |
| `aspect_dataset_label_studio.json`, `aspect_dataset_retrain.json` | Anotasi aspek atas korpus YouTube (format Label Studio dan format latih) |
| `aspect_news_*.json` | Dataset aspek dari teks berita (eksperimen K2) |
| `aspect_lexicon.json`, `aspect_news_lexicon.json` | Leksikon aspek |
| `external/youtube/test_gold.json` | 885 komentar YouTube berlabel aspek (span) |
| `external/youtube/heldout_gold.json` | 177 komentar hold-out untuk evaluasi aspek |
| `external/k6_train_retrain.json` dan sejenisnya | Gabungan data latih per kondisi eksperimen (K1–K6) |
| `experiments/*.json` | Keluaran eksperimen yang dikutip dokumen metodologi |
| `stopwords_id.txt` | Daftar stopword bahasa Indonesia |

Setiap angka di dokumen metodologi dapat dihitung ulang dari berkas ini dengan
skrip di [`nlp-api-service/scripts/`](../nlp-api-service/scripts/).

## 4. Contoh data

[`contoh/contoh-komentar.csv`](contoh/contoh-komentar.csv) berisi 30 komentar
**sintetis**, ditulis khusus untuk mencoba aplikasi. Isinya tidak berasal dari
komentar nyata, dan nama lembaganya disamarkan (Lembaga A, B, C).

| Kolom | Isi |
|---|---|
| `no` | Nomor urut |
| `lembaga` | Nama lembaga samaran, bisa dipakai sebagai kolom pemisah |
| `video_id` | ID video samaran, bisa dipakai sebagai kolom pemisah |
| `komentar` | Teks yang dianalisis |

Cara memakainya: buka **Analisis Baru → Upload File**, unggah berkas ini,
centang "baris pertama berisi nama kolom", pilih `komentar` sebagai kolom
teks, dan (opsional) pilih `lembaga` atau `video_id` sebagai kolom pemisah.
