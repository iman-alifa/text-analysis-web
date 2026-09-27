@extends('layouts.app')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
        <h1 class="text-3xl font-bold text-gray-900">Evaluasi Model</h1>
        <p class="mt-1 text-sm text-gray-600 max-w-3xl">
            Golden dataset adalah kumpulan teks berlabel manusia yang tidak pernah ikut dilatih.
            Hanya angka dari halaman ini yang layak dikutip sebagai kinerja model. Metrik pada
            halaman Training dihitung atas data koreksi yang justru dilatihkan, jadi angkanya
            selalu menyanjung.
        </p>
        </div>

        <a href="{{ route('admin.evaluation.sampling') }}"
           class="inline-flex shrink-0 items-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 shadow-md transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 6h16M4 10h16M4 14h10M4 18h6"/>
            </svg>
            Ambil Sampel dari Korpus
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Kebocoran diperiksa lintas seluruh dataset beku, bukan hanya yang sedang dibuka:
         satu tabrakan di mana pun sudah cukup membuat angka hold-out patut diragukan. --}}
    <div class="rounded-2xl border {{ $leakage['corrected_overlap'] > 0 ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }} p-5">
        <h2 class="text-sm font-semibold text-gray-900">Pemeriksaan kebocoran data</h2>
        @if($leakage['total_golden'] === 0)
            <p class="mt-1 text-sm text-gray-600">
                Belum ada golden dataset beku bertipe <em>test</em>, jadi belum ada yang perlu dijaga.
            </p>
        @elseif($leakage['corrected_overlap'] === 0)
            <p class="mt-1 text-sm text-gray-700">
                {{ $leakage['total_golden'] }} baris golden diperiksa terhadap muatan pelatihan:
                <strong class="text-emerald-700">tidak satu pun pernah dilatihkan</strong>.
            </p>
        @else
            <p class="mt-1 text-sm text-gray-700">
                <strong>{{ $leakage['corrected_golden_affected'] }}</strong> dari {{ $leakage['total_golden'] }}
                baris golden pernah masuk muatan pelatihan lewat {{ $leakage['corrected_overlap'] }} entri
                terkoreksi. Baris itu ditahan penjaga kebocoran sebelum pelatihan berikutnya, tetapi riwayat
                pelatihan sebelumnya perlu diperiksa.
            </p>
        @endif

        @if($leakage['total_golden'] > 0)
            <p class="mt-2 text-xs text-gray-500">
                Konteks: {{ $leakage['golden_affected'] }} dari {{ $leakage['total_golden'] }} baris golden terdaftar
                di <em>training_items</em> sebagai kandidat, terbawa oleh {{ $leakage['overlap'] }} entri. Angka ini
                wajar mendekati seluruh baris dan bukan kebocoran, karena <em>training_items</em> memuat setiap baris
                hasil analisis sedangkan golden dataset memang disampel dari analisis yang sama. Yang dilatihkan
                hanya baris berstatus terkoreksi.
            </p>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="lg:col-span-2 rounded-2xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Golden dataset</h2>
            </div>

            @if($datasets->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm text-gray-600">Belum ada golden dataset.</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Unggah berkas beranotasi di sebelah kanan untuk memulai.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3 text-left">Nama</th>
                                <th class="px-5 py-3 text-left">Jenis</th>
                                <th class="px-5 py-3 text-right">Baris</th>
                                <th class="px-5 py-3 text-right">Evaluasi</th>
                                <th class="px-5 py-3 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($datasets as $dataset)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.evaluation.show', $dataset->id) }}"
                                           class="font-medium text-indigo-600 hover:text-indigo-800">
                                            {{ $dataset->name }}
                                        </a>
                                        @if($dataset->description)
                                            <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($dataset->description, 90) }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-600">
                                        {{ $dataset->split === 'test' ? 'Hold-out test' : 'Dev' }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-gray-900">{{ $dataset->item_count }}</td>
                                    <td class="px-5 py-3 text-right text-gray-600">{{ $dataset->runs_count }}</td>
                                    <td class="px-5 py-3">
                                        @if($dataset->isFrozen())
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                                Beku
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                                Belum dibekukan
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-gray-900">Unggah berkas beranotasi</h2>
            <p class="mt-1 text-xs text-gray-500">
                Untuk berkas yang sudah lengkap labelnya. Bila datanya masih perlu diambil dari
                korpus di sistem, mulai dari <a href="{{ route('admin.evaluation.sampling') }}"
                class="text-indigo-600 hover:text-indigo-800 font-medium">Ambil Sampel</a>.
            </p>

            <div class="mt-3 rounded-lg bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600 space-y-1">
                <p class="font-medium text-gray-700">Kolom yang dikenali</p>
                <p><code>text</code> (atau teks/komentar): wajib</p>
                <p><code>label</code> (atau sentimen): wajib, positif/netral/negatif</p>
                <p><code>label_2</code>: label anotator kedua, untuk menghitung kappa</p>
                <p><code>aspects</code>: dipisah titik koma</p>
                <p><code>segment</code>: mis. nama lembaga, untuk rincian per segmen</p>
            </div>

            <form action="{{ route('admin.evaluation.store') }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-3">
                @csrf

                <div>
                    <label for="file" class="block text-xs font-medium text-gray-700">Berkas CSV / XLSX</label>
                    <input type="file" name="file" id="file" required accept=".csv,.txt,.xlsx,.xls"
                           class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700">
                </div>

                <div>
                    <label for="name" class="block text-xs font-medium text-gray-700">Nama</label>
                    <input type="text" name="name" id="name" required value="{{ old('name') }}"
                           placeholder="Hold-out 6 lembaga"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="description" class="block text-xs font-medium text-gray-700">Keterangan</label>
                    <textarea name="description" id="description" rows="2"
                              placeholder="Asal data, siapa yang menganotasi, kapan"
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description') }}</textarea>
                </div>

                <div>
                    <label for="split" class="block text-xs font-medium text-gray-700">Jenis</label>
                    <select name="split" id="split"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="test">Hold-out test (angka untuk naskah)</option>
                        <option value="dev">Dev (boleh dilihat berulang saat menyetel)</option>
                    </select>
                </div>

                <details class="rounded-lg border border-gray-200 p-3">
                    <summary class="cursor-pointer text-xs font-medium text-gray-700">
                        Pecah berkas ini menjadi test dan sisa
                    </summary>
                    <p class="mt-2 text-xs text-gray-500">
                        Isi bila berkasnya memuat seluruh anotasi dan Anda ingin sebagian saja menjadi
                        test set. Pemecahannya berstrata pada label dan segmen. Kosongkan untuk memakai
                        seluruh baris.
                    </p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <div>
                            <label for="test_ratio" class="block text-xs text-gray-600">Porsi test</label>
                            <input type="number" step="0.05" min="0.05" max="0.9" name="test_ratio" id="test_ratio"
                                   placeholder="0.20"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label for="seed" class="block text-xs text-gray-600">Semai</label>
                            <input type="number" min="0" name="seed" id="seed" value="42"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        </div>
                    </div>
                </details>

                <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 transition shadow-md">
                    Unggah
                </button>
            </form>
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-gray-900">Lewat baris perintah</h2>
        <p class="mt-1 text-xs text-gray-600">
            Setiap langkah juga tersedia sebagai perintah artisan, supaya seluruh proses evaluasi
            bisa diulang persis dan dilampirkan di naskah.
        </p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs leading-relaxed text-gray-100">php artisan evaluation:sample --analysis=72 --size=300 --stratify=lembaga --out=anotasi.csv
php artisan evaluation:import anotasi.csv --name="Hold-out 6 lembaga" --freeze
php artisan evaluation:split anotasi.csv --test-ratio=0.2 --seed=42 --out=sisa-latih.csv --freeze
php artisan evaluation:run 1 --label="Iterasi 0 - baseline"
php artisan evaluation:run 1 --label="Iterasi 1" --compare-last
php artisan evaluation:leakage
php artisan evaluation:report --out=laporan-evaluasi.md</pre>
    </div>
</div>
@endsection
