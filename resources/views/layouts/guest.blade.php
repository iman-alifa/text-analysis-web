<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex">

        {{-- Judul per halaman, bukan nama aplikasi saja. Tab yang semuanya
             berbunyi "Laravel" tidak bisa dibedakan satu sama lain. --}}
        <title>{{ ($judul ?? 'Masuk').' · '.config('app.name', 'AnalisisTeks') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased">
        <div class="min-h-full lg:grid lg:grid-cols-2">

            {{-- Kolom kiri: penjelasan singkat tentang aplikasi apa ini.
                 Disembunyikan di layar sempit supaya formulirnya tidak perlu
                 digulir dulu sebelum bisa diisi. --}}
            <aside class="hidden lg:flex flex-col justify-between bg-gradient-to-br from-blue-600 to-cyan-600 text-white p-12">
                <a href="{{ route('landing') }}" class="flex items-center gap-3 w-fit">
                    <x-logo-mark varian="terang" class="w-10 h-10" />
                    <span class="text-xl font-bold">Analisis<span class="text-cyan-100">Teks</span></span>
                </a>

                <div class="max-w-md">
                    <h1 class="text-3xl font-bold leading-tight">
                        Analisis sentimen, aspek, dan topik untuk teks berbahasa Indonesia.
                    </h1>
                    <p class="mt-4 text-white/90 leading-relaxed">
                        Upload data, jalankan analisis, lalu perbaiki hasil yang keliru.
                        Setiap koreksi ikut melatih model pada putaran berikutnya.
                    </p>

                    {{-- Angkanya dibaca dari keadaan terakhir (PublicStatsService),
                         jadi tidak basi setiap ada evaluasi baru. Tidak tampil
                         sama sekali pada pemasangan yang belum pernah diukur. --}}
                    @php
                        $statistikPublik = app(\App\Services\PublicStatsService::class)->snapshot();
                        $akurasiPublik = \App\Services\PublicStatsService::persen($statistikPublik['accuracy']);
                        $f1Publik = \App\Services\PublicStatsService::persen($statistikPublik['aspect_f1']);
                    @endphp
                    @if ($akurasiPublik || $f1Publik)
                        <dl class="mt-10 grid grid-cols-2 gap-6">
                            @if ($akurasiPublik)
                                <div>
                                    <dt class="text-2xl font-bold">{{ $akurasiPublik }}</dt>
                                    <dd class="text-sm text-white/80">Akurasi sentimen pada test set berlabel</dd>
                                </div>
                            @endif
                            @if ($f1Publik)
                                <div>
                                    <dt class="text-2xl font-bold">{{ $f1Publik }}</dt>
                                    <dd class="text-sm text-white/80">F1-score ekstraksi aspek</dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>

                <p class="text-sm text-white/70">
                    &copy; {{ date('Y') }} AnalisisTeks. Dikembangkan sebagai penelitian skripsi.
                </p>
            </aside>

            {{-- Kolom kanan: formulirnya sendiri. --}}
            <main class="flex flex-col justify-center px-6 py-12 sm:px-12 bg-gray-50">
                <div class="w-full max-w-md mx-auto">
                    <a href="{{ route('landing') }}" class="lg:hidden flex items-center gap-3 mb-8 w-fit">
                        <x-logo-mark class="w-10 h-10" />
                        <span class="text-xl font-bold text-gray-900">Analisis<span class="text-blue-600">Teks</span></span>
                    </a>

                    @isset($judul)
                        <h2 class="text-2xl font-bold text-gray-900">{{ $judul }}</h2>
                        @isset($keterangan)
                            <p class="mt-2 text-sm text-gray-600">{{ $keterangan }}</p>
                        @endisset
                    @endisset

                    <div class="mt-6 bg-white shadow-sm border border-gray-200 rounded-xl p-6 sm:p-8">
                        {{ $slot }}
                    </div>

                    @isset($footer)
                        <p class="mt-6 text-center text-sm text-gray-600">{{ $footer }}</p>
                    @endisset
                </div>
            </main>
        </div>
    </body>
</html>
