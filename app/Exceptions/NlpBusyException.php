<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * Layanan NLP menolak analisis karena model yang dibutuhkan sedang dilatih ulang.
 *
 * Dibalas layanan sebagai HTTP 503 BESERTA header `Retry-After` (saklar
 * RETRAIN_REJECT_INFERENCE di nlp-api-service). Bedakan dari 503 tanpa header
 * itu, yang tetap berarti "layanan belum siap" dan ditangani seperti galat
 * biasa.
 *
 * Ini bukan kegagalan analisis. Job menanggapinya dengan melepas dirinya
 * kembali ke antrean selama `$retryAfter` detik tanpa menghabiskan jatah
 * percobaan - lihat ProcessTextAnalysis.
 */
class NlpBusyException extends Exception
{
    /** Batas bawah dan atas jeda. Layanan sendiri membatasi 30-600 detik. */
    public const JEDA_MIN = 30;

    public const JEDA_MAKS = 900;

    public function __construct(public readonly int $retryAfter, string $message = '')
    {
        parent::__construct($message ?: "Model sedang dilatih ulang. Coba lagi dalam {$retryAfter} detik.");
    }

    /**
     * Apakah respons ini penolakan "sedang melatih", bukan galat lain.
     */
    public static function cocok(?Response $response): bool
    {
        return $response !== null
            && $response->status() === 503
            && trim($response->header('Retry-After')) !== '';
    }

    public static function dariRespons(Response $response): self
    {
        return new self(self::jeda($response->header('Retry-After')));
    }

    /**
     * `Retry-After` boleh berupa jumlah detik atau tanggal HTTP (RFC 9110).
     * Nilai yang tidak bisa dibaca jatuh ke 60 detik, bukan ke nol: mencoba
     * lagi seketika hanya menghasilkan 503 berikutnya.
     */
    public static function jeda(string $nilai): int
    {
        $nilai = trim($nilai);

        if (ctype_digit($nilai)) {
            $detik = (int) $nilai;
        } elseif (($waktu = strtotime($nilai)) !== false) {
            $detik = $waktu - time();
        } else {
            $detik = 60;
        }

        return max(self::JEDA_MIN, min(self::JEDA_MAKS, $detik));
    }
}
