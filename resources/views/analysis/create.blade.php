@extends('layouts.app')

@push('styles')
<style>
    .tab-button.active {
        background: linear-gradient(135deg, #3B82F6 0%, #06B6D4 100%);
        color: white;
    }
    
    .dropzone {
        border: 2px dashed #cbd5e1;
        border-radius: 0.75rem;
        transition: all 0.3s ease;
    }
    
    .dropzone.dragover {
        border-color: #3B82F6;
        background-color: #eff6ff;
    }
    
    .file-preview {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    }
    
    .aspect-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        background: #dbeafe;
        color: #1e40af;
        border-radius: 9999px;
        font-size: 0.875rem;
        margin: 0.25rem;
    }
</style>
@endpush

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Analisis Baru</h1>
            <p class="mt-1 text-sm text-gray-600">Buat analisis teks baru dengan upload file atau input manual</p>
        </div>
        <a href="{{ route('analysis.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Kembali
        </a>
    </div>

    <form action="{{ route('analysis.store') }}" method="POST" enctype="multipart/form-data" id="analysisForm">
        @csrf
        
        <!-- Step Indicator -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-white font-semibold">
                        1
                    </div>
                    <span class="font-medium text-gray-900">Informasi Dasar</span>
                </div>
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                <div class="flex items-center space-x-3">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-300 text-white font-semibold">
                        2
                    </div>
                    <span class="font-medium text-gray-500">Input Data</span>
                </div>
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                <div class="flex items-center space-x-3">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-300 text-white font-semibold">
                        3
                    </div>
                    <span class="font-medium text-gray-500">Konfigurasi</span>
                </div>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Dasar</h3>
            </div>

            <!-- Title -->
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                    Judul Analisis <span class="text-red-500">*</span>
                </label>
                <input type="text" 
                       name="title" 
                       id="title" 
                       value="{{ old('title') }}"
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="Contoh: Analisis Sentimen Ulasan Produk Q1 2024"
                       required>
                @error('title')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                    Deskripsi (Opsional)
                </label>
                <textarea name="description" 
                          id="description" 
                          rows="3"
                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          placeholder="Deskripsikan tujuan dan konteks analisis ini...">{{ old('description') }}</textarea>
                @error('description')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Analysis Type -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-3">
                    Tipe Analisis <span class="text-red-500">*</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <label class="relative cursor-pointer">
                        <input type="radio" 
                               name="analysis_type" 
                               value="sentiment" 
                               class="peer sr-only" 
                               {{ old('analysis_type') == 'sentiment' ? 'checked' : '' }}
                               required>
                        <div class="p-4 border-2 border-gray-300 rounded-lg peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:border-blue-400 transition-all">
                            <div class="text-center">
                                <div class="text-3xl mb-2">😊</div>
                                <div class="font-semibold text-gray-900">Sentimen</div>
                                <div class="text-xs text-gray-500 mt-1">Positif, Negatif, Netral</div>
                            </div>
                        </div>
                    </label>

                    <label class="relative cursor-pointer">
                        <input type="radio" 
                               name="analysis_type" 
                               value="aspect" 
                               class="peer sr-only"
                               {{ old('analysis_type') == 'aspect' ? 'checked' : '' }}>
                        <div class="p-4 border-2 border-gray-300 rounded-lg peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:border-blue-400 transition-all">
                            <div class="text-center">
                                <div class="text-3xl mb-2">🎯</div>
                                <div class="font-semibold text-gray-900">Aspek</div>
                                <div class="text-xs text-gray-500 mt-1">Ekstraksi aspek spesifik</div>
                            </div>
                        </div>
                    </label>

                    <label class="relative cursor-pointer">
                        <input type="radio" 
                               name="analysis_type" 
                               value="topic" 
                               class="peer sr-only"
                               {{ old('analysis_type') == 'topic' ? 'checked' : '' }}>
                        <div class="p-4 border-2 border-gray-300 rounded-lg peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:border-blue-400 transition-all">
                            <div class="text-center">
                                <div class="text-3xl mb-2">🔍</div>
                                <div class="font-semibold text-gray-900">Topik</div>
                                <div class="text-xs text-gray-500 mt-1">Identifikasi tema utama</div>
                            </div>
                        </div>
                    </label>

                    <label class="relative cursor-pointer">
                        <input type="radio" 
                               name="analysis_type" 
                               value="combined" 
                               class="peer sr-only"
                               {{ old('analysis_type') == 'combined' ? 'checked' : '' }}>
                        <div class="p-4 border-2 border-gray-300 rounded-lg peer-checked:border-blue-600 peer-checked:bg-blue-50 hover:border-blue-400 transition-all">
                            <div class="text-center">
                                <div class="text-3xl mb-2">🎨</div>
                                <div class="font-semibold text-gray-900">Lengkap</div>
                                <div class="text-xs text-gray-500 mt-1">Sentimen + Aspek + Topik</div>
                            </div>
                        </div>
                    </label>
                </div>
                @error('analysis_type')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <!-- Input Data Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Input Data</h3>
            </div>

            <!-- Input Type Tabs -->
            <div>
                <div class="flex space-x-2 bg-gray-100 p-1 rounded-lg">
                    <button type="button" 
                            class="tab-button flex-1 px-4 py-2 rounded-md font-medium transition-all active"
                            data-tab="manual"
                            onclick="switchTab('manual')">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Input Manual
                    </button>
                    <button type="button" 
                            class="tab-button flex-1 px-4 py-2 rounded-md font-medium transition-all"
                            data-tab="file"
                            onclick="switchTab('file')">
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Upload File
                    </button>
                </div>
                <input type="hidden" name="input_type" id="input_type" value="manual">
            </div>

            <!-- Manual Input Tab -->
            <div id="manual-tab" class="tab-content">
                <div>
                    <label for="manual_text" class="block text-sm font-medium text-gray-700 mb-2">
                        Masukkan Teks <span class="text-red-500">*</span>
                    </label>
                    <textarea name="manual_text" 
                              id="manual_text" 
                              rows="10"
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono text-sm"
                              placeholder="Masukkan teks di sini, satu teks per baris...

