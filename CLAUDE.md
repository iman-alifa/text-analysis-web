# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Laravel 12 web application (`text-analysis-web`) — the UI/orchestration half of an Indonesian-language text analysis platform (a thesis/skripsi project). It does **no NLP itself**: all sentiment / aspect / topic modeling runs in a separate FastAPI service at `../nlp-api-service` (sibling directory, its own repo), reached over HTTP via `App\Services\NLPApiService`.

Most UI strings, log messages, and code comments are in Indonesian. Match that when editing existing views/controllers.

## Commands

```bash
composer setup          # install + .env + key:generate + migrate + npm install + npm run build
composer dev            # runs server + queue:listen + pail (logs) + vite concurrently — the normal dev loop
composer test           # config:clear then artisan test
php artisan test --filter=ProfileTest      # single test class/method
php artisan queue:work  # required for analyses to process (QUEUE_CONNECTION=database)
php artisan nlp:test    # ping the NLP API /health endpoint
php artisan analysis:repair-distribution [--apply]   # recompute sentiment_distribution on results saved before the batching fix
php artisan analysis:reprocess-aspect [--apply] [--id=N]  # re-queue aspect/combined analyses saved by an older pipeline version
./vendor/bin/pint       # formatter (Laravel Pint)
npm run dev / build     # Vite (Tailwind 3 + Alpine)
```

The NLP service must be running separately (`python run.py` in `../nlp-api-service`, uvicorn on port 8001) or every analysis job fails.

Local DB is **MySQL** (`analisis_teks_db`) despite `.env.example` defaulting to sqlite; tests use in-memory sqlite via `phpunit.xml`.

## Architecture

### Analysis pipeline (the core flow)

1. `AnalysisController::store` validates input (`input_type`: manual|file, `analysis_type`: sentiment|aspect|topic|combined), extracts texts via `FileProcessingService` (csv/txt/xlsx/xls, with per-format config: header row, text column, delimiter, sheet index, txt separator), persists a `TextAnalysis` row with `raw_data` (JSON array of texts), then dispatches `ProcessTextAnalysis`.
2. `ProcessTextAnalysis` (queued, `$timeout = 1800`, 3 tries with backoff) drives `NLPApiService` and writes **progress percentages into the `text_analyses` row** at fixed checkpoints (10/20/30 → 40–70 during analysis → 80 save → 100 done), plus `AnalysisLog` entries.
3. `NLPApiService` posts to `POST {NLP_API_URL}/api/analyze/{sentiment|aspect|topic|combined}` and `/api/preprocess`. Above `services.nlp_api.batch_size` (50) texts it chunks into batches and reports progress through a callback that maps batch N/total onto the 40–70 band. Every batched method merges predictions and sums distributions itself — keep that contract when adding endpoints.
4. Results land in a single `AnalysisResult` row (one-to-one with `TextAnalysis`): `predictions`, `sentiment_distribution`, `aspect_results`, `topic_results`, `metrics`, `visualizations` — all JSON-cast arrays.
5. The show page polls `GET /analysis/{id}/poll-status` (throttled 30/min) every ~3s until status is `completed`/`failed`.

Because progress is written to the DB rather than broadcast, any change to job step ordering must keep the percentage checkpoints monotonic — the frontend progress bar reads them directly.

### Active learning loop

Predictions are stored as one JSON blob, but correction needs rows — `TrainingItemService::extractJsonToTable` lazily explodes `AnalysisResult` into `training_items` (one row per text) the first time a feedback or admin training page is opened. It also back-fills per-row aspects by keyword-matching against the global `aspect_results` dictionary when the model returned no per-row aspects. It is idempotent only via the caller's `trainingItems()->exists()` check (see `TrainingController::syncAll`) — calling it twice on the same analysis duplicates rows.

Two correction surfaces exist over the same `training_items` table:
- **User** (`AnalysisFeedbackController`, `/analysis/{id}/feedback`) — restricted to the owner via `AnalysisPolicy::view`.
- **Admin** (`Admin\TrainingController`, `/admin/training`, behind the `admin` middleware alias → `IsAdmin` → `User::isAdmin()` on the `role` column) — DataTable workspace, bulk correct, custom stopword management, CSV export of the corrected dataset (`text,label,aspects,source_file`).

`ModelEvaluationService` computes accuracy/precision/recall/F1 for sentiment and aspects from corrected vs. predicted items, plus topic coherence (NPMI over a sliding window) in PHP.

