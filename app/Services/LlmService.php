<?php

namespace App\Services;

use App\Exceptions\LlmException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pembungkus Google Gemini untuk menghasilkan narasi berbahasa Indonesia.
 *
 * Dua keputusan yang mengikat di sini:
 *
 * 1. Memakai facade Http, bukan Guzzle mentah, supaya bisa di-fake pada tes
 *    seperti NLPApiService. Versi sebelumnya memakai Guzzle dan akibatnya
 *    menjadi satu-satunya bagian sistem yang tidak punya tes sama sekali.
 * 2. Bentuk keluaran dijamin lewat response_schema (structured output), bukan
 *    lewat kalimat "balas dalam format JSON" di dalam prompt. Versi sebelumnya
 *    memakai cara kedua dan harus menebak ketika model membalas indeks 1-based.
 */
class LlmService
{
    /**
     * Versi prompt, ikut disimpan bersama hasil. Naikkan setiap kali susunan
     * prompt berubah, supaya narasi lama bisa dibedakan dari yang baru ketika
     * hasilnya dikutip di naskah.
     */
    public const PROMPT_VERSION = 3;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $usedModel = '';

    /**
     * Dipatok versinya, bukan alias -latest: narasi yang dikutip di naskah harus
     * bisa direproduksi. Diuji 24 Sep 2026 dengan kunci proyek: 3.5-flash dan
     * 3.6-flash membalas 200; 2.5-flash dan seluruh 1.5 sudah 404 ("no longer
     * available to new users").
     */
    public const MODEL_BAWAAN = 'gemini-3.5-flash';

    public function isConfigured(): bool
    {
        return ! empty(config('services.gemini.key'));
    }

    public function model(): string
    {
        if ($this->usedModel) {
            return $this->usedModel;
        }

        return $this->daftarModel()[0];
    }

