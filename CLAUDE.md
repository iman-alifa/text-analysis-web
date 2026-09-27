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
php artisan evaluation:sample --analysis=N [--size=300] [--stratify=col] [--out=]  # draw a stratified random sample to annotate
php artisan evaluation:import <file> [--name=] [--freeze] [--dry-run]   # import a golden (hold-out) dataset
php artisan evaluation:split <file> [--test-ratio=0.2] [--seed=42] [--out=]  # stratified train/test split
php artisan evaluation:run <dataset-id> [--label=] [--compare-last] [--no-topic]  # measure the live model against a frozen dataset
php artisan evaluation:normalize-aspects [--dataset=]  # backfill stemmed gold aspect labels
php artisan evaluation:merge-aspect-label <id> --from="a" --to=b [--apply]  # merge two gold aspect labels into one
php artisan retrain:analysis [<id>] [--model=] [--force]   # retrain from ONE analysis's corrections; no id lists them
php artisan training:purge-unscorable [--apply]    # drop emoji-only correction rows
php artisan evaluation:leakage [--dataset=]        # check golden/training overlap
php artisan evaluation:report [--dataset=] [--out=]  # markdown tables for the thesis
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

## Penyisiran lanjutan (3 Sep 2026, setelah push)

Sisi `nlp-api-service` sudah diperbaiki pemiliknya: checkpoint hasil retraining
kini dimuat saat startup, `init.py` sudah menjadi `__init__.py`, sintaks Pydantic
v2 dipakai, dan direktori `tests/` sudah terisi. Blocker fine-tuning yang dulu
dicatat di sini sudah tidak berlaku.

Temuan baru di `text-analysis-web` dan perbaikannya:

1. **Path traversal pada rute unduh YouTube.** `download($filename)` menyambung
   nama berkas dari URL langsung ke `storage_path('app/public/exports/')` tanpa
   penyaringan. Karena responsnya memakai `deleteFileAfterSend(true)`, celah itu
   bukan hanya membocorkan berkas di luar direktori exports tetapi juga
   **menghapusnya**. Kini nama dilewatkan `basename()`, dibatasi pola
   `^[A-Za-z0-9._-]+\.(csv|xlsx|txt)$`, dan hasil `realpath()` wajib berada di
   dalam direktori exports.

2. **Dua angka koherensi topik untuk analisis yang sama.** Halaman feedback
   menampilkan skor hasil perhitungan PHP (`ModelEvaluationService::calculateTopicCoherence`),
   sementara halaman hasil menampilkan `quality.c_v` dari NLP API. Keduanya
   implementasi berbeda dan pasti berselisih - berbahaya untuk dikutip di
   naskah. `buildTopicEvaluation()` kini mendahulukan blok `quality` dari API
   (`source: 'nlp-api'`) dan hanya jatuh ke perhitungan PHP untuk analisis lama
   yang belum menyimpannya (`source: 'php-fallback'`). Label mutunya disatukan
   lewat `labelKoherensi()` agar satu angka tidak dilabeli berbeda di dua halaman.

3. **Baris `training_items` yatim ikut dihitung dan ikut dilatih.**
   `TextAnalysis` memakai soft delete, sehingga `onDelete('cascade')` pada
   `training_items` tidak pernah berjalan. Pada database pengembangan ditemukan
   **4.307 baris** milik 94 analisis terhapus - tidak muncul di daftar file mana
   pun, tidak bisa dikoreksi siapa pun, tetapi ikut menggelembungkan "Total Data
   Baris" (4.885 alih-alih 578). Statistik admin, `triggerTraining`,
   `previewTraining`, dan export CSV kini semuanya dibatasi
   `whereHas('textAnalysis')`.

   Catatan: dari 292 koreksi yang ada, **nol** di antaranya milik analisis
   terhapus, jadi data latih untuk studi kasus tidak terpengaruh. Baris yatimnya
   sendiri sengaja tidak dihapus - itu keputusan pemilik data.

Tes: **137**.

## Perombakan daftar prediksi & koreksi (3 Sep 2026)

Dua halaman terberat dirombak ke paginasi sisi server. Ukuran diukur pada
analisis 72 (289 prediksi):

| Halaman | Sebelum | Sesudah |
|---|---|---|
| `/analysis/{id}` | 2.734 KB | **380 KB** |
| `/analysis/{id}/feedback` | 2.110 KB | **217 KB** |

**Daftar prediksi** (`PredictionQueryService`): penyaringan (sentimen + antrean
tinjauan), pencarian, dan paginasi dikerjakan di server lewat
`GET /analysis/{id}/predictions`. Halaman pertama tetap dirender di server
sehingga daftarnya tampil tanpa JavaScript; halaman berikutnya diambil AJAX dan
mengembalikan HTML dari partial yang sama (`analysis.partials.prediction-list`),
jadi markup kartu tidak digandakan di JavaScript. Permintaan yang tertinggal
dibatalkan lewat `AbortController` supaya hasil lama tidak menimpa penyaringan
yang lebih baru, dan pencarian di-debounce 300 ms.

Prediksi disimpan sebagai satu kolom JSON, bukan baris tabel, sehingga
paginasinya memotong array di PHP - bukan query SQL. Itu tetap jauh lebih murah
daripada merender seluruh baris.

**Ikon sentimen** kini satu sprite `<symbol>` yang dirujuk `<use>`, bukan tiga
varian SVG penuh yang diulang di setiap kartu - itu penyumbang terbesar berat
per kartu.

**Halaman koreksi** memakai paginator Eloquent biasa (25 baris, paling tidak
yakin lebih dulu). Evaluasi tetap dihitung dari seluruh baris, hanya renderingnya
yang dibatasi. Setelah menyimpan, pengguna kembali ke halaman koreksi yang sama
alih-alih dilempar ke halaman hasil, karena alurnya biasanya berlanjut ke
halaman berikutnya.

Aksesibilitas: tombol penyaring memakai `role="group"` + `aria-pressed`, kotak
cari punya label, daftar prediksi memakai `aria-live`/`aria-busy`, dan tombol
"Lihat teks terproses" memakai `aria-expanded`/`aria-controls`.

Seluruh basis kode kini lolos `./vendor/bin/pint` (118 file). Tes: **152**.

## Interpretasi AI di halaman hasil (3 Sep 2026)

Tombol "Generate Interpretasi AI" yang lama hanya melabeli topik. Kini ada lima
bagian yang bisa dinarasikan: `overview`, `sentiment`, `aspect`, `topic`,
`association` (`AnalysisInterpretationService::SECTIONS`), lewat satu endpoint
`POST /analysis/{id}/interpret/{section}` (`throttle:10,1`).

Tiga aturan yang mengikat:

1. **Narasi AI adalah lapisan tambahan, bukan pengganti.** Ringkasan template
   (`NLPApiService::generateSentimentSummary`, `generateAspectSummary`) dan narasi
   PMI berbasis aturan tetap tampil. Halaman hasil harus tetap bermakna ketika
   `GEMINI_API_KEY` kosong atau kuotanya habis.
2. **Dibangkitkan saat diminta, bukan di dalam job.** `ProcessTextAnalysis` tidak
   boleh bergantung pada layanan luar yang bisa gagal, dan tiap pemanggilan
   memotong kuota harian.
3. **Angka dikirim ke model, bukan diminta dihitung model.** Prompt memuat
   larangan eksplisit menghitung ulang atau menyebut angka yang tidak ada di
   payload. Seluruh statistik tetap berasal dari pipeline.

Hasil disimpan di kolom baru `analysis_results.ai_interpretations` (JSON per
bagian), **bukan** diselipkan ke `topic_results` seperti versi lama - menumpang
di kolom hasil model membuat narasi ikut hilang setiap analisis diproses ulang.
Pengecualian: label topik tetap *juga* ditulis ke `topic_results['interpretation']`
karena heatmap asosiasi dan export PDF membacanya dari sana.

Tiap entri membawa `model`, `prompt_version`, dan `generated_at`. Ini wajib:
naskah harus bisa menyebut model dan tanggal pembangkitan. Naikkan
`LlmService::PROMPT_VERSION` setiap kali susunan prompt berubah.

### Yang diperbaiki pada fitur lama

1. **Tidak bisa diuji.** `LlmService` memakai Guzzle mentah, jadi `Http::fake()`
   tidak menyentuhnya dan ini satu-satunya bagian sistem tanpa tes. Kini memakai
   facade `Http` dan diinjeksi, bukan `new \App\Services\LlmService` di controller.
2. **JSON diminta lewat prompt, bukan skema.** Kini memakai `response_schema`
   (structured output). Tambalan "kalau LLM membalas indeks 1-based, kurangi 1"
   dihapus; `topic_id` yang tidak ada di daftar topik sekarang ditolak, bukan
   ditebak.
3. **Sekali jadi tidak bisa diulang.** Ada `regenerate`, jadi label yang keliru
   tidak perlu diperbaiki lewat database.
4. **Rute tanpa throttle.** Satu-satunya rute AJAX di grup itu yang tidak punya
   throttle; kini `10,1`.
5. **Prompt tanpa konteks korpus.** Judul dan jumlah teks ikut dikirim. Sebaliknya
   ada larangan mengutip judul di dalam kalimat - judul sering berupa catatan
   teknis ("Final combined 15:17:59") dan terbaca janggal.
6. **Provenance** disimpan (lihat di atas).

### Model

Jangan mengejar versi terbaru. Diuji 3 Sep 2026 dengan API key proyek ini:
`gemini-3.5-flash` -> 200, `gemini-3.8-flash` -> 503. Yang lebih penting, model
**dipatok versinya** dan bukan alias `gemini-flash-latest`: narasi yang dikutip
di naskah harus bisa direproduksi berbulan-bulan kemudian. `GEMINI_TIMEOUT` (45)
dan `GEMINI_RETRY` (2) menyusul, karena narasi lebih panjang daripada label topik
dan sering terpotong pada batas 30 detik yang lama.

### Bug lama yang ikut ketemu

1. **Dua fungsi global dideklarasikan di dalam `@php` pada `show.blade.php`**
   (`getPmiColorClass`, `getCrosstabColorClass`). Merender view itu dua kali dalam
   satu proses PHP memicu `Cannot redeclare` - artinya dua tes yang membuka
   halaman hasil analisis gabungan akan saling menjatuhkan. Diganti closure.
2. **Nama aspek disisipkan ke HTML tanpa escape** pada narasi PMI, lalu dicetak
   dengan `{!! !!}`. Nama aspek bisa berasal dari masukan pengguna
   (`predefined_aspects`), jadi itu jalur XSS tersimpan. Narasinya pindah ke
   `AssociationInsightService` yang meng-`e()` setiap nilai, dan sekaligus keluar
   dari berkas tampilan sehingga bisa diuji dan dipakai ulang.
3. **`$topic['size']` dibaca tanpa penjaga** - sama seperti `proportion` dulu,
   halaman hasil 500 untuk topik yang datanya tidak memuat kunci itu.
4. Narasi AI kini ikut pada **export PDF** beserta model dan tanggalnya.

Diverifikasi terhadap Gemini sungguhan pada analisis 95: kelima bagian berhasil,
dan angkanya cocok persis dengan `sentiment_distribution` tersimpan (60/35/5 dari
60 teks) - model tidak mengarang angka.

Tes: **173**. Yang baru: `AiInterpretationTest` (15), `AssociationInsightTest` (6).

## Penyisiran tampilan halaman hasil (3 Sep 2026)

### Kartu metrik mencetak angka yang salah

Temuan terpenting di sesi ini. Kartu metrik menentukan format dari **tipe data
PHP**, bukan dari arti metriknya:

```php
{{ is_float($value) ? round($value * 100, 1) . '%' : $value }}
```

`negative_percentage` sudah berupa persen, jadi dikalikan 100 sekali lagi.
Halaman hasil analisis 69 dan 72 mencetak **"Negative percentage: 8858%"**.
Analisis 95 menyimpan angka yang sama sebagai integer (60, bukan 60.0), sehingga
lolos dari cabang float dan tampil "60" tanpa tanda persen - metrik yang sama
tampil dengan dua cara berbeda hanya karena JSON-nya menyimpan int atau float.

Diganti `App\Helpers\ResultPresenter`: format ditentukan **per nama metrik**.
Metrik tak dikenal ditampilkan apa adanya - lebih baik terlihat mentah daripada
terlihat rapi tetapi salah, karena menebak format itulah asal mula bug ini.

Kartu utama kini empat yang benar-benar berarti bagi pembaca (teks dianalisis,
rata-rata/terendah/tertinggi keyakinan). Sisanya - `pipeline_version`,
`total_analyzed`, dan tiga `*_percentage` yang sudah tampil lebih baik di panel
Statistik Sentimen - pindah ke blok `<details>` "Detail teknis". Dilipat, bukan
dibuang: menyembunyikan angka dari pemilik datanya bukan keputusan yang boleh
diambil di lapisan tampilan.

### Istilah dicampur dua bahasa

Legenda grafik memakai `['Positif', 'Netral', 'Negatif']` (dari `ChartHelper`),
tetapi panel Statistik Sentimen tepat di sebelahnya mencetak `{{ $sentiment }}`
mentah - "Positive", "Negative". Ikut diperbaiki: lencana jenis analisis
("Combined" -> "Analisis Gabungan"), kartu aspek ("333 mentions" ->
"333 penyebutan"), kolom "Mentions" pada tabel silang, dan label pada kartu
prediksi. Semuanya lewat `ResultPresenter::sentimentLabel()` /
`analysisTypeLabel()`.

### Navigasi dalam halaman

Laporan gabungan memuat belasan blok (ringkasan, metrik, sentimen, prediksi,
aspek, mutu topik, distribusi topik, word cloud, detail topik, tabel silang,
heatmap PMI, empat blok interpretasi AI). Ditambah bilah `sticky` berisi tautan
jangkar, dengan penyorot bagian aktif memakai `IntersectionObserver` (bukan
penangan scroll yang berjalan tiap frame). Daftar tautannya dibangun dari
bagian yang **benar-benar dirender**, dan ada tes yang memastikan setiap tautan
punya jangkar yang ada - tautan menuju jangkar kosong tidak akan lolos.

### Grafik tidak punya padanan teks

Keempat `<canvas>` (sentimen, aspek, topik, word cloud) hanya elemen kosong bagi
pembaca layar. Kini `role="img"` dengan `aria-label` yang dibangun dari data yang
sama dengan grafiknya, mis. "Diagram distribusi sentimen: Netral 5 persen,
Negatif 60 persen, Positif 35 persen."

### Catatan

`use` tidak boleh berada di dalam blok `@php` - Blade mengompilasinya ke dalam
badan fungsi, dan halaman langsung 500. Pakai nama berkualifikasi penuh.

Tes: **184**. Yang baru: `ResultPresenterTest` (11).

## Kolom pemisah / rincian hasil per segmen (3 Sep 2026)

Pengguna bisa memilih sampai **3 kolom pemisah** saat mengunggah berkas
(tanggal, sumber, judul video, apa pun), lalu di halaman hasil membuka rincian
per nilai unik kolom itu. Tidak terbatas pada tanggal - pengelompokan selalu
berdasarkan nilai unik apa adanya.

**Tidak ada analisis ulang.** `SegmentAnalysisService` menghitung rincian dari
`predictions` yang sudah tersimpan. Itu inti fiturnya: studi kasus dengan 10
video per lembaga cukup dijalankan sekali, lalu dipecah per video di halaman
hasil - bukan 10 analisis terpisah.

**Rinciannya tidak dihitung kecuali diminta.** Halaman hasil menampilkan
gambaran keseluruhan lebih dulu; blok rincian hanya berisi menu pemilih kolom
sampai penggunanya memilih. Dikendalikan parameter kueri `?segment=<kolom>`,
bukan sekadar penangan JavaScript, supaya pilihannya bisa ditandai/dibagikan
lewat URL dan halaman tetap berfungsi tanpa JavaScript (ada tombol "Tampilkan"
yang disembunyikan begitu JavaScript aktif).

### Dua hal yang mudah salah

1. **Penyejajaran indeks.** `processCsvWithConfig` / `processExcelWithConfig`
   **melewati baris berteks kosong**, sehingga indeks `raw_data` tidak sama
   dengan nomor baris berkas. Nilai kolom pemisah karena itu dikumpulkan pada
   **lintasan yang sama** dengan teksnya (`FileProcessingService::collectRows`).
   Kalau diambil pada lintasan terpisah, indeksnya bergeser di setiap baris
   kosong dan seluruh pengelompokan menunjuk kalimat yang salah. Ada tesnya.
2. **`original_index`, bukan urutan larik.** `SegmentAnalysisService` memasangkan
   prediksi ke segmen lewat `prediction['original_index']`. Pada korpus yang
   diproses per batch, urutan prediksi tidak selalu sama dengan urutan teks
   masukan. Ada tesnya, dengan prediksi yang sengaja diacak urutannya.

### Keputusan lain

- Kolom teks sendiri tidak boleh jadi pemisah - tiap baris akan menjadi satu
  segmen dan hasilnya tidak berarti apa-apa. Disaring di dua tempat: JavaScript
  formulir dan `resolveSegmentColumns`.
- Baris tanpa nilai pemisah **tidak** dimasukkan ke segmen mana pun, tetapi
  jumlahnya ditampilkan. Kalau tidak, jumlah seluruh segmen tampak tidak cocok
  dengan total analisis.
- Ditampilkan maksimal `SegmentAnalysisService::MAX_SEGMEN` (30) segmen
  terbesar; sisanya disebut jumlahnya. Kolom bernilai hampir unik per baris
  (mis. timestamp) karena itu tidak merusak halaman.
- Nilai disimpan di `text_analyses.segment_data`, bukan di `analysis_results`:
  ini bagian dari **masukan**, tidak berubah walau analisis diproses ulang.
- Narasi AI segmen disimpan per kolom (`ai_interpretations['segment:<kolom>']`).
  Satu kunci bersama akan menampilkan narasi kolom lain saat pengguna berpindah.
  Promptnya ikut menerima distribusi keseluruhan sebagai pembanding - tanpa itu
  model hanya membacakan ulang angka tiap segmen, bukan menunjuk mana yang
  menyimpang - dan diperingatkan bahwa segmen bersisi sedikit tidak cukup kuat
  untuk disimpulkan.

Diverifikasi terhadap Gemini sungguhan pada analisis 95 (60 teks dibagi 3
segmen): model menunjuk segmen paling negatif (80% vs 60% keseluruhan), segmen
yang menyimpang positif, dan menyadari satu segmen persis sama dengan
distribusi keseluruhan. Data pemisah buatan untuk uji itu sudah dihapus lagi
dari basis data.

Berlaku untuk csv/xlsx/xls. Masukan manual dan txt tidak berkolom, jadi tidak
menawarkan pemisah.

Tes: **204**. Yang baru: `SegmentAnalysisTest` (20).

## Penyisiran menyeluruh (3 Sep 2026)

Seluruh rute GET dirender terhadap basis data pengembangan, sebagai admin dan
sebagai pengguna biasa. Semuanya 200/302/403/404 sesuai harapan. Satu-satunya
yang bukan: `/analysis/nlp-status` -> **503**, dan itu memang perilaku yang
benar - service NLP sedang tidak berjalan, dan endpoint itu justru bertugas
melaporkannya.

### Penyaring daftar analisis tidak berfungsi

`/analysis` punya kotak cari dan dua dropdown penyaring. Ketiganya **tidak
melakukan apa pun**: skripnya hanya memanggil `console.log` dan menyisakan
komentar `// TODO: Implement AJAX filtering or redirect with query params`.
Pengguna mengetik, dan tidak terjadi apa-apa.

Kini disaring di server (`?q=`, `?type=`, `?status=`) dengan `withQueryString()`
supaya penyaringnya terbawa ke halaman berikutnya. Harus di server, bukan di
browser: daftarnya dipaginasi 15 baris, jadi penyaringan sisi klien hanya akan
menyentuh baris yang kebetulan sedang tampil. Nilai `type`/`status` divalidasi
terhadap daftar yang sah sebelum masuk klausa `where`.

### Yang diperiksa dan sudah benar

- `php artisan config:cache` aman; tidak ada `env()` langsung di `app/`,
  `routes/`, atau `resources/views/`.
- Hierarki timeout konsisten: HTTP ke NLP 600 detik < job 1800 detik <
  `retry_after` 2100 detik.
- Blok "Kolom Pemisah" berada di dalam `#excel-config`, jadi tidak muncul untuk
  berkas txt yang memang tidak berkolom.

### Catatan

`failed_jobs` berisi 10 baris, terbaru **28 Jan 2026** - semuanya dari sebelum
perbaikan (timeout 300 detik dari konfigurasi lama, dan dua galat sisi Python
yang sudah diperbaiki pemiliknya). Tidak ada kegagalan baru sejak itu. Barisnya
sengaja tidak dihapus; itu keputusan pemilik data.

Tes: **215**. Yang baru: `AnalysisListFilterTest` (9).

## Keyakinan model & ajakan ikut active learning (3 Sep 2026)

Saran dosen penguji: setelah analisis, pengguna diberi tahu seberapa yakin model
pada kasusnya, lalu ditawari mengoreksi hasil yang berkeyakinan rendah.

### Mengapa dihitung ulang di Laravel

`metrics.review_queue` dari NLP API sudah ada, tetapi **kosong untuk seluruh
analisis yang ada di basis data pengembangan** (69, 72, 95). Bergantung pada
blok itu berarti ajakannya tidak akan pernah muncul. `ConfidenceReportService`
karena itu menghitung dari `predictions` - keyakinan per baris selalu tersimpan,
jadi berlaku juga untuk analisis lama.

Ambangnya tetap **mendahulukan angka dari API** bila ada (`threshold_source`
mencatat mana yang dipakai); model lebih berwenang menentukan batas
keyakinannya sendiri daripada konfigurasi sisi web.

### Ambang

`config/analysis.php` -> `confidence.review_threshold`, env
`ANALYSIS_REVIEW_THRESHOLD`, bawaan **0,70**. Dipilih dari sebaran nyata korpus
proyek ini, bukan angka bulat sembarangan:

| Ambang | Analisis 69 (289 teks) | Analisis 95 (60 teks) |
|---|---|---|
| 0,50 | 2 baris | 1 baris |
| 0,60 | 9 baris | 4 baris |
| **0,70** | **20 baris** | **7 baris** |
| 0,85 | 33 baris | 15 baris |

0,70 cukup banyak untuk berarti bagi pelatihan ulang (API mensyaratkan minimal
10 sampel) dan cukup sedikit untuk benar-benar dikoreksi orang dalam sekali
duduk. 0,85 menghasilkan puluhan baris yang sebagian besar sebenarnya sudah
benar.

### Tampilan

