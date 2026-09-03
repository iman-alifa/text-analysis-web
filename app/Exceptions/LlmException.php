<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan saat memanggil penyedia LLM. Dipisahkan dari Exception biasa
 * supaya controller bisa membedakan "AI gagal" dari galat lain dan
 * menyampaikan pesan yang bisa ditindaklanjuti pengguna.
 */
class LlmException extends RuntimeException {}
