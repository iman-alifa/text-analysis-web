@include('errors.layout', [
    'kode' => 403,
    'judul' => 'Anda tidak punya akses ke halaman ini',
    'pesan' => 'Halaman ini dibatasi untuk pemiliknya, atau untuk akun dengan peran administrator.',
    'saran' => [
        'Analisis hanya bisa dibuka oleh akun yang membuatnya.',
        'Halaman Training dan Evaluasi Model hanya untuk administrator.',
    ],
])
