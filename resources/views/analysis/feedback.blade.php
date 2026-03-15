@extends('layouts.app')

@push('styles')
<style>
    /* Confidence meter animation */
    @keyframes fillBar {
        from { width: 0%; }
        to   { width: var(--target-width); }
    }

    .confidence-bar-fill {
        animation: fillBar 0.8s ease-out forwards;
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .fade-in-up {
        animation: fadeInUp 0.4s ease-out both;
    }

    .item-card {
        transition: box-shadow 0.2s, transform 0.2s;
    }
    .item-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.10);
        transform: translateY(-2px);
    }

    /* Sentiment selector buttons */
    .sentiment-btn { transition: all 0.15s; }
    .sentiment-btn.selected-positive  { @apply bg-green-600 text-white border-green-600; }
    .sentiment-btn.selected-negative  { @apply bg-red-600   text-white border-red-600;   }
    .sentiment-btn.selected-neutral   { @apply bg-gray-500  text-white border-gray-500;  }
</style>
@endpush

@section('content')
<div class="space-y-6 max-w-4xl mx-auto">

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- BREADCRUMB                                                         --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <nav class="flex items-center gap-2 text-sm text-gray-500">
        <a href="{{ route('analysis.index') }}" class="hover:text-blue-600 transition">Analisis</a>
        <span>/</span>
        <a href="{{ route('analysis.show', $analysis->id) }}" class="hover:text-blue-600 transition truncate max-w-xs">{{ $analysis->title }}</a>
        <span>/</span>
        <span class="text-gray-800 font-medium">Feedback</span>
    </nav>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- PERSUASION HEADER CARD                                             --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 rounded-2xl shadow-lg p-6 text-white fade-in-up">
        <div class="flex flex-col md:flex-row md:items-center gap-6">
            <div class="flex-1">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold leading-tight">Bantu Tingkatkan Akurasi Model AI</h1>
                        <p class="text-indigo-200 text-sm mt-0.5">Active Learning — Kontribusi Anda sangat berarti</p>
                    </div>
                </div>
                <p class="text-indigo-100 leading-relaxed">
                    Hasil analisis di bawah ini memiliki tingkat kepercayaan (<em>confidence</em>) yang bervariasi.
                    Teks dengan kepercayaan rendah paling membutuhkan masukan Anda. Setiap koreksi yang Anda
                    berikan langsung digunakan untuk melatih ulang model agar semakin akurat ke depannya.
                </p>
            </div>

            {{-- Contribution stats --}}
            <div class="bg-white/15 backdrop-blur rounded-xl p-4 text-center flex-shrink-0 min-w-[160px]">
                <p class="text-xs text-indigo-200 font-semibold uppercase tracking-wider mb-1">Kontribusi Anda</p>
                <p class="text-4xl font-extrabold">{{ $userCorrectionCount }}</p>
                <p class="text-indigo-200 text-sm mt-0.5">koreksi total</p>

                @if($userCorrectionCount >= 10)
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-yellow-400/30 text-yellow-200 rounded-full text-xs font-medium">
                    🏆 Expert Contributor
                </div>
                @elseif($userCorrectionCount >= 3)
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-green-400/30 text-green-200 rounded-full text-xs font-medium">
                    ⭐ Active Contributor
                </div>
                @else
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-white/20 text-indigo-200 rounded-full text-xs font-medium">
                    🌱 New Contributor
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- EDUCATIONAL INFO BOX                                               --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-5 fade-in-up" style="animation-delay:0.1s">
        <div class="flex gap-4">
            <svg class="w-6 h-6 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-sm text-blue-800 space-y-1">
                <p class="font-semibold">Apa itu Margin of Confidence?</p>
                <p>
                    Nilai <strong>confidence</strong> (0 – 1) menunjukkan seberapa yakin model terhadap prediksinya.
                    Nilai mendekati <span class="font-medium text-red-600">0 (merah)</span> berarti model ragu-ragu —
                    kemungkinan besar prediksinya salah dan membutuhkan koreksi Anda.
                    Nilai mendekati <span class="font-medium text-green-600">1 (hijau)</span> berarti model sangat yakin.
                </p>
                <div class="flex flex-wrap gap-3 pt-1">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span> &lt; 40% — Rendah, perlu koreksi
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-yellow-100 text-yellow-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-yellow-500"></span> 40–70% — Sedang
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span> &gt; 70% — Tinggi, sudah akurat
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- ANALYSIS META                                                      --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-wrap gap-4 items-center fade-in-up" style="animation-delay:0.15s">
        <div class="flex-1 min-w-0">
            <h2 class="text-lg font-semibold text-gray-900 truncate">{{ $analysis->title }}</h2>
            <p class="text-sm text-gray-500 mt-0.5">
                {{ $analysis->created_at->format('d M Y') }} &bull;
                {{ $analysis->total_records }} baris &bull;
                <span class="capitalize">{{ str_replace('_', ' ', $analysis->analysis_type) }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            @php
                $lowCount = $items->where('confidence_score', '<', 0.4)->count();
                $correctedCount = $items->where('is_corrected', true)->count();
            @endphp
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $lowCount }}</p>
                <p class="text-xs text-gray-500">Low confidence</p>
            </div>
            <div class="w-px h-10 bg-gray-200"></div>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $correctedCount }}</p>
                <p class="text-xs text-gray-500">Sudah dikoreksi</p>
            </div>
            <div class="w-px h-10 bg-gray-200"></div>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-800">{{ $items->count() }}</p>
                <p class="text-xs text-gray-500">Total baris</p>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- FEEDBACK FORM                                                      --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    @if($items->isEmpty())
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center fade-in-up">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p class="text-gray-500">Belum ada data training untuk analisis ini.</p>
        <p class="text-sm text-gray-400 mt-1">Pastikan analisis telah selesai dan memiliki prediksi.</p>
    </div>
    @else

    <form method="POST" action="{{ route('analysis.feedback.store', $analysis->id) }}" id="feedbackForm">
        @csrf

        <div class="space-y-4">
            @foreach($items as $index => $item)
            @php
                $conf        = (float) $item->confidence_score;
                $confPct     = round($conf * 100);
                $isLow       = $conf < 0.4;
                $isMod       = $conf >= 0.4 && $conf < 0.7;
                $isHigh      = $conf >= 0.7;

                $barColor    = $isLow  ? 'bg-red-500'    : ($isMod ? 'bg-yellow-500' : 'bg-green-500');
                $badgeBg     = $isLow  ? 'bg-red-100 text-red-700'     : ($isMod ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700');
                $badgeLabel  = $isLow  ? 'Rendah'        : ($isMod ? 'Sedang'       : 'Tinggi');
                $cardBorder  = $isLow  ? 'border-red-200'              : ($isMod ? 'border-yellow-200'            : 'border-gray-100');
                $delay       = ($index % 10) * 0.04;
            @endphp

            <div class="item-card bg-white rounded-xl shadow-sm border {{ $cardBorder }} p-5 fade-in-up {{ $item->is_corrected ? 'opacity-75' : '' }}"
                 style="animation-delay: {{ $delay }}s">

                {{-- Hidden item id --}}
                <input type="hidden" name="corrections[{{ $index }}][item_id]" value="{{ $item->id }}">

                <div class="flex flex-col md:flex-row gap-4">
                    {{-- LEFT: Text + confidence --}}
                    <div class="flex-1 min-w-0">
                        {{-- Text content --}}
                        <p class="text-gray-800 leading-relaxed text-sm">{{ $item->text_content }}</p>

                        {{-- Confidence meter --}}
                        <div class="mt-3">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-gray-500 font-medium">Confidence Model</span>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold {{ $isLow ? 'text-red-600' : ($isMod ? 'text-yellow-600' : 'text-green-600') }}">
                                        {{ $confPct }}%
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $badgeBg }}">
                                        {{ $badgeLabel }}
                                    </span>
                                </div>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2.5">
                                <div class="{{ $barColor }} h-2.5 rounded-full confidence-bar-fill"
                                     style="--target-width: {{ $confPct }}%; width: 0%">
                                </div>
                            </div>
                        </div>

                        {{-- AI prediction --}}
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span>Prediksi AI:</span>
                            <span class="px-2 py-0.5 rounded-full font-medium
                                @if($item->predicted_sentiment == 'positive') bg-green-100 text-green-700
                                @elseif($item->predicted_sentiment == 'negative') bg-red-100 text-red-700
                                @else bg-gray-100 text-gray-700
                                @endif">
                                {{ ucfirst($item->predicted_sentiment ?? 'neutral') }}
                            </span>
                            @if(!empty($item->detected_aspects))
                            <span class="text-gray-400">Aspek: {{ implode(', ', $item->detected_aspects) }}</span>
                            @endif

                            @if($item->is_corrected)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-full font-medium">
                                ✓ Sudah dikoreksi
                            </span>
                            @endif
                        </div>
                    </div>

                    {{-- RIGHT: Correction controls --}}
                    <div class="md:w-64 flex-shrink-0 space-y-3">
                        {{-- Sentiment correction --}}
                        @if(in_array($analysis->analysis_type, ['sentiment', 'combined']))
                        <div>
                            <p class="text-xs font-medium text-gray-600 mb-1.5">Koreksi Sentimen</p>
                            <div class="flex gap-1.5">
                                @foreach(['positive' => ['😊','text-green-700','border-green-300'], 'neutral' => ['😐','text-gray-600','border-gray-300'], 'negative' => ['😞','text-red-700','border-red-300']] as $sent => [$emoji, $textColor, $borderColor])
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio"
                                           name="corrections[{{ $index }}][corrected_sentiment]"
                                           value="{{ $sent }}"
                                           class="sr-only peer"
                                           {{ ($item->corrected_sentiment ?? $item->predicted_sentiment) == $sent ? 'checked' : '' }}>
                                    <div class="sentiment-btn text-center py-2 rounded-lg border {{ $borderColor }} text-xs font-medium {{ $textColor }}
                                                peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600
                                                hover:bg-gray-50 transition">
                                        {{ $emoji }}<br>{{ ucfirst($sent) }}
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        </div>
                        @else
                        <input type="hidden" name="corrections[{{ $index }}][corrected_sentiment]" value="{{ $item->corrected_sentiment ?? $item->predicted_sentiment }}">
                        @endif

                        {{-- Aspect correction --}}
                        @if(in_array($analysis->analysis_type, ['aspect', 'combined']))
                        <div>
                            <label class="text-xs font-medium text-gray-600 block mb-1">
                                Koreksi Aspek
                                <span class="text-gray-400 font-normal">(pisahkan dengan koma)</span>
                            </label>
                            <input type="text"
                                   name="corrections[{{ $index }}][corrected_aspects]"
                                   value="{{ $item->corrected_aspects ? implode(', ', $item->corrected_aspects) : (is_array($item->detected_aspects) ? implode(', ', $item->detected_aspects) : '') }}"
                                   placeholder="harga, kualitas, pelayanan..."
                                   class="w-full text-xs px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        </div>
                        @endif

                        {{-- Notes --}}
                        <div>
                            <label class="text-xs font-medium text-gray-600 block mb-1">Catatan <span class="text-gray-400 font-normal">(opsional)</span></label>
                            <textarea name="corrections[{{ $index }}][correction_notes]"
                                      rows="2"
                                      placeholder="Alasan koreksi..."
                                      class="w-full text-xs px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none">{{ $item->correction_notes ?? '' }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- SUBMIT                                                         --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <div class="sticky bottom-4 mt-6">
            <div class="bg-white border border-indigo-200 rounded-2xl shadow-lg p-4 flex flex-col sm:flex-row items-center justify-between gap-4 fade-in-up">
                <div>
                    <p class="font-semibold text-gray-900">Siap mengirimkan koreksi?</p>
                    <p class="text-sm text-gray-500">Setiap koreksi secara langsung berkontribusi pada peningkatan model.</p>
                </div>
                <div class="flex gap-3 flex-shrink-0">
                    <a href="{{ route('analysis.show', $analysis->id) }}"
                       class="px-5 py-2.5 border border-gray-300 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                        Batalkan
                    </a>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 active:scale-95 transition shadow-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M5 13l4 4L19 7"/>
                        </svg>
                        Kirim Koreksi &amp; Tingkatkan Model
                    </button>
                </div>
            </div>
        </div>
    </form>

    @endif

</div>
@endsection
