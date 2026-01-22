@extends('layouts.app')

@push('styles')
<style>
    .text-card { transition: all 0.3s ease; border-left: 4px solid transparent; }
    .text-card.corrected { border-left-color: #10b981; background: #f0fdf4; }
    .text-card.pending { border-left-color: #f59e0b; background: #fff; }
    .sentiment-option.active { border-color: #3b82f6; background-color: #eff6ff; ring: 2px solid #3b82f6; }
</style>
@endpush

@section('content')
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900">{{ $analysis->title }}</h1>
                {{-- Badge Tipe Analisis di Header --}}
                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase bg-gray-100 text-gray-600 border border-gray-200">
                    {{ str_replace('_', ' ', $analysis->analysis_type) }}
                </span>
            </div>
            
            @if($analysis->analysis_type !== 'topic')
                <p class="text-sm text-gray-600">Training Workspace &bull; Akurasi Saat Ini: <span class="font-bold text-blue-600">{{ $accuracy }}%</span></p>
            @else
                <p class="text-sm text-gray-600">Topic Refinement Workspace</p>
            @endif
        </div>
        <a href="{{ route('admin.training.index') }}" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Kembali</a>
    </div>

    @if(in_array($analysis->analysis_type, ['topic', 'combined']))
    <div class="bg-white p-6 rounded-xl shadow-sm border border-indigo-100 mb-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
            <div>
                <h3 class="font-bold text-indigo-700 flex items-center text-lg">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    Topic Refinement (Stopwords)
                </h3>
                <p class="text-sm text-gray-500">Kata-kata yang diblokir akan mempengaruhi pembentukan topik.</p>
            </div>
            
            <form action="{{ route('admin.training.stopword') }}" method="POST" class="flex gap-2 w-full md:w-auto mt-2 md:mt-0">
                @csrf
                <input type="text" name="word" placeholder="Tambah stopword..." class="text-sm border-gray-300 rounded-md focus:ring-indigo-500 w-full">
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">Blokir Kata</button>
            </form>
        </div>

        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
            <h4 class="text-xs font-bold text-gray-500 uppercase mb-3">Topik Terdeteksi pada File Ini:</h4>
            
            @php
                $topicData = $analysis->result->topic_results ?? [];
                if(is_string($topicData)) $topicData = json_decode($topicData, true);
                $topics = $topicData['topics'] ?? [];
            @endphp

            @if(count($topics) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach(array_slice($topics, 0, 6) as $topic)
                        <div class="bg-white p-3 rounded shadow-sm border border-gray-100 text-sm">
                            <div class="flex justify-between mb-1">
                                <span class="font-bold text-gray-700">Topik {{ $loop->iteration }}</span>
                                <span class="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">{{ round(($topic['proportion'] ?? 0) * 100, 1) }}%</span>
                            </div>
                            <div class="text-gray-600 text-xs leading-relaxed">
                                @foreach(array_slice($topic['words'] ?? [], 0, 10) as $word)
                                    <span class="inline-block bg-gray-100 px-1 rounded mr-1 mb-1 border border-gray-200">{{ $word }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-400 italic">Data topik tidak tersedia.</p>
            @endif
        </div>
    </div>
    @endif

    {{-- JIKA TIPE ANALISIS ADALAH TOPIC MODELING, KITA SEMBUNYIKAN BAGIAN LIST DATA --}}
    @if($analysis->analysis_type !== 'topic')
    
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl shadow-sm border">
                <p class="text-sm text-gray-500">Progress Koreksi</p>
                <p class="text-2xl font-bold">{{ $corrected }} / {{ $total }}</p>
            </div>
            
            <div class="md:col-span-3 bg-white p-4 rounded-xl shadow-sm border flex gap-4">
                <input type="text" id="search-text" placeholder="Cari teks..." class="flex-1 border-gray-300 rounded-lg">
                
                <select id="filter-status" class="border-gray-300 rounded-lg">
                    <option value="">Semua Status</option>
                    <option value="pending">Perlu Review</option>
                    <option value="corrected">Sudah Dikoreksi</option>
                </select>
                
                {{-- Sembunyikan Filter Sentimen jika Tipe Analisis = Aspect Extraction --}}
                @if($analysis->analysis_type !== 'aspect')
                <select id="filter-sentiment" class="border-gray-300 rounded-lg">
                    <option value="">Semua Sentimen</option>
                    <option value="positive">Positif</option>
                    <option value="neutral">Netral</option>
                    <option value="negative">Negatif</option>
                </select>
                @endif
            </div>
        </div>

        <div id="data-container" class="space-y-4">
            <div class="text-center py-10 text-gray-400">Memuat Data...</div>
        </div>

        <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm">
            <span id="page-info" class="text-sm text-gray-500"></span>
            <div class="flex gap-2">
                <button id="prev-btn" onclick="changePage(-1)" class="px-4 py-2 border rounded disabled:opacity-50">Prev</button>
                <button id="next-btn" onclick="changePage(1)" class="px-4 py-2 border rounded disabled:opacity-50">Next</button>
            </div>
        </div>
        
    @else
        {{-- Pesan Khusus untuk Topic Modeling Only --}}
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 text-center text-blue-800">
            <h3 class="font-bold text-lg">Mode Topic Modeling</h3>
            <p class="text-sm mt-2">
                Pada mode ini, training dilakukan dengan memfilter kata-kata yang tidak relevan (Stopwords) pada panel di atas.
                <br>Tidak ada koreksi baris per baris yang diperlukan.
            </p>
        </div>
    @endif
</div>

<div id="correction-modal" class="hidden fixed inset-0 z-50 bg-gray-900 bg-opacity-75 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b flex justify-between">
            <h3 class="text-xl font-bold">Koreksi Data</h3>
            <button onclick="closeModal()" class="text-gray-400">&times;</button>
        </div>
        
        <div class="p-6 space-y-6">
            <input type="hidden" id="modal-id">
            
            <div class="bg-gray-50 p-4 rounded border">
                <p id="modal-text" class="text-lg text-gray-800"></p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                {{-- SECTION SENTIMENT (Sembunyikan jika tipe = aspect) --}}
                <div id="div-sentiment-input">
                    <label class="block font-bold mb-2">1. Sentimen</label>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="sentiment-option border p-3 text-center rounded cursor-pointer hover:bg-gray-50" data-val="positive" onclick="setSentiment('positive')">😊 Positif</div>
                        <div class="sentiment-option border p-3 text-center rounded cursor-pointer hover:bg-gray-50" data-val="neutral" onclick="setSentiment('neutral')">😐 Netral</div>
                        <div class="sentiment-option border p-3 text-center rounded cursor-pointer hover:bg-gray-50" data-val="negative" onclick="setSentiment('negative')">😞 Negatif</div>
                    </div>
                </div>

                {{-- SECTION ASPECT (Sembunyikan jika tipe = sentiment) --}}
                <div id="div-aspect-input">
                    <label class="block font-bold mb-2">2. Aspek (Aspect Extraction)</label>
                    <p class="text-xs text-gray-500 mb-2">Masukkan aspek yang dipisahkan koma.</p>
                    <div class="mb-2 text-xs">
                        Terdeteksi AI: <span id="modal-ai-aspects" class="font-mono text-blue-600"></span>
                    </div>
                    <input type="text" id="modal-aspects" class="w-full border-gray-300 rounded focus:ring-blue-500" placeholder="Contoh: rasa, harga">
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold mb-1">Catatan</label>
                <textarea id="modal-notes" class="w-full border-gray-300 rounded" rows="2"></textarea>
            </div>
        </div>

        <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
            <button onclick="closeModal()" class="px-4 py-2 border rounded bg-white">Batal</button>
            <button onclick="saveCorrection()" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Simpan Koreksi</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Config
    const API_URL = "{{ route('admin.training.data', $analysis->id) }}";
    const UPDATE_URL = "{{ url('/admin/training/update') }}";
    const CSRF_TOKEN = "{{ csrf_token() }}";
    // Ambil Tipe Analisis dari Backend ke JS
    const ANALYSIS_TYPE = "{{ $analysis->analysis_type }}"; 
    
    let currentPage = 1;
    let currentData = [];
    let selectedSentiment = null;
    let editingId = null;

    // Helper Escape HTML
    function escapeHtml(text) {
        if (!text) return "";
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Jika Topic Modeling, tidak perlu load data table
        if(ANALYSIS_TYPE !== 'topic_modeling') {
            loadData();
            
            // Listeners
            ['search-text', 'filter-status', 'filter-sentiment'].forEach(id => {
                const el = document.getElementById(id);
                if(el) {
                    el.addEventListener('change', () => { currentPage = 1; loadData(); });
                    if(id === 'search-text') el.addEventListener('input', debounce(() => { currentPage = 1; loadData(); }, 500));
                }
            });
        }
    });

    async function loadData() {
        const container = document.getElementById('data-container');
        if(!container) return; // Guard clause jika container tidak ada (mode topic modeling)

        container.innerHTML = '<div class="text-center py-10 text-gray-400">Loading...</div>';

        const params = new URLSearchParams({
            page: currentPage,
            search: document.getElementById('search-text')?.value || '',
            status: document.getElementById('filter-status')?.value || '',
            sentiment: document.getElementById('filter-sentiment')?.value || '',
        });

        try {
            const res = await fetch(`${API_URL}?${params}`);
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            
            const json = await res.json();
            currentData = json.data;
            renderList(json.data);
            updatePagination(json);
        } catch (error) {
            console.error(error);
            container.innerHTML = `<div class="text-center text-red-500 py-10">Gagal memuat data: ${error.message}</div>`;
        }
    }

    function renderList(items) {
        const container = document.getElementById('data-container');
        container.innerHTML = '';
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="text-center py-10 text-gray-500 border border-dashed rounded">Tidak ada data.</div>';
            return;
        }

        items.forEach(item => {
            const statusClass = item.is_corrected ? 'corrected' : 'pending';
            const safeText = escapeHtml(item.text_content);

            // Badge Status
            const statusLabel = item.is_corrected ? 
                '<span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded font-bold border border-green-200">✓ Dikoreksi</span>' : 
                '<span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded border border-yellow-200">Pending</span>';

            // --- BUILD HTML BERDASARKAN TIPE ---
            let detailsHtml = '';

            // Tampilkan Sentimen (Kecuali Aspect Extraction Only)
            if(ANALYSIS_TYPE !== 'aspect') {
                let sentColor = 'text-gray-600';
                if(item.predicted_sentiment === 'positive') sentColor = 'text-green-600';
                if(item.predicted_sentiment === 'negative') sentColor = 'text-red-600';
                
                detailsHtml += `
                    <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded border mr-2">
                        AI: <b class="${sentColor} uppercase">${item.predicted_sentiment}</b> 
                        <span class="text-gray-400">(${Math.round(item.confidence_score * 100)}%)</span>
                    </span>
                `;
            }

            // Tampilkan Aspek (Kecuali Sentiment Analysis Only)
            if(ANALYSIS_TYPE !== 'sentiment') {
                 const aspectList = (item.detected_aspects && item.detected_aspects.length > 0) 
                    ? item.detected_aspects.join(', ') 
                    : '-';
                 
                 detailsHtml += `
                    <span class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded border mr-2">
                        Aspek AI: <b>${aspectList}</b>
                    </span>
                 `;
            }

            const html = `
                <div class="text-card ${statusClass} p-4 rounded-lg border shadow-sm bg-white hover:shadow-md transition mb-3">
                    <div class="flex justify-between items-start gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="text-gray-900 mb-3 text-sm leading-relaxed border-l-4 border-gray-200 pl-3 italic">"${safeText}"</p>
                            <div class="flex flex-wrap gap-2 items-center">
                                ${statusLabel}
                                ${detailsHtml}
                            </div>
                        </div>
                        <button onclick="openModal(${item.id})" class="shrink-0 text-blue-600 hover:bg-blue-50 p-2 rounded-full border border-transparent hover:border-blue-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        </button>
                    </div>
                </div>
            `;
            container.innerHTML += html;
        });
    }

    function openModal(id) {
        const item = currentData.find(i => i.id === id);
        if(!item) return;
        
        editingId = id;
        document.getElementById('modal-text').innerText = item.text_content;
        document.getElementById('modal-notes').value = item.correction_notes || '';
        
        // --- LOGIC TAMPILAN MODAL ---
        const divSent = document.getElementById('div-sentiment-input');
        const divAsp = document.getElementById('div-aspect-input');

        // Reset Display
        divSent.style.display = 'block';
        divAsp.style.display = 'block';

        if(ANALYSIS_TYPE === 'sentiment') {
            divAsp.style.display = 'none'; // Sembunyikan Aspek
        } else if(ANALYSIS_TYPE === 'aspect') {
            divSent.style.display = 'none'; // Sembunyikan Sentimen
        }

        // Fill Data
        if(ANALYSIS_TYPE !== 'aspect') {
            const sent = item.corrected_sentiment || item.predicted_sentiment;
            setSentiment(sent);
        }

        if(ANALYSIS_TYPE !== 'sentiment') {
            const aiAspects = item.detected_aspects || [];
            document.getElementById('modal-ai-aspects').innerText = aiAspects.join(', ') || '-';
            const correctedAspects = item.corrected_aspects ? item.corrected_aspects : aiAspects;
            document.getElementById('modal-aspects').value = correctedAspects.join(', ');
        }

        document.getElementById('correction-modal').classList.remove('hidden');
    }

    function setSentiment(val) {
        selectedSentiment = val;
        document.querySelectorAll('.sentiment-option').forEach(el => {
            el.classList.remove('active', 'border-blue-500', 'bg-blue-50');
            if(el.dataset.val === val) el.classList.add('active', 'border-blue-500', 'bg-blue-50');
        });
    }

    async function saveCorrection() {
        const btn = document.querySelector('#correction-modal button[onclick="saveCorrection()"]');
        const origText = btn.innerText;
        btn.disabled = true; btn.innerText = 'Menyimpan...';

        // Prepare Payload
        let aspects = [];
        if(ANALYSIS_TYPE !== 'sentiment') {
             aspects = document.getElementById('modal-aspects').value.split(',').map(s => s.trim()).filter(s => s);
        }

        const payload = {
            corrected_sentiment: selectedSentiment,
            corrected_aspects: aspects,
            correction_notes: document.getElementById('modal-notes').value,
            _token: CSRF_TOKEN
        };

        try {
            const res = await fetch(`${UPDATE_URL}/${editingId}`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });

            if(res.ok) {
                closeModal();
                loadData();
            } else {
                alert('Gagal menyimpan.');
            }
        } catch(e) { console.error(e); alert('Error koneksi.'); }
        finally { btn.disabled = false; btn.innerText = origText; }
    }

    function closeModal() { document.getElementById('correction-modal').classList.add('hidden'); }
    function changePage(delta) { currentPage += delta; loadData(); }
    function updatePagination(json) {
        const info = document.getElementById('page-info');
        const prev = document.getElementById('prev-btn');
        const next = document.getElementById('next-btn');
        if(info) info.innerText = `Halaman ${json.current_page} dari ${json.last_page}`;
        if(prev) prev.disabled = json.current_page === 1;
        if(next) next.disabled = json.current_page === json.last_page;
    }
    function debounce(func, wait) { let timeout; return function(...args) { clearTimeout(timeout); timeout = setTimeout(() => func.apply(this, args), wait); }; }
</script>
@endpush
@endsection