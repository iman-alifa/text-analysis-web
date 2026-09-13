<?php

namespace App\Console\Commands;

use App\Models\TextAnalysis;
use App\Services\NLPApiService;
use App\Services\PreprocessingConfigResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hitung ulang HANYA pemodelan topik pada analisis yang sudah selesai.
 *
 * Berbeda dari `analysis:reprocess-aspect`, perintah ini TIDAK mengantre ulang
 * seluruh pekerjaan. Alasannya biaya: pada studi kasus enam lembaga, sentimen
 * dan aspek berjalan atas ribuan komentar dan memakan puluhan menit per
 * analisis, sedangkan pemodelan topik saja selesai dalam hitungan menit.
 * Menjalankan ulang semuanya demi memperbaiki topik berarti menghitung ulang
 * dua modul yang hasilnya sudah benar.
 *
 * Yang diperbarui hanya `topic_results` dan `association_results`. Yang kedua
 * ikut karena PMI aspek-topik dihitung dari `document_topics`; membiarkannya
 * berarti asosiasi menunjuk ke id topik yang sudah tidak ada.
 *
 * Yang TIDAK disentuh: `predictions`, `document_aspects`, `aspect_results`,
 * `sentiment_distribution`, `metrics`. Id analisis, id hasil, dan seluruh
 * relasinya tetap - koreksi pengguna dan golden dataset tidak terputus.
 *
 * Syarat: nlp-api-service berjalan. Tidak butuh queue worker; perintah ini
 * memanggil API secara langsung supaya kemajuannya terlihat.
 */
class ReprocessTopicAnalyses extends Command
{
    protected $signature = 'analysis:reprocess-topic
                            {--apply : Simpan hasilnya (tanpa flag ini hanya menampilkan rencana)}
                            {--id=* : Batasi pada id analisis tertentu}
                            {--num-topics=0 : 0 = mode otomatis (dianjurkan)}
                            {--min-cv= : Peringatkan bila c_v di bawah nilai ini}
                            {--timeout=2400 : Batas waktu per analisis, detik}';

    protected $description = 'Hitung ulang pemodelan topik saja, tanpa mengulang sentimen dan aspek';

