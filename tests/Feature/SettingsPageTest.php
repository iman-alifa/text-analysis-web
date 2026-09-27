<?php

namespace Tests\Feature;

use App\Models\PreprocessingConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman Pengaturan.
 *
 * Dua hal yang dijaga di sini, dan keduanya berasal dari kesalahan nyata:
 *
 * 1. **Halamannya benar-benar merender isinya.** Sebelumnya `profile/edit`
 *    memakai `<x-app-layout>`, sementara `layouts/app` menaruh isi lewat
 *    `@yield('content')` dan bukan `{{ $slot }}`. Akibatnya seluruh formulir
 *    dibuang diam-diam: halaman tetap 200 dan kerangkanya tampil, tetapi tidak
 *    ada satu pun kolom yang bisa diisi. Tidak ada tes yang menangkapnya karena
 *    yang diperiksa hanya kode status.
 * 2. **Preferensi benar-benar tersimpan dan terpakai**, supaya menu bernama
 *    "Pengaturan" tidak menjanjikan sesuatu yang tidak mengubah apa pun.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $nama, bool $default = false): PreprocessingConfig
    {
        return PreprocessingConfig::create([
            'name' => $nama,
            'description' => 'Konfigurasi uji',
            'is_default' => $default,
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => false,
            'remove_stopwords' => true,
            'stemming' => true,
        ]);
    }

    public function test_halaman_pengaturan_merender_seluruh_formulirnya(): void
    {
        $this->config('Bawaan', default: true);

        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            // Keempat kartu, bukan sekadar kerangka halaman.
            ->assertSee('Preferensi Analisis')
            ->assertSee('Data Akun')
            ->assertSee('Kata Sandi')
            ->assertSee('Hapus Akun')
            // Kolom yang benar-benar bisa diisi.
            ->assertSee('preprocessing_config_id');
    }

    public function test_preferensi_preprocessing_tersimpan(): void
    {
        $config = $this->config('Lengkap');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.preferences'), ['preprocessing_config_id' => $config->id])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'preferences-updated');

        $this->assertSame($config->id, $user->fresh()->preprocessing_config_id);
    }

    public function test_pilihan_kosong_berarti_ikut_bawaan_sistem(): void
    {
        // String kosong dari <select> harus menjadi null, bukan 0: null yang
        // membuat resolver jatuh ke konfigurasi `is_default`.
        $config = $this->config('Lengkap');
        $user = User::factory()->create(['preprocessing_config_id' => $config->id]);

        $this->actingAs($user)
            ->patch(route('profile.preferences'), ['preprocessing_config_id' => ''])
            ->assertRedirect();

        $this->assertNull($user->fresh()->preprocessing_config_id);
    }

    public function test_preferensi_menjadi_pilihan_awal_di_formulir_analisis(): void
    {
        $this->config('Bawaan', default: true);
        $pilihan = $this->config('Lengkap');

        $user = User::factory()->create(['preprocessing_config_id' => $pilihan->id]);

        $html = $this->actingAs($user)->get(route('analysis.create'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'value="'.$pilihan->id.'" selected',
            $html,
            'Preferensi pengguna tidak terpilih lebih dulu di formulir analisis.'
        );
    }

    public function test_konfigurasi_karangan_ditolak(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.preferences'), ['preprocessing_config_id' => 99999])
            ->assertSessionHasErrors('preprocessing_config_id');

        $this->assertNull($user->fresh()->preprocessing_config_id);
    }
}