Panel "Seberapa Yakin Model pada Data Anda?" tepat di bawah ringkasan - sebelum
angka apa pun yang lain, karena pembaca perlu tahu seberapa besar hasil di
bawahnya bisa dipercaya sebelum membacanya. Isinya rerata + median, sebaran tiga
pita, dan penilaian yang memakai **dua** ukuran sekaligus: rerata saja
menyesatkan, karena korpus dengan rerata 0,95 dan 15% baris ragu berbeda
kebutuhannya dari korpus dengan rerata sama dan 1% baris ragu.

Ajakannya menyebut angka lebih dulu ("20 teks dinilai dengan ragu"), menjelaskan
mengapa justru baris itu yang berguna, dan menegaskan sifatnya sukarela.
Tombolnya membuka `/analysis/{id}/feedback?fokus=ragu` - halaman koreksi yang
langsung terbatas pada baris di bawah ambang, dengan penyaring "Baris yang ragu
(N) / Semua baris (N)" dan bilah kemajuan koreksi. Fokusnya ikut terbawa setelah
menyimpan; pengguna yang datang dari ajakan tidak boleh terlempar ke daftar penuh.

## Verifikasi alur active learning & retraining (3 Sep 2026)

Rute GET saja tidak membuktikan tombolnya bekerja. `AdminTrainingFlowTest`
memanggil setiap aksi yang mengubah data: sinkronisasi (termasuk bahwa
pemanggilan kedua tidak menggandakan baris), koreksi satu baris, koreksi massal,
tambah/hapus stopword, pratinjau data latih, pemicu retraining (termasuk
penolakan bila koreksi < 10), ekspor CSV, dan bahwa semuanya tertutup bagi
pengguna biasa. Semua lulus.

### Bug yang ketemu di sana

**`bulkCorrect` menerima label apa pun.** Validasinya hanya
`'corrected_sentiment' => 'required'`, sehingga string sembarang bisa masuk ke
kolom label, lalu **ikut terekspor sebagai data latih dan terkirim ke endpoint
retraining**. Kini `in:positive,negative,neutral`, `text_ids.*` diwajibkan
integer, dan querynya dibatasi `whereHas('textAnalysis')` seperti di tempat lain
supaya baris yatim tidak ikut dikoreksi apalagi dilatihkan.

Endpoint itu kemudian **dihapus** (rute + metode + tesnya): tidak ada satu pun
view yang merujuknya, jadi tidak ada jalan ke sana lewat aplikasi. Selain itu
fiturnya sendiri meragukan untuk skripsi - memberi satu label ke puluhan baris
tanpa membaca satu per satu adalah kebalikan dari yang dituju active learning,
dan label serampangan merusak angka yang dilaporkan di naskah.

### Catatan Blade

Frasa yang dipecah ke beberapa baris di Blade **tidak** cocok dengan
`assertSee`/`assertStringContainsString` yang utuh - spasi dan barisnya ikut
terender. Dua tes sempat gagal karena ini. Kalau sebuah kalimat akan diuji,
render dalam satu ekspresi.

Selain itu `TrainingItemService::extractJsonToTable` menuntut kunci `text` pada
tiap prediksi (bukan `original_text`); fixture tes yang hanya memakai
`original_text` menghasilkan nol baris latih tanpa pesan galat apa pun.

Tes: **242**. Yang baru: `ConfidenceInvitationTest` (16), `AdminTrainingFlowTest` (11).

## Berjalan mandiri tanpa role admin (3 Sep 2026)

Saran dosen penguji: aplikasi harus berjalan murni mandiri dan otomatis. Role
admin hanya untuk pengawasan sesekali, bukan syarat operasi.

### Yang dulu mengikat sistem pada admin

Pelatihan ulang **hanya** bisa dipicu dari `/admin/training`. Loop active
learning karena itu berhenti total bila tidak ada admin yang menekan tombol -
pengguna boleh mengoreksi sebanyak apa pun, tetapi modelnya tidak akan pernah
belajar dari koreksi itu sendiri.

### Perubahannya

Logika pelatihan diangkat dari controller ke `RetrainingService`, lalu dipakai
pemicu-pemicu yang menjalankan hal yang persis sama:

1. **Setelah pengguna menyimpan koreksi** (`AnalysisFeedbackController::store`)
   - satu-satunya jalur yang dibutuhkan pemasangan biasa. Koreksi dikumpulkan
   sampai ambang, lalu pelatihan diantrekan. Hanya beberapa query COUNT;
   pelatihannya sendiri berjalan di antrean, jadi permintaannya tidak menunggu.
2. **Tombol di `/admin/training`** - tetap ada, tetapi kini hanya pintasan
   pengawasan, bukan satu-satunya jalan.
3. **`php artisan analysis:auto-retrain`** - pemeriksaan manual untuk keperluan
   operasional. **Sengaja tidak dijadwalkan.** Penjadwal sempat dipasang tiap
   jam, lalu dilepas: satu-satunya celah yang ditutupnya adalah koreksi yang
   sudah melewati ambang tetapi tertahan jeda lalu tidak ada lagi yang
   mengoreksi sesudahnya - kasus sempit yang tidak sepadan dengan membuat
   aplikasi diam-diam bergantung pada `schedule:work` yang mungkin tidak pernah
   dijalankan. Perintahnya tetap ada bila suatu saat memang ingin dijadwalkan.

Kalau logikanya dibiarkan di controller, jalur otomatisnya harus menggandakan
kode - dan dua salinan pasti berselisih.

### Penjaga di `AutoRetrainService`

- **Koreksi *baru* sejak pelatihan terakhir**, bukan jumlah total. Kalau total,
  setiap koreksi berikutnya akan memicu pelatihan lagi selamanya. Memakai
  `verified_at > training.created_at`, bukan `updated_at`: baris latih ikut
  ter-update karena hal lain dan itu akan terhitung sebagai koreksi baru.
- **Jeda** (`cooldown_hours`, bawaan 6). Fine-tuning mahal, dan koreksi biasanya
  datang berkelompok dalam satu sesi.
- **Tidak menumpuk** bila masih ada pelatihan berstatus pending/running.
- Bisa dimatikan seluruhnya lewat `ANALYSIS_AUTO_RETRAIN=false`.

`model_trainings.triggered_by = null` menandai pemicu sistem; riwayat training
menampilkannya sebagai lencana **"Otomatis"**.

### Sisi pengguna: tidak ada yang berubah

Pelatihan ulang berjalan sepenuhnya di belakang layar. Halaman koreksi **tidak**
menyinggungnya sama sekali - tidak ada hitungan menuju ambang, tidak ada
pemberitahuan saat pelatihan terpicu. Alur pengguna tetap: baca, koreksi,
simpan. Menampilkan mekanisme internal hanya menambah istilah teknis pada alur
yang seharusnya sederhana, dan ada tes yang menjaganya
(`assertDontSee('dilatih ulang otomatis')`).

Statusnya tampil di `/admin/training` - permukaan pengawasan - sebagai lencana
ringkas "Otomatis: N/10 koreksi baru", "jeda N jam lagi", atau "mati".

### Apa yang masih admin-only

Hanya pengawasan, dan tidak satu pun menghalangi operasi: melihat riwayat
training, statistik data latih, mengelola stopword kustom, ekspor CSV data
latih, dan tombol pelatihan manual. Aplikasi berjalan penuh tanpa satu pun akun
admin - `AutoRetrainTest` membuktikannya dengan `assertSame(0, User::where('role',
'admin')->count())` setelah pelatihan terpicu.

### Perlu diperhatikan

Basis data pengembangan punya **292 koreksi dan nol riwayat pelatihan**, jadi
koreksi berikutnya yang disimpan siapa pun akan **langsung memicu pelatihan
ulang**. Kalau pelatihan ingin ditahan sampai studi kasus, setel
`ANALYSIS_AUTO_RETRAIN=false` di `.env` sampai saatnya.

Tidak ada cron atau penjadwal yang perlu dijalankan. Cukup `php artisan
queue:work` yang memang sudah dibutuhkan pipeline analisis.

Tes: **252**. Yang baru: `AutoRetrainTest` (12).

## Audit integrasi setelah pelatihan ulang menjadi otomatis (3 Sep 2026)

Diperiksa terhadap `../nlp-api-service` setelah pemicu pelatihan berpindah dari
tombol admin ke koreksi pengguna. Kontrak API-nya sendiri **tidak perlu diubah**
- rincian per segmen dan interpretasi AI seluruhnya dikerjakan sisi Laravel,
dan pelatihan otomatis memakai endpoint yang sudah ada. Tetapi ada tiga hal.

### 1. Ambang tinjauan berselisih antara dua sisi (diperbaiki di Laravel)

`settings.sentiment_review_threshold` di layanan bernilai **0,90** (profil per
bobot bahkan 0,94), sementara `config/analysis.php` sempat memakai **0,70**.
Selisihnya besar dan langsung terlihat:

| Analisis | Ambang 0,70 | Ambang 0,90 (layanan) |
|---|---|---|
| 69 (289 teks) | 20 baris ragu | **42 baris** |
| 95 (60 teks) | 7 baris | **18 baris** |

Analisis yang menyimpan `review_queue` memakai ambang layanan; yang tidak
memakai ambang Laravel - dua jawaban berbeda untuk pertanyaan yang sama, persis
kelas masalah yang dulu ditemukan pada koherensi topik.

`ConfidenceReportService::threshold()` kini bertingkat: ambang yang tersimpan
pada hasil analisis itu sendiri, lalu ambang yang sedang berlaku di layanan
(dibaca dari `/health`, di-cache satu jam), baru konfigurasi sebagai jaring
terakhir. Bawaan konfigurasi ikut diselaraskan ke 0,90.

Catatan implementasi: `Cache::remember` memperlakukan `null` sebagai cache
miss, jadi nilai "layanan tidak terjangkau" disimpan sebagai `false`. Tanpa itu,
layanan yang mati akan dihubungi ulang - dengan timeout 10 detik - setiap kali
halaman hasil dibuka. Ada tesnya (`Http::assertSentCount(1)` untuk tiga render).

### 2. Fine-tuning memblokir seluruh layanan (perlu diperbaiki di Python)

`SentimentService.retrain` dan `AspectService.retrain` dideklarasikan `async def`
tetapi seluruh loop PyTorch-nya berjalan **sinkron di dalam event loop** -
tidak ada `run_in_executor` maupun `asyncio.to_thread` di seluruh basis kode.
Selama pelatihan berjalan, **setiap permintaan `/api/analyze/*` menggantung**.

Dulu ini tersembunyi karena hanya admin yang menekan tombol, dan biasanya di
saat sepi. Sekarang pelatihan dipicu begitu koreksi pengguna mencapai ambang -
bisa tepat ketika pengguna lain sedang menjalankan analisis.

Perbaikan yang disarankan di sisi Python: jalankan bagian pelatihan lewat
`await asyncio.to_thread(...)` (atau `run_in_executor`) sehingga event loop
tetap melayani permintaan lain.

### 3. Tidak ada penjaga pelatihan serentak (perlu diperbaiki di Python)

Tidak ada lock apa pun pada endpoint retraining. Dua permintaan bersamaan akan
sama-sama memuat model, melatih, dan menulis checkpoint. Laravel memang menjaga
sisinya sendiri (`ModelTraining::running()` mencegah antrean kedua), tetapi
layanan tidak boleh bergantung pada kesopanan pemanggilnya - apalagi kini
pemanggilnya otomatis.

Disarankan: lock per model, dan balas **409 Conflict** bila sedang melatih.
`RetrainModel` di Laravel sudah menangani respons non-2xx dengan rapi, jadi
menambahkannya tidak merusak apa pun.

### Yang sudah cocok dan tidak perlu disentuh

- `review_queue` dikembalikan di semua jalur sentimen, termasuk di dalam
  `results.sentiment` pada `/api/analyze/combined`, dan `ProcessTextAnalysis`
  sudah mengambilnya dari kedua tempat. Kosongnya `review_queue` pada analisis
  69/72/95 murni karena analisis itu dijalankan sebelum Laravel menyimpannya
  (95 dibuat 1 Sep 2026), bukan karena API tidak mengirim.
- `original_index` pada tiap prediksi - dipakai rincian per segmen dan antrean
  koreksi - sudah konsisten.
- `document_aspects` sudah tersimpan dan cukup untuk aspek per segmen.
- Batas waktu: `RetrainModel::$timeout` 7200 detik dengan `$tries = 1` sudah
  sesuai untuk fine-tuning yang lama dan mahal.

Tes: **254**.

## Ambang 0,80 dan koreksi sebagian (3 Sep 2026)

### Ambang disetel eksplisit ke 0,80

Urutan prioritas `ConfidenceReportService::threshold()` diubah supaya
**pengaturan eksplisit menang atas segalanya**: konfigurasi -> ambang tersimpan
pada hasil analisis -> ambang berjalan di `/health` -> bawaan 0,90. Sebelumnya
konfigurasi berada di urutan terakhir, sehingga mengisi
`ANALYSIS_REVIEW_THRESHOLD` di `.env` tidak berpengaruh apa-apa selama layanan
NLP hidup - jauh lebih membingungkan daripada sekadar berbeda dari layanan.

`ANALYSIS_REVIEW_THRESHOLD=0.80` sebagai jalan tengah. Pada korpus nyata:
289 teks -> 30 baris ragu (10,4%), 60 teks -> 12 baris (20,0%).

### Formulir koreksi menandai baris yang tidak pernah dibaca

Temuan serius. Radio sentimen **otomatis terpilih** pada prediksi model
(`($item->corrected_sentiment ?? $item->predicted_sentiment) == $sent`), dan
formulir mengirim seluruh baris yang tampil di halaman. Karena `store()`
menandai `is_corrected = true` untuk setiap baris yang terkirim, menekan Simpan
setelah meninjau 3 baris akan menandai **25 baris** sebagai hasil verifikasi
manusia - 22 di antaranya "menyetujui" prediksi model tanpa pernah dibaca siapa
pun, lalu ikut terkirim ke endpoint retraining sebagai data latih.

Untuk skripsi yang justru mengukur peningkatan model dari koreksi manusia, itu
merusak angka yang dilaporkan.

Perbaikannya: radio tidak lagi terpilih otomatis pada baris yang belum
ditinjau, dan `store()` melewati baris tanpa tanda peninjauan.
`adaKoreksi()` menghitung sebuah baris ditinjau bila ada pilihan sentimen,
catatan, **atau** perubahan aspek. Sengaja bukan "berbeda dari prediksi":
menegaskan bahwa model sudah benar juga sinyal berharga bagi active learning.
Aspek dibandingkan terhadap nilai sebelumnya karena kolomnya selalu terisi
bawaan hasil deteksi.

Perilakunya saja yang berubah, tanpa menambah keterangan apa pun ke antarmuka:
tidak ada kotak penjelasan di atas daftar, dan pesan setelah menyimpan tidak
menyebut berapa baris yang dilewati - melewati baris adalah hal yang wajar,
bukan sesuatu yang perlu dilaporkan. Yang tersisa hanya petunjuk kecil
"lewati bila ragu" pada label kolom sentimen, supaya radio yang tidak terpilih
tidak terbaca sebagai kolom wajib.

Ringkasan untuk sisi Python ditulis di
`../nlp-api-service/docs/PENYESUAIAN_DARI_TEXT_ANALYSIS_WEB.md`.

Tes: **265**. Yang baru: `PartialCorrectionTest` (10).

## Tindak lanjut balasan nlp-api-service (4 Sep 2026)

Sisi Python sudah mengerjakan ketiga poin
(`../nlp-api-service/docs/JAWABAN_UNTUK_TEXT_ANALYSIS_WEB.md`). Yang dikerjakan
di sisi web sebagai tindak lanjut:

### 409 dicatat "Dilewati", bukan "Gagal"

Layanan kini membalas **409** bila diminta melatih model yang sedang dilatih.
Itu berarti "sedang berjalan", bukan "rusak". `NLPApiService::executeRetrain`
melempar `RetrainBusyException` khusus untuk 409, dan `RetrainModel`
menangkapnya menjadi status `skipped` - bukan `failed`, yang akan menyiratkan
masalah yang tidak ada. Riwayat training menampilkannya sebagai lencana
"Dilewati" berwarna abu-abu. Koreksinya tetap tersimpan dan ikut pada putaran
berikutnya.

Tidak ada kode yang mencocokkan teks `"failed: 503:"`, jadi perbaikan status
503 di sisi Python tidak memerlukan penyesuaian apa pun.

### Ambang efektif 0,94, bukan 0,90

Nilai `= 0.90` di `app/config.py` hanya default kelas; nilai yang berlaku untuk
bobot aktif (`crypter70`) adalah **0,94**. `fallback_threshold` diluruskan ke
0,94 beserta catatan bahwa nilai ini per bobot dan tidak boleh disalin antar
model.

**Untuk naskah:** pada ambang 0,80 yang dipakai sisi web, yang tertangkap adalah
**29,5% kesalahan** (meninjau 5,2% korpus), bukan 63,6% seperti pada 0,94.
**Angka capaian itu kemudian terbukti dalam-sampel** - lihat bagian "Telaah
kode menyeluruh" di bawah; jangan dikutip sebelum dihitung ulang pada set
validasi.
Angka itu harus disebut apa adanya bila 0,80 yang dipakai saat studi kasus.
Tabel lengkapnya ada di dokumen jawaban sisi Python.

### Bug nyata: `/health` dibaca dengan bentuk yang salah

Ditemukan saat memverifikasi terhadap layanan yang berjalan. Bentuk respons
sebenarnya adalah `{"models": {"sentiment_review_threshold": 0.94, ...}}` -
seluruh info model berada **di dalam blok `models`**, bukan di tingkat atas.
`AnalysisController::nlpStatus()` membacanya di tingkat atas, sehingga panel
kesiapan model pada formulir analisis **selalu menampilkan seluruh kolomnya
kosong**:

```
{"model":{"sentiment_source":null,"sentiment_base":null,
          "sentiment_temperature":null,"sentiment_review_threshold":null}}
```

Pencarian ambang tinjauan lewat `/health` juga selalu gagal karena alasan yang
sama, dan diam-diam jatuh ke nilai cadangan.

Diperbaiki lewat `NLPApiService::healthModelValue()` yang membaca blok `models`
dengan tingkat atas sebagai cadangan (supaya versi layanan lebih tua tidak ikut
rusak). Panelnya kini terisi, terverifikasi terhadap layanan yang berjalan.
`sentiment_retrained` dan `aspect_retrained` ikut diekspos - berguna untuk
memastikan checkpoint hasil active learning benar-benar dimuat.

### Tombol kirim koreksi terkunci sampai ada koreksi

Petunjuk "lewati bila ragu" dihapus. Sebagai gantinya tombol "Kirim Koreksi"
mati sampai ada radio sentimen yang dipilih atau kolom teks yang berubah dari
nilai bawaannya - aturan yang sama persis dengan yang dipakai server saat
menyimpan, jadi tombol tidak pernah aktif untuk kiriman yang toh akan kosong.
Tanpa JavaScript tombolnya tetap aktif, dan server tetap melewati baris yang
tidak ditinjau.

Tidak ada satu pun kalimat di antarmuka yang menyatakan pengguna boleh mengirim
seadanya. Perilakunya ada, keterangannya tidak.

Tes: **268**.

## Evaluasi hold-out / golden dataset (4 Sep 2026)

Untuk bagian evaluasi skripsi: studi kasus 6 lembaga pemerintah × 10 video
YouTube, dengan active learning nyata dan pengukuran perkembangannya.

Panduan lengkapnya (ukuran sampel, aturan anotasi, urutan pengerjaan, tabel
yang dilaporkan, batasan yang harus disebut) ada di `docs/PANDUAN_EVALUASI.md`.

### Masalah yang mendasari seluruh fitur ini

`ModelEvaluationService::buildGlobalEvaluation()` menghitung metrik dari
**seluruh `training_items` terkoreksi** - dan `RetrainingService::correctedItems()`
mengirim baris yang persis sama itu ke pelatihan ulang. Grafik tren di
`/admin/training` karena itu mengukur model pada data latihnya sendiri;
angkanya pasti naik tiap iterasi dan tidak membuktikan apa pun.

Grafik itu tidak dihapus - berguna untuk memantau proses koreksi - tetapi kini
diberi judul "Perkembangan pada Data Koreksi" beserta peringatan eksplisit dan
tautan ke halaman evaluasi. Ada tesnya (`assertSee('atas data koreksi yang ikut
dilatihkan')`).

### Yang dibangun

Empat tabel baru, sengaja **terpisah** dari `training_items` alih-alih sekadar
kolom penanda: batas antara data uji dan data latih harus struktural, karena
satu jalur kode yang lupa menyaring sudah cukup untuk membatalkan seluruh
angka evaluasi.

- `evaluation_datasets` - golden dataset; `frozen_at` mengunci isinya.
- `evaluation_items` - teks + label emas + `text_hash` + `second_sentiment` +
  `segment`. Unik per (dataset, hash), jadi teks kembar ditolak.
- `evaluation_runs` - satu kali pengukuran; menyimpan `model_info` dari
  `/health` dan `preprocessing_config` yang dipakai.
- `evaluation_run_items` - prediksi per baris. Disimpan, bukan dibuang setelah
  metrik dihitung: uji McNemar menuntut prediksi berpasangan, dan analisis
  kesalahan di naskah menuntut contoh kalimatnya.

Layanan di `app/Services/Evaluation/`:

- `ClassificationMetrics` - akurasi + **selang Wilson**, macro/weighted P/R/F1,
  **selang bootstrap** macro-F1 (disemai, jadi bisa dihitung ulang), Cohen
  kappa, **akurasi tebak-kelas-mayoritas** sebagai lantai pembanding, matriks
  kebingungan; aspek multi-label micro/macro + exact match + Hamming loss.
- `GoldenDatasetService` - impor CSV/XLSX dengan alias kolom Indonesia,
  pemecahan berstrata yang disemai, pembekuan, kappa antar-anotator, laporan
  kebocoran.
- `HoldoutEvaluationService` - menjalankan dataset terhadap model aktif memakai
  `PreprocessingConfigResolver` yang **sama** dengan pipeline analisis; rincian
  per segmen ikut dihitung.
- `EvaluationComparisonService` - selisih metrik, kurva iterasi, dan **uji
  McNemar** (binomial eksak untuk b+c ≤ 1000, khi-kuadrat berkoreksi di atas
  itu) beserta daftar baris yang membaik/memburuk.

Perintah: `evaluation:import`, `evaluation:split`, `evaluation:run`,
`evaluation:leakage`, `evaluation:report`. Halaman `/admin/evaluation`.

### Keputusan yang penting diingat

