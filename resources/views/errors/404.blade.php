@include('errors.layout', [
    'kode' => 404,
    'judul' => 'Halaman tidak ditemukan',
    'pesan' => 'Alamat yang Anda buka tidak ada, atau isinya sudah dihapus.',
    'saran' => [
        'Periksa kembali alamat yang diketik atau tautan yang diklik.',
        'Analisis yang dihapus tidak bisa dibuka lagi, termasuk lewat tautan lama.',
    ],
])
