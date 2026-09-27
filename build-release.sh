#!/usr/bin/env bash
#
# Membuat paket rilis yang tinggal diunggah ke server.
#
# Dijalankan DI LAPTOP, dari folder aplikasi. Hasilnya satu berkas .tar.gz di
# luar folder proyek, berisi kode ditambah aset yang sudah dibangun.
#
#   ./build-release.sh
#
# Yang TIDAK ikut dan alasannya:
#
#   vendor/        dipasang di server dengan `composer install --no-dev`,
#                  karena isinya bergantung pada versi PHP di sana
#   node_modules/  hanya dibutuhkan saat membangun aset, bukan saat berjalan
#   .env           berisi kata sandi; disiapkan langsung di server
#   storage/*      data pengguna; tidak boleh menimpa milik server
#   .git/          riwayat pengembangan tidak perlu ikut ke produksi
#   tests/         tidak dipakai saat berjalan
#
# `public/build/` justru IKUT: membangun aset menuntut Node.js, dan banyak
# server tidak memasangnya. Membangun di laptop menghindarkan itu sepenuhnya.

set -euo pipefail

info()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()    { printf '    \033[0;32mOK\033[0m %s\n' "$1"; }
fatal() { printf '\n\033[1;31mGAGAL: %s\033[0m\n\n' "$1" >&2; exit 1; }

[[ -f artisan ]] || fatal "Jalankan dari folder aplikasi (tidak ada berkas 'artisan')."

STAMP=$(date +%Y%m%d-%H%M%S)
NAMA="analisisteks-${STAMP}"
KELUAR="../${NAMA}.tar.gz"

# ---------------------------------------------------------------------------
info "Membangun aset tampilan"

# `npm install` bila node_modules belum ada, BUKAN `npm ci`. Keduanya sama-sama
# mengikuti package-lock.json, tetapi `npm ci` lebih dulu MENGHAPUS node_modules
# seluruhnya. Pada mesin yang sedang menjalankan `composer dev`, itu mencabut
# paket di bawah kaki server Vite yang sedang berjalan.
if [[ ! -d node_modules || ! -f node_modules/.package-lock.json ]]; then
    printf '    memasang dependensi npm (sekali saja)...
'
    npm install --silent
fi

npm run build
[[ -f public/build/manifest.json ]] || fatal "npm run build tidak menghasilkan public/build/manifest.json."
ok "public/build siap"

# public/hot TIDAK dihapus di sini, hanya dikecualikan dari paket (lihat daftar
# --exclude di bawah). Menghapusnya percuma selama `npm run dev` masih berjalan:
# Vite langsung menulisnya kembali. Yang penting ia tidak ikut ke server, dan
# itu sudah dijamin pengecualiannya.
if [[ -f public/hot ]]; then
    ok "public/hot ada secara lokal, dikecualikan dari paket"
fi

# ---------------------------------------------------------------------------
info "Memeriksa hal yang sering terlewat"

# Folder di public/ yang namanya sama dengan rute akan menelan rute itu di
# server, dan gejalanya (404 / 403) tidak menyebut Laravel sama sekali.
php artisan deploy:check >/dev/null 2>&1 \
    && ok "deploy:check lulus di lingkungan lokal" \
    || printf '    \033[0;33mCATATAN\033[0m deploy:check melaporkan masalah. Jalankan `php artisan deploy:check` untuk melihatnya.\n'

# ---------------------------------------------------------------------------
info "Menyusun paket"

# --exclude='.git' tanpa './' di depan berlaku pada SETIAP tingkat, bukan
# hanya akar. Itu perlu: public/anotasi/ adalah klon repositori tersendiri,
# dan folder .git di dalam direktori yang dilayani web membuat seluruh isi
# repositori bisa diambil lewat peramban selama dotfile tidak diblokir.
# Seluruh isi storage/app dikecualikan, bukan daftar subfolder tertentu. Versi
# sebelumnya hanya membuang uploads/, exports/, dan backups/, sehingga berkas
# lepas seperti storage/app/golden-gabungan.csv (data anotasi penelitian) ikut
# terkemas. Folder kosong yang dibutuhkan Laravel dibuat ulang oleh deploy.sh.
tar -czf "$KELUAR" \
    --exclude='.git' \
    --exclude='.pytest_cache' \
    --exclude='__pycache__' \
    --exclude='./vendor' \
    --exclude='./node_modules' \
    --exclude='./.env' \
    --exclude='./tests' \
    --exclude='./storage/logs/*' \
    --exclude='./storage/framework/cache/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./storage/app/*' \
    --exclude='./public/storage' \
    --exclude='./public/hot' \
    --exclude='*.tar.gz' \
    .

UKURAN=$(du -h "$KELUAR" | cut -f1)

ok "paket dibuat"

printf '\n\033[1;32mSelesai.\033[0m\n'
printf '  Berkas : %s (%s)\n' "$(cd "$(dirname "$KELUAR")" && pwd)/$(basename "$KELUAR")" "$UKURAN"
printf '\n  Langkah berikutnya di server:\n'
printf '    1. scp %s user@server:/var/www/\n' "$(basename "$KELUAR")"
printf '    2. tar -xzf %s -C /var/www/analisisteks\n' "$(basename "$KELUAR")"
printf '    3. cd /var/www/analisisteks && cp .env.production.example .env && nano .env\n'
printf '    4. php artisan key:generate\n'
printf '    5. ./deploy.sh --tanpa-git\n\n'
