{{--
    Blok interpretasi AI untuk satu bagian hasil.

    Dipakai bersama oleh bagian sentimen, aspek, asosiasi, dan ringkasan
    menyeluruh. Sengaja opsional: halaman hasil tetap lengkap tanpa menekan
    tombol ini, karena ringkasan berbasis aturan tetap ditampilkan.

    Variabel: $analysis, $section, $judul, $keterangan, $stored (array|null)
--}}
@php
    // $kueri: kueri tambahan pada URL, dipakai bagian 'segment' untuk
    // menyebut kolom mana yang sedang dibandingkan.
    $kueri = $kueri ?? '';
    $stored = $stored ?? null;
    $adaNarasi = is_array($stored) && ! empty($stored['narrative']);
    // Narasi lama (prompt versi 2) tidak punya kedua medan ini. Dibaca
    // defensif supaya hasil yang sudah tersimpan tidak perlu dibangkitkan
    // ulang hanya agar halamannya mau dirender.
    $mengapa = is_array($stored) ? trim((string) ($stored['why'] ?? '')) : '';
    $caraPakai = is_array($stored) && is_array($stored['how'] ?? null) ? $stored['how'] : [];
@endphp

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6"
     data-ai-block="{{ $section }}">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-3">
        <div>
            <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                {{ $judul }}
            </h4>
            @if(! empty($keterangan))
                <p class="text-sm text-gray-500 mt-1">{{ $keterangan }}</p>
            @endif
        </div>

        <button type="button"
                data-ai-interpret="{{ $section }}"
                data-ai-url="{{ route('analysis.interpret', ['id' => $analysis->id, 'section' => $section]).($kueri ?? '') }}"
                data-ai-url-base="{{ route('analysis.interpret', ['id' => $analysis->id, 'section' => $section]) }}"
                aria-controls="ai-hasil-{{ $section }}"
                class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium
                       bg-indigo-50 text-indigo-700 border border-indigo-200
                       hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-400
                       disabled:opacity-60 disabled:cursor-not-allowed">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <span data-ai-label>{{ $adaNarasi ? 'Bangkitkan Ulang' : 'Jelaskan dengan AI' }}</span>
        </button>
    </div>

    <p class="hidden text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-3"
       data-ai-error role="alert"></p>

    <div id="ai-hasil-{{ $section }}"
         data-ai-hasil
         aria-live="polite"
         class="{{ $adaNarasi ? '' : 'hidden' }}">

        <div class="bg-gradient-to-br from-indigo-50 to-white border border-indigo-100 rounded-lg p-4">
            <p class="text-sm text-gray-800 leading-relaxed" data-ai-narrative>{{ $stored['narrative'] ?? '' }}</p>

            <ul class="mt-3 space-y-1.5 {{ empty($stored['highlights']) ? 'hidden' : '' }}" data-ai-highlights>
                @foreach($stored['highlights'] ?? [] as $poin)
                    <li class="text-sm text-gray-700 flex gap-2">
                        <span class="text-indigo-400 mt-0.5" aria-hidden="true">&bull;</span>
                        <span>{{ $poin }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Mengapa bagian ini penting. Warna dan ikon dibedakan dari narasi
             supaya terbaca sebagai jenis keterangan yang lain, bukan lanjutan
             paragraf di atasnya. --}}
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-4 {{ $mengapa === '' ? 'hidden' : '' }}"
             data-ai-why-box>
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Mengapa ini penting
            </p>
            <p class="mt-1.5 text-sm text-amber-900 leading-relaxed" data-ai-why>{{ $mengapa }}</p>
        </div>

        {{-- Langkah konkret. Daftar bernomor, bukan butir: urutannya memang
             berarti, dan pembaca yang mencari "besok harus apa" membacanya
             sebagai antrean pekerjaan. --}}
        <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 {{ empty($caraPakai) ? 'hidden' : '' }}"
             data-ai-how-box>
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Cara memanfaatkan hasil ini
            </p>
            <ol class="mt-2 space-y-1.5" data-ai-how>
                @foreach($caraPakai as $nomor => $langkah)
                    <li class="text-sm text-emerald-900 flex gap-2">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full bg-emerald-200 text-emerald-900 text-xs font-bold flex items-center justify-center">{{ $nomor + 1 }}</span>
                        <span>{{ $langkah }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- Asal-usul narasi. Wajib tampil: pembaca harus tahu kalimat ini
             ditulis mesin, bukan hasil perhitungan pipeline. --}}
        <p class="mt-2 text-xs text-gray-500" data-ai-provenance>
            @if($adaNarasi)
                Ditulis oleh AI ({{ $stored['model'] ?? '-' }})
                @if(! empty($stored['generated_at']))
                    pada {{ \Carbon\Carbon::parse($stored['generated_at'])->translatedFormat('d M Y, H:i') }}
                @endif
                . Angka tetap berasal dari hasil analisis, bukan dari AI.
            @endif
        </p>
    </div>
</div>
