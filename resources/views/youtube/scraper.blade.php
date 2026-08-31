@extends('layouts.app')

@push('styles')
<style>
    /* ── Tab System ── */
    .tab-button {
        position: relative;
        padding: 0.75rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        color: #6b7280;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
        cursor: pointer;
        background: none;
        border-top: none;
        border-left: none;
        border-right: none;
    }
    .tab-button:hover {
        color: #1f2937;
        background: #f9fafb;
    }
    .tab-button.active {
        color: #dc2626;
        border-bottom-color: #dc2626;
    }
    .tab-content {
        display: none;
        animation: fadeIn 0.35s ease;
    }
    .tab-content.active { display: block; }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Video Cards ── */
    .video-card {
        transition: all 0.3s cubic-bezier(.4,0,.2,1);
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    .video-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px rgba(0,0,0,.12);
    }
    .video-card.selected {
        border: 2px solid #3B82F6;
        box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    }
    .video-card.selected .card-check {
        opacity: 1;
        transform: scale(1);
    }
    .card-check {
        position: absolute;
        top: 12px;
        left: 12px;
        width: 28px;
        height: 28px;
        background: #3B82F6;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        opacity: 0;
        transform: scale(0.5);
        transition: all 0.25s cubic-bezier(.4,0,.2,1);
        z-index: 10;
        box-shadow: 0 2px 8px rgba(59,130,246,.4);
    }
    .video-card:hover .card-check {
        opacity: 0.7;
        transform: scale(0.9);
    }
    .video-card.selected .card-check {
        opacity: 1 !important;
        transform: scale(1) !important;
    }

    /* ── Thumbnail ── */
    .video-thumbnail {
        position: relative;
        padding-bottom: 56.25%;
        overflow: hidden;
        background: #0f0f0f;
    }
    .video-thumbnail img {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }
    .video-card:hover .video-thumbnail img {
        transform: scale(1.05);
    }
    .video-duration {
        position: absolute;
        bottom: 8px; right: 8px;
        background: rgba(0,0,0,.85);
        color: #fff;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: .3px;
    }

    /* ── Skeleton Loading ── */
    .skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
        border-radius: 4px;
    }
    @keyframes loading {
        0%   { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }

    /* ── Stats ── */
    .stats-icon {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: #6b7280;
    }

    /* ── API Status Badge ── */
    .api-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
    .api-badge.active   { background: #d1fae5; color: #065f46; }
    .api-badge.warning  { background: #fef3c7; color: #92400e; }
    .api-badge.error    { background: #fee2e2; color: #991b1b; }
    .api-badge .dot { width: 7px; height: 7px; border-radius: 50%; }
    .api-badge.active .dot  { background: #10b981; }
    .api-badge.warning .dot { background: #f59e0b; }
    .api-badge.error .dot   { background: #ef4444; }

    /* ── Direct Link Cards ── */
    .url-preview-card {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px;
        transition: all 0.3s ease;
        animation: slideUp 0.3s ease;
    }
    .url-preview-card:hover { border-color: #3B82F6; }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(12px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Load More Button ── */
    .load-more-btn {
        position: relative;
        overflow: hidden;
    }
    .load-more-btn::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(255,255,255,.1), rgba(255,255,255,0));
        transform: translateX(-100%);
        transition: transform .5s ease;
    }
    .load-more-btn:hover::before {
        transform: translateX(100%);
    }

    /* ── Pulse ring animation ── */
    @keyframes pulseRing {
        0%   { box-shadow: 0 0 0 0 rgba(59,130,246,.4); }
        70%  { box-shadow: 0 0 0 8px rgba(59,130,246,0); }
        100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
    }
    .pulse-ring { animation: pulseRing 2s infinite; }

    /* ── Tooltip ── */
    .tooltip-wrapper {
        position: relative;
    }
    .tooltip-wrapper .tooltip-text {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        bottom: calc(100% + 6px);
        left: 50%;
        transform: translateX(-50%);
        background: #1f2937;
        color: #fff;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        white-space: nowrap;
        transition: all 0.2s ease;
        z-index: 50;
    }
    .tooltip-wrapper:hover .tooltip-text {
        visibility: visible;
        opacity: 1;
    }
</style>
@endpush

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">YouTube Comment Scraper</h1>
            <p class="mt-1 text-sm text-gray-600">Kumpulkan data komentar dari video YouTube untuk analisis</p>
        </div>
        <div class="mt-4 sm:mt-0 flex items-center gap-3">
            <!-- API Status Badge -->
            <div id="api-status-badge" class="api-badge warning">
                <span class="dot"></span>
                <span id="api-status-text">Memeriksa...</span>
            </div>
            <a href="{{ route('analysis.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="flex border-b border-gray-200">
            <button class="tab-button active" data-tab="search">
                <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Pencarian
            </button>
            <button class="tab-button" data-tab="direct-link">
                <svg class="w-4 h-4 inline mr-1.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                Link Langsung
            </button>
        </div>

        <!-- ═══════════════════ Tab: Pencarian ═══════════════════ -->
        <div id="tab-search" class="tab-content active p-6">
            <div class="relative">
                <div class="flex gap-3">
                    <div class="flex-1 relative">
                        <input 
                            type="text" 
                            id="search-input"
                            placeholder="Cari video YouTube..." 
                            class="w-full pl-12 pr-4 py-3.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm"
                            autocomplete="off">
                        <svg class="absolute left-4 top-4 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <button 
                        type="button"
                        id="search-button"
                        class="px-8 py-3.5 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-xl font-semibold hover:shadow-lg transform hover:scale-[1.02] transition-all duration-300 text-sm">
                        <svg class="w-5 h-5 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Cari
                    </button>
                </div>
            </div>

            <!-- Search Filters -->
            <div class="mt-4 flex flex-wrap gap-3">
                <select id="filter-upload-date" class="px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-transparent bg-white">
                    <option value="">Kapan Saja</option>
                    <option value="last_hour">1 Jam Terakhir</option>
                    <option value="today">Hari Ini</option>
                    <option value="this_week">Minggu Ini</option>
                    <option value="this_month">Bulan Ini</option>
                    <option value="this_year">Tahun Ini</option>
                </select>

                <select id="filter-duration" class="px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-transparent bg-white">
                    <option value="">Semua Durasi</option>
                    <option value="short">Pendek (< 4 menit)</option>
                    <option value="medium">Sedang (4-20 menit)</option>
                    <option value="long">Panjang (> 20 menit)</option>
                </select>
                
                <select id="filter-sort" class="px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-transparent bg-white">
                    <option value="relevance">Relevansi</option>
                    <option value="date">Tanggal Upload</option>
                    <option value="viewCount">Jumlah View</option>
                    <option value="rating">Rating</option>
                </select>
            </div>
        </div>

        <!-- ═══════════════════ Tab: Link Langsung ═══════════════════ -->
        <div id="tab-direct-link" class="tab-content p-6">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-2">Masukkan URL Video YouTube</label>
                    <p class="text-xs text-gray-500 mb-3">Paste satu atau lebih URL YouTube (satu per baris). Mendukung format: youtube.com/watch?v=..., youtu.be/..., youtube.com/shorts/...</p>
                    <textarea
                        id="direct-url-input"
                        rows="4"
                        class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none"
                        placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ&#10;https://youtu.be/jNQXAC9IVRw&#10;https://www.youtube.com/watch?v=..."></textarea>
                </div>

                <div class="flex gap-3">
                    <button
                        type="button"
                        id="parse-urls-button"
                        class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                        <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Ambil Info Video
                    </button>
                    <button
                        type="button"
                        id="direct-scrape-button"
                        class="hidden px-6 py-2.5 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                        <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                        Ambil Komentar dari Video Terpilih
                    </button>
                </div>

                <!-- URL Preview Area -->
                <div id="url-previews" class="space-y-3 hidden">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-gray-800">Video Ditemukan</h4>
                        <div class="flex gap-2">
                            <button type="button" id="select-all-urls" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Pilih Semua</button>
                            <span class="text-gray-300">|</span>
                            <button type="button" id="clear-all-urls" class="text-xs text-gray-500 hover:text-gray-700 font-medium">Bersihkan</button>
                        </div>
                    </div>
                    <div id="url-preview-list" class="space-y-2 max-h-96 overflow-y-auto"></div>
                </div>

                <!-- URL Loading State -->
                <div id="url-loading" class="hidden text-center py-8">
                    <svg class="w-8 h-8 text-blue-600 animate-spin mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <p class="text-sm text-gray-600">Mengambil informasi video...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Selected Videos Counter -->
    <div id="selected-counter" class="hidden bg-blue-50 border border-blue-200 rounded-xl p-4 transition-all">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-blue-100 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <span class="font-semibold text-blue-900">
                    <span id="selected-count">0</span> video dipilih
                </span>
            </div>
            <div class="flex gap-2">
                <button 
                    type="button"
                    id="clear-selection"
                    class="px-4 py-2 text-sm text-blue-600 hover:text-blue-800 font-medium rounded-lg hover:bg-blue-100 transition-colors">
                    Bersihkan
                </button>
                <button 
                    type="button"
                    id="scrape-button"
                    class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors pulse-ring">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                    Ambil Komentar
                </button>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="loading-state" class="hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @for($i = 0; $i < 6; $i++)
            <div class="bg-white rounded-xl overflow-hidden border border-gray-200">
                <div class="skeleton h-48"></div>
                <div class="p-4 space-y-3">
                    <div class="skeleton h-4 w-3/4 rounded"></div>
                    <div class="skeleton h-3 w-full rounded"></div>
                    <div class="skeleton h-3 w-2/3 rounded"></div>
                </div>
            </div>
            @endfor
        </div>
    </div>

    <!-- Empty State -->
    <div id="empty-state" class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
        <div class="w-20 h-20 bg-red-50 rounded-2xl flex items-center justify-center mx-auto mb-5">
            <svg class="h-10 w-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
        </div>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">Cari Video YouTube</h3>
        <p class="text-gray-500 mb-6 max-w-md mx-auto">Gunakan pencarian untuk menemukan video, atau paste link YouTube langsung di tab "Link Langsung"</p>
        <div class="flex flex-wrap gap-2 justify-center">
            <span class="px-4 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-red-50 hover:text-red-700 transition-colors" onclick="quickSearch('Tutorial Laravel')">Tutorial Laravel</span>
            <span class="px-4 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-red-50 hover:text-red-700 transition-colors" onclick="quickSearch('Review Smartphone')">Review Smartphone</span>
            <span class="px-4 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-red-50 hover:text-red-700 transition-colors" onclick="quickSearch('Vlog Indonesia')">Vlog</span>
            <span class="px-4 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-red-50 hover:text-red-700 transition-colors" onclick="quickSearch('Gaming Indonesia')">Gaming</span>
            <span class="px-4 py-1.5 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-red-50 hover:text-red-700 transition-colors" onclick="quickSearch('Berita Hari Ini')">Berita</span>
        </div>
    </div>

    <!-- Video Results -->
    <div id="video-results" class="hidden">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">
                    Hasil Pencarian: <span id="search-query" class="text-red-600"></span>
                </h3>
                <span id="results-count" class="text-sm text-gray-500"></span>
            </div>
            <div class="flex gap-2">
                <button type="button" id="select-all-button" class="px-4 py-2 text-sm text-blue-600 hover:text-blue-800 font-medium border border-blue-200 rounded-lg hover:bg-blue-50 transition-colors">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    Pilih Semua
                </button>
            </div>
        </div>
        
        <div id="videos-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Video cards will be populated here -->
        </div>

        <!-- Load More Button -->
        <div id="load-more-container" class="hidden mt-8 text-center">
            <button 
                type="button" 
                id="load-more-button"
                class="load-more-btn px-8 py-3 bg-gradient-to-r from-gray-800 to-gray-900 text-white rounded-xl font-semibold hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300 text-sm">
                <svg class="w-5 h-5 inline mr-2 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                Lihat Lebih Banyak
                <span id="remaining-text" class="ml-1 text-gray-400"></span>
            </button>
            <div id="load-more-spinner" class="hidden mt-4">
                <svg class="w-6 h-6 text-gray-400 animate-spin mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ Scrape Modal ═══════════════════ -->
<div id="scrape-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeScrapeModal()"></div>
        
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900">
                    <svg class="w-6 h-6 inline mr-2 text-red-600 -mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Pengaturan Scraping
                </h3>
                <button onclick="closeScrapeModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 overflow-y-auto max-h-[60vh]">
                <form id="scrape-form">
                    <div class="mb-6 bg-blue-50 rounded-xl p-4">
                        <h4 class="font-semibold text-blue-900 mb-2">Video Terpilih (<span id="modal-video-count">0</span>)</h4>
                        <div id="selected-videos-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-3">Jumlah Komentar per Video</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="comment_limit" value="100" class="mr-3 text-blue-600" checked>
                                    <div>
                                        <span class="text-sm font-medium">100 komentar</span>
                                        <p class="text-xs text-gray-500">Tercepat</p>
                                    </div>
                                </label>
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="comment_limit" value="500" class="mr-3 text-blue-600">
                                    <div>
                                        <span class="text-sm font-medium">500 komentar</span>
                                        <p class="text-xs text-gray-500">~1-2 menit</p>
                                    </div>
                                </label>
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="comment_limit" value="all" class="mr-3 text-blue-600">
                                    <div>
                                        <span class="text-sm font-medium">Semua komentar</span>
                                        <p class="text-xs text-gray-500">Bisa lama</p>
                                    </div>
                                </label>
                                <label class="flex items-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="comment_limit" value="custom" class="mr-3 text-blue-600">
                                    <div>
                                        <span class="text-sm font-medium">Custom</span>
                                        <p class="text-xs text-gray-500">Tentukan sendiri</p>
                                    </div>
                                </label>
                            </div>
                            <input 
                                type="number" 
                                id="custom-limit"
                                class="hidden mt-3 w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                placeholder="Masukkan jumlah komentar"
                                min="1">
                        </div>

                        <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                            <input type="checkbox" id="include-replies" class="mr-3 rounded text-blue-600">
                            <div>
                                <span class="text-sm font-medium text-gray-800">Sertakan balasan (replies)</span>
                                <p class="text-xs text-gray-500">Termasuk balasan dari setiap komentar utama</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-800 mb-2">Format Output</label>
                            <div class="grid grid-cols-4 gap-2">
                                <label class="text-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="output_format" value="csv" class="hidden" checked>
                                    <div class="text-lg mb-0.5">📊</div>
                                    <span class="text-xs font-medium">CSV</span>
                                </label>
                                <label class="text-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="output_format" value="xlsx" class="hidden">
                                    <div class="text-lg mb-0.5">📗</div>
                                    <span class="text-xs font-medium">Excel</span>
                                </label>
                                <label class="text-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="output_format" value="txt" class="hidden">
                                    <div class="text-lg mb-0.5">📄</div>
                                    <span class="text-xs font-medium">Text</span>
                                </label>
                                <label class="text-center p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300">
                                    <input type="radio" name="output_format" value="json" class="hidden">
                                    <div class="text-lg mb-0.5">🔧</div>
                                    <span class="text-xs font-medium">JSON</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex items-center justify-end space-x-3 p-6 border-t border-gray-200 bg-gray-50">
                <button onclick="closeScrapeModal()" class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 text-sm font-medium transition-colors">Batal</button>
                <button onclick="startScraping()" class="px-6 py-2.5 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                    <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Mulai Scraping
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ Progress Modal ═══════════════════ -->
<div id="progress-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full p-8">
            <div class="text-center">
                <div class="w-18 h-18 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-5" style="width:72px;height:72px;">
                    <svg class="w-9 h-9 text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-1">Mengambil Komentar...</h3>
                <p class="text-sm text-gray-500 mb-5">Mohon tunggu, proses sedang berjalan</p>
                <div class="w-full bg-gray-200 rounded-full h-2.5 mb-3 overflow-hidden">
                    <div id="progress-bar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-2.5 rounded-full transition-all duration-500 ease-out" style="width: 0%"></div>
                </div>
                <p class="text-xs text-gray-500"><span id="progress-text">Memulai...</span></p>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ Success Modal ═══════════════════ -->
<div id="success-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75" onclick="closeSuccessModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full p-8">
            <div class="text-center">
                <div class="w-18 h-18 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5" style="width:72px;height:72px;">
                    <svg class="w-9 h-9 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-1">Scraping Berhasil!</h3>
                <p id="success-message" class="text-sm text-gray-500 mb-5"></p>
                <div id="success-stats" class="grid grid-cols-2 gap-3 mb-5">
                    <div class="bg-gray-50 rounded-lg p-3">
                        <div id="stat-comments" class="text-xl font-bold text-gray-900">0</div>
                        <div class="text-xs text-gray-500">Komentar</div>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3">
                        <div id="stat-videos" class="text-xl font-bold text-gray-900">0</div>
                        <div class="text-xs text-gray-500">Video</div>
                    </div>
                </div>
                <button id="download-result" class="w-full px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-xl font-semibold text-sm hover:shadow-lg transition-all">
                    <svg class="w-5 h-5 inline mr-2 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download File
                </button>
                <button onclick="closeSuccessModal()" class="mt-3 w-full px-6 py-2.5 text-gray-600 hover:text-gray-800 text-sm font-medium transition-colors">Tutup</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // ═══════════════════ State ═══════════════════
    let selectedVideos = new Map();
    let directLinkVideos = new Map();
    let currentSearchQuery = '';
    let nextPageToken = null;
    let currentMode = 'search'; // 'search' | 'direct'
    let allDisplayedVideos = [];
    let downloadUrl = '';

    // ═══════════════════ Init ═══════════════════
    document.addEventListener('DOMContentLoaded', () => {
        checkApiStatus();

        // Tab switching
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
                currentMode = btn.dataset.tab === 'search' ? 'search' : 'direct';
            });
        });

        // Search
        document.getElementById('search-button').addEventListener('click', () => performSearch());
        document.getElementById('search-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') performSearch();
        });

        // Comment limit custom
        document.querySelectorAll('input[name="comment_limit"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('custom-limit').classList.toggle('hidden', this.value !== 'custom');
            });
        });

        // Clear selection
        document.getElementById('clear-selection').addEventListener('click', clearAllSelections);

        // Open scrape modal
        document.getElementById('scrape-button').addEventListener('click', openScrapeModal);

        // Select all (search results)
        document.getElementById('select-all-button').addEventListener('click', toggleSelectAll);

        // Load more
        document.getElementById('load-more-button').addEventListener('click', loadMore);

        // Direct link handlers
        document.getElementById('parse-urls-button').addEventListener('click', parseDirectUrls);
        document.getElementById('direct-scrape-button').addEventListener('click', () => {
            // Move direct link videos to selectedVideos
            selectedVideos = new Map(directLinkVideos);
            updateSelectedCounter();
            openScrapeModal();
        });
        document.getElementById('select-all-urls').addEventListener('click', () => {
            directLinkVideos.forEach((v, id) => directLinkVideos.set(id, {...v, _selected: true}));
            renderDirectLinkPreviews();
            updateDirectScrapeButton();
        });
        document.getElementById('clear-all-urls').addEventListener('click', () => {
            directLinkVideos.clear();
            document.getElementById('url-previews').classList.add('hidden');
            document.getElementById('direct-scrape-button').classList.add('hidden');
        });

        // Download result
        document.getElementById('download-result').addEventListener('click', () => {
            if (downloadUrl) window.location.href = downloadUrl;
        });
    });

    // ═══════════════════ API Status ═══════════════════
    async function checkApiStatus() {
        try {
            const res = await fetch('{{ route("youtube.api-status") }}');
            const data = await res.json();
            const badge = document.getElementById('api-status-badge');
            const text = document.getElementById('api-status-text');

            badge.className = 'api-badge';
            if (data.status === 'active') {
                badge.classList.add('active');
                text.textContent = 'API Aktif';
            } else if (data.status === 'quota_exceeded') {
                badge.classList.add('warning');
                text.textContent = data.fallback_available ? 'Kuota Habis (Fallback Aktif)' : 'Kuota Habis';
            } else if (data.status === 'no_key') {
                badge.classList.add(data.fallback_available ? 'warning' : 'error');
                text.textContent = data.fallback_available ? 'Tanpa API (Fallback)' : 'API Belum Dikonfigurasi';
            } else {
                badge.classList.add('error');
                text.textContent = 'API Error';
            }
        } catch (e) {
            const badge = document.getElementById('api-status-badge');
            badge.className = 'api-badge error';
            document.getElementById('api-status-text').textContent = 'Gagal cek API';
        }
    }

    // ═══════════════════ Quick Search ═══════════════════
    function quickSearch(query) {
        document.getElementById('search-input').value = query;
        // Switch to search tab
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelector('[data-tab="search"]').classList.add('active');
        document.getElementById('tab-search').classList.add('active');
        currentMode = 'search';
        performSearch();
    }

    // ═══════════════════ Search ═══════════════════
    async function performSearch(append = false) {
        const query = document.getElementById('search-input').value.trim();
        if (!query) return showToast('Masukkan kata kunci pencarian', 'warning');

        if (!append) {
            currentSearchQuery = query;
            selectedVideos.clear();
            updateSelectedCounter();
            nextPageToken = null;
            allDisplayedVideos = [];
        }

        document.getElementById('empty-state').classList.add('hidden');
        if (!append) {
            document.getElementById('video-results').classList.add('hidden');
            document.getElementById('loading-state').classList.remove('hidden');
        } else {
            document.getElementById('load-more-spinner').classList.remove('hidden');
            document.getElementById('load-more-button').classList.add('hidden');
        }

        try {
            const body = {
                query: query,
                order: document.getElementById('filter-sort').value,
                videoDuration: document.getElementById('filter-duration').value,
                publishedAfter: document.getElementById('filter-upload-date').value,
                maxResults: 12,
            };
            if (append && nextPageToken) {
                body.pageToken = nextPageToken;
            }

            const response = await fetch('{{ route("youtube.search") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(body)
            });

            const result = await response.json();

            if (response.status === 429 && result.quota_exceeded) {
                showToast(result.message, 'warning');
                document.getElementById('loading-state').classList.add('hidden');
                document.getElementById('empty-state').classList.remove('hidden');
                checkApiStatus();
                return;
            }

            if (result.success) {
                nextPageToken = result.nextPageToken || null;
                if (append) {
                    appendResults(result.data, result.totalResults);
                } else {
                    displayResults(result.data, result.totalResults);
                }
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            document.getElementById('loading-state').classList.add('hidden');
            document.getElementById('load-more-spinner').classList.add('hidden');
            if (!append) {
                document.getElementById('empty-state').classList.remove('hidden');
            }
            showToast('Gagal: ' + error.message, 'error');
        }
    }

    // ═══════════════════ Display Results ═══════════════════
    function displayResults(videos, totalResults) {
        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('video-results').classList.remove('hidden');
        document.getElementById('search-query').textContent = currentSearchQuery;
        
        allDisplayedVideos = videos;
        updateResultsCount(totalResults);

        const grid = document.getElementById('videos-grid');
        grid.innerHTML = '';
        videos.forEach(video => grid.appendChild(createVideoCard(video)));

        // Show/hide load more
        updateLoadMoreButton(totalResults);
    }

    function appendResults(videos, totalResults) {
        document.getElementById('load-more-spinner').classList.add('hidden');
        
        allDisplayedVideos = allDisplayedVideos.concat(videos);
        updateResultsCount(totalResults);

        const grid = document.getElementById('videos-grid');
        videos.forEach(video => grid.appendChild(createVideoCard(video)));

        updateLoadMoreButton(totalResults);
    }

    function updateResultsCount(totalResults) {
        const countEl = document.getElementById('results-count');
        countEl.textContent = `Menampilkan ${allDisplayedVideos.length} dari ${totalResults > 1000000 ? '1M+' : totalResults.toLocaleString('id-ID')} video`;
    }

    function updateLoadMoreButton(totalResults) {
        const container = document.getElementById('load-more-container');
        if (nextPageToken) {
            container.classList.remove('hidden');
            document.getElementById('load-more-button').classList.remove('hidden');
        } else {
            container.classList.add('hidden');
        }
    }

    // ═══════════════════ Load More ═══════════════════
    function loadMore() {
        if (nextPageToken) {
            performSearch(true);
        }
    }

    // ═══════════════════ Video Card ═══════════════════
    function createVideoCard(video) {
        const div = document.createElement('div');
        div.className = 'video-card bg-white rounded-xl overflow-hidden border border-gray-200';
        div.dataset.videoId = video.id;

        const relativeTime = getRelativeTime(video.publishedAt);
        
        div.innerHTML = `
            <div class="video-thumbnail">
                <img src="${video.thumbnail}" alt="${escapeHtml(video.title)}" loading="lazy">
                <span class="video-duration">${video.duration}</span>
                <div class="card-check">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
            </div>
            <div class="p-4">
                <h4 class="font-semibold text-gray-900 line-clamp-2 mb-1.5 text-sm leading-snug min-h-[2.5rem]">${escapeHtml(video.title)}</h4>
                <p class="text-xs text-gray-600 mb-1.5 truncate">${escapeHtml(video.channel)}</p>
                <div class="flex items-center text-xs text-gray-500 gap-2 flex-wrap">
                    <span class="stats-icon">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        ${video.viewCount} views
                    </span>
                    <span class="stats-icon">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/></svg>
                        ${video.likeCount}
                    </span>
                    <span class="stats-icon">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                        ${Number(video.commentCount).toLocaleString('id-ID')}
                    </span>
                    <span class="text-gray-400 ml-auto text-[11px]">${relativeTime}</span>
                </div>
            </div>
        `;

        div.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            toggleVideoSelection(video, div);
        });

        return div;
    }

    // ═══════════════════ Selection ═══════════════════
    function toggleVideoSelection(video, el) {
        if (selectedVideos.has(video.id)) {
            selectedVideos.delete(video.id);
            el.classList.remove('selected');
        } else {
            selectedVideos.set(video.id, video);
            el.classList.add('selected');
        }
        updateSelectedCounter();
    }

    function updateSelectedCounter() {
        const count = selectedVideos.size;
        document.getElementById('selected-count').textContent = count;
        document.getElementById('selected-counter').classList.toggle('hidden', count === 0);
    }

    function clearAllSelections() {
        selectedVideos.clear();
        document.querySelectorAll('.video-card').forEach(card => {
            card.classList.remove('selected');
        });
        updateSelectedCounter();
    }

    let isAllSelected = false;
    function toggleSelectAll() {
        const btn = document.getElementById('select-all-button');
        if (!isAllSelected) {
            allDisplayedVideos.forEach(video => {
                selectedVideos.set(video.id, video);
            });
            document.querySelectorAll('.video-card').forEach(card => card.classList.add('selected'));
            btn.innerHTML = `<svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Batal Pilih Semua`;
            isAllSelected = true;
        } else {
            clearAllSelections();
            btn.innerHTML = `<svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>Pilih Semua`;
            isAllSelected = false;
        }
        updateSelectedCounter();
    }

    // ═══════════════════ Direct Link Parsing ═══════════════════
    async function parseDirectUrls() {
        const input = document.getElementById('direct-url-input').value.trim();
        if (!input) return showToast('Masukkan URL YouTube', 'warning');

        const urls = input.split('\n').map(u => u.trim()).filter(u => u.length > 0);
        if (urls.length === 0) return showToast('Tidak ada URL yang valid', 'warning');

        directLinkVideos.clear();
        document.getElementById('url-loading').classList.remove('hidden');
        document.getElementById('url-previews').classList.add('hidden');

        let successCount = 0;
        let failCount = 0;

        for (const url of urls) {
            try {
                const response = await fetch('{{ route("youtube.video-info") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ url: url })
                });

                const result = await response.json();
                if (result.success && result.data) {
                    const video = result.data;
                    video._selected = true;
                    directLinkVideos.set(video.id, video);
                    successCount++;
                } else {
                    failCount++;
                }
            } catch (e) {
                failCount++;
            }
        }

        document.getElementById('url-loading').classList.add('hidden');

        if (directLinkVideos.size > 0) {
            renderDirectLinkPreviews();
            document.getElementById('url-previews').classList.remove('hidden');
            updateDirectScrapeButton();
        }

        if (failCount > 0) {
            showToast(`${successCount} video ditemukan, ${failCount} URL gagal diproses`, failCount === urls.length ? 'error' : 'warning');
        } else {
            showToast(`${successCount} video berhasil ditemukan`, 'success');
        }
    }

    function renderDirectLinkPreviews() {
        const list = document.getElementById('url-preview-list');
        list.innerHTML = '';

        directLinkVideos.forEach((video, id) => {
            const card = document.createElement('div');
            card.className = `url-preview-card flex items-center gap-3 cursor-pointer ${video._selected ? 'border-blue-400 bg-blue-50/50' : ''}`;
            card.innerHTML = `
                <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${video._selected ? 'bg-blue-500' : 'bg-gray-300'}">
                    ${video._selected 
                        ? '<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>'
                        : '<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>'
                    }
                </div>
                <img src="${video.thumbnail}" class="w-24 h-14 object-cover rounded-lg flex-shrink-0" alt="">
                <div class="flex-1 min-w-0">
                    <h5 class="text-sm font-semibold text-gray-900 truncate">${escapeHtml(video.title)}</h5>
                    <p class="text-xs text-gray-500">${escapeHtml(video.channel)} • ${video.viewCount} views • 💬 ${Number(video.commentCount).toLocaleString('id-ID')}</p>
                </div>
                <button type="button" class="remove-url-btn flex-shrink-0 p-1.5 text-gray-400 hover:text-red-500 transition-colors" data-id="${id}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            `;

            card.addEventListener('click', (e) => {
                if (e.target.closest('.remove-url-btn')) {
                    directLinkVideos.delete(id);
                    renderDirectLinkPreviews();
                    updateDirectScrapeButton();
                    return;
                }
                video._selected = !video._selected;
                directLinkVideos.set(id, video);
                renderDirectLinkPreviews();
                updateDirectScrapeButton();
            });

            list.appendChild(card);
        });
    }

    function updateDirectScrapeButton() {
        const selectedCount = Array.from(directLinkVideos.values()).filter(v => v._selected).length;
        const btn = document.getElementById('direct-scrape-button');
        if (selectedCount > 0) {
            btn.classList.remove('hidden');
            btn.innerHTML = `
                <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                Ambil Komentar (${selectedCount} video)
            `;
        } else {
            btn.classList.add('hidden');
        }
    }

    // ═══════════════════ Scrape Modal ═══════════════════
    function openScrapeModal() {
        if (currentMode === 'direct') {
            // Populate from direct link videos
            selectedVideos.clear();
            directLinkVideos.forEach((v, id) => {
                if (v._selected) selectedVideos.set(id, v);
            });
        }

        if (selectedVideos.size === 0) return showToast('Pilih video terlebih dahulu', 'warning');
        
        const list = document.getElementById('selected-videos-list');
        list.innerHTML = '';
        document.getElementById('modal-video-count').textContent = selectedVideos.size;
        
        selectedVideos.forEach(video => {
            const item = document.createElement('div');
            item.className = 'flex items-center space-x-2 text-sm';
            item.innerHTML = `
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
                <span class="flex-1 truncate">${escapeHtml(video.title)}</span>
                <span class="text-xs text-gray-400 flex-shrink-0">💬 ${Number(video.commentCount).toLocaleString('id-ID')}</span>
            `;
            list.appendChild(item);
        });
        
        document.getElementById('scrape-modal').classList.remove('hidden');
    }

    function closeScrapeModal() {
        document.getElementById('scrape-modal').classList.add('hidden');
    }

    // ═══════════════════ Start Scraping ═══════════════════
    async function startScraping() {
        closeScrapeModal();
        
        const limit = document.querySelector('input[name="comment_limit"]:checked').value;
        const custom = document.getElementById('custom-limit').value;
        const format = document.querySelector('input[name="output_format"]:checked').value;
        
        document.getElementById('progress-modal').classList.remove('hidden');
        document.getElementById('progress-bar').style.width = '10%';
        document.getElementById('progress-text').textContent = 'Menghubungkan ke YouTube...';

        try {
            // Determine if using direct URL mode
            const isDirectMode = currentMode === 'direct';
            let endpoint, body;

            if (isDirectMode) {
                const urls = Array.from(selectedVideos.values()).map(v => v.url || `https://www.youtube.com/watch?v=${v.id}`);
                endpoint = '{{ route("youtube.scrape-by-url") }}';
                body = {
                    urls: urls,
                    comment_limit: limit,
                    custom_limit: custom,
                    include_replies: document.getElementById('include-replies').checked,
                    output_format: format,
                };
            } else {
                endpoint = '{{ route("youtube.scrape-comments") }}';
                body = {
                    video_ids: Array.from(selectedVideos.keys()),
                    comment_limit: limit,
                    custom_limit: custom,
                    include_replies: document.getElementById('include-replies').checked,
                    output_format: format,
                };
            }

            // Simulate progress
            let progressInterval = setInterval(() => {
                const bar = document.getElementById('progress-bar');
                const current = parseFloat(bar.style.width);
                if (current < 85) {
                    bar.style.width = (current + Math.random() * 5) + '%';
                    document.getElementById('progress-text').textContent = `Mengambil komentar... (${Math.round(current)}%)`;
                }
            }, 800);

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(body)
            });

            clearInterval(progressInterval);
            const result = await response.json();
            
            if (result.success) {
                document.getElementById('progress-bar').style.width = '100%';
                document.getElementById('progress-text').textContent = 'Selesai!';

                downloadUrl = result.data.download_url;

                setTimeout(() => {
                    document.getElementById('progress-modal').classList.add('hidden');
                    // Show success modal
                    document.getElementById('success-message').textContent = result.message;
                    document.getElementById('stat-comments').textContent = Number(result.data.total_comments).toLocaleString('id-ID');
                    document.getElementById('stat-videos').textContent = result.data.processed_videos;
                    document.getElementById('success-modal').classList.remove('hidden');
                }, 600);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            document.getElementById('progress-modal').classList.add('hidden');
            showToast('Gagal: ' + error.message, 'error');
        }
    }

    function closeSuccessModal() {
        document.getElementById('success-modal').classList.add('hidden');
    }

    // ═══════════════════ Helpers ═══════════════════
    function escapeHtml(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
    }

    function getRelativeTime(dateStr) {
        if (!dateStr) return '';
        const now = new Date();
        const date = new Date(dateStr);
        const diffMs = now - date;
        const diffSeconds = Math.floor(diffMs / 1000);
        const diffMinutes = Math.floor(diffSeconds / 60);
        const diffHours = Math.floor(diffMinutes / 60);
        const diffDays = Math.floor(diffHours / 24);
        const diffWeeks = Math.floor(diffDays / 7);
        const diffMonths = Math.floor(diffDays / 30);
        const diffYears = Math.floor(diffDays / 365);

        if (diffYears > 0) return `${diffYears} tahun lalu`;
        if (diffMonths > 0) return `${diffMonths} bulan lalu`;
        if (diffWeeks > 0) return `${diffWeeks} minggu lalu`;
        if (diffDays > 0) return `${diffDays} hari lalu`;
        if (diffHours > 0) return `${diffHours} jam lalu`;
        if (diffMinutes > 0) return `${diffMinutes} menit lalu`;
        return 'Baru saja';
    }

    // ═══════════════════ Toast Notification ═══════════════════
    function showToast(message, type = 'info') {
        // Remove existing toasts
        document.querySelectorAll('.toast-notification').forEach(t => t.remove());

        const colors = {
            info: 'bg-blue-600',
            success: 'bg-green-600',
            warning: 'bg-amber-600',
            error: 'bg-red-600',
        };
        const icons = {
            info: '💡',
            success: '✅',
            warning: '⚠️',
            error: '❌',
        };

        const toast = document.createElement('div');
        toast.className = `toast-notification fixed top-6 right-6 z-[100] ${colors[type]} text-white px-5 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 transform translate-x-full transition-transform duration-300`;
        toast.innerHTML = `<span>${icons[type]}</span><span>${message}</span>`;
        document.body.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.transform = 'translateX(0)';
        });

        setTimeout(() => {
            toast.style.transform = 'translateX(calc(100% + 24px))';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
</script>
@endpush
@endsection