1. **Penjaga kebocoran ada di `RetrainingService::correctedItems()`**, bukan di
   pemanggil. Koreksi yang teksnya cocok dengan baris golden beku disingkirkan
   dari data latih, dicocokkan lewat `App\Support\TextFingerprint` (huruf kecil,
   tanda baca dibuang) sehingga "Pelayanan bagus" dan "PELAYANAN BAGUS!!"
   terhitung sama. Satu implementasi hash dipakai kedua sisi - kalau berbeda,
   kebocoran akan lolos, dan kebocoran yang lolos justru membuat angka terlihat
   lebih baik.
2. **Hanya dataset `frozen_at` + `split=test` yang menahan data latih.** Dataset
   yang masih disusun belum tentu jadi test set; memblokir koreksi karena berkas
   percobaan akan membuang data latih yang sah.
3. **Dataset beku tidak bisa dibuka lagi bila sudah punya run.** Mengubah isinya
   membuat run lama membicarakan dataset yang tidak ada.
4. **Evaluasi sepenuhnya manual.** Tidak ada pemicu otomatis di mana pun -
   sempat ada setelah pelatihan ulang, lalu dilepas atas permintaan pemilik
   proyek: evaluasi adalah alat penyusunan skripsi, dan kapan sebuah iterasi
   diukur adalah keputusan penelitinya. Ada tes yang menjaganya
   (`Queue::assertNotPushed(RunHoldoutEvaluation::class)` setelah pelatihan yang
   berhasil), supaya pemicu otomatis tidak diam-diam masuk kembali.
   Konsekuensi yang perlu diingat: bobot lama tertimpa begitu checkpoint
   diterima, jadi iterasi yang belum diukur tidak bisa diukur belakangan.
5. **McNemar memakai binomial eksak pada jumlah pembalikan kecil.** Pendekatan
   khi-kuadrat memberi p terlalu kecil di sana, dan itu akan membuat perbaikan
   satu-dua putaran koreksi tampak bermakna padahal belum tentu.
6. **Macro-F1 dilaporkan berdampingan dengan weighted.** Pada korpus timpang,
   weighted-F1 didominasi kelas terbanyak sehingga model yang gagal total pada
   kelas minoritas tetap terlihat baik. Ada tesnya.
7. **Bootstrap disemai tetap** (`ANALYSIS_EVAL_SEED`, bawaan 42). Jangan diubah
   di tengah rangkaian studi kasus - selang di naskah harus bisa dihitung ulang.
8. **Preprocessing evaluasi = preprocessing pipeline.** Mengukur dengan
   pembersihan yang berbeda berarti mengukur sistem yang tidak pernah dijalankan
   siapa pun.

### Catatan

Nilai metrik pulang dari kolom JSON sebagai `int` bila kebetulan bulat (100,
bukan 100.0). Tampilannya aman karena satuan `%` selalu ditambahkan terpisah,
tetapi tes harus memakai `assertEquals`, bukan `assertSame` - kelas bug yang
sama pernah membuat satu metrik tampil dua cara berbeda di halaman hasil.

Diverifikasi ujung ke ujung terhadap layanan NLP yang berjalan: impor 9 baris
(termasuk penolakan duplikat berbeda tanda baca dan kappa antar-anotator 0,833),
dua run, perbandingan McNemar, laporan markdown, pemeriksaan kebocoran. Data uji
itu sudah dihapus lagi dari basis data.

Tes setelah putaran ini: **295**. Yang baru: `HoldoutEvaluationTest` (27).

## Penyesuaian evaluasi (4 Sep 2026, sore)

Tiga permintaan pemilik proyek, dikerjakan sebagai satu putaran.

### 1. Evaluasi tidak lagi otomatis

Pemicu setelah pelatihan ulang dihapus beserta konfigurasinya
(`ANALYSIS_EVAL_AFTER_RETRAIN`). Yang tersisa: tombol di `/admin/evaluation` dan
`php artisan evaluation:run`. Alasannya bukan teknis - evaluasi adalah alat
penyusunan naskah, dan sistem tidak boleh menambah titik ke kurva tanpa diminta.

Halaman koreksi **tidak disentuh**: angka evaluasi yang menurun saat pengguna
mengoreksi memang perilaku yang diinginkan, dan baris itu tetap data latih yang
tidak pernah diuji ulang.

### 2. Koherensi topik masuk ke evaluasi

`metrics.topic` kini memuat `c_v`, `c_npmi`, `diversity`, `outlier_rate`, dan
jumlah topik, diambil dari `/api/analyze/topic` atas teks golden dataset yang
sama. Tampil di halaman evaluasi, perintah, laporan markdown, tabel kurva, dan
sebagai garis terpisah pada grafik dengan **sumbu kanan sendiri** - berskala 0-1,
menaruhnya di sumbu persen membuatnya tergencet di dasar grafik.

Dua hal yang ditegaskan di setiap permukaan, karena mudah disalahpahami:

- Koherensi **tidak punya label emas**. Ia mengukur keterkaitan kata di dalam
  korpus itu sendiri, bukan kecocokan dengan jawaban manusia, jadi tidak boleh
  disandingkan dengan F1 seolah setara.
- **Model topik tidak ikut dilatih ulang** oleh active learning. Koherensi yang
  nyaris diam antar iterasi itu wajar - dan justru berguna sebagai kontrol: bila
  F1 naik sementara koherensi diam, kenaikannya memang dari pelatihan ulang.

Kegagalan menghitung koherensi tidak menjatuhkan run; metrik sentimen yang sudah
terhitung tetap tersimpan.

Ambang label c_v yang tadinya ada dua salinan (`ModelEvaluationService` dan
`show.blade.php`) disatukan ke `ResultPresenter::coherenceBand()`. Halaman
evaluasi akan menjadi salinan ketiga - dan satu angka dilabeli berbeda di dua
halaman persis masalah yang dulu ditemukan pada koherensi topik.

### 3. Alat pengambilan sampel golden dataset

Menu **Ambil Sampel dari Korpus** (`/admin/evaluation/sampling`) +
`php artisan evaluation:sample`. Mengambil dari `raw_data` analisis yang sudah
ada, memakai `segment_data` sebagai dasar stratifikasi, lalu menghasilkan
**berkas anotasi kosong** yang diisi manusia dan diunggah kembali.

Empat aturan yang ditegakkan `GoldenSamplingService`, semuanya berdampak
langsung pada keabsahan angka:

1. **Acak di dalam strata, tidak pernah berdasarkan keyakinan model.** Memakai
   antrean tinjauan sebagai test set sangat menggoda - datanya sudah ada dan
   tampak menantang - tetapi sampel itu memilih kasus tersulit, sehingga
   akurasinya tidak menaksir apa pun. Pilihan itu **tidak disediakan sama
   sekali**, bukan sekadar tidak dianjurkan.
2. **Stratifikasi hanya pada sifat data** (lembaga, video), bukan pada label
   prediksi - yang akan mengubah proporsi kelas sehingga akurasi keseluruhan
   tidak lagi menaksir akurasi korpus.
3. **Baris yang sudah dikoreksi dikecualikan**, begitu juga yang sudah ada di
   golden dataset lain dan yang kembar setelah normalisasi.
4. **Berkas anotasi tanpa kolom prediksi.** Anotator yang melihat tebakan model
   cenderung menyetujuinya. Ada tes yang memastikan header-nya tidak memuat
   `predicted`/`confidence`/`prediksi`.

Alokasi antar strata proporsional dengan metode sisa terbesar. `min_per_stratum`
tersedia tetapi diberi peringatan: menaikkannya membuat angka keseluruhan
menaksir rerata antar-strata, bukan rerata korpus.

Detail yang penting: **pencocokan saat unggah memakai kolom `id`**, dengan sidik
jari teks sebagai cadangan. Tanpa id, anotator yang merapikan teks di spreadsheet
membuat barisnya tidak dikenali dan diam-diam hilang dari test set.

Dataset hasil sampel lahir **tanpa label** dan `freeze()` menolaknya sampai
seluruh baris berlabel - membekukan test set yang sebagian barisnya percuma lebih
buruk daripada gagal keras di sini. Rencana pengambilannya tersimpan di kolom
baru `evaluation_datasets.sampling_plan` dan ditampilkan sebagai blok siap salin
ke bab metodologi.

### Catatan dari verifikasi pada data nyata

Pengambilan sampel dari analisis 72 dan 95 menolak **284 dari 349 baris karena
sudah dikoreksi**. Itu penjaga kebocoran bekerja sebagaimana mestinya, tetapi
juga berarti korpus pengembangan yang ada praktis habis untuk golden dataset.
Golden dataset studi kasus harus diambil dari korpus 6 lembaga yang baru,
sebelum koreksi apa pun dijalankan atasnya.

### Bug yang ikut ketemu

`Http::fake([...])` **tidak** memblokir permintaan yang tidak cocok dengan satu
pun pola - permintaan itu menembus ke layanan sungguhan. Terlihat ketika
koherensi ditambahkan: `/api/analyze/topic` tidak ikut difake dan durasi suite
melonjak 2 - 19 detik, artinya tes diam-diam menuntut layanan Python berjalan.
`HoldoutEvaluationTest` kini memanggil `Http::preventStrayRequests()` supaya
kelalaian serupa gagal keras, bukan menjadi tes yang rapuh.

Tes: **309**. Yang baru: `GoldenSamplingTest` (13), dan `HoldoutEvaluationTest`
bertambah menjadi 28.

## Menindaklanjuti tinjauan nlp-api-service (4 Sep 2026, malam)

`../nlp-api-service/docs/TINJAUAN_EVALUASI_HOLDOUT.md` memverifikasi ulang
statistik evaluasi secara numerik (McNemar eksak cocok sampai 1e-12, tabel
Wilson cocok persis) dan menemukan satu cacat pengukuran serius, satu korpus
yang salah pilih, serta beberapa hal yang perlu ditulis di naskah. Seluruhnya
dikerjakan.

### 1. Metrik aspek salah ukur telak - diperbaiki

`normalizeSet()` mencocokkan nama aspek sebagai string persis. Bahasa Indonesia
aglutinatif: mode automatic mengembalikan bentuk permukaan (`pelayanannya`),
anotator menulis bentuk dasar (`pelayanan`). Tiap pasangan yang BENAR karena itu
dihitung **dua kali salah** - sekali false positive, sekali false negative.
Terukur pada keluaran yang sama: micro-F1 **0,118** dengan string persis,
**0,941** setelah dinormalkan. Modelnya menemukan aspek yang benar pada kedua
hitungan; selisihnya seluruhnya artefak pengukuran.

Perbaikannya menuntut stemmer Sastrawi yang hidup di Python, jadi keduanya
lewat layanan:

- Label emas dinormalkan sekali saat **pembekuan** lewat
  `POST /api/normalize/aspects`, disimpan di kolom baru
  `evaluation_items.gold_aspects_normalized`.
- Prediksi dibaca dari `document_aspects_normalized` (dengan `document_aspects`
  sebagai cadangan).

Bila salah satu sisi belum ternormalisasi, metrik aspek **menolak dihitung**
beserta penjelasannya - bukan diam-diam jatuh ke pencocokan string persis.
Angka yang salah telak lebih berbahaya daripada tidak ada angka. Ada tes untuk
ketiga jalur (emas belum siap, layanan versi lama, keduanya siap).

### 2. Koherensi diukur pada korpus yang salah - diperbaiki

`topicQuality()` menjalankan pemodelan topik atas teks golden dataset. Terukur
pada korpus dan konfigurasi yang sama, hanya jumlah teksnya berbeda: 300 teks
memberi c_v **0,3597**, 1.139 teks memberi **0,4498** - melintasi batas kategori
penafsiran sistem sendiri, dari "lemah" ke "baik untuk teks pendek". Sebabnya
golden dataset adalah sampel acak (kalimat pembentuk satu topik tidak ikut
tersampel) dan ko-okurensi kata menjadi jarang pada beberapa ratus dokumen.

Sekarang **membaca blok `quality` yang tersimpan** pada hasil analisis korpus
sumber (dari `sampling_plan.analysis_ids`, atau `--topic-corpus`). Tidak ada
permintaan baru ke layanan: selain lebih murah, itu menjamin angkanya sama
persis dengan yang tampil di halaman hasil untuk pengguna. Ada tes
`Http::assertNotSent` untuk `/api/analyze/topic`.

Koherensi juga **dihapus dari grafik per iterasi**: model topik tidak dilatih
ulang, jadi garisnya datar dan hanya menambah derau pada grafik yang justru
harus memperlihatkan perubahan F1.

### 3. Daya uji dilaporkan sendiri

Daya McNemar bergantung pada jumlah pembalikan (b+c), bukan pada n.
`powerNote()` menghitung perbaikan bersih terkecil yang masih terdeteksi, dan
interpretasinya menyebutkannya pada hasil yang tidak signifikan: *"Dengan 4
baris yang memburuk, uji ini baru mendeteksi perubahan bila minimal 13 baris
membaik."* Angkanya cocok persis dengan tabel rujukan di tinjauan (b=0→6,
b=2→10, b=4→13, b=6→17, b=8→20), dan ada tes yang menguncinya.

Ini mengubah hasil "tidak signifikan" dari kelemahan menjadi pernyataan yang
bisa dipertahankan.

### 4. Pengujian berganda

`evaluation:report` kini menyatakan di muka bahwa uji utamanya adalah iterasi
pertama lawan terakhir, dan `--compare-last` mencetak peringatan bahwa
perbandingannya deskriptif. Dengan empat uji pada alfa 0,05, peluang setidaknya
satu "signifikan" palsu naik ke ~19%.

### 5. Populasi, dua kappa, sidik jari isi

- Laporan menyebut populasinya apa adanya - **"komentar unik >= 20 karakter"** -
  beserta persentase yang tersaring dan catatan bahwa arah biasnya **ke atas**
  (komentar pendek justru tempat model paling lemah).
- "Kappa" dipecah menjadi **kappa model** dan **kappa antar-anotator (n=...)**.
  Yang kedua adalah batas atas kinerja yang masuk akal.
- `evaluation_datasets.content_hash` menyimpan SHA-256 atas seluruh `text_hash`
  terurut, dihitung saat pembekuan. Membuktikan kepada pembaca bahwa tiap
  iterasi mengukur baris yang sama tanpa perlu mempercayai aplikasinya.

### 6. Baris uji disembunyikan dari halaman koreksi

`RetrainingService` memang sudah menyingkirkannya dari pelatihan, tetapi
pengguna tetap disodori baris yang hasilnya dibuang diam-diam. Kini
disembunyikan dari daftar, dan `store()` menolaknya sebagai penjaga kedua.

## Kesiapan studi kasus (4 Sep 2026)

Berkas di `C:\Documents\*.xlsx` dibaca read-only untuk mengukur skalanya:
**8.393 komentar, 64 video, 6 lembaga**; 6.674 layak dianotasi (unik, >= 20
karakter). Korpusnya sangat timpang - TNI 38,4%, Polri 3,5%.

Dua temuan yang mengubah konfigurasi:

1. **Batas waktu job tidak cukup untuk satu analisis gabungan.** Pada 0,27 detik
   per teks (terukur: 885 teks = 235 detik untuk combined), 8.393 komentar butuh
   ~2.230 detik - melewati `$timeout` lama 1.800 detik, dan `retry_after` 2.100
   lalu melepasnya kembali ke antrean sehingga analisis yang sama berjalan dua
   kali. Dinaikkan ke **3.600** dengan `DB_QUEUE_RETRY_AFTER=4200` dan
   `$uniqueFor = 4200`. Hierarki tetap: HTTP 600 < job 3600 < retry_after 4200.
   `.env` lokal ikut diperbarui, kalau tidak `QueueTimeoutConfigTest` gagal.
2. **Berkasnya tidak punya kolom "Lembaga"** - kolomnya `No, Video ID, Comment
   ID, Author, Comment, Likes, Published At, Updated At, Reply Count`. Karena
   itu anjurannya 6 analisis terpisah dengan judul = nama lembaga: alat
   pengambilan sampel memakai judul analisis sebagai strata bila tidak ada kolom
   pemisah yang dipilih, jadi stratifikasi per lembaga bekerja tanpa menyunting
   berkas. "Video ID" tetap dipakai sebagai kolom pemisah untuk rincian per
   video di halaman hasil.

Ukuran golden dataset: **10% (667 baris) sah dan merupakan batas atas yang masuk
akal; 500 dianjurkan**. Alasan lengkapnya di `docs/PANDUAN_EVALUASI.md` - inti:
presisi tumbuh 1/sqrt(n) sementara tenaga anotasi linear (300->500 membeli 0,9
poin margin, 500->667 hanya 0,4), tetapi daya uji McNemar tumbuh sebanding n
sehingga n=500 membuat perbaikan 1,2 poin bisa terdeteksi sementara n=300 tidak.

Alokasi proporsional pada n=500 memberi Polri hanya 18 baris (margin ±16,7
poin). Rincian per lembaga karena itu **deskriptif saja**; temuan sentimen per
lembaga untuk naskah berasal dari analisis korpus penuh, bukan dari golden
dataset.

### Perlu diperhatikan

`POST /api/normalize/aspects` ada di kode Python tetapi **layanan yang sedang
berjalan belum memuatnya** (404) - prosesnya dijalankan sebelum perubahan itu.
Restart `nlp-api-service` sebelum membekukan golden dataset yang memuat label
aspek, atau jalankan `php artisan evaluation:normalize-aspects` setelahnya.

Tes: **318**.

## Telaah kode menyeluruh lintas repo (4 Sep 2026)

Artifact "Review Kode Skripsi" (5a235102) menelaah ~1,1 MB kode di kedua repo.
Ringkasnya: **tidak ada temuan yang menyatakan model buruk atau sistemnya
salah** - semuanya soal disiplin pelaporan, dan sembilan dari sepuluh temuan
lama sudah beres. Sisi Laravel justru masuk ke bagian "yang layak
dipertahankan": keempat rumus statistik (Wilson, Cohen kappa, McNemar,
bootstrap macro-F1) diperiksa ulang baris demi baris dan **benar**, begitu juga
normalisasi metrik aspek, pembekuan golden dataset, dan `TextFingerprint`.

Sebagian besar temuan kritisnya ada di `nlp-api-service` (S1-S4, T1-T2, A1-A2,
P1) dan tidak bisa dikerjakan dari sini. Yang mengenai repo ini:

### 1. Angka capaian ambang tinjauan ternyata dalam-sampel

`docs/PANDUAN_EVALUASI.md` mengutip "0,80 menangkap 29,5% kesalahan dengan
meninjau 5,2% korpus". Angka itu berasal dari `review_yield` yang dihitung pada
`smsa/test_gold.json` - **set uji yang sama yang dilaporkan sebagai hasil** -
dan ambang 0,94 juga dipilih dari tabel itu. Itu selection-on-test.

Panduan dan `config/analysis.php` kini menyatakannya apa adanya, beserta dua
jalan keluar: jangan kutip angka capaiannya sama sekali (ambangnya sendiri tetap
sah sebagai keputusan rekayasa), atau minta sisi layanan menghitung ulang pada
`valid_gold.json`. Yang **tidak** terpengaruh: pemilihan 0,80 di sisi web tidak
memakai set uji mana pun, dan seluruh metrik hold-out dihitung pada golden
dataset terpisah.

### 2. Metrik aspek mengukur ekstraksi, bukan polaritas

Sentimen per aspek dihasilkan pasca-pemrosesan di sisi layanan (pemenggalan
klausa + `_resolve_low_confidence` pada ambang 0,6) yang belum pernah diukur
harness mana pun. Akurasi sentimen tingkat dokumen karena itu tidak berlaku
untuk persentase per aspek yang tampil di halaman hasil - dan pembaca akan
mengiranya berlaku kalau tidak dikatakan.

Dinyatakan di tiga tempat angkanya dibaca: blok "Detail Aspek" pada halaman
hasil, blok aspek pada halaman evaluasi, dan `evaluation:report`. Ada tesnya.

### 3. Jumlah topik tidak stabil terhadap seed

Terukur di sisi layanan pada lima seed: c_v **0,5023 ± 0,0221** (sangat stabil)
tetapi jumlah topik **13,6 ± 4,98**, rentang 6-19. Kalimat "pemodelan topik
menghasilkan K topik" karena itu bergantung seed. Peringatannya ditambahkan di
panel mutu topik halaman hasil, halaman evaluasi, dan laporan markdown.

Sekalian: **c_npmi negatif itu wajar** (-0,19 sampai -0,49 sementara c_v positif
0,43-0,55) dan merupakan kritik klasik terhadap c_v. Kartu c_npmi di halaman
hasil kini menyebutnya, dan laporan menambahkan paragraf penjelasan bila
nilainya negatif.

### Bug yang ikut ketemu

Tes untuk peringatan di atas menjatuhkan halaman evaluasi dengan
`Undefined array key "method"`: blok "Rencana pengambilan sampel" membaca
`$rencana['method']`, `['seed']`, `['candidates']`, `['rejected'][...]` tanpa
penjaga, jadi `sampling_plan` yang parsial membuat halamannya **500**. Kelas bug
yang sama dengan `$topic['proportion']` dan `$topic['size']` dulu. Seluruh
pembacaannya kini defensif, dan blok "populasi yang diukur" hanya muncul bila
rencananya memang memuat panjang minimal.

### Yang tersisa dan bukan urusan kode

Pelabelan topik oleh Gemini harus diungkap di Bab III. Kodenya sendiri sudah
menyimpan dan menampilkan provenance (model, `prompt_version`, tanggal) di
partial `ai-interpretation`, jadi tinggal ditulis.

Tes: **320**.

## Basis data direset untuk studi kasus (4 Sep 2026)

Seluruh data lama dikosongkan atas permintaan pemilik proyek agar studi kasus
mulai dari nol. **Catatan-catatan di atas yang menyebut keadaan basis data
pengembangan sudah tidak berlaku lagi** - 292 koreksi, analisis 69/72/95,
analisis 81-87 dengan aspek tanpa nama, 4.307 baris yatim, dan 10 `failed_jobs`
dari 28 Jan semuanya sudah tidak ada. Uraian tentang *mengapa* kode berbentuk
seperti sekarang tetap berlaku; hanya angka keadaannya yang usang.

Dikosongkan (5.462 baris, `TRUNCATE` sehingga auto-increment kembali ke 1):
`text_analyses`, `analysis_results`, `analysis_logs`, `training_items`,
`model_trainings`, `evaluation_*`, `datasets`, `failed_jobs`, `jobs`,
`job_batches`.

**Dipertahankan, dan itu disengaja:** `users` (2), `preprocessing_configs` (3,
satu `is_default`), `custom_stopwords` (1). Ketiganya konfigurasi, bukan data -
menghapusnya akan mengubah perilaku preprocessing di tengah penelitian, dan
resolver membutuhkan baris `is_default`.

