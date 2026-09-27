{{--
    Satu gambar pada pedoman penggunaan.

    Tangkapan layar belum tentu sudah ada saat pedoman ditulis. Komponen ini
    karena itu merender **tempat kosong bernama** ketika berkasnya belum ada:
    tata letaknya sudah benar sejak awal, dan menambahkan gambar nanti cukup
    menaruh berkas ke `public/img/panduan/` dengan nama yang tertulis di layar.
    Tanpa itu, gambar yang belum ada akan tampil sebagai ikon rusak dan
    halamannya harus ditata ulang setiap kali satu gambar ditambahkan.

    Pemakaian:
        <x-panduan-gambar src="01-buat-analisis.png"
                          alt="Formulir analisis baru dengan tab Unggah Berkas terbuka"
                          caption="Tab Unggah Berkas pada formulir analisis." />

    `alt` wajib diisi dan harus menjelaskan ISI gambar, bukan mengulang caption:
    pembaca dengan pembaca layar hanya mendapat teks itu.

    Foldernya `public/img/panduan/`, BUKAN `public/panduan/`. Nama yang kedua
    sama persis dengan rute `/panduan`, dan web server memeriksa berkas lebih
    dulu sebelum menyerahkan permintaan ke Laravel: folder yang ada tanpa berkas
    indeks membuat `/panduan` membalas 404 pada server bawaan PHP, dilewati
    rewrite pada Apache (`RewriteCond %{REQUEST_FILENAME} !-d`), dan menjadi 403
    pada nginx (`try_files $uri $uri/`). Rutenya tidak pernah sempat dijalankan.
--}}
@props([
    'src' => null,
    'alt' => '',
    'caption' => null,
    'lebar' => 'penuh',
])

@php
    $berkas = $src ? public_path('img/panduan/'.ltrim($src, '/')) : null;
    $ada = $berkas !== null && is_file($berkas);
    $kelasLebar = $lebar === 'sempit' ? 'max-w-md' : 'w-full';
@endphp

<figure class="my-5 {{ $kelasLebar }}">
    @if($ada)
        <img src="{{ asset('img/panduan/'.ltrim($src, '/')) }}"
             alt="{{ $alt }}"
             loading="lazy"
             class="w-full rounded-lg border border-gray-200 shadow-sm">
    @else
        {{-- Tempat kosong. Sengaja menyebut nama berkas yang ditunggu supaya
             siapa pun yang menambahkan tangkapan layar tahu persis apa yang
             harus disiapkan, tanpa membuka kode. --}}
        <div class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center print:hidden">
            <svg class="mx-auto w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18 12h.008v.008H18V12zm-3-9H6a3 3 0 00-3 3v12a3 3 0 003 3h12a3 3 0 003-3V6a3 3 0 00-3-3z"/>
            </svg>
            <p class="mt-2 text-sm font-medium text-gray-600">Screenshot belum tersedia</p>
            @if($src)
                <p class="mt-1 text-xs text-gray-500">
                    Simpan gambarnya sebagai <code class="px-1 py-0.5 bg-white border border-gray-200 rounded">public/img/panduan/{{ $src }}</code>
                </p>
            @endif
            @if($alt)
                <p class="mt-2 text-xs text-gray-500 italic max-w-lg mx-auto">Isi yang diharapkan: {{ $alt }}</p>
            @endif
        </div>
    @endif

    @if($caption)
        <figcaption class="mt-2 text-xs text-gray-500">{{ $caption }}</figcaption>
    @endif
</figure>
