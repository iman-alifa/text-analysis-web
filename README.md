# Otomatisasi Analisis Sentimen Berbasis Aspek dan Pemodelan Topik

Repositori kode sumber skripsi **"Otomatisasi Analisis Sentimen Berbasis Aspek dan
Pemodelan Topik Menggunakan Pendekatan Machine Learning dan Natural Language
Processing (Studi Kasus: Analisis Opini Publik pada Top Search Video terkait
Lembaga Pemerintahan)"**.

Repositori ini memuat seluruh sistem dalam satu tempat: aplikasi web (Laravel)
di akar repositori dan layanan analisis teks (FastAPI) di folder
[`nlp-api-service/`](nlp-api-service/).

## Identitas

| | |
|---|---|
| Penulis | Iman Alifa Novansyah |
| NIM | 222212663 |
| Kelas | 4SD1 |
| Perguruan tinggi | Politeknik Statistika STIS |
| Dosen pembimbing | Erna Nurmawati, S.ST, MT |
| Dosen penguji | Siti Mariyah, S.S.T., M.T., Ph.D · Budi Yuniarto, S.S.T., M.Si |
| Tahun | 2026 |
| Akun GitHub | [iman-alifa](https://github.com/iman-alifa) |

## Tautan

| | |
|---|---|
| Aplikasi web | <https://text-analyze.ing.biz.id> |
| Pedoman penggunaan (dalam aplikasi) | <https://text-analyze.ing.biz.id/panduan> |
| Kode sumber | <https://github.com/iman-alifa/text-analysis-web> |

## Ringkasan

Aplikasi web untuk mengotomatiskan analisis teks berbahasa Indonesia dari hulu
ke hilir: pengambilan komentar YouTube, *preprocessing*, klasifikasi sentimen,
ekstraksi aspek, pemodelan topik, asosiasi aspek–topik, sampai koreksi oleh
pengguna yang dipakai untuk melatih ulang model (*active learning* berbasis
*uncertainty sampling*). Pengembangan mengikuti kerangka **CRISP-ML(Q)**.

| Komponen | Model / metode |
|---|---|
| Sentimen | IndoBERT (`crypter70/IndoBERT-Sentiment-Analysis`) + *temperature scaling* |
| Ekstraksi aspek | IndoBERT (`indobenchmark/indobert-base-p1`) *fine-tuned* sebagai *token classification* (BIO) |
| Topik | BERTopic (embedding IndoBERT, UMAP, HDBSCAN) |
| Asosiasi aspek–topik | *Pointwise Mutual Information* (PMI) |
| Interpretasi naratif | Gemini (opsional, hanya lapisan penjelas) |

Diterapkan pada **8.385 komentar YouTube** dari video terkait enam lembaga
pemerintahan. Setelah siklus *active learning*, hasil evaluasi pada test set
hold-out:

| Ukuran | Hasil |
|---|---|
| Macro-F1 sentimen | 78,39% |
| F1 ekstraksi aspek | 80,06% |
| *Coherence score* topik (c_v) | 0,4998 |
| Skor kegunaan CSUQ | 4,77 dari 5,00 |

Rincian metodologi dan angka per iterasi ada di buku skripsi dan di
[`nlp-api-service/docs/`](nlp-api-service/docs/).

## Arsitektur

```
 Pengguna (peramban)
        │  HTTPS
        ▼
┌──────────────────────────────┐        HTTP + X-API-Key        ┌──────────────────────────────┐
│ Aplikasi web (Laravel 12)    │ ─────────────────────────────▶ │ nlp-api-service (FastAPI)    │
│ - formulir, hasil, koreksi   │   /api/analyze/*, /retrain/*   │ - preprocessing (TextCleaner)│
│ - antrean job (database)     │ ◀───────────────────────────── │ - sentimen, aspek, topik     │
│ - evaluasi hold-out, admin   │          JSON hasil            │ - PMI, retraining            │
└──────────────┬───────────────┘                                └──────────────┬───────────────┘
               │                                                               │
               ▼                                                               ▼
           MySQL 8                                                 Bobot model (models/)
   (analisis, koreksi, evaluasi)                                   tidak ikut repositori
```

Aplikasi web **tidak menjalankan NLP sendiri**: seluruh pemodelan ada di
layanan Python dan dihubungi lewat HTTP. Penjelasan lengkap ada di
[docs/DOKUMENTASI_SISTEM.md](docs/DOKUMENTASI_SISTEM.md).

## Struktur repositori

```
text-analysis-web/
├── README.md                    ← dokumen ini
├── docs/                        ← dokumentasi sistem & penggunaan
│   ├── DOKUMENTASI_SISTEM.md      arsitektur, alur data, modul, basis data, API
│   ├── PANDUAN_PENGGUNAAN.md      cara memakai aplikasi, langkah demi langkah
│   ├── DEPLOYMENT.md              pemasangan di server (satu VPS)
│   ├── PANDUAN_EVALUASI.md        prosedur evaluasi hold-out / golden dataset
│   └── PEDOMAN_ANOTASI.md         aturan pelabelan sentimen dan aspek
├── dataset/                     ← daftar video sumber, contoh data, atribusi
│
├── app/                         ← kode aplikasi web (Laravel)
│   ├── Http/Controllers/          AnalysisController, AnalysisFeedbackController,
│   │                              YouTubeScraperController, PanduanController,
│   │                              Admin/TrainingController, Admin/EvaluationController
│   ├── Jobs/                      ProcessTextAnalysis, RetrainModel, RunHoldoutEvaluation
│   ├── Services/                  NLPApiService (klien layanan NLP), RetrainingService,
│   │                              AutoRetrainService, ConfidenceReportService, LlmService, …
│   │   └── Evaluation/            ClassificationMetrics (Wilson, kappa, bootstrap),
│   │                              EvaluationComparisonService (McNemar), GoldenSamplingService
│   ├── Models/                    TextAnalysis, AnalysisResult, TrainingItem, ModelTraining,
│   │                              EvaluationDataset/Item/Run, …
│   ├── Console/Commands/          perintah artisan (evaluation:*, retrain:*, deploy:check)
│   ├── Helpers/                   ChartHelper, ResultPresenter
│   └── Support/                   TextFingerprint, PoolFingerprint, ScraperExport
├── config/                      ← konfigurasi (analysis.php, services.php, queue.php)
├── database/migrations/         ← skema basis data
├── resources/views/             ← tampilan Blade + Tailwind
├── routes/web.php               ← seluruh rute aplikasi
├── public/                      ← document root (aset, video panduan)
├── tests/                       ← tes PHPUnit (Feature + Unit)
├── deploy.sh, build-release.sh, backup-db.sh
├── .env.example                 ← templat konfigurasi lokal (tanpa nilai rahasia)
├── .env.production.example      ← templat konfigurasi produksi
│
└── nlp-api-service/             ← layanan analisis teks (Python / FastAPI)
    ├── app/
    │   ├── main.py                endpoint API
    │   ├── config.py              pengaturan (ambang, seed, model dasar)
    │   ├── security.py            kunci API dan pembatas laju
    │   ├── preprocessing/         text_cleaner.py (TextCleaner, satu-satunya jalur pembersihan)
    │   ├── services/              sentiment_service, aspect_service, topic_service,
    │   │                          association_service
    │   ├── schemas/               skema permintaan/respons (Pydantic)
    │   └── utils/                 training.py (retraining), topic_metrics.py
    ├── data/                      korpus, dataset publik, hasil eksperimen
    ├── docs/                      metodologi per modul dan bahan skripsi
    ├── scripts/                   skrip eksperimen & evaluasi yang bisa dijalankan ulang
    ├── tests/                     tes pytest
    ├── requirements.txt, Dockerfile, run.py
    └── .env.example
```

## Menjalankan secara lokal

Kebutuhan: PHP 8.2+, Composer 2, Node.js 18+, MySQL 8, Python 3.11.

**1. Layanan NLP** (terminal pertama)

```bash
cd nlp-api-service
python -m venv venv
source venv/bin/activate            # Windows: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env                # isi API_KEY, atau ubah APP_ENV=development
python run.py                       # http://127.0.0.1:8001/health
```

Bobot model dasar diunduh otomatis dari Hugging Face saat permintaan pertama.
Checkpoint hasil *fine-tuning* (`models/`) tidak ikut repositori karena
ukurannya sekitar 1,4 GB.

**2. Aplikasi web** (terminal kedua, di akar repositori)

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_*, NLP_API_URL=http://127.0.0.1:8001, dan NLP_API_KEY (= API_KEY layanan)
php artisan migrate
php artisan storage:link
npm install && npm run build
composer dev                        # server + worker antrean + log + Vite
```

Buka <http://127.0.0.1:8000>. Tanpa worker antrean (`queue:work`), analisis
akan berhenti di status "diproses".

Pemasangan di server dijelaskan di [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [docs/DOKUMENTASI_SISTEM.md](docs/DOKUMENTASI_SISTEM.md) | Arsitektur, alur analisis, *active learning*, evaluasi, basis data, endpoint API, konfigurasi |
| [docs/PANDUAN_PENGGUNAAN.md](docs/PANDUAN_PENGGUNAAN.md) | Cara memakai aplikasi untuk pengguna dan admin |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Pemasangan produksi: server, Nginx, Supervisor, systemd, HTTPS, cadangan |
| [docs/PANDUAN_EVALUASI.md](docs/PANDUAN_EVALUASI.md) | Prosedur evaluasi hold-out dan pelaporan statistik |
| [docs/PEDOMAN_ANOTASI.md](docs/PEDOMAN_ANOTASI.md) | Pedoman pelabelan golden dataset |
| [nlp-api-service/README.md](nlp-api-service/README.md) | Layanan NLP: menjalankan, endpoint, catatan operasional |
| [nlp-api-service/docs/](nlp-api-service/docs/) | Metodologi preprocessing, sentimen, aspek, topik, dan temuan |
| [dataset/README.md](dataset/README.md) | Data studi kasus, dataset publik, dan contoh data |

## Dataset

Lihat [dataset/README.md](dataset/README.md): daftar 68 video sumber korpus
studi kasus, berkas contoh untuk mencoba aplikasi, dan atribusi dataset publik
(IndoNLU SmSA, TermA, KEPS; NusaX) yang dipakai untuk pelatihan dan pengujian
model.

## Pengujian

```bash
composer test                                  # tes aplikasi web (PHPUnit)
cd nlp-api-service && python -m pytest tests/ -q   # tes layanan NLP
```

Tes web memakai SQLite dalam memori dan memalsukan seluruh permintaan HTTP,
sedangkan tes layanan NLP tidak memuat bobot model. Keduanya bisa dijalankan
tanpa layanan lain yang hidup.

## Keamanan

- Tidak ada kunci API, kata sandi, atau berkas `.env` di repositori. Seluruh
  nilai rahasia diisi lewat `.env` lokal yang diabaikan Git; `.env.example`
  hanya berisi nama kunci.
- Layanan NLP menuntut header `X-API-Key` pada setiap endpoint kecuali `/` dan
  `/health`, dan menolak berjalan di produksi tanpa kunci.
- Pada pemasangan produksi, layanan NLP hanya mendengarkan di `127.0.0.1`.

## Atribusi

Kerangka aplikasi: [Laravel](https://laravel.com) (MIT),
[FastAPI](https://fastapi.tiangolo.com) (MIT). Model dasar:
[indobenchmark/indobert-base-p1](https://huggingface.co/indobenchmark/indobert-base-p1)
dan [crypter70/IndoBERT-Sentiment-Analysis](https://huggingface.co/crypter70/IndoBERT-Sentiment-Analysis).
Pemodelan topik: [BERTopic](https://maartengr.github.io/BERTopic/). Stemming:
[PySastrawi](https://github.com/har07/PySastrawi). Lisensi dataset publik
mengikuti sumber masing-masing (lihat [dataset/README.md](dataset/README.md)).
