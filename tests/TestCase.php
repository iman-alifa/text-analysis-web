<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tidak ada satu pun tes yang boleh menembus ke layanan NLP sungguhan.
     *
     * Ini bukan kehati-hatian teoretis. `Http::fake([...])` **tidak** memblokir
     * permintaan yang tidak cocok dengan satu pun polanya - permintaan itu
     * lolos ke jaringan. Begitu pelatihan ulang otomatis dinyalakan, satu tes
     * yang menyimpan koreksi lewat jalur HTTP biasa langsung memanggil
     * `/api/retrain/*` pada uvicorn yang sedang berjalan, dengan antrean `sync`
     * sehingga jobnya berjalan seketika. Akibatnya bukan tes yang gagal
     * melainkan **checkpoint studi kasus tertimpa bobot hasil data fixture** -
     * kerusakan yang tidak terlihat dari keluaran tes sama sekali.
     *
     * `preventStrayRequests()` membuat kelalaian semacam itu gagal keras di
     * tes yang melakukannya, bukan menjadi kerusakan senyap di luar prosesnya.
     * Tes yang memang perlu memanggil layanan harus memfake endpointnya secara
     * eksplisit - dan dengan begitu ia menyatakan apa yang dipanggilnya.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
