<?php

namespace App\Services;

/**
 * Menyusun narasi berbasis aturan untuk hasil asosiasi aspek x topik.
 *
 * Sebelumnya logika ini tinggal di dalam blok @php pada show.blade.php - 60
 * baris analisis di dalam berkas tampilan, tidak bisa diuji, tidak bisa dipakai
 * ulang oleh export PDF, dan menyisipkan nama aspek ke HTML tanpa escape
 * padahal nama aspek bisa berasal dari masukan pengguna (predefined_aspects).
 *
 * Hasil di sini tetap dipakai walaupun interpretasi AI tersedia: kalau kuota
 * AI habis atau API key kosong, halaman hasil harus tetap bermakna.
 */
class AssociationInsightService
{
    /**
     * @param  array  $associationData  Keluaran ChartHelper::prepareAssociationData
     * @return array<int, string> Kalimat siap tampil (sudah aman untuk {!! !!})
     */
    public function build(array $associationData): array
    {
        $insights = [];

        $pmi = $associationData['pmi'] ?? [];
        $crosstab = $associationData['crosstab'] ?? [];
        $labels = $associationData['topics_label'] ?? [];
        $descriptions = $associationData['topics_desc'] ?? [];

        $terkuat = $this->asosiasiTerkuat($pmi);

        if ($terkuat !== null) {
            $insights[] = $this->kalimatAsosiasi($terkuat, $labels, $descriptions);
        }

        $terbanyak = $this->aspekPalingSeringDisebut($crosstab);

        // Kalau aspek yang paling sering disebut sama dengan yang asosiasinya
        // terkuat, kalimat kedua hanya akan mengulang kalimat pertama.
        if ($terbanyak !== null && $terbanyak['aspect'] !== ($terkuat['aspect'] ?? null)) {
            $kalimat = $this->kalimatDominasi($terbanyak, $labels);

            if ($kalimat !== null) {
                $insights[] = $kalimat;
            }
        }

        if (empty($insights)) {
            $insights[] = 'Data asosiasi berhasil dihitung, namun tidak ditemukan pola dominan yang cukup kuat untuk disorot.';
        }

        return $insights;
    }

    /**
     * @return array{aspect: string, topic_index: int, score: float}|null
     */
    private function asosiasiTerkuat(array $pmi): ?array
    {
        $terbaik = null;

        foreach ($pmi as $baris) {
            foreach ($baris['scores'] ?? [] as $index => $skor) {
                // Hanya PMI positif yang berarti "lebih sering muncul bersama
                // daripada kebetulan"; nilai negatif tidak layak disebut asosiasi.
                if ($skor <= 0) {
                    continue;
                }

                if ($terbaik === null || $skor > $terbaik['score']) {
                    $terbaik = [
                        'aspect' => (string) ($baris['aspect'] ?? ''),
                        'topic_index' => (int) $index,
                        'score' => (float) $skor,
                    ];
                }
            }
        }

        return $terbaik;
    }

    private function aspekPalingSeringDisebut(array $crosstab): ?array
    {
        $terbanyak = null;

        foreach ($crosstab as $baris) {
            if (($baris['mentions'] ?? 0) <= 0) {
                continue;
            }

            if ($terbanyak === null || $baris['mentions'] > $terbanyak['mentions']) {
                $terbanyak = $baris;
            }
        }

        return $terbanyak;
    }

    private function kalimatAsosiasi(array $terkuat, array $labels, array $descriptions): string
    {
        $aspek = e(mb_strtolower($terkuat['aspect']));
        $topik = e($labels[$terkuat['topic_index']] ?? 'topik tersebut');
        $kataKunci = $descriptions[$terkuat['topic_index']] ?? '';
        $skor = number_format($terkuat['score'], 2);

        $tambahan = $kataKunci
            ? ', yang dicirikan oleh kata kunci <em>'.e($kataKunci).'</em>'
            : '';

        return "Aspek <strong>{$aspek}</strong> menunjukkan asosiasi terkuat dengan {$topik} "
             ."(PMI = +{$skor}){$tambahan}. Hal ini mengindikasikan bahwa narasi tentang aspek ini "
             .'sangat spesifik dan melekat erat pada konteks wacana topik tersebut.';
    }

    private function kalimatDominasi(array $baris, array $labels): ?string
    {
        $dominanIndex = null;
        $dominanPersen = 0;

        foreach ($baris['topics'] ?? [] as $index => $persen) {
            if ($persen > $dominanPersen) {
                $dominanPersen = $persen;
                $dominanIndex = (int) $index;
            }
        }

        if ($dominanIndex === null) {
            return null;
        }

        $aspek = e(mb_strtolower((string) $baris['aspect']));
        $topik = e($labels[$dominanIndex] ?? 'topik tersebut');
        $sebutan = (int) $baris['mentions'];

        return "Sementara itu, aspek <strong>{$aspek}</strong> merupakan entitas yang paling banyak "
             ."dibicarakan (muncul {$sebutan} kali). Aspek ini mendominasi pembicaraan pada {$topik} "
             ."(sebesar {$dominanPersen}%), menunjukkan bahwa ini adalah subjek utama yang menjadi "
             .'sorotan sentral dalam topik tersebut.';
    }
}
