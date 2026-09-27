{{-- Lambang aplikasi: balon percakapan berisi tiga batang naik.

     Balonnya mewakili teks opini yang dianalisis (komentar, ulasan), batangnya
     mewakili hasil ukurnya. Tiga warna batang adalah tiga kelas sentimen yang
     dipakai di seluruh aplikasi: negatif, netral, positif.

     Varian:
       - solid : ubin bergradasi biru, untuk latar terang (bawaan).
       - terang: ubin putih transparan, untuk latar yang sudah bergradasi.

     Id gradasi dibuat unik per pemanggilan; dua lambang dengan id yang sama
     pada satu halaman membuat salah satunya kehilangan warna. --}}
@props(['varian' => 'solid'])

@php
    $gid = 'logo-grad-'.\Illuminate\Support\Str::random(6);
@endphp

<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Lambang AnalisisTeks" {{ $attributes->merge(['class' => 'w-10 h-10 shrink-0']) }}>
    @if ($varian === 'terang')
        <rect width="48" height="48" rx="12" fill="#ffffff" fill-opacity="0.16"/>
    @else
        <defs>
            <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#2563EB"/>
                <stop offset="1" stop-color="#06B6D4"/>
            </linearGradient>
        </defs>
        <rect width="48" height="48" rx="12" fill="url(#{{ $gid }})"/>
    @endif
    <path d="M16 10.5H32A6 6 0 0 1 38 16.5V27A6 6 0 0 1 32 33H22.5L15.5 39V33A6 6 0 0 1 10 27V16.5A6 6 0 0 1 16 10.5Z" fill="#ffffff"/>
    <rect x="15.5" y="23" width="4.5" height="5.5" rx="1.5" fill="#F43F5E"/>
    <rect x="21.75" y="19.5" width="4.5" height="9" rx="1.5" fill="#F59E0B"/>
    <rect x="28" y="15.5" width="4.5" height="13" rx="1.5" fill="#10B981"/>
</svg>
