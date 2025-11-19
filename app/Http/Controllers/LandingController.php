<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function index()
    {
        $features = [
            [
                'icon' => '📊',
                'title' => 'Analisis Sentimen',
                'description' => 'Identifikasi polaritas emosi positif, negatif, atau netral dari teks menggunakan model IndoBERT.'
            ],
            [
                'icon' => '🎯',
                'title' => 'Ekstraksi Aspek',
                'description' => 'Temukan aspek spesifik yang dibicarakan dalam teks dengan pendekatan rule-based atau otomatis.'
            ],
            [
                'icon' => '🔍',
                'title' => 'Identifikasi Topik',
                'description' => 'Deteksi tema dan isu utama dari kumpulan teks menggunakan topic modeling modern.'
            ],
            [
                'icon' => '📁',
                'title' => 'Multi-Format Input',
                'description' => 'Upload file CSV, TXT, XLSX atau input teks langsung melalui form yang user-friendly.'
            ],
            [
                'icon' => '🤖',
                'title' => 'Preprocessing Otomatis',
                'description' => 'Pembersihan teks otomatis dengan case folding, stopword removal, dan stemming.'
            ],
            [
                'icon' => '📈',
                'title' => 'Visualisasi Interaktif',
                'description' => 'Hasil analisis ditampilkan dalam grafik, word cloud, dan tabel yang mudah dipahami.'
            ],
        ];

        $stats = [
            ['number' => '10K+', 'label' => 'Teks Dianalisis'],
            ['number' => '99%', 'label' => 'Akurasi Model'],
            ['number' => '500+', 'label' => 'Pengguna Aktif'],
            ['number' => '<5s', 'label' => 'Waktu Proses'],
        ];

        $testimonials = [
            [
                'name' => 'Dr. Ahmad Fauzi',
                'role' => 'Peneliti BPS',
                'image' => 'https://ui-avatars.com/api/?name=Ahmad+Fauzi&background=4F46E5&color=fff&size=128',
                'comment' => 'Sistem ini sangat membantu dalam menganalisis data survei terbuka secara cepat dan akurat. Visualisasi hasil yang interaktif memudahkan interpretasi.'
            ],
            [
                'name' => 'Siti Nurhaliza',
                'role' => 'Data Analyst',
                'image' => 'https://ui-avatars.com/api/?name=Siti+Nurhaliza&background=10B981&color=fff&size=128',
                'comment' => 'Fitur ekstraksi aspek sangat powerful untuk memahami feedback pelanggan. Hemat waktu hingga 80% dibanding analisis manual.'
            ],
            [
                'name' => 'Budi Santoso',
                'role' => 'Project Manager',
                'image' => 'https://ui-avatars.com/api/?name=Budi+Santoso&background=F59E0B&color=fff&size=128',
                'comment' => 'Interface yang intuitif dan hasil yang komprehensif. Sangat recommended untuk analisis opini publik skala besar.'
            ],
        ];

        return view('landing', compact('features', 'stats', 'testimonials'));
    }
}