<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Platform analisis teks otomatis berbasis Machine Learning dan Natural Language Processing untuk analisis sentimen, ekstraksi aspek, dan identifikasi topik.">
    <meta name="keywords" content="analisis teks, NLP, machine learning, sentiment analysis, text mining, Indonesia">
    <meta name="author" content="Iman Alifa Novansyah">
    <title>AnalisisTeks: Analisis Sentimen, Aspek, dan Topik Bahasa Indonesia</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />
    
    {{-- Hanya CSS. app.js (Alpine, WordCloud, axios; ~92 KB) tidak dipakai
         halaman ini sama sekali, dan pustaka animasi AOS dari unpkg diganti
         skrip kecil di bawah. Dulu stylesheet-nya memblokir render dari
         server luar. --}}
    @vite(['resources/css/app.css'])
    
    <!-- Favicon -->
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    
    {{-- Dulu halaman ini memuat tiga "blob" 300-400px dengan filter blur(70px)
         yang dianimasikan tanpa henti dan digeser lagi pada setiap event scroll.
         Blur sebesar itu digambar ulang tiap frame, dan itulah sumber utama
         halaman terasa berat. Kini latarnya satu gradien radial statis: tampak
         nyaris sama, biaya gambarnya nol setelah render pertama. --}}
    <style>
        .gradient-bg { background: linear-gradient(135deg, #0EA5E9 0%, #06B6D4 100%); }

        .gradient-text {
            background: linear-gradient(135deg, #0EA5E9 0%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-glow {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(28rem 22rem at 5% 10%, rgba(147, 197, 253, .55), transparent 70%),
                radial-gradient(32rem 26rem at 100% 35%, rgba(103, 232, 249, .45), transparent 70%),
                radial-gradient(26rem 20rem at 40% 100%, rgba(125, 211, 252, .40), transparent 70%);
        }

        .hover-lift { transition: transform .3s ease, box-shadow .3s ease; }
        .hover-lift:hover { transform: translateY(-6px); box-shadow: 0 20px 40px rgba(0, 0, 0, .12); }

        /* Tanpa backdrop-filter: pada latar 98% buram hasilnya tidak terlihat,
           tetapi biayanya dibayar di setiap frame scroll. */
        nav { transition: background-color .3s ease, box-shadow .3s ease; }
        nav.scrolled { background: rgba(255, 255, 255, .98); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, .1); }

        /* Hanya dua kartu kecil yang melayang; kartu besar diam. */
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
        .float-soft { animation: float 4s ease-in-out infinite; will-change: transform; }

        /* Pengganti AOS. Hanya berlaku bila JavaScript jalan (kelas .js dipasang
           di <html>), jadi tanpa JavaScript isinya tetap terlihat. */
        .js [data-aos] { opacity: 0; transition: opacity .6s ease, transform .6s ease; }
        .js [data-aos="fade-up"] { transform: translateY(24px); }
        .js [data-aos="fade-right"] { transform: translateX(-24px); }
        .js [data-aos="fade-left"] { transform: translateX(24px); }
        .js [data-aos].aos-animate { opacity: 1; transform: none; }

        .counter { font-variant-numeric: tabular-nums; }

        @media (prefers-reduced-motion: reduce) {
            .float-soft { animation: none; }
            .js [data-aos] { opacity: 1; transform: none; transition: none; }
            html { scroll-behavior: auto; }
        }
    </style>
    <script>document.documentElement.classList.add('js');</script>
</head>
<body class="font-sans antialiased bg-white text-gray-900">
    
    <!-- ============================================
         NAVIGATION BAR
         ============================================ -->
    <nav id="navbar" class="fixed w-full z-50 top-0 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <!-- Logo -->
                <div class="flex-shrink-0 flex items-center">
                    <a href="/" class="flex items-center space-x-3 group">
                        <x-logo-mark class="w-10 h-10 transform group-hover:scale-110 transition-transform duration-300" />
                        <span class="text-xl font-bold text-gray-900 hidden sm:inline">
                            Analisis<span class="gradient-text">Teks</span>
                        </span>
                    </a>
                </div>
                
                <!-- Menu Desktop -->
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-gray-700 hover:text-blue-600 transition font-medium">Fitur</a>
                    <a href="#how-it-works" class="text-gray-700 hover:text-blue-600 transition font-medium">Cara Kerja</a>
                    @if (count($pengukuran))
                    <a href="#hasil-uji" class="text-gray-700 hover:text-blue-600 transition font-medium">Hasil Uji</a>
                    @endif
                    <a href="#about" class="text-gray-700 hover:text-blue-600 transition font-medium">Tentang</a>
                    <a href="{{ route('panduan') }}" class="text-gray-700 hover:text-blue-600 transition font-medium">Pedoman</a>
                    
                    @auth
                        <a href="{{ route('dashboard') }}" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-lg font-semibold hover:shadow-lg transform hover:scale-105 transition-all duration-300">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-700 hover:text-blue-600 transition font-medium">Login</a>
                        <a href="{{ route('register') }}" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-lg font-semibold hover:shadow-lg transform hover:scale-105 transition-all duration-300">
                            Daftar Gratis
                        </a>
                    @endauth
                </div>
                
                <!-- Mobile Menu Button -->
                <div class="md:hidden">
                    <button id="mobile-menu-button" class="text-gray-700 hover:text-blue-600 focus:outline-none">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-t border-gray-200 shadow-lg">
            <div class="px-4 pt-2 pb-3 space-y-1">
                <a href="#features" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Fitur</a>
                <a href="#how-it-works" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Cara Kerja</a>
                @if (count($pengukuran))
                <a href="#hasil-uji" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Hasil Uji</a>
                @endif
                <a href="{{ route('panduan') }}" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Pedoman</a>
                <a href="#about" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Tentang</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="block px-3 py-2 bg-blue-600 text-white rounded-md text-center font-semibold">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Login</a>
                    <a href="{{ route('register') }}" class="block px-3 py-2 bg-blue-600 text-white rounded-md text-center font-semibold">Daftar Gratis</a>
                @endauth
            </div>
        </div>
    </nav>
    
    <!-- ============================================
         HERO SECTION
         ============================================ -->
    <section class="relative pt-32 pb-20 px-4 sm:px-6 lg:px-8 overflow-hidden">
        <div class="hero-glow" aria-hidden="true"></div>
        
        <div class="max-w-7xl mx-auto relative z-10">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div data-aos="fade-right">
                    <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-700 rounded-full text-sm font-medium mb-6">
                        <span class="w-2 h-2 bg-blue-600 rounded-full mr-2 animate-pulse"></span>
                        Powered by Machine Learning & NLP
                    </div>
                    
                    <h1 class="text-5xl lg:text-6xl font-extrabold leading-tight mb-6">
                        Analisis Teks <br/>
                        <span class="gradient-text">Otomatis & Cerdas</span>
                    </h1>
                    
                    <p class="text-xl text-gray-600 mb-8 leading-relaxed">
                        Platform berbasis AI untuk menganalisis sentimen, mengekstraksi aspek, dan mengidentifikasi topik dari data teks berbahasa Indonesia. Cocok untuk riset, survei, dan analisis opini publik.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 mb-8">
                        @auth
                            <a href="{{ route('dashboard') }}" class="px-8 py-4 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-xl font-semibold text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300 text-center">
                                Buka Dashboard
                                <span class="ml-2">→</span>
                            </a>
                        @else
                            <a href="{{ route('register') }}" class="px-8 py-4 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-xl font-semibold text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300 text-center">
                                Mulai Gratis
                                <span class="ml-2">→</span>
                            </a>
                        @endauth
                        
                        <a href="#features" class="px-8 py-4 bg-white border-2 border-gray-300 text-gray-700 rounded-xl font-semibold text-lg hover:border-blue-600 hover:text-blue-600 transition-all duration-300 text-center">
                            Pelajari Lebih Lanjut
                        </a>
                    </div>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                        @foreach($stats as $index => $stat)
                        <div class="text-center" data-aos="fade-up" data-aos-delay="{{ $index * 100 }}">
                            <div class="text-3xl font-bold gradient-text counter">{{ $stat['number'] }}</div>
                            <div class="text-sm text-gray-600 mt-1">{{ $stat['label'] }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
                
                <!-- Right Image/Illustration -->
                <div data-aos="fade-left" class="hidden lg:block">
                    <div class="relative">
                        <div class="absolute inset-0 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-3xl transform rotate-6 opacity-20"></div>
                        <div class="relative bg-white rounded-3xl shadow-2xl p-8">
                            {{-- Pratinjau halaman hasil, digambar langsung dengan HTML/SVG.
                                 Dulu foto stok dari Unsplash: bergantung pada layanan luar,
                                 kosong saat gagal dimuat, dan tidak memperlihatkan aplikasi
                                 ini sama sekali. Angkanya contoh dan diberi label begitu,
                                 supaya tidak terbaca sebagai hasil pengukuran. --}}
                            <figure class="rounded-2xl bg-gray-50 border border-gray-100 p-5 select-none" aria-label="Contoh tampilan halaman hasil analisis">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-2">
                                        <x-logo-mark class="w-7 h-7" />
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900 leading-tight">Komentar Layanan Publik</div>
                                            <div class="text-[11px] text-gray-500">Analisis gabungan &middot; 1.200 teks</div>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 bg-white border border-gray-200 rounded-full px-2 py-0.5">Contoh</span>
                                </div>

                                <div class="grid grid-cols-5 gap-3 mb-3">
                                    {{-- Distribusi sentimen --}}
                                    <div class="col-span-2 bg-white rounded-xl border border-gray-100 p-3">
                                        <div class="text-[11px] font-medium text-gray-500 mb-2">Sentimen</div>
                                        <div class="relative w-20 h-20 mx-auto">
                                            <svg viewBox="0 0 36 36" class="w-full h-full -rotate-90" aria-hidden="true">
                                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#10B981" stroke-width="5" stroke-dasharray="52 48"/>
                                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#F59E0B" stroke-width="5" stroke-dasharray="30 70" stroke-dashoffset="-52"/>
                                                <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#F43F5E" stroke-width="5" stroke-dasharray="18 82" stroke-dashoffset="-82"/>
                                            </svg>
                                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                                <span class="text-base font-bold text-gray-900 leading-none">52%</span>
                                                <span class="text-[9px] text-gray-500">positif</span>
                                            </div>
                                        </div>
                                        <div class="mt-2 space-y-0.5 text-[10px] text-gray-600">
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Positif 52%</div>
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span>Netral 30%</div>
                                            <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-rose-500"></span>Negatif 18%</div>
                                        </div>
                                    </div>

                                    {{-- Sentimen per aspek --}}
                                    <div class="col-span-3 bg-white rounded-xl border border-gray-100 p-3">
                                        <div class="text-[11px] font-medium text-gray-500 mb-2">Sentimen per aspek</div>
                                        <div class="space-y-2.5">
                                            @foreach ([['pelayanan', 64, 22, 14], ['kinerja', 48, 30, 22], ['anggaran', 21, 34, 45], ['informasi', 55, 35, 10]] as [$aspek, $pos, $net, $neg])
                                                <div>
                                                    <div class="text-[10px] text-gray-700 mb-0.5">{{ $aspek }}</div>
                                                    <div class="flex h-2 rounded-full overflow-hidden bg-gray-100">
                                                        <div class="bg-emerald-500" style="width: {{ $pos }}%"></div>
                                                        <div class="bg-amber-400" style="width: {{ $net }}%"></div>
                                                        <div class="bg-rose-500" style="width: {{ $neg }}%"></div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                {{-- Contoh prediksi per komentar --}}
                                <div class="space-y-2">
                                    <div class="bg-white rounded-xl border border-gray-100 px-3 py-2 flex items-start gap-2">
                                        <span class="mt-0.5 shrink-0 text-[10px] font-semibold text-emerald-700 bg-emerald-50 rounded px-1.5 py-0.5">Positif</span>
                                        <p class="text-[11px] text-gray-700 leading-snug flex-1">Pelayanannya sekarang cepat, antrean juga lebih tertib.</p>
                                        <span class="text-[10px] text-gray-400 shrink-0">0,97</span>
                                    </div>
                                    <div class="bg-white rounded-xl border border-gray-100 px-3 py-2 flex items-start gap-2">
                                        <span class="mt-0.5 shrink-0 text-[10px] font-semibold text-rose-700 bg-rose-50 rounded px-1.5 py-0.5">Negatif</span>
                                        <p class="text-[11px] text-gray-700 leading-snug flex-1">Anggarannya besar tapi hasilnya belum terasa di daerah.</p>
                                        <span class="text-[10px] text-gray-400 shrink-0">0,93</span>
                                    </div>
                                </div>
                            </figure>
                            
                            @if (($stats[0]['label'] ?? null) === 'Akurasi sentimen')
                            <!-- Floating Card 1 -->
                            <div class="absolute -top-6 -right-6 bg-white rounded-xl shadow-xl p-4 float-soft">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                        <span class="text-2xl">✅</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">{{ $stats[0]['number'] }} Akurasi</div>
                                        <div class="text-xs text-gray-500">Diuji pada test set berlabel</div>
                                    </div>
                                </div>
                            </div>
                            @endif

                            <!-- Floating Card 2 -->
                            <div class="absolute -bottom-16 -left-6 bg-white rounded-xl shadow-xl p-4 float-soft" style="animation-delay: -2s;">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <span class="text-2xl">⚡</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Berjalan di Latar</div>
                                        <div class="text-xs text-gray-500">Ribuan teks sekali jalan</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         FEATURES SECTION
         ============================================ -->
    <section id="features" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16" data-aos="fade-up">
                <h2 class="text-4xl font-extrabold text-gray-900 mb-4">
                    Fitur <span class="gradient-text">Unggulan</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    Sistem analisis teks lengkap dengan teknologi machine learning dan natural language processing terkini
                </p>
            </div>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($features as $index => $feature)
                <div class="feature-card bg-white rounded-2xl p-8 shadow-lg hover-lift" data-aos="fade-up" data-aos-delay="{{ $index * 100 }}">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl flex items-center justify-center text-4xl mb-6 transform hover:rotate-12 transition-transform duration-300">
                        {{ $feature['icon'] }}
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">{{ $feature['title'] }}</h3>
                    <p class="text-gray-600 leading-relaxed">{{ $feature['description'] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>
    
    <!-- ============================================
         HOW IT WORKS SECTION
         ============================================ -->
    <section id="how-it-works" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16" data-aos="fade-up">
                <h2 class="text-4xl font-extrabold text-gray-900 mb-4">
                    Cara <span class="gradient-text">Kerja</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    Proses analisis teks yang sederhana dan efisien dalam 4 langkah mudah
                </p>
            </div>
            
            <div class="grid md:grid-cols-4 gap-8">
                @php
                $steps = [
                    [
                        'number' => '01',
                        'title' => 'Upload Data',
                        'description' => 'Upload file CSV, TXT, XLSX atau input teks langsung melalui form yang user-friendly',
                        'icon' => '📁'
                    ],
                    [
                        'number' => '02',
                        'title' => 'Preprocessing',
                        'description' => 'Sistem membersihkan dan memproses teks secara otomatis dengan NLP techniques',
                        'icon' => '🔧'
                    ],
                    [
                        'number' => '03',
                        'title' => 'Analisis AI',
                        'description' => 'Model Machine Learning menganalisis sentimen, aspek, dan topik dengan akurat',
                        'icon' => '🤖'
                    ],
                    [
                        'number' => '04',
                        'title' => 'Hasil & Laporan',
                        'description' => 'Dapatkan visualisasi interaktif dan laporan komprehensif yang siap digunakan',
                        'icon' => '📊'
                    ],
                ];
                @endphp
                
                @foreach($steps as $index => $step)
                <div class="relative" data-aos="fade-up" data-aos-delay="{{ $index * 100 }}">
                    @if($index < count($steps) - 1)
                    <div class="hidden md:block absolute top-16 left-full w-full h-0.5 bg-gradient-to-r from-blue-600 to-purple-400"></div>
                    @endif
                    
                    <div class="text-center">
                        <div class="relative w-32 h-32 mx-auto mb-6">
                            <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-3xl transform hover:scale-110 hover:rotate-6 transition-all duration-300 flex items-center justify-center">
                                <span class="text-5xl font-bold text-white">{{ $step['number'] }}</span>
                            </div>
                        </div>
                        <div class="text-4xl mb-4">{{ $step['icon'] }}</div>
                        <h3 class="text-xl font-bold text-gray-900 mb-3">{{ $step['title'] }}</h3>
                        <p class="text-gray-600">{{ $step['description'] }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>
    
    <!-- ============================================
         HASIL PENGUKURAN
         ============================================ -->
    @if (count($pengukuran))
    <section id="hasil-uji" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16" data-aos="fade-up">
                <h2 class="text-4xl font-extrabold text-gray-900 mb-4">
                    Hasil <span class="gradient-text">Pengukuran</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    Angka di bawah ini berasal dari pengujian pada komentar yang dilabeli manusia
                    dan sengaja disisihkan dari data training, bukan dari data yang dipakai melatih model.
                </p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                @foreach($pengukuran as $index => $ukur)
                <div class="bg-white rounded-2xl p-8 shadow-lg hover-lift" data-aos="fade-up" data-aos-delay="{{ $index * 100 }}">
                    <p class="text-4xl font-extrabold gradient-text">{{ $ukur['angka'] }}</p>
                    <h3 class="mt-3 text-lg font-bold text-gray-900">{{ $ukur['judul'] }}</h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ $ukur['dasar'] }}</p>
                </div>
                @endforeach
            </div>

            {{-- Batasan disebut di halaman depan, bukan disembunyikan di naskah.
                 Pembaca yang melihat angka akurasi berhak tahu di korpus apa angka
                 itu berlaku sebelum menyimpulkan berlaku juga untuk datanya. --}}
            <p class="mt-10 text-center text-sm text-gray-500 max-w-3xl mx-auto">
                Pengukuran dilakukan pada korpus komentar YouTube berbahasa Indonesia.
                Ketepatan pada jenis teks yang berbeda bisa berbeda pula, jadi gunakan fitur koreksi
                untuk menyesuaikan model dengan data Anda sendiri.
                @if ($diukurPada)
                    Pengukuran terakhir: {{ $diukurPada }}.
                @endif
            </p>
        </div>
    </section>
    @endif

    <!-- ============================================
         CTA SECTION
         ============================================ -->
    <section class="py-20 gradient-bg relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-64 h-64 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
            <div class="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/2 translate-y-1/2"></div>
            <div class="absolute top-1/2 left-1/2 w-80 h-80 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
        </div>
        
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10" data-aos="fade-up">
            <h2 class="text-4xl md:text-5xl font-extrabold text-white mb-6">
                Siap Memulai Analisis Teks Anda?
            </h2>
            <p class="text-xl text-white/90 mb-10 max-w-2xl mx-auto">
                Upload data Anda, jalankan analisis, lalu perbaiki hasilnya bila ada yang keliru.
                Setiap koreksi ikut melatih model pada putaran berikutnya.
            </p>
            
            @auth
                <a href="{{ route('dashboard') }}" class="inline-block px-10 py-5 bg-white text-blue-600 rounded-xl font-bold text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300">
                    Buka Dashboard Sekarang
                    <span class="ml-2">→</span>
                </a>
            @else
                <a href="{{ route('register') }}" class="inline-block px-10 py-5 bg-white text-blue-600 rounded-xl font-bold text-lg hover:shadow-2xl hover:scale-105 transition-all duration-300">
                    Daftar Gratis Sekarang
                    <span class="ml-2">→</span>
                </a>
            @endauth
            
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-6 text-white/80 text-sm">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Tidak perlu kartu kredit
                </div>
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Gratis selamanya
                </div>
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Setup dalam 2 menit
                </div>
            </div>
        </div>
    </section>
    
    <!-- ============================================
         FOOTER
         ============================================ -->
    <footer id="about" class="bg-gray-900 text-gray-300 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-4 gap-8 mb-8">
                <!-- Brand -->
                <div class="md:col-span-2">
                    <div class="flex items-center space-x-3 mb-4">
                        <x-logo-mark class="w-10 h-10" />
                        <span class="text-xl font-bold text-white">Analisis<span class="gradient-text">Teks</span></span>
                    </div>
                    <p class="text-gray-400 mb-4 max-w-md leading-relaxed">
                        Platform analisis teks otomatis berbasis Machine Learning dan Natural Language Processing untuk mendukung transformasi digital dan modernisasi statistik di Indonesia.
                    </p>
                    <div class="flex space-x-4">
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-blue-600 transition-colors duration-300" aria-label="Facebook">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-blue-600 transition-colors duration-300" aria-label="Twitter">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                            </svg>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-blue-600 transition-colors duration-300" aria-label="Instagram">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                            </svg>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-800 rounded-lg flex items-center justify-center hover:bg-blue-600 transition-colors duration-300" aria-label="GitHub">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/>
                            </svg>
                        </a>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div>
                    <h3 class="text-white font-bold mb-4">Quick Links</h3>
                    <ul class="space-y-2">
                        <li><a href="#features" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Fitur
                        </a></li>
                        <li><a href="#how-it-works" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Cara Kerja
                        </a></li>
                        @if (count($pengukuran))
                        <li><a href="#hasil-uji" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Hasil Uji
                        </a></li>
                        @endif
                        <li><a href="{{ route('panduan') }}" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Pedoman Penggunaan
                        </a></li>
                        @guest
                        <li><a href="{{ route('login') }}" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Login
                        </a></li>
                        <li><a href="{{ route('register') }}" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Daftar
                        </a></li>
                        @else
                        <li><a href="{{ route('dashboard') }}" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Dashboard
                        </a></li>
                        @endguest
                    </ul>
                </div>
                
                <!-- Contact -->
                <div>
                    <h3 class="text-white font-bold mb-4">Kontak</h3>
                    <ul class="space-y-3">
                        <li class="flex items-start space-x-3 hover:text-blue-400 transition-colors duration-300">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span>info@analisisteks.id</span>
                        </li>
                        <li class="flex items-start space-x-3 hover:text-blue-400 transition-colors duration-300">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <span>+62 812-3456-7890</span>
                        </li>
                        <li class="flex items-start space-x-3 hover:text-blue-400 transition-colors duration-300">
                            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span>Jakarta, Indonesia</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="border-t border-gray-800 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                    <p class="text-gray-400 text-sm text-center md:text-left">
                        © 2025 <span class="font-semibold">Analisis Teks</span>. Dikembangkan oleh <span class="text-blue-400">Iman Alifa Novansyah</span> untuk skripsi Program Studi D-IV Komputasi Statistik.
                    </p>
                    <div class="flex space-x-6 text-sm">
                        <a href="#" class="hover:text-blue-400 transition-colors duration-300">Privacy Policy</a>
                        <a href="#" class="hover:text-blue-400 transition-colors duration-300">Terms of Service</a>
                        <a href="#" class="hover:text-blue-400 transition-colors duration-300">Documentation</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Scroll to Top Button -->
    <button id="scroll-to-top" class="fixed bottom-8 right-8 w-12 h-12 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-full shadow-lg opacity-0 invisible transition-all duration-300 hover:scale-110 z-40 flex items-center justify-center">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
        </svg>
    </button>
    
    <!-- ============================================
         JAVASCRIPT
         ============================================ -->
    
    <script>
    (() => {
        // Satu skrip kecil menggantikan AOS dan empat listener scroll. Yang dulu
        // dihitung di tiap event scroll (posisi setiap section, parallax blob)
        // kini dikerjakan IntersectionObserver, di luar jalur scroll.
        const navbar = document.getElementById('navbar');
        const keAtas = document.getElementById('scroll-to-top');
        const menu = document.getElementById('mobile-menu');
        const tombolMenu = document.getElementById('mobile-menu-button');

        // --- Scroll: satu listener pasif, paling banyak sekali per frame ---
        let menunggu = false;
        const perbarui = () => {
            const y = window.scrollY;
            navbar.classList.toggle('scrolled', y > 50);
            const tampil = y > 300;
            keAtas.classList.toggle('opacity-0', !tampil);
            keAtas.classList.toggle('invisible', !tampil);
            keAtas.classList.toggle('opacity-100', tampil);
            menunggu = false;
        };
        window.addEventListener('scroll', () => {
            if (!menunggu) {
                menunggu = true;
                requestAnimationFrame(perbarui);
            }
        }, { passive: true });
        perbarui();

        keAtas.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

        // --- Menu seluler ---
        tombolMenu.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!menu.contains(e.target)) menu.classList.add('hidden');
        });

        // --- Tautan jangkar: geser 80px untuk bilah navigasi tetap ---
        document.querySelectorAll('a[href^="#"]').forEach((tautan) => {
            tautan.addEventListener('click', (e) => {
                const href = tautan.getAttribute('href');
                const sasaran = href.length > 1 ? document.querySelector(href) : null;
                if (!sasaran) return;
                e.preventDefault();
                window.scrollTo({ top: sasaran.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
                menu.classList.add('hidden');
            });
        });

        const adaObserver = 'IntersectionObserver' in window;
        const kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // --- Animasi muncul (pengganti AOS) ---
        const muncul = document.querySelectorAll('[data-aos]');
        if (!adaObserver || kurangiGerak) {
            muncul.forEach((el) => el.classList.add('aos-animate'));
        } else {
            const pengamat = new IntersectionObserver((entri) => {
                entri.forEach((e) => {
                    if (!e.isIntersecting) return;
                    e.target.style.transitionDelay = parseInt(e.target.dataset.aosDelay || '0', 10) + 'ms';
                    e.target.classList.add('aos-animate');
                    pengamat.unobserve(e.target);
                });
            }, { rootMargin: '0px 0px -60px 0px' });
            muncul.forEach((el) => pengamat.observe(el));
        }

        // --- Penanda tautan navigasi aktif ---
        if (adaObserver) {
            const tautanNav = document.querySelectorAll('nav a[href^="#"]');
            const pengamatBagian = new IntersectionObserver((entri) => {
                entri.forEach((e) => {
                    if (!e.isIntersecting) return;
                    tautanNav.forEach((l) => {
                        const aktif = l.getAttribute('href') === '#' + e.target.id;
                        l.classList.toggle('text-blue-600', aktif);
                        l.classList.toggle('font-bold', aktif);
                    });
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            document.querySelectorAll('section[id], footer[id]').forEach((s) => pengamatBagian.observe(s));
        }

        // --- Angka berjalan ---
        // Angka ditulis dalam format Indonesia ("85,7%", "8.393"). Formatnya
        // dibaca, animasi memakai format yang sama, dan teks aslinya dipasang
        // kembali di akhir supaya angka yang terbaca selalu persis angka sumber.
        const jalankanAngka = (el) => {
            const asli = el.textContent.trim();
            const cocok = asli.match(/^(\D*)([\d.]+(?:,\d+)?)(.*)$/);
            if (!cocok) return;
            const [, awalan, angka, akhiran] = cocok;
            const desimal = (angka.split(',')[1] || '').length;
            const nilai = parseFloat(angka.replace(/\./g, '').replace(',', '.'));
            if (!isFinite(nilai)) return;
            const format = (v) => v.toLocaleString('id-ID', { minimumFractionDigits: desimal, maximumFractionDigits: desimal });
            const mulai = performance.now();
            const langkah = (kini) => {
                const p = Math.min((kini - mulai) / 1500, 1);
                el.textContent = p < 1 ? awalan + format(nilai * p) + akhiran : asli;
                if (p < 1) requestAnimationFrame(langkah);
            };
            requestAnimationFrame(langkah);
        };
        if (adaObserver && !kurangiGerak) {
            const pengamatAngka = new IntersectionObserver((entri) => {
                entri.forEach((e) => {
                    if (!e.isIntersecting) return;
                    jalankanAngka(e.target);
                    pengamatAngka.unobserve(e.target);
                });
            }, { threshold: 0.5 });
            document.querySelectorAll('.counter').forEach((el) => pengamatAngka.observe(el));
        }
    })();
    </script>
    
</body>
</html>