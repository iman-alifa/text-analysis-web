{{-- Fase evaluasi kuesioner: tunda pelatihan otomatis, lalu putar ulang.
     Lihat RetrainDeferralService untuk alasan tiap langkah. --}}
@php
    $putar = $tunda['putar_ulang_info'] ?? [];
    $labelStatus = [
        'tertunda' => ['Tertunda', 'bg-amber-100 text-amber-800'],
        'dijalankan' => ['Diputar ulang', 'bg-green-100 text-green-800'],
        'dilewati' => ['Dilewati', 'bg-gray-100 text-gray-600'],
        'dibatalkan' => ['Dibatalkan', 'bg-gray-100 text-gray-500'],
    ];
@endphp
<div id="fase-kuesioner" class="bg-white rounded-xl shadow-sm border {{ $tunda['tunda'] ? 'border-amber-300' : 'border-gray-200' }} p-6">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                Fase Evaluasi Kuesioner
                @if($tunda['tunda'])
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">Mode tunda aktif</span>
                @elseif($tunda['putar_ulang'])
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800">Pemutaran ulang berjalan</span>
                @endif
            </h3>
            <p class="mt-1 text-sm text-gray-600">
                Selama mode tunda, koreksi pengguna tetap tersimpan, tetapi pelatihan otomatis tidak dijalankan.
                Setiap kali pelatihan seharusnya terpicu, waktunya dicatat sebagai satu pemicu tertunda.
            </p>
            <ol class="mt-3 text-sm text-gray-600 list-decimal list-inside space-y-1">
                <li>Nyalakan mode tunda sebelum pengguna mulai uji coba.</li>
                <li>Setelah semua pengguna selesai, ambil golden dataset baru dari analisis mereka, anotasi, lalu bekukan.</li>
                <li>Jalankan evaluasi baseline pada golden dataset itu di halaman evaluasi.</li>
                <li>Matikan mode tunda, lalu putar ulang. Tiap pemicu dijalankan sebagai satu putaran atas koreksi yang sudah ada <em>pada saat pemicu itu terjadi</em>, dan diukur otomatis sebelum putaran berikutnya.</li>
            </ol>
        </div>

        <dl class="grid grid-cols-3 gap-3 text-center shrink-0">
            <div class="rounded-lg bg-amber-50 px-4 py-2">
                <dt class="text-xs text-amber-800">Tertunda</dt>
                <dd class="text-2xl font-bold text-amber-900">{{ $tunda['tertunda'] }}</dd>
            </div>
            <div class="rounded-lg bg-green-50 px-4 py-2">
                <dt class="text-xs text-green-800">Diputar ulang</dt>
                <dd class="text-2xl font-bold text-green-900">{{ $tunda['dijalankan'] }}</dd>
            </div>
            <div class="rounded-lg bg-gray-50 px-4 py-2">
                <dt class="text-xs text-gray-600">Dilewati</dt>
                <dd class="text-2xl font-bold text-gray-800">{{ $tunda['dilewati'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('admin.training.tunda') }}">
            @csrf
            <input type="hidden" name="aktif" value="{{ $tunda['tunda'] ? 0 : 1 }}">
            <button type="submit"
                    onclick="return confirm({{ Js::from($tunda['tunda']
                        ? 'Matikan mode tunda? Pemicu berikutnya akan kembali menjalankan pelatihan langsung. Pemicu yang sudah tercatat tetap menunggu diputar ulang.'
                        : 'Nyalakan mode tunda? Pelatihan otomatis akan dicatat, tidak dijalankan, sampai mode ini dimatikan.') }})"
                    class="px-4 py-2 rounded-lg text-sm font-medium shadow-sm {{ $tunda['tunda'] ? 'bg-white border border-amber-300 text-amber-800 hover:bg-amber-50' : 'bg-amber-500 text-white hover:bg-amber-600' }}">
                {{ $tunda['tunda'] ? 'Matikan mode tunda' : 'Nyalakan mode tunda' }}
            </button>
        </form>

        @if($tunda['putar_ulang'])
            <form method="POST" action="{{ route('admin.training.tunda.hentikan') }}">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-white border border-gray-300 text-gray-700 hover:bg-gray-50"
                        onclick="return confirm('Hentikan pemutaran ulang? Putaran yang sedang berjalan tetap diselesaikan; sisanya tetap tertunda dan bisa dilanjutkan.')">
                    Hentikan pemutaran ulang
                </button>
            </form>
        @elseif($tunda['tertunda'] > 0)
            <form method="POST" action="{{ route('admin.training.tunda.putar') }}">
                @csrf
                <button type="submit" @disabled(! empty($tunda['syarat']))
                        onclick="return confirm({{ Js::from('Putar ulang '.$tunda['tertunda'].' pemicu tertunda? Tiap putaran memakan puluhan menit dan diukur otomatis sebelum putaran berikutnya.') }})"
                        class="px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Putar ulang {{ $tunda['tertunda'] }} pemicu tertunda
                </button>
            </form>
        @endif
    </div>

    @if(! $tunda['putar_ulang'] && $tunda['tertunda'] > 0 && ! empty($tunda['syarat']))
        <ul class="mt-3 text-xs text-gray-600 space-y-1">
            @foreach($tunda['syarat'] as $syarat)
                <li>&bull; {{ $syarat }}</li>
            @endforeach
        </ul>
    @endif

    @if($tunda['riwayat']->isNotEmpty())
        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2 pr-4 text-left">#</th>
                        <th class="py-2 pr-4 text-left">Terpicu</th>
                        <th class="py-2 pr-4 text-right">Koreksi baru</th>
                        <th class="py-2 pr-4 text-right">Kolam</th>
                        <th class="py-2 pr-4 text-left">Asal</th>
                        <th class="py-2 pr-4 text-left">Status</th>
                        <th class="py-2 text-left">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($tunda['riwayat'] as $baris)
                        @php [$label, $warna] = $labelStatus[$baris->status] ?? [$baris->status, 'bg-gray-100 text-gray-600']; @endphp
                        <tr>
                            <td class="py-2 pr-4 text-gray-500">{{ $baris->id }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $baris->cutoff_at->translatedFormat('j M Y H:i') }}</td>
                            <td class="py-2 pr-4 text-right">{{ number_format($baris->new_corrections) }}</td>
                            <td class="py-2 pr-4 text-right">{{ number_format($baris->pool_size) }}</td>
                            <td class="py-2 pr-4 text-gray-600">{{ $baris->textAnalysis?->title ?? '-' }}</td>
                            <td class="py-2 pr-4"><span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $warna }}">{{ $label }}</span></td>
                            <td class="py-2 text-xs text-gray-500">{{ $baris->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
