@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Active Learning Center</h1>
            <p class="mt-1 text-sm text-gray-600">Kelola data training, koreksi hasil analisis, dan konfigurasi model.</p>
        </div>
        
        {{-- Tombol utilitas. Dulu baris ini juga memuat seluruh formulir
             retrain (dua select + input angka + tombol) dalam satu `flex`
             tanpa pembungkus, sehingga melewati lebar halaman pada layar yang
             lebih sempit dari ~1.600px. Formulirnya kini panel tersendiri di
             bawah - ia butuh ruang untuk keterangan ambang dan centang
             "paksa", dan itu tidak muat di bilah tombol. --}}
        <div class="flex flex-wrap items-center gap-3">
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

            <button type="button"
                    id="btn-preview-retrain"
                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 shadow-sm transition text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Periksa Data Training
            </button>
        </div>
    </div>

    @include('admin.training.partials.tunda')

    {{-- Panel pelatihan ulang.
         Tiap putaran melatih dari base model atas SELURUH kolam koreksi -
         protokol active learning baku, dan sama dengan yang dipakai layanan
         (`retrain_from_baseline = true`). Menyaring per analisis akan membuat
         putaran kedua berangkat dari base model tetapi hanya melihat lembaga
         kedua: lembaga pertama terlupakan, dan kurvanya mengukur "ganti
         korpus" alih-alih "tambah data".
         Pemilihan lembaga tetap WAJIB, tetapi sebagai PENCATATAN asal putaran -
         dulu "Semua analisis (gabungan)" adalah opsi pertama sehingga terpilih
         sendiri, dan kedua pelatihan iterasi 1 tercatat tanpa asal sama sekali. --}}
    <div class="bg-white rounded-xl shadow-sm border border-indigo-100 p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Jalankan Putaran Active Learning</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Satu putaran melatih ulang model dari base model memakai <strong>seluruh kolam koreksi</strong> ({{ number_format($autoStatus['pool']) }} baris), mengikuti protokol active learning baku. Ukur hasilnya di halaman evaluasi sebelum menjalankan putaran berikutnya: bobot lama tertimpa, jadi iterasi yang belum diukur tidak bisa diukur belakangan.
                </p>
            </div>

            {{-- Statusnya tampil di sini dan bukan di halaman pengguna -
                 pelatihan ulang adalah urusan internal sistem. --}}
            <div class="flex items-center gap-2 px-3 py-2 rounded-lg border text-sm shrink-0
                        {{ $autoStatus['enabled']
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                            : 'border-gray-200 bg-gray-50 text-gray-600' }}">
                @if(! $autoStatus['enabled'])
                    <span>Pelatihan otomatis: <strong>mati</strong></span>
                @elseif($autoStatus['running'])
                    <span>Pelatihan sedang berjalan</span>
                @elseif($autoStatus['cooldown_hours_left'] > 0)
                    <span>{{ 'Otomatis: jeda '.$autoStatus['cooldown_hours_left'].' jam lagi' }}</span>
                @else
                    <span>{{ 'Otomatis: '.$autoStatus['new_corrections'].'/'.$autoStatus['threshold'].' koreksi baru' }}</span>
                @endif
            </div>
        </div>

        <form action="{{ route('admin.training.trigger') }}" method="POST"
              id="form-retrain" class="mt-5 space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="lg:col-span-2">
                    <label for="retrain-analysis" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">
                        Lembaga yang baru masuk kolam
                    </label>
                    <select name="text_analysis_id" id="retrain-analysis" required
                            class="w-full rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="" disabled selected>Pilih analisis&hellip;</option>
                        @foreach($perAnalisis as $satu)
                            <option value="{{ $satu->id }}">#{{ $satu->id }} {{ Str::limit($satu->title, 28) }} (menyumbang {{ $satu->koreksi_count }} koreksi)</option>
                        @endforeach
                        {{-- Tetap ada untuk putaran yang tidak berasal dari satu
                             lembaga tertentu - mis. mengulang pengukuran setelah
                             beberapa lembaga dikoreksi sekaligus. Sejak kolamnya
                             tidak lagi disaring, pilihan ini tidak mengubah data
                             latih sama sekali; hanya barisnya di riwayat. --}}
                        <option value="gabungan">Tidak spesifik / beberapa lembaga sekaligus</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        Dicatat di riwayat untuk penamaan iterasi. <strong>Bukan penyaring</strong>, karena data training-nya tetap seluruh kolam.
                    </p>
                </div>
                <div>
                    <label for="retrain-model" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Model</label>
                    <select name="model_type" id="retrain-model"
                            class="w-full rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="both">Sentimen + Aspek</option>
                        <option value="sentiment">Sentimen saja</option>
                        <option value="aspect">Aspek saja</option>
                    </select>
                </div>
                <div>
                    <label for="retrain-epochs" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Epoch</label>
                    <input type="number" name="epochs" id="retrain-epochs" value="3" min="1" max="20"
                           class="w-full rounded-lg border-gray-300 text-sm text-gray-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                {{-- Setara `--force` pada `php artisan retrain:analysis`. Ambangnya
                     kini satu angka untuk kedua jalur; sebelumnya UI menerima 10
                     sampel sementara perintahnya menolak di bawah 50, sehingga
                     tombol ini bisa memicu pelatihan yang perintahnya sendiri tolak. --}}
                <label class="flex items-start gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="paksa" value="1" id="retrain-paksa"
                           class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span>
                        <strong class="text-gray-800">Paksa</strong>: jalankan walau kolamnya di bawah {{ $ambangLatih }} sampel, atau iterasi sebelumnya belum diukur.
                        <span class="block text-xs text-gray-500">Di bawah {{ $ambangLatih }} sampel, set validasi 20%-nya tidak sampai 10 baris sehingga keputusan menerima checkpoint tidak lagi bermakna.</span>
                    </span>
                </label>

                <button type="submit"
                        class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 shadow-md transition text-sm font-medium shrink-0">
                    Retrain Model
                </button>
            </div>
        </form>
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

    {{-- Laporan kesiapan data latih, sebelum melatih apa pun --}}
    <div id="retrain-preview-panel" class="bg-white rounded-xl shadow-sm border border-gray-100 hidden">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Kesiapan Data Training</h2>
            <p class="mt-1 text-sm text-gray-600">
                Komposisi data koreksi yang akan dikirim ke NLP API. Data yang timpang bisa
                menghasilkan model yang hanya menebak kelas mayoritas.
            </p>
        </div>
        <div id="retrain-preview-body" class="p-6 space-y-6"></div>
    </div>

    {{-- Riwayat retraining: bukti loop active learning benar-benar berjalan --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Riwayat Training Model</h2>
            <p class="mt-1 text-sm text-gray-600">
                Hasil pengiriman data terkoreksi ke NLP API. Training berjalan di background,
                jadi pastikan queue worker aktif.
            </p>
            {{-- Tiga kolom yang menentukan sahnya satu putaran - validasi, delta,
                 dan suhu kalibrasi - dulu hanya bisa dilihat dengan membuka basis
                 data. Itu persis bagaimana putaran pertama ketahuan rusak, setelah
                 bobotnya terlanjur terpasang. --}}
            <p class="mt-2 text-xs text-gray-500">
                <strong>Validasi</strong> adalah dasar keputusan menerima checkpoint; di bawah {{ $ambangValidasi }} baris, "tidak memburuk" berhenti berarti apa-apa. <strong>Delta</strong> nol berarti pengukuran tidak membedakan apa pun. <strong>T</strong> yang diwarisi berarti keyakinan model pada iterasi itu belum diukur ulang. <strong>Sidik jari kolam</strong> yang sama dua putaran berturut-turut berarti kolamnya tidak bertambah, dan itu keliru menurut protokol kolam.
                {{-- Keterangan BATAL hanya muncul bila memang ada barisnya.
                     Legenda untuk keadaan yang tidak ada di layar membuat
                     pembaca mencarinya, lalu mengira ada yang terlewat. --}}
                @if($trainings->contains(fn ($satu) => $satu->batal))
                    Baris <strong>BATAL</strong> tetap ditampilkan sebagai jejak audit, tetapi angkanya tidak dicetak dan tidak ikut ke grafik atau laporan mana pun.
                @endif
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <th class="px-6 py-3">Waktu</th>
                        <th class="px-6 py-3">Model</th>
                        <th class="px-6 py-3">Analisis</th>
                        <th class="px-6 py-3">Dipicu</th>
                        <th class="px-6 py-3">Sampel</th>
                        <th class="px-6 py-3">Sidik jari kolam</th>
                        <th class="px-6 py-3">Validasi</th>
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
                        <td class="px-6 py-3 font-medium text-gray-900">
                            <span class="capitalize">{{ $training->model_type }}</span>
                            @if(($pelatihanAktif[$training->model_type] ?? null) === $training->id)
                                <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800"
                                      title="{{ $aktif['pasti'] ? 'Bobot ini yang sedang dipakai layanan (sidik jari kolam cocok).' : 'Tebakan: layanan tidak menyebut sidik jari bobotnya, jadi dianggap pelatihan tersimpan terakhir.' }}">
                                    Aktif{{ $aktif['pasti'] ? '' : '?' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            {{-- Gabungan ditandai tegas: putaran yang mencampur
                                 enam lembaga tidak bisa dilaporkan sebagai
                                 "iterasi untuk lembaga X", dan kedua pelatihan
                                 iterasi 1 tercatat begitu tanpa disengaja. --}}
                            @if($training->text_analysis_id)
                                <span class="text-gray-700">#{{ $training->text_analysis_id }}
                                    <span class="block text-xs text-gray-400">{{ Str::limit($training->textAnalysis?->title ?? '-', 22) }}</span>
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Gabungan</span>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            {{-- triggered_by null berarti dipicu sistem: loop
                                 active learning berjalan tanpa admin. --}}
                            @if($training->triggered_by)
                                <span class="text-gray-600">{{ $training->user?->name ?? 'Pengguna' }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                    Otomatis
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-600">{{ $training->total_samples }}</td>
                        <td class="px-6 py-3">
                            {{-- Isi kolam yang benar-benar dilatihkan. Kontaminasi
                                 checkpoint tidak terlihat dari berkas bobot, dari
                                 ukurannya, maupun dari /health - ketiganya tampak
                                 normal. Angka inilah yang membedakannya. --}}
                            @if($training->sidik_jari_kolam)
                                <span class="font-mono text-xs text-gray-700">{{ $training->sidik_jari_kolam }}</span>
                                @if(($sidikBerulang[$training->id] ?? false))
                                    <span class="block text-xs text-amber-700 font-semibold">sama dengan putaran sebelumnya, berarti kolam tidak bertambah</span>
                                @endif
                            @else
                                <span class="text-gray-400" title="Putaran ini berjalan sebelum sidik jari kolam dicatat">-</span>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            {{-- Dasar seluruh keputusan terima/tolak checkpoint.
                                 Pada beberapa baris, "tidak memburuk" berhenti
                                 berarti apa-apa - putaran pertama menyimpan
                                 checkpoint atas val_size 4 dengan delta 0. --}}
                            @if($training->val_size === null)
                                <span class="text-gray-400">-</span>
                            @elseif($training->val_size < 10)
                                <span class="font-semibold text-red-700">{{ $training->val_size }}</span>
                                <span class="block text-xs text-red-600">terlalu kecil untuk menolak apa pun</span>
                            @elseif($training->val_size < $ambangValidasi)
                                <span class="font-semibold text-amber-700">{{ $training->val_size }}</span>
                                <span class="block text-xs text-amber-600">di bawah {{ $ambangValidasi }}</span>
                            @else
                                <span class="text-gray-700">{{ $training->val_size }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-600">{{ $training->epochs }}</td>
                        <td class="px-6 py-3">
                            @php
                                $badge = match($training->status) {
                                    'completed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    // Ditolak bukan gagal: training berjalan, tapi
                                    // hasilnya lebih buruk sehingga tidak disimpan.
                                    'rejected' => 'bg-amber-100 text-amber-800',
                                    // Dilewati juga bukan gagal: layanan sedang
                                    // melatih model yang sama (HTTP 409), jadi
                                    // pelatihan ini tidak pernah dimulai.
                                    'skipped' => 'bg-gray-100 text-gray-700',
                                    'running' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                                $labelStatus = match($training->status) {
                                    'rejected' => 'Ditolak',
                                    'skipped' => 'Dilewati',
                                    default => ucfirst($training->status),
                                };
                            @endphp
                            <span class="px-2 py-1 rounded-full text-xs font-medium {{ $badge }}">
                                {{ $labelStatus }}
                            </span>

                            {{-- Dibatalkan setelah selesai: angkanya tidak boleh
                                 dikutip. Barisnya tetap ada - jejak auditnya
                                 justru yang perlu disimpan - tetapi tidak boleh
                                 terbaca sebagai putaran yang sah. --}}
                            @if($training->batal)
                                <span class="block mt-1 px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 text-center">
                                    BATAL
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-600">
                            @php
                                $hasil = $training->result ?? [];
                                // Nama kunci F1 berbeda per modul: sentimen memakai
                                // weighted_f1, aspek memakai f1. Dibaca berurutan
                                // supaya satu kolom melayani keduanya.
                                $f1Sebelum = $hasil['metrics_before']['weighted_f1'] ?? $hasil['metrics_before']['f1'] ?? null;
                                $f1Sesudah = $hasil['metrics_after']['weighted_f1'] ?? $hasil['metrics_after']['f1'] ?? null;
                                $delta = $training->f1_delta;
                                $kalibrasi = $training->calibration;
                            @endphp

                            @if($training->batal)
                                {{-- Angkanya sengaja TIDAK dicetak. Training #4
                                     dibatalkan bukan karena hasilnya jelek -
                                     aspek 27,14 -> 31,57, naik - melainkan karena
                                     titik berangkatnya checkpoint yang tercemar,
                                     sehingga tidak ada seorang pun yang bisa
                                     menghitung ulang angka itu. Menampilkannya
                                     apa adanya mengundangnya masuk naskah. --}}
                                <span class="text-red-700 font-semibold">Dibatalkan karena tidak dapat direproduksi.</span>
                                <span class="block text-xs text-red-600">
                                    {{ $training->alasan_batal ?? 'Angkanya tidak boleh dikutip; putaran ini perlu diulang.' }}
                                </span>
                            @elseif($training->status === 'failed')
                                <span class="text-red-600">{{ Str::limit($training->error_message, 80) }}</span>
                            @elseif($training->status === 'rejected')
                                <span class="text-amber-700">{{ Str::limit($training->error_message, 110) }}</span>
                            @elseif($f1Sebelum !== null && $f1Sesudah !== null)
                                <span class="text-xs">
                                    F1
                                    <span class="font-mono">{{ $f1Sebelum }}</span>
                                    &rarr;
                                    <span class="font-mono font-semibold text-green-700">{{ $f1Sesudah }}</span>
                                </span>

                                {{-- Delta selalu ditampilkan, termasuk ketika nol.
                                     Nol adalah tanda putaran yang tidak sah - itu
                                     yang terjadi pada putaran pertama, dan
                                     checkpoint tetap disimpan karena "tidak
                                     memburuk" secara teknis benar pada n=4. --}}
                                @if($delta !== null)
                                    <span class="block text-xs {{ $delta > 0 ? 'text-green-700' : ($delta < 0 ? 'text-red-700' : 'text-amber-700 font-semibold') }}">
                                        {{ sprintf('%+.4f', $delta) }}{{ $delta == 0.0 ? ' (pengukuran tidak membedakan apa pun)' : '' }}
                                    </span>
                                @endif

                                {{-- Titik berangkat. Membaca `trained_from_baseline`,
                                     BUKAN `baseline_swapped`: yang kedua hanya menyala
                                     ketika bobot ditukar, sehingga putaran yang memang
                                     berangkat dari base model karena checkpoint-nya
                                     belum ada tercatat false - kebalikan dari yang
                                     terjadi. Protokol kolam menuntut SELALU dari dasar,
                                     jadi yang tidak, ditandai. --}}
                                @if($training->dari_bobot_dasar === false)
                                    <span class="block text-xs text-amber-700">melanjutkan checkpoint sebelumnya, bukan dari base model</span>
                                @elseif($training->dari_bobot_dasar === true)
                                    <span class="block text-xs text-gray-400">berangkat dari base model</span>
                                @endif

                                {{-- Suhu kalibrasi. Diwarisi berarti keyakinan pada
                                     iterasi ini belum diukur ulang; angkanya datang
                                     dari bobot sebelumnya. --}}
                                @if($kalibrasi)
                                    <span class="block text-xs {{ $kalibrasi['inherited'] || $kalibrasi['unknown'] ? 'text-amber-700' : 'text-gray-400' }}">
                                        T = {{ rtrim(rtrim(number_format($kalibrasi['temperature'], 4, ',', ''), '0'), ',') }} ({{ $kalibrasi['label'] }})
                                    </span>
                                @endif

                                @isset($hasil['samples_skipped'])
                                    @if($hasil['samples_skipped'] > 0)
                                    <span class="block text-xs text-gray-400">{{ $hasil['samples_skipped'] }} sampel dilewati</span>
                                    @endif
                                @endisset
                            @elseif($training->result)
                                <span class="font-mono text-xs">{{ Str::limit(json_encode($training->result), 90) }}</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-gray-500">
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
            <h2 class="text-lg font-semibold text-gray-900">Perkembangan pada Data Koreksi</h2>
            <p class="mt-1 text-sm text-gray-600">
                Metrik direkam setiap kali retraining dipicu, sebagai pantauan proses koreksi.
            </p>

            {{-- Peringatan ini wajib ada di sini. Angka di bawah dihitung atas baris
                 yang persis sama dengan yang dikirim ke pelatihan, jadi kenaikannya
                 sebagian berasal dari model menghafal data training-nya sendiri. Tanpa
                 keterangan ini, grafiknya mudah dikira bukti perbaikan model. --}}
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Angka di bawah dihitung <strong>atas data koreksi yang ikut dilatihkan</strong>,
                jadi tidak boleh dikutip sebagai kinerja model, karena kenaikannya sebagian hanyalah
                model menghafal data training-nya sendiri. Kinerja model yang sah diukur di
                <a href="{{ route('admin.evaluation.index') }}" class="font-semibold underline">halaman Evaluasi Model</a>,
                atas golden dataset yang tidak pernah dilatihkan.
            </div>
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
                                {{ $snapshot->sentiment_accuracy !== null ? $snapshot->sentiment_accuracy . '%' : '-' }}
                            </td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ $snapshot->sentiment_weighted_f1 !== null ? $snapshot->sentiment_weighted_f1 . '%' : '-' }}
                            </td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ $snapshot->aspect_f1 !== null ? $snapshot->aspect_f1 . '%' : '-' }}
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
<script>
// Konfirmasi pelatihan ulang.
//
// Tiga kalimat berbeda, karena tiga keputusan yang berbeda beratnya. Satu
// konfirmasi umum "yakin?" untuk ketiganya sama saja dengan tidak ada
// konfirmasi - yang membacanya tidak tahu apa yang sedang disetujuinya.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('form-retrain');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const analisis = document.getElementById('retrain-analysis');
        const paksa = document.getElementById('retrain-paksa');

        if (analisis && analisis.value === 'gabungan') {
            // Bukan lagi soal mencampur korpus - kolamnya memang selalu utuh.
            // Yang hilang hanya kemampuan menamai iterasi ini di riwayat.
            const setuju = confirm(
                'Putaran ini akan tercatat TANPA lembaga asal.\n\n'
                + 'Data training-nya sama saja (seluruh kolam), tetapi riwayat tidak bisa '
                + 'menjawab "lembaga mana yang baru ditambahkan pada iterasi ini", '
                + 'dan itu yang dipakai untuk menamai iterasi di naskah.\n\n'
                + 'Lanjutkan tanpa lembaga asal?'
            );

            if (!setuju) {
                e.preventDefault();
                return;
            }
        }

        if (paksa && paksa.checked) {
            const setuju = confirm(
                'Mode PAKSA melewati penjaga kelayakan: pelatihan tetap berjalan walau '
                + 'sampelnya di bawah {{ $ambangLatih }}, atau walau iterasi sebelumnya belum diukur '
                + '(bobotnya akan tertimpa dan tidak bisa diukur belakangan).\n\n'
                + 'Lanjutkan dengan paksa?'
            );

            if (!setuju) {
                e.preventDefault();
                return;
            }
        }

        if (!confirm('Kirim data terkoreksi ke NLP API untuk melatih ulang model?')) {
            e.preventDefault();
        }
    });
});
</script>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('btn-preview-retrain');
    if (!button) return;

    const panel = document.getElementById('retrain-preview-panel');
    const body = document.getElementById('retrain-preview-body');

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function renderSentiment(report) {
        if (!report.available) {
            return '<p class="text-sm text-gray-500">' + escapeHtml(report.message) + '</p>';
        }

        const dist = report.label_distribution || {};
        const counts = dist.counts || {};
        const rows = Object.entries(counts).map(([label, count]) =>
            '<li class="flex justify-between"><span class="capitalize">' + escapeHtml(label)
            + '</span><span class="font-medium">' + count + '</span></li>'
        ).join('');

        const warnings = (report.warnings || []).map(w =>
            '<li class="text-amber-800">&bull; ' + escapeHtml(w) + '</li>'
        ).join('');

        return '<div>'
            + '<h3 class="font-semibold text-gray-900 mb-2">Sentimen</h3>'
            + '<div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">'
            + '<div><p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Distribusi label</p><ul class="space-y-0.5">' + rows + '</ul>'
            + '<p class="mt-1 text-xs text-gray-500">Rasio ketimpangan ' + (dist.imbalance_ratio ?? '-') + ':1</p></div>'
            + '<div><p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Pembagian data</p>'
            + '<p>Latih: <strong>' + (report.split?.train_size ?? '-') + '</strong></p>'
            + '<p>Validasi: <strong>' + (report.split?.val_size ?? '-') + '</strong></p>'
            + '<p class="text-xs text-gray-500 mt-1">Seed ' + (report.split?.seed ?? '-') + '</p></div>'
            + '<div><p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Ambang pembanding</p>'
            + '<p>Tebak kelas mayoritas: <strong>' + Math.round((report.majority_baseline_accuracy ?? 0) * 1000) / 10 + '%</strong></p>'
            + '<p class="text-xs text-gray-500 mt-1">Model hasil latih harus mengalahkan angka ini agar berarti.</p></div>'
            + '</div>'
            + (warnings ? '<ul class="mt-3 text-sm space-y-1">' + warnings + '</ul>' : '')
            + '</div>';
    }

    function renderAspect(report) {
        if (!report.available) {
            return '<p class="text-sm text-gray-500">' + escapeHtml(report.message) + '</p>';
        }

        const bio = report.bio_report || {};
        const warnings = (report.warnings || []).map(w =>
            '<li class="text-amber-800">&bull; ' + escapeHtml(w) + '</li>'
        ).join('');

        const rows = Object.entries(bio).filter(([, value]) => typeof value !== 'object').map(([key, value]) =>
            '<li class="flex justify-between gap-4"><span>' + escapeHtml(key.replace(/_/g, ' ')) + '</span>'
            + '<span class="font-medium">' + escapeHtml(String(value)) + '</span></li>'
        ).join('');

        return '<div class="pt-6 border-t border-gray-100">'
            + '<h3 class="font-semibold text-gray-900 mb-2">Aspek</h3>'
            + '<ul class="text-sm space-y-0.5 max-w-md">' + rows + '</ul>'
            + (warnings ? '<ul class="mt-3 text-sm space-y-1">' + warnings + '</ul>' : '')
            + '</div>';
    }

    button.addEventListener('click', function () {
        button.disabled = true;
        button.textContent = 'Memeriksa...';

        fetch('{{ route('admin.training.preview') }}', { headers: { 'Accept': 'application/json' } })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                panel.classList.remove('hidden');

                if (!ok || !data.success) {
                    body.innerHTML = '<p class="text-sm text-red-700">' + escapeHtml(data.message || 'Pemeriksaan gagal.') + '</p>';
                    return;
                }

                body.innerHTML = renderSentiment(data.reports.sentiment || {})
                               + renderAspect(data.reports.aspect || {});
            })
            .catch(() => {
                panel.classList.remove('hidden');
                body.innerHTML = '<p class="text-sm text-red-700">Tidak bisa menghubungi server.</p>';
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = 'Periksa Data Training';
            });
    });
});
</script>
@endpush