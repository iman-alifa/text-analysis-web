{{--
    Tabel rincian hasil per nilai kolom pemisah.

    Dirender di server dan dikembalikan apa adanya oleh endpoint AJAX ketika
    pengguna berpindah kolom, supaya markupnya tidak digandakan di JavaScript.

    Variabel: $breakdown (keluaran SegmentAnalysisService::breakdown)
--}}
@php
    $segments = $breakdown['segments'] ?? [];
    $terpotong = ($breakdown['total_segments'] ?? 0) - count($segments);
    // Kolom topik hanya masuk akal untuk analisis yang menjalankan topic
    // modeling ('combined'). Diperiksa dari isi datanya sendiri, bukan
    // analysis_type, supaya baris lama tanpa topik tidak menampilkan kolom
    // kosong di semua baris.
    $punyaTopik = collect($segments)->contains(fn ($s) => ! empty($s['top_topics']));
@endphp

@if(empty($segments))
    <p class="text-sm text-gray-500 py-6 text-center">
        Tidak ada baris yang memiliki nilai pada kolom ini.
    </p>
@else
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-xs text-gray-500">
        <span>{{ number_format($breakdown['total_segments']) }} nilai unik</span>

        @if($terpotong > 0)
            <span class="text-amber-700">
                Menampilkan {{ count($segments) }} terbesar; {{ $terpotong }} lainnya tidak ditampilkan.
            </span>
        @endif

        @if(($breakdown['ungrouped'] ?? 0) > 0)
            {{-- Baris tanpa nilai sengaja tidak dimasukkan ke segmen mana pun,
                 tetapi jumlahnya harus terlihat: kalau tidak, jumlah seluruh
                 segmen tampak tidak cocok dengan total analisis. --}}
            <span>{{ number_format($breakdown['ungrouped']) }} baris tanpa nilai, tidak dikelompokkan</span>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[640px]">
            <caption class="sr-only">
                Rincian hasil analisis menurut kolom {{ $breakdown['column'] }}
            </caption>
            <thead>
                <tr>
                    <th scope="col" class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200">
                        {{ $breakdown['column'] }}
                    </th>
                    <th scope="col" class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200 text-center">
                        Teks
                    </th>
                    <th scope="col" class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200">
                        Distribusi Sentimen
                    </th>
                    <th scope="col" class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200">
                        Aspek Teratas
                    </th>
                    @if($punyaTopik)
                    <th scope="col" class="py-3 px-4 font-semibold text-xs text-gray-500 uppercase tracking-wider border-b border-gray-200">
                        Topik Dominan
                    </th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($segments as $segmen)
                <tr class="hover:bg-gray-50 align-top">
                    <td class="py-3 px-4 border-b border-gray-100">
                        <span class="text-sm font-medium text-gray-900">{{ $segmen['label'] }}</span>
                        <span class="block text-xs mt-0.5
                            @if($segmen['dominant'] === 'positive') text-green-600
                            @elseif($segmen['dominant'] === 'negative') text-red-600
                            @else text-gray-500 @endif">
                            Dominan {{ \App\Helpers\ResultPresenter::sentimentLabel($segmen['dominant']) }}
                        </span>
                    </td>

                    <td class="py-3 px-4 border-b border-gray-100 text-center text-sm text-gray-600">
                        {{ number_format($segmen['count']) }}
                    </td>

                    <td class="py-3 px-4 border-b border-gray-100 w-64">
                        {{-- Batang tumpuk: proporsinya langsung terbandingkan
                             antar baris tanpa membaca angkanya satu per satu. --}}
                        <div class="flex h-4 w-full rounded overflow-hidden bg-gray-100"
                             role="img"
                             aria-label="Positif {{ $segmen['distribution']['positive'] }} persen, netral {{ $segmen['distribution']['neutral'] }} persen, negatif {{ $segmen['distribution']['negative'] }} persen">
                            @foreach(['positive' => 'bg-green-500', 'neutral' => 'bg-gray-400', 'negative' => 'bg-red-500'] as $label => $warna)
                                @if($segmen['distribution'][$label] > 0)
                                    <div class="{{ $warna }}" style="width: {{ $segmen['distribution'][$label] }}%"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex justify-between mt-1 text-xs text-gray-500">
                            <span class="text-green-600">{{ $segmen['distribution']['positive'] }}%</span>
                            <span>{{ $segmen['distribution']['neutral'] }}%</span>
                            <span class="text-red-600">{{ $segmen['distribution']['negative'] }}%</span>
                        </div>
                    </td>

                    <td class="py-3 px-4 border-b border-gray-100">
                        @forelse($segmen['top_aspects'] as $aspek)
                            <span class="inline-block mr-1 mb-1 px-2 py-0.5 rounded bg-blue-50 text-blue-800 text-xs">
                                {{ $aspek['aspect'] }} ({{ $aspek['count'] }})
                            </span>
                        @empty
                            <span class="text-xs text-gray-400">-</span>
                        @endforelse
                    </td>

                    @if($punyaTopik)
                    <td class="py-3 px-4 border-b border-gray-100">
                        @forelse($segmen['top_topics'] as $topik)
                            <span class="inline-block mr-1 mb-1 px-2 py-0.5 rounded bg-purple-50 text-purple-800 text-xs">
                                {{ $topik['label'] }} ({{ $topik['count'] }})
                            </span>
                        @empty
                            <span class="text-xs text-gray-400">-</span>
                        @endforelse
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