`AnalysisResult.metrics.pipeline_version` records which version of the analysis pipeline produced a
row (`ProcessTextAnalysis::PIPELINE_VERSION`, currently 2). Bump it whenever the *meaning* of a stored
figure changes, and `analysis:reprocess-aspect` will pick up the stale rows. Do not try to detect
staleness from the data itself — the first attempt flagged correct analyses as old because their
sentences were unambiguous enough to produce no neutral class at all.

Note: `queue:work` caches code in memory. After changing the job, run `php artisan queue:restart` **and
start a fresh worker** — the restart signal only makes the current worker exit.

`TrainingController::triggerTraining` is currently a **stub** — it flashes a success message and retrains nothing. The retraining handoff is the exported CSV.

### Other subsystems

- **YouTube scraper** (`YouTubeScraperController`, ~1200 lines): search + comment scraping via the YouTube Data API v3 (`YOUTUBE_API_KEY`), with a `yt-dlp` CLI fallback when quota/API fails. Exports csv/xlsx/txt to storage and serves them through a named download route. Output feeds back in as analysis input files.
- **LLM topic labeling** (`LlmService`): Gemini (`GEMINI_API_KEY`, read via `env()` directly, not config) turns topic keyword lists into Indonesian labels+descriptions; called from `AnalysisController::generateTopicInterpretation`.
- **Charts**: `App\Helpers\ChartHelper` shapes controller data into Chart.js payloads; wordcloud via the `wordcloud` npm package, both wired in Blade `<script>` blocks rather than JS modules. `resources/js/app.js` only bootstraps Alpine + WordCloud.
- **Auth**: Laravel Breeze (Blade), plus a `role` column added by migration.

## Config

`config/services.php → nlp_api`: `url`, `timeout` (7200 default, `.env` sets 3600), `batch_size` (50), `max_texts_single_request` (100). Env keys beyond stock Laravel: `NLP_API_URL`, `NLP_API_TIMEOUT`, `YOUTUBE_API_KEY`, `GEMINI_API_KEY`.

## Reference docs

`SYSTEM_ARCHITECTURE_WEB.md`, `SYSTEM_ARCHITECTURE_SERVICE.md`, `SYSTEM_ARCHITECTURE.md`, and the `project_summary_*.md` handoff notes are long hand-written context documents for the web app and the NLP service. The service doc carries binding rules for `../nlp-api-service`: CRISP-ML(Q) commentary on model changes, preprocessing must go through the shared `TextCleaner`, and API responses must stay backward compatible — relevant here because `NLPApiService` depends on those response shapes. All of them predate current code in places and contradict each other — the service doc still calls active learning "future implementation" while the retrain endpoints exist, and the summaries disagree on whether `distribution` holds counts or percentages. Trust the "Verified NLP API contract" section above over any of them.

Tests are stock Breeze auth/profile tests only; there is no coverage of the analysis pipeline.

## Cross-repo layout (verified)

Two overlapping git repos, easy to commit into the wrong one:

- `C:\Skripsi` — outer repo (2 commits, latest `8b7933cc "Remove venv and large files"`). Tracks **both** `nlp-api-service/` and a **stale snapshot** of `text-analysis-web/` (134 files). `nlp-api-service` has no `.git` of its own — this is its only repo.
- `C:\Skripsi\text-analysis-web` — nested repo, where actual web development happens (`b173bb1`, `abdef6d`, …).

So: web-app commits go in the nested repo; NLP-service commits go in the outer one. Running `git status` from `C:\Skripsi` also lists modified `text-analysis-web/...` files from the stale snapshot — do not commit those there.

## Verified NLP API contract (supersedes the architecture docs)

The `SYSTEM_ARCHITECTURE*.md` files and `project_summary_*.md` predate the service. Verified against `../nlp-api-service/app/main.py`:

- Endpoints that exist: `GET /`, `GET /health`, `POST /api/preprocess`, `POST /api/analyze/{sentiment,aspect,topic,combined}`, **`POST /api/retrain/aspect`**, **`POST /api/retrain/sentiment`** (min 10 samples, `{text,aspects[]}` / `{text,label}` — exactly the shape of `training_items` corrections). The retraining loop is therefore blocked only on the Laravel side (`TrainingController::triggerTraining`).
- `POST /api/analyze/combined` also returns an `association` block (aspect↔topic PMI from `AssociationService`). Laravel never stores it.
- `results.distribution` from the sentiment endpoint is a **percentage** per class (0–100, sums to 100), *not* counts. Same for `aspect_sentiments[].sentiments`.
- Aspect responses include `document_aspects` (per-document aspect lists) and topic responses include `document_topics`; Laravel discards `document_aspects`.
- Models lazy-load on first request, so the first analysis after a service restart is slow; retrained checkpoints land in `../nlp-api-service/models/` and hot-reload.