Contoh:
Pelayanan sangat baik dan memuaskan
Produk berkualitas tinggi
Harga terlalu mahal untuk fitur yang ditawarkan
Pengiriman cepat dan aman">{{ old('manual_text') }}</textarea>
                    <div class="mt-2 flex items-center justify-between text-sm text-gray-500">
                        <span>Pisahkan setiap teks dengan baris baru (Enter)</span>
                        <span id="line-count">0 baris</span>
                    </div>
                    @error('manual_text')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- File Upload Tab -->
            <div id="file-tab" class="tab-content hidden">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">
                        Upload File <span class="text-red-500">*</span>
                    </label>
                    
                    <!-- Dropzone -->
                    <div id="dropzone" class="dropzone p-8 text-center cursor-pointer">
                        <input type="file" 
                               name="file" 
                               id="file-input" 
                               class="hidden"
                               accept=".csv,.txt,.xlsx,.xls">
                        
                        <div id="upload-placeholder">
                            <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            <p class="text-lg font-medium text-gray-700 mb-2">
                                Drag & drop file atau klik untuk browse
                            </p>
                            <p class="text-sm text-gray-500 mb-4">
                                Mendukung: CSV, TXT, XLSX (Maks. 10MB)
                            </p>
                            <button type="button" 
                                    onclick="document.getElementById('file-input').click()"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                                Pilih File
                            </button>
                        </div>

                        <!-- File Preview (Hidden initially) -->
                        <div id="file-preview" class="hidden">
                            <div class="file-preview rounded-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-12 h-12 bg-blue-600 rounded-lg flex items-center justify-center">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                        </div>
                                        <div class="text-left">
                                            <p class="font-semibold text-gray-900" id="file-name">-</p>
                                            <p class="text-sm text-gray-500">
                                                <span id="file-size">-</span> • 
                                                <span id="file-type">-</span>
                                            </p>
                                        </div>
                                    </div>
                                    <button type="button" 
                                            onclick="clearFile()"
                                            class="text-red-600 hover:text-red-700">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <p class="text-2xl font-bold text-blue-600" id="total-rows">-</p>
                                        <p class="text-xs text-gray-500">Total Baris</p>
                                    </div>
                                    <div>
                                        <p class="text-2xl font-bold text-green-600" id="valid-rows">-</p>
                                        <p class="text-xs text-gray-500">Valid</p>
                                    </div>
                                    <div>
                                        <p class="text-2xl font-bold text-gray-600" id="columns-count">-</p>
                                        <p class="text-xs text-gray-500">Kolom</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @error('file')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Advanced Configuration -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Konfigurasi Lanjutan</h3>
                <button type="button" 
                        onclick="document.getElementById('advanced-config').classList.toggle('hidden')"
                        class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                    Tampilkan/Sembunyikan
                </button>
            </div>

            <div id="advanced-config" class="space-y-6 hidden">
                <!-- Preprocessing Config -->
                <div>
                    <label for="preprocessing_config_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Konfigurasi Preprocessing
                    </label>
                    <select name="preprocessing_config_id" 
                            id="preprocessing_config_id"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">Default (Rekomendasi)</option>
                        @foreach($preprocessingConfigs as $config)
                            <option value="{{ $config->id }}" {{ old('preprocessing_config_id') == $config->id ? 'selected' : '' }}>
                                {{ $config->name }} - {{ $config->description }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Aspect Analysis Options (Only show when aspect or combined is selected) -->
                <div id="aspect-options" class="hidden space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">
                            Mode Ekstraksi Aspek
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-start">
                                <input type="radio" 
                                       name="aspect_mode" 
                                       value="automatic" 
                                       class="mt-1 mr-3"
                                       checked>
                                <div>
                                    <span class="font-medium text-gray-900">Otomatis</span>
                                    <p class="text-sm text-gray-500">Sistem akan menemukan aspek secara otomatis menggunakan model ML</p>
                                </div>
                            </label>
                            <label class="flex items-start">
                                <input type="radio" 
                                       name="aspect_mode" 
                                       value="rule-based" 
                                       class="mt-1 mr-3">
                                <div>
                                    <span class="font-medium text-gray-900">Rule-Based</span>
                                    <p class="text-sm text-gray-500">Tentukan aspek spesifik yang ingin dianalisis</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Predefined Aspects (Only show when rule-based is selected) -->
                    <div id="predefined-aspects-container" class="hidden">
                        <label for="predefined_aspects" class="block text-sm font-medium text-gray-700 mb-2">
                            Aspek yang Ingin Dianalisis
                        </label>
                        <input type="text" 
                               name="predefined_aspects" 
                               id="predefined_aspects" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Contoh: pelayanan, kualitas, harga, fitur, kecepatan"
                               value="{{ old('predefined_aspects') }}">
                        <p class="mt-2 text-sm text-gray-500">
                            Pisahkan dengan koma (,) untuk multiple aspek
                        </p>
                        <div id="aspect-tags" class="mt-3"></div>
                    </div>
                </div>

                <!-- Topic Analysis Options -->
                <div id="topic-options" class="hidden">
                    <div>
                        <label for="num_topics" class="block text-sm font-medium text-gray-700 mb-2">
                            Jumlah Topik yang Diidentifikasi
                        </label>
                        <input type="number" 
                               name="num_topics" 
                               id="num_topics" 
                               min="2" 
                               max="10" 
                               value="5"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="mt-2 text-sm text-gray-500">
                            Rekomendasi: 3-7 topik untuk hasil optimal
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex items-center justify-between bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <a href="{{ route('dashboard') }}" class="text-gray-600 hover:text-gray-900 font-medium">
                Batal
            </a>
            <div class="flex space-x-3">
                <button type="button" 
                        onclick="validateAndShowPreview()"
                        class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-semibold hover:bg-gray-200 transition-all">
                    Preview
                </button>
                <button type="submit" 
                        class="px-8 py-3 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-lg font-semibold hover:shadow-lg transform hover:scale-105 transition-all">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Mulai Analisis
                </button>
            </div>
        </div>
    </form>

    <!-- Preview Modal -->
    <div id="preview-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closePreviewModal()"></div>
            
            <div class="relative bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-xl font-bold text-gray-900">Preview Analisis</h3>
                    <button onclick="closePreviewModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-6 overflow-y-auto max-h-[70vh]" id="preview-content">
                    <!-- Content will be populated by JavaScript -->
                </div>

                <!-- Modal Footer -->
                <div class="flex items-center justify-end space-x-3 p-6 border-t border-gray-200">
                    <button onclick="closePreviewModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        Tutup
                    </button>
                    <button onclick="closePreviewModal(); document.getElementById('analysisForm').submit();" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Lanjutkan Analisis
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // ==========================================
    // Tab Switching
    // ==========================================
    function switchTab(tab) {
        // Update input type
        document.getElementById('input_type').value = tab;
        
        // Update tab buttons
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
        
        // Show/hide tab content
        if (tab === 'manual') {
            document.getElementById('manual-tab').classList.remove('hidden');
            document.getElementById('file-tab').classList.add('hidden');
        } else {
            document.getElementById('manual-tab').classList.add('hidden');
            document.getElementById('file-tab').classList.remove('hidden');
        }
    }

    // ==========================================
    // Line Counter for Manual Input
    // ==========================================
    document.getElementById('manual_text').addEventListener('input', function() {
        const lines = this.value.split('\n').filter(line => line.trim() !== '');
        document.getElementById('line-count').textContent = `${lines.length} baris`;
    });

    // ==========================================
    // File Upload - Drag & Drop
    // ==========================================
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');
    
    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Highlight drop zone when item is dragged over it
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, () => {
            dropzone.classList.add('dragover');
        }, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, () => {
            dropzone.classList.remove('dragover');
        }, false);
    });
    
    // Handle dropped files
    dropzone.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect(files[0]);
        }
    }, false);
    
    // Handle file input change
    fileInput.addEventListener('change', function(e) {
        if (this.files.length > 0) {
            handleFileSelect(this.files[0]);
        }
    });

    // ==========================================
    // File Processing
    // ==========================================
    function handleFileSelect(file) {
        // Validate file type
        const allowedTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const allowedExtensions = ['.csv', '.txt', '.xlsx', '.xls'];
        const fileExtension = '.' + file.name.split('.').pop().toLowerCase();
        
        if (!allowedExtensions.includes(fileExtension)) {
            alert('Tipe file tidak didukung. Gunakan CSV, TXT, atau XLSX.');
            clearFile();
            return;
        }
        
        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('Ukuran file terlalu besar. Maksimal 10MB.');
            clearFile();
            return;
        }
        
        // Show loading
        showFileLoading();
        
        // Upload and process file
        const formData = new FormData();
        formData.append('file', file);
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
        
        fetch('{{ route("analysis.upload-file") }}', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayFilePreview(file, data.data);
            } else {
                alert('Gagal memproses file: ' + data.message);
                clearFile();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan saat memproses file.');
            clearFile();
        });
    }

    function showFileLoading() {
        document.getElementById('upload-placeholder').classList.add('hidden');
        document.getElementById('file-preview').classList.remove('hidden');
        document.getElementById('file-name').textContent = 'Memproses file...';
        document.getElementById('file-size').textContent = '...';
        document.getElementById('file-type').textContent = '...';
        document.getElementById('total-rows').textContent = '...';
        document.getElementById('valid-rows').textContent = '...';
        document.getElementById('columns-count').textContent = '...';
    }

    function displayFilePreview(file, data) {
        document.getElementById('upload-placeholder').classList.add('hidden');
        document.getElementById('file-preview').classList.remove('hidden');
        
        // File info
        document.getElementById('file-name').textContent = file.name;
        document.getElementById('file-size').textContent = formatFileSize(file.size);
        document.getElementById('file-type').textContent = file.name.split('.').pop().toUpperCase();
        
        // Data info
        document.getElementById('total-rows').textContent = data.total;
        document.getElementById('valid-rows').textContent = data.total;
        document.getElementById('columns-count').textContent = data.headers ? data.headers.length : 1;
    }

    function clearFile() {
        fileInput.value = '';
        document.getElementById('upload-placeholder').classList.remove('hidden');
        document.getElementById('file-preview').classList.add('hidden');
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    // ==========================================
    // Analysis Type Change Handler
    // ==========================================
    document.querySelectorAll('input[name="analysis_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const selectedType = this.value;
            
            // Show/hide aspect options
            if (selectedType === 'aspect' || selectedType === 'combined') {
                document.getElementById('aspect-options').classList.remove('hidden');
            } else {
                document.getElementById('aspect-options').classList.add('hidden');
            }
            
            // Show/hide topic options
            if (selectedType === 'topic' || selectedType === 'combined') {
                document.getElementById('topic-options').classList.remove('hidden');
            } else {
                document.getElementById('topic-options').classList.add('hidden');
            }
        });
    });

    // ==========================================
    // Aspect Mode Change Handler
    // ==========================================
    document.querySelectorAll('input[name="aspect_mode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'rule-based') {
                document.getElementById('predefined-aspects-container').classList.remove('hidden');
            } else {
                document.getElementById('predefined-aspects-container').classList.add('hidden');
            }
        });
    });

    // ==========================================
    // Aspect Tags Handler
    // ==========================================
    document.getElementById('predefined_aspects').addEventListener('input', function() {
        const aspects = this.value.split(',').map(a => a.trim()).filter(a => a !== '');
        const tagsContainer = document.getElementById('aspect-tags');
        
        tagsContainer.innerHTML = '';
        aspects.forEach(aspect => {
            const tag = document.createElement('span');
            tag.className = 'aspect-tag';
            tag.innerHTML = `
                ${aspect}
                <svg class="w-4 h-4 ml-1 cursor-pointer" fill="currentColor" viewBox="0 0 20 20" onclick="removeAspect('${aspect}')">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            `;
            tagsContainer.appendChild(tag);
        });
    });

    function removeAspect(aspectToRemove) {
        const input = document.getElementById('predefined_aspects');
        const aspects = input.value.split(',').map(a => a.trim()).filter(a => a !== aspectToRemove);
        input.value = aspects.join(', ');
        input.dispatchEvent(new Event('input'));
    }

    // ==========================================
    // Preview Modal
    // ==========================================
    function validateAndShowPreview() {
        const form = document.getElementById('analysisForm');
        
        // Basic validation
        const title = document.getElementById('title').value;
        const analysisType = document.querySelector('input[name="analysis_type"]:checked');
        const inputType = document.getElementById('input_type').value;
        
        if (!title) {
            alert('Judul analisis harus diisi!');
            document.getElementById('title').focus();
            return;
        }
        
        if (!analysisType) {
            alert('Pilih tipe analisis!');
            return;
        }
        
        // Check input
        if (inputType === 'manual') {
            const manualText = document.getElementById('manual_text').value.trim();
            if (!manualText) {
                alert('Masukkan teks yang ingin dianalisis!');
                document.getElementById('manual_text').focus();
                return;
            }
        } else {
            const fileInput = document.getElementById('file-input');
            if (!fileInput.files.length) {
                alert('Upload file terlebih dahulu!');
                return;
            }
        }
        
        // Generate preview content
        generatePreview();
        
        // Show modal
        document.getElementById('preview-modal').classList.remove('hidden');
    }

    function generatePreview() {
        const title = document.getElementById('title').value;
        const description = document.getElementById('description').value;
        const analysisType = document.querySelector('input[name="analysis_type"]:checked').value;
        const inputType = document.getElementById('input_type').value;
        
        let dataPreview = '';
        if (inputType === 'manual') {
            const lines = document.getElementById('manual_text').value.split('\n').filter(line => line.trim() !== '');
            dataPreview = `
                <div class="space-y-2">
                    <p class="font-medium text-gray-700">Sample data (5 baris pertama):</p>
                    <div class="bg-gray-50 rounded-lg p-4 space-y-2 text-sm font-mono">
                        ${lines.slice(0, 5).map((line, i) => `<div class="text-gray-700">${i + 1}. ${line}</div>`).join('')}
                        ${lines.length > 5 ? `<div class="text-gray-500">... dan ${lines.length - 5} baris lainnya</div>` : ''}
                    </div>
                </div>
            `;
        } else {
            const fileName = document.getElementById('file-name').textContent;
            const fileSize = document.getElementById('file-size').textContent;
            const totalRows = document.getElementById('total-rows').textContent;
            dataPreview = `
                <div class="space-y-2">
                    <p class="font-medium text-gray-700">File yang akan diproses:</p>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">${fileName}</p>
                                <p class="text-sm text-gray-500">${fileSize} • ${totalRows} baris</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        const analysisTypeLabel = {
            'sentiment': '😊 Analisis Sentimen',
            'aspect': '🎯 Ekstraksi Aspek',
            'topic': '🔍 Identifikasi Topik',
            'combined': '🎨 Analisis Lengkap'
        };
        
        const previewContent = `
            <div class="space-y-6">
                <div>
                    <h4 class="font-semibold text-gray-900 mb-2">Judul Analisis</h4>
                    <p class="text-gray-700">${title}</p>
                </div>
                
                ${description ? `
                <div>
                    <h4 class="font-semibold text-gray-900 mb-2">Deskripsi</h4>
                    <p class="text-gray-700">${description}</p>
                </div>
                ` : ''}
                
                <div>
                    <h4 class="font-semibold text-gray-900 mb-2">Tipe Analisis</h4>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                        ${analysisTypeLabel[analysisType]}
                    </span>
                </div>
                
                ${dataPreview}
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-blue-900">Perkiraan waktu proses</p>
                            <p class="text-sm text-blue-700 mt-1">~5-30 detik tergantung jumlah data</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('preview-content').innerHTML = previewContent;
    }

    function closePreviewModal() {
        document.getElementById('preview-modal').classList.add('hidden');
    }

    // ==========================================
    // Form Validation
    // ==========================================
    document.getElementById('analysisForm').addEventListener('submit', function(e) {
        const inputType = document.getElementById('input_type').value;
        
        if (inputType === 'manual') {
            const manualText = document.getElementById('manual_text').value.trim();
            if (!manualText) {
                e.preventDefault();
                alert('Masukkan teks yang ingin dianalisis!');
                document.getElementById('manual_text').focus();
                return false;
            }
        } else {
            const fileInput = document.getElementById('file-input');
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Upload file terlebih dahulu!');
                return false;
            }
        }
    });

    // ==========================================
    // Initialize
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        // Trigger change event for selected analysis type (for old input)
        const selectedAnalysisType = document.querySelector('input[name="analysis_type"]:checked');
        if (selectedAnalysisType) {
            selectedAnalysisType.dispatchEvent(new Event('change'));
        }
    });
</script>
@endpush
@endsection