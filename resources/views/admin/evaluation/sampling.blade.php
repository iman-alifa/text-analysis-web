@extends('layouts.app')

@section('content')
<div class="space-y-6">

    <div>
        <a href="{{ route('admin.evaluation.index') }}" class="text-xs text-gray-500 hover:text-gray-700">&larr; Evaluasi Model</a>
        <h1 class="mt-1 text-3xl font-bold text-gray-900">Ambil Sampel Golden Dataset</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-600">
            Mengambil sampel acak berstrata dari korpus yang sudah ada di sistem, untuk dianotasi
            manusia. Hasilnya berupa berkas anotasi kosong, dan labelnya diisi orang, bukan model.
        </p>
    </div>

    @if(session('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Aturan metodologisnya ditulis di halaman, bukan hanya di dokumentasi:
         keputusan yang salah di sini tidak bisa diperbaiki belakangan tanpa
         menganotasi ulang dari nol. --}}
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
        <h2 class="text-sm font-semibold text-indigo-900">Empat aturan yang menentukan keabsahan angka</h2>
        <ol class="mt-3 space-y-2 text-sm text-indigo-900">
            <li>
                <strong>1. Acak, bukan berdasarkan keyakinan model.</strong>
                Menggoda memakai baris berkeyakinan rendah sebagai test set, karena datanya sudah ada
                dan tampak menantang. Tetapi sampel itu memilih justru kasus tersulit, sehingga
                akurasinya jauh di bawah kinerja sebenarnya dan tidak bisa digeneralisasi.
                Halaman ini karena itu <em>tidak menyediakan</em> pilihan tersebut.
            </li>
            <li>
                <strong>2. Stratifikasi pada sifat data, bukan pada tebakan model.</strong>
                Lembaga dan video adalah sifat dokumen. Menstratifikasi pada label prediksi akan
                mengubah proporsi kelas sehingga akurasinya tidak lagi menaksir akurasi korpus.
            </li>
            <li>
                <strong>3. Baris yang sudah dikoreksi dikecualikan otomatis.</strong>
                Baris itu data training; memasukkannya ke test set adalah kebocoran sejak awal.
            </li>
            <li>
                <strong>4. Berkas anotasi tidak memuat prediksi model.</strong>
                Anotator yang melihat tebakan model cenderung menyetujuinya, dan akurasi terukur
                naik tanpa modelnya berubah.
            </li>
        </ol>
    </div>

    @if(empty($sumber))
        <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center">
            <p class="text-sm text-gray-600">Belum ada analisis yang bisa dijadikan sumber sampel.</p>
            <p class="mt-1 text-xs text-gray-500">Jalankan analisis atas korpusnya lebih dulu.</p>
        </div>
    @else
    <form action="{{ route('admin.evaluation.sample') }}" method="POST" class="space-y-6">
        @csrf

        <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">1. Pilih korpus sumber</h2>
                <p class="mt-0.5 text-xs text-gray-500">
                    Pilih analisis yang isinya benar-benar populasi yang ingin dibicarakan di naskah.
                </p>
            </div>
            <div class="max-h-80 overflow-y-auto divide-y divide-gray-100">
                @foreach($sumber as $satu)
                    <label class="flex items-start gap-3 px-5 py-3 hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="analysis_ids[]" value="{{ $satu['id'] }}"
                               data-kolom="{{ implode('|', $satu['segment_columns']) }}"
                               @checked(in_array($satu['id'], (array) old('analysis_ids', [])))
                               class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900">{{ $satu['title'] }}</span>
                            <span class="block text-xs text-gray-500">
                                #{{ $satu['id'] }} &middot; {{ $satu['type'] }} &middot;
                                {{ number_format($satu['text_count']) }} teks &middot;
                                {{ $satu['created_at']?->format('d M Y') }}
                                @if($satu['segment_columns'])
                                    &middot; kolom pemisah: {{ implode(', ', $satu['segment_columns']) }}
                                @else
                                    &middot; <span class="text-amber-600">tanpa kolom pemisah</span>
                                @endif
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">2. Ukuran sampel</h2>

                {{-- Dua cara menyebut ukuran, dan keduanya sah.
                     Jumlah baris menentukan presisi secara langsung: margin
                     hanya bergantung pada n, bukan pada besar korpus. Persentase
                     lebih mudah dijelaskan di naskah dan ikut menyesuaikan bila
                     korpusnya berubah. --}}
                <div class="mt-3 inline-flex rounded-lg border border-gray-300 p-0.5" role="group">
                    <button type="button" data-mode="jumlah"
                            class="mode-ukuran px-3 py-1.5 text-xs font-medium rounded-md">
                        Jumlah baris
                    </button>
                    <button type="button" data-mode="persen"
                            class="mode-ukuran px-3 py-1.5 text-xs font-medium rounded-md">
                        Persen korpus
                    </button>
                </div>
                <input type="hidden" name="ukuran_mode" id="ukuran_mode" value="{{ old('ukuran_mode', 'jumlah') }}">

                <div class="mt-3" id="kolom-jumlah">
                    <label for="size" class="block text-xs font-medium text-gray-700">Jumlah baris</label>
                    <input type="number" name="size" id="size" min="10" max="5000"
                           value="{{ old('size', 500) }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-xs text-gray-500">
                        Menentukan presisi secara langsung &mdash; margin hanya bergantung pada n.
                    </p>
                </div>

                <div class="mt-3" id="kolom-persen">
                    <label for="percent" class="block text-xs font-medium text-gray-700">Persen dari kolam layak</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="number" step="0.5" min="0.5" max="100" name="percent" id="percent"
                               value="{{ old('percent', 10) }}"
                               class="block w-28 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <span class="text-sm text-gray-500">%</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        Penyebutnya adalah baris yang <strong>lolos penyaringan</strong> (unik, ada isinya,
                        cukup panjang, belum dikoreksi) &mdash; bukan jumlah baris mentah di berkas. Pada
                        korpus komentar YouTube selisihnya bisa lebih dari 20%, karena emotikon, duplikat, dan komentar pendek ikut tersaring.
                    </p>
                </div>

                <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Baris</th>
                                <th class="px-3 py-2 text-right">Margin 95%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($margin as $n => $poin)
                                <tr class="{{ $n == 300 ? 'bg-indigo-50' : '' }}">
                                    <td class="px-3 py-1.5 text-gray-700">{{ $n }}</td>
                                    <td class="px-3 py-1.5 text-right text-gray-900">&plusmn;{{ $poin }} poin</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 rounded-lg bg-indigo-50 border border-indigo-200 px-3 py-2 text-sm text-indigo-900"
                   id="margin-hidup"></p>

                <p class="mt-2 text-xs text-gray-500">
                    Margin confidence interval Wilson pada akurasi 85%. Perhatikan bahwa margin
                    <strong>hanya bergantung pada n</strong>, bukan pada persentase korpus: 500 baris
                    memberi &plusmn;3,1 poin baik diambil dari korpus 5.000 maupun 50.000. Itu sebabnya
                    jumlah baris yang menentukan mutu evaluasi, sementara persentase hanya cara
                    menyebutnya.
                </p>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-4">
                <h2 class="text-sm font-semibold text-gray-900">3. Stratifikasi &amp; penyaringan</h2>

                <div>
                    <label for="stratify_by" class="block text-xs font-medium text-gray-700">Kolom pemisah</label>
                    <select name="stratify_by" id="stratify_by"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Tanpa kolom (distratifikasi per analisis)</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        Isi dengan kolom yang memuat nama lembaga atau judul video. Daftarnya
                        mengikuti analisis yang dicentang.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="seed" class="block text-xs font-medium text-gray-700">Semai acak</label>
                        <input type="number" name="seed" id="seed" min="0" required value="{{ old('seed', 42) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <p class="mt-1 text-xs text-gray-500">Dicatat pada rencana; sampel yang sama bisa diulang.</p>
                    </div>
                    <div>
                        <label for="min_length" class="block text-xs font-medium text-gray-700">Panjang minimal</label>
                        <input type="number" name="min_length" id="min_length" min="0" max="500"
                               value="{{ old('min_length', 20) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                        <p class="mt-1 text-xs text-gray-500">Karakter. Di bawah 20 hampir selalu emoji.</p>
                    </div>
                </div>

                <details class="rounded-lg border border-gray-200 p-3">
                    <summary class="cursor-pointer text-xs font-medium text-gray-700">Jatah minimal per strata</summary>
                    <input type="number" name="min_per_stratum" min="0" max="500"
                           value="{{ old('min_per_stratum', 0) }}"
                           class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm">
                    <p class="mt-2 text-xs text-amber-700">
                        Biarkan 0 kecuali ada lembaga yang komentarnya sangat sedikit. Menaikkannya
                        membuat proporsi tidak lagi mengikuti korpus, sehingga angka keseluruhan
                        menaksir rerata antar-lembaga alih-alih rerata korpus, dan itu harus
                        disebut di naskah.
                    </p>
                </details>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <label for="name" class="block text-xs font-medium text-gray-700">Nama golden dataset</label>
            <input type="text" name="name" id="name" required
                   value="{{ old('name', 'Hold-out test set') }}"
                   class="mt-1 block w-full max-w-md rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">

            <button type="submit"
                    class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-700 shadow-md transition">
                Ambil Sampel
            </button>

            <p class="mt-3 text-xs text-gray-500">
                Sampelnya tersimpan tanpa label. Langkah berikutnya: unduh berkas anotasi, isi kolom
                <code>label</code>, unggah kembali, lalu bekukan datasetnya sebelum active learning
                dimulai.
            </p>
        </div>
    </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- pemilih mode ukuran sampel -------------------------------------
    const modeInput = document.getElementById('ukuran_mode');
    const tombolMode = document.querySelectorAll('.mode-ukuran');
    const kolom = {
        jumlah: document.getElementById('kolom-jumlah'),
        persen: document.getElementById('kolom-persen'),
    };

    function pasangMode(mode) {
        modeInput.value = mode;

        tombolMode.forEach(function (b) {
            const aktif = b.dataset.mode === mode;
            b.classList.toggle('bg-indigo-600', aktif);
            b.classList.toggle('text-white', aktif);
            b.classList.toggle('text-gray-600', !aktif);
            b.setAttribute('aria-pressed', aktif ? 'true' : 'false');
        });

        Object.entries(kolom).forEach(function ([nama, el]) {
            if (el) {
                el.hidden = nama !== mode;
            }
        });
    }

    tombolMode.forEach(b => b.addEventListener('click', () => pasangMode(b.dataset.mode)));

    // Margin dihitung langsung dari n yang diketik, bukan dibaca dari tabel
    // statis. Alasan memilih ukuran sampel yang bisa dipertahankan di sidang
    // adalah "supaya marginnya sekian" - jadi angkanya harus terlihat saat
    // keputusannya diambil, bukan setelah anotasi selesai.
    var kotakMargin = document.getElementById('margin-hidup');
    var isianJumlah = document.getElementById('size');

    function wilsonMargin(n) {
        if (!n || n < 2) { return null; }
        var p = 0.85, z = 1.959964, z2 = z * z;
        var penyebut = 1 + z2 / n;
        var sebar = (z / penyebut) * Math.sqrt(p * (1 - p) / n + z2 / (4 * n * n));
        return sebar * 100;
    }

    function perbaruiMargin() {
        var n = parseInt(isianJumlah.value, 10);
        var m = wilsonMargin(n);

        if (!m) { kotakMargin.textContent = ''; return; }

        var teks = 'Dengan ' + n + ' baris: margin ±' + m.toFixed(1) + ' poin (95%, pada akurasi 85%). ';
        // Daya uji McNemar: perbaikan bersih terkecil yang terdeteksi adalah
        // 6 baris pada b = 0, berapa pun n-nya.
        teks += 'Uji McNemar baru mendeteksi perbaikan mulai ' + (6 / n * 100).toFixed(2) + ' poin.';

        kotakMargin.textContent = teks;
    }

    isianJumlah.addEventListener('input', perbaruiMargin);
    perbaruiMargin();

    pasangMode(modeInput.value || 'jumlah');

    // --- kolom pemisah bersama ------------------------------------------
    const centang = document.querySelectorAll('input[name="analysis_ids[]"]');
    const pilihan = document.getElementById('stratify_by');

    if (!pilihan || centang.length === 0) {
        return;
    }

    const terpilihAwal = @json(old('stratify_by'));

    function perbarui() {
        // Hanya kolom yang dimiliki SEMUA analisis tercentang yang ditawarkan.
        // Kolom yang hanya ada di sebagian sumber akan membuat sebagian baris
        // jatuh ke strata kosong, dan alokasinya menjadi tidak berarti.
        let bersama = null;

        centang.forEach(function (el) {
            if (!el.checked) {
                return;
            }

            const kolom = (el.dataset.kolom || '').split('|').filter(Boolean);
            bersama = bersama === null ? kolom : bersama.filter(k => kolom.includes(k));
        });

        const sebelumnya = pilihan.value;
        pilihan.innerHTML = '';

        const kosong = document.createElement('option');
        kosong.value = '';
        kosong.textContent = 'Tanpa kolom (distratifikasi per analisis)';
        pilihan.appendChild(kosong);

        (bersama || []).forEach(function (kolom) {
            const opsi = document.createElement('option');
            opsi.value = kolom;
            opsi.textContent = kolom;
            pilihan.appendChild(opsi);
        });

        const kembalikan = sebelumnya || terpilihAwal;

        if (kembalikan && (bersama || []).includes(kembalikan)) {
            pilihan.value = kembalikan;
        }
    }

    centang.forEach(el => el.addEventListener('change', perbarui));
    perbarui();
});
</script>
@endpush
