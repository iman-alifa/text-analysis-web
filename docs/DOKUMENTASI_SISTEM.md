# Dokumentasi Sistem

Dokumen ini menjelaskan cara kerja sistem secara teknis: komponen, alur data,
modul analisis, siklus *active learning*, evaluasi, basis data, dan antarmuka
antar komponen. Cara memakai aplikasi dijelaskan terpisah di
[PANDUAN_PENGGUNAAN.md](PANDUAN_PENGGUNAAN.md), dan pemasangan di server di
[DEPLOYMENT.md](DEPLOYMENT.md).

Daftar isi:

1. [Gambaran umum](#1-gambaran-umum)
2. [Komponen dan teknologi](#2-komponen-dan-teknologi)
3. [Alur analisis](#3-alur-analisis)
4. [Preprocessing](#4-preprocessing)
5. [Modul analisis](#5-modul-analisis)
6. [Keyakinan model dan ambang tinjauan](#6-keyakinan-model-dan-ambang-tinjauan)
7. [Siklus active learning](#7-siklus-active-learning)
8. [Evaluasi hold-out](#8-evaluasi-hold-out)
9. [Fitur pendukung](#9-fitur-pendukung)
10. [Basis data](#10-basis-data)
11. [Antarmuka API layanan NLP](#11-antarmuka-api-layanan-nlp)
12. [Rute aplikasi web](#12-rute-aplikasi-web)
13. [Antrean dan batas waktu](#13-antrean-dan-batas-waktu)
14. [Konfigurasi](#14-konfigurasi)
15. [Perintah artisan](#15-perintah-artisan)
16. [Pengujian](#16-pengujian)

---

## 1. Gambaran umum

Sistem terdiri atas dua aplikasi yang berjalan terpisah dan berkomunikasi
lewat HTTP:

| Bagian | Letak di repositori | Peran |
|---|---|---|
| Aplikasi web | akar repositori | Antarmuka pengguna, penyimpanan data, antrean job, koreksi, evaluasi, admin |
| Layanan NLP | `nlp-api-service/` | Seluruh pemrosesan bahasa: preprocessing, sentimen, aspek, topik, PMI, retraining |

Pemisahan ini disengaja. Model PyTorch membutuhkan memori besar dan dimuat
sekali per proses, sedangkan aplikasi web melayani banyak permintaan pendek.
Aplikasi web tidak menjalankan model apa pun; ia mengirim teks ke layanan NLP
dan menyimpan hasilnya.

```
┌───────────────────────── Aplikasi web (Laravel 12, PHP 8.2+) ─────────────────────────┐
│                                                                                        │
│  Controller ──▶ TextAnalysis (DB) ──▶ ProcessTextAnalysis (job antrean)               │
│      ▲                                        │                                        │
│      │ polling status                         ▼                                        │
│  Halaman hasil ◀── AnalysisResult (DB) ◀── NLPApiService ── HTTP + X-API-Key ──┐       │
│      │                                                                          │       │
│      ▼                                                                          │       │
│  Koreksi pengguna ──▶ training_items ──▶ RetrainingService ──▶ RetrainModel ────┤       │
│                                                                                 │       │
│  Golden dataset ──▶ RunHoldoutEvaluation ──▶ evaluation_runs ───────────────────┤       │
└─────────────────────────────────────────────────────────────────────────────────┼───────┘
                                                                                  ▼
┌──────────────────────── nlp-api-service (FastAPI, Python 3.11) ─────────────────────────┐
│  TextCleaner ──▶ SentimentService · AspectService · TopicService · AssociationService   │
│                  retraining (lock per model, dari bobot dasar, seed tetap)               │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

## 2. Komponen dan teknologi

| Lapisan | Teknologi |
|---|---|
| Web | Laravel 12, Blade, Tailwind CSS 3, Alpine.js, Chart.js, wordcloud |
| Autentikasi | Laravel Breeze, ditambah kolom `role` (`user`/`admin`) |
| Basis data | MySQL 8 (produksi), SQLite dalam memori (tes) |
| Antrean | Driver `database`; koneksi `training` terpisah untuk pelatihan ulang |
| Layanan NLP | FastAPI, Uvicorn (`--workers 1`), Pydantic v2 |
| Model | PyTorch, Hugging Face Transformers, BERTopic (UMAP + HDBSCAN), scikit-learn, Sastrawi |
| Interpretasi | Google Gemini lewat REST (opsional) |
| Sumber data | YouTube Data API v3, dengan `yt-dlp` sebagai cadangan |

Kode inti per bagian:

| Berkas | Fungsi |
|---|---|
| `app/Http/Controllers/AnalysisController.php` | Membuat analisis, menampilkan hasil, ekspor, interpretasi AI |
| `app/Jobs/ProcessTextAnalysis.php` | Menjalankan analisis di antrean dan menulis kemajuan |
| `app/Services/NLPApiService.php` | Klien HTTP layanan NLP; pemecahan batch dan penggabungan hasil |
| `app/Services/FileProcessingService.php` | Membaca CSV, TXT, XLSX, XLS beserta kolom pemisah |
| `app/Http/Controllers/AnalysisFeedbackController.php` | Halaman koreksi pengguna |
| `app/Services/RetrainingService.php` | Menyusun data latih dan semua penjaga pelatihan ulang |
| `app/Services/AutoRetrainService.php` | Pemicu pelatihan otomatis setelah koreksi |
| `app/Jobs/RetrainModel.php` | Memanggil `/api/retrain/*` dan mencatat hasilnya |
| `app/Services/Evaluation/*` | Golden dataset, metrik, perbandingan antar iterasi |
| `app/Services/ConfidenceReportService.php` | Ambang tinjauan dan laporan keyakinan |
| `nlp-api-service/app/main.py` | Seluruh endpoint layanan NLP |
| `nlp-api-service/app/preprocessing/text_cleaner.py` | Satu-satunya jalur pembersihan teks |
| `nlp-api-service/app/services/*.py` | Model sentimen, aspek, topik, dan PMI |
| `nlp-api-service/app/utils/training.py` | Rutin *fine-tuning* dan provenance bobot |

## 3. Alur analisis

1. **Masukan.** `AnalysisController::store` menerima teks manual (satu per
   baris) atau berkas. `FileProcessingService` membaca berkas sesuai
   pengaturannya (baris judul, kolom teks, pemisah, lembar Excel) dan
   mengumpulkan nilai kolom pemisah **pada lintasan yang sama** dengan teksnya,
   supaya indeks tetap sejajar walaupun ada baris kosong yang dilewati.
2. **Validasi.** Jumlah teks dan panjang tiap teks dibatasi
   (`services.nlp_api.max_texts`, `max_text_length`; bawaan 10.000).
3. **Penyimpanan.** Satu baris `text_analyses` dibuat, dengan `raw_data`
   (larik teks), `metadata` (jumlah topik, aspek, mode aspek), dan
   `segment_data` (nilai kolom pemisah).
4. **Antrean.** `ProcessTextAnalysis` diantrekan. Job menulis kemajuan ke baris
   `text_analyses`: 10 → 20 → 30 (persiapan), 40–70 (analisis), 80 (simpan),
   90 (visualisasi), 100 (selesai). Halaman hasil menanyakan status lewat
   `GET /analysis/{id}/poll-status` setiap beberapa detik. Karena kemajuan
   dibaca langsung dari basis data, urutan langkah job harus menjaga angka ini
   naik terus.
5. **Pemanggilan layanan NLP.** `NLPApiService` mengirim teks ke
   `/api/analyze/{sentiment|aspect|topic|combined}`. Di atas 50 teks
   (`services.nlp_api.batch_size`), teks dipecah per batch; setiap prediksi
   membawa `original_index` sehingga pemetaan ke teks asli tidak bergeser
   walaupun satu batch gagal. Distribusi sentimen dihitung ulang dari seluruh
   prediksi gabungan, bukan dijumlahkan dari persentase per batch.
6. **Hasil.** Satu baris `analysis_results` per analisis menyimpan
   `predictions`, `sentiment_distribution`, `aspect_results`, `topic_results`,
   `association_results`, `metrics`, dan `visualizations` sebagai JSON.
   `metrics.pipeline_version` mencatat versi pipeline yang menghasilkannya.

Kegagalan sebagian batch tidak menggagalkan analisis; batch yang gagal dicatat
di `metrics.failed_batches`. Analisis baru dinyatakan gagal bila seluruh batch
gagal.

## 4. Preprocessing

Seluruh pembersihan teks dilakukan oleh `TextCleaner`
(`nlp-api-service/app/preprocessing/text_cleaner.py`). Tidak ada pembersihan
lain di sisi web. Pembersihan berbeda menurut kebutuhan model, sehingga ada
beberapa profil:

| Profil | Dipakai oleh | Ciri utama |
|---|---|---|
| `transformer` | Sentimen | Pembersihan ringan; tanpa stemming dan tanpa pembuangan stopword, karena IndoBERT membutuhkan kalimat utuh |
| `span` | Ekstraksi aspek | Menjaga posisi kata supaya label BIO tetap menunjuk kata yang benar |
| `bag_of_words` | Topik | Stemming Sastrawi, pembuangan stopword, panjang token minimal 2 |

Urutan langkah di `TextCleaner.clean_text`: pembuangan URL, email, mention,
dan penanda RT; tanda pagar dibuang tetapi kata tagarnya dipertahankan;
pembuangan emoji; case folding; pembersihan karakter di luar huruf, angka, dan
tanda baca bermakna; pemadatan huruf berulang; perbaikan salah ketik;
normalisasi kata tidak baku dan singkatan; lalu (sesuai profil) pembuangan
angka, stopword, dan stemming. Stopword
tambahan dikelola admin di `/admin/training` dan dikirim bersama setiap
permintaan.

Konfigurasi yang dipakai sebuah analisis ditentukan
`PreprocessingConfigResolver`: preferensi pengguna, lalu konfigurasi
`is_default`, lalu cadangan statis. Resolver yang sama dipakai oleh job
analisis, pratinjau preprocessing, dan evaluasi, supaya ketiganya memproses
teks dengan cara yang persis sama.

Rincian dan pengukurannya: `nlp-api-service/docs/METODOLOGI_PREPROCESSING.md`.

## 5. Modul analisis

### Sentimen

- Model: `crypter70/IndoBERT-Sentiment-Analysis` (turunan
  `indobenchmark/indobert-base-p1`), tiga kelas: positif, netral, negatif.
- Keyakinan dikalibrasi dengan *temperature scaling* sehingga skor keyakinan
  dapat dibaca sebagai perkiraan peluang benar.
- Setiap prediksi membawa `method`: `model`, `rule-based` (cadangan tanpa
  model), `empty` (teks habis setelah dibersihkan, tidak dinilai), atau
  `error`.
- Metodologi: `nlp-api-service/docs/METODOLOGI_ANALISIS_SENTIMEN.md`.

### Ekstraksi aspek

- Model: `indobenchmark/indobert-base-p1` yang di-*fine-tune* sebagai
  *token classification* dengan skema BIO (B-ASPECT, I-ASPECT, O).
- Model produksi (kondisi K6) dilatih dari TermA (IndoNLU) ditambah anotasi korpus YouTube *in-domain*.
- Sentimen per aspek ditentukan dari klausa tempat aspek disebut.
- Mode `automatic` (aspek ditemukan model) atau `rule-based` (pengguna
  memberi daftar aspek).
- Respons memuat `document_aspects` per dokumen, beserta bentuk dasarnya
  (`document_aspects_normalized`) untuk evaluasi.
- Metodologi: `nlp-api-service/docs/METODOLOGI_EKSTRAKSI_ASPEK.md`.

### Pemodelan topik

- BERTopic dengan embedding IndoBERT, reduksi dimensi UMAP, dan klasterisasi
  HDBSCAN. Parameter UMAP/HDBSCAN menyesuaikan ukuran korpus.
- Jumlah topik bisa otomatis (`num_topics = 0`) atau ditentukan 2–20.
- Mutu topik dilaporkan sebagai `quality`: c_v, c_npmi, diversity, dan
  outlier_rate.
- Metodologi: `nlp-api-service/docs/METODOLOGI_TOPIC_MODELING.md`.

### Asosiasi aspek–topik

`AssociationService` menghitung *Pointwise Mutual Information* antara aspek
dan topik per dokumen. Dipanggil pada analisis gabungan; untuk korpus yang
diproses per batch, aplikasi web memanggil `/api/analyze/association` setelah
seluruh batch selesai. Tabel silang dihitung di PHP
(`ChartHelper::prepareAssociationData`), dan narasinya disusun
`AssociationInsightService`.

## 6. Keyakinan model dan ambang tinjauan

`ConfidenceReportService` menentukan baris mana yang perlu ditinjau manusia.
Ambang dinyatakan sebagai toleransi galat:

```
ambang tinjauan = 1 − ANALYSIS_ERROR_TOLERANCE = 1 − 0,10 = 0,90
```

Karena keyakinan model sudah dikalibrasi, ambang 0,90 berarti "tinjau bila
perkiraan peluang salahnya di atas 10%". Satu angka ini menentukan seluruh
tampilan keyakinan: batas pita teratas sama dengan ambang tinjauan, dan pita
kedua memakai toleransi 0,30 (keyakinan 0,70).

Halaman hasil menampilkan rerata dan median keyakinan, sebaran tiga pita, dan
ajakan meninjau baris di bawah ambang. Halaman koreksi dengan `?fokus=ragu`
langsung terbatas pada baris itu, diurutkan dari yang paling tidak yakin.

## 7. Siklus active learning

Siklus yang dijalankan adalah *pool-based batch-mode active learning* dengan
*uncertainty sampling*:

```
analisis ──▶ baris berkeyakinan rendah ──▶ koreksi pengguna ──▶ kolam koreksi
   ▲                                                              │
   │                                                              ▼
   └──── bobot baru (bila lolos) ◀── fine-tuning dari bobot dasar ◀┘
                                         │
                                         ▼
                              evaluasi pada test set hold-out
```

**Dari prediksi ke baris koreksi.** Prediksi disimpan sebagai satu JSON, sedangkan
koreksi membutuhkan baris. `TrainingItemService::extractJsonToTable` memecah
hasil analisis menjadi `training_items` (satu baris per teks) saat halaman
koreksi pertama kali dibuka. Teks yang tidak bisa dinilai (misalnya hanya
emoji) tidak dibuatkan baris.

**Koreksi.** Pengguna memilih sentimen yang benar dan menyunting aspek. Hanya
baris yang benar-benar ditinjau yang disimpan (`is_corrected`, `verified_at`).
Koreksi dikirim sebagai satu medan JSON supaya ratusan koreksi tidak terpotong
oleh batas `max_input_vars` PHP.

**Protokol kolam.** Setiap putaran melatih ulang dari **bobot dasar yang
tetap** dengan seed tetap, atas **seluruh kolam koreksi** yang sudah
terkumpul, bukan hanya koreksi terbaru. Hasilnya dapat direproduksi dari data
koreksi yang tersimpan. Sidik jari kolam (`PoolFingerprint`, SHA-256 atas teks
terurut) dicatat per pelatihan.

**Penjaga.** Semuanya berada di `RetrainingService::dispatch()`, sehingga
berlaku sama untuk ketiga pemicu (koreksi pengguna, tombol admin, perintah
`retrain:analysis`):

| Penjaga | Alasan |
|---|---|
| Kolam minimal 50 sampel (`MIN_LAYAK`) | Di bawah itu set validasi 20% terlalu kecil untuk menilai regresi |
| Tidak ada pelatihan lain yang berjalan | Mencegah dua pelatihan menimpa bobot yang sama |
| Pelatihan sebelumnya sudah dievaluasi (bila ada test set beku) | Bobot lama tertimpa begitu bobot baru diterima; iterasi yang belum diukur tidak bisa diukur belakangan |
| Baris golden dataset beku disingkirkan dari data latih | Mencegah kebocoran test set ke data latih (`TextFingerprint`) |

Penjaga dapat dilewati dengan sengaja lewat opsi "paksa" / `--force`.

**Di sisi layanan.** Pelatihan dijalankan di thread terpisah dengan lock per
model; permintaan kedua untuk model yang sama dibalas `409`. Bobot baru
ditolak bila metrik validasinya menurun (`rejected_for_regression`), dan
riwayat mencatat statusnya sebagai `rejected`.

**Pemicu otomatis.** `AutoRetrainService` memeriksa setelah setiap penyimpanan
koreksi: jumlah koreksi baru sejak batas kolam terakhir (bawaan 10), jeda
antar pelatihan, dan saklar `ANALYSIS_AUTO_RETRAIN`. Perintah
`analysis:auto-retrain` juga dijadwalkan tiap jam lewat `schedule:run`.

**Mode tunda.** Selama fase uji coba pengguna, pelatihan dapat ditunda dari
`/admin/training`. Pemicu yang muncul dicatat di `retrain_deferrals` beserta
batas waktunya, lalu diputar ulang berurutan setelah golden dataset siap;
kolam tiap putaran dipotong pada batas waktu pemicunya.

**Evaluasi otomatis.** Setelah satu putaran pelatihan selesai,
`AutoEvaluationService` mengantrekan evaluasi pada test set beku yang aktif.

Riwayat pelatihan (`model_trainings`) mencatat jumlah sampel, ukuran set
validasi, selisih F1, suhu kalibrasi, dan sidik jari kolam.

## 8. Evaluasi hold-out

Metrik yang dihitung dari data koreksi tidak bisa dipakai untuk menilai model,
karena data itu juga yang dilatihkan. Evaluasi karena itu memakai **golden
dataset** yang terpisah secara struktural (tabel `evaluation_*`, bukan kolom
penanda di `training_items`).

| Tahap | Pelaksana |
|---|---|
| Pengambilan sampel acak berstrata dari korpus | `GoldenSamplingService`, `/admin/evaluation/sampling`, `evaluation:sample` |
| Anotasi manusia | berkas CSV dan pedoman [PEDOMAN_ANOTASI.md](PEDOMAN_ANOTASI.md) |
| Impor, kappa antar-anotator | `GoldenDatasetService`, `evaluation:import` |
| Pembekuan (isi dikunci, `content_hash`, normalisasi aspek) | `freeze()` |
| Pengukuran model aktif | `HoldoutEvaluationService`, `RunHoldoutEvaluation`, `evaluation:run` |
| Perbandingan antar iterasi | `EvaluationComparisonService`, `evaluation:report` |

Metrik yang dilaporkan (`ClassificationMetrics`):

- Sentimen: akurasi dengan selang Wilson, precision/recall/F1 macro dan
  weighted, selang bootstrap macro-F1 (seed tetap), Cohen kappa, akurasi
  tebak-kelas-mayoritas sebagai pembanding, matriks kebingungan, dan tabel
  reliabilitas keyakinan dengan ECE.
- Aspek: micro/macro-F1 dengan pencocokan ketat dan parsial (bentuk dasar),
  macro-F1 untuk aspek yang muncul minimal dua kali, exact match, dan Hamming
  loss. Baris yang memang tidak beraspek tetap dinilai, sehingga aspek
  karangan model dihitung sebagai *false positive*.
- Topik: c_v, c_npmi, diversity, dan outlier_rate dari analisis korpus sumber.
- Perbandingan iterasi: uji McNemar (binomial eksak untuk jumlah pembalikan
  kecil) beserta catatan daya uji.

Evaluasi menolak hasil yang tidak utuh (batch gagal, baris tanpa prediksi,
prediksi `method: error`), supaya metrik tidak pernah dihitung dari sebagian
test set. Prosedur lengkapnya: [PANDUAN_EVALUASI.md](PANDUAN_EVALUASI.md).

## 9. Fitur pendukung

| Fitur | Letak | Keterangan |
|---|---|---|
| YouTube scraper | `YouTubeScraperController`, `/youtube/scraper` | Cari video atau tempel tautan, ambil komentar, ekspor CSV/XLSX/TXT, lalu langsung dianalisis. Nama berkas unduhan divalidasi `ScraperExport` |
| Rincian per kolom pemisah | `SegmentAnalysisService` | Hasil dipecah per nilai kolom (mis. video, tanggal) dari prediksi tersimpan, tanpa analisis ulang |
| Interpretasi AI | `AnalysisInterpretationService`, `LlmService` | Narasi untuk ringkasan, sentimen, aspek, topik, asosiasi, dan segmen. Angka dikirim ke model, tidak dihitung model. Menyimpan model, versi prompt, dan waktu pembangkitan |
| Ekspor | `AnalysisController::exportPdf`, `exportCsv` | PDF ringkasan; CSV per teks: `no, teks, teks_preprocessed, sentimen, confidence, aspek` |
| Daftar prediksi berpaginasi | `PredictionQueryService` | Penyaringan, pencarian, dan paginasi di server |
| Statistik publik | `PublicStatsService`, `ActiveModelService` | Angka di halaman depan diambil dari evaluasi yang mengukur bobot yang sedang aktif |
| Pedoman dalam aplikasi | `PanduanController`, `/panduan` | Bisa dibuka tanpa login, termasuk video panduan |
| Pemeriksaan kesiapan | `php artisan deploy:check` | Memeriksa kunci, debug, aset, migrasi, antrean, layanan NLP, kunci API, Gemini |

## 10. Basis data

| Tabel | Isi |
|---|---|
| `users` | Akun, `role`, preferensi konfigurasi preprocessing |
| `text_analyses` | Satu analisis: judul, jenis, masukan (`raw_data`), metadata, kolom pemisah, status, kemajuan |
| `analysis_results` | Hasil satu analisis (JSON): prediksi, distribusi, aspek, topik, asosiasi, metrik, interpretasi AI |
| `analysis_logs` | Log langkah per analisis |
| `preprocessing_configs` | Konfigurasi pembersihan teks; satu baris `is_default` |
| `custom_stopwords` | Stopword tambahan yang dikelola admin |
| `training_items` | Satu baris per teks untuk koreksi: prediksi, koreksi, status verifikasi |
| `model_trainings` | Riwayat pelatihan ulang: model, status, jumlah sampel, metrik sebelum/sesudah, sidik jari kolam |
| `retrain_deferrals`, `app_settings` | Mode tunda pelatihan dan pengaturan aplikasi |
| `evaluation_datasets` | Golden dataset: split, waktu beku, rencana pengambilan sampel, `content_hash` |
| `evaluation_items` | Teks, label emas sentimen dan aspek, label anotator kedua |
| `evaluation_runs` | Satu kali pengukuran: iterasi, info model, metrik |
| `evaluation_run_items` | Prediksi per baris pada satu pengukuran (untuk McNemar dan analisis kesalahan) |
| `evaluation_snapshots` | Potret metrik pada data koreksi (pemantauan, bukan evaluasi) |
| `jobs`, `failed_jobs`, `job_batches`, `cache`, `sessions` | Tabel bawaan Laravel |

Skemanya ada di `database/migrations/`.

## 11. Antarmuka API layanan NLP

Basis URL diatur `NLP_API_URL` (bawaan lokal `http://127.0.0.1:8001`). Semua
endpoint kecuali `/` dan `/health` menuntut header `X-API-Key` yang sama
dengan `API_KEY` layanan.

| Metode | Jalur | Fungsi |
|---|---|---|
| GET | `/`, `/health` | Status layanan, bobot yang dimuat, ambang tinjauan, sidik jari bobot aktif |
| POST | `/api/warmup` | Memuat model lebih awal |
| POST | `/api/preprocess` | Pratinjau pembersihan teks per profil (`task`) |
| POST | `/api/normalize/aspects` | Mengubah nama aspek ke bentuk dasar (stemming) |
| POST | `/api/analyze/sentiment` | Sentimen per teks, distribusi (persen), antrean tinjauan |
| POST | `/api/analyze/aspect` | Aspek per dokumen dan sentimen per aspek |
| POST | `/api/analyze/topic` | Topik, kata kunci, proporsi, mutu topik |
| POST | `/api/analyze/combined` | Ketiganya sekaligus ditambah asosiasi PMI |
| POST | `/api/analyze/association` | PMI aspek–topik dari hasil yang sudah ada |
| POST | `/api/retrain/sentiment` | *Fine-tuning* sentimen dari `{text, label}` |
| POST | `/api/retrain/aspect` | *Fine-tuning* aspek dari `{text, aspects[]}` |
| POST | `/api/retrain/preview` | Pratinjau data latih: distribusi label, ketimpangan, pembanding kelas mayoritas |

Kode status khusus:

| Kode | Arti | Penanganan di web |
|---|---|---|
| 401 / 403 | Kunci API kosong atau salah | `deploy:check` melaporkannya |
| 409 | Model yang sama sedang dilatih | Pelatihan dicatat `skipped` |
| 503 + `Retry-After` | Model sedang dilatih, analisis ditunda | Job analisis dilepas dan dicoba lagi tanpa memakan jatah percobaan |

Distribusi sentimen dikembalikan sebagai **persen** (0–100), bukan jumlah.

## 12. Rute aplikasi web

| Kelompok | Rute | Akses |
|---|---|---|
| Publik | `/`, `/panduan`, `/login`, `/register` | Siapa pun |
| Dashboard | `/dashboard` | Pengguna masuk |
| Analisis | `/analysis`, `/analysis/create`, `/analysis/{id}`, ekspor PDF/CSV, interpretasi | Pemilik analisis |
| Koreksi | `/analysis/{id}/feedback` | Pemilik analisis |
| YouTube | `/youtube/scraper` dan turunannya | Pengguna masuk |
| Pengaturan | `/profile` | Pengguna masuk |
| Data latih | `/admin/training` | Admin |
| Evaluasi | `/admin/evaluation` | Admin |

Daftar lengkapnya ada di `routes/web.php`. Admin hanya dibutuhkan untuk
pengawasan; seluruh siklus analisis, koreksi, dan pelatihan ulang dapat
berjalan tanpa akun admin.

## 13. Antrean dan batas waktu

| Job | Koneksi / antrean | Batas waktu job | `retry_after` |
|---|---|---|---|
| `ProcessTextAnalysis` | `database` / `default` | 3.600 detik | 4.200 detik |
| `RunHoldoutEvaluation` | `database` / `default` | 1.800 detik | 4.200 detik |
| `RetrainModel` | `training` / `training` | 7.200 detik | 7.500 detik |

Rantai batas waktu dijaga selalu naik: batas HTTP < batas job < `retry_after`.
Bila `retry_after` lebih kecil dari batas job, job yang masih berjalan diambil
worker kedua dan pekerjaan yang sama berjalan dua kali. `deploy:check`
memeriksa rantai ini.

| Batas HTTP | Kunci | Bawaan |
|---|---|---|
| Analisis | `NLP_API_TIMEOUT` | 600 detik |
| Pemodelan topik | `NLP_API_TOPIC_TIMEOUT` | 2.400 detik |
| Pelatihan ulang | `NLP_API_RETRAIN_TIMEOUT` | 6.600 detik |

Permintaan yang kehabisan waktu tidak diulang, karena layanan tidak
menghentikan pekerjaan saat klien memutus koneksi.

## 14. Konfigurasi

Seluruh kunci terdokumentasi di `.env.example` (web) dan
`nlp-api-service/.env.example` (layanan). Yang terpenting:

| Kunci | Bagian | Keterangan |
|---|---|---|
| `NLP_API_URL`, `NLP_API_KEY` | web | Alamat dan kunci layanan NLP |
| `API_KEY` | layanan | Harus sama dengan `NLP_API_KEY` |
| `DB_*` | web | Koneksi MySQL |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | web | Opsional; tanpa ini fitur interpretasi AI mati dan halaman lain tetap utuh |
| `YOUTUBE_API_KEY` | web | Opsional; untuk YouTube scraper |
| `ANALYSIS_ERROR_TOLERANCE` | web | Toleransi galat, menentukan ambang tinjauan (bawaan 0,10) |
| `ANALYSIS_AUTO_RETRAIN`, `ANALYSIS_AUTO_RETRAIN_MIN`, `ANALYSIS_AUTO_RETRAIN_COOLDOWN` | web | Pemicu pelatihan otomatis |
| `ANALYSIS_EVAL_SEED` | web | Seed bootstrap evaluasi (bawaan 42) |
| `DB_QUEUE_RETRY_AFTER` | web | Harus lebih besar dari batas waktu job |
| `MODEL_PATH`, `ASPECT_BASELINE_CHECKPOINT` | layanan | Letak bobot model |

Pengaturan aplikasi dibaca lewat `config()`, bukan `env()` langsung, sehingga
`php artisan config:cache` aman dipakai di produksi.

## 15. Perintah artisan

| Perintah | Fungsi |
|---|---|
| `nlp:test` | Memeriksa koneksi ke layanan NLP |
| `deploy:check [--produksi]` | Pemeriksaan kesiapan pemasangan |
| `analysis:auto-retrain` | Menjalankan pemeriksaan pemicu pelatihan otomatis |
| `retrain:analysis [<id>] [--model=] [--force]` | Pelatihan ulang manual; tanpa id menampilkan koreksi per analisis |
| `retrain:tunda [status\|nyalakan\|matikan\|putar-ulang\|hentikan\|lanjutkan]` | Mode tunda pelatihan |
| `training:purge-unscorable [--apply]` | Menghapus baris latih yang tidak bisa dinilai |
| `evaluation:sample --analysis=N [--size=] [--percent=]` | Mengambil sampel golden dataset |
| `evaluation:import <berkas> [--freeze]` | Mengimpor golden dataset |
| `evaluation:split <berkas>` | Pemecahan train/test berstrata |
| `evaluation:run <dataset-id> [--compare-last]` | Mengukur model aktif pada test set |
| `evaluation:report [--dataset=] [--out=]` | Tabel markdown untuk naskah |
| `evaluation:leakage [--dataset=]` | Memeriksa irisan golden dataset dan data latih |
| `evaluation:normalize-aspects`, `evaluation:merge-aspect-label`, `evaluation:audit-aspects` | Pemeliharaan label aspek |
| `analysis:repair-distribution`, `analysis:reprocess-aspect` | Perbaikan data hasil versi lama |

## 16. Pengujian

| Bagian | Perintah | Cakupan |
|---|---|---|
| Web | `composer test` | Pipeline analisis, batch, koreksi, pelatihan ulang dan penjaganya, evaluasi dan statistiknya, tampilan hasil, keamanan unduhan, pemeriksaan deploy |
| Layanan NLP | `cd nlp-api-service && python -m pytest tests/ -q` | Preprocessing, kontrak API, BIO tagging, penggabungan aspek, PMI, concurrency retraining, provenance bobot |

Tes web memakai SQLite dalam memori. Seluruh permintaan HTTP ke layanan NLP
dan Gemini dipalsukan, dan permintaan yang tidak dipalsukan langsung gagal
(`Http::preventStrayRequests()`), sehingga tes tidak pernah menyentuh layanan
sungguhan. Tes layanan NLP tidak memuat bobot model.
