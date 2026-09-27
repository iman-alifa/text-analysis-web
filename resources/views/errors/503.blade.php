@include('errors.layout', [
    'kode' => 503,
    'judul' => 'Layanan sedang tidak tersedia',
    'pesan' => 'Aplikasi sedang dalam pemeliharaan, atau layanan analisis di belakangnya belum siap menerima permintaan.',
    'saran' => [
        'Coba lagi beberapa saat lagi.',
        'Analisis yang sudah tersimpan tidak terpengaruh dan tetap bisa dibuka setelah layanan kembali.',
    ],
])
