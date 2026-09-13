@extends('layouts.app')

@php
    $sentimen = $runTerakhir?->metrics['sentiment'] ?? null;
    $aspek = $runTerakhir?->metrics['aspect'] ?? null;
    $segmen = $runTerakhir?->metrics['segment'] ?? null;
    $topik = $runTerakhir?->metrics['topic'] ?? null;
    $reliabilitas = $runTerakhir?->metrics['reliability'] ?? null;
    $labelIndo = ['positive' => 'Positif', 'neutral' => 'Netral', 'negative' => 'Negatif'];
@endphp

@section('content')
<div class="space-y-6">

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <a href="{{ route('admin.evaluation.index') }}" class="text-xs text-gray-500 hover:text-gray-700">&larr; Semua golden dataset</a>
            <h1 class="mt-1 text-3xl font-bold text-gray-900">{{ $dataset->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-700">
                    {{ $dataset->split === 'test' ? 'Hold-out test' : 'Dev' }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-700">
                    {{ $dataset->item_count }} baris
                </span>
                @if($dataset->isFrozen())
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-emerald-100 text-emerald-800">
                        Beku sejak {{ $dataset->frozen_at->format('d M Y H:i') }}
                    </span>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium bg-amber-100 text-amber-800">
                        Belum dibekukan
                    </span>
                @endif
            </div>
            @if($dataset->description)
                <p class="mt-2 max-w-2xl text-sm text-gray-600">{{ $dataset->description }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if($dataset->isFrozen())
                <form action="{{ route('admin.evaluation.run', $dataset->id) }}" method="POST"
                      class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="label" placeholder="Nama iterasi"
                           class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                        <input type="checkbox" name="with_topic" value="1" checked
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        Ukur koherensi topik
                    </label>
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 shadow-md transition">
                        Jalankan Evaluasi
                    </button>
                </form>
                @unless($dataset->runs->count())
                    <form action="{{ route('admin.evaluation.unfreeze', $dataset->id) }}" method="POST">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                            Buka Pembekuan
                        </button>
                    </form>
                @endunless
            @else
                <form action="{{ route('admin.evaluation.freeze', $dataset->id) }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 shadow-md transition">
                        Bekukan Dataset
                    </button>
                </form>
                <form action="{{ route('admin.evaluation.destroy', $dataset->id) }}" method="POST"
                      onsubmit="return confirm('Hapus golden dataset ini beserta seluruh barisnya?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm text-rose-600 hover:bg-rose-50">
                        Hapus
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ session('error') }}</div>
    @endif

    @unless($dataset->isFrozen())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Dataset ini belum dibekukan, jadi evaluasi belum bisa dijalankan. Pembekuan mengunci isinya
            supaya dua evaluasi pada iterasi berbeda benar-benar mengukur baris yang sama - tanpa itu,
            selisih angkanya bisa berasal dari datanya yang berubah, bukan dari modelnya.
        </div>
    @endunless

    {{-- --------------------------------------------------------- anotasi --}}
    @php $belum = $dataset->label_distribution['belum_dilabeli'] ?? 0; @endphp

    @unless($dataset->isFrozen())
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Anotasi</h2>
                    @if($belum > 0)
                        <p class="mt-1 text-sm text-gray-600">
                            <strong>{{ $dataset->item_count - $belum }}</strong> dari
                            {{ $dataset->item_count }} baris sudah berlabel;
                            <strong>{{ $belum }}</strong> belum.
                        </p>
                        <div class="mt-2 h-2 w-64 overflow-hidden rounded-full bg-gray-200">
                            <div class="h-full rounded-full bg-indigo-600"
                                 style="width: {{ $dataset->item_count > 0 ? round(($dataset->item_count - $belum) / $dataset->item_count * 100) : 0 }}%"></div>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-emerald-700">
                            Semua {{ $dataset->item_count }} baris sudah berlabel. Dataset siap dibekukan.
                        </p>
                    @endif

                    @if($aspekBelumDitinjau > 0)
                        <p class="mt-2 text-sm text-amber-700">
                            <strong>{{ $aspekBelumDitinjau }}</strong> baris belum ditinjau aspeknya, jadi
                            metrik ekstraksi aspek belum akan dihitung. Baris yang memang tidak punya aspek
                            tetap harus ditandai &mdash; kalau tidak, aspek yang dikarang model di sana tidak
                            terhitung sebagai kesalahan.
                        </p>
                    @else
                        <p class="mt-2 text-sm text-emerald-700">
                            Seluruh baris sudah ditinjau aspeknya; metrik ekstraksi aspek akan ikut dihitung.
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.evaluation.annotation', $dataset->id) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Unduh Berkas Anotasi
                    </a>

                    <a href="{{ route('admin.evaluation.annotation', ['id' => $dataset->id, 'prediksi' => 1]) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Unduh + Prediksi Model
                    </a>

                    <form action="{{ route('admin.evaluation.annotation.upload', $dataset->id) }}"
                          method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                        @csrf
                        <input type="file" name="file" required accept=".csv,.txt,.xlsx,.xls"
                               class="text-sm text-gray-700 file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700">
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg text-sm font-medium hover:bg-gray-800">
                            Unggah Anotasi
                        </button>
                    </form>
                </div>
            </div>

            <p class="mt-3 text-xs text-gray-500">
                Jangan menyunting kolom <code>id</code> - kolom itu yang memasangkan baris kembali;
                teks yang tersunting masih dikenali lewat sidik jari, tetapi id lebih pasti.
            </p>

            <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                <p class="text-sm font-semibold text-indigo-900">Aplikasi anotasi</p>
                <p class="mt-1 text-xs text-indigo-900">
                    Buka <a href="{{ url('/anotasi') }}" target="_blank" class="font-semibold underline">{{ url('/anotasi') }}</a>,
                    unggah berkas di atas, lalu anotasi satu per satu dengan pintasan papan tik.
                    Berkasnya satu halaman statis tanpa backend - salin folder <code>public/anotasi</code>
                    ke hosting mana pun, atau kirim berkasnya langsung ke anotator. Pekerjaan tersimpan
                    otomatis di peramban dan tidak dikirim ke mana pun.
                </p>
                <p class="mt-2 text-xs text-indigo-900">
                    Versi <strong>+ Prediksi Model</strong> menyertakan tebakan model, tetapi aplikasi
                    menyembunyikannya sampai anotator memilih labelnya sendiri - lalu mencatat berapa
                    label yang berubah setelah melihatnya. Tanpa penundaan itu, anotator cenderung
                    menyetujui model dan akurasi terukur naik tanpa modelnya berubah.
                </p>
            </div>

            @if($belum > 0)
                <form action="{{ route('admin.evaluation.drop-unlabeled', $dataset->id) }}" method="POST"
                      class="mt-3"
                      onsubmit="return confirm('Buang {{ $belum }} baris yang tidak berhasil dianotasi? Jumlahnya dicatat untuk dilaporkan di naskah.')">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50">
                        Buang {{ $belum }} baris tanpa label
                    </button>
                    <span class="ml-2 text-xs text-gray-500">
                        Untuk baris yang anotator tandai "tidak jelas". Dataset tidak bisa dibekukan
                        selama masih ada baris tanpa label.
                    </span>
                </form>
            @endif
        </div>
    @endunless

    @if($dataset->sampling_plan)
        @php $rencana = $dataset->sampling_plan; @endphp
        <details class="rounded-2xl border border-gray-200 bg-white p-5">
            <summary class="cursor-pointer text-sm font-semibold text-gray-900">
                Rencana pengambilan sampel
                <span class="ml-1 font-normal text-gray-500">- salin ke bab metodologi</span>
            </summary>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Metode</dt><dd class="text-gray-900">{{ $rencana['method'] ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Semai</dt><dd class="text-gray-900">{{ $rencana['seed'] ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Stratifikasi</dt><dd class="text-gray-900">{{ $rencana['stratify_by'] ?? 'per analisis' }}</dd></div>
                <div><dt class="text-gray-500">Kandidat setelah penyaringan</dt><dd class="text-gray-900">{{ $rencana['candidates'] ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Diminta / terambil</dt><dd class="text-gray-900">{{ $rencana['requested_size'] ?? '-' }} / {{ $rencana['sampled'] ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Panjang minimal</dt><dd class="text-gray-900">{{ $rencana['min_length'] ?? '-' }} karakter</dd></div>
                @isset($rencana['dropped_unlabeled'])
                    <div>
                        <dt class="text-gray-500">Dibuang karena tak berhasil dianotasi</dt>
                        <dd class="text-gray-900">{{ $rencana['dropped_unlabeled'] }} baris</dd>
                    </div>
                @endisset
                <div class="sm:col-span-2">
                    <dt class="text-gray-500">Korpus sumber</dt>
                    <dd class="text-gray-900">{{ implode('; ', $rencana['analysis_titles'] ?? []) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-gray-500">Ditolak</dt>
                    <dd class="text-gray-900">
                        tidak bisa dinilai {{ $rencana['rejected']['tidak_dinilai'] ?? 0 }},
                        terlalu pendek {{ $rencana['rejected']['terlalu_pendek'] ?? 0 }},
                        duplikat {{ $rencana['rejected']['duplikat'] ?? 0 }},
                        sudah di golden lain {{ $rencana['rejected']['sudah_di_golden'] ?? 0 }},
                        sudah dikoreksi {{ $rencana['rejected']['sudah_dikoreksi'] ?? 0 }}
                    </dd>
                </div>
            </dl>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 text-left">Strata</th>
                            <th class="py-2 text-right">Tersedia</th>
                            <th class="py-2 text-right">Diambil</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rencana['strata'] ?? [] as $nama => $angka)
                            <tr>
                                <td class="py-2 text-gray-700">{{ $nama }}</td>
                                <td class="py-2 text-right text-gray-500">{{ $angka['tersedia'] }}</td>
                                <td class="py-2 text-right text-gray-900">{{ $angka['diambil'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    {{-- ------------------------------------------------ komposisi & reliabilitas --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-900">Komposisi</h2>
            <dl class="mt-3 space-y-2 text-sm">
                @foreach(['positive', 'neutral', 'negative'] as $label)
                    @php $jumlah = $dataset->label_distribution[$label] ?? 0; @endphp
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-600">{{ $labelIndo[$label] }}</dt>
                        <dd class="font-medium text-gray-900">
                            {{ $jumlah }}
                            <span class="text-xs font-normal text-gray-500">
                                ({{ $dataset->item_count > 0 ? round($jumlah / $dataset->item_count * 100, 1) : 0 }}%)
                            </span>
                        </dd>
                    </div>
                @endforeach
                <div class="flex items-center justify-between border-t border-gray-100 pt-2">
                    <dt class="text-gray-600">Punya label aspek</dt>
                    <dd class="font-medium text-gray-900">{{ $dataset->label_distribution['dengan_aspek'] ?? 0 }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-gray-600">Jumlah segmen</dt>
                    <dd class="font-medium text-gray-900">{{ $dataset->label_distribution['segmen'] ?? 0 }}</dd>
                </div>
            </dl>

            @if($dataset->content_hash)
                <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
                    Sidik jari isi <code>{{ Str::limit($dataset->content_hash, 16, '') }}&hellip;</code> -
                    membuktikan tiap iterasi mengukur baris yang sama, tanpa perlu mempercayai aplikasinya.
                </p>
            @endif

            @php
                $rencanaRingkas = $dataset->sampling_plan ?? [];
                $ditolakTotal = array_sum($rencanaRingkas['rejected'] ?? []);
                $kandidatAwal = ($rencanaRingkas['candidates'] ?? 0) + $ditolakTotal;
            @endphp

            @isset($rencanaRingkas['min_length'])
                <p class="mt-2 text-xs text-gray-500">
                    Populasi yang diukur: <strong>komentar unik &ge; {{ $rencanaRingkas['min_length'] }} karakter</strong>,
                    bukan seluruh korpus
                    ({{ $ditolakTotal }} dari {{ $kandidatAwal }} baris tersaring). Arah biasnya <strong>ke atas</strong>:
                    komentar pendek justru tempat model paling lemah.
                </p>
            @endisset
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-900">Kappa antar-anotator</h2>
            @if($dataset->annotation)
                <p class="mt-3 text-3xl font-bold text-gray-900">{{ number_format($dataset->annotation['cohen_kappa'], 3) }}</p>
                <p class="text-xs text-gray-500">
                    n = {{ $dataset->annotation['double_annotated'] }} - {{ $dataset->annotation['label'] }}
                </p>
                <p class="mt-3 text-sm text-gray-600">
                    Sepakat {{ $dataset->annotation['agreement'] }}% secara mentah.
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    Ini <strong>batas atas</strong> kinerja yang masuk akal. Berbeda dari &ldquo;kappa model&rdquo;
                    di bawah, yang mengukur kesepakatan model dengan label emas.
                </p>
            @else
                <p class="mt-3 text-sm text-gray-600">
                    Tidak ada anotator kedua pada dataset ini.
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    Tanpa angka kesepakatan, label emas hanyalah pendapat satu orang dan batas atas
                    kinerja model tidak diketahui. Tambahkan kolom <code>label_2</code> bila sebagian
                    baris bisa dianotasi ulang.
                </p>
            @endif
        </div>

        <div class="rounded-2xl border {{ $leakage['overlap'] > 0 ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }} p-5">
            <h2 class="text-sm font-semibold text-gray-900">Kebocoran ke data latih</h2>
            @if($leakage['overlap'] === 0)
                <p class="mt-3 text-3xl font-bold text-emerald-700">0</p>
                <p class="text-xs text-gray-500">baris bertabrakan</p>
                <p class="mt-3 text-sm text-gray-600">
                    Tidak satu pun baris uji pernah masuk data latih. Angka hold-out sah dikutip.
                </p>
            @else
                <p class="mt-3 text-3xl font-bold text-amber-700">{{ $leakage['overlap'] }}</p>
                <p class="text-xs text-gray-600">baris bertabrakan, {{ $leakage['corrected_overlap'] }} sudah dikoreksi</p>
                <p class="mt-3 text-sm text-gray-700">
                    Baris ini otomatis disingkirkan sebelum pelatihan, jadi hold-out tetap sah.
                </p>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------ hasil run terakhir --}}
    @if($sentimen && ($sentimen['available'] ?? false))
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Hasil terakhir - {{ $runTerakhir->judul() }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Dijalankan {{ $runTerakhir->completed_at?->format('d M Y H:i') }} atas {{ $sentimen['n'] }} baris.
                        Model: <code>{{ $runTerakhir->model_info['sentiment_source'] ?? 'tidak tercatat' }}</code>,
                        checkpoint hasil pelatihan ulang
                        {{ ($runTerakhir->model_info['sentiment_retrained'] ?? false) ? 'dimuat' : 'tidak dimuat' }}.
                    </p>
                </div>
                <a href="{{ route('admin.evaluation.errors', $runTerakhir->id) }}"
                   class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50">
                    Unduh baris yang salah dinilai
                </a>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500">Akurasi</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $sentimen['accuracy'] }}%</p>
                    <p class="text-xs text-gray-500">
                        95% CI {{ $sentimen['accuracy_ci']['low'] }}-{{ $sentimen['accuracy_ci']['high'] }}
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500">Macro-F1</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $sentimen['macro_f1'] }}%</p>
                    <p class="text-xs text-gray-500">
                        95% CI {{ $sentimen['macro_f1_ci']['low'] }}-{{ $sentimen['macro_f1_ci']['high'] }}
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500">Cohen kappa</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($sentimen['cohen_kappa'], 3) }}</p>
                    <p class="text-xs text-gray-500">kesepakatan di atas kebetulan</p>
                </div>
                <div class="rounded-xl border {{ $sentimen['accuracy'] > $sentimen['majority_baseline']['accuracy'] ? 'border-gray-200' : 'border-rose-200 bg-rose-50' }} p-4">
                    <p class="text-xs text-gray-500">Tebak kelas mayoritas</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $sentimen['majority_baseline']['accuracy'] }}%</p>
                    <p class="text-xs text-gray-500">
                        lantai pembanding ({{ $labelIndo[$sentimen['majority_baseline']['label']] ?? '-' }})
                    </p>
                </div>
            </div>

            @if($sentimen['accuracy'] <= $sentimen['majority_baseline']['accuracy'])
                <p class="mt-3 rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-sm text-rose-900">
                    Akurasi model tidak melampaui tebak-kelas-mayoritas. Angka ini belum membuktikan
                    model berguna pada korpus tersebut.
                </p>
            @endif

            @if(($sentimen['unscorable'] ?? 0) > 0)
                <p class="mt-3 text-xs text-amber-700">
                    {{ $sentimen['unscorable'] }} baris tidak bisa dinilai (model tidak mengembalikan label sah).
                    Baris itu tidak ikut dalam metrik di atas.
                </p>
            @endif

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Per kelas</h3>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="py-2 text-left">Kelas</th>
                                    <th class="py-2 text-right">Presisi</th>
                                    <th class="py-2 text-right">Recall</th>
                                    <th class="py-2 text-right">F1</th>
                                    <th class="py-2 text-right">Support</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($sentimen['per_class'] as $label => $skor)
                                    <tr>
                                        <td class="py-2 text-gray-700">{{ $labelIndo[$label] ?? $label }}</td>
                                        <td class="py-2 text-right text-gray-900">{{ $skor['precision'] }}%</td>
                                        <td class="py-2 text-right text-gray-900">{{ $skor['recall'] }}%</td>
                                        <td class="py-2 text-right font-medium text-gray-900">{{ $skor['f1'] }}%</td>
                                        <td class="py-2 text-right text-gray-500">{{ $skor['support'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Matriks kebingungan
                    </h3>
                    <p class="mt-1 text-xs text-gray-500">Baris = label emas, kolom = prediksi model.</p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="py-2 text-left">Emas \ Prediksi</th>
                                    @foreach($sentimen['labels'] as $label)
                                        <th class="py-2 text-right">{{ $labelIndo[$label] ?? $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($sentimen['labels'] as $emas)
                                    <tr>
                                        <td class="py-2 font-medium text-gray-700">{{ $labelIndo[$emas] ?? $emas }}</td>
                                        @foreach($sentimen['labels'] as $prediksi)
                                            <td class="py-2 text-right {{ $emas === $prediksi ? 'font-semibold text-emerald-700' : 'text-gray-600' }}">
                                                {{ $sentimen['confusion_matrix'][$emas][$prediksi] ?? 0 }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @if($reliabilitas && ($reliabilitas['available'] ?? false))
                <div class="mt-6 border-t border-gray-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Reliabilitas keyakinan
                    </h3>
                    <p class="mt-1 max-w-3xl text-xs text-gray-500">
                        Ambang tinjauan menyatakan &ldquo;tinjau bila perkiraan salahnya di atas sekian&rdquo;.
                        Klaim itu hanya sah bila keyakinan model memang menaksir peluang benarnya &mdash; tabel
                        ini mengujinya <strong>pada golden dataset Anda sendiri</strong>, bukan pada set uji
                        milik layanan. Selisih negatif berarti model terlalu percaya diri di pita itu, dan
                        itulah alasan barisnya perlu dilihat manusia.
                    </p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="py-2 text-left">Rentang keyakinan</th>
                                    <th class="py-2 text-right">n</th>
                                    <th class="py-2 text-right">Porsi</th>
                                    <th class="py-2 text-right">Akurasi nyata</th>
                                    <th class="py-2 text-right">Rerata keyakinan</th>
                                    <th class="py-2 text-right">Selisih</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($reliabilitas['bins'] as $pita)
                                    <tr class="{{ $pita['lower'] >= ($confidenceBounds['tinggi'] ?? 1) ? 'bg-emerald-50' : '' }}">
                                        <td class="py-2 text-gray-700">{{ $pita['range'] }}</td>
                                        <td class="py-2 text-right text-gray-500">{{ $pita['n'] }}</td>
                                        <td class="py-2 text-right text-gray-500">{{ $pita['share'] }}%</td>
                                        <td class="py-2 text-right font-medium text-gray-900">
                                            {{ $pita['accuracy'] === null ? '-' : $pita['accuracy'].'%' }}
                                        </td>
                                        <td class="py-2 text-right text-gray-600">
                                            {{ $pita['confidence'] === null ? '-' : $pita['confidence'].'%' }}
                                        </td>
                                        <td class="py-2 text-right {{ ($pita['gap'] ?? 0) < -5 ? 'text-rose-700 font-medium' : 'text-gray-500' }}">
                                            {{ $pita['gap'] === null ? '-' : ($pita['gap'] > 0 ? '+' : '').$pita['gap'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-2 text-xs text-gray-500">
                        ECE {{ $reliabilitas['ece'] }} atas {{ $reliabilitas['n'] }} baris
                        &mdash; rerata terbobot selisih akurasi dan keyakinan. Nilainya bergantung pada
                        pembagian pita, jadi bandingkan hanya dengan pembagian yang sama. Baris berlatar hijau
                        adalah pita yang <strong>tidak</strong> ditinjau pada ambang yang berlaku sekarang.
                    </p>
                </div>
            @endif

            @if($aspek)
                <div class="mt-6 border-t border-gray-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Aspek (multi-label)</h3>
                    @if($aspek['available'] ?? false)
                        @php
                            $aspekParsial = $aspek['partial'] ?? [];
                            $aspekSering = $aspek['macro_frequent'] ?? [];
                            $jenisAspek = count($aspek['per_aspect'] ?? []);
                            $aspekJarang = $jenisAspek - (int) ($aspekSering['aspects'] ?? $jenisAspek);
                        @endphp

                        {{-- Keempat angkanya ditampilkan bersama, bukan hanya yang ketat.
                             Ketat dan parsial berbeda pada cara mencocokkan nama; macro
                             penuh dan macro bersyarat support berbeda pada aspek mana yang
                             ikut dirata-ratakan. Menampilkan satu saja menyembunyikan
                             pertanyaan yang justru akan ditanyakan penguji. --}}
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-xs uppercase text-gray-500">
                                    <tr>
                                        <th class="py-2 text-left">Pencocokan</th>
                                        <th class="py-2 text-right">Micro-F1</th>
                                        <th class="py-2 text-right">Macro-F1</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr>
                                        <td class="py-2 text-gray-700">
                                            Ketat
                                            <span class="block text-xs text-gray-400">nama aspek sama persis setelah dinormalkan</span>
                                        </td>
                                        <td class="py-2 text-right font-semibold text-gray-900">{{ $aspek['micro_f1'] }}%</td>
                                        <td class="py-2 text-right font-semibold text-gray-900">{{ $aspek['macro_f1'] }}%</td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 text-gray-700">
                                            Parsial
                                            <span class="block text-xs text-gray-400">ketemu bila ada kata-dasar yang sama</span>
                                        </td>
                                        <td class="py-2 text-right font-semibold text-gray-900">
                                            {{ isset($aspekParsial['micro_f1']) ? $aspekParsial['micro_f1'].'%' : '-' }}
                                        </td>
                                        <td class="py-2 text-right text-gray-600">
                                            {{ isset($aspekParsial['macro_f1']) ? $aspekParsial['macro_f1'].'%' : '-' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 text-gray-700">
                                            Ketat, aspek support &ge; {{ $aspekSering['min_support'] ?? 2 }}
                                            <span class="block text-xs text-gray-400">
                                                {{ $aspekSering['aspects'] ?? 0 }} dari {{ $jenisAspek }} jenis aspek
                                            </span>
                                        </td>
                                        <td class="py-2 text-right text-gray-400">&mdash;</td>
                                        <td class="py-2 text-right font-semibold text-gray-900">
                                            {{ isset($aspekSering['macro_f1']) ? $aspekSering['macro_f1'].'%' : '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div><p class="text-xs text-gray-500">Exact match</p><p class="text-lg font-bold text-gray-900">{{ $aspek['exact_match'] }}%</p></div>
                            <div><p class="text-xs text-gray-500">Hamming loss</p><p class="text-lg font-bold text-gray-900">{{ $aspek['hamming_loss'] }}</p></div>
                            <div><p class="text-xs text-gray-500">TP / FP / FN</p><p class="text-lg font-bold text-gray-900">{{ $aspek['tp'] }} / {{ $aspek['fp'] }} / {{ $aspek['fn'] }}</p></div>
                        </div>

                        @if($aspekJarang > 0)
                            <p class="mt-3 text-xs text-gray-500">
                                Macro penuh merata-ratakan <strong>{{ $jenisAspek }} jenis aspek</strong>,
                                <strong>{{ $aspekJarang }}</strong> di antaranya muncul sekali saja &mdash; skornya
                                hanya bisa 0 atau 1 dan tidak bermakna statistik. Baris ketiga mengeluarkannya.
                                Laporkan <strong>keduanya</strong> di naskah: yang bersyarat support melengkapi,
                                bukan menggantikan.
                            </p>
                        @endif

                        <p class="mt-2 text-xs text-gray-500">
                            Pencocokan <strong>parsial</strong> melonggarkan hukuman granularitas frasa
                            (<code>dewan wakil rakyat</code> versus <code>dewan</code> + <code>rakyat</code>),
                            yang bukan kesalahan model. Angka <strong>ketat tetap batas bawahnya</strong>,
                            supaya pelonggaran itu tidak menyembunyikan apa pun.
                        </p>
                        <p class="mt-2 text-xs text-gray-500">
                            Kecocokan dinilai pada {{ $aspek['match'] ?? 'bentuk dasar' }}. Bahasa Indonesia aglutinatif:
                            membandingkan <code>pelayanannya</code> dengan <code>pelayanan</code> sebagai string persis
                            menghitung pasangan yang benar dua kali salah - sekali sebagai false positive, sekali
                            sebagai false negative.
                        </p>
                        <p class="mt-1 text-xs text-amber-700">
                            Angka ini mengukur <strong>ekstraksi aspek</strong>, bukan polaritas per aspek. Sentimen
                            per aspek dihasilkan pasca-pemrosesan di sisi layanan yang belum pernah diukur harness
                            mana pun, jadi akurasi sentimen tingkat dokumen juga tidak berlaku untuk angka per aspek
                            di halaman hasil.
                        </p>
                    @else
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ $aspek['message'] ?? 'Tidak dihitung.' }}
                        </p>
                    @endif
                </div>
            @endif

            @if($topik)
                <div class="mt-6 border-t border-gray-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pemodelan topik</h3>

                    @if($topik['available'] ?? false)
                        @php $pita = \App\Helpers\ResultPresenter::coherenceBand((float) $topik['c_v']); @endphp
                        <p class="mt-1 max-w-3xl text-xs text-gray-500">
                            Diukur pada <strong>{{ $topik['source'] }}</strong>
                            ({{ number_format($topik['corpus_texts']) }} teks), bukan pada test set. Golden dataset
                            adalah sampel acak: kalimat pembentuk satu topik sebagian besar tidak ikut tersampel, dan
                            pada beberapa ratus dokumen pendek matriks ko-okurensinya jarang sehingga koherensi
                            tertekan. Koherensi juga <strong>tidak memakai label emas</strong> - jangan
                            disandingkan dengan F1 seolah setara, dan tidak sebanding antar korpus. Model topik tidak
                            ikut dilatih ulang oleh active learning, jadi angka ini berperan sebagai kontrol.
                        </p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="rounded-lg border p-3 {{ $pita['class'] }}">
                                <p class="text-xs font-medium uppercase tracking-wide opacity-80">Koherensi c<sub>v</sub></p>
                                <p class="mt-1 text-2xl font-bold">{{ number_format($topik['c_v'], 4) }}</p>
                                <p class="mt-0.5 text-xs">{{ $pita['label'] }}</p>
                            </div>
                            <div><p class="text-xs text-gray-500">c<sub>npmi</sub></p><p class="text-lg font-bold text-gray-900">{{ $topik['c_npmi'] ?? '-' }}</p></div>
                            <div><p class="text-xs text-gray-500">Diversity</p><p class="text-lg font-bold text-gray-900">{{ $topik['diversity'] ?? '-' }}</p></div>
                            <div><p class="text-xs text-gray-500">Outlier rate</p><p class="text-lg font-bold text-gray-900">{{ $topik['outlier_rate'] ?? '-' }}</p></div>
                            <div>
                                <p class="text-xs text-gray-500">Jumlah topik</p>
                                <p class="text-lg font-bold text-gray-900">{{ $topik['topic_count'] }}</p>
                                <p class="text-xs text-amber-700">bergantung seed</p>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">
                            <strong>Jumlah topik tidak stabil terhadap seed, koherensinya stabil.</strong> Terukur pada
                            lima seed: c<sub>v</sub> 0,5023 &plusmn; 0,0221, tetapi jumlah topik 13,6 &plusmn; 4,98
                            (rentang 6-19). Sebutkan seed-nya dan sertakan rentang antar-seed setiap kali menulis
                            jumlah topik di naskah.
                            @if(($topik['c_npmi'] ?? 0) < 0)
                                c<sub>npmi</sub> yang negatif sementara c<sub>v</sub> positif juga wajar - itu
                                kritik klasik terhadap c<sub>v</sub>, yang konfirmasinya tak-langsung lewat kosinus
                                antar-vektor NPMI; siapkan satu paragraf penjelasan.
                            @endif
                        </p>
                    @else
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {{ $topik['message'] ?? 'Tidak dicatat.' }}
                        </p>
                    @endif
                </div>
            @endif

            @if($segmen)
                <div class="mt-6 border-t border-gray-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Per segmen</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Rerata keseluruhan bisa menyembunyikan satu segmen yang jatuh. Segmen dengan
                        n kecil punya selang lebar - jangan menyimpulkan perbedaan dari selisih
                        beberapa poin saja.
                    </p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="py-2 text-left">Segmen</th>
                                    <th class="py-2 text-right">n</th>
                                    <th class="py-2 text-right">Akurasi</th>
                                    <th class="py-2 text-right">Macro-F1</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($segmen as $nama => $skor)
                                    <tr>
                                        <td class="py-2 text-gray-700">{{ $nama }}</td>
                                        <td class="py-2 text-right text-gray-500">{{ $skor['n'] }}</td>
                                        <td class="py-2 text-right text-gray-900">{{ $skor['accuracy'] }}%</td>
                                        <td class="py-2 text-right text-gray-900">{{ $skor['macro_f1'] }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- --------------------------------------------------------- kurva iterasi --}}
    @if(count($kurva) >= 1)
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-900">Perkembangan antar iterasi active learning</h2>
            <p class="mt-0.5 text-xs text-gray-500">
                Setiap titik adalah satu evaluasi atas dataset beku yang sama, jadi selisihnya berasal
                dari modelnya.
            </p>

            @if(count($kurva) >= 2)
                <div class="mt-4" style="height: 260px;">
                    <canvas id="kurvaEvaluasi"
                            role="img"
                            aria-label="Grafik akurasi dan macro-F1 per iterasi evaluasi hold-out"></canvas>
                </div>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 text-left">Iterasi</th>
                            <th class="py-2 text-right">Akurasi</th>
                            <th class="py-2 text-right">Macro-F1</th>
                            <th class="py-2 text-right">Weighted-F1</th>
                            <th class="py-2 text-right">Kappa</th>
                            <th class="py-2 text-right">Baseline</th>
                            <th class="py-2 text-left">Dijalankan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($kurva as $titik)
                            <tr>
                                <td class="py-2 text-gray-700">{{ $titik['label'] }}</td>
                                <td class="py-2 text-right text-gray-900">{{ $titik['accuracy'] }}%</td>
                                <td class="py-2 text-right font-medium text-gray-900">{{ $titik['macro_f1'] }}%</td>
                                <td class="py-2 text-right text-gray-600">{{ $titik['weighted_f1'] }}%</td>
                                <td class="py-2 text-right text-gray-600">{{ number_format((float) $titik['cohen_kappa'], 3) }}</td>
                                <td class="py-2 text-right text-gray-400">{{ $titik['majority_baseline'] }}%</td>
                                <td class="py-2 text-gray-500">{{ $titik['completed_at'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------ perbandingan --}}
    @if($runSelesai->count() >= 2)
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Uji signifikansi antar dua iterasi</h2>
                    <p class="mt-0.5 max-w-2xl text-xs text-gray-500">
                        Selisih akurasi saja tidak cukup untuk mengklaim model membaik. Uji McNemar
                        bekerja pada prediksi berpasangan per baris - berapa baris yang berubah
                        dari salah menjadi benar, dan sebaliknya.
                    </p>
                    <p class="mt-1 max-w-2xl text-xs text-amber-700">
                        Uji utama yang dilaporkan di naskah adalah <strong>iterasi pertama lawan iterasi
                        terakhir</strong>. Perbandingan pasangan lain di sini bersifat deskriptif: menguji setiap
                        pasangan iterasi adalah pengujian berganda, dan dengan empat uji pada &alpha; 0,05 peluang
                        setidaknya satu &ldquo;signifikan&rdquo; palsu naik ke sekitar 19%.
                    </p>
                </div>

                <form method="GET" class="flex items-end gap-2">
                    <div>
                        <label for="before" class="block text-xs text-gray-600">Sebelum</label>
                        <select name="before" id="before" class="mt-1 rounded-lg border-gray-300 text-sm shadow-sm">
                            @foreach($runSelesai as $run)
                                <option value="{{ $run->id }}" @selected($run->id === $sebelumId)>{{ $run->judul() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="after" class="block text-xs text-gray-600">Sesudah</label>
                        <select name="after" id="after" class="mt-1 rounded-lg border-gray-300 text-sm shadow-sm">
                            @foreach($runSelesai as $run)
                                <option value="{{ $run->id }}" @selected($run->id === $sesudahId)>{{ $run->judul() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-3 py-2 bg-gray-900 text-white rounded-lg text-sm font-medium hover:bg-gray-800">
                        Bandingkan
                    </button>
                </form>
            </div>

            @if($perbandingan && ! ($perbandingan['valid'] ?? false))
                <p class="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
                    {{ $perbandingan['message'] }}
                </p>
            @elseif($perbandingan)
                @php $mc = $perbandingan['mcnemar']; @endphp

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="py-2 text-left">Metrik</th>
                                    <th class="py-2 text-right">{{ $perbandingan['before']['label'] }}</th>
                                    <th class="py-2 text-right">{{ $perbandingan['after']['label'] }}</th>
                                    <th class="py-2 text-right">Selisih</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach([
                                    'Akurasi' => 'accuracy',
                                    'Macro-F1' => 'macro_f1',
                                    'Weighted-F1' => 'weighted_f1',
                                ] as $judul => $kunci)
                                    @php $delta = $perbandingan['deltas'][$kunci]; @endphp
                                    <tr>
                                        <td class="py-2 text-gray-700">{{ $judul }}</td>
                                        <td class="py-2 text-right text-gray-600">{{ $perbandingan['before'][$kunci] }}%</td>
                                        <td class="py-2 text-right text-gray-900">{{ $perbandingan['after'][$kunci] }}%</td>
                                        <td class="py-2 text-right font-medium {{ $delta > 0 ? 'text-emerald-700' : ($delta < 0 ? 'text-rose-700' : 'text-gray-500') }}">
                                            {{ $delta === null ? '-' : ($delta > 0 ? '+' : '').number_format($delta, 2).' poin' }}
                                        </td>
                                    </tr>
                                @endforeach
                                @php $dCv = $perbandingan['deltas']['coherence']; @endphp
                                @if($dCv !== null)
                                    <tr>
                                        <td class="py-2 text-gray-700">
                                            Koherensi c<sub>v</sub>
                                            <span class="block text-xs text-gray-400">kontrol - model topik tidak dilatih ulang</span>
                                        </td>
                                        <td class="py-2 text-right text-gray-600">{{ $perbandingan['before']['coherence'] }}</td>
                                        <td class="py-2 text-right text-gray-900">{{ $perbandingan['after']['coherence'] }}</td>
                                        <td class="py-2 text-right font-medium text-gray-500">
                                            {{ ($dCv > 0 ? '+' : '').number_format($dCv, 4) }}
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-xl border {{ $mc['significant'] ? 'border-emerald-200 bg-emerald-50' : 'border-gray-200 bg-gray-50' }} p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Uji McNemar</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">
                            p = {{ $mc['p_value'] < 0.001 ? '<0,001' : number_format($mc['p_value'], 3) }}
                        </p>
                        <p class="text-xs text-gray-500">{{ $mc['method'] }}</p>
                        <dl class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Berubah jadi benar</dt>
                                <dd class="font-medium text-emerald-700">{{ $mc['c'] }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600">Berubah jadi salah</dt>
                                <dd class="font-medium text-rose-700">{{ $mc['b'] }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-gray-700">{{ $mc['interpretation'] }}</p>
                        @if(! $mc['significant'] && ($mc['power']['min_c'] ?? null) !== null)
                            <p class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500">
                                Daya uji: dengan {{ $mc['b'] }} baris memburuk, perubahan baru terdeteksi bila
                                minimal <strong>{{ $mc['power']['min_c'] }}</strong> baris membaik.
                            </p>
                        @endif
                    </div>
                </div>

                @if($perbandingan['flips']['improved'] || $perbandingan['flips']['regressed'])
                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                Membaik ({{ $perbandingan['flips']['improved_count'] }})
                            </h3>
                            <ul class="mt-2 space-y-2">
                                @forelse($perbandingan['flips']['improved'] as $baris)
                                    <li class="rounded-lg border border-gray-200 p-2 text-xs">
                                        <p class="text-gray-700">{{ $baris['text'] }}</p>
                                        <p class="mt-1 text-gray-500">
                                            emas <strong>{{ $labelIndo[$baris['gold']] ?? $baris['gold'] }}</strong>,
                                            dulu {{ $labelIndo[$baris['before']] ?? ($baris['before'] ?? '-') }}
                                            &rarr; kini {{ $labelIndo[$baris['after']] ?? ($baris['after'] ?? '-') }}
                                        </p>
                                    </li>
                                @empty
                                    <li class="text-xs text-gray-500">Tidak ada.</li>
                                @endforelse
                            </ul>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-rose-700">
                                Memburuk ({{ $perbandingan['flips']['regressed_count'] }})
                            </h3>
                            <ul class="mt-2 space-y-2">
                                @forelse($perbandingan['flips']['regressed'] as $baris)
                                    <li class="rounded-lg border border-gray-200 p-2 text-xs">
                                        <p class="text-gray-700">{{ $baris['text'] }}</p>
                                        <p class="mt-1 text-gray-500">
                                            emas <strong>{{ $labelIndo[$baris['gold']] ?? $baris['gold'] }}</strong>,
                                            dulu {{ $labelIndo[$baris['before']] ?? ($baris['before'] ?? '-') }}
                                            &rarr; kini {{ $labelIndo[$baris['after']] ?? ($baris['after'] ?? '-') }}
                                        </p>
                                    </li>
                                @empty
                                    <li class="text-xs text-gray-500">Tidak ada.</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    @endif

    {{-- --------------------------------------------------------- riwayat run --}}
    <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm font-semibold text-gray-900">Riwayat evaluasi</h2>
        </div>
        @if($dataset->runs->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-gray-600">Belum ada evaluasi yang dijalankan.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-5 py-3 text-left">Run</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-right">Akurasi</th>
                            <th class="px-5 py-3 text-right">Macro-F1</th>
                            <th class="px-5 py-3 text-left">Selesai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($dataset->runs as $run)
                            @php $m = $run->metrics['sentiment'] ?? []; @endphp
                            {{-- Run yang dibatalkan diredupkan, bukan disembunyikan:
                                 jejak auditnya justru yang perlu disimpan. Yang
                                 dibuang adalah ANGKANYA - ia sudah tidak ikut
                                 kurva, perbandingan, maupun laporan. --}}
                            <tr class="{{ $run->batal ? 'bg-rose-50/60' : '' }}">
                                <td class="px-5 py-3 {{ $run->batal ? 'text-rose-900' : 'text-gray-900' }}">
                                    {{ $run->judul() }}
                                    @if($run->batal && $run->alasan_batal)
                                        <span class="block text-xs text-rose-700">{{ $run->alasan_batal }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if($run->batal)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800">
                                            BATAL
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ match($run->status) {
                                                'completed' => 'bg-emerald-100 text-emerald-800',
                                                'failed' => 'bg-rose-100 text-rose-800',
                                                'running' => 'bg-blue-100 text-blue-800',
                                                default => 'bg-gray-100 text-gray-700',
                                            } }}">
                                            {{ match($run->status) {
                                                'completed' => 'Selesai',
                                                'failed' => 'Gagal',
                                                'running' => 'Berjalan',
                                                default => 'Menunggu',
                                            } }}
                                        </span>
                                    @endif
                                    @if($run->error_message)
                                        <p class="mt-1 text-xs text-rose-600">{{ Str::limit($run->error_message, 120) }}</p>
                                    @endif
                                </td>
                                @if($run->batal)
                                    {{-- Angkanya sengaja tidak dicetak. Mencetaknya
                                         dengan penanda saja tidak cukup: angka yang
                                         terbaca akan dikutip, dan yang ini tidak
                                         dapat direproduksi oleh siapa pun. --}}
                                    <td colspan="2" class="px-5 py-3 text-center text-xs text-rose-700 font-semibold">
                                        tidak dapat direproduksi - jangan dikutip
                                    </td>
                                @else
                                    <td class="px-5 py-3 text-right text-gray-900">{{ $m['accuracy'] ?? '-' }}{{ isset($m['accuracy']) ? '%' : '' }}</td>
                                    <td class="px-5 py-3 text-right text-gray-900">{{ $m['macro_f1'] ?? '-' }}{{ isset($m['macro_f1']) ? '%' : '' }}</td>
                                @endif
                                <td class="px-5 py-3 text-gray-500">{{ $run->completed_at?->format('d M Y H:i') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------ contoh baris --}}
    <details class="rounded-2xl border border-gray-200 bg-white p-5">
        <summary class="cursor-pointer text-sm font-semibold text-gray-900">Contoh isi dataset</summary>
        <ul class="mt-3 space-y-2">
            @foreach($contohItem as $item)
                <li class="rounded-lg border border-gray-100 p-3 text-sm">
                    <p class="text-gray-700">{{ Str::limit($item->text, 200) }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $labelIndo[$item->gold_sentiment] ?? $item->gold_sentiment }}
                        @if($item->segment) &middot; {{ $item->segment }} @endif
                        @if($item->gold_aspects) &middot; aspek: {{ implode(', ', (array) $item->gold_aspects) }} @endif
                    </p>
                </li>
            @endforeach
        </ul>
    </details>
</div>
@endsection

@push('scripts')
@if(count($kurva) >= 2)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('kurvaEvaluasi');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    const titik = @json($kurva);

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: titik.map(t => t.label),
            datasets: [
                {
                    label: 'Akurasi',
                    data: titik.map(t => t.accuracy),
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    tension: 0.25,
                },
                {
                    label: 'Macro-F1',
                    data: titik.map(t => t.macro_f1),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
                    tension: 0.25,
                },
                {
                    // Lantai pembanding ikut digambar supaya kenaikan model
                    // selalu terbaca relatif terhadap tebakan paling naif.
                    label: 'Tebak kelas mayoritas',
                    data: titik.map(t => t.majority_baseline),
                    borderColor: '#9ca3af',
                    borderDash: [5, 5],
                    pointRadius: 0,
                    tension: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                // Koherensi sengaja tidak digambar di sini: ia sifat korpus,
                // bukan sifat model, dan model topik tidak ikut dilatih ulang -
                // garis datar di sumbu kedua hanya menambah derau pada grafik
                // yang justru harus memperlihatkan perubahan F1.
                y: {
                    suggestedMin: 0,
                    suggestedMax: 100,
                    ticks: { callback: value => value + '%' },
                },
            },
            plugins: {
                legend: { position: 'bottom' },
            },
        },
    });
});
</script>
@endif
@endpush