Cadangan lengkap sebelum penghapusan ada di
`storage/app/backups/analisis_teks_db-sebelum-studi-kasus-<stamp>.sql` (6,4 MB),
bersama satu berkas ekspor YouTube lama yang ikut dipindah ke sana.

Akibat sampingan yang bagus: bahaya "koreksi pertama langsung memicu pelatihan
ulang" hilang dengan sendirinya. `AutoRetrainService::status()` kini melaporkan
`new_corrections 0 / threshold 10`, jadi loop-nya berperilaku sebagaimana
dirancang.

**Yang sengaja TIDAK direset: checkpoint model aspek** di
`../nlp-api-service/models/aspect_retrained.pt` (`/health` melaporkan
`aspect_retrained: true`, `sentiment_retrained: false`). Menghapusnya akan
mengembalikan modul aspek ke bobot dasar yang praktis tidak bisa mengekstraksi
aspek (F1 0,17-0,35 menurut eksperimen K1-K6, versus 0,92 setelah fine-tuning),
sehingga baseline-nya justru tidak menggambarkan sistem yang dijalankan
pengguna. Iterasi 0 karena itu berarti: modul sentimen dari bobot publik
`crypter70`, modul aspek dari checkpoint K6. Sebutkan itu apa adanya di naskah.

## Baris tak-ternilai, ukuran sampel, dan aplikasi anotasi (5 Sep 2026)

### Penyaringan baris yang tidak bisa dinilai gagal diam-diam

Penyaringan `predicted_method != 'empty'` sudah dipasang di halaman koreksi,
tetapi **tidak berpengaruh apa-apa**: `training_items` untuk analisis pertama
studi kasus dibuat sebelum kolom itu ada, jadi seluruh 3.548 barisnya bernilai
NULL — dan penyaringnya sengaja meloloskan NULL supaya data lama tidak ikut
hilang. Hasilnya 175 baris emotikon tetap muncul, dan karena `confidence_score`
baris kosong selalu 0, semuanya menumpuk **paling atas** pada fokus "baris yang
ragu".

Diperbaiki di akarnya, bukan dengan menambah penyaring lagi:

- `App\Support\TextScorability` — satu definisi "tidak bisa dinilai", dipakai
  tiga tempat. Dua penanda dan keduanya perlu: `method === 'empty'` dari layanan
  (paling berwenang), dan teks tanpa satu pun huruf/angka (cadangan untuk baris
  yang tersimpan sebelum `method` ada, dan untuk jalur yang tidak punya akses ke
  `method` sama sekali seperti pengambilan sampel golden).
- `TrainingItemService::extractJsonToTable` **menolak membuat baris** semacam
  itu. Menolak di sumber membuat halaman koreksi, statistik admin, dan muatan
  pelatihan melihat himpunan yang sama.
- `php artisan training:purge-unscorable [--apply]` membereskan yang terlanjur
  ada. Perintah ini juga **mengisi `predicted_method` dari `predictions`
  tersimpan** lebih dulu — perlu, karena heuristik teks saja hanya menangkap 175
  dari 182: tujuh sisanya punya huruf tetapi habis setelah pembersihan, dan
  hanya layanan yang tahu itu. Baris yang sudah dikoreksi manusia tidak pernah
  disentuh.

Dijalankan pada analisis 1: 3.548 baris diisi `predicted_method`, 182 dihapus,
daftar "ragu" turun dari 546 ke 364.

Pengambilan sampel golden juga menolaknya, dengan alasan tersendiri di rencana
pengambilan (`rejected.tidak_dinilai`). Ambang panjang saja tidak cukup:
untaian emotikon panjang lolos dari 20 karakter.

### Ukuran sampel kini bisa disebut sebagai persentase

`--percent=10` pada perintah, dan pemilih mode di formulir. Diterjemahkan
menjadi jumlah baris **setelah** penyaringan, karena penyebut yang benar adalah
kolam yang layak dianotasi — pada korpus studi kasus 8.393 baris mentah menjadi
6.674 kandidat, selisih 20,5%. Kalau dihitung dari angka mentah, "10%" berarti
porsi berbeda setiap kali komposisi emotikon dan duplikatnya berbeda.

Yang tetap perlu ditegaskan di UI dan panduan: **margin hanya bergantung pada
n**, bukan pada persentase korpus. 500 baris memberi ±3,1 poin baik dari korpus
5.000 maupun 50.000.

### Aplikasi anotasi

`public/anotasi/index.html` — satu halaman statis, tanpa backend, tanpa CDN,
dilayani di `/anotasi` dan bisa disalin ke hosting mana pun atau dikirim sebagai
berkas. Berisi pedoman lengkap, satu komentar per layar, pintasan papan tik,
mode anotator pertama/kedua, penyimpanan otomatis di peramban, dan ekspor CSV
yang langsung bisa diunggah kembali.

Parser CSV-nya ditulis sendiri dan menangani tanda kutip, kutip ganda, baris
baru di dalam sel, dan BOM — komentar YouTube penuh koma, tanda kutip, dan baris
baru, jadi `split(',')` akan merusak data secara diam-diam.

**Tebakan model ditampilkan, tetapi ditunda.** Pemilik proyek meminta anotator
melihat prediksi model supaya tidak "under-predict". Permintaannya dipenuhi,
dengan satu penyesuaian yang menjaga keabsahan angkanya: prediksi baru muncul
**setelah** anotator memilih labelnya sendiri, lalu perubahan yang terjadi
sesudah itu dicatat di kolom `changed_after_reveal`. Tanpa penundaan, yang
terukur adalah kesepakatan anotator dengan model, bukan kemampuan model — dan
akurasi naik tanpa modelnya berubah. Dengan penundaan, keduanya tetap dapat, dan
jumlah perubahan justru menjadi angka yang layak dilaporkan.

Berkas anotasi hanya memuat prediksi bila diminta: tombol "Unduh + Prediksi
Model" atau `evaluation:sample --with-prediction`.

Pedoman anotasi lengkap ada dua rupa: `docs/PEDOMAN_ANOTASI.md` untuk lampiran
naskah, dan salinan yang sama tertanam di aplikasinya supaya anotator benar-benar
membacanya. Isinya definisi label, 12 aturan keputusan berurutan (sarkasme,
campuran, pertanyaan retoris, doa, salam, umpatan, tuntutan), kategori "tidak
jelas", tabel kasus sulit yang sudah diputuskan, dan daftar apa yang harus
disalin ke bab metodologi.

### Baris "tidak jelas"

Pulang tanpa label. Dataset tidak bisa dibekukan selama masih ada baris tanpa
label, jadi ada tombol "Buang N baris tanpa label" di halaman dataset yang
mencatat jumlahnya ke `sampling_plan.dropped_unlabeled` — berapa persen korpus
yang bahkan manusia tidak bisa memutuskannya adalah temuan yang dilaporkan,
bukan disembunyikan.

### Pemisahan golden vs analisis

Cakupan penjaga sengaja berbeda: `frozenHashes()` (dataset beku saja) menahan
**data latih**, `reservedHashes()` (dataset mana pun, termasuk yang masih
dianotasi) menyembunyikan dari **halaman koreksi**. Menyembunyikan terlalu
banyak berbiaya beberapa ratus baris yang tidak ditawarkan; menahan terlalu
banyak berbiaya data latih.

Baris golden **tidak** dihapus dari hasil analisis, dan tidak boleh: hasil
analisis adalah temuan studi kasus atas seluruh korpus. Pemisahannya adalah
pemisahan peran, bukan penghapusan.

Tes: **347**.

## Evaluasi ekstraksi aspek benar-benar bisa jalan (6 Sep 2026)

Pemilik proyek bertanya apakah evaluasi golden dataset mencakup ekstraksi aspek,
dan mengapa aplikasi anotasi tidak punya input aspek. Jawabannya: mesin
evaluasinya **sudah ada sejak awal** (`ClassificationMetrics::aspects()`,
micro/macro-F1, exact match, Hamming loss, dibandingkan pada bentuk dasar), tetapi
**tidak akan pernah menyala** - tidak ada satu pun jalur yang bisa mengisi
`gold_aspects`. Aplikasi anotasi hanya menganotasi sentimen.

Sekalian ketemu cacat pengukuran yang lebih dalam.

### Baris tanpa aspek tidak pernah dinilai

`$berAspek = $items->filter(fn ($item) => ! empty($item->gold_aspects))` membuang
baris yang label emasnya kosong. Akibatnya baris seperti "Setuju!" atau "Wkwkwk"
- yang memang tidak membicarakan aspek apa pun - tidak pernah ikut dinilai, dan
**aspek yang dikarang model di sana tidak pernah terhitung sebagai false
positive**. Presisi yang dilaporkan jadi terlalu tinggi, dan besar biasnya persis
sebanding dengan berapa banyak komentar tanpa aspek di korpus - pada komentar
YouTube itu porsi yang besar.

Perbaikannya menuntut tiga keadaan yang bisa dibedakan, bukan dua:

| `gold_aspects` | Artinya | Dinilai? |
|---|---|---|
| `null` | belum ditinjau | tidak |
| `[]` | ditinjau, memang tidak ada aspek | **ya** |
| `[...]` | ditinjau, ada aspeknya | ya |

Tidak perlu kolom baru: `null` dan `[]` sudah berbeda di kolom JSON, hanya
`empty()` yang menyamakan keduanya. Penyaringnya kini `is_array()`. Pada CSV,
penanda keadaan kedua adalah `-` (juga `tidak ada`/`none`) - satu kolom lebih
mudah diisi di spreadsheet daripada kolom penanda terpisah.

Ditambah satu aturan lagi: metrik aspek menuntut **seluruh** baris ditinjau,
bukan sebagian. Menghitung atas subhimpunan yang ditinjau memindahkan masalah
yang sama dari "baris tanpa aspek" ke "baris belum ditinjau". Pesannya menyebut
berapa baris yang kurang, dan halaman dataset menampilkannya selama anotasi
berlangsung.

### Bug yang ikut ketemu

`applyAnnotations` memutuskan "berubah atau tidak" **hanya dari sentimen**, jadi
unggahan yang menambah anotasi aspek sementara sentimennya tidak berubah dibuang
sebagai "tidak berubah" - aspeknya hilang tanpa pesan apa pun. Kini keduanya
diperiksa terpisah, dan baris tanpa label sentimen pun tetap boleh menyumbang
anotasi aspek (keduanya dinilai metrik yang berbeda). Hasilnya membawa
`aspects_filled` sendiri.

### Input aspek di aplikasi anotasi

- **Sorot kata di komentarnya** untuk menambahkannya sebagai aspek - lebih cepat
  daripada mengetik, dan ejaannya persis sama dengan yang tertulis sehingga tidak
  ada aspek yang meleset karena salah ketik. Dibatasi maksimal tiga kata supaya
  menyeret kalimat panjang tidak tercatat sebagai aspek.
- Ketik manual + Enter, dengan **usulan dari aspek yang sudah dipakai anotator
  sendiri** - bukan dari daftar bawaan. Konsistensi penamaan antar baris jauh
  lebih penting daripada kelengkapan kosakata, dan daftar bawaan justru mendorong
  memilih yang sudah ada alih-alih membaca teksnya.
- Tombol **"Tidak ada aspek"**, yang menghasilkan `[]`. Menekan ulang membatalkan
  tinjauan supaya salah tekan bisa dikoreksi.
- Maju otomatis ditahan selama aspeknya belum ditinjau.
- Pintasan `a` untuk melompat ke kolom aspek. Kemajuan aspek ikut di penanda.
- Bisa dimatikan lewat centang "Ikut menganotasi aspek" di layar muat.

Bentuk dasar tidak dituntut dari anotator: kedua sisi tetap di-stem lewat
`/api/normalize/aspects` saat pembekuan, jadi `pelayanannya` dan `pelayanan`
bertemu di token yang sama. Pedoman tetap meminta bentuk dasar demi konsistensi.

Pedoman anotasi bertambah bagian 4 (aspek): definisi "objek yang dinilai, bukan
sifatnya", tabel contoh aspek versus bukan-aspek, enam aturan B1-B6, dan
penjelasan mengapa "Tidak ada aspek" wajib ditekan. Ada di dokumen dan di dalam
aplikasinya.

Tes: **350**.

## Ambang keyakinan diselaraskan dan diberi dasar (6 Sep 2026)

Satu panel keyakinan memakai **empat angka berbeda**: ambang tinjauan 0,80,
batas pita 0,85 dan 0,70, dan penilaian ringkas yang menulis 0,90/0,80 sendiri
di dalam `verdict()`. Akibatnya teks berkeyakinan 0,82 disebut "cukup yakin"
sekaligus tidak ditandai perlu ditinjau, sementara 0,86 masuk pita teratas tetapi
tetap di bawah salah satu batas penilaian. Tidak ada satu pun dari keempatnya
yang punya dasar tertulis.

### Ambang kini dinyatakan sebagai toleransi galat

`ANALYSIS_ERROR_TOLERANCE` (bawaan 0,10) → ambang = 1 - toleransi = **0,90**.

Dasarnya dua lapis, dan lapis pertama yang membuatnya kebal terhadap keberatan
metodologis:

1. **Pernyataan risiko pada model terkalibrasi.** Model sentimen dikalibrasi
   suhu (T = 2,7748, ECE 0,0209 pada SmSA n=500), jadi skor keyakinan adalah
   taksiran peluang benar. Ambang 0,90 berarti "tinjau bila perkiraan salahnya
   di atas 10%". Itu tidak menyentuh data uji sama sekali - berbeda dari 0,94 di
   layanan, yang DIPILIH dari tabel yield pada set uji yang juga dilaporkan
   (selection-on-test, lihat tinjauan nlp-api-service).
2. **Terkonfirmasi tabel reliabilitas** dari `sentiment_eval.json`: di atas 0,90
   akurasi 95,4% (455 dari 500 sampel), tepat di bawahnya 57,1%. Jurang 38 poin.
   Dan di bawah 0,90 model **terlalu percaya diri** - akurasi nyata 57% padahal
   keyakinannya menyebut 70-90%. ECE yang bagus didominasi 91% sampel di pita
   teratas dan menutupi hal itu; justru karena keyakinannya sendiri tidak bisa
   dipercaya di sana, di sanalah manusia perlu melihat.

### Satu angka menentukan seluruh tampilan

- Batas pita teratas **dipaksa** sama dengan ambang tinjauan di
  `ConfidenceReportService::bands()`, apa pun isi config. "Di luar pita teratas"
  dan "perlu ditinjau" kini himpunan yang sama persis, dan ada tesnya yang
  memeriksanya pada tiga toleransi berbeda.
- Batas pita kedua juga dinyatakan sebagai toleransi galat (0,30 → 0,70), dan
  0,70 bukan angka bulat sembarangan: di sanalah akurasi nyata berhenti turun
  (57,1% lalu 53,3%) dan mulai runtuh (0%).
- `verdict()` menerima ambang sebagai argumen, tidak lagi menulis 0,90/0,80
  sendiri. Ada tes yang memastikan penilaiannya bergeser saat ambang berubah.
- Keterangan pita menyebut **arti** angkanya ("perkiraan salah ≤10%"), bukan
  angkanya saja.

### Tabel reliabilitas dari golden dataset sendiri

`ClassificationMetrics::reliability()` + `metrics.reliability` pada tiap
evaluation run: per pita melaporkan n, porsi, akurasi nyata, rerata keyakinan,
dan selisihnya, plus ECE. Pitanya memakai batas yang sama persis dengan yang
dilihat pengguna di halaman hasil - kalau berbeda, tabelnya tidak membenarkan
apa pun. Tampil di halaman evaluasi dan `evaluation:report`.

Ini yang memindahkan pembenaran ambang dari SmSA ke korpus studi kasus. Selisih
negatif ditandai merah: pita yang modelnya terlalu percaya diri persis alasan
baris di sana perlu ditinjau.

### Bug yang ikut ketemu

`AnalysisFeedbackController` membaca `config('analysis.confidence.review_threshold')`
langsung untuk analisis yang belum punya hasil. Begitu kunci itu dikosongkan -
jalur normalnya kini toleransi galat - hasilnya `(float) null` = **0,0**,
sehingga halaman "baris yang ragu" menjadi kosong tanpa pesan apa pun.
`threshold()` kini menerima `?AnalysisResult`, dan tidak ada lagi pembacaan
ambang di luar `ConfidenceReportService`. Ditangkap `EmptyPredictionFeedbackTest`.

Satu bug lagi bikinan sendiri: batas pita sempat diletakkan di dalam `bands`,
dan panel yang mengiterasi seluruh isinya sebagai pita langsung 500 karena entri
itu tidak punya `label`. Dipindah ke `band_bounds` di tingkat atas.

Tes: **362**.

## Titipan active learning dari nlp-api-service (10 Sep 2026)

`../nlp-api-service/docs/TITIPAN_ACTIVE_LEARNING.md` mendiagnosis putaran active
learning pertama dari basis data langsung: **218 koreksi sentimen tersimpan,
hanya 19 yang dilatihkan** (aspek 186 vs 17). Diverifikasi ulang di sini — cocok
persis. Balasannya di `../nlp-api-service/docs/JAWABAN_TITIPAN_ACTIVE_LEARNING.md`.

### Tombol kirim: penyebabnya DUA, bukan satu

Yang dilaporkan: formulir hanya mengirim 25 baris halaman paginasi yang tampil.
Koreksi halaman lain hidup sebagai draf `localStorage` dan tidak pernah sampai
ke server.

Yang ikut ketemu, dan ini yang penting: **`max_input_vars` PHP = 1.000**.
Kiriman memakai empat medan per baris, jadi 262 koreksi = 1.048 variabel - PHP
memotong sisanya **tanpa galat, tanpa peringatan, tanpa entri log**. Memperbaiki
penyebab pertama saja akan menghasilkan pola kehilangan yang identik, hanya
berpindah dari peramban ke PHP.

Karena itu kirimannya kini **satu medan JSON** (`corrections_json`), bukan medan
per baris. `corrections[]` biasa tetap diterima sebagai jalur tanpa JavaScript,
dan di jalur itu jumlahnya selalu satu halaman sehingga aman. Ada tes yang
mengirim 300 koreksi sekaligus.

Sekalian dirapikan di `store()`: satu query untuk seluruh baris (dulu satu query
per koreksi, plus satu lagi per baris dari aturan `exists`), dan seluruh
penyimpanan dibungkus transaksi.

### Draf localStorage: kuncinya tidak boleh disentuh

Ada 262 baris belum terkirim di sana saat perbaikan dikerjakan. Kunci
(`koreksi-draf:<id>`), bentuk datanya, dan jalur pemulihannya **tidak diubah**.

Yang berubah justru menjadi lebih hati-hati. Draf dulu dihapus begitu tombol
Kirim ditekan, untuk baris pada halaman yang tampil - optimistis pada dua hal
sekaligus: bahwa kirimannya berhasil, dan bahwa setiap baris terkirim memang
disimpan (padahal baris yang tidak benar-benar ditinjau dilewati server).
Sekarang server membalas `koreksi_tersimpan` berisi id yang benar-benar
tersimpan, dan hanya itu yang dihapus.

Ikut dibalas: id yang **tidak akan pernah bisa disimpan** (baris uji golden,
baris milik analisis lain). Tanpa itu drafnya menyala selamanya dan pengguna
terus disuguhi "tidak ada yang disimpan" tanpa tahu sebabnya.

Tombol Kirim kini juga menyala bila ada draf di halaman lain - dulu tidak, jadi
koreksi halaman sebelumnya praktis terkunci di peramban - dan panelnya menyebut
angkanya. Bug ini tidak pernah ketahuan justru karena tidak ada satu pun angka di
layar yang bisa dibandingkan pengguna dengan yang dikerjakannya.

### Pelatihan per analisis

- `model_trainings.text_analysis_id` (nullable; pelatihan gabungan tetap sah).
- `RetrainingService::correctedItems()` / `dispatch()` menerima penyaring analisis.
- `AutoRetrainService::newCorrectionCount()` ikut disaring, **dan** titik mulai
  hitungannya memakai pelatihan terakhir **analisis itu**. Kalau tidak, melatih
  lembaga A membuat koreksi lembaga B mendadak dianggap "sudah lama".
- `php artisan retrain:analysis <id>` - tanpa argumen menampilkan daftar koreksi
  per analisis. Menolak berjalan di bawah 50 sampel kecuali `--force`: di bawah
  itu set validasi 20% tidak sampai 10 baris dan "tidak memburuk" berhenti
  berarti apa-apa (terukur pada putaran pertama: val_size 4, delta 0, checkpoint
  tetap disimpan).
- Pemilih analisis pada tombol retrain di `/admin/training`.

### Pelatihan otomatis dimatikan

`.env` ternyata **tidak punya kunci `ANALYSIS_AUTO_RETRAIN` sama sekali**, jadi
selama ini jatuh ke bawaan config (`true`, ambang 10). Ditambahkan eksplisit
`ANALYSIS_AUTO_RETRAIN=false` dan `ANALYSIS_AUTO_RETRAIN_MIN=50`.

Dimatikan, bukan sekadar dinaikkan: dengan 218 koreksi sudah tersimpan, ambang
berapa pun terlampaui pada koreksi berikutnya, dan pelatihan akan terpicu
**sebelum** iterasi 0 sempat diukur.

### Putaran tidak sah dibersihkan

Cadangan penuh dulu, lalu `model_trainings` (2), `evaluation_runs` (4),
`evaluation_run_items` (1.200), `evaluation_snapshots` (1) dikosongkan.
**Koreksi tidak disentuh**: tetap 218 sentimen / 186 aspek.

`evaluation_snapshots` ikut dibuang walau tidak diminta - isinya potret metrik
yang diambil saat pelatihan tidak sah dipicu, jadi ia mengukur hal yang sama.

### `evaluation:merge-aspect-label`

`--from` (boleh diulang) `--to`, mode rencana secara bawaan seperti
`evaluation:audit-aspects`. Mengubah `gold_aspects` dan **mengosongkan**
`gold_aspects_normalized` supaya `evaluation:normalize-aspects` mengisi ulang -
selama kosong, `HoldoutEvaluationService` menolak menghitung metrik aspek, dan
itu disengaja.

Dijalankan: `prabowo subianto` -> `prabowo`, **7 baris, ketujuhnya sudah punya
`prabowo`** - jadi tidak ada label baru, hanya bentuk panjangnya yang dibuang.
Nol duplikat, 300 baris dinormalkan ulang.

Perubahannya berjejak di kolom baru `evaluation_datasets.label_changes`. Kolom
sendiri, bukan menumpang `annotation` - kolom itu ditimpa utuh setiap kali
`refreshStats()` menghitung ulang kappa antar-anotator. `content_hash` tetap sah
karena teksnya tidak berubah.

### Yang MASIH memblokir iterasi 0

