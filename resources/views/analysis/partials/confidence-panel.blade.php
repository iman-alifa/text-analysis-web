{{--
    Panel keyakinan model + ajakan ikut serta dalam active learning.

    Ditempatkan tepat di bawah ringkasan, sebelum angka apa pun yang lain:
    pembaca perlu tahu seberapa besar hasil di bawahnya bisa dipercaya sebelum
    membaca hasilnya.

    Variabel: $analysis, $confidence (keluaran ConfidenceReportService::build)
--}}
@php
    $sudahDikoreksi = $analysis->trainingItems()->where('is_corrected', true)->count();
@endphp

<div id="bagian-keyakinan" class="scroll-mt-24 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Seberapa Yakin Model pada Data Anda?</h3>
            <p class="text-sm text-gray-500 mt-1">
                Keyakinan adalah ukuran seberapa mantap model pada tebakannya sendiri -
                bukan jaminan bahwa tebakannya benar.
            </p>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold border {{ $confidence['verdict']['class'] }}">
            {{ $confidence['verdict']['label'] }}
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <p class="text-sm text-gray-600">Rata-rata keyakinan</p>
            <p class="text-4xl font-bold text-gray-900 mt-1">{{ number_format($confidence['average'] * 100, 1) }}%</p>
            <p class="text-xs text-gray-500 mt-1">
                Median {{ number_format($confidence['median'] * 100, 1) }}%
                dari {{ number_format($confidence['scored']) }} teks yang dinilai
                @if($confidence['unscored'] > 0)
                    ({{ number_format($confidence['unscored']) }} teks tidak dinilai)
                @endif
            </p>
        </div>

        <div class="lg:col-span-2">
            <p class="text-sm text-gray-600 mb-2">Sebaran keyakinan</p>

            <div class="flex h-5 w-full rounded overflow-hidden bg-gray-100"
                 role="img"
                 aria-label="@foreach($confidence['bands'] as $pita){{ $pita['label'] }} {{ $pita['share'] }} persen. @endforeach">
                @foreach(['tinggi' => 'bg-green-500', 'sedang' => 'bg-amber-400', 'rendah' => 'bg-red-500'] as $kunci => $warna)
                    @if($confidence['bands'][$kunci]['share'] > 0)
                        <div class="{{ $warna }}" style="width: {{ $confidence['bands'][$kunci]['share'] }}%"></div>
                    @endif
                @endforeach
            </div>

            <dl class="grid grid-cols-3 gap-3 mt-3">
                @foreach(['tinggi' => 'text-green-600', 'sedang' => 'text-amber-600', 'rendah' => 'text-red-600'] as $kunci => $warna)
                <div>
                    <dt class="text-xs font-medium {{ $warna }}">{{ $confidence['bands'][$kunci]['label'] }}</dt>
                    <dd class="text-sm font-semibold text-gray-900">
                        {{ number_format($confidence['bands'][$kunci]['count']) }}
                        <span class="font-normal text-gray-500">teks</span>
                    </dd>
                    <dd class="text-xs text-gray-400">{!! $confidence['bands'][$kunci]['keterangan'] !!}</dd>
                </div>
                @endforeach
            </dl>
        </div>
    </div>

    <p class="mt-4 text-sm text-gray-700">{{ $confidence['verdict']['pesan'] }}</p>

    {{-- Ajakan ikut serta. Sengaja menyebut angka lebih dulu, baru mengajak:
         pengguna perlu alasan konkret, bukan sekadar tombol.

         Hanya meminta pita "Sangat ragu" ($confidence['bands']['rendah']) -
         bukan seluruh yang di bawah ambang tinjauan utama
         ($confidence['low_count'], gabungan pita "Ragu" + "Sangat ragu" dan
         jauh lebih banyak). Meminta semuanya sekaligus di awal membuat
         permintaannya terasa berat dan menurunkan peluang benar-benar
         diselesaikan; pita paling meragukan itu juga yang paling berharga
         per baris bagi pelatihan ulang. Pita "Ragu" yang lebih luas tetap
         bisa dibuka dari halaman koreksi sendiri bila pengguna mau lebih. --}}
    @php
        $sangatRagu = $confidence['bands']['rendah'];
    @endphp
    @if($confidence['actionable'])
    <div class="mt-5 rounded-lg border border-indigo-200 bg-indigo-50 p-5">
        <div class="flex items-start gap-4 flex-wrap">
            <svg class="w-6 h-6 text-indigo-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>

            <div class="flex-1 min-w-[16rem]">
                <p class="font-semibold text-indigo-900">
                    {{ number_format($sangatRagu['count']) }} teks dinilai dengan sangat ragu
                    - maukah Anda membantu memperbaikinya?
                </p>
                <p class="text-sm text-indigo-800 mt-1">
                    Baris ini punya keyakinan di bawah
                    <strong>{{ number_format($confidence['band_bounds']['sedang'] * 100, 0) }}%</strong>
                    ({{ $sangatRagu['share'] }}% dari yang dinilai) - pita paling meragukan pada analisis
                    ini. Diminta lebih dulu, bukan seluruh baris yang ragu sekaligus: model belajar
                    paling banyak dari contoh yang paling membuatnya ragu, dan permintaan yang lebih
                    kecil lebih mungkin benar-benar diselesaikan.
                </p>

                @if($sudahDikoreksi > 0)
                    <p class="text-sm text-indigo-700 mt-2">
                        Anda sudah mengoreksi {{ number_format($sudahDikoreksi) }} baris pada analisis ini. Terima kasih.
                    </p>
                @endif

                <p class="text-xs text-indigo-700 mt-2">
                    Ambang diambil dari
                    {{ $confidence['threshold_source'] === 'nlp-api' ? 'model itu sendiri' : 'pengaturan aplikasi' }}.
                    Bersifat sukarela - hasil analisis tetap bisa dipakai tanpa mengoreksi apa pun.
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <a href="{{ route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']) }}"
                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    Koreksi {{ number_format($sangatRagu['count']) }} baris yang sangat ragu
                </a>
                <a href="{{ route('analysis.feedback', $analysis->id) }}"
                   class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium bg-white text-indigo-700 border border-indigo-200 hover:bg-indigo-50">
                    Lihat semua baris
                </a>
            </div>
        </div>
    </div>
    @else
    <p class="mt-5 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg p-4">
        Tidak ada teks yang keyakinannya di bawah
        {{ number_format($confidence['band_bounds']['sedang'] * 100, 0) }}%, jadi tidak ada yang perlu
        ditinjau lebih dulu. Anda tetap bisa
        <a href="{{ route('analysis.feedback', $analysis->id) }}" class="text-indigo-700 underline">mengoreksi baris mana pun</a>
        bila menemukan yang keliru.
    </p>
    @endif
</div>
