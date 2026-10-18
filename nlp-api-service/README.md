# NLP Analysis API

Layanan FastAPI untuk analisis teks berbahasa Indonesia: klasifikasi sentimen,
ekstraksi aspek, pemodelan topik, dan asosiasi aspek–topik. Menjadi backend NLP
bagi aplikasi web Laravel di akar repositori ini, yang memanggilnya lewat HTTP.

Bagian dari repositori skripsi Iman Alifa Novansyah (Politeknik Statistika
STIS, 2026). Identitas, arsitektur keseluruhan, dan struktur repositori ada di
[README utama](../README.md); penjelasan teknis lengkap di
[docs/DOKUMENTASI_SISTEM.md](../docs/DOKUMENTASI_SISTEM.md).

## Menjalankan secara lokal

```bash
cd nlp-api-service
python -m venv venv
source venv/bin/activate      # Windows: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env          # ubah APP_ENV=development agar API_KEY boleh kosong
python run.py
curl http://localhost:8001/health
```

Tes (`pip install -r requirements-dev.txt` lebih dulu):

```bash
python -m pytest tests/ -q
```

Suite tes sengaja tidak memuat bobot model, jadi ia berjalan tanpa jaringan.

## Deployment

**Dua hal wajib sebelum URL dibagikan ke publik:**

1. **`API_KEY` diisi.** Layanan menolak start bila `APP_ENV=production` dan
   kunci ini kosong. Tanpa kunci, `/api/retrain/*` terbuka untuk siapa pun yang
   tahu URL-nya — dan endpoint itu menimpa bobot model.
2. **`MODEL_PATH` menunjuk ke penyimpanan permanen**, atau `HF_WEIGHTS_REPO`
   diisi. `models/` tidak ikut image; tanpa salah satunya, hasil retraining
   hilang pada redeploy berikutnya dan layanan diam-diam kembali ke bobot
   publik.

Langkah pemasangan lengkap (systemd + venv di satu VPS bersama aplikasi web)
ada di [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) bagian 8. `Dockerfile`
disediakan sebagai alternatif.

### Yang wajib diperhatikan

- **`--workers 1` bersifat arsitektural.** Keadaan model — termasuk bobot hasil
  retraining — hidup di global tingkat proses. Dua worker berarti dua salinan
  bobot dan keadaan yang menyimpang setelah retraining. Unit systemd dan
  `Dockerfile` keduanya memakukannya.
- **RAM:** ~2,5 GB ketika seluruh bobot termuat, **~4 GB puncak** saat
  retraining (gradien + state optimizer).
- **Bobot dimuat secara malas.** Permintaan pertama tiap jenis membayar biaya
  muat model. Panggil `POST /api/warmup` setelah boot.

## Endpoint

| metode | jalur | kunci API |
|---|---|---|
| GET | `/`, `/health` | tidak |
| POST | `/api/warmup` | ya |
| POST | `/api/preprocess` | ya |
| POST | `/api/normalize/aspects` | ya |
| POST | `/api/analyze/{sentiment,aspect,topic,combined,association}` | ya |
| POST | `/api/retrain/{sentiment,aspect,preview}` | ya |

`/health` melaporkan bobot mana yang sedang hidup (`sentiment_retrained`,
`aspect_retrained`) dan suhu kalibrasi yang berlaku.

## Lisensi model dasar

Bobot layanan ini diturunkan dari `indobenchmark/indobert-base-p1` dan
`crypter70/IndoBERT-Sentiment-Analysis`. Periksa lisensi masing-masing sebelum
mendistribusikan ulang checkpoint hasil retraining — turunan mewarisi
kewajibannya.

## Struktur

```
app/
├── main.py                 endpoint API, lock pelatihan per model
├── config.py               pengaturan: model dasar, ambang, seed
├── security.py             kunci API (X-API-Key) dan pembatas laju
├── weights_store.py        penyimpanan bobot (opsional ke Hugging Face Hub)
├── preprocessing/
│   ├── text_cleaner.py       TextCleaner: satu-satunya jalur pembersihan teks
│   └── indonesian_stopwords.py
├── services/
│   ├── sentiment_service.py  IndoBERT sentimen + temperature scaling
│   ├── aspect_service.py     ekstraksi aspek (token classification BIO)
│   ├── topic_service.py      BERTopic (IndoBERT + UMAP + HDBSCAN)
│   └── association_service.py PMI aspek–topik
├── schemas/                skema permintaan (Pydantic)
└── utils/
    ├── training.py           fine-tuning, sidik jari kolam, provenance
    └── topic_metrics.py      c_v, c_npmi, diversity
data/                       korpus, dataset publik, hasil eksperimen
docs/                       metodologi per modul dan bahan skripsi
scripts/                    skrip eksperimen dan evaluasi
tests/                      tes pytest
```

## Dokumentasi metodologi

| Dokumen | Isi |
|---|---|
| [METODOLOGI_PREPROCESSING.md](docs/METODOLOGI_PREPROCESSING.md) | Rancangan dan pengukuran preprocessing |
| [METODOLOGI_ANALISIS_SENTIMEN.md](docs/METODOLOGI_ANALISIS_SENTIMEN.md) | Pemilihan model, kalibrasi, evaluasi sentimen |
| [METODOLOGI_EKSTRAKSI_ASPEK.md](docs/METODOLOGI_EKSTRAKSI_ASPEK.md) | Eksperimen K1–K6 dan model produksi aspek |
| [METODOLOGI_TOPIC_MODELING.md](docs/METODOLOGI_TOPIC_MODELING.md) | Konfigurasi BERTopic, jumlah topik, kestabilan |
| [TINJAUAN_KONSEP_ACTIVE_LEARNING.md](docs/TINJAUAN_KONSEP_ACTIVE_LEARNING.md) | Kesesuaian siklus dengan konsep active learning |
| [TINJAUAN_EVALUASI_HOLDOUT.md](docs/TINJAUAN_EVALUASI_HOLDOUT.md) | Pemeriksaan statistik evaluasi hold-out |
| [RINGKASAN_TEMUAN_UNTUK_SKRIPSI.md](docs/RINGKASAN_TEMUAN_UNTUK_SKRIPSI.md) | Ringkasan temuan enam iterasi |
| [skripsi/](docs/skripsi/) | Bahan pembahasan dan metrik dalam JSON |

Data yang dipakai dijelaskan di [dataset/README.md](../dataset/README.md).
