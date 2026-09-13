<?php

namespace Tests\Feature;

use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua anotator yang bekerja dari salinan berkas kosong yang sama secara
 * TERPISAH pulang dengan dua berkas, masing-masing berisi jawabannya sendiri
 * di kolom `label`. `evaluation:merge-annotators` menggabungkannya menjadi
 * satu berkas `label` + `label_2` yang dikenali `evaluation:import` - tanpa
 * ini, mengunggah keduanya bergantian akan menimpa label anotator pertama,
 * bukan menghasilkan pasangan untuk kappa.
 */
class EvaluationMergeAnnotatorsTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $isi): string
    {
        $path = tempnam(sys_get_temp_dir(), 'anotator').'.csv';
        file_put_contents($path, $isi);

        return $path;
    }

    public function test_dua_berkas_independen_digabung_lewat_id(): void
    {
        $file1 = $this->csv(
            "id,text,label,label_2,aspects,segment,note\n".
            "1,Pelayanannya bagus,positive,,pelayanan,Video A,\n".
            "2,Sistemnya error terus,negative,,sistem,Video A,\n".
            "3,Biasa saja,neutral,,,Video B,\n"
        );

        // Anotator kedua menulis jawabannya sendiri di kolom `label` pada
        // berkasnya sendiri - bukan di `label_2` - karena tidak mengganti mode.
        $file2 = $this->csv(
            "id,text,label,label_2,aspects,segment,note\n".
            "1,Pelayanannya bagus,positive,,,Video A,\n".
            "2,Sistemnya error terus,neutral,,,Video A,\n".
            "3,Biasa saja,neutral,,,Video B,\n"
        );

        $out = tempnam(sys_get_temp_dir(), 'gabungan').'.csv';

        $this->artisan('evaluation:merge-annotators', [
            'file1' => $file1,
            'file2' => $file2,
            '--out' => $out,
        ])
            ->expectsOutputToContain('Baris berpasangan (dianotasi ganda): 3')
            ->assertExitCode(0);

        $dibaca = app(GoldenDatasetService::class)->readFile($out);

        $this->assertCount(3, $dibaca['rows']);

        $baris1 = collect($dibaca['rows'])->firstWhere('text', 'Pelayanannya bagus');
        $this->assertSame('positive', $baris1['gold_sentiment']);
        $this->assertSame('positive', $baris1['second_sentiment']);
        $this->assertSame(['pelayanan'], $baris1['gold_aspects']);

        // Anotator kedua menilai baris ini "neutral", anotator pertama "negative" -
        // keduanya harus terbawa apa adanya, bukan salah satunya hilang.
        $baris2 = collect($dibaca['rows'])->firstWhere('text', 'Sistemnya error terus');
        $this->assertSame('negative', $baris2['gold_sentiment']);
        $this->assertSame('neutral', $baris2['second_sentiment']);
    }

    public function test_anotator_kedua_yang_memakai_mode_label_2_tetap_terbaca(): void
    {
        // Alur yang "benar" menurut aplikasi anotasi: anotator kedua membuka
        // hasil ekspor anotator pertama dan mengisi kolom label_2. Perintah ini
        // harus tetap bekerja walau kedua kolom sudah campur begini.
        $file1 = $this->csv(
            "id,text,label,label_2,aspects,segment,note\n".
            "1,Bagus,positive,,,,\n"
        );
        $file2 = $this->csv(
            "id,text,label,label_2,aspects,segment,note\n".
            "1,Bagus,,positive,,,\n"
        );

        $out = tempnam(sys_get_temp_dir(), 'gabungan').'.csv';

        $this->artisan('evaluation:merge-annotators', [
            'file1' => $file1, 'file2' => $file2, '--out' => $out,
        ])->assertExitCode(0);

        $dibaca = app(GoldenDatasetService::class)->readFile($out);

        $this->assertSame('positive', $dibaca['rows'][0]['gold_sentiment']);
        $this->assertSame('positive', $dibaca['rows'][0]['second_sentiment']);
    }

    public function test_pencocokan_jatuh_ke_sidik_jari_teks_tanpa_kolom_id(): void
    {
        $file1 = $this->csv(
            "text,label\n".
            "Pelayanannya bagus,positive\n".
            "Sistemnya error terus,negative\n"
        );
        $file2 = $this->csv(
            "text,label\n".
            "Sistemnya error terus,negative\n".
            "Pelayanannya bagus,positive\n"
        );

        $out = tempnam(sys_get_temp_dir(), 'gabungan').'.csv';

        $this->artisan('evaluation:merge-annotators', [
            'file1' => $file1, 'file2' => $file2, '--out' => $out,
        ])
            ->expectsOutputToContain('Baris berpasangan (dianotasi ganda): 2')
            ->assertExitCode(0);
    }

    public function test_baris_hanya_di_berkas_kedua_dilewati_dengan_peringatan(): void
    {
        $file1 = $this->csv(
            "id,text,label\n".
            "1,Bagus,positive\n"
        );
        $file2 = $this->csv(
            "id,text,label\n".
            "1,Bagus,positive\n".
            "2,Baris asing,negative\n"
        );

        $out = tempnam(sys_get_temp_dir(), 'gabungan').'.csv';

        $this->artisan('evaluation:merge-annotators', [
            'file1' => $file1, 'file2' => $file2, '--out' => $out,
        ])
            ->expectsOutputToContain('1 baris pada berkas kedua tidak ditemukan')
            ->assertExitCode(0);

        $dibaca = app(GoldenDatasetService::class)->readFile($out);
        $this->assertCount(1, $dibaca['rows']);
    }

    public function test_kappa_rendah_memicu_peringatan(): void
    {
        $file1 = $this->csv(
            "id,text,label\n".
            "1,Satu,positive\n".
            "2,Dua,negative\n".
            "3,Tiga,neutral\n".
            "4,Empat,positive\n"
        );
        // Sepakat sekali dari empat - kesepakatan rendah.
        $file2 = $this->csv(
            "id,text,label\n".
            "1,Satu,negative\n".
            "2,Dua,neutral\n".
            "3,Tiga,positive\n".
            "4,Empat,positive\n"
        );

        $out = tempnam(sys_get_temp_dir(), 'gabungan').'.csv';

        $this->artisan('evaluation:merge-annotators', [
            'file1' => $file1, 'file2' => $file2, '--out' => $out,
        ])
            ->expectsOutputToContain('Kappa di bawah 0,60')
            ->assertExitCode(0);
    }

    public function test_berkas_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('evaluation:merge-annotators', [
            'file1' => '/tidak/ada.csv',
            'file2' => '/tidak/ada-juga.csv',
        ])->assertExitCode(1);
    }
}
