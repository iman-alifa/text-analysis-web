@extends('layouts.app')

@push('styles')
<style>
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    .metric-card {
        transition: all 0.3s ease;
    }
    
    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    
    .word-cloud-item {
        display: inline-block;
        margin: 5px;
        padding: 8px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 20px;
        font-weight: 600;
        transition: transform 0.2s;
    }
    
    .word-cloud-item:hover {
        transform: scale(1.1);
    }
    
    .aspect-bar {
        height: 30px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        padding: 0 15px;
        color: white;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .aspect-bar:hover {
        filter: brightness(1.1);
        transform: scaleX(1.02);
    }
    
    .prediction-card {
        border-left: 4px solid transparent;
        transition: all 0.3s ease;
    }
    
    .prediction-card.positive {
        border-left-color: #10b981;
        background: linear-gradient(to right, #f0fdf4, white);
    }
    
    .prediction-card.negative {
        border-left-color: #ef4444;
        background: linear-gradient(to right, #fef2f2, white);
    }
    
    .prediction-card.neutral {
        border-left-color: #6b7280;
        background: linear-gradient(to right, #f9fafb, white);
    }
    
    .prediction-card:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in-up {
        animation: fadeInUp 0.5s ease-out;
    }
</style>
@endpush

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-start space-x-4">
            <a href="{{ route('analysis.index') }}" class="p-2 hover:bg-gray-100 rounded-lg transition-colors mt-1">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ $analysis->title }}</h1>
                <div class="flex flex-wrap items-center gap-3 mt-2 text-sm text-gray-600">
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        {{ $analysis->created_at->format('d M Y, H:i') }}
                    </span>
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        {{ $analysis->total_records }} data
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($analysis->analysis_type == 'sentiment') bg-blue-100 text-blue-800
                        @elseif($analysis->analysis_type == 'aspect') bg-purple-100 text-purple-800
                        @elseif($analysis->analysis_type == 'topic') bg-green-100 text-green-800
                        @else bg-gradient-to-r from-blue-100 to-purple-100 text-blue-800
                        @endif">
                        {{ ucfirst($analysis->analysis_type) }}
                    </span>
                    @if($analysis->status == 'completed' && $analysis->duration)
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $analysis->duration }}
                    </span>
                    @endif
                </div>
                @if($analysis->description)
                <p class="mt-2 text-gray-600">{{ $analysis->description }}</p>
                @endif
            </div>
        </div>

        @if($analysis->status == 'completed')
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('analysis.export-pdf', $analysis->id) }}"
               class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-medium rounded-lg shadow-sm hover:shadow-md hover:from-red-600 hover:to-red-700 transform hover:-translate-y-0.5 transition-all duration-200">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
                </svg>
                <span>Export PDF</span>
            </a>
            <a href="{{ route('analysis.export-csv', $analysis->id) }}"
               class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-sm font-medium rounded-lg shadow-sm hover:shadow-md hover:from-emerald-600 hover:to-green-700 transform hover:-translate-y-0.5 transition-all duration-200">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Export CSV</span>
            </a>
            <a href="{{ route('analysis.feedback', $analysis->id) }}"
               class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-indigo-500 to-purple-600 text-white text-sm font-medium rounded-lg shadow-sm hover:shadow-md hover:from-indigo-600 hover:to-purple-700 transform hover:-translate-y-0.5 transition-all duration-200">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
                <span>Feedback</span>
            </a>
        </div>
        @endif
    </div>

    <!-- Status Alert & Content -->
    @if(session('feedback_success'))
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-5 flex items-start gap-3">
        <svg class="w-6 h-6 text-indigo-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="font-semibold text-indigo-900">Koreksi Berhasil Disimpan!</p>
            <p class="text-sm text-indigo-700 mt-0.5">{{ session('feedback_success') }}</p>
        </div>
    </div>
    @endif

    @if($analysis->status == 'processing')
        <!-- Alert Box -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
            <div class="flex items-center">
                <svg class="w-6 h-6 text-yellow-600 animate-spin mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-yellow-900 status-text">Analisis Sedang Diproses</h3>
                    <p class="text-sm text-yellow-700 mt-1 status-ket">Harap tunggu, halaman akan otomatis refresh.</p>
                </div>
            </div>
        </div>
        
        <!-- Skeleton Loading -->
        <div class="space-y-6">
            @for($i = 0; $i < 4; $i++)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 animate-pulse">
                <div class="h-4 bg-gray-200 rounded w-1/4 mb-4"></div>
                <div class="h-64 bg-gray-200 rounded"></div>
            </div>
            @endfor
        </div>

    @elseif($analysis->status == 'failed')
        <div class="bg-red-50 border border-red-200 rounded-xl p-6">
            <div class="flex items-start">
                <svg class="w-6 h-6 text-red-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-red-900">Analisis Gagal</h3>
                    <p class="text-sm text-red-700 mt-1">{{ $analysis->error_message ?? 'Terjadi kesalahan saat memproses analisis.' }}</p>
                    <div class="mt-4">
                        <a href="{{ route('analysis.create') }}" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Coba Lagi
                        </a>
                    </div>
                </div>
            </div>
        </div>

    @elseif($analysis->status == 'pending')
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
            <div class="flex items-center">
                <svg class="w-6 h-6 text-yellow-600 animate-spin mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-yellow-900 status-text">Analisis Dalam Antrian</h3>
                    <p class="text-sm text-yellow-700 mt-1 status-ket">Analisis Anda sedang menunggu untuk diproses.</p>
                </div>
            </div>
        </div>
        
        <!-- Skeleton Loading -->
        <div class="space-y-6">
            @for($i = 0; $i < 4; $i++)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 animate-pulse">
                <div class="h-4 bg-gray-200 rounded w-1/4 mb-4"></div>
                <div class="h-64 bg-gray-200 rounded"></div>
            </div>
            @endfor
        </div>        
    @endif

    <!-- Analysis Results (Only show if completed) -->
    @if($analysis->status == 'completed' && $analysis->result)
        
        @php
            $result = $analysis->result;
        @endphp

        <!-- Summary Card -->
        @if($result->summary)
        <div class="bg-gradient-to-br from-blue-500 to-cyan-500 rounded-xl shadow-lg p-6 text-white">
            <div class="flex items-start">
                <svg class="w-8 h-8 mr-4 mt-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold mb-2">Ringkasan Analisis</h3>
                    <p class="text-white/95 leading-relaxed">{{ $result->summary }}</p>
                </div>
            </div>
        </div>
        @endif

        @include('analysis.partials.ai-interpretation', [
            'section' => 'overview',
            'judul' => 'Ringkasan Eksekutif AI',
            'keterangan' => 'Menyatukan seluruh bagian hasil menjadi satu gambaran utuh.',
            'stored' => $aiSections['overview'] ?? null,
        ])

        <!-- Metrics Cards -->
        @php
            // Hanya nilai tunggal yang layak jadi kartu. metrics juga memuat
            // nilai bersarang seperti review_queue dan failed_batches, dan
            // mencetak array lewat {{ }} membuat seluruh halaman gagal render.
            $metricCards = collect($result->metrics ?? [])->filter(fn ($value) => is_scalar($value));
        @endphp
        @if($metricCards->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($metricCards as $key => $value)
            <div class="metric-card bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-gray-600">{{ ucfirst(str_replace('_', ' ', $key)) }}</p>
                    @if(strpos($key, 'accuracy') !== false || strpos($key, 'confidence') !== false)
                        <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </div>
                <p class="text-3xl font-bold text-gray-900">
                    @if(is_numeric($value))
                        {{ is_float($value) ? round($value * 100, 1) . '%' : $value }}
                    @else
                        {{ $value }}
                    @endif
                </p>
            </div>
            @endforeach
        </div>
        @endif

        <!-- Sentiment Analysis Results -->
        @if($analysis->analysis_type == 'sentiment' || $analysis->analysis_type == 'combined')
            @if($result->sentiment_distribution)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Sentiment Distribution Chart -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Distribusi Sentimen</h3>
                    <div class="chart-container">
                        <canvas id="sentimentChart"></canvas>
                    </div>
                </div>

                <!-- Sentiment Statistics -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Statistik Sentimen</h3>
                    <div class="space-y-4">
                        @foreach($result->sentiment_distribution as $sentiment => $percentage)
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700 capitalize">{{ $sentiment }}</span>
                                <span class="text-sm font-bold text-gray-900">{{ $percentage }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="h-3 rounded-full transition-all duration-500
                                    @if($sentiment == 'positive') bg-green-500
                                    @elseif($sentiment == 'negative') bg-red-500
                                    @else bg-gray-500
                                    @endif"
                                    style="width: {{ $percentage }}%">
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @include('analysis.partials.ai-interpretation', [
                'section' => 'sentiment',
                'judul' => 'Interpretasi AI: Sentimen',
                'keterangan' => 'Penjelasan naratif atas distribusi sentimen di atas.',
                'stored' => $aiSections['sentiment'] ?? null,
            ])

            <!-- Predictions List -->
            @if($result->predictions)
            @php
                $reviewQueue = $result->metrics['review_queue'] ?? null;
                $qualityCounters = collect([
                    'total_empty' => ['label' => 'baris kosong', 'note' => 'tidak ikut dihitung dalam persentase'],
                    'total_truncated' => ['label' => 'teks terpotong', 'note' => 'melebihi 512 token, ekornya tidak dinilai'],
                    'total_failed' => ['label' => 'baris gagal dinilai', 'note' => 'model tidak berhasil menilai'],
                ])->filter(fn ($meta, $key) => ($result->metrics[$key] ?? 0) > 0);
            @endphp
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Detail Prediksi</h3>
                            <p class="text-sm text-gray-500 mt-1">Menampilkan {{ count($result->predictions) }} teks yang dianalisis</p>
                        </div>
                        
                        <!-- Filter Buttons -->
                        <div class="flex gap-2 flex-wrap" role="group" aria-label="Saring prediksi">
                            <button type="button" onclick="filterPredictions('all')" class="filter-btn active px-3 py-1.5 rounded-lg text-sm font-medium bg-blue-600 text-white" data-filter="all" aria-pressed="true">
                                Semua
                            </button>
                            @if($reviewQueue && $reviewQueue['count'] > 0)
                            <button type="button" onclick="filterPredictions('review')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-amber-100 text-amber-800 hover:bg-amber-200" data-filter="review" aria-pressed="false">
                                Perlu Ditinjau ({{ $reviewQueue['count'] }})
                            </button>
                            @endif
                            <button type="button" onclick="filterPredictions('positive')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="positive" aria-pressed="false">
                                Positif
                            </button>
                            <button type="button" onclick="filterPredictions('neutral')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="neutral" aria-pressed="false">
                                Netral
                            </button>
                            <button type="button" onclick="filterPredictions('negative')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="negative" aria-pressed="false">
                                Negatif
                            </button>
                        </div>
                    </div>
                </div>
                
                @if($reviewQueue && $reviewQueue['count'] > 0)
                <div class="px-6 py-4 border-b border-gray-200 bg-amber-50">
                    <p class="text-sm text-amber-900">
                        <strong>{{ $reviewQueue['count'] }} baris</strong>
                        ({{ round($reviewQueue['share'] * 100, 1) }}% dari yang dinilai)
                        punya keyakinan di bawah {{ round($reviewQueue['threshold'] * 100, 1) }}%.
                        Mengoreksi baris ini lebih dulu adalah cara termurah mengumpulkan data latih.
                    </p>
                    <p class="mt-1 text-xs text-amber-800">
                        Catatan: antrean ini sengaja berisi baris yang model paling ragu, jadi
                        <strong>jangan memakainya untuk mengukur akurasi</strong> &mdash; untuk pengukuran, ambil sampel acak.
                    </p>
                </div>
                @endif

                @if($qualityCounters->isNotEmpty())
                <div class="px-6 py-4 border-b border-gray-200 bg-blue-50">
                    <p class="text-sm font-medium text-blue-900 mb-1">Catatan mutu data</p>
                    <ul class="text-xs text-blue-800 space-y-0.5">
                        @foreach($qualityCounters as $key => $meta)
                        <li>&bull; {{ $result->metrics[$key] }} {{ $meta['label'] }} &mdash; {{ $meta['note'] }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <!-- Search Box -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <label for="searchPredictions" class="sr-only">Cari teks prediksi</label>
                    <input type="search"
                        id="searchPredictions"
                        autocomplete="off"
                        placeholder="Cari teks..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="p-6">
                    <!-- Predictions Container -->
                    {{-- Sprite ikon: tiga varian yang sama dulu diulang penuh
                         pada setiap kartu, dan itu bagian terbesar dari berat
                         halaman ketika prediksinya ratusan. --}}
                    <svg class="hidden" aria-hidden="true">
                        <symbol id="ikon-sentimen-positive" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </symbol>
                        <symbol id="ikon-sentimen-negative" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </symbol>
                        <symbol id="ikon-sentimen-neutral" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                        </symbol>
                    </svg>

                    <div id="predictions-container"
                         class="space-y-3"
                         aria-live="polite"
                         aria-busy="false">
                        @include('analysis.partials.prediction-list', [
                            'items' => $predictionPage['items'],
                            'meta' => $predictionPage['meta'],
                        ])
                    </div>

                    {{-- Kontrol halaman --}}
                    <div id="predictions-pagination" class="mt-4 flex items-center justify-between gap-4 flex-wrap">
                        <p class="text-sm text-gray-500" id="predictions-count">
                            Menampilkan {{ $predictionPage['meta']['from'] }}&ndash;{{ $predictionPage['meta']['to'] }}
                            dari {{ $predictionPage['meta']['filtered'] }} prediksi
                        </p>

                        <div class="flex items-center gap-2">
                            <button type="button" id="predictions-prev"
                                    class="px-3 py-1.5 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                    disabled>
                                Sebelumnya
                            </button>
                            <span class="text-sm text-gray-600" id="predictions-page-label">
                                Halaman {{ $predictionPage['meta']['page'] }} dari {{ $predictionPage['meta']['last_page'] }}
                            </span>
                            <button type="button" id="predictions-next"
                                    class="px-3 py-1.5 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                    {{ $predictionPage['meta']['last_page'] > 1 ? '' : 'disabled' }}>
                                Berikutnya
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            @push('scripts')
            <script>
                // Daftar prediksi dimuat per halaman dari server. Sebelumnya
                // seluruh baris dirender sekaligus lalu disaring di browser,
                // sehingga halaman hasil untuk 289 prediksi mencapai 2,7 MB.
                (function () {
                    const container = document.getElementById('predictions-container');
                    if (!container) return;

                    const searchInput = document.getElementById('searchPredictions');
                    const countLabel = document.getElementById('predictions-count');
                    const pageLabel = document.getElementById('predictions-page-label');
                    const prevBtn = document.getElementById('predictions-prev');
                    const nextBtn = document.getElementById('predictions-next');
                    const endpoint = @json(route('analysis.predictions', $analysis->id));

                    let state = { filter: 'all', q: '', page: 1, lastPage: {{ $predictionPage['meta']['last_page'] }} };
                    let pending = null;

                    function setLoading(loading) {
                        container.setAttribute('aria-busy', loading ? 'true' : 'false');
                        container.classList.toggle('opacity-50', loading);
                    }

                    function muat() {
                        // Permintaan sebelumnya dibatalkan supaya hasil yang datang
                        // terlambat tidak menimpa penyaringan yang lebih baru.
                        if (pending) pending.abort();
                        pending = new AbortController();

                        const params = new URLSearchParams({ filter: state.filter, page: state.page });
                        if (state.q) params.set('q', state.q);

                        setLoading(true);

                        fetch(`${endpoint}?${params}`, {
                            headers: { 'Accept': 'application/json' },
                            signal: pending.signal,
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) return;

                            container.innerHTML = data.html;
                            state.page = data.meta.page;
                            state.lastPage = data.meta.last_page;

                            countLabel.textContent = data.meta.filtered === 0
                                ? 'Tidak ada prediksi yang cocok'
                                : `Menampilkan ${data.meta.from}\u2013${data.meta.to} dari ${data.meta.filtered} prediksi`;
                            pageLabel.textContent = `Halaman ${data.meta.page} dari ${data.meta.last_page}`;
                            prevBtn.disabled = data.meta.page <= 1;
                            nextBtn.disabled = data.meta.page >= data.meta.last_page;
                        })
                        .catch(error => {
                            if (error.name === 'AbortError') return;
                            container.innerHTML = '<p class="py-8 text-center text-red-600">Gagal memuat prediksi.</p>';
                        })
                        .finally(() => setLoading(false));
                    }

                    window.filterPredictions = function (filter) {
                        state.filter = filter;
                        state.page = 1;

                        document.querySelectorAll('.filter-btn').forEach(btn => {
                            // Kelas amber milik tombol tinjauan ikut dilepas, kalau tidak
                            // dua kelas latar aktif bersamaan saat tombol itu dipilih.
                            btn.classList.remove('active', 'bg-blue-600', 'text-white', 'bg-amber-100', 'text-amber-800', 'hover:bg-amber-200');
                            btn.classList.add('bg-gray-100', 'text-gray-700');
                            btn.setAttribute('aria-pressed', 'false');
                        });

                        const activeBtn = document.querySelector(`[data-filter="${filter}"]`);
                        if (activeBtn) {
                            activeBtn.classList.add('active', 'bg-blue-600', 'text-white');
                            activeBtn.classList.remove('bg-gray-100', 'text-gray-700');
                            activeBtn.setAttribute('aria-pressed', 'true');
                        }

                        muat();
                    };

                    window.toggleProcessedText = function (index) {
                        const element = document.getElementById(`processed-text-${index}`);
                        if (!element) return;

                        const tampil = element.classList.toggle('hidden') === false;
                        const tombol = document.querySelector(`[aria-controls="processed-text-${index}"]`);
                        if (tombol) tombol.setAttribute('aria-expanded', tampil ? 'true' : 'false');
                    };

                    let debounce = null;
                    searchInput?.addEventListener('input', function () {
                        clearTimeout(debounce);
                        // Ditunda agar setiap ketikan tidak memicu satu permintaan.
                        debounce = setTimeout(() => {
                            state.q = this.value.trim();
                            state.page = 1;
                            muat();
                        }, 300);
                    });

                    prevBtn?.addEventListener('click', () => {
                        if (state.page > 1) { state.page -= 1; muat(); }
                    });

                    nextBtn?.addEventListener('click', () => {
                        if (state.page < state.lastPage) { state.page += 1; muat(); }
                    });
                })();
            </script>
            @endpush
            @endif

            <!-- Interactive Predictions Filter -->
            {{-- Panel "Filter Hasil" duplikat dihapus di sini.
                 Skrip di dalamnya mendeklarasikan ulang const allPredictions dan
                 currentFilter pada lingkup global yang sama dengan blok Detail
                 Prediksi di atas, sehingga seluruh skrip itu gagal di-parse
                 (SyntaxError) dan tombolnya mati. Kotak carinya juga memakai id
                 searchPredictions yang sudah dipakai, jadi tidak pernah terbaca. --}}
            @endif
        @endif

        <!-- Aspect Analysis Results -->
        @php $aspectRows = $chartData['aspects'] ?? []; @endphp
        @if(($analysis->analysis_type == 'aspect' || $analysis->analysis_type == 'combined') && !empty($aspectRows))
        <div class="space-y-6">
            <!-- Aspect Chart -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Analisis Aspek</h3>
                <div class="chart-container" style="height: 400px;">
                    <canvas id="aspectChart"></canvas>
                </div>
            </div>

            <!-- Aspect Details -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Detail Aspek</h3>
                <div class="space-y-4">
                    @foreach($aspectRows as $aspect)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold text-gray-900 capitalize">{{ $aspect['aspect'] }}</h4>
                            <span class="text-sm text-gray-500">{{ $aspect['count'] }} mentions</span>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach($aspect['sentiments'] as $sentiment => $percentage)
                            <div class="text-center p-2 rounded
                                @if($sentiment == 'positive') bg-green-50
                                @elseif($sentiment == 'negative') bg-red-50
                                @else bg-gray-50
                                @endif">
                                <p class="text-xs font-medium text-gray-600 capitalize">{{ $sentiment }}</p>
                                <p class="text-lg font-bold
                                    @if($sentiment == 'positive') text-green-600
                                    @elseif($sentiment == 'negative') text-red-600
                                    @else text-gray-600
                                    @endif">
                                    {{ round($percentage) }}%
                                </p>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @include('analysis.partials.ai-interpretation', [
                'section' => 'aspect',
                'judul' => 'Interpretasi AI: Aspek',
                'keterangan' => 'Aspek mana yang paling disorot dan mana yang sentimennya bermasalah.',
                'stored' => $aiSections['aspect'] ?? null,
            ])
        </div>
        @endif

        <!-- Topic Modeling Results -->
        @if(($analysis->analysis_type == 'topic' || $analysis->analysis_type == 'combined') && $result->topic_results)
        <div class="space-y-6">
            @php
                $topicQuality = $result->topic_results['quality'] ?? null;
                // Rambu penafsiran c_v untuk teks pendek/tidak baku seperti komentar.
                $cv = $topicQuality['c_v'] ?? null;
                $cvBand = match(true) {
                    $cv === null => null,
                    $cv < 0.30 => ['label' => 'Tidak koheren', 'class' => 'text-red-700 bg-red-50 border-red-200'],
                    $cv < 0.40 => ['label' => 'Lemah', 'class' => 'text-orange-700 bg-orange-50 border-orange-200'],
                    $cv < 0.55 => ['label' => 'Baik untuk teks pendek', 'class' => 'text-green-700 bg-green-50 border-green-200'],
                    $cv < 0.70 => ['label' => 'Sangat baik', 'class' => 'text-green-800 bg-green-100 border-green-300'],
                    default => ['label' => 'Patut dicurigai', 'class' => 'text-amber-800 bg-amber-50 border-amber-200'],
                };
            @endphp

            @if($topicQuality)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Mutu Pemodelan Topik</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Ukuran seberapa koheren topik yang terbentuk pada korpus ini.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @if($cv !== null)
                    <div class="rounded-lg border p-4 {{ $cvBand['class'] }}">
                        <p class="text-xs font-medium uppercase tracking-wide opacity-80">Koherensi (c<sub>v</sub>)</p>
                        <p class="mt-1 text-2xl font-bold">{{ number_format($cv, 4) }}</p>
                        <p class="text-xs mt-1">{{ $cvBand['label'] }}</p>
                    </div>
                    @endif

                    @isset($topicQuality['diversity'])
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Keberagaman</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($topicQuality['diversity'], 4) }}</p>
                        <p class="text-xs mt-1 text-gray-500">Porsi kata kunci yang tidak berulang antar topik</p>
                    </div>
                    @endisset

                    @isset($topicQuality['outlier_rate'])
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokumen di luar topik</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ round($topicQuality['outlier_rate'] * 100, 1) }}%</p>
                        <p class="text-xs mt-1 text-gray-500">Wajar pada kisaran 5&ndash;20%</p>
                    </div>
                    @endisset

                    @isset($topicQuality['c_npmi'])
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">c<sub>NPMI</sub></p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($topicQuality['c_npmi'], 4) }}</p>
                        <p class="text-xs mt-1 text-gray-500">Ukuran pembanding, rentang &minus;1 sampai 1</p>
                    </div>
                    @endisset
                </div>

                <p class="mt-4 text-xs text-gray-500">
                    Nilai c<sub>v</sub> <strong>tidak sebanding antar korpus</strong>, jadi jangan dibandingkan
                    langsung dengan angka dari penelitian lain &mdash; gunakan untuk membandingkan
                    konfigurasi pada data yang sama.
                </p>
            </div>
            @endif

            <!-- Topics Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Topic Distribution -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Distribusi Topik</h3>
                    <div class="chart-container">
                        <canvas id="topicChart"></canvas>
                    </div>
                </div>

                <!-- Word Cloud (wordcloud2.js) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Word Cloud</h3>
                        <span class="text-xs text-gray-400">{{ count($result->topic_results['word_frequencies'] ?? []) }} kata</span>
                    </div>
                    <div id="wordCloudCanvas" class="w-full" style="height: 360px;">
                        <canvas id="wordCloudCanvasEl" style="width:100%; height:100%;"></canvas>
                    </div>
                    @if(isset($result->topic_results['word_frequencies']) && count($result->topic_results['word_frequencies']) > 0)
                        @php
                            $wordFreqs = array_slice($result->topic_results['word_frequencies'], 0, 60);
                            // Hitung range frekuensi untuk normalisasi ukuran (10-72 px)
                            $maxFreq = max(array_column($wordFreqs, 'frequency')) ?: 1;
                            $minFreq = min(array_column($wordFreqs, 'frequency')) ?: 1;
                            $wordCloudList = [];
                            foreach ($wordFreqs as $w) {
                                $range = max(1, $maxFreq - $minFreq);
                                $norm = ($w['frequency'] - $minFreq) / $range; // 0..1
                                // wordcloud2.js menggunakan list of [word, weight]
                                $wordCloudList[] = [$w['word'], max(8, $norm * 60 + 8)];
                            }
                        @endphp
                        <script>
                            window.__wordCloudData = @json($wordCloudList);
                        </script>
                    @endif
                </div>
            </div>

            <!-- Topic Details -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Detail Topik</h3>
                    @php $topikSudahDilabeli = ! empty($result->topic_results['interpretation']); @endphp
                    <div class="flex items-center gap-2 flex-wrap">
                        @if($topikSudahDilabeli)
                            <span id="badgeTopikAI" class="inline-flex items-center px-2.5 py-1 bg-purple-100 text-purple-800 text-xs font-semibold rounded-full border border-purple-200">
                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                Diinterpretasikan oleh AI
                            </span>
                        @endif

                        {{-- Tombol tetap ada setelah label terbentuk: label yang
                             kurang tepat harus bisa dibangkitkan ulang tanpa
                             menyunting database. --}}
                        <button type="button" id="btnGenerateAI"
                                data-ai-url="{{ route('analysis.interpret', ['id' => $analysis->id, 'section' => 'topic']) }}"
                                data-sudah="{{ $topikSudahDilabeli ? '1' : '0' }}"
                                class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-purple-500 to-indigo-600 text-white text-sm font-medium rounded-lg hover:from-purple-600 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            <span id="labelBtnGenerateAI">{{ $topikSudahDilabeli ? 'Bangkitkan Ulang Label' : 'Generate Interpretasi AI' }}</span>
                        </button>
                    </div>
                </div>

                <p id="aiTopikError" class="hidden mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2" role="alert"></p>

                <div id="aiLoadingIndicator" class="hidden mb-4 p-4 bg-indigo-50 rounded-lg border border-indigo-100 flex items-center justify-center">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <span class="text-sm font-medium text-indigo-700">AI sedang memproses kata kunci untuk menghasilkan interpretasi... (Bisa memakan waktu 5-10 detik)</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($result->topic_results['topics'] as $topic)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold text-gray-900" id="topic-label-{{ $topic['topic_id'] }}">
                                @if(isset($result->topic_results['interpretation'][$topic['topic_id']]))
                                    {{ $result->topic_results['interpretation'][$topic['topic_id']]['label'] }}
                                @else
                                    Topik #{{ $topic['topic_id'] + 1 }}
                                @endif
                            </h4>
                            <span class="text-sm text-gray-500">{{ round(($topic['proportion'] ?? 0) * 100, 1) }}%</span>
                        </div>
                        
                        <p class="text-sm text-gray-700 mb-3 @if(!isset($result->topic_results['interpretation'][$topic['topic_id']])) hidden @endif" id="topic-desc-{{ $topic['topic_id'] }}">
                            @if(isset($result->topic_results['interpretation'][$topic['topic_id']]))
                                {{ $result->topic_results['interpretation'][$topic['topic_id']]['description'] }}
                            @endif
                        </p>

                        <div class="flex flex-wrap gap-2">
                            @foreach(array_slice($topic['words'], 0, 8) as $word)
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm rounded-full">
                                {{ $word }}
                            </span>
                            @endforeach
                        </div>
                        <p class="mt-3 text-sm text-gray-600">
                            Muncul pada {{ $topic['size'] ?? 0 }} teks
                        </p>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <!-- Aspect-Topic Association Results -->
        @if($analysis->analysis_type == 'combined' && !empty($aspectRows) && $result->topic_results)
        <div class="space-y-6 mt-10">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    Asosiasi Aspek & Topik
                </h3>
                <span class="px-3 py-1 bg-gradient-to-r from-indigo-100 to-purple-100 text-indigo-800 rounded-full text-xs font-semibold tracking-wide border border-indigo-200">Advanced Insight</span>
            </div>
            
            <div class="bg-blue-50/50 border border-blue-100 p-4 rounded-xl flex gap-4 items-start">
                <div class="flex-shrink-0 mt-0.5">
                    <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <p class="text-sm text-blue-800 leading-relaxed">
                    Analisis ini menghubungkan aspek yang diekstrak (level token) dengan topik dokumen (level dokumen) menggunakan <strong>Pointwise Mutual Information (PMI)</strong> untuk menemukan kekuatan asosiasi statistik. Dokumen menjadi jembatan antara entitas spesifik dengan tema wacana secara keseluruhan.
                </p>
            </div>

            @php
                // Sumber data asli: association_results (PMI dari NLP API) +
                // document_aspects/document_topics. Tidak ada lagi contoh angka
                // bawaan di sini, karena data mock sempat tampil seolah hasil analisis.
                $associationData = $chartData['association'] ?? null;
            @endphp

            @if(!$associationData)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center">
                <p class="text-gray-600">Data asosiasi aspek&ndash;topik belum tersedia untuk analisis ini.</p>
                <p class="mt-2 text-sm text-gray-500">
                    PMI hanya dihitung saat analisis gabungan menghasilkan aspek dan topik sekaligus.
                    Jalankan ulang analisis agar asosiasi ikut tersimpan.
                </p>
            </div>
            @else
            @php
                // Narasi berbasis aturan disusun di AssociationInsightService,
                // bukan di sini: 60 baris analisis di dalam berkas tampilan
                // tidak bisa diuji, tidak bisa dipakai ulang oleh export PDF,
                // dan dulu menyisipkan nama aspek ke HTML tanpa escape.
                if (empty($associationData['insights'])) {
                    $associationData['insights'] = app(\App\Services\AssociationInsightService::class)
                        ->build($associationData);
                }

                // Closure, bukan function: `function foo()` di dalam @php
                // mendeklarasikan fungsi global, sehingga merender view ini dua
                // kali dalam satu proses PHP (mis. di dalam satu berkas tes)
                // memicu "Cannot redeclare".
                $warnaPmi = function ($value) {
                    if ($value >= 0.5) return 'bg-amber-600 text-amber-50 shadow-sm border border-amber-700/50';
                    if ($value >= 0.3) return 'bg-amber-400 text-amber-900 border border-amber-500/50';
                    if ($value >= 0.1) return 'bg-blue-100 text-blue-800 border border-blue-200';
                    if ($value > 0) return 'bg-blue-50 text-blue-700 border border-blue-100';
                    if ($value >= -0.2) return 'bg-gray-100 text-gray-600 border border-gray-200';
                    if ($value >= -0.5) return 'bg-gray-200 text-gray-700 border border-gray-300';
                    return 'bg-emerald-50 text-emerald-800 border border-emerald-200';
                };

                $warnaCrosstab = function ($percentage) {
                    if ($percentage >= 70) return 'bg-amber-100 text-amber-900 font-semibold px-2 py-0.5 rounded';
                    if ($percentage >= 40) return 'bg-blue-50 text-blue-800 font-medium px-2 py-0.5 rounded';
                    return 'text-gray-500 font-medium';
                };
            @endphp

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Tabel Distribusi -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="text-base font-semibold text-gray-900 mb-1">Distribusi Proporsi Aspek × Topik</h4>
                    <p class="text-xs text-gray-500 mb-4">Persentase dokumen mengandung aspek tertentu yang masuk ke setiap topik.</p>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse min-w-[500px]">
                            <thead>
                                <tr>
                                    <th class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200">Aspek</th>
                                    <th class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200 text-center">Mentions</th>
                                    @foreach($associationData['topics_label'] as $label)
                                    <th class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200 text-center whitespace-nowrap">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($associationData['crosstab'] as $row)
                                <tr class="hover:bg-gray-50/50 transition-colors">
                                    <td class="py-3 px-4 text-sm font-medium text-gray-900">{{ $row['aspect'] }}</td>
                                    <td class="py-3 px-4 text-sm text-gray-600 text-center">{{ $row['mentions'] }}</td>
                                    @foreach($row['topics'] as $val)
                                    <td class="py-3 px-4 text-sm text-center">
                                        <span class="{{ $warnaCrosstab($val) }}">~{{ $val }}%</span>
                                    </td>
                                    @endforeach
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Heatmap PMI -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col">
                    <h4 class="text-base font-semibold text-gray-900 mb-1">Kekuatan Asosiasi Statistik (Heatmap PMI)</h4>
                    <p class="text-xs text-gray-500 mb-4">PMI (Pointwise Mutual Information) > 0 menandakan asosiasi kuat.</p>
                    
                    <div class="flex-1 flex flex-col justify-center overflow-x-auto">
                        <div class="min-w-[360px]">
                            <!-- Header Grid -->
                            <div class="grid grid-cols-[80px_repeat(3,1fr)] gap-2 mb-2">
                                <div></div>
                                @foreach($associationData['topics_label'] as $index => $label)
                                <div class="text-center flex flex-col justify-end">
                                    <span class="text-xs font-semibold text-gray-600">{{ $label }}</span>
                                    @if(!empty($associationData['topics_desc'][$index]))
                                    <span class="text-[10px] text-gray-400 font-normal leading-tight mt-1">{{ $associationData['topics_desc'][$index] }}</span>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                            
                            <!-- Body Grid -->
                            <div class="grid grid-cols-[80px_repeat(3,1fr)] gap-2">
                                @foreach($associationData['pmi'] as $row)
                                <div class="flex items-center text-sm font-medium text-gray-900">{{ $row['aspect'] }}</div>
                                @foreach($row['scores'] as $score)
                                <div class="text-center rounded-lg py-2 text-sm font-medium transition-transform hover:scale-105 {{ $warnaPmi($score) }}">
                                    {{ $score > 0 ? '+'.$score : $score }}
                                </div>
                                @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    <div class="mt-6 flex flex-wrap gap-4 pt-4 border-t border-gray-100">
                        <div class="flex items-center gap-2 text-xs text-gray-600">
                            <div class="w-3.5 h-3.5 rounded bg-amber-600"></div> Asosiasi Kuat
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-600">
                            <div class="w-3.5 h-3.5 rounded bg-blue-100 border border-blue-200"></div> Asosiasi Positif
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-600">
                            <div class="w-3.5 h-3.5 rounded bg-gray-200 border border-gray-300"></div> Jarang Bersama
                        </div>
                    </div>
                </div>
            </div>

            <!-- Insight Narasi -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h4 class="text-base font-semibold text-gray-900 mb-1">Interpretasi Hasil</h4>
                <p class="text-sm text-gray-500 mb-4">Disusun otomatis dari nilai PMI dan tabel silang di atas.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($associationData['insights'] as $insight)
                    <div class="bg-gradient-to-br from-gray-50 to-white border border-gray-200 rounded-lg p-4 border-l-4 border-l-amber-500">
                        <p class="text-sm text-gray-700 leading-relaxed">{!! $insight !!}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            @include('analysis.partials.ai-interpretation', [
                'section' => 'association',
                'judul' => 'Interpretasi AI: Asosiasi Aspek & Topik',
                'keterangan' => 'Penjelasan naratif atas keterkaitan PMI di atas.',
                'stored' => $aiSections['association'] ?? null,
            ])
            @endif
        </div>
        @endif

    @endif

