<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Layanan NLP menolak permintaan pelatihan karena sedang melatih model itu
 * (HTTP 409).
 *
 * Dipisahkan dari kegagalan biasa karena artinya berbeda: tidak ada yang rusak,
 * permintaannya hanya datang di saat yang salah. Mencatatnya sebagai "gagal"
 * akan membuat riwayat training menyiratkan masalah yang tidak ada.
 */
class RetrainBusyException extends RuntimeException {}
