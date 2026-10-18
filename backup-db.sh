#!/usr/bin/env bash
#
# Cadangan harian basis data AnalisisTeks.
#
# Yang tak tergantikan di seluruh sistem adalah ISI BASIS DATA: koreksi
# pengguna (training_items), golden dataset, dan riwayat evaluasi. Bobot model
# bisa dibangkitkan ulang dari kolam koreksi (protokol kolam deterministik,
# seed 42); koreksinya sendiri tidak bisa. Karena itu cadangan ini lebih
# mendesak daripada cadangan bobot mana pun.
#
# Pemakaian (dari cron, lihat docs/DEPLOYMENT.md bagian 11):
#   ./backup-db.sh
#
# Variabel opsional:
#   BACKUP_DIR     tempat menyimpan (bawaan /var/backups/analisisteks)
#   BACKUP_KEEP    berapa hari disimpan di server (bawaan 14)
#   RCLONE_REMOTE  mis. "gdrive:analisisteks-backup". Bila diisi, berkas juga
#                  disalin ke luar server. Cadangan yang hanya ada di server
#                  yang sama ikut hilang bersama servernya.

set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/analisisteks}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"

# Baca kredensial dari .env aplikasi, bukan ditulis di sini. Nilai boleh
# diapit tanda kutip.
baca_env() {
    grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

# .env berizin 640 milik www-data (docs/DEPLOYMENT.md bagian 5). Tanpa pemeriksaan
# ini, `grep` yang ditolak menghentikan skrip lewat `set -e` dengan pesan
# "Permission denied" yang tidak menyebut jalan keluarnya.
if [[ ! -r "$APP_DIR/.env" ]]; then
    echo "Tidak bisa membaca $APP_DIR/.env. Jalankan dengan sudo (cron: pakai crontab root)." >&2
    exit 1
fi

DB_HOST="$(baca_env DB_HOST)"
DB_PORT="$(baca_env DB_PORT)"
DB_DATABASE="$(baca_env DB_DATABASE)"
DB_USERNAME="$(baca_env DB_USERNAME)"
DB_PASSWORD="$(baca_env DB_PASSWORD)"

if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "DB_DATABASE / DB_USERNAME tidak ditemukan di $APP_DIR/.env" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

STEMPEL="$(date +%Y%m%d-%H%M%S)"
BERKAS="$BACKUP_DIR/${DB_DATABASE}-${STEMPEL}.sql.gz"

# Kata sandi lewat MYSQL_PWD, bukan argumen -p: argumen perintah terlihat oleh
# pengguna lain di server lewat `ps`.
# --single-transaction: salinan konsisten tanpa mengunci tabel, jadi aplikasi
# tetap bisa dipakai selama pencadangan.
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" \
    --user="$DB_USERNAME" \
    --single-transaction --routines --triggers --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -9 > "$BERKAS.tmp"

# Nama akhir baru dipasang setelah dump selesai, supaya dump yang terputus
# tidak pernah terlihat seperti cadangan yang sah.
mv "$BERKAS.tmp" "$BERKAS"
chmod 600 "$BERKAS"

echo "Cadangan: $BERKAS ($(du -h "$BERKAS" | cut -f1))"

if [[ -n "${RCLONE_REMOTE:-}" ]]; then
    rclone copy "$BERKAS" "$RCLONE_REMOTE"
    echo "Disalin ke $RCLONE_REMOTE"
fi

# Buang cadangan lokal yang lebih tua dari BACKUP_KEEP hari. Salinan di
# RCLONE_REMOTE tidak disentuh.
find "$BACKUP_DIR" -name "${DB_DATABASE}-*.sql.gz" -mtime +"$BACKUP_KEEP" -delete
