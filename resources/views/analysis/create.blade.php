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
    
    .file-config-section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-top: 1rem;
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

                    <!-- File Configuration Section (Hidden initially) -->
                    <div id="file-config" class="hidden mt-6 space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-900">Konfigurasi File</h4>
                            <span class="text-xs text-gray-500">Sesuaikan pengaturan sesuai format file Anda</span>
                        </div>

                        <!-- Excel/CSV Configuration -->
                        <div id="excel-config" class="file-config-section hidden">
                            <h5 class="font-medium text-gray-900 mb-3">Pengaturan Excel/CSV</h5>
                            
                            <!-- Has Header -->
                            <div class="mb-4">
                                <label class="flex items-center space-x-3">
                                    <input type="checkbox" 
                                           name="file_has_header" 
                                           id="file_has_header"
                                           class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                                           checked
                                           onchange="toggleHeaderOptions()">
                                    <span class="text-sm font-medium text-gray-700">File memiliki baris header (baris pertama adalah nama kolom)</span>
                                </label>
                            </div>

                            <!-- Column Selection (with header) -->
                            <div id="column-with-header" class="space-y-3">
                                <label for="text_column_name" class="block text-sm font-medium text-gray-700">
                                    Pilih Kolom Teks <span class="text-red-500">*</span>
                                </label>
                                <select name="text_column_name" 
                                        id="text_column_name"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="">-- Pilih kolom setelah file di-upload --</option>
                                </select>
                                <p class="text-xs text-gray-500">Pilih kolom yang berisi teks yang ingin dianalisis</p>
                            </div>

                            <!-- Column Selection (without header) -->
                            <div id="column-without-header" class="hidden space-y-3">
                                <label for="text_column_index" class="block text-sm font-medium text-gray-700">
                                    Nomor Kolom Teks <span class="text-red-500">*</span>
                                </label>
                                <input type="number" 
                                       name="text_column_index" 
                                       id="text_column_index"
                                       min="1"
                                       value="1"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <p class="text-xs text-gray-500">Masukkan nomor kolom (dimulai dari 1) yang berisi teks untuk dianalisis</p>
                            </div>

                            <!-- CSV Delimiter (CSV only) -->
                            <div id="csv-delimiter-config" class="hidden space-y-3">
                                <label for="csv_delimiter" class="block text-sm font-medium text-gray-700">
                                    Pemisah Kolom (Delimiter)
                                </label>
                                <select name="csv_delimiter" 
                                        id="csv_delimiter"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="," selected>Koma (,)</option>
                                    <option value=";">Titik Koma (;)</option>
                                    <option value="\t">Tab</option>
                                    <option value="|">Pipe (|)</option>
                                </select>
                            </div>

                            <!-- Sheet Selection (Excel only) -->
                            <div id="excel-sheet-config" class="hidden space-y-3">
                                <label for="excel_sheet" class="block text-sm font-medium text-gray-700">
                                    Pilih Sheet
                                </label>
                                <select name="excel_sheet" 
                                        id="excel_sheet"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="0">Sheet 1 (Default)</option>
                                </select>
                            </div>
                        </div>

                        <!-- TXT Configuration -->
                        <div id="txt-config" class="file-config-section hidden">
                            <h5 class="font-medium text-gray-900 mb-3">Pengaturan File TXT</h5>
                            
                            <!-- Record Separator -->
                            <div class="space-y-3">
                                <label class="block text-sm font-medium text-gray-700">
                                    Pemisah Antar Record <span class="text-red-500">*</span>
                                </label>
                                
                                <div class="space-y-2">
                                    <label class="flex items-start">
                                        <input type="radio" 
                                               name="txt_separator" 
                                               value="newline" 
                                               class="mt-1 mr-3"
                                               checked>
                                        <div>
                                            <span class="font-medium text-gray-900">Baris Baru (Enter)</span>
                                            <p class="text-sm text-gray-500">Setiap baris adalah satu record terpisah</p>
                                            <code class="block mt-1 text-xs bg-gray-100 p-2 rounded">Teks pertama\nTeks kedua\nTeks ketiga</code>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-start">
                                        <input type="radio" 
                                               name="txt_separator" 
                                               value="period" 
                                               class="mt-1 mr-3">
                                        <div>
                                            <span class="font-medium text-gray-900">Titik (.)</span>
                                            <p class="text-sm text-gray-500">Teks dipisahkan oleh karakter titik</p>
                                            <code class="block mt-1 text-xs bg-gray-100 p-2 rounded">Teks pertama. Teks kedua. Teks ketiga.</code>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-start">
                                        <input type="radio" 
                                               name="txt_separator" 
                                               value="double_newline" 
                                               class="mt-1 mr-3">
                                        <div>
                                            <span class="font-medium text-gray-900">Baris Kosong (Double Enter)</span>
                                            <p class="text-sm text-gray-500">Paragraf dipisahkan oleh baris kosong</p>
                                            <code class="block mt-1 text-xs bg-gray-100 p-2 rounded">Teks pertama\n\nTeks kedua\n\nTeks ketiga</code>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-start">
                                        <input type="radio" 
                                               name="txt_separator" 
                                               value="custom" 
                                               class="mt-1 mr-3"
                                               onchange="toggleCustomSeparator()">
                                        <div class="flex-1">
                                            <span class="font-medium text-gray-900">Custom (Tentukan sendiri)</span>
                                            <p class="text-sm text-gray-500 mb-2">Masukkan karakter pemisah kustom</p>
                                            <input type="text" 
                                                   name="txt_custom_separator" 
                                                   id="txt_custom_separator"
                                                   placeholder="Contoh: ||, ---, ###"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                                                   disabled>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Encoding -->
                            <div class="space-y-3 mt-4">
                                <label for="txt_encoding" class="block text-sm font-medium text-gray-700">
                                    Encoding File
                                </label>
                                <select name="txt_encoding" 
                                        id="txt_encoding"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="utf-8" selected>UTF-8 (Rekomendasi)</option>
                                    <option value="iso-8859-1">ISO-8859-1 (Latin-1)</option>
                                    <option value="windows-1252">Windows-1252</option>
                                </select>
                                <p class="text-xs text-gray-500">Pilih encoding yang sesuai dengan file Anda untuk menghindari karakter aneh</p>
                            </div>
                        </div>

                        <!-- Preview Section -->
                        <div id="file-data-preview" class="hidden">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <h5 class="font-medium text-blue-900">Preview Data</h5>
                                    <span id="selected-column-indicator" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded"></span>
                                </div>
                                <div class="text-sm text-blue-800 space-y-1">
                                    <p>📊 <strong>Total records:</strong> <span id="preview-total">-</span></p>
                                    <p>✅ <strong>Valid records:</strong> <span id="preview-valid">-</span></p>
                                </div>
                                <div id="preview-sample" class="mt-3 bg-white rounded-lg p-0 border border-gray-200 overflow-hidden text-sm w-full">
                                    <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                                        <p class="font-medium text-gray-700" id="preview-title">Sample Data (3 Baris Pertama)</p>
                                    </div>
                                    <div id="preview-content" class="p-0 overflow-x-auto w-full">
                                        <!-- Will be populated by JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
    // Global variable to store file metadata
    let currentFileData = null;

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
                currentFileData = data.data;
                displayFilePreview(file, data.data);
                showFileConfiguration(fileExtension, data.data);
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
        document.getElementById('total-rows').textContent = data.total || 0;
        document.getElementById('valid-rows').textContent = data.valid || data.total || 0;
        document.getElementById('columns-count').textContent = data.headers ? data.headers.length : 1;
    }

    function showFileConfiguration(fileExtension, data) {
        // Show file config section
        document.getElementById('file-config').classList.remove('hidden');
        
        // Hide all config sections first
        document.getElementById('excel-config').classList.add('hidden');
        document.getElementById('txt-config').classList.add('hidden');
        document.getElementById('csv-delimiter-config').classList.add('hidden');
        document.getElementById('excel-sheet-config').classList.add('hidden');
        
        // Show appropriate config based on file type
        if (fileExtension === '.xlsx' || fileExtension === '.xls') {
            // Excel configuration
            document.getElementById('excel-config').classList.remove('hidden');
            document.getElementById('excel-sheet-config').classList.remove('hidden');
            
            // Populate sheet options if available
            if (data.sheets && data.sheets.length > 0) {
                const sheetSelect = document.getElementById('excel_sheet');
                sheetSelect.innerHTML = '';
                data.sheets.forEach((sheet, index) => {
                    const option = document.createElement('option');
                    option.value = index;
                    option.textContent = sheet;
                    sheetSelect.appendChild(option);
                });
            }
            
            // Populate column options
            populateColumnOptions(data);
            
        } else if (fileExtension === '.csv') {
            // CSV configuration
            document.getElementById('excel-config').classList.remove('hidden');
            document.getElementById('csv-delimiter-config').classList.remove('hidden');
            
            // Populate column options
            populateColumnOptions(data);
            
        } else if (fileExtension === '.txt') {
            // TXT configuration
            document.getElementById('txt-config').classList.remove('hidden');
        }
        
        // Show preview
        showDataPreview(data);
    }

    function populateColumnOptions(data) {
        if (data.headers && data.headers.length > 0) {
            const columnSelect = document.getElementById('text_column_name');
            columnSelect.innerHTML = '<option value="">-- Pilih kolom --</option>';
            
            data.headers.forEach((header, index) => {
                const option = document.createElement('option');
                option.value = header;
                option.textContent = `${header} (Kolom ${index + 1})`;
                columnSelect.appendChild(option);
            });
            
            // Auto-select first column that likely contains text (excluding IDs, links, dates)
            const textColumns = data.headers.filter(h => {
                const lower = h.toLowerCase();
                // Exclude columns that are clearly IDs, links, dates, or numeric keys
                if (lower === 'id' || lower.endsWith('_id') || lower.endsWith(' id') || 
                    lower.includes('url') || lower.includes('link') || 
                    lower.includes('date') || lower.includes('time') || lower.includes('tanggal')) {
                    return false;
                }
                return lower.includes('text') || 
                       lower.includes('review') || 
                       lower.includes('comment') ||
                       lower.includes('content') ||
                       lower.includes('komentar') ||
                       lower.includes('ulasan');
            });
            
            if (textColumns.length > 0) {
                columnSelect.value = textColumns[0];
            } else if (data.headers.length > 0) {
                columnSelect.value = data.headers[0];
            }
        }
    }

    function showDataPreview(data) {
        const previewSection = document.getElementById('file-data-preview');
        previewSection.classList.remove('hidden');
        
        document.getElementById('preview-total').textContent = data.total || 0;
        document.getElementById('preview-valid').textContent = data.valid || data.total || 0;
        
        // Get selected column info
        const hasHeader = document.getElementById('file_has_header').checked;
        const selectedColumn = hasHeader 
            ? document.getElementById('text_column_name').value 
            : parseInt(document.getElementById('text_column_index').value) - 1;
        
        // Update column indicator
        const columnIndicator = document.getElementById('selected-column-indicator');
        if (hasHeader && selectedColumn) {
            columnIndicator.textContent = `📌 Kolom: ${selectedColumn}`;
        } else if (!hasHeader && selectedColumn !== undefined && !isNaN(selectedColumn)) {
            columnIndicator.textContent = `📌 Kolom ke-${selectedColumn + 1}`;
        } else {
            columnIndicator.textContent = '⚠️ Pilih kolom terlebih dahulu';
        }
        
        // Show sample data
        if (data.sample && data.sample.length > 0) {
            const previewContent = document.getElementById('preview-content');
            previewContent.innerHTML = '';
            
            if (typeof data.sample[0] === 'object' && data.sample[0] !== null) {
                // Render as Table
                let tableHtml = '<table class="min-w-full divide-y divide-gray-200 text-left text-sm whitespace-nowrap">';
                
                // Headers
                tableHtml += '<thead class="bg-gray-50"><tr>';
                tableHtml += '<th scope="col" class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider w-10">#</th>';
                
                const headers = data.headers || Object.keys(data.sample[0]);
                headers.forEach((header, index) => {
                    const isSelected = (hasHeader && header === selectedColumn) || (!hasHeader && index === selectedColumn);
                    const bgClass = isSelected ? 'bg-blue-100 text-blue-800' : 'text-gray-500';
                    tableHtml += `<th scope="col" class="px-4 py-3 text-xs font-semibold uppercase tracking-wider ${bgClass}">
                        ${header} ${isSelected ? '<span class="ml-1">✓</span>' : ''}
                    </th>`;
                });
                tableHtml += '</tr></thead>';
                
                // Body
                tableHtml += '<tbody class="bg-white divide-y divide-gray-200">';
                data.sample.slice(0, 3).forEach((row, index) => {
                    tableHtml += `<tr class="hover:bg-gray-50 transition-colors">`;
                    tableHtml += `<td class="px-4 py-3 text-gray-500 text-xs">${index + 1}</td>`;
                    
                    headers.forEach((header, hIndex) => {
                        const val = row[header] !== undefined ? row[header] : (Object.values(row)[hIndex] || '');
                        // Truncate for display
                        const displayVal = String(val).length > 80 ? String(val).substring(0, 80) + '...' : val;
                        
                        const isSelected = (hasHeader && header === selectedColumn) || (!hasHeader && hIndex === selectedColumn);
                        const textClass = isSelected ? 'font-medium text-blue-900 bg-blue-50/50' : 'text-gray-700';
                        
                        tableHtml += `<td class="px-4 py-3 ${textClass}" title="${String(val).replace(/"/g, '&quot;')}">
                            ${displayVal}
                        </td>`;
                    });
                    
                    tableHtml += `</tr>`;
                });
                tableHtml += '</tbody></table>';
                
                previewContent.innerHTML = tableHtml;
            } else {
                // Render as Plain Text List
                const listContainer = document.createElement('div');
                listContainer.className = 'p-4 space-y-2';
                
                data.sample.slice(0, 3).forEach((row, index) => {
                    const div = document.createElement('div');
                    div.className = 'text-gray-700 bg-gray-50 p-3 rounded-lg border border-gray-100 font-mono text-xs break-words whitespace-pre-wrap';
                    div.textContent = `${index + 1}. ${row}`;
                    listContainer.appendChild(div);
                });
                previewContent.appendChild(listContainer);
            }
            
            // Show message if no column selected
            if (typeof data.sample[0] === 'object' && data.sample[0] !== null) {
                if ((!hasHeader && (selectedColumn === undefined || isNaN(selectedColumn))) || 
                    (hasHeader && !selectedColumn)) {
                    const warningDiv = document.createElement('div');
                    warningDiv.className = 'bg-yellow-50 text-yellow-700 p-3 text-sm border-t border-yellow-100 flex items-center';
                    warningDiv.innerHTML = '<svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg> Menampilkan semua kolom. Silakan pilih satu kolom teks yang ingin dianalisis.';
                    previewContent.appendChild(warningDiv);
                }
            }
        }
    }
    
    // Update preview when column selection changes
    function updatePreviewOnColumnChange() {
        if (currentFileData && currentFileData.sample) {
            showDataPreview(currentFileData);
        }
    }

    function toggleHeaderOptions() {
        const hasHeader = document.getElementById('file_has_header').checked;
        
        if (hasHeader) {
            document.getElementById('column-with-header').classList.remove('hidden');
            document.getElementById('column-without-header').classList.add('hidden');
        } else {
            document.getElementById('column-with-header').classList.add('hidden');
            document.getElementById('column-without-header').classList.remove('hidden');
        }
    }

    function toggleCustomSeparator() {
        const customInput = document.getElementById('txt_custom_separator');
        const isCustom = document.querySelector('input[name="txt_separator"][value="custom"]').checked;
        
        customInput.disabled = !isCustom;
        if (isCustom) {
            customInput.focus();
        }
    }

    function clearFile() {
        fileInput.value = '';
        currentFileData = null;
        document.getElementById('upload-placeholder').classList.remove('hidden');
        document.getElementById('file-preview').classList.add('hidden');
        document.getElementById('file-config').classList.add('hidden');
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
            
            // Validate file configuration
            const fileExt = '.' + fileInput.files[0].name.split('.').pop().toLowerCase();
            
            if (fileExt === '.xlsx' || fileExt === '.xls' || fileExt === '.csv') {
                const hasHeader = document.getElementById('file_has_header').checked;
                
                if (hasHeader) {
                    const columnName = document.getElementById('text_column_name').value;
                    if (!columnName) {
                        alert('Pilih kolom yang berisi teks untuk dianalisis!');
                        return;
                    }
                } else {
                    const columnIndex = document.getElementById('text_column_index').value;
                    if (!columnIndex || columnIndex < 1) {
                        alert('Masukkan nomor kolom yang valid!');
                        return;
                    }
                }
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
            const fileExt = '.' + fileName.split('.').pop().toLowerCase();
            
            let configInfo = '';
            
            if (fileExt === '.xlsx' || fileExt === '.xls' || fileExt === '.csv') {
                const hasHeader = document.getElementById('file_has_header').checked;
                
                if (hasHeader) {
                    const columnName = document.getElementById('text_column_name').value;
                    configInfo = `<p class="text-sm text-gray-600">📌 Kolom teks: <strong>${columnName}</strong></p>`;
                } else {
                    const columnIndex = document.getElementById('text_column_index').value;
                    configInfo = `<p class="text-sm text-gray-600">📌 Kolom ke-<strong>${columnIndex}</strong></p>`;
                }
                
                if (fileExt === '.csv') {
                    const delimiter = document.getElementById('csv_delimiter').value;
                    const delimiterName = {',': 'Koma', ';': 'Titik Koma', '\t': 'Tab', '|': 'Pipe'}[delimiter];
                    configInfo += `<p class="text-sm text-gray-600">📌 Delimiter: <strong>${delimiterName}</strong></p>`;
                }
            } else if (fileExt === '.txt') {
                const separator = document.querySelector('input[name="txt_separator"]:checked').value;
                const separatorName = {
                    'newline': 'Baris Baru (Enter)',
                    'period': 'Titik (.)',
                    'double_newline': 'Baris Kosong',
                    'custom': 'Custom'
                }[separator];
                
                configInfo = `<p class="text-sm text-gray-600">📌 Pemisah: <strong>${separatorName}</strong></p>`;
                
                if (separator === 'custom') {
                    const customSep = document.getElementById('txt_custom_separator').value;
                    configInfo += `<p class="text-sm text-gray-600">📌 Custom separator: <strong>${customSep}</strong></p>`;
                }
            }
            
            dataPreview = `
                <div class="space-y-3">
                    <p class="font-medium text-gray-700">File yang akan diproses:</p>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center space-x-3 mb-3">
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
                        ${configInfo}
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
        
        // Initialize custom separator toggle
        document.querySelectorAll('input[name="txt_separator"]').forEach(radio => {
            radio.addEventListener('change', toggleCustomSeparator);
        });
        
        // Add event listeners for column selection changes to update preview
        const textColumnNameSelect = document.getElementById('text_column_name');
        const textColumnIndexInput = document.getElementById('text_column_index');
        const fileHasHeaderCheckbox = document.getElementById('file_has_header');
        
        if (textColumnNameSelect) {
            textColumnNameSelect.addEventListener('change', updatePreviewOnColumnChange);
        }
        
        if (textColumnIndexInput) {
            textColumnIndexInput.addEventListener('input', updatePreviewOnColumnChange);
        }
        
        if (fileHasHeaderCheckbox) {
            fileHasHeaderCheckbox.addEventListener('change', function() {
                toggleHeaderOptions();
                updatePreviewOnColumnChange();
            });
        }
    });
</script>
@endpush
@endsection