{{-- ✅ POLLING SCRIPT - Updated with Progress Tracking --}}
@if($analysis->status == 'pending' || $analysis->status == 'processing')
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const analysisId = {{ $analysis->id }};
    const currentStatus = '{{ $analysis->status }}';
    
    let pollingInterval = null;
    let pollCount = 0;
    const maxPolls = 200; // Maximum 200 polls (10 minutes at 3s interval)
    
    console.log('Starting polling for analysis ID:', analysisId);
    
    // ✅ Start polling
    startPolling();
    
    function startPolling() {
        // Poll immediately
        pollStatus();
        
        // Then poll every 3 seconds
        pollingInterval = setInterval(() => {
            pollCount++;
            
            // Safety: stop after max polls
            if (pollCount >= maxPolls) {
                console.warn('Max poll count reached, stopping polling');
                stopPolling();
                showTimeoutMessage();
                return;
            }
            
            pollStatus();
        }, 3000); // Poll every 3 seconds
    }
    
    function pollStatus() {
        fetch(`/analysis/${analysisId}/poll-status`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Poll response:', data);
            updateUI(data);
            
            // ✅ Stop polling if completed or failed
            if (data.status === 'completed') {
                stopPolling();
                showSuccessMessage();
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else if (data.status === 'failed') {
                stopPolling();
                showErrorMessage(data.error_message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Polling error:', error);
            // Don't stop polling on network errors
            // Server might be temporarily unavailable
        });
    }
    
    function updateUI(data) {
        // Update status text
        const statusText = document.querySelector('.status-text');
        const statusKet = document.querySelector('.status-ket');
        
        if (statusText) {
            if (data.status === 'processing') {
                statusText.textContent = 'Analisis Sedang Diproses';
                if (statusKet && data.current_step) {
                    statusKet.textContent = data.current_step;
                }
            } else if (data.status === 'pending') {
                statusText.textContent = 'Analisis Dalam Antrian';
                if (statusKet) {
                    statusKet.textContent = 'Menunggu untuk diproses...';
                }
            }
        }
        
        // Update progress if available (optional enhancement)
        if (data.progress !== undefined && data.progress > 0) {
            updateProgress(data.progress, data.current_step || '');
        }
    }
    
    function updateProgress(progress, message) {
        // Check if progress elements exist, if not create them
        let progressContainer = document.getElementById('progress-container');
        
        if (!progressContainer) {
            // Create progress bar if doesn't exist
            const alertBox = document.querySelector('.bg-yellow-50');
            if (alertBox) {
                progressContainer = document.createElement('div');
                progressContainer.id = 'progress-container';
                progressContainer.className = 'mt-4';
                progressContainer.innerHTML = `
                    <div class="bg-white rounded-lg border border-gray-200 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-700">Progress</span>
                            <span id="progress-percentage" class="text-sm font-bold text-blue-600">0%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div id="progress-bar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <p id="progress-message" class="mt-2 text-xs text-gray-500"></p>
                    </div>
                `;
                alertBox.after(progressContainer);
            }
        }
        
        // Update progress values
        const progressBar = document.getElementById('progress-bar');
        const progressPercentage = document.getElementById('progress-percentage');
        const progressMessage = document.getElementById('progress-message');
        
        if (progressBar) {
            progressBar.style.width = progress + '%';
        }
        if (progressPercentage) {
            progressPercentage.textContent = progress + '%';
        }
        if (progressMessage && message) {
            progressMessage.textContent = message;
        }
    }
    
    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
            console.log('Polling stopped');
        }
    }
    
    function showSuccessMessage() {
        const alertBox = document.querySelector('.bg-yellow-50');
        if (alertBox) {
            alertBox.classList.remove('bg-yellow-50', 'border-yellow-200');
            alertBox.classList.add('bg-green-50', 'border-green-200');
            alertBox.innerHTML = `
                <div class="flex items-center">
                    <svg class="w-6 h-6 text-green-600 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <h3 class="text-lg font-semibold text-green-900">Analisis Selesai!</h3>
                        <p class="text-sm text-green-700 mt-1">Memuat hasil analisis...</p>
                    </div>
                </div>
            `;
        }
    }
    
    function showErrorMessage(errorMsg) {
        const alertBox = document.querySelector('.bg-yellow-50');
        if (alertBox) {
            alertBox.classList.remove('bg-yellow-50', 'border-yellow-200');
            alertBox.classList.add('bg-red-50', 'border-red-200');
            alertBox.innerHTML = `
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-red-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-red-900">Analisis Gagal</h3>
                        <p class="text-sm text-red-700 mt-1">${errorMsg}</p>
                        <div class="mt-4">
                            <a href="{{ route('analysis.create') }}" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                Coba Lagi
                            </a>
                        </div>
                    </div>
                </div>
            `;
        }
    }
    
    function showTimeoutMessage() {
        const alertBox = document.querySelector('.bg-yellow-50');
        if (alertBox) {
            alertBox.classList.remove('bg-yellow-50', 'border-yellow-200');
            alertBox.classList.add('bg-orange-50', 'border-orange-200');
            alertBox.innerHTML = `
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-orange-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <h3 class="text-lg font-semibold text-orange-900">Proses Memakan Waktu Lama</h3>
                        <p class="text-sm text-orange-700 mt-1">Analisis masih berjalan tetapi memakan waktu lebih lama dari biasanya. Anda dapat refresh halaman secara manual.</p>
                        <button onclick="location.reload()" class="mt-3 inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Refresh Halaman
                        </button>
                    </div>
                </div>
            `;
        }
    }
    
    // Cleanup when leaving page
    window.addEventListener('beforeunload', function() {
        stopPolling();
    });
});
</script>
@endpush
@endif

