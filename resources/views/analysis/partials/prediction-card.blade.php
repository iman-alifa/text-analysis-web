{{--
    Satu kartu prediksi.

    Dipakai untuk render awal maupun pemuatan halaman berikutnya lewat AJAX,
    supaya markupnya tidak digandakan di JavaScript.

    @param array $prediction  Baris prediksi dari NLP API
    @param int   $position    Nomor urut yang ditampilkan
    @param int   $index       Indeks baris tersimpan, untuk id elemen
    @param int   $reviewRank  Peringkat antrean tinjauan, -1 bila tidak masuk
--}}
@php
    $sentiment = $prediction['sentiment'] ?? 'neutral';
    $method = $prediction['method'] ?? null;
    $processed = $prediction['processed_text'] ?? null;
    $punyaProcessed = filled($processed) && $processed !== ($prediction['text'] ?? null);
    $perluTinjau = $reviewRank >= 0;

    $warnaSentimen = match ($sentiment) {
        'positive' => 'bg-green-100 text-green-800',
        'negative' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-800',
    };
@endphp

<article class="prediction-card {{ $sentiment }} p-4 rounded-lg{{ $perluTinjau ? ' ring-1 ring-amber-300' : '' }}"
         data-sentiment="{{ $sentiment }}"
         data-review-rank="{{ $reviewRank }}">
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <p class="text-gray-800 leading-relaxed mb-2">{{ $prediction['text'] ?? '' }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $warnaSentimen }}">
                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <use href="#ikon-sentimen-{{ $sentiment }}"></use>
                    </svg>
                    {{ ucfirst($sentiment) }}
                </span>

                @isset($prediction['confidence'])
                <span class="text-xs text-gray-500">
                    Keyakinan: <strong>{{ round($prediction['confidence'] * 100, 1) }}%</strong>
                </span>
                @endisset

                @if($perluTinjau)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                    Perlu ditinjau
                </span>
                @endif

                {{-- Asal prediksi: model sungguhan atau jalur cadangan --}}
                @if($method && $method !== 'indobert')
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    @if($method === 'rule-based') bg-orange-100 text-orange-800
                    @elseif($method === 'error') bg-red-100 text-red-800
                    @else bg-gray-100 text-gray-600
                    @endif">
                    @if($method === 'rule-based') Tanpa model
                    @elseif($method === 'empty') Tidak dinilai
                    @else Gagal dinilai @endif
                </span>
                @endif

                @if($punyaProcessed)
                <button type="button"
                        onclick="toggleProcessedText({{ $index }})"
                        aria-controls="processed-text-{{ $index }}"
                        aria-expanded="false"
                        class="text-xs text-blue-600 hover:text-blue-800">
                    Lihat teks terproses
                </button>
                @endif
            </div>

            @if($punyaProcessed)
            <div id="processed-text-{{ $index }}" class="hidden mt-3 p-3 bg-gray-50 rounded border border-gray-200">
                <p class="text-xs text-gray-500 mb-1 font-semibold">Teks setelah preprocessing</p>
                <p class="text-sm text-gray-700 font-mono break-words">{{ $processed }}</p>
            </div>
            @endif

            @isset($prediction['scores'])
            <div class="mt-3 space-y-1">
                <p class="text-xs text-gray-500 font-semibold mb-2">Skor detail</p>
                @foreach($prediction['scores'] as $label => $score)
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-600 w-20 capitalize">{{ $label }}</span>
                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                        <div class="h-2 rounded-full
                            @if($label === 'positive') bg-green-500
                            @elseif($label === 'negative') bg-red-500
                            @else bg-gray-500
                            @endif"
                            style="width: {{ round($score * 100, 1) }}%"></div>
                    </div>
                    <span class="text-xs text-gray-600 w-12 text-right">{{ round($score * 100, 1) }}%</span>
                </div>
                @endforeach
            </div>
            @endisset
        </div>

        <div class="flex-shrink-0">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 text-gray-600 text-sm font-semibold">
                {{ $position }}
            </span>
        </div>
    </div>
</article>
