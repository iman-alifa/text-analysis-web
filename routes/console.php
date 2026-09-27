<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pemicu pelatihan ulang otomatis hanya dievaluasi saat pengguna menyimpan
// koreksi. Koreksi yang datang berdempetan jatuh ke dalam jeda, dan bila tidak
// ada lagi yang menyimpan setelah jeda habis, pemicunya tidak pernah
// dievaluasi ulang - terjadi di server 24 Sep: 36 koreksi tidak pernah masuk
// putaran mana pun. Tanpa cron `schedule:run` aplikasi tetap berjalan seperti
// dulu; penjadwal hanya menutup celah itu.
Schedule::command('analysis:auto-retrain')
    ->hourly()
    ->withoutOverlapping();
