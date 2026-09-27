{{--
    Kerangka halaman galat.

    Berdiri sendiri, tanpa layout aplikasi: halaman ini juga dirender ketika
    sesi kedaluwarsa atau layanan bermasalah, dan layout yang membaca
    Auth::user() akan gagal justru di saat ia paling dibutuhkan.

    Variabel: $kode, $judul, $pesan, $saran (array<string>|null)
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $judul }} &middot; {{ config('app.name', 'AnalisisTeks') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-gray-50 font-sans antialiased">
    <main class="min-h-full flex items-center justify-center px-6 py-16">
        <div class="w-full max-w-lg text-center">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 mb-8" aria-label="Ke halaman utama">
                <x-logo-mark class="w-9 h-9" />
                <span class="text-lg font-bold text-gray-900">Analisis<span class="text-blue-600">Teks</span></span>
            </a>
            <p class="text-sm font-semibold tracking-[0.2em] text-blue-600 uppercase">Error {{ $kode }}</p>

            <h1 class="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">{{ $judul }}</h1>

            <p class="mt-4 text-base leading-relaxed text-gray-600">{{ $pesan }}</p>

            @if(! empty($saran))
                <ul class="mt-6 space-y-2 text-left inline-block">
                    @foreach($saran as $butir)
                        <li class="flex gap-2.5 text-sm text-gray-600">
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span>{{ $butir }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                {{-- Tujuan utama menyesuaikan keadaan: pengguna yang sudah masuk
                     ingin kembali bekerja, yang belum masuk ingin halaman depan. --}}
                <a href="{{ auth()->check() ? route('dashboard') : route('landing') }}"
                   class="inline-flex items-center px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition">
                    {{ auth()->check() ? 'Kembali ke Dashboard' : 'Ke Halaman Utama' }}
                </a>

                <button type="button" onclick="history.back()"
                        class="inline-flex items-center px-5 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-700 text-sm font-semibold shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 transition">
                    Halaman Sebelumnya
                </button>
            </div>
        </div>
    </main>
</body>
</html>
