{{--
    Blok interpretasi AI untuk satu bagian hasil.

    Dipakai bersama oleh bagian sentimen, aspek, asosiasi, dan ringkasan
    menyeluruh. Sengaja opsional: halaman hasil tetap lengkap tanpa menekan
    tombol ini, karena ringkasan berbasis aturan tetap ditampilkan.

    Variabel: $analysis, $section, $judul, $keterangan, $stored (array|null)
--}}
@php
    $stored = $stored ?? null;
    $adaNarasi = is_array($stored) && ! empty($stored['narrative']);
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
                data-ai-url="{{ route('analysis.interpret', ['id' => $analysis->id, 'section' => $section]) }}"
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

        {{-- Asal-usul narasi. Wajib tampil: pembaca harus tahu kalimat ini
             ditulis mesin, bukan hasil perhitungan pipeline. --}}
        <p class="mt-2 text-xs text-gray-500" data-ai-provenance>
            @if($adaNarasi)
                Ditulis oleh AI ({{ $stored['model'] ?? '-' }})
                @if(! empty($stored['generated_at']))
                    pada {{ \Carbon\Carbon::parse($stored['generated_at'])->translatedFormat('d M Y, H:i') }}
                @endif
                &mdash; angka tetap berasal dari hasil analisis, bukan dari AI.
            @endif
        </p>
    </div>
</div>