1. **Bobot aspek belum bersih di sisi layanan.** `/health` melaporkan
   `aspect_retrained: true`. Tetapi jangan sekadar menghapus
   `aspect_retrained.pt` - isinya checkpoint **K6** yang sudah terpasang sebelum
   putaran active learning, dan menghapusnya mengembalikan modul aspek ke bobot
   dasar (F1 0,17-0,35 versus 0,92). Yang benar: pulihkan K6 dari `models_exp/`.
2. **Kontradiksi di urutan titipan.** §5 menempatkan audit konsistensi sebelum
   iterasi 0, tetapi `evaluation:audit-aspects --only-predicted` menuntut run
   yang sudah selesai - yang baru saja dikosongkan. Dua jalan keluar ada di
   dokumen balasan; yang disarankan adalah menjalankan audit **tanpa**
   `--only-predicted`, karena mode itu membuat setiap label yang ditambahkan
   otomatis menjadi true positive dan presisinya optimistis.

### Catatan

`having()` atas kolom hasil `withCount` jalan di MySQL tetapi ditolak SQLite
("HAVING clause on a non-aggregate query"), dan tes berjalan di SQLite. Dipakai
`whereHas` yang portabel. Perbedaan dialek semacam ini hanya ketahuan kalau
jalurnya benar-benar dirender.

Tes: **413**. Yang baru: `WholeAnalysisCorrectionTest` (9),
`MergeAspectLabelTest` (6).

## Empat angka aspek, bukan hanya mode ketat (10 Sep 2026)

`ClassificationMetrics::aspects()` menyimpan **empat** angka, tetapi ketiga
permukaannya - tabel CLI `evaluation:run`, laporan markdown, dan halaman
`/admin/evaluation` - hanya menampilkan micro/macro **ketat**. Dua yang
tersembunyi justru yang menjawab pertanyaan yang pasti ditanyakan penguji.

Terukur pada Iterasi 0 studi kasus (golden dataset 300 baris):

| Pencocokan | Micro-F1 | Macro-F1 |
|---|---|---|
| Ketat (nama sama persis) | 51,84% | **15,87%** |
| Parsial (irisan kata-dasar) | 56,49% | 24,46% |
| Ketat, aspek support >= 2 (62 dari 225 jenis) | - | **29,12%** |

**Macro melonjak 15,87 -> 29,12 begitu aspek yang cuma muncul sekali
dikeluarkan.** 163 dari 225 jenis aspek muncul sekali saja - skornya hanya bisa
0 atau 1 dan tidak bermakna statistik. Angka 15,87 itu memang didominasi aspek
tanpa makna statistik, bukan tanda modul aspeknya rusak.

**Pencocokan parsial** melonggarkan hukuman granularitas frasa: label emas
`dewan wakil rakyat` versus prediksi `dewan` + `rakyat` dihitung satu false
negative DAN dua false positive pada mode ketat, padahal konsepnya justru
ditemukan.

Ketiganya kini menampilkan keempatnya. Angka **ketat tetap dilaporkan sebagai
batas bawah** - pelonggaran apa pun tidak boleh menyembunyikannya - dan setiap
permukaan menyebut berapa jenis aspek yang muncul sekali, supaya alasan macro
rendah terbaca tanpa perlu menebak.

Tes: **416**. Yang baru: `AspectMetricReportingTest` (3).

## Balasan titipan: dua pertanyaan terbuka tertutup (10 Sep 2026)

`../nlp-api-service/docs/BALASAN_TITIPAN_ACTIVE_LEARNING.md`:

1. **Blocker bobot aspek adalah alarm palsu.** `models/aspect_retrained.pt`
   sudah dipulihkan dari `models_exp/k6_production/` sebelum jawaban ditulis,
   dan **checksum-nya identik**. Jadi `aspect_retrained: true` memang keadaan
   yang benar. Yang patut dicatat: `/health` **tidak bisa membedakan** "K6" dari
   "K6 yang sudah dilatih ulang" - keduanya melaporkan `true`. Hanya checksum
   yang membedakannya, jadi jangan pernah menyimpulkan kebersihan bobot dari
   `/health` saja.
2. **Kontradiksi urutan diakui**, dan pilihannya jatuh ke **(b) audit
   menyeluruh** - tanpa `--only-predicted`. Bukan karena argumennya, melainkan
   karena bukti baru: 262 draf koreksi untuk lima analisis lain menunjukkan
   anotator **menambah pada 103 baris dan membuang pada 1**, dengan rerata 2,79
   aspek per baris (golden 2,34). Kemunculan tanpa label jauh lebih mungkin
   kelalaian daripada keputusan, sehingga dasar keberatan terhadap audit
   menyeluruh melemah.

   Selisihnya terukur: (b) memberi F1 51,47% versus (a) 55,40%. (a) lebih tinggi
   **justru karena** 61 label yang ditambahkannya semuanya langsung menjadi true
   positive; (b) menambah 131 label yang 61 menjadi TP dan 70 menjadi FN - naik
   dan turun, tanda ia tidak berpihak pada model.

**Iterasi 0 sudah tercatat** (10 Sep 02:10): sentimen dari bobot publik
`crypter70` tanpa retraining, aspek dari checkpoint K6. Sebutkan apa adanya di
naskah.

Catatan dari sisi layanan yang perlu diingat saat mengirim koreksi lima analisis
lain: `kejagung` di-stem Sastrawi menjadi `jagung`, tertabrak dengan jagung
(tanaman) yang muncul 11 kali dengan makna lain; sudah diubah menjadi
`kejaksaan` (stem `jaksa`) di berkas draf yang dibersihkan. Kalau koreksi kelima
analisis itu ikut dikirim sebelum Iterasi 1, laporkan sebagai satu putaran
gabungan - jangan dicampur dengan angka "218 koreksi TNI".

## Kesiapan enam iterasi per analisis (10 Sep 2026)

Pemilik proyek meminta active learning dijalankan **per analisis - enam
iterasi**, dengan evaluasi sesudah tiap putaran. Penyisiran kesiapannya
menemukan dua cacat yang khusus muncul pada rangkaian panjang.

### Nomor iterasi maju walau modelnya tidak berubah

Dua pengukuran baseline berturut-turut (10 Sep 02:10 dan 02:48), **keduanya
sebelum pelatihan apa pun, angkanya identik sampai desimal terakhir** - akurasi
83, macro-F1 75,68, kappa 0,6897, aspek micro 51,84 - tercatat sebagai iterasi 0
dan **iterasi 1**. Pada kurva active learning itu tampil sebagai "iterasi 1 tanpa
perbaikan sama sekali", padahal iterasi 1 belum pernah ada.

`nextIteration()` dulu selalu `max + 1`. Sekarang iterasi **hanya maju bila ada
pelatihan yang selesai setelah run terakhir** - mengukur ulang model yang sama
bukan iterasi baru. Run #2 diperbaiki menjadi iterasi 0 dan dilabeli ulang
"pengukuran ulang baseline"; keduanya dipertahankan karena kesamaannya justru
bukti kestabilan pengukuran.

`model_training_id` juga **ditautkan otomatis** ke pelatihan terakhir yang
selesai, jadi rantai "pelatihan -> pengukuran" terbaca tanpa mengingat urutannya.

### Bobot bisa tertimpa sebelum iterasinya diukur

Enam putaran berarti **enam kesempatan** melakukan kesalahan yang tidak bisa
dibatalkan: begitu checkpoint baru diterima, bobot lama hilang dan iterasi itu
tidak bisa diukur belakangan.

`retrain:analysis` sekarang **menolak berjalan** bila pelatihan terakhir yang
selesai belum ada evaluasi sesudahnya, menyebut pelatihan mana dan kapan
selesainya. Dilewati dengan `--force`.

### Penyisiran tombol

- **68 rute GET** dirender terhadap basis data nyata, sebagai admin **dan**
  pengguna biasa. Semuanya 200/302/403/404 sesuai harapan; tidak ada yang gagal.
- Aksi yang **mengubah data** disilang-periksa terhadap tes. Lima di jalur
  evaluasi belum bertes sama sekali - termasuk **tombol "Jalankan Evaluasi"**
  yang akan ditekan enam kali. Ditutup `EvaluationButtonsTest`: jalankan,
  penolakan saat dataset belum beku, bekukan/buka, penolakan saat masih ada
  baris tanpa label, unggah anotasi, penolakan hapus dataset yang sudah punya
  run, dan seluruhnya tertutup bagi pengguna biasa.

Yang masih tanpa tes dan memang di luar jalur ini: Breeze bawaan
(profile/password/verification) dan subsistem YouTube scraper.

### Keadaan saat ini

| Analisis | Koreksi | Sentimen | Aspek | Status |
|---|---|---|---|---|
| #1 TNI | 218 | 218 | 186 | **siap** |
| #2-#6 | 0 | 0 | 0 | belum cukup |

262 draf koreksi untuk analisis #2-#6 masih di sisi pengguna
(`draf-koreksi-bersih.json`) dan belum diunggah. Tersebar ke lima analisis itu
sekitar 52 per analisis - **tepat di garis ambang 50**, jadi sebagian mungkin
jatuh di bawahnya dan ditolak `retrain:analysis` tanpa `--force`.

Golden dataset (300 baris) menyumbang tidak merata: TNI 115, Presiden 70,
Kejagung 60, DPR 28, KPK 14, Polri 13. Rincian per lembaga pada n=13-14 tidak
bermakna; itu sudah dicatat di `docs/PANDUAN_EVALUASI.md` sebagai deskriptif saja.

**Tidak ada koreksi yang tertahan golden dataset** (`ditahan` = 0 untuk analisis
1), jadi seluruh 218 koreksi memang layak dilatihkan.

Tes: **428**. Yang baru: `IterationSequenceTest` (5), `EvaluationButtonsTest` (7).

## Titipan kedua: jalur UI active learning (10 Sep 2026)

`../nlp-api-service/docs/TITIPAN_UI_ACTIVE_LEARNING.md`. Pemilik proyek
menjalankan active learning **dari antarmuka**, bukan dari `retrain:analysis`,
dan kedua jalur itu ternyata tidak setara.

### Ambang pindah ke layanan bersama (bagian 2)

Ambang kelayakan 50 sampel dulu hidup sebagai `MIN_LAYAK` di dalam
`RetrainAnalysis`, sehingga **tombol UI bisa memicu pelatihan yang perintahnya
sendiri tolak**: jalur UI hanya dibatasi `NLPApiService::MIN_RETRAIN_SAMPLES`
(10). Dengan angka draf yang ada, #4 KPK (21 koreksi) dan #5 Polri (22) lolos UI
tetapi ditolak perintah - dan set validasi 4 baris adalah persis kondisi yang
membuat putaran pertama tidak sah.

Sekarang `RetrainingService::MIN_LAYAK` dan penjaga "iterasi sebelumnya belum
diukur" keduanya di dalam `RetrainingService::dispatch()`, dengan parameter
`bool $paksa`. Ketiga pemicu - koreksi pengguna, tombol admin, perintah artisan -
melewati satu penjaga yang sama. `MIN_RETRAIN_SAMPLES = 10` **tetap** sebagai
batas API; yang naik adalah ambang jalur active learning, seperti diminta.

Memaksa tetap mungkin tetapi harus disengaja: `--force` pada perintah, centang
**"paksa"** pada formulir, dengan konfirmasi tersendiri yang menyebut apa yang
dilewati.

### Pemilihan analisis wajib (bagian 1)

`<option value="">Semua analisis (gabungan)</option>` dulu opsi **pertama**,
jadi terpilih sendiri - dan kedua `model_trainings` iterasi 1 memang tercatat
`text_analysis_id = NULL`. Kini opsi pertama `disabled selected` "Pilih
analisis…", gabungan turun ke bawah dengan **nilai tersendiri** (`gabungan`).

Nilai tersendiri, bukan medan kosong: selama gabungan diwakili string kosong, ia
juga mewakili "tidak memilih apa-apa", dan keduanya tidak bisa dibedakan
validasi. Gabungan punya konfirmasinya sendiri yang menyebut konsekuensinya.

### Tiga angka penentu sahnya putaran (bagian 3)

`val_size`, `weighted_f1_delta`/`f1_delta`, dan `temperature` +
`temperature_inherited` sudah tersimpan di `model_trainings.result` tetapi tidak
pernah dirender. Ditambahkan sebagai accessor (`val_size`, `f1_delta`,
`calibration`) dan kolom di riwayat, plus kolom **Analisis** yang menandai
"Gabungan" dengan tegas.

Delta **selalu** ditampilkan termasuk ketika nol - nol justru tanda putaran yang
tidak sah. Kalibrasi punya **tiga** keadaan, bukan dua: diwarisi, diukur ulang,
dan **tidak tercatat** (putaran sebelum layanan mewariskan suhu, yang justru
mereset T ke 1,0 diam-diam). Melabeli keadaan ketiga "diukur ulang" akan
menyatakan kebalikan dari yang terjadi.

### Tombol Kirim menutup putarannya sendiri

`ANALYSIS_AUTO_RETRAIN` kembali `true`. Yang dulu membuatnya dimatikan - putaran
terpicu pada 19 koreksi dengan val_size 4 - kini dicegah penjaga di
`RetrainingService`, yang berlaku untuk semua jalur; saklar itu hanya mematikan
satu jalur. Jeda (`cooldown`) dihitung **per analisis**, sama seperti
`newCorrectionCount()`: jeda global akan menahan lembaga berikutnya hanya karena
lembaga sebelumnya baru dilatih, padahal studi kasusnya menjalankan enam putaran
satu per lembaga.

Halaman koreksi kini **menyebut** putaran yang benar-benar jalan, dan menyebut
alasan bila penjaga menolaknya (`AutoRetrainService::run()['blocking']`). Yang
tetap bisu adalah keadaan rutin - koreksi belum cukup, masih dalam jeda. Diam
pada kasus yang menghalangi persis kelas masalah yang dikeluhkan titipan: satu-
satunya cara tahu adalah membuka basis data.

### Bilah tombol melewati lebar halaman

Bagian atas `/admin/training` adalah satu `<div class="flex gap-3">` berisi lima
kendali sekaligus, termasuk seluruh formulir retrain (dua select + input angka +
tombol). Formulirnya kini panel tersendiri - ia butuh ruang untuk keterangan
ambang dan centang "paksa" - dan bilah tombol yang tersisa memakai `flex-wrap`.

### Kerusakan yang terjadi saat mengerjakan ini, dan penjaganya

Menyalakan `ANALYSIS_AUTO_RETRAIN=true` lalu menjalankan `php artisan test`
**menimpa checkpoint studi kasus dengan bobot hasil data fixture**. Antrean tes
adalah `sync`, dan `Http::fake([...])` tidak memblokir permintaan yang tidak
cocok dengan satu pun polanya - permintaan itu lolos ke uvicorn yang sedang
berjalan. Tes tidak gagal; kerusakannya seluruhnya di luar prosesnya.

Dua penjaga dipasang, keduanya perlu:

1. `Tests\TestCase::setUp()` memanggil **`Http::preventStrayRequests()`** untuk
   seluruh suite. Kelalaian semacam ini kini gagal keras di tes yang
   melakukannya. Efek sampingan yang menegaskan masalahnya: durasi suite turun
   dari **516 detik ke 28 detik** - selama ini sebagian tes memang menunggu
   layanan Python.
2. `phpunit.xml` menyetel `ANALYSIS_AUTO_RETRAIN=false`, jadi menyalakannya di
   `.env` tidak pernah bisa membuat suite memicu pelatihan. Tes yang memang
   menguji jalur otomatis menyalakannya sendiri lewat `config()->set()`.

Yang menyelamatkan keadaan adalah **protokol kolam** di sisi layanan
(`retrain_from_baseline = True`): tiap pelatihan berangkat dari bobot dasar tetap
(`crypter70` untuk sentimen, K6 untuk aspek) dengan seed 42, bukan dari
checkpoint sekarang. Iterasi karena itu **dapat direproduksi** dari koreksi yang
tersimpan - dan koreksinya tidak tersentuh (218/186).

`models/aspect_baseline.pt` diverifikasi checksum-nya identik dengan
`models_exp/k6_production/aspect_retrained.pt`, jadi dasarnya utuh.

Tes: **433**. Yang baru: 5 di `RetrainTriggerTest` (ambang, paksa, analisis
wajib, gabungan sadar), 2 di `WholeAnalysisCorrectionTest`.

## Protokol kolam: pelatihan selalu atas seluruh koreksi (10 Sep 2026)

Perubahan **arti**, bukan sekadar perbaikan. `RetrainingService::correctedItems()`
tidak lagi menerima penyaring analisis: tiap putaran melatih ulang dari bobot
dasar tetap atas **seluruh kolam koreksi** yang sudah terkumpul, lalu diukur
pada hold-out set yang sama. Itu protokol active learning baku, dan sama dengan
yang sudah dipakai sisi layanan (`retrain_from_baseline = true`).

Kalau kolamnya disaring per analisis sementara layanan tetap berangkat dari
bobot dasar, putaran kedua hanya melihat lembaga kedua - lembaga pertama
**terlupakan seluruhnya**, dan kurva iterasinya mengukur "ganti korpus" alih-alih
"tambah data". Dua sisi harus sepakat soal ini; penyaringan di satu sisi saja
menghasilkan pelatihan yang tidak berakumulasi.

### Pemilihan analisis berubah arti

Dari **penyaring data latih** menjadi **pencatatan asal putaran**: "lembaga ini
baru saja masuk ke kolam". Dipakai untuk `model_trainings.text_analysis_id` dan
penamaan iterasi ("Iterasi 2 - setelah Presiden ditambahkan"), tidak pernah
untuk menyusun muatan.

Dua method terpisah, dan itu disengaja:

- `correctedItems()` - kolam, tanpa argumen sama sekali.
- `contributionOf(int $analysisId)` - sumbangan satu lembaga, **untuk pelaporan
  saja** (tabel perintah, label dropdown).

Satu method dengan argumen opsional akan mengulang kesalahan yang sama: satu
pemanggil yang lupa mengosongkan argumennya sudah cukup untuk diam-diam melatih
sepotong kolam. Menghapus argumennya membuat kekeliruan itu menjadi galat sintaks.

### Pemicu otomatis mengikuti protokol yang sama

- **`newCorrectionCount()` memakai pelatihan terakhir mana pun** sebagai titik
  mulai, bukan pelatihan analisis itu. Pelatihan terakhir sudah melatih seluruh
  kolam termasuk koreksi lembaga ini; menghitung dari "pelatihan lembaga ini"
  akan menghitung ulang baris yang sudah dilatihkan sebagai "baru", dan putaran
  berikutnya terpicu tanpa satu label baru pun.
- **Ambang pemicu dinilai atas seluruh label baru di kolam**, bukan sumbangan
  satu lembaga. Menilai per lembaga akan menolak putaran yang sah hanya karena
  lembaga terakhir kecil - #6 DPR menyumbang 46, sementara kolamnya sudah ratusan.
- **Jeda kembali global**, karena pelatihan kini peristiwa global. Jeda per
  analisis akan membolehkan enam putaran berturut-turut atas kolam yang praktis
  sama. `ANALYSIS_AUTO_RETRAIN_COOLDOWN=0` selama studi kasus: yang menjaga
  urutan bukan jeda melainkan penolakan "iterasi sebelumnya belum diukur", dan
  itu tidak bisa dilewati dengan menunggu.
- **`MIN_LAYAK` kini menilai kolam.** Begitu kolam >= 50, tiap putaran lolos -
  dan itu memang benar: yang menentukan set validasi cukup besar adalah ukuran
  yang dilatih, yaitu kolam.

### Urutan yang menjaga rangkaian tetap sah

Hanya satu penjaga yang benar-benar mengurutkan enam iterasi, dan letaknya di
`RetrainingService::dispatch()`: **pelatihan ditolak selama pelatihan terakhir
belum ada evaluasinya**. Itu yang mencegah bobot iterasi N tertimpa sebelum
iterasi N sempat diukur - kesalahan yang tidak bisa dibatalkan. Jeda waktu,
ambang koreksi, dan saklar otomatis semuanya bisa dilewati; penjaga ini tidak,
kecuali dengan `--force` / centang "paksa" yang eksplisit.

Tes: **434**. `WholeAnalysisCorrectionTest` menukar tes penyaringan per analisis
dengan tiga tes protokol kolam: kolam utuh, analisis hanya dicatat, dan lembaga
kecil tetap lolos karena kolamnya besar.

## Penjaga "belum diukur" disaring per modul (10 Sep 2026)

`../nlp-api-service/docs/TITIPAN_PENJAGA_PELATIHAN.md`. Celah yang baru muncul
karena keadaan campuran: iterasi 2 meninggalkan pelatihan #3 (sentiment, **gagal**
- os error 1224) dan #4 (aspect, selesai, belum diukur). Mengulang **sentimen
saja** ditolak dengan alasan bobot #4 akan tertimpa.

Alasannya tidak berlaku: kedua modul menulis ke berkas yang berbeda -
`models/sentiment_retrained/` (direktori) versus `models/aspect_retrained.pt`
(berkas tunggal). Melatih sentimen tidak bisa menimpa bobot aspek.

`unmeasuredTraining(?string $modelType = null)` kini menyaring
`whereIn('model_type', [$modelType, 'both'])`. `both` di kedua arah dan itu
wajib: pelatihan `both` yang belum diukur memang menimpa keduanya, jadi ia tetap
memblokir permintaan satu modul. Diteruskan dari `tolakBilaTidakLayak()`, jadi
ketiga jalur (tombol koreksi, tombol admin, `retrain:analysis`) ikut - tidak ada
salinan penjaga di luar `RetrainingService`.

Dua alasan mengapa ini bukan kerapian:

1. Menuruti penjaga yang tidak disaring berarti menjalankan evaluasi atas
   gabungan **aspek iterasi 2 + sentimen bobot dasar** - satu titik campuran
   yang tidak sesuai iterasi mana pun, dan hanya menambah baris membingungkan di
   `evaluation_runs`.
2. Begitu "paksa" jadi kebiasaan setiap kali satu modul gagal dan modul lain
   berhasil, penjaga yang **benar** ikut kehilangan daya - dan pada rangkaian
   enam iterasi, menimpa bobot yang belum diukur tidak bisa dibatalkan.

Diverifikasi terhadap basis data nyata pada keadaan iterasi 2 itu sendiri:
`sentiment` boleh jalan, `aspect` dan `both` diblokir oleh #4.

### Catatan fixture

`analisisBerkoreksi()` semula hanya mengisi `corrected_sentiment`, sehingga
muatan aspeknya nol dan permintaan `--model=aspect` ditolak lebih dulu oleh
penjaga **ambang** - penjaga "belum diukur" yang sedang diuji tidak pernah
tercapai. Aspeknya kini ikut dikoreksi. Kelas kesalahan yang sama membuat satu
tes sempat lulus karena alasan yang salah.

