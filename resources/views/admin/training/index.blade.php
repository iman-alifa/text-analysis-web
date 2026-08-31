@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Active Learning Center</h1>
            <p class="mt-1 text-sm text-gray-600">Kelola data training, koreksi hasil analisis, dan konfigurasi model.</p>
        </div>
        
        <div class="flex gap-3">
            <a href="{{ route('admin.training.export') }}" target="_blank"
            class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
            </a>

            <form action="{{ route('admin.training.sync') }}" method="POST">
                @csrf
                <button type="submit" onclick="return confirm('Proses ini akan membaca semua hasil analisis lama dan menyiapkannya untuk training. Lanjutkan?')" 
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-md transition text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Sync Data Lama
                </button>
            </form>

            <form action="{{ route('admin.training.trigger') }}" method="POST" class="flex gap-2">
                @csrf
                <select name="model_type"
                        class="rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="both">Sentimen + Aspek</option>
                    <option value="sentiment">Sentimen saja</option>
                    <option value="aspect">Aspek saja</option>
                </select>
                <input type="number" name="epochs" value="3" min="1" max="20" title="Jumlah epoch"
                       class="w-20 rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <button type="submit" onclick="return confirm('Kirim data terkoreksi ke NLP API untuk melatih ulang model?')" 
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 shadow-md transition text-sm font-medium">
                    Retrain Model
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Akurasi Global</p>
            <div class="flex items-baseline mt-2">
                <p class="text-3xl font-bold text-purple-600">{{ $stats['accuracy'] }}%</p>
            </div>
            <p class="text-xs text-gray-400 mt-1">Rata-rata dari semua file</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Data Baris</p>
            <p class="text-3xl font-bold text-gray-800 mt-2">{{ number_format($stats['total_texts']) }}</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Sudah Diverifikasi</p>
            <p class="text-3xl font-bold text-green-600 mt-2">{{ number_format($stats['corrected_count']) }}</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Antrian Review</p>
            <p class="text-3xl font-bold text-yellow-600 mt-2">{{ number_format($stats['pending_count']) }}</p>
        </div>
    </div>

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
            <form action="{{ route('admin.training.stopword') }}" method="POST" class="flex gap-2 w-full md:w-auto">
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
            <form action="{{ route('admin.training.stopword.delete', $sw->id) }}" method="POST" class="inline">
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
                        <th class="px-6 py-3">Tipe Analisis</th> {{-- Kolom Baru --}}
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
                            {{-- Badge Tipe Analisis --}}
                            @php
                                $typeLabel = ucwords(str_replace('_', ' ', $batch->analysis_type));
                                $badgeColor = match($batch->analysis_type) {
                                    'sentiment_analysis' => 'bg-blue-100 text-blue-700 border-blue-200',
                                    'topic_modeling' => 'bg-purple-100 text-purple-700 border-purple-200',
                                    'aspect_extraction' => 'bg-orange-100 text-orange-700 border-orange-200',
                                    'combined' => 'bg-teal-100 text-teal-700 border-teal-200',
                                    default => 'bg-gray-100 text-gray-700 border-gray-200'
                                };
                            @endphp
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium border {{ $badgeColor }}">
                                {{ $typeLabel }}
                            </span>
                        </td>
                        <td class="px-6 py-4 w-4/12">
                            @php
                                $total = $batch->total_records > 0 ? $batch->total_records : 1; 
                                $verified = $batch->verified_count ?? 0;
                                $percent = round(($verified / $total) * 100);
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
                            <a href="{{ route('admin.training.show', $batch->id) }}" 
                               class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-indigo-600 transition shadow-sm">
                                Buka &rarr;
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-400 italic">
                            Belum ada file analisis yang diupload.
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

    {{-- Riwayat retraining: bukti loop active learning benar-benar berjalan --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Riwayat Training Model</h2>
            <p class="mt-1 text-sm text-gray-600">
                Hasil pengiriman data terkoreksi ke NLP API. Training berjalan di background,
                jadi pastikan queue worker aktif.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <th class="px-6 py-3">Waktu</th>
                        <th class="px-6 py-3">Model</th>
                        <th class="px-6 py-3">Sampel</th>
                        <th class="px-6 py-3">Epoch</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Hasil</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($trainings as $training)
                    <tr>
                        <td class="px-6 py-3 text-gray-600 whitespace-nowrap">
                            {{ $training->created_at->format('d M Y H:i') }}
                            @if($training->duration)
                                <span class="block text-xs text-gray-400">durasi {{ $training->duration }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 font-medium text-gray-900 capitalize">{{ $training->model_type }}</td>
                        <td class="px-6 py-3 text-gray-600">{{ $training->total_samples }}</td>
                        <td class="px-6 py-3 text-gray-600">{{ $training->epochs }}</td>
                        <td class="px-6 py-3">
                            @php
                                $badge = match($training->status) {
                                    'completed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'running' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                            @endphp
                            <span class="px-2 py-1 rounded-full text-xs font-medium {{ $badge }}">
                                {{ ucfirst($training->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-gray-600">
                            @if($training->status === 'failed')
                                <span class="text-red-600">{{ Str::limit($training->error_message, 80) }}</span>
                            @elseif($training->result)
                                <span class="font-mono text-xs">{{ Str::limit(json_encode($training->result), 90) }}</span>
                            @else
                                <span class="text-gray-400">&mdash;</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            Belum ada training yang dijalankan.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Perkembangan metrik antar-iterasi active learning --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Perkembangan Evaluasi Model</h2>
            <p class="mt-1 text-sm text-gray-600">
                Metrik direkam setiap kali retraining dipicu, sehingga perbaikan model
                antar-iterasi bisa ditelusuri.
            </p>
        </div>

        @php $chronological = $snapshots->reverse()->values(); @endphp

        @if($chronological->isEmpty())
            <p class="px-6 py-8 text-center text-gray-500">
                Belum ada rekaman evaluasi. Rekaman pertama dibuat saat Anda memicu retraining.
            </p>
        @else
            <div class="p-6">
                <div class="h-64">
                    <canvas id="evaluationTrendChart"></canvas>
                </div>
            </div>

            <div class="overflow-x-auto border-t border-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <th class="px-6 py-3">Waktu</th>
                            <th class="px-6 py-3">Data terkoreksi</th>
                            <th class="px-6 py-3">Akurasi sentimen</th>
                            <th class="px-6 py-3">F1 sentimen</th>
                            <th class="px-6 py-3">F1 aspek</th>
                            <th class="px-6 py-3">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($snapshots as $snapshot)
                        <tr>
                            <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ $snapshot->created_at->format('d M Y H:i') }}</td>
                            <td class="px-6 py-3 text-gray-600">{{ $snapshot->corrected_total }}</td>
                            <td class="px-6 py-3 text-gray-900 font-medium">
                                {{ $snapshot->sentiment_accuracy !== null ? $snapshot->sentiment_accuracy . '%' : '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ $snapshot->sentiment_weighted_f1 !== null ? $snapshot->sentiment_weighted_f1 . '%' : '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ $snapshot->aspect_f1 !== null ? $snapshot->aspect_f1 . '%' : '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ $snapshot->note }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
@if($snapshots->isNotEmpty())
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('evaluationTrendChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const snapshots = @json($snapshots->reverse()->values());

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: snapshots.map(s => new Date(s.created_at).toLocaleDateString('id-ID', {
                day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
            })),
            datasets: [
                {
                    label: 'Akurasi sentimen (%)',
                    data: snapshots.map(s => s.sentiment_accuracy),
                    borderColor: 'rgb(79, 70, 229)',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                },
                {
                    label: 'F1 sentimen (%)',
                    data: snapshots.map(s => s.sentiment_weighted_f1),
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                },
                {
                    label: 'F1 aspek (%)',
                    data: snapshots.map(s => s.aspect_f1),
                    borderColor: 'rgb(249, 115, 22)',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    tension: 0.3,
                    spanGaps: true,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, ticks: { callback: value => value + '%' } }
            },
            plugins: { legend: { position: 'bottom' } }
        }
    });
});
</script>
@endif
@endpush