    public function handle(NLPApiService $api, PreprocessingConfigResolver $resolver): int
    {
        // Pemodelan topik pada korpus besar memakan waktu jauh lebih lama
        // daripada permintaan analisis biasa: terukur 810 detik untuk 3 548
        // komentar, sementara batas bawaan `nlp_api.timeout * 2` = 1 200 detik.
        // Sisa kelonggarannya hanya 1,5x, dan itu habis begitu ada proses lain
        // memakai CPU yang sama - persis yang terjadi pada korpus TNI.
        //
        // Config diubah SEBELUM service dibuat ulang, karena NLPApiService
        // membaca timeout-nya sekali di konstruktor.
        config(['services.nlp_api.timeout' => (int) $this->option('timeout')]);
        $api = app(NLPApiService::class);

        $apply = (bool) $this->option('apply');
        $numTopics = (int) $this->option('num-topics');
        $minCv = $this->option('min-cv') !== null ? (float) $this->option('min-cv') : null;
        $onlyIds = array_filter((array) $this->option('id'));

        $query = TextAnalysis::with('result')
            ->where('status', 'completed')
            ->whereIn('analysis_type', ['topic', 'combined']);

        if ($onlyIds) {
            $query->whereIn('id', $onlyIds);
        }

        $analyses = $query->orderBy('id')->get()
            ->filter(fn (TextAnalysis $a) => $a->result && ! empty($a->raw_data));

        if ($analyses->isEmpty()) {
            $this->error('Tidak ada analisis topik/gabungan yang selesai dan punya hasil.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('MODE RENCANA - tidak ada yang disimpan. Tambahkan --apply untuk menjalankan.');
        }

        $this->newLine();
        $rows = [];
        $gagal = 0;

        foreach ($analyses as $analysis) {
            $texts = array_values($analysis->raw_data);
            $lama = $analysis->result->topic_results['quality']['c_v'] ?? null;
            $lamaK = count($analysis->result->topic_results['topics'] ?? []);

            $this->line(sprintf(
                '#%d %s (%d teks) — topik lama: %d, c_v lama: %s',
                $analysis->id, $analysis->title, count($texts), $lamaK, $lama ?? '-'
            ));

            if (! $apply) {
                $rows[] = [$analysis->id, $analysis->title, count($texts), $lamaK, $lama, '-', '-', '-'];

                continue;
            }

            try {
                $mulai = microtime(true);
                $config = $resolver->resolve();
                $respons = $api->analyzeTopic($texts, $config, $numTopics);
                $topik = $respons['results'] ?? [];

                if (empty($topik['document_topics'])) {
                    throw new \RuntimeException('Layanan tidak mengembalikan document_topics.');
                }

                // Kontrak penjajaran: N teks masuk, N id topik keluar. Kalau ini
                // meleset, menyimpannya akan merusak pemasangan hasil dengan
                // teks aslinya di seluruh baris setelah titik gesernya.
                if (count($topik['document_topics']) !== count($texts)) {
                    throw new \RuntimeException(sprintf(
                        'Penjajaran rusak: %d teks -> %d document_topics.',
                        count($texts), count($topik['document_topics'])
                    ));
                }

                $baru = $topik['quality']['c_v'] ?? null;
                $baruK = count($topik['topics'] ?? []);

                $data = ['topic_results' => $topik];

                // Asosiasi dihitung ulang karena bergantung pada document_topics.
                $docAspects = $analysis->result->document_aspects ?? [];
                if ($docAspects && count($docAspects) === count($texts)) {
                    try {
                        $asosiasi = $api->analyzeAssociation(
                            $docAspects, $topik['document_topics'], $baruK
                        );
                        $data['association_results'] = $asosiasi['results'] ?? $asosiasi;
                    } catch (Throwable $e) {
                        $this->warn('   asosiasi gagal dihitung ulang: '.$e->getMessage());
                    }
                }

                $analysis->result->update($data);
                $durasi = microtime(true) - $mulai;

                $tanda = '';
                if ($minCv !== null && $baru !== null && $baru < $minCv) {
                    $tanda = ' ⚠ di bawah '.$minCv;
                    $gagal++;
                }

                $this->info(sprintf(
                    '   -> topik: %d, c_v: %s (%s), %.0f detik%s',
                    $baruK, $baru ?? '-',
                    $lama !== null && $baru !== null
                        ? sprintf('%+.4f', $baru - $lama) : 'baru',
                    $durasi, $tanda
                ));

                $rows[] = [
                    $analysis->id, mb_substr($analysis->title, 0, 26), count($texts),
                    $lamaK.' -> '.$baruK, $lama, $baru,
                    $lama !== null && $baru !== null ? sprintf('%+.4f', $baru - $lama) : '-',
                    sprintf('%.0fs', $durasi),
                ];
            } catch (Throwable $e) {
                $gagal++;
                $this->error('   gagal: '.$e->getMessage());
                $rows[] = [$analysis->id, mb_substr($analysis->title, 0, 26),
                    count($texts), 'GAGAL', $lama, '-', '-', '-'];
            }
        }

        $this->newLine();
        $this->table(
            ['id', 'judul', 'teks', 'topik', 'c_v lama', 'c_v baru', 'delta', 'waktu'],
            $rows
        );

        if ($apply && $minCv !== null) {
            $this->newLine();
            $this->line($gagal === 0
                ? "Semua analisis mencapai c_v >= {$minCv}."
                : "{$gagal} analisis masih di bawah c_v {$minCv}.");
        }

        return $gagal > 0 && $apply ? self::FAILURE : self::SUCCESS;
    }
}
