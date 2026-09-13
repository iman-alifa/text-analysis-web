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
    public const PROMPT_VERSION = 2;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $usedModel = '';

    public function isConfigured(): bool
    {
        return ! empty(config('services.gemini.key'));
    }

    public function model(): string
    {
        if ($this->usedModel) {
            return $this->usedModel;
        }
        $models = explode(',', (string) config('services.gemini.model', 'gemini-3.5-flash'));

        return trim($models[0]);
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

        $models = array_map('trim', explode(',', (string) config('services.gemini.model', 'gemini-3.5-flash')));
        $response = null;
        $lastStatus = 500;
        $lastModel = $models[0];

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
            $url = self::BASE_URL.$modelName.':generateContent';
            $lastModel = $modelName;

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
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url.'?key='.config('services.gemini.key'), $payload);

                if ($response->successful()) {
                    $this->usedModel = $modelName;
                    break;
                }

                $lastStatus = $response->status();
                Log::warning("Gemini (model {$modelName}) membalas {$lastStatus}: ".$response->body());
            } catch (\Throwable $e) {
                // Direset supaya percobaan model sebelumnya yang gagal tidak
                // dibaca seolah masih berlaku kalau model TERAKHIR justru
                // gagal lewat exception (bukan respons HTTP).
                $response = null;
                Log::error("Gemini (model {$modelName}) tidak dapat dihubungi: ".$e->getMessage());
            }
        }

        if (! $response || $response->failed()) {
            Log::error('Semua model Gemini gagal memberikan respons yang valid.');
            throw new LlmException($this->pesanKegagalan($lastStatus, $lastModel));
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

    private function pesanKegagalan(int $status, ?string $model = null): string
    {
        return match (true) {
            $status === 429 => 'Kuota AI harian sudah habis. Coba lagi besok atau gunakan API key lain.',
            $status === 400 => 'Permintaan ke AI ditolak. Periksa GEMINI_API_KEY dan nama model.',
            $status === 404 => 'Model AI "'.($model ?? $this->model()).'" tidak tersedia untuk API key ini.',
            $status >= 500 => 'Layanan AI sedang bermasalah. Coba lagi beberapa menit lagi.',
            default => 'Permintaan ke AI gagal (HTTP '.$status.').',
        };
    }
}