    /**
     * Kandidat model dari GEMINI_MODEL, berurutan, tanpa entri kosong.
     *
     * @return list<string>
     */
    private function daftarModel(): array
    {
        $models = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.gemini.model', self::MODEL_BAWAAN))
        )));

        return $models ?: [self::MODEL_BAWAAN];
    }

    /**
     * Ambang keamanan yang dilonggarkan ke BLOCK_ONLY_HIGH.
     *
     * Bawaan Gemini (BLOCK_MEDIUM_AND_ABOVE) dirancang untuk keluaran yang
     * dilihat banyak orang. Di sini modelnya cuma MERANGKUM ulasan/komentar
     * warga yang SUDAH ada di database - termasuk kritik keras dan tuduhan
     * korupsi terhadap lembaga pemerintah/militer pada studi kasus proyek
     * ini - dan itu berbeda dari model MENGARANG konten berbahaya. Ambang
     * bawaan pernah memblokir narasi yang sah (finishReason: SAFETY) untuk
     * kasus semacam ini, terlihat sebagai "AI tidak menghasilkan jawaban"
     * padahal datanya tidak apa-apa - justru topik yang paling penting
     * diringkas (kritik tajam) yang paling sering kena.
     */
    private const SAFETY_SETTINGS = [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ];

    /**
     * Kirim satu prompt dan kembalikan hasil terstruktur sesuai $schema.
     *
     * @param  array  $schema  Skema OpenAPI subset yang dipahami Gemini
     * @param  string|null  $systemInstruction  Aturan perilaku yang berlaku
     *                                          untuk seluruh permintaan (persona,
     *                                          format, larangan) - dipisah dari
     *                                          $prompt (tugas + data) supaya
     *                                          model lebih konsisten
     *                                          mengikutinya, sesuai panduan
     *                                          resmi Gemini untuk system
     *                                          instruction vs. isi giliran user.
     * @return array Hasil ter-decode sesuai skema
     *
     * @throws LlmException
     */
    public function generate(string $prompt, array $schema, float $temperature = 0.2, ?string $systemInstruction = null): array
    {
        if (! $this->isConfigured()) {
            throw new LlmException('GEMINI_API_KEY belum diisi pada .env.');
        }

        $models = $this->daftarModel();
        $response = null;

        // Hasil tiap model: kode HTTP, atau 0 bila koneksi gagal / habis waktu.
        // Dipakai memilih pesan yang paling berguna bila SEMUANYA gagal.
        $kegagalan = [];

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'safetySettings' => self::SAFETY_SETTINGS,
            'generationConfig' => [
                // Rendah supaya narasi untuk data yang sama tidak
                // berubah-ubah setiap kali dibangkitkan ulang.
                'temperature' => $temperature,
                'response_mime_type' => 'application/json',
                'response_schema' => $schema,
            ],
        ];

        if ($systemInstruction !== null && $systemInstruction !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        foreach ($models as $modelName) {
            try {
                $response = Http::timeout((int) config('services.gemini.timeout', 45))
                    ->retry(
                        (int) config('services.gemini.retry', 2),
                        fn (int $attempt) => min(1000 * 2 ** ($attempt - 1), 4000),
                        // Coba lagi hanya untuk kegagalan yang sifatnya sementara
                        // (occupied/unavailable atau koneksi putus). 429 di sini
                        // berarti kuota harian habis dan 400/404 berarti konfigurasi
                        // salah - mengulangnya hanya membuang jatah waktu tanpa
                        // pernah berhasil.
                        fn (\Throwable $e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                            || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() >= 500),
                        throw: false
                    )
                    // Kunci lewat header, BUKAN ?key= di URL. Pesan galat koneksi
                    // Laravel memuat URL lengkap, sehingga kunci di URL ikut
                    // tertulis ke storage/logs setiap kali Gemini tidak terjangkau.
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => (string) config('services.gemini.key'),
                    ])
                    ->post(self::BASE_URL.$modelName.':generateContent', $payload);

                if ($response->successful()) {
                    $this->usedModel = $modelName;
                    break;
                }

                $kegagalan[$modelName] = $response->status();

                if ($response->status() === 404) {
                    // Model pensiun atau tidak tersedia untuk kunci ini. Bukan
                    // gangguan sesaat: namanya perlu dibuang dari GEMINI_MODEL.
                    Log::warning("Gemini: model {$modelName} tidak tersedia (404). Hapus dari GEMINI_MODEL. "
                        .mb_substr((string) $response->json('error.message'), 0, 200));
                } else {
                    Log::warning("Gemini (model {$modelName}) membalas {$response->status()}: ".mb_substr($response->body(), 0, 500));
                }
            } catch (\Throwable $e) {
                // Direset supaya percobaan model sebelumnya yang gagal tidak
                // dibaca seolah masih berlaku kalau model TERAKHIR justru
                // gagal lewat exception (bukan respons HTTP).
                $response = null;
                $kegagalan[$modelName] = 0;
                Log::error("Gemini (model {$modelName}) tidak dapat dihubungi: ".$e->getMessage());
            }
        }

        if (! $response || $response->failed()) {
            Log::error('Semua model Gemini gagal: '.json_encode($kegagalan));
            throw new LlmException($this->pesanKegagalan($kegagalan));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            // Umumnya karena filter keamanan Gemini memblokir keluaran.
            $alasan = $response->json('candidates.0.finishReason') ?? 'tidak diketahui';
            Log::warning('Gemini tidak mengembalikan teks, finishReason: '.$alasan);

            throw new LlmException('AI tidak menghasilkan jawaban (alasan: '.$alasan.').');
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::error('Keluaran Gemini bukan JSON valid: '.$text);

            throw new LlmException('Jawaban AI tidak dapat dibaca. Coba bangkitkan ulang.');
        }

        return $decoded;
    }

    /**
     * Coba setiap model di GEMINI_MODEL dengan permintaan terkecil.
     *
     * Dipakai `deploy:check`. Daftar model dari API tidak cukup sebagai bukti:
     * gemini-2.5-flash masih tercantum di sana tetapi generateContent-nya
     * membalas 404 "no longer available to new users".
     *
     * @return array<string, int> model => kode HTTP (0 = tidak terjangkau)
     */
    public function periksaModel(): array
    {
        $hasil = [];

        foreach ($this->daftarModel() as $model) {
            try {
                $hasil[$model] = Http::timeout(30)
                    ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                    ->post(self::BASE_URL.$model.':generateContent', [
                        'contents' => [['parts' => [['text' => 'Balas: ok']]]],
                        'generationConfig' => ['maxOutputTokens' => 5],
                    ])
                    ->status();
            } catch (\Throwable) {
                $hasil[$model] = 0;
            }
        }

        return $hasil;
    }

    /**
     * Metadata asal-usul yang disimpan bersama setiap narasi.
     *
     * Naskah skripsi perlu bisa menyebut model dan tanggal pembangkitan;
     * tanpa ini hasilnya tidak dapat dipertanggungjawabkan.
     */
    public function provenance(): array
    {
        return [
            'model' => $this->model(),
            'prompt_version' => self::PROMPT_VERSION,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Beri label topik memakai kata kuncinya.
     *
     * @param  array  $topics  Daftar topik, tiap topik punya kunci 'words'
     * @param  string|null  $konteks  Judul/asal korpus, membantu model memilih
     *                                label yang sesuai domain
     * @return array<int, array{label: string, description: string}> Dipetakan per topic_id
     *
     * @throws LlmException
     */
    public function generateTopicInterpretations(array $topics, ?string $konteks = null): array
    {
        if (empty($topics)) {
            return [];
        }

        // Aturan perilaku dipisah dari isi (topik + kata kunci) lewat
        // systemInstruction - lihat catatan pada LlmService::generate().
        $systemInstruction = 'Anda ahli linguistik dan data scientist yang merangkum hasil topic modeling '
            ."teks berbahasa Indonesia.\n\n"
            .'Untuk setiap topik berikan satu label singkat (maksimal 4 kata) dan satu kalimat deskripsi '
            ."yang menjelaskan makna topik itu.\n"
            ."Gunakan topic_id persis seperti yang tertulis pada data yang diberikan.\n"
            .'Dasarkan jawaban hanya pada kata kunci yang diberikan; jangan menambahkan informasi '
            .'yang tidak terlihat di sana.';

        $prompt = '';

        if ($konteks) {
            $prompt .= "Konteks korpus: {$konteks}\n\n";
        }

        $prompt .= "Berikut daftar topik beserta kata kunci teratasnya:\n\n";

        foreach ($topics as $index => $topic) {
            $id = $topic['topic_id'] ?? $index;
            $words = $topic['words'] ?? $topic['keywords'] ?? [];
            $prompt .= 'Topik '.$id.': '.implode(', ', array_slice($words, 0, 10))."\n";
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'topic_id' => ['type' => 'integer'],
                            'label' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['topic_id', 'label', 'description'],
                    ],
                ],
            ],
            'required' => ['topics'],
        ];

        $hasil = $this->generate($prompt, $schema, systemInstruction: $systemInstruction);

        // Kunci topik yang sah, dipakai untuk menolak topic_id karangan.
        $idSah = [];
        foreach ($topics as $index => $topic) {
            $idSah[(int) ($topic['topic_id'] ?? $index)] = true;
        }

        $mapped = [];

        foreach ($hasil['topics'] ?? [] as $urutan => $item) {
            $topicId = (int) ($item['topic_id'] ?? $urutan);

            if (! isset($idSah[$topicId])) {
                Log::warning("Gemini mengembalikan topic_id {$topicId} yang tidak ada, dilewati.");

                continue;
            }

            $mapped[$topicId] = [
                'label' => trim((string) ($item['label'] ?? '')) ?: 'Topik Tanpa Label',
                'description' => trim((string) ($item['description'] ?? '')),
            ];
        }

        return $mapped;
    }

    /**
     * Pesan untuk pengguna saat SEMUA model gagal.
     *
     * Dulu pesannya diambil dari model TERAKHIR yang dicoba. Dengan
     * GEMINI_MODEL berisi cadangan yang sudah pensiun, kegagalan sesaat model
     * utama (sibuk/timeout) tampil sebagai "Model gemini-1.5-pro tidak tersedia"
     * - menyesatkan, karena penyebab sebenarnya layanan yang sedang sibuk.
     *
     * Urutan prioritas: kuota habis > layanan sibuk/tak terjangkau > permintaan
     * ditolak > model tidak tersedia (hanya bila SEMUA kandidat 404).
     *
     * @param  array<string, int>  $kegagalan  model => kode HTTP (0 = koneksi gagal)
     */
    private function pesanKegagalan(array $kegagalan): string
    {
        $kode = array_values($kegagalan);

        return match (true) {
            in_array(429, $kode, true) => 'Kuota AI harian sudah habis. Coba lagi besok atau gunakan API key lain.',
            (bool) array_filter($kode, fn ($k) => $k === 0 || $k >= 500) => 'Layanan AI sedang sibuk atau tidak terjangkau. Coba lagi beberapa menit lagi.',
            in_array(400, $kode, true) || in_array(403, $kode, true) => 'Permintaan ke AI ditolak. Periksa GEMINI_API_KEY.',
            $kode !== [] && count(array_filter($kode, fn ($k) => $k === 404)) === count($kode) => 'Model AI ('.implode(', ', array_keys($kegagalan)).') tidak tersedia untuk API key ini. Perbarui GEMINI_MODEL.',
            default => 'Permintaan ke AI gagal (HTTP '.implode(', ', $kode).').',
        };
    }
}
