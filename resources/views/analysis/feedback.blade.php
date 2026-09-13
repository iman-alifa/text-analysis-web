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
                        <p class="text-indigo-200 text-sm mt-0.5">Active Learning - Kontribusi Anda sangat berarti</p>
                    </div>
                </div>
                <p class="text-indigo-100 leading-relaxed">
                    Hasil analisis di bawah ini memiliki tingkat kepercayaan (<em>confidence</em>) yang bervariasi.
                    Teks dengan kepercayaan rendah paling membutuhkan masukan Anda. Setiap koreksi yang Anda
                    berikan dipakai untuk melatih ulang model agar semakin akurat ke depannya.
                </p>
            </div>

            {{-- Contribution stats --}}
            <div class="bg-white/15 backdrop-blur rounded-xl p-4 text-center flex-shrink-0 min-w-[160px]">
                <p class="text-xs text-indigo-200 font-semibold uppercase tracking-wider mb-1">Kontribusi Anda</p>
                <p class="text-4xl font-extrabold">{{ $userCorrectionCount }}</p>
                <p class="text-indigo-200 text-sm mt-0.5">koreksi total</p>

                @if($userCorrectionCount >= 10)
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-yellow-400/30 text-yellow-200 rounded-full text-xs font-medium">
                    🏆 Kontributor Ahli
                </div>
                @elseif($userCorrectionCount >= 3)
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-green-400/30 text-green-200 rounded-full text-xs font-medium">
                    ⭐ Kontributor Aktif
                </div>
                @else
                <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 bg-white/20 text-indigo-200 rounded-full text-xs font-medium">
                    🌱 Kontributor Baru
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
                <p class="font-semibold">Apa itu Confidence Score?</p>
                <p>
                    Nilai <strong>confidence</strong> (0 - 1) menunjukkan seberapa yakin model terhadap prediksinya.
                    Nilai mendekati <span class="font-medium text-red-600">0 (merah)</span> berarti model ragu-ragu -
                    kemungkinan besar prediksinya salah dan membutuhkan koreksi Anda.
                    Nilai mendekati <span class="font-medium text-green-600">1 (hijau)</span> berarti model sangat yakin.
                </p>
                <div class="flex flex-wrap gap-3 pt-1">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span> &lt; 40% - Rendah, perlu koreksi
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-yellow-100 text-yellow-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-yellow-500"></span> 40-70% - Sedang
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span> &gt; 70% - Tinggi, sudah akurat
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

    @if(!empty($evaluation))
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4 fade-in-up" style="animation-delay:0.18s">
        <div>
            <h3 class="text-lg font-bold text-gray-900">Rangkuman Evaluasi Model Dokumen Ini</h3>
            <p class="text-sm text-gray-500">
                Metrik dihitung dari data yang sudah dikoreksi.
                <span class="font-medium text-indigo-600">{{ $evaluation['corrected_total'] ?? 0 }} baris tervalidasi</span>.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @if(!empty($evaluation['sentiment']))
            <div class="rounded-xl border border-blue-100 bg-blue-50/60 p-4">
                <h4 class="font-semibold text-blue-900 text-sm mb-3">Klasifikasi Sentimen</h4>
                @if($evaluation['sentiment']['available'] ?? false)
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="bg-white border border-blue-100 rounded-lg p-2">
                            <p class="text-gray-500">Accuracy</p>
                            <p class="font-bold text-blue-700">{{ $evaluation['sentiment']['accuracy'] }}%</p>
                        </div>
                        <div class="bg-white border border-blue-100 rounded-lg p-2">
                            <p class="text-gray-500">Weighted F1</p>
                            <p class="font-bold text-blue-700">{{ $evaluation['sentiment']['weighted_f1'] }}%</p>
                        </div>
                        <div class="bg-white border border-blue-100 rounded-lg p-2">
                            <p class="text-gray-500">Weighted Precision</p>
                            <p class="font-bold text-blue-700">{{ $evaluation['sentiment']['weighted_precision'] }}%</p>
                        </div>
                        <div class="bg-white border border-blue-100 rounded-lg p-2">
                            <p class="text-gray-500">Weighted Recall</p>
                            <p class="font-bold text-blue-700">{{ $evaluation['sentiment']['weighted_recall'] }}%</p>
                        </div>
                    </div>
                    <p class="text-[11px] text-blue-800 mt-2">Weighted metrics memberikan bobot proporsional per kelas sentimen, jadi evaluasi lebih akurat saat distribusi label tidak seimbang.</p>
                @else
                    <p class="text-sm text-blue-700">{{ $evaluation['sentiment']['message'] ?? 'Data sentimen belum tersedia.' }}</p>
                @endif
            </div>
            @endif

            @if(!empty($evaluation['aspect']))
            <div class="rounded-xl border border-orange-100 bg-orange-50/60 p-4">
                <h4 class="font-semibold text-orange-900 text-sm mb-3">Ekstraksi Aspek</h4>
                @if($evaluation['aspect']['available'] ?? false)
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        {{-- <div class="bg-white border border-orange-100 rounded-lg p-2">
                            <p class="text-gray-500">Exact Match</p>
                            <p class="font-bold text-orange-700">{{ $evaluation['aspect']['exact_match'] }}%</p>
                        </div> --}}
                        <div class="bg-white border border-orange-100 rounded-lg p-2">
                            <p class="text-gray-500">Precision</p>
                            <p class="font-bold text-orange-700">{{ $evaluation['aspect']['precision'] }}%</p>
                        </div>
                        <div class="bg-white border border-orange-100 rounded-lg p-2">
                            <p class="text-gray-500">Recall</p>
                            <p class="font-bold text-orange-700">{{ $evaluation['aspect']['recall'] }}%</p>
                        </div>
                        <div class="bg-white border border-orange-100 rounded-lg p-2">
                            <p class="text-gray-500">F1</p>
                            <p class="font-bold text-orange-700">{{ $evaluation['aspect']['f1'] }}%</p>
                        </div>
                    </div>
                    <p class="text-[11px] text-orange-800 mt-2">
                        TP: {{ $evaluation['aspect']['tp'] }} &bull;
                        FP: {{ $evaluation['aspect']['fp'] }} &bull;
                        FN: {{ $evaluation['aspect']['fn'] }}
                    </p>
                @else
                    <p class="text-sm text-orange-700">{{ $evaluation['aspect']['message'] ?? 'Data aspek belum tersedia.' }}</p>
                @endif
            </div>
            @endif

            @if(!empty($evaluation['topic']))
            <div class="rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
                <h4 class="font-semibold text-indigo-900 text-sm mb-3">Identifikasi Topik</h4>
                @if($evaluation['topic']['available'] ?? false)
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="bg-white border border-indigo-100 rounded-lg p-2">
                            <p class="text-gray-500">Jumlah Topik</p>
                            <p class="font-bold text-indigo-700">{{ $evaluation['topic']['topic_count'] }}</p>
                        </div>
                        <div class="bg-white border border-indigo-100 rounded-lg p-2">
                            <p class="text-gray-500">
                                Coherence Score
                                @if(($evaluation['topic']['source'] ?? null) === 'nlp-api')
                                    <span class="text-[10px] text-gray-400">(c<sub>v</sub>)</span>
                                @endif
                            </p>
                            <p class="font-bold text-indigo-700">{{ $evaluation['topic']['coherence_score'] }}</p>
                        </div>
                        <div class="bg-white border border-indigo-100 rounded-lg p-2">
                            <p class="text-gray-500">Kualitas Topik</p>
                            <p class="font-bold text-indigo-700">{{ $evaluation['topic']['coherence_label'] }}</p>
                        </div>
                        <div class="bg-white border border-indigo-100 rounded-lg p-2">
                            <p class="text-gray-500">Pasangan Kata</p>
                            <p class="font-bold text-indigo-700">
                                {{ $evaluation['topic']['coherence_pairs'] ?? '-' }}
                            </p>
                        </div>
                    </div>
                    <p class="text-[11px] text-indigo-800 mt-2">
                        Semakin tinggi coherence score, semakin konsisten keterkaitan kata dalam topik.
                    </p>
                @else
                    <p class="text-sm text-indigo-700">{{ $evaluation['topic']['message'] ?? 'Data topik belum tersedia.' }}</p>
                @endif
            </div>
            @endif
        </div>
    </div>
    @endif

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
        {{-- Seluruh koreksi analisis ini dikirim lewat satu medan JSON, bukan
             sebagai medan formulir per baris. Empat medan x 262 koreksi = 1.048
             variabel, sementara max_input_vars PHP bawaannya 1.000 - sisanya
             dibuang diam-diam. Medan per baris di bawah tetap ada sebagai jalur
             tanpa JavaScript, dan di jalur itu jumlahnya selalu satu halaman. --}}
        <input type="hidden" name="corrections_json" id="corrections_json">
        @csrf
        <input type="hidden" name="page" value="{{ $items->currentPage() }}">
        <input type="hidden" name="fokus" value="{{ $fokusInfo['aktif'] }}">

        {{-- Penyaring fokus. Pengguna yang datang dari ajakan di halaman hasil
             langsung berada pada "sangat ragu" - ajakan active learning
             sengaja hanya meminta pita paling meragukan dulu, bukan seluruh
             "ragu" sekaligus (lihat ConfidenceReportService::build). Tetap
             bisa berpindah ke pita yang lebih luas atau daftar penuh tanpa
             kehilangan konteks. --}}
        <div class="mb-4 flex items-center gap-2 flex-wrap" role="group" aria-label="Saring baris koreksi">
            <a href="{{ route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']) }}"
               aria-pressed="{{ $fokusInfo['aktif'] === 'sangat-ragu' ? 'true' : 'false' }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium border
                      {{ $fokusInfo['aktif'] === 'sangat-ragu'
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Sangat ragu ({{ number_format($fokusInfo['jumlah_sangat_ragu']) }})
            </a>
            <a href="{{ route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'ragu']) }}"
               aria-pressed="{{ $fokusInfo['aktif'] === 'ragu' ? 'true' : 'false' }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium border
                      {{ $fokusInfo['aktif'] === 'ragu'
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Baris yang ragu ({{ number_format($fokusInfo['jumlah_ragu']) }})
            </a>
            <a href="{{ route('analysis.feedback', $analysis->id) }}"
               aria-pressed="{{ $fokusInfo['aktif'] === 'semua' ? 'true' : 'false' }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium border
                      {{ $fokusInfo['aktif'] === 'semua'
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Semua baris ({{ number_format($fokusInfo['jumlah_semua']) }})
            </a>

            <span class="text-xs text-gray-500 ml-1">
                &ldquo;Sangat ragu&rdquo; = keyakinan di bawah {{ number_format($fokusInfo['ambang_sangat_ragu'] * 100, 0) }}%,
                &ldquo;Ragu&rdquo; = di bawah {{ number_format($fokusInfo['ambang'] * 100, 0) }}%
            </span>
        </div>

        {{-- Baris uji disembunyikan, dan jumlahnya disebut supaya angkanya tidak
             terlihat tidak cocok dengan jumlah prediksi di halaman hasil.
             Kalimatnya sengaja netral: mengoreksinya memang tidak akan berguna,
             bukan karena barisnya bermasalah. --}}
        @if(($fokusInfo['disembunyikan_uji'] ?? 0) > 0)
        <p class="mb-4 text-xs text-gray-500">
            {{ number_format($fokusInfo['disembunyikan_uji']) }} baris tidak ditampilkan karena
            dipakai sebagai data uji evaluasi.
        </p>
        @endif

        @if($fokusInfo['aktif'] === 'sangat-ragu' && $fokusInfo['jumlah_sangat_ragu'] === 0)
        <p class="mb-4 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg p-4">
            Tidak ada baris dengan keyakinan di bawah
            {{ number_format($fokusInfo['ambang_sangat_ragu'] * 100, 0) }}% pada analisis ini.
            Anda tetap bisa membuka <a href="{{ route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'ragu']) }}" class="text-indigo-700 underline">baris yang ragu</a>
            atau <a href="{{ route('analysis.feedback', $analysis->id) }}" class="text-indigo-700 underline">semua baris</a>.
        </p>
        @endif

        @if($fokusInfo['aktif'] === 'ragu' && $fokusInfo['jumlah_ragu'] === 0)
        <p class="mb-4 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg p-4">
            Tidak ada baris dengan keyakinan di bawah
            {{ number_format($fokusInfo['ambang'] * 100, 0) }}% pada analisis ini.
            Anda tetap bisa membuka <a href="{{ route('analysis.feedback', $analysis->id) }}" class="text-indigo-700 underline">semua baris</a>.
        </p>
        @endif

        {{-- Kemajuan koreksi. Tanpa ini pengguna tidak punya rasa "tinggal
             sedikit lagi" dan cenderung berhenti di tengah. --}}
        @php
            $persenKoreksi = $fokusInfo['jumlah_semua'] > 0
                ? round(($fokusInfo['sudah_dikoreksi'] / $fokusInfo['jumlah_semua']) * 100)
                : 0;
        @endphp
        <div class="mb-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                Kemajuan pada analisis ini
            </p>
            <div class="flex items-center justify-between text-sm mb-1">
                <span class="text-gray-600">{{ number_format($fokusInfo['sudah_dikoreksi']).' dari '.number_format($fokusInfo['jumlah_semua']).' baris sudah dikoreksi' }}</span>
                <span class="text-gray-500">{{ $persenKoreksi }}%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2" role="img"
                 aria-label="Kemajuan koreksi {{ $persenKoreksi }} persen">
                <div class="h-2 rounded-full bg-indigo-600" style="width: {{ $persenKoreksi }}%"></div>
            </div>
        </div>

        <div class="mb-4 flex items-center justify-between gap-4 flex-wrap">
            <p class="text-sm text-gray-600">
                Menampilkan {{ $items->firstItem() ?? 0 }}-{{ $items->lastItem() ?? 0 }}
                dari {{ $items->total() }} baris, diurutkan dari yang paling tidak yakin.
            </p>
            <p class="text-sm text-gray-500">
                Halaman {{ $items->currentPage() }} dari {{ max($items->lastPage(), 1) }}
            </p>
        </div>

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
                $sentimentOptions = [
                    'positive' => ['😊', 'text-green-700', 'border-green-300'],
                    'neutral' => ['😐', 'text-gray-600', 'border-gray-300'],
                    'negative' => ['😞', 'text-red-700', 'border-red-300'],
                ];
            @endphp

            <div class="item-card bg-white rounded-xl shadow-sm border {{ $cardBorder }} p-5 fade-in-up {{ $item->is_corrected ? 'opacity-75' : '' }}"
                 style="animation-delay: {{ $delay }}s">

                {{-- Hidden item id --}}
                <input type="hidden" name="corrections[{{ $index }}][item_id]" value="{{ $item->id }}">

                <div class="flex flex-col md:flex-row gap-4">
                    {{-- LEFT: Text + confidence --}}
                    <div class="flex-1 min-w-0">
                        {{-- Text content. Bisa diblok untuk menambah aspek (maks. 3
                             kata) - lihat data-aspect-source, sama seperti komentar
                             pada halaman anotasi (public/anotasi/index.html). --}}
                        <p class="text-gray-800 leading-relaxed text-sm" data-aspect-source="aspek-{{ $item->id }}">{{ $item->text_content }}</p>

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
                                @foreach($sentimentOptions as $sent => [$emoji, $textColor, $borderColor])
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio"
                                           name="corrections[{{ $index }}][corrected_sentiment]"
                                           value="{{ $sent }}"
                                           class="sr-only peer"
                                           {{-- Sengaja TIDAK otomatis terpilih pada baris
                                                yang belum ditinjau: kalau terpilih, menekan
                                                Simpan akan menandai seluruh baris di halaman
                                                ini sebagai "sudah dikoreksi manusia", termasuk
                                                yang tidak pernah dibaca siapa pun - dan baris
                                                itu ikut terkirim sebagai data latih. --}}
                                           {{ $item->is_corrected && $item->corrected_sentiment == $sent ? 'checked' : '' }}>
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
                            {{-- Placeholder ini cuma tampil kalau modelnya sama sekali
                                 tidak mendeteksi aspek (value di bawah kosong). Dulu
                                 memakai contoh nama aspek nyata ("harga, kualitas,
                                 pelayanan...") yang gampang dikira aspek yang memang
                                 sudah terdeteksi. Kini kalimat instruksi, bukan contoh
                                 data, dan digayakan miring/lebih pudar supaya beda jelas
                                 dari aspek asli yang tersimpan sebagai value. --}}
                            <input type="text"
                                   id="aspek-{{ $item->id }}"
                                   name="corrections[{{ $index }}][corrected_aspects]"
                                   value="{{ $item->corrected_aspects ? implode(', ', $item->corrected_aspects) : (is_array($item->detected_aspects) ? implode(', ', $item->detected_aspects) : '') }}"
                                   placeholder="Belum ada aspek terdeteksi - isi kalau ada, pisahkan dengan koma"
                                   class="w-full text-xs px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent placeholder:italic placeholder:text-gray-400">
                            <p class="mt-1 text-[11px] text-gray-400">Atau blok kata pada teks di sebelah kiri (maks. 3 kata) untuk menambahkannya.</p>
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
                    {{-- Hitungannya wajib terlihat. Bug yang membuat 218 koreksi
                         menyusut jadi 19 tidak pernah ketahuan justru karena
                         tidak ada satu pun angka di layar yang bisa dibandingkan
                         pengguna dengan yang dikerjakannya. --}}
                    <p class="text-sm text-gray-500" id="ringkasKirim">
                        Setiap koreksi secara langsung berkontribusi pada peningkatan model.
                    </p>
                </div>
                <div class="flex gap-3 flex-shrink-0">
                    <a href="{{ route('analysis.show', $analysis->id) }}"
                       class="px-5 py-2.5 border border-gray-300 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                        Batalkan
                    </a>
                    <button type="submit"
                            id="btnKirimKoreksi"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 active:scale-95 transition shadow-md
                                   disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-indigo-600 disabled:active:scale-100">
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

    {{-- Id baris yang BENAR-BENAR tersimpan pada kiriman terakhir. Dipakai
         peramban untuk menghapus drafnya, dan hanya itu. Draf tidak pernah
         dihapus optimistis saat menekan Kirim: kalau kirimannya gagal di tengah
         jalan, pekerjaan pengguna harus tetap ada saat halaman dibuka lagi. --}}
    <script type="application/json" id="koreksi-tersimpan">@json(session('koreksi_tersimpan', []))</script>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('feedbackForm');
        const tombol = document.getElementById('btnKirimKoreksi');

        if (!form || !tombol) {
            return;
        }

        // Nilai awal tiap kolom, untuk membedakan "diisi bawaan" dari
        // "diubah pengguna". Kolom aspek selalu terisi hasil deteksi model,
        // jadi terisinya saja bukan tanda pengguna meninjau baris itu.
        //
        // Diambil SEBELUM draf dipulihkan di bawah: kalau tidak, nilai hasil
        // pemulihan akan tercatat seolah "nilai awal dari server", dan
        // tombol Kirim Koreksi tidak pernah menyala untuk baris yang
        // koreksinya sebenarnya cuma dipulihkan dari draf.
        const awal = new Map();

        form.querySelectorAll('input[type=text], textarea').forEach(function (el) {
            awal.set(el, el.value);
        });

        // ============================================================
        // Draf otomatis - supaya koreksi yang belum dikirim tidak hilang
        // kalau halaman ini di-refresh atau tertutup tidak sengaja.
        //
        // Disimpan di localStorage peramban (sama seperti halaman anotasi,
        // public/anotasi/index.html), per item_id - bukan per halaman/fokus -
        // supaya draf tetap ketemu walau pengguna berpindah halaman atau
        // penyaring sebelum sempat mengirim. Tidak dikirim ke mana pun.
        // ============================================================
        const KUNCI_DRAF = 'koreksi-draf:{{ $analysis->id }}';

        function bacaDraf() {
            try {
                return JSON.parse(localStorage.getItem(KUNCI_DRAF) || '{}');
            } catch (e) {
                return {};
            }
        }

        function tulisDraf(draf) {
            try {
                localStorage.setItem(KUNCI_DRAF, JSON.stringify(draf));
            } catch (e) {
                // Penuh atau diblokir peramban - draf tidak tersimpan, tetapi
                // halaman tetap harus berfungsi seperti biasa.
            }
        }

        function idBarisDari(kartu) {
            const el = kartu.querySelector('input[name$="[item_id]"]');

            return el ? el.value : null;
        }

        function simpanBarisKeDraf(kartu) {
            const id = idBarisDari(kartu);
            if (!id) {
                return;
            }

            const radioTerpilih = kartu.querySelector('input[type=radio]:checked');
            const aspekInput = kartu.querySelector('input[type=text][name$="[corrected_aspects]"]');
            const catatanInput = kartu.querySelector('textarea[name$="[correction_notes]"]');

            const draf = bacaDraf();
            draf[id] = {
                corrected_sentiment: radioTerpilih ? radioTerpilih.value : null,
                corrected_aspects: aspekInput ? aspekInput.value : null,
                correction_notes: catatanInput ? catatanInput.value : null,
            };
            tulisDraf(draf);
        }

        // Pulihkan draf yang ada SEBELUM listener autosave dipasang, supaya
        // pemulihan itu sendiri tidak langsung tertulis ulang sebagai draf
        // "baru" dengan nilai yang persis sama.
        (function pulihkanDraf() {
            const draf = bacaDraf();
            if (Object.keys(draf).length === 0) {
                return;
            }

            form.querySelectorAll('.item-card').forEach(function (kartu) {
                const id = idBarisDari(kartu);
                const barisDraf = id ? draf[id] : null;
                if (!barisDraf) {
                    return;
                }

                if (barisDraf.corrected_sentiment) {
                    const radio = kartu.querySelector(
                        'input[type=radio][value="' + barisDraf.corrected_sentiment + '"]'
                    );
                    if (radio) {
                        radio.checked = true;
                    }
                }

                if (barisDraf.corrected_aspects !== null && barisDraf.corrected_aspects !== undefined) {
                    const aspekInput = kartu.querySelector('input[type=text][name$="[corrected_aspects]"]');
                    if (aspekInput) {
                        aspekInput.value = barisDraf.corrected_aspects;
                    }
                }

                if (barisDraf.correction_notes !== null && barisDraf.correction_notes !== undefined) {
                    const catatanInput = kartu.querySelector('textarea[name$="[correction_notes]"]');
                    if (catatanInput) {
                        catatanInput.value = barisDraf.correction_notes;
                    }
                }
            });
        })();

        // ============================================================
        // Bersihkan draf milik baris yang SERVER konfirmasi tersimpan.
        //
        // Dulu draf dihapus begitu tombol Kirim ditekan, untuk baris pada
        // halaman yang sedang tampil - optimistis pada dua hal sekaligus:
        // bahwa kirimannya berhasil, dan bahwa setiap baris terkirim memang
        // disimpan (padahal baris yang tidak benar-benar ditinjau dilewati
        // server). Sekarang yang dihapus persis daftar id yang dibalas server.
        // ============================================================
        (function bersihkanYangTersimpan() {
            const wadah = document.getElementById('koreksi-tersimpan');
            if (!wadah) {
                return;
            }

            let tersimpan = [];
            try {
                tersimpan = JSON.parse(wadah.textContent || '[]');
            } catch (e) {
                return;
            }

            if (!Array.isArray(tersimpan) || tersimpan.length === 0) {
                return;
            }

            const draf = bacaDraf();
            tersimpan.forEach(function (id) {
                delete draf[String(id)];
            });
            tulisDraf(draf);
        })();

        // ============================================================
        // Kirim SELURUH koreksi analisis ini, bukan hanya halaman yang tampil.
        //
        // Inilah perbaikan utamanya. Draf peramban menyimpan koreksi per
        // item_id lintas halaman, tetapi formulir hanya memuat medan untuk 25
        // baris yang sedang dirender - jadi koreksi halaman sebelumnya tidak
        // pernah sampai ke server. Terukur pada studi kasus: 218 koreksi
        // tersimpan, 19 yang dilatihkan.
        //
        // Nilai pada halaman yang sedang tampil menang atas draf: itu yang
        // barusan dilihat dan disunting pengguna.
        // ============================================================
        function kumpulkanSemua() {
            const gabungan = {};
            const draf = bacaDraf();

            Object.keys(draf).forEach(function (id) {
                const baris = draf[id] || {};
                gabungan[id] = {
                    item_id: Number(id),
                    corrected_sentiment: baris.corrected_sentiment || null,
                    corrected_aspects: baris.corrected_aspects || null,
                    correction_notes: baris.correction_notes || null,
                };
            });

            form.querySelectorAll('.item-card').forEach(function (kartu) {
                const id = idBarisDari(kartu);
                if (!id) {
                    return;
                }

                const radio = kartu.querySelector('input[type=radio]:checked');
                const aspek = kartu.querySelector('input[type=text][name$="[corrected_aspects]"]');
                const catatan = kartu.querySelector('textarea[name$="[correction_notes]"]');

                gabungan[id] = {
                    item_id: Number(id),
                    corrected_sentiment: radio ? radio.value : null,
                    corrected_aspects: aspek ? aspek.value : null,
                    correction_notes: catatan ? catatan.value : null,
                };
            });

            return Object.keys(gabungan).map(function (id) {
                return gabungan[id];
            });
        }

        const medanJson = document.getElementById('corrections_json');

        form.addEventListener('submit', function () {
            if (medanJson) {
                medanJson.value = JSON.stringify(kumpulkanSemua());
            }
        });

        // Berapa baris berdraf yang TIDAK sedang tampil di halaman ini.
        // Angka inilah yang dulu hilang tanpa jejak.
        function drafLuarHalaman() {
            const draf = bacaDraf();
            const idTampil = new Set();

            form.querySelectorAll('.item-card').forEach(function (kartu) {
                const id = idBarisDari(kartu);
                if (id) {
                    idTampil.add(String(id));
                }
            });

            return Object.keys(draf).filter(function (id) {
                if (idTampil.has(id)) {
                    return false;
                }

                const baris = draf[id] || {};

                return Boolean(baris.corrected_sentiment)
                    || Boolean((baris.corrected_aspects || '').trim())
                    || Boolean((baris.correction_notes || '').trim());
            }).length;
        }

        function adaKoreksi() {
            const radioTerpilih = form.querySelector('input[type=radio]:checked');

            if (radioTerpilih) {
                return true;
            }

            for (const [el, nilaiAwal] of awal) {
                if (el.value !== nilaiAwal) {
                    return true;
                }
            }

            // Draf dari halaman lain juga akan ikut terkirim, jadi tombolnya
            // harus menyala walau halaman ini belum disentuh sama sekali.
            // Tanpa ini, koreksi halaman sebelumnya terkunci di peramban.
            return drafLuarHalaman() > 0;
        }

        const ringkas = document.getElementById('ringkasKirim');

        function perbarui() {
            // Aturannya sama dengan yang dipakai server saat menyimpan, jadi
            // tombol tidak pernah aktif untuk kiriman yang toh akan kosong.
            tombol.disabled = !adaKoreksi();

            if (!ringkas) {
                return;
            }

            const luar = drafLuarHalaman();

            ringkas.textContent = luar > 0
                ? 'Termasuk ' + luar + ' koreksi dari halaman lain yang belum terkirim. '
                    + 'Semuanya ikut dalam satu kiriman ini.'
                : 'Setiap koreksi secara langsung berkontribusi pada peningkatan model.';
        }

        function tanganiPerubahan(e) {
            perbarui();

            const kartu = e.target.closest('.item-card');
            if (kartu) {
                simpanBarisKeDraf(kartu);
            }
        }

        form.addEventListener('input', tanganiPerubahan);
        form.addEventListener('change', tanganiPerubahan);
        perbarui();

        // Blok kata pada teks untuk menambahkannya sebagai aspek - mekanisme
        // yang sama dengan komentar pada halaman anotasi (public/anotasi/index.html):
        // ejaannya persis sama dengan yang tertulis, jadi tidak ada aspek yang
        // meleset karena salah ketik, dan lebih cepat daripada mengetik ulang.
        // Dibatasi maksimal tiga kata supaya menyeret kalimat panjang tidak
        // tercatat sebagai aspek.
        form.querySelectorAll('[data-aspect-source]').forEach(function (teks) {
            teks.addEventListener('mouseup', function () {
                const aspekInput = document.getElementById(teks.dataset.aspectSource);
                if (!aspekInput) {
                    return;
                }

                const pilihan = String(window.getSelection()).trim();

                if (pilihan.length <= 1 || pilihan.split(/\s+/).length > 3) {
                    return;
                }

                const daftar = aspekInput.value
                    .split(',')
                    .map(function (a) { return a.trim(); })
                    .filter(Boolean);

                const sudahAda = daftar.some(function (a) {
                    return a.toLowerCase() === pilihan.toLowerCase();
                });

                if (!sudahAda) {
                    daftar.push(pilihan);
                    aspekInput.value = daftar.join(', ');
                    // dispatchEvent, bukan mengandalkan browser: mengubah .value
                    // lewat skrip tidak memicu event 'input' dengan sendirinya,
                    // dan tanpa ini tombol Kirim Koreksi tidak pernah menyala
                    // untuk koreksi yang datang dari menyorot teks.
                    aspekInput.dispatchEvent(new Event('input', { bubbles: true }));
                }

                window.getSelection().removeAllRanges();
            });
        });
    });
    </script>
    @endpush

    @if($items->hasPages())
    <div class="mt-6">
        <p class="mb-2 text-xs text-gray-500">
            Simpan koreksi pada halaman ini sebelum berpindah - perubahan yang belum
            disimpan tidak ikut terbawa ke halaman berikutnya.
        </p>
        {{ $items->links() }}
    </div>
    @endif

    @endif

</div>
@endsection