## Fixes applied (31 Aug 2026)

All verified against tests in `tests/Feature/` — the pipeline had zero coverage before this.

- **`metadata` was double-encoded** (`json_encode` + `array` cast), so `num_topics`, `predefined_aspects`, and `aspect_mode` were silently ignored on every analysis ever run. `AnalysisController::store` now assigns the array; `TextAnalysis::getMetadataAttribute` decodes legacy rows too.
- **Sentiment distribution above 50 texts** — `batchSentimentAnalysis` now counts labels from the merged predictions and emits percentages, matching the single-request contract. Each prediction carries `original_index` so text mapping survives a failed batch.
- **Partial batch failures** no longer abort the run (sentiment + aspect); failures land in `metrics.failed_batches` / `results.failed_batches`, and only an all-batches-failed run throws.
- **Preprocessing config** is resolved from the user's `preprocessing_config_id` → the `is_default` row → a static fallback, always merged with `custom_stopwords` from the admin table. `aspect_mode` is honoured, falling back to `automatic` when rule-based is picked without aspects.
- **Aspect analyses now write per-row `predictions`** (from `document_aspects`), so the feedback/training pages are no longer empty for them, and `TrainingItemService` uses real aspects instead of keyword-guessing. That service is now idempotent on its own.
- **PMI was dead on the Python side**: `AssociationService` called `.get('aspect')` on plain strings from `_build_document_aspects`, so `/api/analyze/combined` always returned `association: {error: ...}`. Fixed to accept both shapes. New additive endpoint `POST /api/analyze/association` lets the batched (>50 texts) combined path get PMI too. Stored in `analysis_results.association_results`.
- **Active learning loop closed**: `TrainingController::triggerTraining` builds payloads from corrected `training_items` and dispatches `RetrainModel` (queued) against `/api/retrain/{sentiment,aspect}`. Runs are recorded in the new `model_trainings` table and shown on `/admin/training`.
- **Export PDF/CSV were routed but never implemented** (`AnalysisController@exportPdf/exportCsv` did not exist, so both buttons 500'd). Implemented, with `resources/views/analysis/export-pdf.blade.php`. The dead `analysis.process` route was removed.
- **`php artisan migrate` failed on a fresh database** — `custom_stopwords` was created by two migrations. Both are now guarded with `Schema::hasTable`. This is why the whole test suite was failing (23 of 25).
- API keys moved from `env()` to `config('services.gemini|youtube')` so `config:cache` is safe; `.env.example` now documents every key and MySQL.

### Second pass (same day, after the DB came up)

- **Mock PMI data was being rendered as real results.** The "Asosiasi Aspek & Topik" block on the results page fell back to hardcoded numbers (Koruptor/Pajak, PMI +0.71) whenever `topic_results['association']` was missing — which was always, since PMI was broken server-side. It now reads real data via `ChartHelper::prepareAssociationData` (PMI from the API, crosstab counted in PHP from `document_aspects` + `document_topics`) and shows an explicit empty state otherwise.
- **`aspect_results` exists in three shapes** across code versions: current `{aspect, count, sentiments}`, older `{aspect, positive, neutral, negative}` counts, and older still with no aspect name and all-zero values (analyses 81–87 — unrecoverable, they need re-running). `AnalysisResult::normalizedAspectResults()` unifies them; the view and `ChartHelper` consume that, so old analyses no longer render blank cards with PHP warnings.
- **`DashboardSeeder` double-encoded every JSON column** (same bug class as `metadata`), so seeded demo data was unreadable by the app. Fixed, plus `AnalysisResult::castAttribute` now transparently decodes any legacy double-encoded row.
- **Evaluation history added**: `evaluation_snapshots` records global sentiment/aspect metrics each time retraining is triggered, with a trend chart on `/admin/training`. This is what lets the thesis show model improvement across active learning iterations rather than a single point-in-time number.
- `$topic['proportion']` is now read defensively in the view (Python always sets it, but partial data used to 500 the page).

Live data repaired: `analysis:repair-distribution --apply` fixed analyses 89 (600%) and 91 (1800%) back to 100%.

Not addressed: `AnalysisController` still scopes ownership with `where('user_id', Auth::id())` rather than `AnalysisPolicy` (consistent and not a vulnerability, just two styles); analyses 81–87 hold aspect data with no aspect names and must be re-run to be usable.

## Testing

`php artisan test` — 57 tests. Beyond the stock Breeze ones:
- `tests/Feature/SentimentBatchAggregationTest.php` — batch aggregation, index mapping, partial/total batch failure.
- `tests/Feature/AnalysisPipelineTest.php` — metadata casting (incl. legacy rows), preprocessing-config resolution, aspect mode, `saveResults` for aspect/combined, `TrainingItemService`.
- `tests/Feature/RetrainTriggerTest.php` — the active learning loop end to end, including admin-only access and NLP API failure handling.
- `tests/Feature/RepairDistributionCommandTest.php` — the legacy-data repair command.
- `tests/Feature/AssociationDisplayTest.php` — PMI crosstab building and, crucially, that the results page no longer prints the old mock numbers.
- `tests/Feature/AspectResultNormalizationTest.php` — the three historical `aspect_results` shapes.

Tests hit in-memory sqlite; `Http::fake` stands in for the NLP service, so none of them need Python running.

## Integrasi UI dengan kontrak API terbaru (3 Sep 2026)

Dikerjakan mengikuti `../nlp-api-service/docs/PANDUAN_INTEGRASI_UI.md`, diverifikasi
ulang terhadap kode Python (dokumen itu mengklaim sebagian pekerjaan Laravel yang
memang sudah ada: `warmUp()`, `scorablePredictions()`, `retry_after`).

- **`review_queue` ditampilkan** sebagai tab "Perlu Ditinjau (N)" pada halaman hasil,
  diurutkan dari yang paling tidak yakin. Jalur batch **menyusun ulang antreannya
  sendiri** (`NLPApiService::buildReviewQueue`) karena tiap batch mengirim indeks
  lokalnya sendiri — menggabungkannya mentah-mentah akan menunjuk kalimat yang salah.
  Ambangnya diambil dari respons API, tidak pernah dikarang di sisi Laravel.
  Disimpan di dalam `metrics.review_queue` (tanpa kolom baru).
- **`method` per prediksi** tampil sebagai penanda: `rule-based` → "Tanpa model"
  (mutu turun ~25 poin), `empty` → "Tidak dinilai", `error` → "Gagal dinilai".
- **Penghitung mutu** (`total_empty`/`total_truncated`/`total_failed`) hanya muncul
  bila tidak nol.
- **Formulir topik**: `num_topics` kini select dengan opsi **Otomatis (0)** dan 2–20;
  1 ditolak validasi karena API menolaknya (422). Petunjuk lama "Rekomendasi 3-7 topik"
  dihapus — terukur keliru (k=14–20 memberi c_v lebih baik). `$request->num_topics`
  dulu dicek truthy sehingga mode otomatis (0) dibuang diam-diam; kini `filled()`.
- **Pratinjau preprocessing** (fitur baru, sebelumnya tidak ada di UI): mengirim
  `task` sesuai jenis analisis (`transformer`/`bag_of_words`/`span`) supaya pratinjau
  tidak berbohong. `PreprocessingConfigResolver` dipakai bersama job dan pratinjau —
  kalau keduanya menghitung sendiri, pratinjau bisa berbeda dari yang dijalankan.
- **Kesiapan model**: `GET /analysis/nlp-status` dan `POST /analysis/warm-up`, dengan
  panel status per model di formulir analisis.
- **Mutu topik** (`quality`: c_v, c_npmi, diversity, outlier_rate) tampil dengan rambu
  penafsiran, disertai catatan bahwa c_v tidak sebanding antar korpus.
- **`/api/retrain/preview`** disambungkan sebagai tombol "Periksa Data Latih" di
  `/admin/training` — menampilkan distribusi label, rasio ketimpangan, komposisi split,
  dan **akurasi tebak-kelas-mayoritas** sebagai pembanding, sebelum melatih apa pun.

### Bug lama yang ikut ketemu dan diperbaiki

1. **Kartu metrics mencetak array.** `@foreach($result->metrics ...)` mencetak nilai
   apa adanya, sehingga nilai bersarang (`review_queue`, dan `failed_batches` yang
   sudah ada sebelumnya) memicu `htmlspecialchars(): Argument #1 must be of type
   string, array given` — halaman hasil 500 untuk setiap analisis yang punya batch gagal.
   Kini disaring ke nilai skalar saja.
2. **Panel "Filter Hasil" duplikat.** Blok kedua di `show.blade.php` mendeklarasikan
   ulang `const allPredictions` dan `currentFilter` pada lingkup global yang sama,
   memicu `SyntaxError` di browser untuk setiap analisis >10 baris, dan kotak carinya
   memakai id `searchPredictions` yang sudah dipakai. Blok itu dihapus (99 baris).
3. **Tombol Export CSV admin selalu 404.** `GET /training/{id}` terdaftar sebelum
   `GET /training/export-csv`, sehingga `{id}` menelannya. Kedua rute `{id}` kini
   dibatasi `->whereNumber('id')`.
4. `$topic['proportion']` dibaca defensif (dulu 500 bila datanya parsial).

Tes bertambah jadi **110**; yang baru: `ReviewQueueTest`, `ReviewQueueDisplayTest`,
`TopicOptionsTest`, `PreprocessingPreviewTest`, `NlpStatusTest`, `RetrainPreviewTest`.

## Audit kesesuaian dengan nlp-api-service (3 Sep 2026)

Seluruh 12 endpoint API sudah terpakai dari Laravel. Nama kunci respons
(`sentiment`, `confidence`, `method`, `processed_text`, `aspect_sentiments`,
`document_aspects`, `word_frequencies` sebagai list `{word, frequency}`) cocok
dengan yang dibaca `NLPApiService` dan `ChartHelper`.

Ketidaksesuaian yang ditemukan dan diperbaiki:

1. **`num_topics` tidak pernah dikirim untuk analisis gabungan.** Formulir
   menawarkan pilihan jumlah topik untuk `combined`, tetapi baik
   `executeCombinedAnalysis` maupun jalur batch tidak meneruskannya sehingga API
   selalu memakai bawaannya (5). Kini diteruskan lewat `getNumTopics()`, termasuk
   nilai 0 (mode otomatis) yang bernilai falsy dan mudah hilang.
2. **Checkpoint yang ditolak dicatat sebagai berhasil.** API menolak menyimpan
   hasil retraining yang menurunkan metrik validasi (`rejected_for_regression`,
   dan bobot lama dipertahankan). `RetrainModel` dulu menandai semua respons HTTP
   sukses sebagai `completed`. Kini ada status `rejected` beserta ringkasan
   `weighted_f1 sebelum -> sesudah`, dan riwayat training menampilkannya.
   Kolom `status` diubah dari enum menjadi string agar menambah status tidak
   perlu migrasi skema dan perilakunya sama di MySQL maupun SQLite.
3. **`max_texts_single_request` (100) tidak pernah dibaca dan nilainya keliru** —
   batas API sebenarnya 10.000 teks dan 10.000 karakter per teks. Diganti
   `max_texts` + `max_text_length`, dan divalidasi di `AnalysisController::store`
   supaya pengguna mendapat pesan yang menyebut baris keberapa yang bermasalah.
4. **Palet warna topik hanya cukup untuk lima topik.** `array_slice($colors, 0,
   count($topics))` membuat topik keenam dan seterusnya tanpa warna — masalah
   yang baru terasa setelah mode otomatis bisa menghasilkan sampai 20 topik.
   Warna kini diputar.
5. **`prepareWordCloudData` memanggil `max()` pada array kosong** dan bisa membagi
   dengan nol; keduanya menggagalkan render halaman hasil untuk korpus kecil.

`force` pada endpoint retraining (menyimpan checkpoint walau metrik menurun)
sengaja **tidak** diekspos di UI: perilaku bawaan yang menolak regresi adalah
yang benar untuk loop active learning.

### Catatan performa yang belum diselesaikan

Halaman hasil merender setiap prediksi sebagai kartu HTML penuh. Terukur pada
analisis 72 (289 prediksi): **2,7 MB**. Menghapus salinan JSON prediksi yang
ditanam ke JavaScript — dipakai hanya untuk membaca `.length` — memangkas
169 KB; sisanya adalah markup per kartu (~9 KB/kartu, didominasi SVG inline yang
berulang). Perbaikan yang benar adalah paginasi daftar prediksi, tetapi itu
mengharuskan filter/pencarian/antrean tinjauan pindah ke sisi server.

### Format kode

Repo belum pernah diformat Pint (`./vendor/bin/pint --test` melaporkan 66 isu di
112 file, sebagian besar kode lama). Menjalankan Pint sebaiknya dilakukan
sekaligus dalam commit tersendiri agar tidak bercampur dengan perubahan fungsional.

Tes: **126**.
