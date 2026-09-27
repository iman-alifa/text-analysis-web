<?php

namespace App\Http\Controllers;

/**
 * Pedoman penggunaan aplikasi.
 *
 * Isinya ditaruh di controller, bukan ditulis langsung di Blade, karena
 * sebagian besar pedoman ini berbentuk daftar berpola: langkah, istilah,
 * skenario. Menyimpannya sebagai larik membuat menambah atau menukar urutan
 * satu butir cukup menyentuh satu baris, dan tata letaknya tidak perlu
 * disalin ulang setiap kali.
 *
 * Halamannya sengaja **dapat dibuka tanpa masuk**: peserta uji coba biasanya
 * membacanya sebelum punya akun, dan pedoman yang menuntut login untuk
 * menjelaskan cara login tidak berguna.
 */
class PanduanController extends Controller
{
    /** Letak video panduan, relatif terhadap public/. */
    public const VIDEO = 'video/Pedoman.mp4';

    public function index()
    {
        return view('panduan', [
            'video' => $this->video(),
            'daftarIsi' => [
                ['id' => 'sebelum-mulai', 'judul' => 'Sebelum mulai'],
                ['id' => 'menyiapkan-data', 'judul' => 'Menyiapkan data'],
                ['id' => 'menjalankan-analisis', 'judul' => 'Menjalankan analisis'],
                ['id' => 'menunggu-proses', 'judul' => 'Menunggu proses'],
                ['id' => 'membaca-hasil', 'judul' => 'Membaca hasil'],
                ['id' => 'mengoreksi-hasil', 'judul' => 'Memperbaiki hasil'],
                ['id' => 'mengekspor', 'judul' => 'Menyimpan hasil'],
                ['id' => 'istilah', 'judul' => 'Istilah'],
                ['id' => 'masalah-umum', 'judul' => 'Bila ada masalah'],
                ['id' => 'skenario-latihan', 'judul' => 'Skenario latihan'],
            ],

            'menu' => [
                ['nama' => 'Dashboard', 'guna' => 'Ringkasan analisis yang pernah Anda jalankan.'],
                ['nama' => 'Analisis Baru', 'guna' => 'Tempat memulai analisis: memilih data, jenis analisis, dan pengaturannya.'],
                ['nama' => 'Riwayat Analisis', 'guna' => 'Daftar seluruh analisis Anda. Dari sini hasil lama dibuka lagi.'],
                ['nama' => 'YouTube Scraper', 'guna' => 'Mengambil komentar dari video YouTube untuk langsung dianalisis.'],
                ['nama' => 'Pengaturan', 'guna' => 'Preferensi analisis, data akun, dan kata sandi.'],
            ],

            'sumberData' => [
                [
                    'judul' => 'Ketik atau tempel langsung',
                    'isi' => 'Pada tab Input Manual, tulis satu teks per baris. Cara tercepat untuk mencoba '
                        .'aplikasi atau memeriksa beberapa kalimat saja.',
                    'catatan' => 'Tekan Enter untuk memisahkan teks. Baris kosong diabaikan.',
                ],
                [
                    'judul' => 'Unggah berkas',
                    'isi' => 'Pada tab Upload File, seret berkas CSV, TXT, atau Excel ke kotak unggah, atau klik '
                        .'untuk memilihnya. Aplikasi membaca isinya dan menampilkan pratinjau beserta daftar kolom.',
                    'catatan' => 'Untuk CSV dan Excel, Anda harus menunjuk kolom mana yang berisi teks yang akan dianalisis.',
                ],
                [
                    'judul' => 'Ambil dari YouTube',
                    'isi' => 'Buka menu YouTube Scraper, cari video berdasarkan kata kunci atau tempel tautannya, '
                        .'pilih video yang diinginkan, lalu jalankan pengambilan komentar. Setelah selesai, tekan '
                        .'tombol Analisis Sekarang dan formulir analisis langsung terbuka dengan berkas itu terpasang.',
                    'catatan' => 'Anda tetap bisa mengubah seluruh pengaturan sebelum menjalankannya, termasuk mengganti berkasnya.',
                ],
            ],

            'langkahAnalisis' => [
                [
                    'judul' => 'Beri judul yang menjelaskan isinya',
                    'isi' => 'Judul inilah yang muncul di Riwayat Analisis. "Komentar video peluncuran Maret" jauh '
                        .'lebih menolong daripada "Analisis 1" ketika Anda punya belasan analisis.',
                ],
                [
                    'judul' => 'Pilih jenis analisis',
                    'isi' => 'Empat pilihan: Sentimen, Aspek, Topik, atau Gabungan. Lihat tabel di bawah untuk '
                        .'menentukan yang sesuai dengan pertanyaan Anda.',
                ],
                [
                    'judul' => 'Masukkan datanya',
                    'isi' => 'Pilih tab Input Manual atau Upload File. Bila datang dari YouTube Scraper, berkasnya '
                        .'sudah terpasang dan langkah ini bisa dilewati.',
                ],
                [
                    'judul' => 'Tentukan kolom teks',
                    'isi' => 'Hanya untuk CSV dan Excel. Centang apakah baris pertama berisi nama kolom, lalu pilih '
                        .'kolom yang isinya teks. Salah kolom di sini berarti aplikasi menganalisis hal yang keliru, '
                        .'jadi periksa pratinjaunya.',
                ],
                [
                    'judul' => 'Pilih kolom pemisah bila ada',
                    'isi' => 'Opsional, maksimal tiga. Bila data Anda punya kolom seperti nama video, tanggal, atau '
                        .'sumber, pilih di sini. Nanti hasilnya bisa dipecah per kelompok itu tanpa menjalankan '
                        .'analisis ulang.',
                ],
                [
                    'judul' => 'Periksa pembersihan teks',
                    'isi' => 'Konfigurasi bawaan sudah sesuai untuk kebanyakan data. Tombol Pratinjau memperlihatkan '
                        .'bagaimana teks Anda terlihat setelah dibersihkan, sebelum apa pun dijalankan.',
                ],
                [
                    'judul' => 'Tekan Jalankan Analisis',
                    'isi' => 'Aplikasi memproses di latar belakang dan membawa Anda ke halaman hasil.',
                ],
            ],

            'jenisAnalisis' => [
                [
                    'nama' => 'Sentimen',
                    'pertanyaan' => 'Secara keseluruhan, orang bernada positif, negatif, atau netral?',
                    'kapan' => 'Anda ingin mengukur penerimaan atau kepuasan.',
                ],
                [
                    'nama' => 'Aspek',
                    'pertanyaan' => 'Hal apa yang sebenarnya mereka keluhkan atau puji?',
                    'kapan' => 'Anda perlu tahu sasaran perbaikannya, bukan sekadar suasana hatinya.',
                ],
                [
                    'nama' => 'Topik',
                    'pertanyaan' => 'Tema besar apa yang muncul dari kumpulan teks ini?',
                    'kapan' => 'Datanya banyak dan Anda belum tahu isinya apa saja.',
                ],
                [
                    'nama' => 'Gabungan',
                    'pertanyaan' => 'Ketiganya sekaligus, plus keterkaitan antar aspek dan topik.',
                    'kapan' => 'Anda ingin gambaran menyeluruh, atau belum yakin mana yang dibutuhkan.',
                ],
            ],

            'bagianHasil' => [
                [
                    'nama' => 'Ringkasan Analisis',
                    'isi' => 'Beberapa kalimat tentang temuan utamanya. Bacalah ini dulu sebelum melihat grafik.',
                ],
                [
                    'nama' => 'Ringkasan Eksekutif AI',
                    'isi' => 'Penjelasan yang menyatukan seluruh bagian menjadi satu gambaran, ditambah alasan '
                        .'mengapa hasilnya penting dan langkah yang bisa diambil. Perlu ditekan tombolnya lebih dulu.',
                ],
                [
                    'nama' => 'Seberapa Yakin Model',
                    'isi' => 'Menunjukkan seberapa besar hasil di bawahnya bisa dipercaya. Bila banyak teks dinilai '
                        .'dengan ragu, di sinilah ajakan untuk mengoreksi muncul.',
                ],
                [
                    'nama' => 'Distribusi Sentimen',
                    'isi' => 'Grafik dan angka porsi positif, negatif, dan netral.',
                ],
                [
                    'nama' => 'Detail Prediksi',
                    'isi' => 'Daftar tiap teks beserta penilaiannya. Bisa disaring per sentimen, dicari, dan '
                        .'dibatasi pada baris yang perlu ditinjau.',
                ],
                [
                    'nama' => 'Analisis Aspek',
                    'isi' => 'Hal-hal yang paling banyak dibicarakan, lengkap dengan nada tiap aspek. Klik sebuah '
                        .'aspek untuk membaca kalimat yang menyebutnya.',
                ],
                [
                    'nama' => 'Topik',
                    'isi' => 'Tema yang terbentuk dari data, kata kunci tiap tema, word cloud, dan ukuran mutunya.',
                ],
                [
                    'nama' => 'Rincian per Pemisah',
                    'isi' => 'Bila Anda memilih kolom pemisah, di sini hasilnya dipecah per kelompok sehingga '
                        .'kelompok yang menyimpang dari pola umum terlihat.',
                ],
            ],

            'langkahKoreksi' => [
                [
                    'judul' => 'Buka halaman koreksi',
                    'isi' => 'Dari halaman hasil, tekan tombol koreksi pada panel keyakinan, atau buka '
                        .'Riwayat Analisis lalu pilih Koreksi pada analisis yang bersangkutan.',
                ],
                [
                    'judul' => 'Mulai dari baris yang ragu',
                    'isi' => 'Penyaring di bagian atas membatasi daftar pada teks yang model sendiri tidak yakin. '
                        .'Di sanalah kesalahan paling mungkin ada, jadi tenaga Anda paling berguna di situ.',
                ],
                [
                    'judul' => 'Perbaiki yang keliru',
                    'isi' => 'Pilih sentimen yang benar. Bila aspeknya salah atau kurang, sunting kolom aspek. '
                        .'Menegaskan bahwa model sudah benar juga berguna, jadi tetap pilih sentimennya walau tidak berubah.',
                ],
                [
                    'judul' => 'Lewati yang Anda ragukan',
                    'isi' => 'Biarkan kosong. Baris yang tidak ditinjau tidak ikut tersimpan.',
                ],
                [
                    'judul' => 'Tekan Kirim Koreksi',
                    'isi' => 'Seluruh koreksi Anda pada analisis itu terkirim sekaligus, termasuk yang dibuat di '
                        .'halaman sebelumnya. Bila jumlahnya sudah mencukupi, model langsung dilatih ulang.',
                ],
            ],

            'istilah' => [
                [
                    'istilah' => 'Sentimen',
                    'arti' => 'Nada sebuah teks: positif, negatif, atau netral.',
                ],
                [
                    'istilah' => 'Aspek',
                    'arti' => 'Objek yang dinilai di dalam teks. Pada "pelayanannya lambat", aspeknya "pelayanan", '
                        .'bukan "lambat".',
                ],
                [
                    'istilah' => 'Topik',
                    'arti' => 'Kelompok teks yang membicarakan tema serupa, ditemukan sendiri oleh sistem tanpa '
                        .'daftar kategori yang ditentukan lebih dulu.',
                ],
                [
                    'istilah' => 'Keyakinan (confidence)',
                    'arti' => 'Angka 0 sampai 1 yang menunjukkan seberapa yakin model pada penilaiannya. Makin '
                        .'rendah, makin perlu diperiksa manusia.',
                ],
                [
                    'istilah' => 'Perlu ditinjau',
                    'arti' => 'Penanda pada teks yang keyakinannya di bawah ambang. Bukan berarti salah, tetapi '
                        .'di sanalah kesalahan paling sering ada.',
                ],
                [
                    'istilah' => 'Preprocessing',
                    'arti' => 'Pembersihan teks sebelum dianalisis: case folding, pembuangan tanda baca, '
                        .'stopword removal, dan stemming.',
                ],
                [
                    'istilah' => 'Koherensi topik',
                    'arti' => 'Ukuran seberapa sering kata kunci satu topik benar-benar muncul berdekatan di teks '
                        .'yang sama. Makin tinggi, topiknya makin padu.',
                ],
                [
                    'istilah' => 'Active learning',
                    'arti' => 'Cara kerja yang dipakai aplikasi ini: koreksi Anda dikumpulkan, lalu dipakai melatih '
                        .'ulang model sehingga analisis berikutnya lebih tepat.',
                ],
            ],

            'masalah' => [
                [
                    'gejala' => 'Analisis berhenti lama di status "sedang diproses"',
                    'jawaban' => 'Untuk korpus besar ini wajar; seribu teks memakan sekitar lima menit. Bila tidak '
                        .'bergerak sama sekali selama lebih dari satu jam, laporkan kepada pengelola: biasanya '
                        .'proses latar belakang di server sedang tidak berjalan.',
                ],
                [
                    'gejala' => 'Kolom teks yang saya pilih ternyata salah',
                    'jawaban' => 'Jalankan analisis baru dengan kolom yang benar. Analisis yang telanjur salah bisa '
                        .'dihapus dari Riwayat Analisis.',
                ],
                [
                    'gejala' => 'Tombol Kirim Koreksi tidak bisa ditekan',
                    'jawaban' => 'Tombol itu baru aktif setelah ada minimal satu baris yang Anda tinjau. Pilih '
                        .'sentimen pada salah satu teks, atau ubah kolom aspeknya.',
                ],
                [
                    'gejala' => 'Tombol Jelaskan dengan AI memberi pesan error',
                    'jawaban' => 'Layanan AI punya batas pemakaian harian dan batas sepuluh permintaan per menit. '
                        .'Tunggu sebentar lalu coba lagi. Seluruh isi halaman tetap lengkap tanpa penjelasan AI.',
                ],
                [
                    'gejala' => 'Hasil analisis aspek kosong',
                    'jawaban' => 'Terjadi bila teksnya sangat pendek atau tidak menyebut objek apa pun secara jelas. '
                        .'Analisis sentimen tetap berjalan seperti biasa.',
                ],
                [
                    'gejala' => 'Saya menutup halaman saat analisis berjalan',
                    'jawaban' => 'Tidak masalah. Proses berlanjut di server, dan hasilnya bisa dibuka lagi lewat '
                        .'Riwayat Analisis.',
                ],
            ],

            'skenario' => [
                [
                    'tugas' => 'Masuk ke aplikasi, lalu temukan menu Analisis Baru.',
                    'selesai' => 'Formulir analisis baru terbuka di layar Anda.',
                ],
                [
                    'tugas' => 'Jalankan satu analisis dengan mengetik langsung minimal sepuluh kalimat pada tab Input Manual. Pilih jenis Analisis Gabungan.',
                    'selesai' => 'Halaman hasil terbuka dan statusnya berubah menjadi selesai.',
                ],
                [
                    'tugas' => 'Temukan berapa persen teks yang bernada negatif, lalu baca satu contoh teks negatif dari daftar prediksi.',
                    'selesai' => 'Anda bisa menyebutkan angkanya dan satu kalimat contohnya.',
                ],
                [
                    'tugas' => 'Tekan tombol Jelaskan dengan AI pada salah satu bagian hasil, lalu baca langkah tindak lanjut yang disarankan.',
                    'selesai' => 'Blok penjelasan muncul lengkap dengan daftar langkah bernomor.',
                ],
                [
                    'tugas' => 'Buka halaman koreksi, perbaiki minimal tiga baris yang menurut Anda dinilai keliru, lalu kirim.',
                    'selesai' => 'Muncul pesan bahwa koreksi Anda tersimpan.',
                ],
                [
                    'tugas' => 'Ekspor hasil analisis tersebut sebagai PDF.',
                    'selesai' => 'Berkas PDF terunduh ke perangkat Anda.',
                ],
            ],
        ]);
    }

