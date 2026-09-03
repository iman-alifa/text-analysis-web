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

    public function isConfigured(): bool
    {
        return ! empty(config('services.gemini.key'));
    }

    public function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-3.5-flash');
    }

    /**
     * Kirim satu prompt dan kembalikan hasil terstruktur sesuai $schema.
     *
     * @param  array  $schema  Skema OpenAPI subset yang dipahami Gemini
     * @return array Hasil ter-decode sesuai skema
     *
     * @throws LlmException
     */
    public function generate(string $prompt, array $schema, float $temperature = 0.2): array
    {
        if (! $this->isConfigured()) {
            throw new LlmException('GEMINI_API_KEY belum diisi pada .env.');
        }

        $url = self::BASE_URL.$this->model().':generateContent';

        try {
            $response = Http::timeout((int) config('services.gemini.timeout', 45))
                ->retry((int) config('services.gemini.retry', 2), 500, throw: false)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url.'?key='.config('services.gemini.key'), [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        // Rendah supaya narasi untuk data yang sama tidak
                        // berubah-ubah setiap kali dibangkitkan ulang.
                        'temperature' => $temperature,
                        'response_mime_type' => 'application/json',
                        'response_schema' => $schema,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Gemini tidak dapat dihubungi: '.$e->getMessage());

            throw new LlmException('Layanan AI tidak dapat dihubungi. Coba lagi beberapa saat lagi.');
        }

        if ($response->failed()) {
            Log::error('Gemini membalas '.$response->status().': '.$response->body());

            throw new LlmException($this->pesanKegagalan($response->status()));
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

        $prompt = "Anda ahli linguistik dan data scientist yang merangkum hasil topic modeling teks berbahasa Indonesia.\n\n";

        if ($konteks) {
            $prompt .= "Konteks korpus: {$konteks}\n\n";
        }

        $prompt .= "Berikut daftar topik beserta kata kunci teratasnya:\n\n";

        foreach ($topics as $index => $topic) {
            $id = $topic['topic_id'] ?? $index;
            $words = $topic['words'] ?? $topic['keywords'] ?? [];
            $prompt .= 'Topik '.$id.': '.implode(', ', array_slice($words, 0, 10))."\n";
        }

        $prompt .= "\nUntuk setiap topik berikan satu label singkat (maksimal 4 kata) dan satu kalimat "
                 ."deskripsi yang menjelaskan makna topik itu.\n"
                 ."Gunakan topic_id persis seperti yang tertulis di atas.\n"
                 .'Dasarkan jawaban hanya pada kata kunci yang diberikan; jangan menambahkan informasi '
                 .'yang tidak terlihat di sana.';

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

        $hasil = $this->generate($prompt, $schema);

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

    private function pesanKegagalan(int $status): string
    {
        return match (true) {
            $status === 429 => 'Kuota AI harian sudah habis. Coba lagi besok atau gunakan API key lain.',
            $status === 400 => 'Permintaan ke AI ditolak. Periksa GEMINI_API_KEY dan nama model.',
            $status === 404 => 'Model AI "'.$this->model().'" tidak tersedia untuk API key ini.',
            $status >= 500 => 'Layanan AI sedang bermasalah. Coba lagi beberapa menit lagi.',
            default => 'Permintaan ke AI gagal (HTTP '.$status.').',
        };
    }
}
