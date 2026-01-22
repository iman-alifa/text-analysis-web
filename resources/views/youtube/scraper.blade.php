@extends('layouts.app')

@push('styles')
<style>
    .video-card {
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .video-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
    }
    
    .video-card.selected {
        border: 2px solid #3B82F6;
        background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    }
    
    .video-thumbnail {
        position: relative;
        padding-bottom: 56.25%;
        overflow: hidden;
        background: #000;
    }
    
    .video-thumbnail img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .video-duration {
        position: absolute;
        bottom: 8px;
        right: 8px;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
    }
    
    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    .stats-icon {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: #6b7280;
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
        <div class="mt-4 sm:mt-0">
            <a href="{{ route('analysis.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="relative">
            <div class="flex gap-3">
                <div class="flex-1 relative">
                    <input 
                        type="text" 
                        id="search-input"
                        placeholder="Cari video YouTube..." 
                        class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        autocomplete="off">
                    <svg class="absolute left-4 top-3.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <button 
                    type="button"
                    id="search-button"
                    class="px-8 py-3 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-lg font-semibold hover:shadow-lg transform hover:scale-105 transition-all duration-300">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Cari
                </button>
            </div>
        </div>

        <!-- Search Filters -->
        <div class="mt-4 flex flex-wrap gap-3">
            <select id="filter-duration" class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="">Semua Durasi</option>
                <option value="short">Pendek (< 4 menit)</option>
                <option value="medium">Sedang (4-20 menit)</option>
                <option value="long">Panjang (> 20 menit)</option>
            </select>
            
            <select id="filter-sort" class="px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="relevance">Relevansi</option>
                <option value="date">Tanggal Upload</option>
                <option value="viewCount">Jumlah View</option>
                <option value="rating">Rating</option>
            </select>
        </div>
    </div>

    <!-- Selected Videos Counter -->
    <div id="selected-counter" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span class="font-medium text-blue-900">
                    <span id="selected-count">0</span> video dipilih
                </span>
            </div>
            <div class="flex gap-2">
                <button 
                    type="button"
                    id="clear-selection"
                    class="px-4 py-2 text-sm text-blue-600 hover:text-blue-800 font-medium">
                    Bersihkan
                </button>
                <button 
                    type="button"
                    id="scrape-button"
                    class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors">
                    Ambil Komentar
                </button>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="loading-state" class="hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @for($i = 0; $i < 6; $i++)
            <div class="bg-white rounded-lg overflow-hidden border border-gray-200">
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
        <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">Cari Video YouTube</h3>
        <p class="text-gray-500 mb-6">Gunakan search bar di atas untuk mencari video YouTube yang ingin Anda ambil komentarnya</p>
        <div class="flex flex-wrap gap-2 justify-center">
            <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-gray-200" onclick="quickSearch('Tutorial Laravel')">Tutorial Laravel</span>
            <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-gray-200" onclick="quickSearch('Review Smartphone')">Review Smartphone</span>
            <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-gray-200" onclick="quickSearch('Vlog Indonesia')">Vlog</span>
            <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm cursor-pointer hover:bg-gray-200" onclick="quickSearch('Gaming')">Gaming</span>
        </div>
    </div>

    <!-- Video Results -->
    <div id="video-results" class="hidden">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">
                Hasil Pencarian: <span id="search-query" class="text-blue-600"></span>
            </h3>
            <span id="results-count" class="text-sm text-gray-600"></span>
        </div>
        
        <div id="videos-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Video cards will be populated here -->
        </div>
    </div>
</div>

<!-- Scrape Modal -->
<div id="scrape-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeScrapeModal()"></div>
        
        <div class="relative bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-900">Pengaturan Scraping</h3>
                <button onclick="closeScrapeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-6 overflow-y-auto max-h-[60vh]">
                <form id="scrape-form">
                    <div class="mb-6 bg-blue-50 rounded-lg p-4">
                        <h4 class="font-semibold text-blue-900 mb-2">Video Terpilih (<span id="modal-video-count">0</span>)</h4>
                        <div id="selected-videos-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Jumlah Komentar per Video</label>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="radio" name="comment_limit" value="100" class="mr-3" checked>
                                    <span>100 komentar pertama</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="comment_limit" value="500" class="mr-3">
                                    <span>500 komentar pertama</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="comment_limit" value="all" class="mr-3">
                                    <span>Semua komentar</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="comment_limit" value="custom" class="mr-3">
                                    <span>Custom</span>
                                </label>
                            </div>
                            <input 
                                type="number" 
                                id="custom-limit"
                                class="hidden mt-2 w-full px-4 py-2 border border-gray-300 rounded-lg"
                                placeholder="Masukkan jumlah"
                                min="1">
                        </div>

                        <div>
                            <label class="flex items-center">
                                <input type="checkbox" id="include-replies" class="mr-3">
                                <span class="text-sm font-medium text-gray-700">Sertakan balasan</span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Format Output</label>
                            <select id="output-format" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                <option value="csv">CSV (.csv)</option>
                                <option value="xlsx">Excel (.xlsx)</option>
                                <option value="txt">Text (.txt)</option>
                                <option value="json">JSON (.json)</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>

            <div class="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
                <button onclick="closeScrapeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Batal</button>
                <button onclick="startScraping()" class="px-6 py-2 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-lg font-semibold hover:shadow-lg">Mulai Scraping</button>
            </div>
        </div>
    </div>
</div>

<!-- Progress Modal -->
<div id="progress-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75"></div>
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
            <div class="text-center">
                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Mengambil Komentar...</h3>
                <p class="text-sm text-gray-600 mb-4">Mohon tunggu, proses sedang berjalan</p>
                <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                    <div id="progress-bar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p class="text-xs text-gray-500"><span id="progress-text">Memulai...</span></p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    let selectedVideos = new Map();
    let currentSearchQuery = '';

    document.getElementById('search-button').addEventListener('click', performSearch);
    document.getElementById('search-input').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') performSearch();
    });

    function quickSearch(query) {
        document.getElementById('search-input').value = query;
        performSearch();
    }

    document.querySelectorAll('input[name="comment_limit"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('custom-limit').classList.toggle('hidden', this.value !== 'custom');
        });
    });

    async function performSearch() {
        const query = document.getElementById('search-input').value.trim();
        if (!query) return alert('Masukkan kata kunci');

        currentSearchQuery = query;
        selectedVideos.clear();
        updateSelectedCounter();
        
        document.getElementById('empty-state').classList.add('hidden');
        document.getElementById('video-results').classList.add('hidden');
        document.getElementById('loading-state').classList.remove('hidden');

        try {
            const response = await fetch('{{ route("youtube.search") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    query: query,
                    order: document.getElementById('filter-sort').value,
                    videoDuration: document.getElementById('filter-duration').value
                })
            });

            const result = await response.json();
            if (result.success) {
                displayResults(result.data);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            document.getElementById('loading-state').classList.add('hidden');
            document.getElementById('empty-state').classList.remove('hidden');
            alert('Gagal: ' + error.message);
        }
    }

    function displayResults(videos) {
        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('video-results').classList.remove('hidden');
        document.getElementById('search-query').textContent = currentSearchQuery;
        document.getElementById('results-count').textContent = `${videos.length} video`;

        const grid = document.getElementById('videos-grid');
        grid.innerHTML = '';
        videos.forEach(video => grid.appendChild(createVideoCard(video)));
    }

    function createVideoCard(video) {
        const div = document.createElement('div');
        div.className = 'video-card bg-white rounded-lg overflow-hidden border border-gray-200';
        div.dataset.videoId = video.id;
        
        div.innerHTML = `
            <div class="video-thumbnail">
                <img src="${video.thumbnail}" alt="${escapeHtml(video.title)}">
                <span class="video-duration">${video.duration}</span>
            </div>
            <div class="p-4">
                <h4 class="font-semibold text-gray-900 line-clamp-2 mb-2 min-h-[3rem]">${escapeHtml(video.title)}</h4>
                <p class="text-sm text-gray-600 mb-2">${escapeHtml(video.channel)}</p>
                <div class="flex items-center text-xs text-gray-500 space-x-3 mb-3">
                    <span class="stats-icon">👁️ ${video.viewCount}</span>
                    <span class="stats-icon">💬 ${video.commentCount || '0'}</span>
                </div>
                <div class="flex justify-between">
                    <a href="https://youtube.com/watch?v=${video.id}" target="_blank" class="text-sm text-blue-600">Lihat</a>
                    <span class="select-indicator text-xs text-gray-500">Klik untuk pilih</span>
                </div>
            </div>
        `;

        div.addEventListener('click', (e) => {
            if (e.target.closest('a')) return;
            toggleVideoSelection(video, div);
        });

        return div;
    }

    function toggleVideoSelection(video, el) {
        if (selectedVideos.has(video.id)) {
            selectedVideos.delete(video.id);
            el.classList.remove('selected');
            el.querySelector('.select-indicator').textContent = 'Klik untuk pilih';
        } else {
            selectedVideos.set(video.id, video);
            el.classList.add('selected');
            el.querySelector('.select-indicator').textContent = '✓ Dipilih';
        }
        updateSelectedCounter();
    }

    function updateSelectedCounter() {
        document.getElementById('selected-count').textContent = selectedVideos.size;
        document.getElementById('selected-counter').classList.toggle('hidden', selectedVideos.size === 0);
    }

    document.getElementById('clear-selection').addEventListener('click', () => {
        selectedVideos.clear();
        document.querySelectorAll('.video-card').forEach(card => {
            card.classList.remove('selected');
            card.querySelector('.select-indicator').textContent = 'Klik untuk pilih';
        });
        updateSelectedCounter();
    });

    document.getElementById('scrape-button').addEventListener('click', () => {
        if (selectedVideos.size === 0) return;
        
        const list = document.getElementById('selected-videos-list');
        list.innerHTML = '';
        document.getElementById('modal-video-count').textContent = selectedVideos.size;
        
        selectedVideos.forEach(video => {
            const item = document.createElement('div');
            item.className = 'flex items-start space-x-2 text-sm';
            item.innerHTML = `<svg class="w-4 h-4 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg><span class="flex-1">${escapeHtml(video.title)}</span>`;
            list.appendChild(item);
        });
        
        document.getElementById('scrape-modal').classList.remove('hidden');
    });

    function closeScrapeModal() {
        document.getElementById('scrape-modal').classList.add('hidden');
    }

    async function startScraping() {
        closeScrapeModal();
        
        const limit = document.querySelector('input[name="comment_limit"]:checked').value;
        const custom = document.getElementById('custom-limit').value;
        
        document.getElementById('progress-modal').classList.remove('hidden');
        document.getElementById('progress-text').textContent = 'Mengambil komentar...';

        try {
            const response = await fetch('{{ route("youtube.scrape-comments") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    video_ids: Array.from(selectedVideos.keys()),
                    comment_limit: limit,
                    custom_limit: custom,
                    include_replies: document.getElementById('include-replies').checked,
                    output_format: document.getElementById('output-format').value
                })
            });

            const result = await response.json();
            
            if (result.success) {
                document.getElementById('progress-bar').style.width = '100%';
                document.getElementById('progress-text').textContent = 'Selesai!';
                setTimeout(() => {
                    document.getElementById('progress-modal').classList.add('hidden');
                    window.location.href = result.data.download_url;
                }, 1000);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            document.getElementById('progress-modal').classList.add('hidden');
            alert('Gagal: ' + error.message);
        }
    }

    function escapeHtml(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return text.replace(/[&<>"']/g, m => map[m]);
    }
</script>
@endpush
@endsection