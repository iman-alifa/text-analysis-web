# AnalisisTeks

Aplikasi web untuk analisis teks berbahasa Indonesia: **analisis sentimen**,
**ekstraksi aspek**, dan **pemodelan topik**, dengan alur koreksi pengguna yang
ikut melatih ulang model (*active learning*).

Aplikasi ini **tidak menjalankan NLP sendiri**. Seluruh pemodelan berjalan di
layanan FastAPI terpisah (`nlp-api-service`) yang dihubungi lewat HTTP.

---

## Kebutuhan

| Komponen | Versi |
|---|---|
| PHP | 8.2 atau lebih baru |
| Composer | 2.x |
| Node.js | 18 atau lebih baru |
| MySQL | 8.0 (atau MariaDB 10.6+) |
| `nlp-api-service` | berjalan di port 8001 |

Ekstensi PHP: `pdo_mysql`, `mbstring`, `openssl`, `zip`, `gd`, `fileinfo`.

## Pemasangan

```bash
composer install
cp .env.example .env
php artisan key:generate

# Sesuaikan DB_*, NLP_API_URL, dan kunci API di .env
php artisan migrate
php artisan storage:link

npm install && npm run build
```

Jalankan dua proses, dan keduanya wajib:

```bash
php artisan serve      # aplikasi web
php artisan queue:work # analisis & pelatihan ulang berjalan di antrean
```

Tanpa `queue:work`, analisis akan berhenti di status "diproses" selamanya.

Untuk pengembangan, `composer dev` menjalankan server, worker antrean, log, dan
Vite sekaligus.

## Kunci konfigurasi

Seluruhnya terdokumentasi di `.env.example`. Yang wajib diisi:

| Kunci | Keterangan |
|---|---|
| `NLP_API_URL` | Alamat layanan NLP, mis. `http://127.0.0.1:8001` |
| `DB_*` | Koneksi MySQL |
| `GEMINI_API_KEY` | Opsional. Tanpa ini, tombol penjelasan AI mati dan sisa halaman tetap utuh |
| `YOUTUBE_API_KEY` | Opsional, hanya untuk fitur pengambilan komentar YouTube |

Tiga kunci yang **jangan diubah sembarangan**:

- `DB_QUEUE_RETRY_AFTER` (4200) harus lebih besar dari `ProcessTextAnalysis::$timeout`
  (3600). Bila lebih kecil, job yang masih berjalan diambil worker kedua dan
  analisis yang sama berjalan berkali-kali.
- `ANALYSIS_EVAL_SEED` (42) menentukan selang kepercayaan macro-F1. Mengubahnya
  di tengah rangkaian evaluasi membuat angka lama tidak bisa dihitung ulang.
- `ANALYSIS_ERROR_TOLERANCE` (0,10) menentukan ambang tinjauan sekaligus batas
  pita keyakinan. Keduanya sengaja satu angka.

## Menyiapkan untuk produksi

Langkah lengkapnya (server, web server, worker antrean, layanan NLP, HTTPS,
dan daftar periksa) ada di **[DEPLOYMENT.md](DEPLOYMENT.md)**. Ringkasnya:

```bash
composer install --no-dev --optimize-autoloader
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

Pastikan di `.env` produksi:

```env
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` di produksi akan menampilkan jejak galat beserta isi
konfigurasi kepada pengunjung.

Arahkan document root web server ke `public/`, bukan ke akar proyek. Jalankan
`queue:work` sebagai layanan yang dipantau (systemd atau Supervisor) agar
otomatis hidup lagi bila mati.

## Struktur singkat

```
app/Http/Controllers/    Analisis, feedback, scraper, admin (training & evaluasi)
app/Services/            NLPApiService, RetrainingService, Evaluation/*
app/Support/             TextFingerprint, PoolFingerprint, ScraperExport
resources/views/         Blade + Tailwind
public/anotasi/          Aplikasi anotasi statis, tanpa backend
```

## Pengujian

```bash
composer test        # 454 tes
./vendor/bin/pint    # format kode
```

Tes memakai SQLite dalam memori dan memalsukan seluruh permintaan HTTP, jadi
tidak memerlukan layanan NLP yang berjalan.

## Catatan

Dokumen penelitian (panduan evaluasi, pedoman anotasi, catatan arsitektur)
berada di luar folder aplikasi agar tidak ikut ter-deploy.

Antarmuka dan pesan aplikasi seluruhnya berbahasa Indonesia.
