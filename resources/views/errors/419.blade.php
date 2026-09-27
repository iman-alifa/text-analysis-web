@include('errors.layout', [
    'kode' => 419,
    'judul' => 'Sesi Anda sudah kedaluwarsa',
    'pesan' => 'Halaman dibiarkan terbuka terlalu lama, sehingga formulirnya tidak lagi dianggap sah.',
    'saran' => [
        'Muat ulang halaman, lalu kirim sekali lagi.',
        'Bila diminta masuk kembali, isian yang belum terkirim perlu diketik ulang.',
    ],
])
