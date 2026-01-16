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
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('analysis.export-pdf', $analysis->id) }}" 
               class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
                </svg>
                Export PDF
            </a>
            <a href="{{ route('analysis.export-csv', $analysis->id) }}" 
               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export CSV
            </a>
        </div>
        @endif
    </div>

    <!-- Status Alert & Content -->
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

        <!-- Metrics Cards -->
        @if($result->metrics)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($result->metrics as $key => $value)
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

            <!-- Predictions List -->
            @if($result->predictions)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Detail Prediksi</h3>
                            <p class="text-sm text-gray-500 mt-1">Menampilkan {{ count($result->predictions) }} teks yang dianalisis</p>
                        </div>
                        
                        <!-- Filter Buttons -->
                        <div class="flex gap-2">
                            <button onclick="filterPredictions('all')" class="filter-btn active px-3 py-1.5 rounded-lg text-sm font-medium bg-blue-600 text-white" data-filter="all">
                                Semua
                            </button>
                            <button onclick="filterPredictions('positive')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="positive">
                                Positif
                            </button>
                            <button onclick="filterPredictions('neutral')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="neutral">
                                Netral
                            </button>
                            <button onclick="filterPredictions('negative')" class="filter-btn px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200" data-filter="negative">
                                Negatif
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Search Box -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <input type="text" 
                        id="searchPredictions" 
                        placeholder="Cari teks..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="p-6">
                    <!-- Predictions Container -->
                    <div id="predictions-container" class="space-y-3 max-h-[600px] overflow-y-auto">
                        @foreach($result->predictions as $index => $pred)
                        <div class="prediction-card {{ $pred['sentiment'] }} p-4 rounded-lg" data-sentiment="{{ $pred['sentiment'] }}" data-text="{{ strtolower($pred['text']) }}">
                            <div class="flex items-start justify-between gap-4">
                                <!-- Text Content -->
                                <div class="flex-1 min-w-0">
                                    <!-- Original Text (Displayed) -->
                                    <div class="mb-2">
                                        <p class="text-gray-800 leading-relaxed">{{ $pred['text'] }}</p>
                                    </div>
                                    
                                    <!-- Metadata -->
                                    <div class="flex flex-wrap items-center gap-3">
                                        <!-- Sentiment Badge -->
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($pred['sentiment'] == 'positive') bg-green-100 text-green-800
                                            @elseif($pred['sentiment'] == 'negative') bg-red-100 text-red-800
                                            @else bg-gray-100 text-gray-800
                                            @endif">
                                            @if($pred['sentiment'] == 'positive')
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                </svg>
                                            @elseif($pred['sentiment'] == 'negative')
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                                </svg>
                                            @else
                                                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                                                </svg>
                                            @endif
                                            {{ ucfirst($pred['sentiment']) }}
                                        </span>
                                        
                                        <!-- Confidence -->
                                        @if(isset($pred['confidence']))
                                        <span class="text-xs text-gray-500 flex items-center">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                                                <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/>
                                            </svg>
                                            Confidence: <strong class="ml-1">{{ round($pred['confidence'] * 100, 1) }}%</strong>
                                        </span>
                                        @endif
                                        
                                        <!-- Show Processed Text Toggle (Optional) -->
                                        @if(isset($pred['processed_text']) && $pred['processed_text'] != $pred['text'])
                                        <button 
                                            onclick="toggleProcessedText({{ $index }})" 
                                            class="text-xs text-blue-600 hover:text-blue-800 flex items-center">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Lihat teks terproses
                                        </button>
                                        @endif
                                    </div>
                                    
                                    <!-- Processed Text (Hidden by default) -->
                                    @if(isset($pred['processed_text']) && $pred['processed_text'] != $pred['text'])
                                    <div id="processed-text-{{ $index }}" class="hidden mt-3 p-3 bg-gray-50 rounded border border-gray-200">
                                        <p class="text-xs text-gray-500 mb-1 font-semibold">Teks Setelah Preprocessing:</p>
                                        <p class="text-sm text-gray-700 font-mono">{{ $pred['processed_text'] }}</p>
                                    </div>
                                    @endif
                                    
                                    <!-- Sentiment Scores (if available) -->
                                    @if(isset($pred['scores']))
                                    <div class="mt-3 space-y-1">
                                        <p class="text-xs text-gray-500 font-semibold mb-2">Skor Detail:</p>
                                        @foreach($pred['scores'] as $sentiment => $score)
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-gray-600 w-20 capitalize">{{ $sentiment }}:</span>
                                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                                <div class="h-2 rounded-full transition-all duration-500
                                                    @if($sentiment == 'positive') bg-green-500
                                                    @elseif($sentiment == 'negative') bg-red-500
                                                    @else bg-gray-500
                                                    @endif"
                                                    style="width: {{ $score * 100 }}%">
                                                </div>
                                            </div>
                                            <span class="text-xs text-gray-600 w-12 text-right">{{ round($score * 100, 1) }}%</span>
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                                
                                <!-- Index Number -->
                                <div class="flex-shrink-0">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 text-gray-600 text-sm font-semibold">
                                        {{ $index + 1 }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    
                    <!-- Results Count -->
                    <div class="mt-4 text-center text-sm text-gray-500">
                        <span id="predictions-count">Menampilkan {{ count($result->predictions) }} dari {{ count($result->predictions) }} prediksi</span>
                    </div>
                </div>
            </div>

            @push('scripts')
            <script>
                const allPredictions = @json($result->predictions);
                let currentFilter = 'all';
                
                // Toggle processed text visibility
                function toggleProcessedText(index) {
                    const element = document.getElementById(`processed-text-${index}`);
                    if (element) {
                        element.classList.toggle('hidden');
                    }
                }
                
                // Filter predictions by sentiment
                function filterPredictions(sentiment) {
                    currentFilter = sentiment;
                    
                    // Update button states
                    document.querySelectorAll('.filter-btn').forEach(btn => {
                        btn.classList.remove('active', 'bg-blue-600', 'text-white');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    
                    const activeBtn = document.querySelector(`[data-filter="${sentiment}"]`);
                    if (activeBtn) {
                        activeBtn.classList.add('active', 'bg-blue-600', 'text-white');
                        activeBtn.classList.remove('bg-gray-100', 'text-gray-700');
                    }
                    
                    applyFilters();
                }
                
                // Search functionality
                document.getElementById('searchPredictions')?.addEventListener('input', function(e) {
                    applyFilters();
                });
                
                // Apply filters
                function applyFilters() {
                    const searchTerm = document.getElementById('searchPredictions')?.value.toLowerCase() || '';
                    const cards = document.querySelectorAll('.prediction-card');
                    let visibleCount = 0;
                    
                    cards.forEach(card => {
                        const sentiment = card.dataset.sentiment;
                        const text = card.dataset.text;
                        
                        let shouldShow = true;
                        
                        // Filter by sentiment
                        if (currentFilter !== 'all' && sentiment !== currentFilter) {
                            shouldShow = false;
                        }
                        
                        // Filter by search term
                        if (searchTerm && !text.includes(searchTerm)) {
                            shouldShow = false;
                        }
                        
                        // Show/hide card
                        if (shouldShow) {
                            card.style.display = 'block';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });
                    
                    // Update count
                    const countEl = document.getElementById('predictions-count');
                    if (countEl) {
                        countEl.textContent = `Menampilkan ${visibleCount} dari ${allPredictions.length} prediksi`;
                    }
                }
            </script>
            @endpush
            @endif

            <!-- Interactive Predictions Filter -->
            @if($result->predictions && count($result->predictions) > 10)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Filter Hasil</h3>
                    <div class="flex gap-2">
                        <button onclick="filterPredictions('all')" class="filter-btn active px-4 py-2 rounded-lg text-sm font-medium" data-filter="all">
                            Semua
                        </button>
                        <button onclick="filterPredictions('positive')" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium" data-filter="positive">
                            Positif
                        </button>
                        <button onclick="filterPredictions('neutral')" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium" data-filter="neutral">
                            Netral
                        </button>
                        <button onclick="filterPredictions('negative')" class="filter-btn px-4 py-2 rounded-lg text-sm font-medium" data-filter="negative">
                            Negatif
                        </button>
                    </div>
                </div>
                
                <input type="text" 
                    id="searchPredictions" 
                    placeholder="Cari teks..." 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            @push('scripts')
            <script>
                const allPredictions = @json($result->predictions);
                let currentFilter = 'all';
                
                function filterPredictions(sentiment) {
                    currentFilter = sentiment;
                    
                    // Update button states
                    document.querySelectorAll('.filter-btn').forEach(btn => {
                        btn.classList.remove('active', 'bg-blue-600', 'text-white');
                        btn.classList.add('bg-gray-100', 'text-gray-700');
                    });
                    
                    const activeBtn = document.querySelector(`[data-filter="${sentiment}"]`);
                    activeBtn.classList.add('active', 'bg-blue-600', 'text-white');
                    activeBtn.classList.remove('bg-gray-100', 'text-gray-700');
                    
                    renderPredictions();
                }
                
                function renderPredictions() {
                    const searchTerm = document.getElementById('searchPredictions').value.toLowerCase();
                    
                    let filtered = allPredictions;
                    
                    // Filter by sentiment
                    if (currentFilter !== 'all') {
                        filtered = filtered.filter(p => p.sentiment === currentFilter);
                    }
                    
                    // Filter by search term
                    if (searchTerm) {
                        filtered = filtered.filter(p => p.text.toLowerCase().includes(searchTerm));
                    }
                    
                    // Render results
                    const container = document.getElementById('predictions-container');
                    if (!container) return;
                    
                    container.innerHTML = filtered.map(pred => `
                        <div class="prediction-card ${pred.sentiment} p-4 rounded-lg">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <p class="text-gray-800">${pred.text}</p>
                                    <div class="mt-2 flex items-center gap-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            ${pred.sentiment === 'positive' ? 'bg-green-100 text-green-800' : 
                                            pred.sentiment === 'negative' ? 'bg-red-100 text-red-800' : 
                                            'bg-gray-100 text-gray-800'}">
                                            ${pred.sentiment.charAt(0).toUpperCase() + pred.sentiment.slice(1)}
                                        </span>
                                        ${pred.confidence ? `
                                        <span class="text-xs text-gray-500">
                                            Confidence: ${Math.round(pred.confidence * 100)}%
                                        </span>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('');
                    
                    // Show count
                    document.getElementById('predictions-count').textContent = 
                        `Menampilkan ${filtered.length} dari ${allPredictions.length} prediksi`;
                }
                
                // Search functionality
                document.getElementById('searchPredictions')?.addEventListener('input', renderPredictions);
            </script>
            @endpush
            @endif
            @endif
        @endif

        <!-- Aspect Analysis Results -->
        @if(($analysis->analysis_type == 'aspect' || $analysis->analysis_type == 'combined') && $result->aspect_results)
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
                    @foreach($result->aspect_results as $aspect)
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
        </div>
        @endif

        <!-- Topic Modeling Results -->
        @if(($analysis->analysis_type == 'topic' || $analysis->analysis_type == 'combined') && $result->topic_results)
        <div class="space-y-6">
            <!-- Topics Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Topic Distribution -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Distribusi Topik</h3>
                    <div class="chart-container">
                        <canvas id="topicChart"></canvas>
                    </div>
                </div>

                <!-- Word Cloud -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Word Cloud</h3>
                    <div class="flex flex-wrap justify-center items-center p-4" id="wordCloud">
                        @if(isset($result->topic_results['word_frequencies']))
                            @foreach(array_slice($result->topic_results['word_frequencies'], 0, 30) as $word)
                            <span class="word-cloud-item" style="font-size: {{ 12 + ($word['frequency'] / 2) }}px;">
                                {{ $word['word'] }}
                            </span>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            <!-- Topic Details -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Detail Topik</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($result->topic_results['topics'] as $topic)
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold text-gray-900">Topik #{{ $topic['topic_id'] + 1 }}</h4>
                            <span class="text-sm text-gray-500">{{ round($topic['proportion'] * 100, 1) }}%</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach(array_slice($topic['words'], 0, 8) as $word)
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm rounded-full">
                                {{ $word }}
                            </span>
                            @endforeach
                        </div>
                        <p class="mt-3 text-sm text-gray-600">
                            Muncul pada {{ $topic['size'] }} teks
                        </p>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

    @endif
</div>

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
    @if($result->aspect_results)
    const aspectData = @json($result->aspect_results);
    
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
        const topicProportions = topicData.map(t => (t.proportion * 100).toFixed(1));
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
@endsection