<?php

namespace Tests\Feature;

use App\Services\NLPApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `document_aspects_normalized` harus ikut terangkut di jalur BATCH.
 *
 * Jalur batch dipakai begitu jumlah teks melewati `services.nlp_api.batch_size`
 * (bawaan 50). Sebelumnya jalur itu hanya merakit `document_aspects`, sehingga
 * bentuk dasarnya hilang sama sekali untuk korpus besar.
 *
 * Yang membuatnya mahal: evaluasi hold-out memakai golden dataset 300 baris,
 * jadi ia SELALU melewati jalur batch - dan metrik aspek tidak pernah bisa
 * dihitung. Penjaga di HoldoutEvaluationService menolak melaporkan angka aspek
 * ketika bentuk dasarnya tidak ada, jadi kegagalannya terlihat, bukan diam-diam
 * melaporkan F1 yang salah. Test ini menjaga sisi pengangkutannya.
 */
class BatchAspectNormalizedTest extends TestCase
{
    private function teks(int $n): array
    {
        return array_map(fn ($i) => "komentar nomor {$i} tentang pelayanan", range(1, $n));
    }

    /**
     * Balasan satu batch: tiap dokumen punya bentuk permukaan + bentuk dasar.
     */
    private function balasanBatch(bool $denganNormalisasi = true): callable
    {
        return function ($request) use ($denganNormalisasi) {
            $jumlah = count($request->data()['texts']);

            $hasil = [
                'aspect_sentiments' => [[
                    'aspect' => 'pelayanan', 'count' => $jumlah,
                    'sentiments' => ['positive' => 100, 'neutral' => 0, 'negative' => 0],
                ]],
                'document_aspects' => array_fill(0, $jumlah, ['pelayanannya']),
                'summary' => 'uji',
            ];

            if ($denganNormalisasi) {
                $hasil['document_aspects_normalized'] = array_fill(0, $jumlah, ['layan']);
            }

            return Http::response(['status' => 'success', 'results' => $hasil]);
        };
    }

    public function test_bentuk_dasar_terangkut_pada_korpus_di_atas_batch_size(): void
    {
        config(['services.nlp_api.batch_size' => 50]);
        Http::fake(['*/api/analyze/aspect' => $this->balasanBatch()]);

        $hasil = app(NLPApiService::class)->analyzeAspect($this->teks(300));
        $r = $hasil['results'];

        $this->assertArrayHasKey('document_aspects_normalized', $r);
        $this->assertCount(300, $r['document_aspects_normalized']);
        $this->assertSame(['layan'], $r['document_aspects_normalized'][0]);
        $this->assertSame(['layan'], $r['document_aspects_normalized'][299]);
    }

    public function test_bentuk_dasar_sejajar_indeks_dengan_bentuk_permukaan(): void
    {
        config(['services.nlp_api.batch_size' => 50]);
        Http::fake(['*/api/analyze/aspect' => $this->balasanBatch()]);

        $r = app(NLPApiService::class)->analyzeAspect($this->teks(300))['results'];

        $this->assertCount(
            count($r['document_aspects']),
            $r['document_aspects_normalized'],
            'panjang bentuk dasar dan bentuk permukaan harus sama'
        );
        // Kontrak penjajaran: baris ke-i pada keduanya menunjuk dokumen yang sama.
        foreach ([0, 49, 50, 150, 299] as $i) {
            $this->assertSame(['pelayanannya'], $r['document_aspects'][$i]);
            $this->assertSame(['layan'], $r['document_aspects_normalized'][$i]);
        }
    }

    public function test_kunci_tidak_dikarang_bila_layanan_tidak_mengirimnya(): void
    {
        /**
         * Mengirim larik kosong akan jauh lebih berbahaya daripada tidak
         * mengirim apa pun: evaluasi akan mengira normalisasi tersedia, lalu
         * membandingkan label emas dengan daftar kosong dan melaporkan nol
         * untuk seluruh metrik aspek - tanpa satu pun tanda bahwa penyebabnya
         * versi layanan yang tertinggal.
         */
        config(['services.nlp_api.batch_size' => 50]);
        Http::fake(['*/api/analyze/aspect' => $this->balasanBatch(false)]);

        $r = app(NLPApiService::class)->analyzeAspect($this->teks(120))['results'];

        $this->assertArrayNotHasKey('document_aspects_normalized', $r);
        $this->assertCount(120, $r['document_aspects']);
    }

    public function test_jalur_tunggal_meneruskan_apa_adanya(): void
    {
        config(['services.nlp_api.batch_size' => 50]);
        Http::fake(['*/api/analyze/aspect' => $this->balasanBatch()]);

        $r = app(NLPApiService::class)->analyzeAspect($this->teks(10))['results'];

        $this->assertArrayHasKey('document_aspects_normalized', $r);
        $this->assertCount(10, $r['document_aspects_normalized']);
    }
}
