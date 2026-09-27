{{--
    Pedoman penggunaan aplikasi.

    Berdiri sendiri, tidak memakai `layouts.app`: halaman ini harus bisa dibuka
    kapan pun, termasuk oleh peserta uji coba yang belum masuk. Layout aplikasi
    membaca `Auth::user()->name` di bilah sisinya dan akan gagal untuk tamu.

    Dipakai juga sebagai bahan bacaan sebelum pengisian kuesioner CSUQ, jadi
    tiga hal dijaga: urutannya mengikuti alur nyata pemakaian, setiap langkah
    menyebut apa yang terlihat di layar, dan halamannya bisa dicetak.
--}}
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Pedoman penggunaan AnalisisTeks: alur lengkap dari menyiapkan data sampai membaca dan mengoreksi hasil analisis.">

    <title>Pedoman Penggunaan &middot; {{ config('app.name', 'AnalisisTeks') }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css'])

    <style>
        /* Dicetak untuk peserta uji coba yang memilih pedoman di atas kertas.
           Yang dibuang hanya elemen navigasi: isinya harus utuh. */
        @media print {
            .print\:hidden { display: none !important; }
            body { background: #fff; }
            .langkah { break-inside: avoid; }
            a[href^="http"]::after { content: " (" attr(href) ")"; font-size: 0.75rem; color: #666; }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans antialiased text-gray-900">

{{-- ============================ BILAH ATAS ============================ --}}
<header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-gray-200 print:hidden">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
        <a href="{{ auth()->check() ? route('dashboard') : route('landing') }}" class="flex items-center gap-3">
            <x-logo-mark class="w-9 h-9" />
            <span class="font-bold text-gray-900 hidden sm:inline">Analisis<span class="text-blue-600">Teks</span></span>
        </a>

        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()"
                    class="hidden sm:inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Cetak
            </button>

            @auth
                <a href="{{ route('dashboard') }}"
                   class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                    Ke Dashboard
                </a>
            @else
                <a href="{{ route('login') }}"
                   class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                    Masuk
                </a>
            @endauth
        </div>
    </div>
</header>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="lg:grid lg:grid-cols-[16rem_1fr] lg:gap-10">

        {{-- ========================= DAFTAR ISI ========================= --}}
        <nav aria-label="Daftar isi" class="print:hidden mb-8 lg:mb-0">
            <div class="lg:sticky lg:top-24">
                <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-3">Daftar Isi</p>
                {{-- Tautan video tidak bernomor: nomor daftar isi harus tetap
                     sama dengan nomor yang tertulis di judul tiap bagian. --}}
                @if($video)
                    <a href="#video-panduan"
                       class="mb-2 flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 transition">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                        Video panduan
                    </a>
                @endif
                <ol class="space-y-1 text-sm">
                    @foreach($daftarIsi as $nomor => $bagian)
                        <li>
                            <a href="#{{ $bagian['id'] }}"
                               class="block px-3 py-2 rounded-lg text-gray-600 hover:bg-white hover:text-blue-700 transition">
                                <span class="text-gray-400 tabular-nums">{{ $nomor + 1 }}.</span>
                                {{ $bagian['judul'] }}
                            </a>
                        </li>
                    @endforeach
                </ol>
            </div>
        </nav>

        {{-- =========================== ISI ============================== --}}
        <main class="min-w-0">
            <div class="mb-10">
                <h1 class="text-3xl sm:text-4xl font-bold tracking-tight">Pedoman Penggunaan</h1>
                <p class="mt-3 text-lg text-gray-600 leading-relaxed">
                    Panduan lengkap memakai AnalisisTeks, dari menyiapkan data sampai membaca
                    dan memperbaiki hasilnya. Ikuti urutannya sekali, dan Anda sudah melewati
                    seluruh alur utama aplikasi.
                </p>

                <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-5">
                    <p class="text-sm font-semibold text-blue-900">Ringkas sekali: apa yang dikerjakan aplikasi ini?</p>
                    <p class="mt-1.5 text-sm text-blue-900 leading-relaxed">
                        Anda memberi sekumpulan teks berbahasa Indonesia, misalnya komentar atau
                        jawaban survei. Aplikasi menilai nada tiap teks (positif, negatif, netral),
                        menemukan hal apa yang dibicarakan, dan mengelompokkannya menjadi beberapa
                        tema. Bila ada penilaian yang keliru, Anda bisa memperbaikinya, dan
                        perbaikan itu dipakai melatih ulang model.
                    </p>
                </div>
            </div>

            {{-- ------------------------- VIDEO PANDUAN ------------------------- --}}
            @if($video)
                @php
                    $menit = $video['durasi'] ? intdiv($video['durasi'], 60) : null;
                    $detik = $video['durasi'] ? $video['durasi'] % 60 : null;
                    $poster = is_file(public_path('img/panduan/video-poster.jpg')) ? asset('img/panduan/video-poster.jpg') : null;
                @endphp
                <section id="video-panduan" class="scroll-mt-24 mb-12" aria-labelledby="judul-video">
                    <h2 id="judul-video" class="text-2xl font-bold tracking-tight">Tonton dulu: video panduan</h2>
                    <p class="mt-2 text-gray-600 leading-relaxed">
                        {{ 'Seluruh alur di pedoman ini dalam satu video'
                            .($menit !== null ? ' ('.$menit.' menit'.($detik ? ' '.$detik.' detik' : '').')' : '').'.' }}
                        Bagian tertulis di bawahnya menjelaskan tiap langkah lebih rinci dan bisa dibaca sambil mencoba.
                    </p>

                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-black shadow-sm print:hidden">
                        {{-- preload="metadata": hanya indeks video yang diunduh saat
                             halaman dibuka, bukan seluruh berkas. --}}
                        <video controls preload="metadata" playsinline
                               class="block w-full aspect-video"
                               @if($poster) poster="{{ $poster }}" @endif
                               aria-label="Video panduan penggunaan AnalisisTeks">
                            <source src="{{ $video['url'] }}" type="video/mp4">
                            Peramban Anda tidak dapat memutar video ini.
                            <a href="{{ $video['url'] }}">Unduh videonya</a> lalu putar di perangkat Anda.
                        </video>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 print:hidden">
                        Video tidak berputar atau tersendat?
                        <a href="{{ $video['url'] }}" download="Pedoman-AnalisisTeks.mp4" class="text-blue-600 hover:underline">Unduh videonya</a>
                        ({{ $video['mb'] }} MB) lalu putar dari perangkat Anda.
                    </p>

                    {{-- Versi cetak: pemutar tidak bisa dicetak, jadi yang dicetak alamatnya. --}}
                    <p class="hidden print:block mt-2 text-sm text-gray-700">
                        Video panduan: {{ url(\App\Http\Controllers\PanduanController::VIDEO) }}
                    </p>
                </section>
            @endif

            {{-- ---------------------- 1. SEBELUM MULAI ---------------------- --}}
            <section id="sebelum-mulai" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">1. Sebelum mulai</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Anda memerlukan akun. Buka halaman
                    <a href="{{ route('register') }}" class="text-blue-600 font-medium hover:underline">Daftar</a>,
                    isi nama, email, dan kata sandi, lalu masuk. Setelah masuk, Anda berada di
                    <strong>Dashboard</strong>, dan seluruh menu ada pada bilah di sisi kiri layar.
                </p>

                <x-panduan-gambar src="01-dashboard.png"
                    alt="Dashboard setelah masuk, dengan bilah menu di sisi kiri"
                    caption="Bilah menu di kiri adalah jalan ke semua fitur." />

                <h3 class="mt-6 text-base font-semibold text-gray-900">Menu yang akan Anda pakai</h3>
                <dl class="mt-3 space-y-3">
                    @foreach($menu as $item)
                        <div class="flex gap-3">
                            <dt class="flex-shrink-0 w-44 text-sm font-semibold text-gray-900">{{ $item['nama'] }}</dt>
                            <dd class="text-sm text-gray-600">{{ $item['guna'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm text-amber-900">
                        <strong>Menu Training Model dan Evaluasi Model hanya muncul untuk administrator.</strong>
                        Keduanya alat pemantauan, dan aplikasi berjalan penuh tanpa membukanya.
                    </p>
                </div>
            </section>

            {{-- ---------------------- 2. MENYIAPKAN DATA -------------------- --}}
            <section id="menyiapkan-data" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">2. Menyiapkan data</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Ada tiga cara memasukkan teks, dan semuanya bermuara ke formulir yang sama.
                    Pilih yang paling dekat dengan data Anda.
                </p>

                <div class="mt-5 space-y-5">
                    @foreach($sumberData as $i => $sumber)
                        <div class="rounded-xl border border-gray-200 bg-white p-5">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 text-sm font-bold flex items-center justify-center">{{ chr(65 + $i) }}</span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-gray-900">{{ $sumber['judul'] }}</h3>
                                    <p class="mt-1 text-sm text-gray-600 leading-relaxed">{{ $sumber['isi'] }}</p>
                                    @if(! empty($sumber['catatan']))
                                        <p class="mt-2 text-sm text-gray-500"><strong>Perhatikan:</strong> {{ $sumber['catatan'] }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <h3 class="mt-8 text-base font-semibold text-gray-900">Syarat berkas</h3>
                <ul class="mt-3 space-y-2 text-sm text-gray-700">
                    <li class="flex gap-2"><span class="text-blue-500">&bull;</span> Format CSV, TXT, XLSX, atau XLS. Ukuran maksimal 10 MB.</li>
                    <li class="flex gap-2"><span class="text-blue-500">&bull;</span> Satu teks per baris. Untuk CSV dan Excel, tentukan kolom mana yang berisi teksnya.</li>
                    <li class="flex gap-2"><span class="text-blue-500">&bull;</span> Baris yang kosong atau hanya berisi emotikon otomatis dilewati, karena tidak ada yang bisa dinilai di sana.</li>
                </ul>
            </section>

            {{-- ---------------------- 3. MENJALANKAN ------------------------ --}}
            <section id="menjalankan-analisis" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">3. Menjalankan analisis</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Buka <strong>Analisis Baru</strong>, lalu isi formulirnya dari atas ke bawah.
                </p>

                <x-panduan-gambar src="02-analisis-baru.png"
                    alt="Formulir Analisis Baru dengan kolom judul, pilihan jenis analisis, dan tab sumber data"
                    caption="Formulir analisis baru." />

                <ol class="mt-5 space-y-5">
                    @foreach($langkahAnalisis as $i => $langkah)
                        <li class="flex gap-4">
                            <span class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-600 text-white text-sm font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                            <div class="min-w-0 pt-0.5">
                                <p class="font-semibold text-gray-900">{{ $langkah['judul'] }}</p>
                                <p class="mt-1 text-sm text-gray-600 leading-relaxed">{{ $langkah['isi'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                <h3 class="mt-8 text-base font-semibold text-gray-900">Memilih jenis analisis</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                        <thead class="bg-gray-50">
                            <tr class="text-left">
                                <th class="px-4 py-2.5 font-semibold text-gray-900">Jenis</th>
                                <th class="px-4 py-2.5 font-semibold text-gray-900">Menjawab pertanyaan</th>
                                <th class="px-4 py-2.5 font-semibold text-gray-900">Pilih bila</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach($jenisAnalisis as $jenis)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900 align-top whitespace-nowrap">{{ $jenis['nama'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 align-top">{{ $jenis['pertanyaan'] }}</td>
                                    <td class="px-4 py-3 text-gray-600 align-top">{{ $jenis['kapan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 rounded-lg border border-blue-200 bg-blue-50 p-4">
                    <p class="text-sm text-blue-900">
                        <strong>Bingung memilih?</strong> Pakai <em>Analisis Gabungan</em>. Ia menjalankan
                        ketiganya sekaligus, dan Anda bisa mengabaikan bagian yang tidak diperlukan.
                    </p>
                </div>
            </section>

            {{-- ---------------------- 4. MENUNGGU --------------------------- --}}
            <section id="menunggu-proses" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">4. Menunggu proses selesai</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Setelah menekan <strong>Jalankan Analisis</strong>, Anda dibawa ke halaman hasil
                    yang menampilkan bilah kemajuan. Analisis berjalan di latar belakang, jadi
                    halaman ini boleh ditutup: kemajuannya tetap berlanjut, dan hasilnya bisa
                    dibuka lagi lewat <strong>Riwayat Analisis</strong>.
                </p>

                <x-panduan-gambar src="03-proses.png"
                    alt="Halaman hasil menampilkan bilah kemajuan dengan status sedang diproses"
                    caption="Bilah kemajuan memperbarui dirinya sendiri setiap beberapa detik." />

                <p class="mt-4 text-gray-700 leading-relaxed">
                    Lamanya bergantung pada jumlah teks. Sebagai gambaran kasar, seribu teks
                    memakan sekitar lima menit. Korpus besar bisa memakan puluhan menit, dan itu
                    wajar.
                </p>
            </section>

            {{-- ---------------------- 5. MEMBACA HASIL ---------------------- --}}
            <section id="membaca-hasil" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">5. Membaca hasil</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Halaman hasil panjang, dan itu disengaja. Gunakan bilah tautan di bagian atas
                    untuk melompat antar bagian. Urutan membacanya dari atas ke bawah sudah
                    disusun mengikuti urutan pertanyaan yang biasanya muncul.
                </p>

                <x-panduan-gambar src="04-hasil.png"
                    alt="Halaman hasil analisis dengan ringkasan, grafik sentimen, dan daftar prediksi"
                    caption="Halaman hasil analisis." />

                <div class="mt-5 space-y-4">
                    @foreach($bagianHasil as $bagian)
                        <div class="rounded-xl border border-gray-200 bg-white p-5">
                            <h3 class="font-semibold text-gray-900">{{ $bagian['nama'] }}</h3>
                            <p class="mt-1.5 text-sm text-gray-600 leading-relaxed">{{ $bagian['isi'] }}</p>
                        </div>
                    @endforeach
                </div>

                <h3 class="mt-8 text-base font-semibold text-gray-900">Tombol "Jelaskan dengan AI"</h3>
                <p class="mt-2 text-gray-700 leading-relaxed">
                    Di beberapa bagian ada tombol ini. Menekannya menghasilkan penjelasan dalam
                    kalimat biasa, lengkap dengan <strong>mengapa bagian itu penting</strong> dan
                    <strong>langkah yang bisa Anda ambil</strong>. Angkanya tetap berasal dari
                    hasil analisis, bukan dikarang AI. Penjelasan ini tambahan: seluruh isi
                    halaman tetap lengkap tanpa menekannya.
                </p>

                <x-panduan-gambar src="05-penjelasan-ai.png"
                    alt="Blok penjelasan AI berisi narasi, poin temuan, kotak mengapa ini penting, dan daftar langkah"
                    caption="Penjelasan AI menjawab tiga hal: apa isinya, mengapa penting, dan apa langkah berikutnya." />
            </section>

            {{-- ---------------------- 6. MENGOREKSI ------------------------- --}}
            <section id="mengoreksi-hasil" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">6. Memperbaiki hasil yang keliru</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Tidak ada model yang benar seratus persen. Bila Anda menemukan penilaian yang
                    salah, perbaiki saja: koreksi Anda dipakai melatih ulang model, sehingga
                    analisis berikutnya lebih tepat. Inilah bagian yang membedakan aplikasi ini
                    dari alat analisis sekali jalan.
                </p>

                <ol class="mt-5 space-y-5">
                    @foreach($langkahKoreksi as $i => $langkah)
                        <li class="flex gap-4">
                            <span class="flex-shrink-0 w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                            <div class="min-w-0 pt-0.5">
                                <p class="font-semibold text-gray-900">{{ $langkah['judul'] }}</p>
                                <p class="mt-1 text-sm text-gray-600 leading-relaxed">{{ $langkah['isi'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                <x-panduan-gambar src="06-koreksi.png"
                    alt="Halaman koreksi dengan daftar teks, pilihan sentimen, dan kolom aspek"
                    caption="Halaman koreksi, disaring pada baris yang model sendiri tidak yakin." />

                <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm text-emerald-900">
                        <strong>Melewati baris itu wajar.</strong> Bila Anda ragu pada sebuah teks,
                        biarkan saja kosong. Baris yang tidak Anda tinjau tidak ikut tersimpan, dan
                        itu memang yang diinginkan: tebakan yang asal justru merusak model.
                    </p>
                </div>
            </section>

            {{-- ---------------------- 7. MENGEKSPOR ------------------------- --}}
            <section id="mengekspor" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">7. Menyimpan dan membagikan hasil</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Di bagian atas halaman hasil ada dua tombol ekspor.
                </p>

                <dl class="mt-4 space-y-3">
                    <div class="flex gap-3">
                        <dt class="flex-shrink-0 w-28 text-sm font-semibold text-gray-900">Export PDF</dt>
                        <dd class="text-sm text-gray-600">Laporan siap cetak berisi ringkasan, grafik, dan penjelasan AI bila sudah dibangkitkan. Cocok untuk lampiran.</dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="flex-shrink-0 w-28 text-sm font-semibold text-gray-900">Export CSV</dt>
                        <dd class="text-sm text-gray-600">Data mentah per baris beserta labelnya, untuk diolah lagi di Excel atau alat statistik.</dd>
                    </div>
                </dl>
            </section>

            {{-- ---------------------- 8. ISTILAH ---------------------------- --}}
            <section id="istilah" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">8. Istilah yang sering muncul</h2>

                <dl class="mt-4 divide-y divide-gray-200 border-t border-b border-gray-200">
                    @foreach($istilah as $kata)
                        <div class="py-4 sm:grid sm:grid-cols-[12rem_1fr] sm:gap-4">
                            <dt class="text-sm font-semibold text-gray-900">{{ $kata['istilah'] }}</dt>
                            <dd class="mt-1 sm:mt-0 text-sm text-gray-600 leading-relaxed">{{ $kata['arti'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            {{-- ---------------------- 9. MASALAH UMUM ----------------------- --}}
            <section id="masalah-umum" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">9. Bila ada yang tidak berjalan</h2>

                <div class="mt-4 space-y-3">
                    @foreach($masalah as $m)
                        <details class="group rounded-xl border border-gray-200 bg-white p-5">
                            <summary class="flex items-center justify-between gap-3 cursor-pointer list-none font-medium text-gray-900">
                                <span>{{ $m['gejala'] }}</span>
                                <svg class="w-5 h-5 text-gray-400 flex-shrink-0 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </summary>
                            <p class="mt-3 text-sm text-gray-600 leading-relaxed">{{ $m['jawaban'] }}</p>
                        </details>
                    @endforeach
                </div>
            </section>

            {{-- ---------------------- 10. LATIHAN --------------------------- --}}
            <section id="skenario-latihan" class="langkah scroll-mt-24 mb-12">
                <h2 class="text-2xl font-bold tracking-tight">10. Skenario latihan</h2>

                <p class="mt-3 text-gray-700 leading-relaxed">
                    Kerjakan enam tugas berikut berurutan. Seluruhnya menyentuh alur utama
                    aplikasi, dan bila semuanya selesai berarti Anda sudah memakai setiap
                    fitur pokok setidaknya sekali.
                </p>

                <ol class="mt-5 space-y-3">
                    @foreach($skenario as $i => $tugas)
                        <li class="rounded-xl border border-gray-200 bg-white p-5">
                            <div class="flex gap-4">
                                <span class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-900 text-white text-sm font-bold flex items-center justify-center">{{ $i + 1 }}</span>
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900">{{ $tugas['tugas'] }}</p>
                                    <p class="mt-1 text-sm text-gray-500">
                                        <strong>Selesai bila:</strong> {{ $tugas['selesai'] }}
                                    </p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-6 rounded-xl border border-gray-300 bg-white p-5">
                    <p class="text-sm text-gray-700 leading-relaxed">
                        Setelah keenam tugas selesai, Anda akan diminta mengisi kuesioner penilaian
                        kemudahan penggunaan. Isilah berdasarkan pengalaman Anda barusan, bukan
                        berdasarkan pedoman ini. Tidak ada jawaban benar atau salah, dan penilaian
                        yang jujur justru yang paling berguna.
                    </p>
                </div>
            </section>

            <hr class="my-10 border-gray-200">

            <p class="text-sm text-gray-500">
                Masih ada yang membingungkan setelah membaca pedoman ini? Bagian yang membuat Anda
                berhenti adalah masukan paling berharga. Catat di kuesioner, atau sampaikan kepada
                pendamping uji coba.
            </p>
        </main>
    </div>
</div>

</body>
</html>
