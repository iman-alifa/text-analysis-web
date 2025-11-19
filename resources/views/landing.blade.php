<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Platform analisis teks otomatis berbasis Machine Learning dan Natural Language Processing untuk analisis sentimen, ekstraksi aspek, dan identifikasi topik.">
    <meta name="keywords" content="analisis teks, NLP, machine learning, sentiment analysis, text mining, Indonesia">
    <meta name="author" content="Iman Alifa Novansyah">
    <title>Analisis Teks Otomatis - NLP & Machine Learning Platform</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900&display=swap" rel="stylesheet" />
    
    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
    
    <style>
        /* Custom Gradient Background */
        .gradient-bg {
            background: linear-gradient(135deg, #0EA5E9 0%, #06B6D4 100%); /* Sky Blue to Cyan */
        }
        
        .gradient-bg-2 {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .gradient-bg-3 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        /* Gradient Text */
        .gradient-text {
            background: linear-gradient(135deg, #0EA5E9 0%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Glass Effect */
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Hover Lift Effect */
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        /* Animated Blob */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            opacity: 0.7;
            animation: blob 7s infinite;
            z-index: 0;
        }
        
        @keyframes blob {
            0%, 100% { 
                transform: translate(0, 0) scale(1); 
            }
            33% { 
                transform: translate(30px, -50px) scale(1.1); 
            }
            66% { 
                transform: translate(-20px, 20px) scale(0.9); 
            }
        }
        
        /* Feature Card Shimmer Effect */
        .feature-card {
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .feature-card:hover::before {
            left: 100%;
        }
        
        /* Navbar Scroll Effect */
        nav {
            transition: all 0.3s ease;
        }
        
        nav.scrolled {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        /* Floating Animation */
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        /* Pulse Animation */
        @keyframes pulse-ring {
            0% {
                transform: scale(0.95);
                opacity: 1;
            }
            100% {
                transform: scale(1.05);
                opacity: 0;
            }
        }
        
        .pulse-ring {
            animation: pulse-ring 1.5s cubic-bezier(0.455, 0.03, 0.515, 0.955) infinite;
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #0EA5E9 0%, #06B6D4 100%);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #06B6D4 0%, #0EA5E9 100%);
        }
        
        /* Number Counter Animation */
        .counter {
            font-variant-numeric: tabular-nums;
        }
    </style>
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
                        <div class="w-10 h-10 gradient-bg rounded-lg flex items-center justify-center transform group-hover:scale-110 transition-transform duration-300">
                            <span class="text-white text-xl font-bold">AT</span>
                        </div>
                        <span class="text-xl font-bold text-gray-900 hidden sm:inline">
                            Analisis<span class="gradient-text">Teks</span>
                        </span>
                    </a>
                </div>
                
                <!-- Menu Desktop -->
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-gray-700 hover:text-blue-600 transition font-medium">Fitur</a>
                    <a href="#how-it-works" class="text-gray-700 hover:text-blue-600 transition font-medium">Cara Kerja</a>
                    <a href="#testimonials" class="text-gray-700 hover:text-blue-600 transition font-medium">Testimoni</a>
                    <a href="#about" class="text-gray-700 hover:text-blue-600 transition font-medium">Tentang</a>
                    
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
                <a href="#testimonials" class="block px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-blue-600 rounded-md transition">Testimoni</a>
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
        <!-- Background Blobs -->
        <div class="blob w-72 h-72 bg-blue-300 top-0 -left-20"></div>
        <div class="blob w-96 h-96 bg-cyan-300 top-40 -right-40" style="animation-delay: 2s;"></div>
        <div class="blob w-80 h-80 bg-sky-300 bottom-0 left-1/3" style="animation-delay: 4s;"></div>
        
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
                        Platform berbasis AI untuk menganalisis sentimen, mengekstraksi aspek, dan mengidentifikasi topik dari data teks dalam hitungan detik. Sempurna untuk riset, survei, dan analisis opini publik.
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
                    <div class="relative float-animation">
                        <div class="absolute inset-0 bg-gradient-to-r from-blue-500 to-cyan-600 rounded-3xl transform rotate-6 opacity-20"></div>
                        <div class="relative bg-white rounded-3xl shadow-2xl p-8">
                            <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800&h=600&fit=crop" 
                                 alt="Data Analysis Dashboard" 
                                 class="rounded-2xl w-full h-auto">
                            
                            <!-- Floating Card 1 -->
                            <div class="absolute -top-6 -right-6 bg-white rounded-xl shadow-xl p-4" style="animation: float 3s ease-in-out infinite;">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                        <span class="text-2xl">✅</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">99% Akurasi</div>
                                        <div class="text-xs text-gray-500">Model Tervalidasi</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Floating Card 2 -->
                            <div class="absolute -bottom-6 -left-6 bg-white rounded-xl shadow-xl p-4" style="animation: float 3s ease-in-out infinite; animation-delay: 1s;">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <span class="text-2xl">⚡</span>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900">Proses Cepat</div>
                                        <div class="text-xs text-gray-500">< 5 detik</div>
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
         TESTIMONIALS SECTION
         ============================================ -->
    <section id="testimonials" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16" data-aos="fade-up">
                <h2 class="text-4xl font-extrabold text-gray-900 mb-4">
                    Apa Kata <span class="gradient-text">Pengguna</span>
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    Dipercaya oleh peneliti, analis data, dan profesional di berbagai bidang
                </p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                @foreach($testimonials as $index => $testimonial)
                <div class="bg-white rounded-2xl p-8 shadow-lg hover-lift" data-aos="fade-up" data-aos-delay="{{ $index * 100 }}">
                    <div class="flex items-center mb-6">
                        <img src="{{ $testimonial['image'] }}" 
                             alt="{{ $testimonial['name'] }}" 
                             class="w-16 h-16 rounded-full mr-4 ring-4 ring-blue-100">
                        <div>
                            <h4 class="font-bold text-gray-900">{{ $testimonial['name'] }}</h4>
                            <p class="text-sm text-gray-600">{{ $testimonial['role'] }}</p>
                        </div>
                    </div>
                    
                    <div class="flex mb-4">
                        @for($i = 0; $i < 5; $i++)
                        <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        @endfor
                    </div>
                    
                    <p class="text-gray-600 italic leading-relaxed">"{{ $testimonial['comment'] }}"</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>
    
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
                Bergabunglah dengan ratusan pengguna yang telah memanfaatkan kekuatan AI untuk analisis teks. Mulai sekarang, gratis!
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
                        <div class="w-10 h-10 gradient-bg rounded-lg flex items-center justify-center">
                            <span class="text-white text-xl font-bold">AT</span>
                        </div>
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
                        <li><a href="#testimonials" class="hover:text-blue-400 transition-colors duration-300 flex items-center">
                            <span class="mr-2">→</span> Testimoni
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
    
    <!-- AOS Animation Script -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // ==========================================
        // Initialize AOS Animation
        // ==========================================
        AOS.init({
            duration: 800,
            once: true,
            offset: 100,
            easing: 'ease-in-out'
        });
        
        // ==========================================
        // Mobile Menu Toggle
        // ==========================================
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
        
        // ==========================================
        // Navbar Scroll Effect
        // ==========================================
        const navbar = document.getElementById('navbar');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
        
        // ==========================================
        // Smooth Scroll for Anchor Links
        // ==========================================
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                
                if (target) {
                    const offsetTop = target.offsetTop - 80;
                    
                    window.scrollTo({
                        top: offsetTop,
                        behavior: 'smooth'
                    });
                    
                    mobileMenu.classList.add('hidden');
                }
            });
        });
        
        // ==========================================
        // Scroll to Top Button
        // ==========================================
        const scrollToTopBtn = document.getElementById('scroll-to-top');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                scrollToTopBtn.classList.remove('opacity-0', 'invisible');
                scrollToTopBtn.classList.add('opacity-100', 'visible');
            } else {
                scrollToTopBtn.classList.add('opacity-0', 'invisible');
                scrollToTopBtn.classList.remove('opacity-100', 'visible');
            }
        });
        
        scrollToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // ==========================================
        // Number Counter Animation
        // ==========================================
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px 0px -100px 0px'
        };
        
        const counters = document.querySelectorAll('.counter');
        const animateCounter = (element) => {
            const target = element.textContent;
            const isPercent = target.includes('%');
            const isTime = target.includes('s');
            const isPlus = target.includes('+');
            
            let numericValue = parseInt(target.replace(/[^0-9]/g, ''));
            const duration = 2000;
            const increment = numericValue / (duration / 16);
            let current = 0;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= numericValue) {
                    current = numericValue;
                    clearInterval(timer);
                }
                
                let displayValue = Math.floor(current);
                if (isPlus) displayValue += 'K+';
                if (isPercent) displayValue += '%';
                if (isTime) displayValue = '<' + displayValue + 's';
                
                element.textContent = displayValue;
            }, 16);
        };
        
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    counterObserver.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        counters.forEach(counter => {
            counterObserver.observe(counter);
        });
        
        // ==========================================
        // Parallax Effect for Blobs
        // ==========================================
        window.addEventListener('scroll', () => {
            const blobs = document.querySelectorAll('.blob');
            const scrolled = window.pageYOffset;
            
            blobs.forEach((blob, index) => {
                const speed = 0.5 + (index * 0.1);
                blob.style.transform = `translateY(${scrolled * speed}px)`;
            });
        });
        
        // ==========================================
        // Add active state to navigation links
        // ==========================================
        const sections = document.querySelectorAll('section[id]');
        const navLinks = document.querySelectorAll('nav a[href^="#"]');
        
        window.addEventListener('scroll', () => {
            let current = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.clientHeight;
                
                if (window.pageYOffset >= sectionTop - 100) {
                    current = section.getAttribute('id');
                }
            });
            
            navLinks.forEach(link => {
                link.classList.remove('text-blue-600', 'font-bold');
                if (link.getAttribute('href') === `#${current}`) {
                    link.classList.add('text-blue-600', 'font-bold');
                }
            });
        });
        
        // ==========================================
        // Lazy Loading Images
        // ==========================================
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src || img.src;
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            document.querySelectorAll('img').forEach(img => {
                imageObserver.observe(img);
            });
        }
        
        // ==========================================
        // Close mobile menu when clicking outside
        // ==========================================
        document.addEventListener('click', (e) => {
            if (!mobileMenuButton.contains(e.target) && !mobileMenu.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });
        
        // ==========================================
        // Console Easter Egg
        // ==========================================
        console.log('%c🤖 Analisis Teks Otomatis', 'color: #667eea; font-size: 20px; font-weight: bold;');
        console.log('%cDikembangkan dengan ❤️ menggunakan Laravel & Machine Learning', 'color: #764ba2; font-size: 12px;');
        console.log('%cInterested in the code? Check out the GitHub repo!', 'color: #10B981; font-size: 12px;');
    </script>
    
</body>
</html>