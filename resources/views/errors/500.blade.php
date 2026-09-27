@include('errors.layout', [
    'kode' => 500,
    'judul' => 'Terjadi kesalahan di sisi server',
    'pesan' => 'Permintaan Anda gagal diproses. Kesalahannya sudah tercatat di log aplikasi.',
    'saran' => [
        'Coba ulangi langkah terakhir Anda.',
        'Bila analisis sedang berjalan, prosesnya tetap lanjut di latar belakang.',
    ],
])
