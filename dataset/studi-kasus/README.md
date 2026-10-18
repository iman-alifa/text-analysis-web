# Hasil analisis korpus studi kasus

Folder ini berisi berkas ekspor CSV hasil analisis gabungan untuk keenam
lembaga, diunduh dari aplikasi (halaman hasil → **Export CSV**).

| Berkas | Lembaga | Video | Komentar |
|---|---|---:|---:|
| `tni.csv` | Tentara Nasional Indonesia | 12 | 3.549 |
| `presiden.csv` | Presiden | 11 | 1.859 |
| `kejaksaan-agung.csv` | Kejaksaan Agung | 11 | 1.493 |
| `kpk.csv` | Komisi Pemberantasan Korupsi | 12 | 368 |
| `polri.csv` | Kepolisian Republik Indonesia | 12 | 340 |
| `dpr.csv` | Dewan Perwakilan Rakyat | 10 | 778 |

Kolom setiap berkas:

| Kolom | Isi |
|---|---|
| `no` | Nomor urut teks sesuai urutan masukan |
| `teks` | Teks komentar asli |
| `teks_preprocessed` | Teks setelah preprocessing untuk modul sentimen |
| `sentimen` | Label sentimen hasil prediksi |
| `confidence` | *Confidence score* prediksi sentimen |
| `aspek` | Aspek yang diekstraksi, dipisahkan tanda koma |

Label pada berkas ini adalah **prediksi model**, bukan label manusia. Label
manusia untuk evaluasi berada di golden dataset (lihat
[`docs/PANDUAN_EVALUASI.md`](../../docs/PANDUAN_EVALUASI.md)).

Berkas ekspor tidak memuat nama akun, ID komentar, maupun foto profil pemberi
komentar. Daftar video sumber ada di
[`../daftar-video-sumber.csv`](../daftar-video-sumber.csv).