{{-- @if($analysis->status == 'pending' || $analysis->status == 'processing')
@push('scripts')
<script>
    let refreshInterval;
    let refreshCount = 0;
    const maxRefresh = 60; // Maximum 60 refreshes (5 minutes if interval is 5 seconds)
    
    function checkStatus() {
        refreshCount++;
        
        // Stop after max attempts
        if (refreshCount >= maxRefresh) {
            clearInterval(refreshInterval);
            showTimeoutMessage();
            return;
        }
        
        // Fetch current status via AJAX
        fetch('{{ route("analysis.status", $analysis->id) }}', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            // console.log('Status check:', data.status);
            
            // Update status badge if exists
            updateStatusBadge(data.status);
            
            // If status changed to completed or failed, reload page
            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(refreshInterval);
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error checking status:', error);
        });
    }
    
    function updateStatusBadge(status) {
        const statusText = document.querySelector('.status-text');
        const statusKet = document.querySelector('.status-ket');
        if (statusText && statusKet) {
            if (status === 'processing') {
                statusText.textContent = 'Analisis Sedang Diproses';
                statusText.textContent = 'Harap tunggu, halaman akan otomatis refresh.';
            } else if (status === 'pending') {
                statusText.textContent = 'Analisis Dalam Antrian';
                statusText.textContent = 'Analisis Anda sedang menunggu untuk diproses.';
            }
        }
    }
    
    function showTimeoutMessage() {
        const alertBox = document.querySelector('.bg-yellow-50');
        if (alertBox) {
            alertBox.classList.remove('bg-yellow-50', 'border-yellow-200');
            alertBox.classList.add('bg-red-50', 'border-red-200');
            alertBox.innerHTML = `
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-red-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <h3 class="text-lg font-semibold text-red-900">Proses Timeout</h3>
                        <p class="text-sm text-red-700 mt-1">Proses analisis memakan waktu lebih lama dari biasanya. Silakan refresh halaman secara manual.</p>
                        <button onclick="location.reload()" class="mt-3 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Refresh Halaman
                        </button>
                    </div>
                </div>
            `;
        }
    }
    
    // Start checking status every 3 seconds
    refreshInterval = setInterval(checkStatus, 3000);
    
    // Also check immediately on page load
    checkStatus();
</script>
@endpush
@endif --}}

