@extends('layouts.app')

@push('styles')
<style>
    .confidence-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        border: 1px solid;
    }
    .confidence-badge.confident  { background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
    .confidence-badge.moderate   { background:#fef9c3; color:#a16207; border-color:#fef08a; }
    .confidence-badge.uncertain  { background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
</style>
@endpush

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            @if(Auth::user()->isAdmin())
                <h1 class="text-3xl font-bold text-gray-900">Active Learning Center</h1>
                <p class="mt-1 text-sm text-gray-600">Kelola data training, koreksi hasil analisis, dan konfigurasi model.</p>
            @else
                <h1 class="text-3xl font-bold text-gray-900">Koreksi Active Learning</h1>
                <p class="mt-1 text-sm text-gray-600">Koreksi hasil analisis Anda untuk membantu meningkatkan model AI.</p>
            @endif
        </div>

        <div class="flex gap-3">
            {{-- Export CSV (available to all) --}}
            <a href="{{ route('training.export') }}" target="_blank"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
            </a>

            @if(Auth::user()->isAdmin())
            {{-- Sync Data Lama (admin only) --}}
            <form action="{{ route('training.sync') }}" method="POST">
                @csrf
                <button type="submit" onclick="return confirm('Proses ini akan membaca semua hasil analisis lama dan menyiapkannya untuk training. Lanjutkan?')"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-md transition text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync Data Lama
                </button>
            </form>

            {{-- Retrain Model (admin only) --}}
            <form action="{{ route('training.trigger') }}" method="POST">
                @csrf
                <button type="submit" onclick="return confirm('Mulai training model?')"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 shadow-md transition text-sm font-medium">
                    Retrain Model
                </button>
            </form>
            @endif
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-green-800 text-sm flex items-center gap-2">
        <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
        </svg>
        {{ session('success') }}
    </div>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Akurasi Koreksi</p>
            <div class="flex items-baseline mt-2 gap-2">
                <p class="text-3xl font-bold text-purple-600">{{ $stats['accuracy'] }}%</p>
            </div>
            <p class="text-xs text-gray-400 mt-1">Prediksi AI yang disetujui</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Baris</p>
            <p class="text-3xl font-bold text-gray-800 mt-2">{{ number_format($stats['total_texts']) }}</p>
            <p class="text-xs text-gray-400 mt-1">Siap dikoreksi</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Sudah Diverifikasi</p>
            <p class="text-3xl font-bold text-green-600 mt-2">{{ number_format($stats['corrected_count']) }}</p>
            <p class="text-xs text-gray-400 mt-1">Koreksi tersimpan</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Antrian Review</p>
            <p class="text-3xl font-bold text-yellow-600 mt-2">{{ number_format($stats['pending_count']) }}</p>
            <p class="text-xs text-gray-400 mt-1">Menunggu koreksi</p>
        </div>
    </div>

    <!-- Stopwords Management (admin only) -->
    @if(Auth::user()->isAdmin())
    <div class="bg-white rounded-xl shadow-sm border border-indigo-100 p-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    Topic Filter (Stopwords)
                </h3>
                <p class="text-sm text-gray-500 mt-1">
                    Kata-kata di bawah ini akan <b>dibuang</b> dari proses Topic Identification agar hasil topik lebih bersih.
                </p>
            </div>
            <form action="{{ route('training.stopword') }}" method="POST" class="flex gap-2 w-full md:w-auto">
                @csrf
                <input type="text" name="word" placeholder="Tambah kata (mis: sih, dong)..." required
                       class="flex-1 md:w-64 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 shadow-sm">
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 shadow-sm transition">
                    + Add
                </button>
            </form>
        </div>

        <div class="flex flex-wrap gap-2 max-h-40 overflow-y-auto pr-1 bg-gray-50 p-4 rounded-lg border border-gray-100">
            @forelse($stopwords as $sw)
            <form action="{{ route('training.stopword.delete', $sw->id) }}" method="POST" class="inline">
                @csrf @method('DELETE')
                <button type="submit" class="group inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-white border border-gray-200 text-gray-700 hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition shadow-sm" title="Klik untuk hapus">
                    {{ $sw->word }}
                    <span class="ml-1.5 text-gray-400 group-hover:text-red-500 font-bold">&times;</span>
                </button>
            </form>
            @empty
            <div class="w-full text-center text-xs text-gray-400 italic">
                Belum ada custom stopword.
            </div>
            @endforelse
        </div>
    </div>
    @endif

    <!-- Batch List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-bold text-gray-900">File Analisis (Batch)</h2>
            <p class="text-sm text-gray-500">Pilih file di bawah ini untuk mulai mengoreksi baris kalimat di dalamnya.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-600">
                <thead class="bg-gray-50 text-gray-900 font-medium">
                    <tr>
                        <th class="px-6 py-3">Judul File</th>
                        <th class="px-6 py-3">Tipe Analisis</th>
                        <th class="px-6 py-3">Confidence AI</th>
                        <th class="px-6 py-3">Progress Koreksi</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($batches as $batch)
                    <tr class="hover:bg-gray-50 transition group">
                        <td class="px-6 py-4">
                            <div class="font-medium text-gray-900">{{ $batch->title }}</div>
                            <div class="text-xs text-gray-400">
                                {{ $batch->created_at->format('d M Y') }} &bull; {{ $batch->total_records ?? 0 }} Baris
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $typeLabel = ucwords(str_replace('_', ' ', $batch->analysis_type));
                                $badgeColor = match($batch->analysis_type) {
                                    'sentiment_analysis', 'sentiment' => 'bg-blue-100 text-blue-700 border-blue-200',
                                    'topic_modeling', 'topic'        => 'bg-purple-100 text-purple-700 border-purple-200',
                                    'aspect_extraction', 'aspect'    => 'bg-orange-100 text-orange-700 border-orange-200',
                                    'combined'                       => 'bg-teal-100 text-teal-700 border-teal-200',
                                    default                          => 'bg-gray-100 text-gray-700 border-gray-200',
                                };
                            @endphp
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium border {{ $badgeColor }}">
                                {{ $typeLabel }}
                            </span>
                        </td>
                        <td class="px-6 py-4 w-3/12">
                            @php
                                $avgConf = (float) ($batch->avg_confidence ?? 0);
                                // M ≈ (3P - 1) / 2 for 3-class approximation
                                $margin  = max(0.0, (3 * $avgConf - 1) / 2);
                                $confPct = round($avgConf * 100, 1);
                                $marginPct = round($margin * 100, 1);
                                if ($margin >= 0.6) {
                                    $confStatus = 'confident';
                                    $barClass   = 'bg-green-500';
                                } elseif ($margin >= 0.3) {
                                    $confStatus = 'moderate';
                                    $barClass   = 'bg-yellow-500';
                                } else {
                                    $confStatus = 'uncertain';
                                    $barClass   = 'bg-red-500';
                                }
                            @endphp
                            @if($avgConf > 0)
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-500">{{ $confPct }}%</span>
                                    <span class="confidence-badge {{ $confStatus }}">
                                        @if($confStatus === 'confident') Confident
                                        @elseif($confStatus === 'moderate') Moderate
                                        @else Uncertain @endif
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-1.5">
                                    <div class="{{ $barClass }} h-1.5 rounded-full transition-all duration-500"
                                         style="width: {{ $confPct }}%"></div>
                                </div>
                                <p class="text-xs text-gray-400">M(x) ≈ {{ $marginPct }}%</p>
                            </div>
                            @else
                            <span class="text-xs text-gray-400 italic">Belum ada data</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 w-4/12">
                            @php
                                $total    = $batch->total_records > 0 ? $batch->total_records : 1;
                                $verified = $batch->verified_count ?? 0;
                                $percent  = round(($verified / $total) * 100);
                            @endphp
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="{{ $percent == 100 ? 'text-green-600' : 'text-gray-600' }}">
                                    {{ $verified }} / {{ $batch->total_records }} selesai
                                </span>
                                <span>{{ $percent }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="h-2 rounded-full transition-all duration-500 {{ $percent == 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                                     style="width: {{ $percent }}%"></div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('training.show', $batch->id) }}"
                               class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-indigo-600 transition shadow-sm">
                                Buka &rarr;
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                                </svg>
                                <p class="text-gray-400 italic text-sm">Belum ada file analisis.</p>
                                <a href="{{ route('analysis.create') }}" class="text-sm text-blue-600 hover:underline">
                                    Mulai analisis baru &rarr;
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-gray-100">
            {{ $batches->links() }}
        </div>
    </div>
</div>
@endsection