Tes: **439**. Yang baru: 5 di `IterationSequenceTest` (aspek tidak menghalangi
sentimen, sentimen tidak menghalangi aspek, `both` memblokir keduanya, dan dua
tes perintah ujung ke ujung).

## Pembersihan iterasi 2: putaran batal & sidik jari kolam (10 Sep 2026)

`../nlp-api-service/docs/TITIPAN_PEMBERSIHAN_ACTIVE_LEARNING.md`.

### Kenapa training #4 dibatalkan

Bukan karena angkanya jelek - **angkanya justru naik** (aspek 27,14 -> 31,57).
Karena angkanya tidak dapat direproduksi: titik berangkatnya bukan bobot dasar
melainkan checkpoint yang sudah tercemar dua pelatihan verifikasi berkalimat
sintetis, dan checkpoint antaranya sudah tertimpa sendiri. Tidak ada seorang pun
yang bisa menghitung ulang 31,57.

Itu sebabnya penanda `batal` ada di data, bukan barisnya dihapus: **jejak
auditnya justru yang perlu disimpan**. Yang dibuang adalah ANGKANYA.

### `scopeCompleted()` menjadi satu-satunya pintu

`EvaluationRun::scopeCompleted()` kini `->where('status','completed')->sah()`,
dan `scopeSah()` menyingkirkan `model_info->batal`. Disaring di scope, bukan di
tiap pemanggil: kurva iterasi, perbandingan McNemar, laporan markdown, dan
penomoran iterasi semuanya lewat sana, dan satu jalur yang lupa sudah cukup untuk
memasukkan kembali angka yang tidak boleh dikutip ke naskah.

Konsekuensi yang memang diinginkan: run iterasi 2 yang dibatalkan **tidak lagi
menaikkan nomor iterasi**, jadi pengukuran berikutnya kembali menjadi iterasi 2 -
bukan melompat ke 3 dan meninggalkan lubang di kurva.

Dibaca sebagai JSON, bukan disalin ke kolom sendiri: penanda ini kadang ditulis
langsung ke basis data dari sisi layanan, dan kolom bayangan akan diam-diam basi.

### Tampilan: diredupkan, angkanya tidak dicetak

Baris batal tetap dirender di riwayat training dan riwayat evaluasi dengan
lencana **BATAL** dan alasannya, tetapi kolom angkanya diganti "tidak dapat
direproduksi - jangan dikutip". Mencetak angkanya dengan penanda saja tidak
cukup: angka yang terbaca akan dikutip. Ada tesnya (`assertStringNotContainsString`
atas angka run batal).

### Sidik jari kolam

`App\Support\PoolFingerprint` - **salinan persis** `pool_fingerprint()` di
`../nlp-api-service/app/utils/training.py` (SHA-256 atas teks terurut, pemisah
`\x00`, dipotong 16 heksadesimal). Diverifikasi cocok pada lima vektor termasuk
emoji, aksen, dan kasus tabrakan `["ab","c"]` vs `["a","bc"]`; vektornya dikunci
di `CancelledRoundTest`. Harus persis - kalau algoritmanya berbeda sedikit saja,
angka Laravel tidak akan pernah cocok dengan `provenance.json` dan
perbandingannya kehilangan arti (kelas kesalahan yang sama dengan
`TextFingerprint` pada penjaga kebocoran).

**Catatan penting:** titipan menyebut "simpan `sidik_jari_kolam` yang dikembalikan
`/api/retrain/*`", tetapi layanan **belum mengembalikannya di badan respons** -
ia hanya menulisnya ke `provenance.json`. `RetrainModel` karena itu menghitung
sendiri dari muatan yang benar-benar dikirim, dan `result.sidik_jari_sumber`
mencatat `laravel` atau `layanan`. Begitu layanan mengembalikannya, nilainya yang
dipakai - dan selisih antara keduanya justru temuan yang layak dilihat.

Ditampilkan sebagai kolom di riwayat training. Sidik jari yang **sama dengan
putaran sebelumnya pada modul yang sama** ditandai "kolam tidak bertambah" -
keliru menurut protokol kolam. Dibandingkan per modul karena muatan sentimen dan
aspek berbeda dan silangnya tidak berarti apa-apa.

### `trained_from_baseline`, bukan `baseline_swapped`

`ModelTraining::getDariBobotDasarAttribute()` membaca yang pertama. Yang kedua
hanya menyala ketika bobot DITUKAR, sehingga putaran yang memang berangkat dari
bobot dasar karena checkpoint-nya belum ada tercatat `false` - kebalikan dari
yang terjadi. Training #5 kena itu. Riwayat menandai putaran yang **tidak**
berangkat dari bobot dasar, karena protokol kolam menuntut selalu dari dasar.

Tes: **446**. Yang baru: `CancelledRoundTest` (7).

## Enam iterasi selesai & pembersihan akhir (11 Sep 2026)

Seluruh rangkaian active learning dijalankan pemilik proyek. Keadaan akhir:
**7 titik pengukuran (iterasi 0-6), 481 koreksi di kolam, 13 pelatihan.**

### Baris yang dihapus atas permintaan pemilik proyek

