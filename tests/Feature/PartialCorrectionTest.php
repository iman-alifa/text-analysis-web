<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengguna boleh menyimpan sebanyak yang sempat ditinjau.
 *
 * Formulir koreksi mengirim SELURUH baris yang tampil di halaman, bukan hanya
 * yang ditinjau. Dulu setiap baris yang terkirim ditandai "sudah dikoreksi"
 * dengan prediksi model sendiri sebagai koreksinya - artinya menekan Simpan
 * setelah meninjau 3 baris menandai 25 baris sebagai hasil verifikasi manusia,
 * dan 22 di antaranya ikut terkirim sebagai data latih tanpa pernah dibaca
 * siapa pun. Untuk skripsi yang mengukur peningkatan model dari koreksi
 * manusia, itu merusak angka yang dilaporkan.
 */
class PartialCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(string $jenis = 'sentiment'): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga',
            'input_type' => 'manual',
            'analysis_type' => $jenis,
            'raw_data' => range(1, 5),
            'total_records' => 5,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(fn ($i) => [
                'original_index' => $i,
                'text' => "kalimat nomor {$i}",
                'sentiment' => 'neutral',
                'confidence' => 0.5,
                'aspects' => ['harga'],
            ], range(0, 4)),
            'sentiment_distribution' => ['positive' => 0.0, 'neutral' => 100.0, 'negative' => 0.0],
        ]);

        $analysis = $analysis->fresh('result');

        // Halaman koreksi memicu ekstraksi baris latih.
        $this->actingAs($user)->get(route('analysis.feedback', $analysis->id))->assertOk();

        return $analysis;
    }

    /** Kirim seluruh baris seperti yang dilakukan formulir sungguhan. */
    private function kirimSeluruhBaris(TextAnalysis $analysis, array $ditinjau): \Illuminate\Testing\TestResponse
    {
        $corrections = $analysis->trainingItems()->orderBy('id')->get()
            ->map(fn ($item) => array_merge(
                ['item_id' => $item->id, 'corrected_sentiment' => null],
                $ditinjau[$item->id] ?? []
            ))
            ->all();

        return $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), ['corrections' => $corrections]);
    }

    public function test_baris_yang_tidak_ditinjau_tidak_ditandai_terkoreksi(): void
    {
        $analysis = $this->analysis();
        $items = $analysis->trainingItems()->orderBy('id')->get();

        $this->kirimSeluruhBaris($analysis, [
            $items[0]->id => ['corrected_sentiment' => 'positive'],
            $items[2]->id => ['corrected_sentiment' => 'negative'],
        ])->assertRedirect();

        $this->assertSame(2, $analysis->trainingItems()->where('is_corrected', true)->count());
        $this->assertSame(3, $analysis->trainingItems()->where('is_corrected', false)->count());
    }

    public function test_hanya_baris_yang_ditinjau_yang_menyimpan_label(): void
    {
        $analysis = $this->analysis();
        $items = $analysis->trainingItems()->orderBy('id')->get();

        $this->kirimSeluruhBaris($analysis, [
            $items[0]->id => ['corrected_sentiment' => 'positive'],
        ]);

        $this->assertSame('positive', $items[0]->fresh()->corrected_sentiment);
        $this->assertNull($items[1]->fresh()->corrected_sentiment);
    }

    public function test_menyetujui_prediksi_model_tetap_dihitung_sebagai_koreksi(): void
    {
        // Menegaskan bahwa model sudah benar juga sinyal yang berharga bagi
        // active learning, jadi bukan "tidak ada perubahan berarti dilewati".
        $analysis = $this->analysis();
        $item = $analysis->trainingItems()->orderBy('id')->first();

        $this->kirimSeluruhBaris($analysis, [
            $item->id => ['corrected_sentiment' => 'neutral'],
        ]);

        $this->assertTrue((bool) $item->fresh()->is_corrected);
        $this->assertSame('neutral', $item->fresh()->corrected_sentiment);
    }

    public function test_catatan_saja_sudah_menghitung_sebagai_ditinjau(): void
    {
        $analysis = $this->analysis();
        $item = $analysis->trainingItems()->orderBy('id')->first();

        $this->kirimSeluruhBaris($analysis, [
            $item->id => ['correction_notes' => 'kalimatnya ambigu'],
        ]);

        $this->assertTrue((bool) $item->fresh()->is_corrected);
    }

    public function test_perubahan_aspek_saja_sudah_menghitung_sebagai_ditinjau(): void
    {
        $analysis = $this->analysis('aspect');
        $item = $analysis->trainingItems()->orderBy('id')->first();

        $this->kirimSeluruhBaris($analysis, [
            $item->id => ['corrected_aspects' => 'harga, pelayanan'],
        ]);

        $this->assertTrue((bool) $item->fresh()->is_corrected);
        $this->assertSame(['harga', 'pelayanan'], $item->fresh()->corrected_aspects);
    }

    public function test_aspek_yang_tidak_diubah_tidak_menghitung_sebagai_ditinjau(): void
    {
        // Kolom aspek selalu terisi bawaan hasil deteksi model, jadi mengirim
        // nilainya kembali apa adanya bukan tanda pengguna meninjau baris itu.
        $analysis = $this->analysis('aspect');
        $item = $analysis->trainingItems()->orderBy('id')->first();

        $this->kirimSeluruhBaris($analysis, [
            $item->id => ['corrected_aspects' => implode(', ', $item->detected_aspects)],
        ]);

        $this->assertFalse((bool) $item->fresh()->is_corrected);
    }

    public function test_pesan_hanya_menyebut_yang_disimpan(): void
    {
        $analysis = $this->analysis();
        $item = $analysis->trainingItems()->orderBy('id')->first();

        // Baris yang dilewati tidak disebut-sebut: itu perilaku yang wajar,
        // bukan sesuatu yang perlu dilaporkan kepada pengguna.
        $this->kirimSeluruhBaris($analysis, [
            $item->id => ['corrected_sentiment' => 'positive'],
        ])->assertSessionHas('feedback_success', function ($pesan) {
            return str_contains($pesan, '1 koreksi')
                && ! str_contains($pesan, 'dilewati')
                && ! str_contains($pesan, 'dibiarkan apa adanya');
        });
    }

    public function test_menyimpan_tanpa_meninjau_apa_pun_tidak_mengubah_data(): void
    {
        $analysis = $this->analysis();

        $this->kirimSeluruhBaris($analysis, [])
            ->assertSessionHas('feedback_success', fn ($pesan) => str_contains($pesan, 'Belum ada baris yang ditinjau'));

        $this->assertSame(0, $analysis->trainingItems()->where('is_corrected', true)->count());
    }

    public function test_koreksi_bisa_dilanjutkan_pada_kunjungan_berikutnya(): void
    {
        $analysis = $this->analysis();
        $items = $analysis->trainingItems()->orderBy('id')->get();

        $this->kirimSeluruhBaris($analysis, [$items[0]->id => ['corrected_sentiment' => 'positive']]);
        $this->kirimSeluruhBaris($analysis, [$items[1]->id => ['corrected_sentiment' => 'negative']]);

        $this->assertSame(2, $analysis->trainingItems()->where('is_corrected', true)->count());
    }

    public function test_halaman_tidak_memilih_sentimen_untuk_baris_yang_belum_ditinjau(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Radio yang otomatis terpilih adalah sebab seluruh baris ikut
        // tersimpan; tidak boleh ada satu pun pada halaman yang belum dikoreksi.
        $this->assertStringNotContainsString('checked>', $html);
    }
}