    /**
     * Data video panduan, atau null bila berkasnya tidak ada.
     *
     * Bagian videonya tidak dirender sama sekali tanpa berkas: pemutar kosong
     * yang gagal dimuat lebih membingungkan daripada tidak ada video.
     * `v` (waktu ubah berkas) ikut di URL supaya video yang diganti tidak
     * tertahan di cache peramban.
     */
    private function video(): ?array
    {
        $path = public_path(self::VIDEO);

        if (! is_file($path)) {
            return null;
        }

        return [
            'url' => asset(self::VIDEO).'?v='.filemtime($path),
            'mb' => round(filesize($path) / 1048576),
            'durasi' => self::durasiMp4($path),
        ];
    }

    /**
     * Durasi MP4 dalam detik, dibaca dari atom `mvhd`, atau null bila tidak
     * ditemukan di awal berkas.
     *
     * Hanya 1 MB pertama yang dibaca. Itu cukup karena videonya disimpan
     * "faststart" (indeks moov di depan data), yang juga membuat peramban bisa
     * mulai memutar tanpa menunggu seluruh 60-an MB terunduh.
     */
    public static function durasiMp4(string $path): ?int
    {
        $awal = @file_get_contents($path, false, null, 0, 1048576);
        $i = $awal === false ? false : strpos($awal, 'mvhd');

        if ($i === false) {
            return null;
        }

        $versi = ord($awal[$i + 4]);
        [$skala, $durasi] = $versi === 1
            ? [unpack('N', substr($awal, $i + 24, 4))[1], unpack('J', substr($awal, $i + 28, 8))[1]]
            : [unpack('N', substr($awal, $i + 16, 4))[1], unpack('N', substr($awal, $i + 20, 4))[1]];

        return $skala > 0 ? (int) round($durasi / $skala) : null;
    }
}