@if($analysis->status == 'completed' && $analysis->result)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/wordcloud2.js/1.2.2/wordcloud2.min.js"></script>
<script>
    // ==========================================
    // Sentiment Chart
    // ==========================================
    @if($result->sentiment_distribution)
    const sentimentData = @json($result->sentiment_distribution);
    
    const sentimentCtx = document.getElementById('sentimentChart');
    if (sentimentCtx) {
        new Chart(sentimentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Positif', 'Netral', 'Negatif'],
                datasets: [{
                    data: [
                        sentimentData.positive || 0,
                        sentimentData.neutral || 0,
                        sentimentData.negative || 0
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(107, 114, 128, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderColor: [
                        'rgb(16, 185, 129)',
                        'rgb(107, 114, 128)',
                        'rgb(239, 68, 68)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 14
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.parsed + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    @endif

    // ==========================================
    // Aspect Chart
    // ==========================================
    @if(!empty($chartData['aspects']))
    const aspectData = @json($chartData['aspects'] ?? []);
    
    const aspectCtx = document.getElementById('aspectChart');
    if (aspectCtx) {
        const aspectLabels = aspectData.map(a => a.aspect.charAt(0).toUpperCase() + a.aspect.slice(1));
        const positiveData = aspectData.map(a => a.sentiments.positive || 0);
        const neutralData = aspectData.map(a => a.sentiments.neutral || 0);
        const negativeData = aspectData.map(a => a.sentiments.negative || 0);
        
        new Chart(aspectCtx, {
            type: 'bar',
            data: {
                labels: aspectLabels,
                datasets: [
                    {
                        label: 'Positif',
                        data: positiveData,
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 1
                    },
                    {
                        label: 'Netral',
                        data: neutralData,
                        backgroundColor: 'rgba(107, 114, 128, 0.8)',
                        borderColor: 'rgb(107, 114, 128)',
                        borderWidth: 1
                    },
                    {
                        label: 'Negatif',
                        data: negativeData,
                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                        borderColor: 'rgb(239, 68, 68)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    @endif

    // ==========================================
    // Topic Chart
    // ==========================================
    @if(isset($result->topic_results['topics']))
    const topicData = @json($result->topic_results['topics']);
    
    const topicCtx = document.getElementById('topicChart');
    if (topicCtx) {
        const topicLabels = topicData.map(t => 'Topik #' + (t.topic_id + 1));
        const topicProportions = topicData.map(t => ((t.proportion ?? 0) * 100).toFixed(1));
        new Chart(topicCtx, {
            type: 'bar',
            data: {
                labels: topicLabels,
                datasets: [{
                    label: 'Proporsi Topik',
                    data: topicProportions,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(249, 115, 22, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(236, 72, 153, 0.8)',
                        'rgba(14, 165, 233, 0.8)',
                        'rgba(34, 197, 94, 0.8)'
                    ],
                    borderColor: [
                        'rgb(59, 130, 246)',
                        'rgb(16, 185, 129)',
                        'rgb(249, 115, 22)',
                        'rgb(168, 85, 247)',
                        'rgb(236, 72, 153)',
                        'rgb(14, 165, 233)',
                        'rgb(34, 197, 94)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Proporsi: ' + context.parsed.x + '%';
                            },
                            afterLabel: function(context) {
                                const topicIndex = context.dataIndex;
                                const words = topicData[topicIndex].words.slice(0, 5).join(', ');
                                return 'Kata kunci: ' + words;
                            }
                        }
                    }
                }
            }
        });
    }
    @endif

    function downloadChart(chartId, filename) {
        const canvas = document.getElementById(chartId);
        if (!canvas) return;
        
        const url = canvas.toDataURL('image/png');
        const link = document.createElement('a');
        link.download = filename + '.png';
        link.href = url;
        link.click();
    }
    
    // Add download buttons to charts
    document.addEventListener('DOMContentLoaded', function() {
        const charts = ['sentimentChart', 'aspectChart', 'topicChart'];
        
        charts.forEach(chartId => {
            const canvas = document.getElementById(chartId);
            if (!canvas) return;
            
            const container = canvas.parentElement.parentElement;
            const header = container.querySelector('h3');
            
            if (header) {
                const downloadBtn = document.createElement('button');
                downloadBtn.innerHTML = `
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                `;
                downloadBtn.className = 'ml-auto text-gray-600 hover:text-blue-600 transition-colors';
                downloadBtn.onclick = () => downloadChart(chartId, chartId);
                
                const headerContainer = document.createElement('div');
                headerContainer.className = 'flex items-center justify-between mb-4';
                header.parentNode.insertBefore(headerContainer, header);
                headerContainer.appendChild(header);
                headerContainer.appendChild(downloadBtn);
            }
        });
    });
</script>
@endpush
@endif

@push('scripts')
<script>
(function() {
    var data = window.__wordCloudData;
    var canvas = document.getElementById('wordCloudCanvasEl');
    if (!canvas || !data || data.length === 0) return;

    var parent = canvas.parentElement;
    canvas.width = parent.clientWidth;
    canvas.height = parent.clientHeight;

    WordCloud(canvas, {
        list: data,
        gridSize: 8,
        weightFactor: function(size) { return size * 1.6; },
        fontFamily: 'Inter, system-ui, -apple-system, sans-serif',
        fontWeight: '600',
        color: function(word, weight) {
            if (weight > 40) return '#4338ca';
            if (weight > 25) return '#6366f1';
            if (weight > 15) return '#818cf8';
            return '#a5b4fc';
        },
        backgroundColor: '#ffffff',
        rotateRatio: 0.3,
        rotationSteps: 2,
        minSize: 10,
        shuffle: false,
        drawOutOfBound: false,
        shrinkToFit: true,
        click: function(item) {
            var search = document.getElementById('searchPredictions');
            if (search && item && item[0]) {
                search.value = item[0];
                search.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ---------------------------------------------------------------- AI
    // Satu penangan untuk semua tombol interpretasi. Bagian "topic" memakai
    // markup lamanya sendiri karena keluarannya label per topik, bukan paragraf.
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

    function mintaInterpretasi(url, regenerate) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ regenerate: regenerate ? 1 : 0 }),
        }).then(async (response) => {
            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.success) {
                // 429 datang dari throttle rute, bukan dari Gemini.
                throw new Error(
                    response.status === 429
                        ? 'Terlalu banyak permintaan. Tunggu satu menit lalu coba lagi.'
                        : (data.message || 'Gagal menghasilkan interpretasi.')
                );
            }

            return data;
        });
    }

    // --- Narasi per bagian (overview, sentiment, aspect, association)
    document.querySelectorAll('[data-ai-interpret]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const blok = btn.closest('[data-ai-block]');
            const kotakError = blok.querySelector('[data-ai-error]');
            const hasil = blok.querySelector('[data-ai-hasil]');
            const label = btn.querySelector('[data-ai-label]');
            const teksAwal = label.textContent;

            // Kotak hasil yang sudah terlihat berarti pengguna minta versi baru.
            const regenerate = !hasil.classList.contains('hidden');

            kotakError.classList.add('hidden');
            btn.disabled = true;
            hasil.setAttribute('aria-busy', 'true');
            label.textContent = regenerate ? 'Membangkitkan ulang...' : 'Memproses...';

            mintaInterpretasi(btn.dataset.aiUrl, regenerate)
                .then((data) => {
                    const narasi = data.data || {};

                    blok.querySelector('[data-ai-narrative]').textContent = narasi.narrative || '';

                    const daftar = blok.querySelector('[data-ai-highlights]');
                    daftar.innerHTML = '';

                    (narasi.highlights || []).forEach((poin) => {
                        const li = document.createElement('li');
                        li.className = 'text-sm text-gray-700 flex gap-2';

                        const bullet = document.createElement('span');
                        bullet.className = 'text-indigo-400 mt-0.5';
                        bullet.setAttribute('aria-hidden', 'true');
                        bullet.textContent = '\u2022';

                        const teks = document.createElement('span');
                        // textContent, bukan innerHTML: keluaran model tidak
                        // pernah disisipkan sebagai HTML.
                        teks.textContent = poin;

                        li.append(bullet, teks);
                        daftar.appendChild(li);
                    });

                    daftar.classList.toggle('hidden', (narasi.highlights || []).length === 0);

                    const jejak = blok.querySelector('[data-ai-provenance]');

                    if (jejak) {
                        jejak.textContent = 'Ditulis oleh AI (' + (narasi.model || '-') + ') baru saja'
                            + ' \u2014 angka tetap berasal dari hasil analisis, bukan dari AI.';
                    }

                    hasil.classList.remove('hidden');
                    label.textContent = 'Bangkitkan Ulang';
                })
                .catch((error) => {
                    kotakError.textContent = error.message;
                    kotakError.classList.remove('hidden');
                    label.textContent = teksAwal;
                })
                .finally(() => {
                    btn.disabled = false;
                    hasil.setAttribute('aria-busy', 'false');
                });
        });
    });

    // --- Label topik
    const btnGenerateAI = document.getElementById('btnGenerateAI');

    if (btnGenerateAI) {
        btnGenerateAI.addEventListener('click', function () {
            const label = document.getElementById('labelBtnGenerateAI');
            const kotakError = document.getElementById('aiTopikError');
            const memuat = document.getElementById('aiLoadingIndicator');
            const regenerate = btnGenerateAI.dataset.sudah === '1';
            const teksAwal = label.textContent;

            kotakError.classList.add('hidden');
            btnGenerateAI.disabled = true;
            label.textContent = 'Memproses...';
            memuat.classList.remove('hidden');

            mintaInterpretasi(btnGenerateAI.dataset.aiUrl, regenerate)
                .then((data) => {
                    const topik = (data.data && data.data.topics) || {};

                    Object.entries(topik).forEach(([topicId, interpretasi]) => {
                        const labelEl = document.getElementById('topic-label-' + topicId);
                        const descEl = document.getElementById('topic-desc-' + topicId);

                        if (labelEl) labelEl.textContent = interpretasi.label;

                        if (descEl) {
                            descEl.textContent = interpretasi.description;
                            descEl.classList.remove('hidden');
                        }
                    });

                    btnGenerateAI.dataset.sudah = '1';
                    label.textContent = 'Bangkitkan Ulang Label';

                    if (!document.getElementById('badgeTopikAI')) {
                        const badge = document.createElement('span');
                        badge.id = 'badgeTopikAI';
                        badge.className = 'inline-flex items-center px-2.5 py-1 bg-purple-100 text-purple-800 text-xs font-semibold rounded-full border border-purple-200';
                        badge.textContent = 'Diinterpretasikan oleh AI';
                        btnGenerateAI.parentNode.insertBefore(badge, btnGenerateAI);
                    }
                })
                .catch((error) => {
                    kotakError.textContent = error.message;
                    kotakError.classList.remove('hidden');
                    label.textContent = teksAwal;
                })
                .finally(() => {
                    btnGenerateAI.disabled = false;
                    memuat.classList.add('hidden');
                });
        });
    }
});
</script>
@endpush
@endsection