Titipan sisi layanan menyarankan menyimpannya sebagai jejak audit; pemilik
proyek memilih menghapusnya agar tidak mengganggu. Cadangan JSON lengkap
menggantikan peran itu:
`storage/app/backups/active-learning-sebelum-pembersihan-20260910-173311.json`
(15 pelatihan, 8 run, 300 baris prediksi run #4).

- `model_trainings#3` - sentimen, **gagal** (os error 1224, Windows file-mapping)
- `model_trainings#4` - aspek, **batal** (berangkat dari checkpoint tercemar)
- `evaluation_runs#4` + 300 `evaluation_run_items` - **batal**

Skripnya berpenjaga: hanya menghapus baris yang benar-benar `failed` atau
bertanda `batal`, dan membatalkan seluruh operasi bila salah satu ternyata sah.
Run yang menunjuk pelatihan terhapus **dilepas tautannya**, bukan ikut dihapus.

### Nomor iterasi diluruskan

Run #5-#9 tercatat `iteration` 3-7 sementara labelnya "Iterasi 2-6": run batal
sempat menaikkan nomor sebelum `scopeCompleted()` menyaringnya. Label yang benar
- itu yang dibaca manusia dan yang akan dikutip naskah - jadi kolomnya
diluruskan mengikuti urutan `completed_at`. Sekarang 0..6 tanpa lubang.

### Bukti protokol kolam bekerja

`total_samples` naik monoton tiap putaran: **218 -> 330 -> 392 -> 413 -> 435 ->
481** (sentimen), 186 -> 279 -> 329 -> 342 -> 359 -> 398 (aspek). Kolam
bertambah, tidak diganti.

**Reproduksibilitas terbukti:** pelatihan #5 (13:00) dan #6 (15:18) - kolam yang
sama, seed yang sama, bobot dasar yang sama - menghasilkan angka yang **identik
sampai empat desimal**: wF1 0,5538 -> 0,6988, T = 2,3238. Keduanya sengaja
dipertahankan; itu bukti kestabilan yang layak dikutip.

`metrics_before` aspek pada putaran yang sah berkisar **0,41-0,49** (K6 diukur
pada split validasinya masing-masing), sementara pelatihan #4 yang dibatalkan
**0,3256** - pencilan yang konsisten dengan titik berangkat yang tercemar.

### Yang perlu disebut apa adanya di naskah

1. **Perbaikan sentimen tidak signifikan secara statistik.** McNemar iterasi 0
   lawan 6: b = 21 memburuk, c = 29 membaik, **p = 0,322** (binomial eksak).
   Dengan 21 baris memburuk, uji ini baru mendeteksi perubahan bila minimal 37
   membaik. Akurasi 83 -> 85,67 (+2,67 poin) berada di dalam derau.
2. **Perbaikan ekstraksi aspek besar dan konsisten**: micro-F1 ketat
   51,84 -> 74,38, parsial 56,49 -> 80,06, macro support>=2 **29,12 -> 72,76**,
   exact match 27,67 -> 40,67, Hamming loss 1,5733 -> 1,1967.
3. **Kurva sentimen tidak monoton**: 83 / 86,33 / 85,67 / **87,33** / 85,67 /
   84,33 / 85,67. Puncaknya di iterasi 3, bukan di akhir.
4. **Suhu kalibrasi tidak stabil** antar putaran: 2,3238 / 1,6729 / 0,7617 /
   4,1704 / 0,9336 pada set validasi 66-96 baris.
5. **Sidik jari kolam kosong untuk seluruh 13 pelatihan** - fiturnya ditambahkan
   setelah rangkaian selesai. Buktinya `total_samples` yang naik monoton, bukan
   sidik jari. Berlaku untuk putaran berikutnya.
6. `trained_from_baseline` **null untuk seluruh pelatihan aspek** - modul aspek
   di sisi layanan tidak mengembalikannya di badan respons.

Tes: **446**.

## Persiapan deployment (22 Sep 2026)

Satu putaran menyeluruh menyiapkan aplikasi untuk dipasang, sekaligus
membereskan beberapa hal yang tidak layak ikut ter-deploy.

### Dokumen penelitian keluar dari folder aplikasi

Dipindah ke `../dokumen-skripsi/`: `SYSTEM_ARCHITECTURE*.md` (3 berkas),
`docs/` (PANDUAN_EVALUASI, PEDOMAN_ANOTASI), `scratch/`, dan
`hasil-uji-php.txt`. Yang tersisa di akar hanya `README.md` dan `CLAUDE.md`.
`resources/views/welcome.blade.php` dan `dashboard.blade.php` dihapus: tidak ada
satu pun controller yang mengembalikannya.

### Halaman depan memuat testimoni karangan

Temuan paling serius di putaran ini. `LandingController` memuat **tiga testimoni
lengkap dengan nama, jabatan, dan foto** ("Dr. Ahmad Fauzi, Peneliti BPS"),
ditambah "99% Akurasi Model" dan "500+ Pengguna Aktif". Semuanya karangan, dan
angka akurasinya bahkan **bertentangan dengan hasil ukur sendiri** (85,67%).
Satu klaim lagi tersembunyi di kartu melayang: "< 5 detik", padahal terukur
0,27 detik per teks sehingga korpus ribuan baris justru memakan menit.

Seluruhnya diganti blok **Hasil Pengukuran** yang tiap barisnya menyebut
dasarnya sendiri (n, selang kepercayaan, himpunan uji), plus catatan batasan
bahwa angka itu berlaku untuk korpus komentar YouTube berbahasa Indonesia.

### Tombol "Jelaskan dengan AI" menjawab tiga pertanyaan, bukan satu

Skema keluaran bertambah dari dua medan menjadi empat: `narrative`,
`highlights`, **`why`** (mengapa bagian ini penting, dikaitkan dengan datanya
sendiri), dan **`how`** (2-4 langkah konkret yang mengacu pada angka atau nama
yang benar-benar muncul di data). `PROMPT_VERSION` naik ke 3.

Kegunaan tiap jenis analisis ditulis di sisi kode (`kegunaanBagian()`), bukan
diserahkan sepenuhnya ke model: kegunaan sebuah metode adalah pengetahuan tetap
yang tidak berubah mengikuti data, dan membiarkan model mengarangnya tiap kali
membuka peluang penjelasan yang keliru tentang alat yang dipakai.

Narasi lama (versi 2) tidak punya kedua medan baru, jadi partial-nya membaca
defensif dan halaman tetap terbuka tanpa perlu dibangkitkan ulang. Ada tesnya.

### Alur scraper ke analisis tersambung

`App\Support\ScraperExport` menjadi **satu-satunya** penjawab "nama berkas ini
sah atau tidak", dipakai bersama tombol unduh dan jalur analisis. Berkasnya
dibungkus `UploadedFile` sehingga melewati pemrosesan yang persis sama dengan
unggahan manual; menyalin logika pemrosesan ke jalur kedua akan membuat kedua
jalur berselisih pada berkas yang isinya sama.

`download()` **tidak lagi memakai `deleteFileAfterSend()`**. Alur "ambil data
lalu analisis" mustahil dengan itu: menekan Unduh menghapus berkas yang beberapa
detik kemudian dipakai tombol "Analisis Sekarang".

Penjaga sisi klien ikut disesuaikan: dua tempat menuntut `<input type=file>`
terisi, padahal berkas scraper tidak pernah mengisinya. `adaBerkasMasukan()`
memakai aturan yang sama dengan `berkasMasukan()` di server, jadi penjaga klien
tidak pernah menolak kiriman yang sebenarnya akan diterima.

### Keterangan angka mendefinisikan, bukan menilai

`coherenceMeaning()` menggantikan label pita ("Baik untuk teks pendek") pada
kartu koherensi. Label yang menilai tidak memberi tahu apa yang diukur, sehingga
pembaca tidak bisa menimbang sendiri apakah penilaian itu masuk akal untuk
datanya. Pitanya tinggal dipakai untuk warna. Kartu `outlier_rate` dan `c_npmi`
ikut diperlakukan sama.

Paragraf rambu c_v dan ketergantungan seed dihapus dari halaman hasil atas
permintaan pemilik proyek. **Masih ada di `/admin/evaluation`**, dan sengaja:
di sanalah angka untuk naskah dibaca.

### Lainnya

- **Ringkasan Eksekutif AI** naik ke bawah Ringkasan Analisis. Keduanya menjawab
  pertanyaan yang sama, dan panel keyakinan di antaranya memutus bacaan.
- **Panel "Kesiapan Model" dibuang** dari formulir analisis beserta skripnya.
  Isinya urusan internal layanan dan tidak menuntun pengguna melakukan apa pun.
- **Menu Pengaturan jadi nyata**: kolom baru `users.preprocessing_config_id`
  (nullOnDelete) plus kartu "Preferensi Analisis" yang menentukan konfigurasi
  terpilih saat membuat analisis baru. `old()` tetap menang atas preferensi.
- **Halaman galat** 403/404/419/429/500/503 dengan layout sendiri yang tidak
  membaca `Auth::user()`: halaman ini juga dirender saat sesi kedaluwarsa.
- **Halaman auth** dirombak menjadi dua kolom berbahasa Indonesia, dengan judul
  tab per halaman (dulu semuanya berbunyi "Laravel").
- **`APP_LOCALE=id`** supaya `Carbon::translatedFormat()` merender tanggal dalam
  bahasa Indonesia. `fallback_locale` tetap `en` karena `lang/id` belum ada.
- **README.md** diganti dari bawaan Laravel menjadi petunjuk pemasangan,
  konfigurasi, dan langkah produksi yang sebenarnya.
- **Tanda pisah**: 59 kalimat yang dibangun di sekitar em dash lalu ditukar tanda
  hubung begitu saja **diparafrase**, bukan ditukar lagi. Sisa " - " pada berkas
  tampilan seluruhnya aritmetika (`$a - $b`), bukan prosa.

### Diverifikasi

68 rute GET dirender sebagai admin dan pengguna biasa: seluruhnya
200/302/403/404 sesuai harapan. `config:cache`, `route:cache`, dan `view:cache`
berhasil, artinya tidak ada `env()` liar di luar config.

### Halaman Pengaturan tidak pernah menampilkan isinya

Ditemukan saat memverifikasi menu Pengaturan. `profile/edit.blade.php` memakai
`<x-app-layout>`, sementara `layouts/app.blade.php` menaruh isi lewat
`@yield('content')` dan **bukan** `{{ $slot }}`. Akibatnya seluruh formulir
dibuang diam-diam: halaman tetap membalas 200 dan kerangkanya tampil lengkap,
tetapi tidak ada satu pun kolom yang bisa diisi. Halaman profil karena itu tidak
pernah benar-benar berfungsi sejak dipasang.

Tidak ada tes yang menangkapnya karena penyisiran rute sebelumnya hanya
memeriksa **kode status**, dan 200 memang yang dikembalikan. `SettingsPageTest`
kini memeriksa keempat kartunya benar-benar dirender.

Diperbaiki dengan `@extends('layouts.app')`, mengikuti seluruh halaman lain.
Ketiga partial bawaan Breeze (data akun, kata sandi, hapus akun) sekalian
dialihbahasakan; teksnya diganti langsung, bukan lewat `lang/id`, karena menambah
berkas terjemahan untuk tiga halaman justru menyebarkan kalimatnya ke dua tempat.

### Berkas keluaran yang menumpuk di akar

`hasil-uji-php.xml` (141 KB laporan PHPUnit), `metrik-*.json` (3 berkas, 544 KB
potret metrik penelitian), `test_api.php`, dan `test_output.json` ikut dipindah:
metriknya ke `../dokumen-skripsi/metrik/`, sisanya ke `scratch/`. Polanya
ditambahkan ke `.gitignore` supaya tidak kembali.

Tes: **462**. Yang baru: `ScraperToAnalysisTest` (8, termasuk penolakan path
traversal dalam empat bentuk), `SettingsPageTest` (5), 3 di
`AiInterpretationTest`.

## Pedoman penggunaan dalam aplikasi (22 Sep 2026)

Halaman `/panduan` yang mengajarkan alur utama aplikasi, dipakai juga sebagai
bahan bacaan peserta sebelum mengisi kuesioner CSUQ.

### Dua sifat yang dijaga tes

1. **Bisa dibuka tanpa masuk.** Peserta uji coba biasanya membacanya sebelum
   punya akun, dan pedoman yang menuntut login untuk menjelaskan cara login
   tidak berguna. Halamannya karena itu **tidak memakai `layouts.app`**, yang
   membaca `Auth::user()->name` pada bilah sisinya dan akan gagal untuk tamu.
2. **Tautannya benar-benar ada di layar**: bilah menu aplikasi (membuka tab
   baru, supaya pekerjaan yang sedang berjalan tidak ditinggalkan), menu dan
   footer halaman depan. Pedoman yang hanya bisa dicapai dengan mengetik alamat
   sama saja dengan tidak ada.

### Tangkapan layar ditambahkan belakangan

`<x-panduan-gambar>` merender **tempat kosong bernama** ketika berkasnya belum
ada: kotak putus-putus yang menyebut `public/panduan/<nama>.png` dan isi yang
diharapkan. Tata letaknya sudah benar sejak pedoman ditulis, jadi menambahkan
gambar cukup menaruh berkas dan tidak menuntut penataan ulang. Tanpa itu, gambar
yang belum ada tampil sebagai ikon rusak dan halamannya harus ditata ulang
setiap kali satu gambar ditambahkan.

Enam nama berkas yang ditunggu didokumentasikan di `public/panduan/README.txt`.

### Isinya di controller, bukan di Blade

Sebagian besar pedoman berbentuk daftar berpola (langkah, istilah, skenario,
masalah umum). Disimpan sebagai larik di `PanduanController` supaya menambah
atau menukar urutan satu butir cukup menyentuh satu baris.

Konsekuensinya: daftar isi dibangun dari larik itu sementara jangkarnya ditulis
tangan di Blade, dan keduanya mudah berselisih. Ada tes yang memindai setiap
`href="#..."` lalu memastikan `id`-nya benar-benar ada, karena tautan menuju
jangkar kosong hanya ketahuan kalau diklik.

### Bagian khusus CSUQ

Bagian 10 memuat **enam tugas berurutan** yang menyentuh seluruh alur pokok:
masuk, menjalankan analisis, membaca hasil, memakai penjelasan AI, mengoreksi,
dan mengekspor. Tiap tugas menyebut kriteria "selesai bila", supaya penilaian
antar peserta dibandingkan atas dasar pekerjaan yang sama. Halamannya bisa
dicetak (`@media print` membuang navigasi, menjaga isinya utuh).

Tes: **469**. Yang baru: `PanduanTest` (7).

## Kesiapan deploy & jebakan `public/hot` (22 Sep 2026)

### Kenapa `/panduan` "tidak bisa diakses"

Rutenya terdaftar dan halamannya membalas 200. Yang rusak adalah **asetnya**:
berkas `public/hot` tertinggal di folder proyek, berisi `http://[::1]:5173`.
Berkas itu ditulis `npm run dev`, dan selama ia ada `@vite` mengarahkan seluruh
CSS serta JavaScript ke server pengembangan Vite. Server itu sudah mati, jadi
**setiap halaman** dirender tanpa gaya sama sekali dan terbaca seperti halaman
yang gagal dimuat.

Halaman baru terasa "paling rusak" hanya karena ia yang paling bergantung pada
tata letak; halaman lama pun sebenarnya ikut kehilangan gayanya.

`public/hot` sudah ada di `.gitignore`, jadi ia tidak akan ikut ter-commit.
Tetapi ia **tetap bisa ikut** bila folder proyek disalin apa adanya ke server,
dan gejalanya persis sama di sana. Karena itu `DEPLOYMENT.md` menyebutnya dua
kali: pada langkah membangun aset, dan pada daftar periksa.

Perbaikannya: `rm -f public/hot` lalu `npm run build`.

### Yang disiapkan untuk deploy

- **`DEPLOYMENT.md`** - panduan lengkap: spesifikasi server, MySQL, Nginx,
  Supervisor, systemd untuk layanan NLP, HTTPS, optimasi, dan daftar periksa.
- **`.env.production.example`** - hanya kunci yang berbeda dari `.env.example`
  atau yang wajib diperiksa ulang, masing-masing dengan alasannya.
- **`robots.txt`** - halaman depan dan `/panduan` boleh diindeks; area kerja
  pengguna tidak, karena toh menuntut login.
- **README** menunjuk `DEPLOYMENT.md` alih-alih menggandakan langkahnya.

### Kendala hosting yang menentukan pilihan server

Ini **dua aplikasi**, dan yang kedua berat: `nlp-api-service` memerlukan PyTorch
(sekitar 2 GB RAM saat memuat model) ditambah **1,4 GB bobot model** di disk
(`aspect_baseline.pt` 473 MB, `aspect_retrained.pt` 473 MB,
`sentiment_retrained/` 476 MB; berkas `*.bak_*` tidak perlu ikut).

Akibatnya **shared hosting dan cPanel biasa tidak cukup**. Minimum yang masuk
akal: VPS 4 GB RAM, 20 GB disk, 2 vCPU.

Tiga hal yang paling sering membuat pemasangan gagal, dan ketiganya masuk daftar
periksa:

1. **Worker antrean tidak berjalan** - setiap analisis berhenti selamanya di
   "sedang diproses". `--tries=1`, `--timeout=3700`, dan `numprocs=1` ketiganya
   punya alasan yang ditulis di panduan.
2. **Document root menunjuk akar proyek**, bukan `public/`. Itu membuat `.env`
   bisa diunduh siapa pun lewat peramban.
3. **Port 8001 terbuka ke internet.** Layanan NLP tidak punya autentikasi sama
   sekali, jadi siapa pun yang menjangkaunya bisa memicu pelatihan ulang.

Tes: **469**.

## Folder di `public/` bisa menelan rute (22 Sep 2026)

Halaman `/panduan` membalas **404** di peramban walaupun `route:list`
menampilkannya dengan normal dan seluruh tesnya lulus. Penyebabnya bikinan
sendiri: tangkapan layar pedoman ditaruh di **`public/panduan/`**, persis sama
dengan alamat rutenya.

Web server selalu memeriksa berkas dan folder **lebih dulu** sebelum menyerahkan
permintaan ke Laravel, jadi foldernya menelan rutenya sendiri. Gejalanya berbeda
di tiap server, dan tidak satu pun menyebut Laravel:

| Server | Gejala |
|---|---|
| Server bawaan PHP (`artisan serve`) | `404 The requested resource /panduan was not found on this server` |
| Apache | Rewrite dilewati oleh `RewriteCond %{REQUEST_FILENAME} !-d` |
| nginx | `403`, karena `try_files $uri $uri/` cocok ke foldernya |

Tesnya lulus justru karena `$this->get(route('panduan'))` memanggil kernel
Laravel langsung dan **tidak pernah menyentuh lapisan berkas** - persis lapisan
tempat kerusakannya berada. Itu kelas kesalahan yang sama dengan penyisiran rute
yang dulu hanya memeriksa kode status: keduanya menguji lapisan yang salah.

Dipindah ke **`public/img/panduan/`**, dan `PanduanTest` mendapat penjaga baru
yang memindai seluruh rute lalu memastikan segmen pertamanya tidak berupa folder
di `public/`. `storage` dikecualikan dan itu benar: `public/storage` adalah
symlink `storage:link`, dan menaunginya rute `storage/{path}` memang yang
diinginkan karena melayani berkas langsung jauh lebih murah.

Diverifikasi lewat HTTP sungguhan, bukan hanya kernel: `php artisan serve` pada
port terpisah, lalu `curl` ke `/`, `/panduan`, `/login`, dan berkas di
`/img/panduan/`. Seluruhnya 200.

### Catatan `public/hot` yang menyusul

Saat memverifikasi, `public/hot` sempat muncul lagi dan menunjuk
`http://[::1]:5173`. Kali ini **benar**: pemilik proyek sedang menjalankan
`composer dev`, jadi Vite memang melayani asetnya (diverifikasi
`/@vite/client` dan `/resources/css/app.css` membalas 200). Berkas itu sengaja
tidak dihapus.

Yang perlu diingat: berkas yang sama menjadi masalah begitu server Vite mati,
dan gejalanya adalah **seluruh halaman tanpa gaya** - bukan hanya halaman baru.

Tes: **470**.

## Mekanisme deploy (22 Sep 2026)

Tiga berkas, masing-masing menjawab satu pertanyaan yang berbeda.

### `php artisan deploy:check`

Gerbang kesiapan, dan dipakai `deploy.sh` sebagai penentu berhasil atau
tidaknya sebuah deploy. Dibuat karena tiga kesalahan pemasangan yang paling
sering terjadi semuanya **tidak menjatuhkan aplikasi**: halamannya tetap
membalas 200, dan baru ketahuan setelah ada yang mencoba memakainya.

Memeriksa APP_KEY, APP_DEBUG, `retry_after` versus timeout job, aset terbangun,
symlink storage, izin tulis, tabrakan folder dengan rute, folder `.git` di dalam
`public/`, koneksi dan migrasi basis data, antrean, serta layanan NLP.

Sudah menemukan tiga hal nyata saat pertama dijalankan:

1. **`public/anotasi/.git/`** - klon repositori `anotasi-web` di dalam direktori
   yang dilayani web. Selama dotfile tidak diblokir, `/anotasi/.git/config` bisa
   diambil siapa pun dan isi repositorinya disusun ulang. Peringatan di lokal,
   **kegagalan** dengan `--produksi`; `build-release.sh` mengecualikannya dari
   paket.
2. **`.pytest_cache/`** di akar proyek PHP, sisa dari sisi layanan Python.
   Masuk `.gitignore` dan dikecualikan dari paket.
3. Dua pemeriksaan buatan sendiri yang **salah** dan langsung ketahuan: symlink
   storage diperiksa dengan `is_link()`/`is_dir()` yang keduanya mengembalikan
   false untuk symlink Windows walaupun tautannya berfungsi (diganti
   `realpath()`), dan status migrasi dibaca lewat API internal migrator yang
   melempar diam-diam sehingga berubah menjadi catatan "tidak bisa dibaca"
   alih-alih menjawab pertanyaannya (diganti perbandingan langsung terhadap
   tabel `migrations`).

### `deploy.sh`

Dijalankan di server, idempoten. Halaman pemeliharaan, dependensi, aset,
migrasi, cache, muat ulang worker, lalu `deploy:check --produksi`. Situs
dikembalikan menyala lewat `trap EXIT` walaupun ada langkah yang gagal di
tengah.

### `build-release.sh`

Dijalankan di laptop, menghasilkan `.tar.gz` sekitar 600 KB berisi kode plus
aset terbangun. Untuk server tanpa Git atau Node.js.

Dua kesalahan yang terjadi saat menulisnya, keduanya sudah diperbaiki:

- **`npm ci` menghapus `node_modules` lebih dulu**, dan itu mencabut paket di
  bawah kaki server Vite yang sedang berjalan (`composer dev`). Diganti
  `npm install` yang hanya dijalankan bila `node_modules` belum ada.
- **`rm -f public/hot` percuma** selama `npm run dev` berjalan: Vite langsung
  menulisnya kembali. Yang penting ia tidak ikut ke paket, dan itu sudah
  dijamin daftar `--exclude`.

`--exclude='.git'` ditulis **tanpa `./` di depan** supaya berlaku pada setiap
tingkat, bukan hanya akar. Versi pertama memakai `'./.git'` dan meloloskan
`public/anotasi/.git/` (67 entri) ke dalam paket.

### Catatan tes

`phpunit.xml` menyetel `QUEUE_CONNECTION=sync`, dan itu benar untuk menjalankan
tes - tetapi salah satu keadaan yang justru harus DITOLAK `deploy:check`.
`DeployCheckTest` karena itu menyiapkan keadaan "sehat" secara eksplisit alih-
alih mewarisinya dari lingkungan tes.

Satu lagi: `Http::fake()` **menggabungkan** stub, bukan menggantinya, dan stub
yang cocok lebih dulu yang menang. Stub "layanan sehat" di `setUp()` membuat tes
yang justru menguji layanan mati tetap melihat balasan 200. Fake-nya dipindah ke
masing-masing pemanggil.

Tes: **477**. Yang baru: `DeployCheckTest` (7).

## Kunci API layanan NLP & varian Hugging Face Spaces (23 Sep 2026)

Layanan NLP kini **berkunci**: setiap endpoint kecuali `/` dan `/health` menuntut
header `X-API-Key`, dan wajib diisi bila `APP_ENV=production` (lihat
`../nlp-api-service/app/security.py`). **Catatan di atas yang menyebut layanan
NLP "tidak punya autentikasi" sudah tidak berlaku** - termasuk bagian "Kendala
hosting". Sisi web sudah mengirim header itu dari `NLP_API_KEY` sejak sebelumnya
(`NLPApiService::http()`); yang kurang adalah dokumentasi dan pemeriksaannya.

`NLP_API_KEY` (web) dan `API_KEY` (layanan) harus sama persis. Di lokal keduanya
sudah diisi dan diverifikasi identik (48 karakter). Di Hugging Face Spaces,
`API_KEY` diisi sebagai **Secret**, bukan di berkas.

### Celah yang ditutup: `deploy:check` hijau dengan kunci yang salah

`/health` sengaja terbuka tanpa kunci supaya platform hosting bisa memakainya
sebagai healthcheck. Akibatnya "Layanan NLP merespons" lulus walaupun kuncinya
salah, sementara **setiap analisis ditolak 401**.

`NLPApiService::checkApiKey()` memanggil `/api/normalize/aspects` - endpoint
berkunci yang paling ringan (hanya stemmer, tidak memuat model, tidak mengubah
apa pun) - dan membedakan 200, 401/403 (kunci kosong atau salah, dengan pesan
berbeda), dan galat lain. `deploy:check` menampilkannya sebagai baris tersendiri.

Diverifikasi terhadap layanan yang berjalan: kunci benar -> HTTP 200; kunci yang
sengaja disalahkan -> "Layanan NLP merespons" **tetap OK** dan pemeriksaan baru
GAGAL dengan HTTP 401. Persis celah yang dimaksud.

### Varian HF Spaces di `DEPLOYMENT.md` (bagian 0b)

Hanya sisi web; pemasangan Space diurus repo layanan (Dockerfile port 7860,
`HF_WEIGHTS_REPO`, `HF_TOKEN`). Isinya: server web cukup **1 GB RAM** karena
PyTorch pindah ke HF, langkah 8 dilewati, keamanan lewat kunci API bukan
firewall, dan **worker antrean tetap wajib** (alasan shared hosting biasanya
tetap tidak cukup).

Tiga hal yang harus diukur selama uji coba Space, karena menentukan apakah batas
waktu sisi web perlu diubah:

1. **Waktu bangun** Space yang tertidur, versus batas `health()` 10 detik.
2. **Lama satu putaran pelatihan** di CPU Space, versus `RetrainModel` 7200 detik.
3. **Apakah bobot hasil pelatihan bertahan** setelah Space dimulai ulang. Disk
   Space tidak permanen; bila `*_retrained` kembali `false`, riwayat pelatihan
   mencatat "selesai" padahal model sudah kembali ke bobot dasar.

Tes: **478**. Yang baru: satu di `DeployCheckTest` (kunci ditolak walau
`/health` hijau).

## Lambang aplikasi & gambar halaman depan (23 Sep 2026)

**Lambang**: balon percakapan putih berisi tiga batang naik di atas ubin
bergradasi biru (#2563EB -> #06B6D4). Balon = teks opini, batang = hasil ukur,
tiga warna batang = tiga kelas sentimen (rose/amber/emerald) yang dipakai di
seluruh aplikasi. Satu komponen `<x-logo-mark>` (varian `solid` / `terang` untuk
latar yang sudah bergradasi) menggantikan kotak teks "AT" di halaman depan,
navigasi, layout tamu, pedoman, dan halaman galat; `application-logo` Breeze
diarahkan ke sana. Id gradasinya diacak per pemanggilan - dua lambang dengan id
yang sama pada satu halaman membuat salah satunya kehilangan warna (ada tesnya).

Ikon: `public/favicon.svg` (sumber utama), `favicon.ico` dan
`apple-touch-icon.png` digambar ulang dengan Pillow dari geometri yang sama,
`public/img/logo-512.png` untuk keperluan lain. Favicon emoji robot dibuang.

**Gambar pahlawan** halaman depan dulu foto stok Unsplash yang tampil kosong.
Diganti pratinjau halaman hasil yang digambar langsung dengan HTML/SVG (donat
sentimen, batang per aspek, dua kartu prediksi), berlabel **"Contoh"** supaya
angkanya tidak terbaca sebagai hasil pengukuran.

**Animasi angka** di bawah tombol memotong format Indonesia: `parseInt` atas
digit saja membuat 85,7% beranimasi menjadi **857%** dan 8.393 menjadi 8393.
Kini formatnya dibaca, animasi memakai `toLocaleString('id-ID')` dengan jumlah
desimal yang sama, dan teks asli dipasang kembali di akhir. Klaim "dalam
hitungan detik" ikut dihapus (korpus ribuan teks memakan menit).

Tes: **481**. Yang baru: `BrandingTest` (3).

## Angka halaman publik mengikuti keadaan terakhir (23 Sep 2026)

`App\Services\PublicStatsService::snapshot()` menggantikan angka yang ditulis
tangan di `LandingController` dan `layouts/guest.blade.php`. Sumbernya:
evaluation run terakhir yang sah (`EvaluationRun::completed()`, jadi run batal
tidak pernah tampil) untuk akurasi, confidence interval, ukuran test set, dan
micro F1 aspek; run pertama pada dataset yang sama sebagai pembanding "naik
dari"; nomor iterasi run terakhir; jumlah `training_items` terkoreksi; jumlah
`total_records` analisis yang selesai. Cache 60 detik.

Setiap angka boleh null, dan kartu yang null **tidak dirender** - termasuk
seluruh bagian "Hasil Uji" beserta tautan navigasinya bila belum ada evaluasi.
Kegagalan basis data (tabel belum dimigrasi, MySQL mati) ditangkap dan halaman
tetap 200 tanpa angka; kegagalan tidak di-cache.

Catatan: kartu iterasi kini menampilkan **6** (iterasi active learning setelah
baseline), bukan 7 (jumlah titik pengukuran) seperti versi tulis tangan.

Tidak ada lagi sebutan "studi kasus" di antarmuka; data yang dulu disebut begitu
diperlakukan sebagai pemakaian nyata aplikasi. Istilah teknis yang lazim dalam
bahasa Inggris tidak diterjemahkan lagi: active learning, test set, data
training, confidence score, confidence interval, base model, preprocessing,
stopword, stemming, case folding, topic modeling, word cloud, F1-score, data
leakage, screenshot, error (halaman galat), upload.

Tes: **483**. `BrandingTest` bertambah dua (angka mengikuti run terakhir dan
menyingkirkan run batal; tanpa evaluasi bagian Hasil Uji tidak tampil).

## Halaman depan diringankan (23 Sep 2026)

Sumber beratnya di peramban, bukan di server (TTFB setara `/login`):

1. **Tiga blob 300-400px dengan `filter: blur(70px)`** yang dianimasikan terus
   dan digeser lagi di setiap event scroll (parallax). Blur sebesar itu
   digambar ulang tiap frame. Diganti satu `.hero-glow` berisi tiga
   `radial-gradient` statis: tampak hampir sama, tanpa biaya setelah render.
2. **AOS dari unpkg**: stylesheet yang memblokir render dari server luar plus
   skripnya. Diganti skrip kecil berbasis IntersectionObserver yang membaca
   atribut `data-aos`/`data-aos-delay` yang sama. Status tersembunyinya hanya
   berlaku di bawah kelas `.js` pada `<html>`, jadi tanpa JavaScript isi tetap
   terlihat.
3. **`app.js` (Alpine, WordCloud, axios; 92 KB)** tidak dipakai halaman ini dan
   kini tidak dimuat; `@vite` hanya memuat CSS.
4. **Empat listener scroll** (satu membaca `offsetTop` setiap section di tiap
   event) menjadi satu listener pasif yang di-throttle `requestAnimationFrame`;
   penanda menu aktif pindah ke IntersectionObserver.
5. `backdrop-filter` pada navbar dibuang (tak terlihat pada latar 98% buram),
   kartu besar hero tidak lagi melayang (hanya dua kartu kecil), bobot font
   Inter 900 yang tidak dipakai dibuang, CSS mati (`glass-effect`,
   `pulse-ring`, `gradient-bg-2/3`) dan console easter egg dihapus.
6. `prefers-reduced-motion` dihormati: animasi melayang, muncul, dan angka
   berjalan dimatikan.

`BrandingTest` menjaga agar unpkg, `app.js`, dan blob ber-blur tidak kembali.
Tes: **484**.

## Hosting satu VPS & antrean pelatihan terpisah (23 Sep 2026)

Disepakati bersama sesi `nlp-api-service` lewat
`../nlp-api-service/docs/TITIPAN_HOSTING_SATU_VPS.md` dan
`JAWABAN_HOSTING_SATU_VPS.md`: **satu VPS** (4 vCPU / 8 GB, Ubuntu 24.04,
swap 4 GB) menjalankan web dan layanan NLP; layanan di 127.0.0.1:8001 lewat
systemd + venv, torch versi CPU. `DEPLOYMENT.md` ditulis ulang untuk itu
(bagian 0, 1, 7, 8, 11, daftar periksa); varian HF Spaces dibuang karena HF
tidak lagi menyediakan Space Docker gratis.

### 1. Timeout HTTP pelatihan dipisah (dulu 1200 dtk)

Terukur di CPU (Ryzen 7 5700U): 1,68 dtk/sampel sentimen, 1,06 aspek; VPS
diperkirakan 2-3x lebih lambat, jadi 41-61 menit per putaran. Klien yang
menyerah **tidak menghentikan** pelatihan di layanan (terukur dengan uvicorn
sungguhan): checkpoint tersimpan sementara `model_trainings` mencatat `failed`.
Rantainya kini `services.nlp_api.retrain_timeout` 6600 < `RetrainModel::$timeout`
7200 < retry_after koneksi `training` 7500.

### 2. Antrean `training` dengan worker sendiri

`RetrainModel` masuk koneksi `config('queue.training_connection')` (bawaan
`training`, tabel `jobs` yang sama, antrean `training`, retry_after sendiri).
`phpunit.xml` menyetelnya `sync`. `composer dev` kini menjalankan listener
kelima (`queue:listen training --queue=training`). **Worker `composer dev` yang
sudah berjalan harus dimulai ulang**, atau pelatihan lokal tidak pernah diambil.
`deploy.sh` sengaja hanya me-restart paksa worker analisis; worker pelatihan
berhenti sendiri lewat `queue:restart` setelah job-nya selesai.

### 3. 503 + `Retry-After` saat model sedang dilatih

Layanan (saklar `RETRAIN_REJECT_INFERENCE`, mati secara bawaan) menolak analisis
yang butuh model yang sedang dilatih. Sisi web:

- `NLPApiService::kirimAnalisis()` - satu jalur untuk keempat endpoint analisis.
  503 **dengan** `Retry-After` tidak diulang HTTP-nya dan menjadi
  `NlpBusyException`; 503 **tanpa** header itu tetap galat biasa.
- Kedua loop batch melempar ulang `NlpBusyException` alih-alih mencatatnya
  sebagai batch gagal. Tanpa itu analisis selesai "sukses" dengan teks hilang.
- `ProcessTextAnalysis`: `$tries = 3` diganti `retryUntil()` 8 jam +
  `$maxExceptions = 3` + `$failOnTimeout = true`. Jatah galat sungguhan
  (`PERCOBAAN = 3`) dihitung `percobaanNyata()` = `attempts()` dikurangi
  jumlah pelepasan karena menunggu (di cache). Laravel menghitung setiap
  release sebagai percobaan, jadi tanpa ini menunggu satu pelatihan saja
  menghabiskan jatahnya.

Urutan pemasangan: versi web ini dulu, **baru** `RETRAIN_REJECT_INFERENCE=true`.

### 4. Temuan: contoh `.env` produksi berisi `NLP_API_TIMEOUT=3600`

Sama dengan batas job, melanggar rantai "HTTP < job". Tes tidak membaca berkas
itu, jadi `deploy:check` kini memeriksa rantai analisis dan rantai pelatihan
secara eksplisit.

### 5. Cadangan

`backup-db.sh` (mysqldump `--single-transaction`, gzip, 14 hari, `RCLONE_REMOTE`
untuk salinan di luar server), dijadwalkan cron. Basis data adalah satu-satunya
data yang tak tergantikan; bobot bisa dibangkitkan ulang dari kolam koreksi.

Tes: **495**. Yang baru: `NlpBusyRetryTest` (8), satu di
`QueueTimeoutConfigTest`, dua di `DeployCheckTest`.

## Evaluasi otomatis, F1 aspek parsial, dan server Rumahweb (24 Sep 2026)

### Evaluasi hold-out kini OTOMATIS (keputusan pemilik proyek)

Membalik keputusan 4 Sep ("evaluasi sepenuhnya manual"). `RetrainModel::handle()`
memanggil `AutoEvaluationService::setelahPelatihan()` di blok `finally`, jadi
berjalan apa pun status job-nya. Evaluasi diantrekan hanya bila:

1. `ANALYSIS_AUTO_EVALUATE` menyala (bawaan true);
2. **tidak ada pelatihan lain yang pending/running** - satu putaran = job
   sentimen + job aspek, dan mengukur setelah job pertama berarti mengukur
   campuran bobot baru dan lama;
3. ada test set beku (`EvaluationDataset::ujiAktif()`: split `test`, beku,
   berisi; yang terakhir dipakai run didahulukan);
4. ada pelatihan `completed` yang belum diukur (bila kedua modul `rejected`,
   bobot tidak berubah dan tidak ada yang perlu diukur);
5. belum ada run pending/running pada dataset itu.

Run tidak diberi `training_id`: job pemicunya bisa saja yang gagal/ditolak, dan
`HoldoutEvaluationService` sudah menautkan run ke pelatihan terakhir yang selesai.

**Penjaga "belum diukur" kini hanya berlaku bila ada test set beku.** Tanpa itu
pemasangan tanpa golden dataset berhenti selamanya setelah putaran pertama:
penjaga menunggu evaluasi yang tidak akan pernah dijalankan.

Tes: `AutoEvaluationTest` (7). Tes lama `test_pelatihan_ulang_tidak_memicu_evaluasi`
dihapus; helper `IterationSequenceTest::pelatihanSelesai()` kini memastikan ada
test set beku.

Catatan Pint: `php_unit_method_casing` mengganti nama method tes yang diawali
`test` - termasuk helper privat seperti `testSetBeku()` - menjadi `test_set_beku()`
dan memutus pemanggilnya. Jangan awali nama helper tes dengan `test`.

### F1 ekstraksi aspek: parsial adalah angka utama

`metrics.aspect.partial.micro_f1` (iterasi 6: 80,06%) menggantikan micro-F1 ketat
(74,38%) sebagai angka utama di halaman depan, panel login, halaman evaluasi
(baris pertama, lencana "utama"), `evaluation:run`, dan `evaluation:report`.
Ketat tetap ditampilkan sebagai **batas bawah**. `PublicStatsService` jatuh ke
angka ketat untuk run lama tanpa blok `partial`. Kurva perbandingan mendapat
`aspect_partial_f1`.

### Halaman hasil: bagian prediksi dirampingkan

Tab "Perlu Ditinjau (N)", catatan antrean tinjauan ("jangan memakainya untuk
mengukur akurasi"), dan "Catatan mutu data" dihapus dari `show.blade.php`.
Ajakan mengoreksi baris yang ragu tetap ada di panel keyakinan. Data
`review_queue` dan `data-review-rank` pada kartu tidak disentuh.

### DEPLOYMENT.md untuk server sungguhan

Server: Rumahweb VPS XL (4 vCPU Xeon Gold 6138, 7,8 GB, 158 GB), domain
`text-analyze.ing.biz.id` (subdomain, tanpa `www`). Temuan sesi layanan saat
memasang, kini masuk panduan:

- **Mirror `cermin.rumahweb.id` tidak menyajikan `noble-updates`/`-security`.**
  `apt upgrade` melaporkan "0 upgraded" tanpa galat sementara `openssh-server`
  tertahan di versi rentan CVE-2024-6387. Langkah 0 di §0b memeriksa
  `apt-cache policy openssh-server` dan mengganti sumber apt.
- `apt update` sebelum memasang ufw (image baru: "no installation candidate").
- Swap bawaan 255 MB diganti 4 GB.
- nginx `listen [::]:80` karena server ber-IPv6: dengan record AAAA, Let's
  Encrypt memvalidasi lewat IPv6 lebih dulu.
- Daftar periksa: sudo tanpa kata sandi sementara harus dicabut.

Tes: **501**.

### Timeout topik & tidak mengulang permintaan yang kehabisan waktu (24 Sep 2026)

Terukur di VPS produksi: 3.366 komentar = sentimen 3,6 mnt + aspek 9,5 mnt +
topik 16,2 mnt (972 dtk). `analyzeTopic` dulu memakai `timeout * 2` = 1200 dtk,
sisa ~19%. Kini `services.nlp_api.topic_timeout` (`NLP_API_TOPIC_TIMEOUT`,
2400), dijaga `QueueTimeoutConfigTest` dan `deploy:check`.

`kirimAnalisis()` **tidak lagi mengulang HTTP yang kehabisan waktu**
(`NLPApiService::kehabisanWaktu()`: ConnectionException dengan cURL 28/"timed
out"). Layanan tidak membatalkan handler saat klien memutus, jadi mengulang
berarti dua pemodelan topik serentak di 3 vCPU. Koneksi ditolak (cURL 7) tetap
diulang. Di tingkat job, `ProcessTextAnalysis` kini langsung menandai gagal
dengan pesan "tidak selesai dalam batas waktu... pecah berkasnya" alih-alih
melepas ulang dengan backoff, karena alasan yang sama.

Catatan tes: permintaan yang callback `Http::fake`-nya MELEMPAR tidak tercatat
oleh `Http::assertSentCount`; hitung di dalam callback.

Tes: **505**.

## Mode tunda pelatihan untuk fase evaluasi kuesioner (24 Sep 2026)

Permintaan pemilik proyek: selama uji coba CSUQ pengguna nyata langsung
mengoreksi hasilnya, tetapi golden dataset domain baru baru bisa diambil
setelah semua pengguna selesai. Pelatihan otomatis karena itu DITUNDA dan
pemicunya dicatat, lalu diputar ulang setelah golden dataset siap.

### Alur

1. `/admin/training` -> "Nyalakan mode tunda" (atau `retrain:tunda nyalakan`).
   Disimpan di tabel baru `app_settings` (kunci `retrain.tunda`), bukan .env,
   supaya berganti fase tidak menuntut SSH. Bawaan awal: `ANALYSIS_RETRAIN_DEFER`.
2. Selama tunda, `AutoRetrainService::run()` - SETELAH lolos ambang dan jeda -
   memanggil `RetrainDeferralService::catat()`: satu baris `retrain_deferrals`
   (`cutoff_at`, koreksi baru, besar kolam, analisis asal). Tidak ada pesan ke
   pengguna (`blocking` false). Tombol admin dan `retrain:analysis` juga
   ditolak kecuali "paksa".
3. Ambil golden dataset dari analisis pengguna, anotasi, bekukan, lalu jalankan
   evaluasi baseline (iterasi 0) pada dataset itu.
4. Matikan tunda, lalu "Putar ulang N pemicu" (`retrain:tunda putar-ulang`).
   `RetrainDeferralService::lanjutkan()` menjalankan pemicu tertua lewat
   `RetrainingService::dispatch(..., $batas = cutoff_at)`; evaluasi otomatis
   mengukurnya; `RunHoldoutEvaluation` (atau `AutoEvaluationService` bila bobot
   tidak berubah) memanggil `lanjutkan()` lagi untuk pemicu berikutnya.

### Keputusan yang penting

- **Kolam dipotong pada `cutoff_at`** (`correctedItemsSampai()`). Protokol
  kolam melatih dari bobot dasar dengan seed tetap atas seluruh koreksi; tanpa
  potongan, N putaran atas kolam akhir yang sama menghasilkan model identik N
  kali. `correctedItems()` tetap tanpa argumen seperti keputusan 10 Sep;
  potongan waktu adalah method terpisah yang hanya dipakai pemutaran ulang.
- **Titik hitung koreksi baru dan jeda** kini pelatihan terakhir ATAU pemicu
  tertunda terakhir (`titikTerakhir()`). Tanpa itu, selama tunda setiap koreksi
  sesudah ambang pertama tercatat sebagai pemicu baru.
- Pemicu yang kolamnya di bawah `MIN_LAYAK` pada titik itu **dilewati** (bila
  berjalan langsung pun ia ditolak), lalu pemicu berikutnya dicoba.
- Selama pemutaran ulang, pemicu langsung diam, supaya kurva rekonstruksinya
  tidak bercampur putaran yang tidak pernah ditunda.
- Syarat mulai: tunda mati, ada yang tertunda, tidak ada pelatihan berjalan,
  evaluasi otomatis menyala, ada test set beku, dan test set itu sudah diukur
  sejak dibekukan.

### Pengambilan sampel golden: koreksi yang belum dilatih boleh terambil

Aturan lama mengecualikan SEMUA baris yang pernah dikoreksi. Kini hanya yang
koreksinya **sudah dilatihkan** (terverifikasi sebelum pelatihan terakhir
dibuat, atau tanpa `verified_at`). Koreksi fase kuesioner belum pernah
dilatihkan, jadi mengecualikannya tidak mencegah kebocoran apa pun, dan justru
membuat sampel tidak acak: ajakan koreksi mendahulukan baris yang paling ragu,
sehingga test set kehilangan baris tersulit. Setelah dibekukan,
`correctedItems()` menyingkirkannya dari pelatihan. Harganya dicatat di
`sampling_plan.koreksi_belum_dilatih_terambil`: koreksi pengguna yang tidak akan
pernah dilatihkan.

### Lain-lain

- `.env` di server berizin 640 milik www-data; `backup-db.sh` kini keluar dengan
  pesan jelas bila tidak bisa membacanya (jalankan dengan sudo / crontab root).
  DEPLOYMENT.md bagian 5 memuat izin itu.
- Butuh `php artisan migrate` di server (tabel `app_settings`,
  `retrain_deferrals`).

Tes: **517**. Yang baru: `RetrainDeferralTest` (11), satu di `GoldenSamplingTest`.

### Perbaikan setelah pemasangan 24 Sep 17.15 (laporan sesi layanan)

- `build-release.sh` mengemas `storage/app/golden-gabungan.csv` karena hanya
  mengecualikan subfolder tertentu. Kini `--exclude='./storage/app/*'`
  (diverifikasi dengan `tar -t`: yang tersisa hanya folder kosong dan
  `.gitignore`), dan `deploy.sh` membuat ulang folder storage yang dibutuhkan
  dengan `mkdir -p` sebelum `storage:link`.
- `PanduanTest::test_tangkapan_layar_yang_belum_ada...` mengandaikan
  `01-dashboard.png` belum ada dan gagal begitu pemilik proyek menaruhnya. Kini
  merender komponen `<x-panduan-gambar>` dengan nama yang tidak akan pernah ada.
- Sesi layanan menambah subbagian "Header keamanan" di DEPLOYMENT.md bagian 6
  (HSTS tanpa includeSubDomains, nosniff, X-Frame-Options SAMEORIGIN,
  Referrer-Policy, Permissions-Policy, server_tokens off).

## Video panduan di /panduan (24 Sep 2026)

`public/video/Pedoman.mp4` (66 MB, H.264 + AAC, 2:10) tampil sebagai bagian
"Tonton dulu: video panduan" di atas bagian 1, dengan tautan tak bernomor di
atas daftar isi (nomor daftar isi harus tetap sama dengan nomor di judul
bagian). `PanduanController::video()` mengembalikan null bila berkas tidak ada,
dan bagiannya tidak dirender - pemutar kosong lebih membingungkan daripada tidak
ada video. URL membawa `?v=<filemtime>` supaya video yang diganti tidak tertahan
cache. Durasinya dibaca dari atom `mvhd` (`durasiMp4()`, 1 MB pertama saja).

**Berkas aslinya tidak faststart**: atom `moov` (indeks) ada di UJUNG, setelah
66 MB data, sehingga peramban harus melompat ke akhir berkas sebelum bisa
memutar. Disusun ulang tanpa re-encode (skrip Python: moov dipindah sebelum
mdat, 7.797 offset `stco` digeser 89.255 byte), lalu diverifikasi: setiap
potongan menunjuk 64 byte yang identik dengan aslinya dan blok mdat identik
byte demi byte. Tanpa ffmpeg di mesin ini, itu satu-satunya jalan.

`preload="metadata"` hanya mengunduh indeks saat halaman dibuka. Ada tautan
unduhan cadangan, dan versi cetak menampilkan alamat video sebagai ganti pemutar.
Sampul opsional: `public/img/panduan/video-poster.jpg`.

Catatan: `public/hot` sempat tertinggal lagi (Vite mati) sehingga SELURUH
halaman lokal tanpa gaya; dihapus dan aset dibangun ulang.

Tes: **519**. Yang baru: dua di `PanduanTest` (bagian video, pembaca `mvhd`
versi 0 dan 1).

## Galat "Model AI gemini-1.5-pro tidak tersedia" (24 Sep 2026)

Keluhan pengguna nyata pada Ringkasan Eksekutif AI. Penyebabnya dua lapis:

1. `.env` (lokal dan server, karena `.env` server dibangun dari lokal) berisi
   `GEMINI_MODEL=gemini-3.5-flash,gemini-1.5-flash,gemini-1.5-pro`. Kedua
   cadangan sudah **pensiun** (404 "no longer available to new users"; begitu
   juga `gemini-2.5-*`, walau masih tercantum di `ListModels`).
2. `LlmService::generate()` melaporkan galat model **terakhir** yang dicoba.
   Model utama gagal sesaat (sibuk/timeout), cadangannya 404, dan pengguna
   melihat "model tidak tersedia" - bukan penyebab sebenarnya.

Perbaikan (berlaku untuk SEMUA pemanggilan AI; seluruhnya lewat `generate()`):

- Rantai baru `gemini-3.5-flash,gemini-3.6-flash`, diuji langsung dengan kunci
  proyek (200 keduanya; `3.5-flash-lite` 200 tetapi 41 detik, terlalu dekat
  batas 45). Bawaan config ikut diubah; tetap dipatok, bukan `-latest`.
- `pesanKegagalan()` memilih penyebab paling berguna dari SEMUA kandidat: kuota
  (429) > sibuk/tak terjangkau (5xx, koneksi) > ditolak (400/403) > "model tidak
  tersedia" hanya bila seluruh kandidat 404. Model 404 dicatat di log dengan
  saran menghapusnya dari `GEMINI_MODEL`.
- **Kunci API kini lewat header `x-goog-api-key`, bukan `?key=` di URL.** Pesan
  galat koneksi Laravel memuat URL lengkap, sehingga kunci ikut tertulis ke
  `storage/logs`. Log lokal memuat 3 baris seperti itu; server kemungkinan juga.
- `deploy:check` mendapat bagian "AI (Gemini)": `LlmService::periksaModel()`
  mengirim permintaan terkecil ke tiap model di `GEMINI_MODEL` (peringatan, bukan
  kegagalan - aplikasi tetap utuh tanpa AI). `ListModels` saja tidak cukup
  sebagai bukti, lihat butir 1.

Tes: **522**. Yang baru: tiga di `LlmServiceTest`.

### Worker tertahan 11 menit saat deploy (24 Sep 2026)

Dari supervisord.log server (dibaca sesi layanan): `queue:restart` membuat worker
pelatihan yang menganggur keluar (18:26:49,617), Supervisor langsung memunculkan
proses baru (49,738), dan SIGTERM dari `supervisorctl restart all` tiba sekitar
25 ms kemudian, saat proses itu masih bootstrap. Sinyalnya hilang sebelum
Laravel memasang penangannya, Supervisor menunggu `stopwaitsecs`, dan situs
tetap dalam mode pemeliharaan sampai proses itu di-SIGKILL 11 menit kemudian.
pcntl terpasang; pada 17:14 dengan jarak 0,3 detik proses yang sama berhenti
normal.

Kondisi pacu yang sama berlaku untuk worker analisis di `deploy.sh`
(`queue:restart` lalu `supervisorctl restart 'analisisteks-worker:*'`). Kini
`deploy.sh` hanya mengirim `queue:restart`: tiap worker menyelesaikan job-nya,
keluar, dan Supervisor menghidupkannya lagi dengan kode baru. Buktinya bukan
"RUNNING" (proses lama juga RUNNING) melainkan **PID yang berganti**: PID
dicatat sebelum `queue:restart`, lalu ditunggu sampai 45 detik (worker pelatihan
memakai `--sleep=10`). Worker yang sedang mengerjakan job boleh mempertahankan
PID lamanya dan hanya dilaporkan sebagai CATATAN, karena deploy.sh tidak
menolak berjalan saat ada analisis. Setiap pembacaan PID memakai `|| true`
karena skripnya `set -euo pipefail`. Kedua jalur diuji dengan supervisorctl
palsu. Gagasan menunggu PID dari sesi layanan. DEPLOYMENT.md bagian 7 memperingatkan agar
`supervisorctl restart` tidak disusulkan setelah `queue:restart`.

## Aturan pengambilan golden 24 Sep dipertahankan (26 Sep 2026)

Pemilik proyek sempat meminta golden sampling mengecualikan SEMUA baris
terkoreksi, lalu membatalkannya setelah meninjau ulang. Aturan 24 Sep tetap
berlaku: yang dikecualikan hanya koreksi yang **sudah dilatihkan**
(`hashSudahDikoreksi()`, `verified_at` <= pelatihan terakhir); koreksi yang
belum dilatihkan boleh terambil dan jumlahnya dicatat di
`sampling_plan.koreksi_belum_dilatih_terambil`.

Alasan pemilik: supaya baris berkeyakinan rendah tetap ikut diuji. Mengecualikan
semua koreksi membuat test set kehilangan baris tersulit, karena ajakan koreksi
mendahulukan baris yang paling ragu. Penjaga yang dibutuhkan hanya satu: data
yang sudah dipakai untuk retraining tidak boleh masuk test set. Koreksi yang
terambil golden disingkirkan dari pelatihan sesudahnya oleh `frozenHashes()`.

Jangan usulkan pembalikan ini lagi tanpa alasan baru.

## Tebakan aspek model di aplikasi anotasi (26 Sep 2026)

Berkas anotasi "+ Prediksi Model" kini punya kolom ketiga `model_aspects`, diisi
`GoldenSamplingService::modelAspects()` dari `document_aspects` analisis sumber
(dikunci indeks `raw_data`, dicocokkan lewat sidik jari teks, tanpa panggilan
baru ke layanan). Tiga keadaan seperti kolom `aspects`: daftar dipisah `;`,
`-` = model memeriksa dan tidak menemukan aspek, kosong = tidak ada data
(analisis sentimen saja). Bentuk permukaan, bukan stem, karena dibaca manusia.
**Batasan (diluruskan 27 Sep):** baris dari batch aspek yang gagal TIDAK
kosong melainkan `-`, karena jalur batch mengisinya `[]` dan kegagalannya
tidak tersimpan pada hasil analisis.

`public/anotasi/index.html`: tebakan aspek **tidak** terbuka otomatis seperti
tebakan sentimen. Sentimen diputuskan satu klik, aspek ditambahkan satu per
satu - membukanya begitu aspek pertama ada akan menjangkarkan aspek
berikutnya. Tombol "Tampilkan tebakan aspek model" baru muncul setelah aspek
anotator tertetapkan (termasuk "Tidak ada aspek") atau baris ditandai tidak
jelas. Chip hijau = sama, kuning = hanya model. Perubahan aspek setelah tebakan
dibuka dicatat di kolom ekspor `aspects_changed_after_reveal`, terpisah dari
`changed_after_reveal`; kolom itu hanya ditambahkan bila berkasnya memuat
`model_aspects`, jadi berkas lama diekspor seperti dulu.

Impor mengabaikan kolom tambahan karena `readAnnotationFile` mencari kolom
lewat nama persis; `model_aspects` tidak pernah tertukar dengan `aspects`
(ada tesnya). Skenario aplikasi diuji lewat Edge headless (`--dump-dom`) pada
salinan halaman: 24 pemeriksaan, termasuk berkas lama.

Tes: **524**.

## Evaluasi menolak hasil parsial, run yatim, dan pemicu yang terlewat (27 Sep 2026)

Dari sesi `nlp-api-service`, terukur di server 26 Sep: setelah pelatihan
iterasi 2, `RunHoldoutEvaluation` berjalan 31 menit, dua batch aspek kehabisan
waktu (cURL 28, 600 dtk), job dibunuh, dan run-nya tertinggal `running`.
Akar lambatnya (memori layanan tidak dilepas setelah pelatihan) diperbaiki di
sisi layanan.

### Run yatim

`RunHoldoutEvaluation::failed()` menandai run pada dataset itu yang dibuat sejak
job diantrekan (`$diantrekanPada`, dibulatkan ke detik) menjadi `failed`. Blok
catch di `HoldoutEvaluationService::run()` tidak pernah berjalan bila job
dibunuh dari luar. `deploy:check` memeriksa rantai HTTP analisis < job evaluasi
< retry_after (peringatan, bukan kegagalan).

### Evaluasi tidak boleh selesai dengan batch bolong

Analisis pengguna tetap memaafkan batch yang gagal (`failed_batches`). Evaluasi
tidak: `NLPApiService::utuh()` mengembalikan salinan yang melempar pada batch
PERTAMA yang gagal. Selain metriknya aman, itu membebaskan worker bawaan yang
dipakai bersama analisis pengguna, alih-alih menunggu sisa batch dari pekerjaan
yang toh ditolak. `HoldoutEvaluationService` menjaga lapis kedua: menolak
`failed_batches`, baris tanpa prediksi, baris `method: error` (layanan
mengisinya `neutral` keyakinan 0 - bukan tebakan model), dan
`document_aspects` yang tidak mencakup setiap baris.

### Pemicu yang terlewat

1. **Batas kolam, bukan waktu pelatihan.** `newCorrectionCount()` kini dihitung
   dari `RetrainDeferralService::batasKolamTerakhir()`: `cutoff_at` pemicu yang
   diputar ulang (dibaca lewat `retrain_deferrals.training_ids`), `created_at`
   untuk pelatihan langsung, dan `cutoff_at` pemicu tertunda. Dulu dari waktu
   pelatihan dibuat, sehingga pelatihan hasil pemutaran ulang (26 Sep) menelan
   36 koreksi 24 Sep yang tidak pernah dilatih. Jeda tetap memakai
   `titikTerakhir()` (waktu sebenarnya).
2. **`analysis:auto-retrain` dijadwalkan tiap jam** di `routes/console.php`,
   membalik keputusan 3 Sep. Penyebabnya nyata: koreksi yang tertahan jeda tidak
   pernah dievaluasi ulang bila tidak ada yang menyimpan lagi. Butuh cron
   `* * * * * php artisan schedule:run` sebagai www-data; tanpanya perilakunya
   sama seperti dulu.

Catatan tes: `schedule:run` menjalankan perintah terjadwal sebagai PROSES
terpisah terhadap basis data sungguhan, jadi tes memeriksa pendaftaran jadwal
dan perilaku perintahnya secara terpisah.

Diverifikasi: 8 dari 12 tes baru gagal pada kode sebelum perbaikan (4 sisanya
penjaga regresi).

Tes: **536**. Yang baru: `EvaluationRobustnessTest` (8), dua di
`RetrainDeferralTest`, dua di `AutoRetrainTest`.

## Angka publik = angka model yang sedang dipakai (27 Sep 2026)

Permintaan pemilik proyek. 27 Sep bobot aspek dikembalikan ke iterasi 1 (#17)
karena iterasi 2 (#19) lebih buruk secara signifikan, sementara sentimen tetap
iterasi 2 (#18). Halaman depan membaca run terakhir (#14), jadi F1 aspeknya
(78,77) milik bobot yang sudah diarsipkan.

`App\Services\ActiveModelService` menjadi satu-satunya penjawab "bobot mana yang
aktif, dan run mana yang mengukurnya":

- Bobot aktif per modul dari `/health` -> `models.<modul>_pool_fingerprint`
  (timeout 3 dtk, cache 5 menit, bacaan terakhir disimpan selamanya sebagai
  cadangan saat layanan mati). Kunci ada bernilai null = bobot dasar; kunci
  tidak ada sama sekali (layanan versi lama) = tebakan "pelatihan tersimpan
  terakhir", ditandai `pasti: false`.
- Bobot dibandingkan lewat KUNCI (`dasar` / `sj:<sidik>` / `latih:<id>` untuk
  pelatihan lama tanpa sidik jari), bukan id pelatihan: dua pelatihan atas kolam
  yang sama menghasilkan bobot yang sama.
- Bobot sebuah run: `model_info.<modul>_pool_fingerprint` (kini disimpan
  `modelInfo()` bersama `*_pool_samples` dan `aspect_restored_from`, hanya bila
  layanan menyebutnya). Run lama tanpa itu: pelatihan `completed` modul itu
  yang selesai sebelum run dimulai. Benar untuk run 10, 11, 14 di server karena
  pemulihan terjadi setelahnya.

`PublicStatsService`: akurasi dari run terbaru yang mengukur sentimen aktif, F1
aspek dari run terbaru yang mengukur aspek aktif (boleh run berbeda; ekstraksi
aspek tidak memakai model sentimen). **Bobot aktif yang belum diukur tidak
menampilkan angka apa pun** - angka run lain tidak boleh tampil sebagai angka
model aktif. Kalimat "dasar" di halaman depan menyebut iterasi asal tiap angka;
`iterations` = iterasi tertinggi yang terukur pada dataset itu, bukan nomor run
terakhir. Dataset: `ujiAktif()`, atau dataset run terakhir bila belum ada yang
beku.

Admin: lencana "Aktif" pada pelatihan di `/admin/training` ("Aktif?" bila
tebakan) dan "sentimen aktif"/"aspek aktif" pada run di halaman dataset
evaluasi. `RetrainModel` mengosongkan cache bobot aktif setelah tiap pelatihan.

Urutan pemasangan: layanan NLP dulu (yang melaporkan sidik jari), baru web.
Tanpa layanan baru, web jatuh ke tebakan lama dan tidak rusak.

Diverifikasi: kedelapan tes baru gagal pada kode sebelum perubahan. Dua tes
"layanan tidak terjangkau" gagal di sana hanya karena asersinya memakai kelas
baru; bagian "halaman tetap terbuka"-nya penjaga regresi.

Tes: **544**. Yang baru: `ActiveModelStatsTest` (8).

