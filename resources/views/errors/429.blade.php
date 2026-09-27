@include('errors.layout', [
    'kode' => 429,
    'judul' => 'Terlalu banyak permintaan',
    'pesan' => 'Beberapa fitur dibatasi jumlah permintaannya per menit agar layanan tetap stabil untuk semua pengguna.',
    'saran' => [
        'Tunggu sekitar satu menit, lalu coba lagi.',
        'Interpretasi AI dibatasi 10 permintaan per menit karena memotong kuota harian.',
    ],
])
