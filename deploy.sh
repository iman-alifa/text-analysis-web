#!/usr/bin/env bash
#
# Deploy AnalisisTeks di server.
#
# Dijalankan DI SERVER, dari dalam folder aplikasi. Aman diulang: seluruh
# langkahnya idempoten, jadi menjalankannya dua kali tidak merusak apa pun.
#
#   ./deploy.sh              # deploy biasa (menarik perubahan dari Git)
#   ./deploy.sh --tanpa-git  # deploy dari berkas yang sudah diunggah manual
#
# Kenapa berupa skrip dan bukan daftar perintah di dokumen: urutannya penting
# dan sebagian langkah mudah terlewat. Lupa `queue:restart` saja sudah cukup
# membuat worker tetap menjalankan kode lama tanpa satu pun tanda di layar.

set -euo pipefail

TANPA_GIT=0
[[ "${1:-}" == "--tanpa-git" ]] && TANPA_GIT=1

info()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()    { printf '    \033[0;32mOK\033[0m %s\n' "$1"; }
fatal() { printf '\n\033[1;31mGAGAL: %s\033[0m\n\n' "$1" >&2; exit 1; }

[[ -f artisan ]] || fatal "Jalankan skrip ini dari folder aplikasi (tidak ada berkas 'artisan' di sini)."

# ---------------------------------------------------------------------------
info "Mengaktifkan halaman pemeliharaan"
# --retry=15 memberi tahu peramban dan mesin pencari untuk mencoba lagi,
# alih-alih mencatat situsnya sebagai mati.
php artisan down --retry=15 || true

# Apa pun yang terjadi di bawah, situs harus kembali menyala. Tanpa trap ini,
# satu langkah gagal akan meninggalkan situs dalam mode pemeliharaan selamanya.
selesai() {
    php artisan up || true
}
trap selesai EXIT

# ---------------------------------------------------------------------------
if [[ $TANPA_GIT -eq 0 ]]; then
    info "Menarik perubahan dari Git"
    git pull --ff-only
    ok "kode diperbarui"
fi

# ---------------------------------------------------------------------------
info "Memasang dependensi PHP"
# --no-dev membuang PHPUnit, Pint, dan Faker dari server.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
ok "vendor/ siap"

# ---------------------------------------------------------------------------
info "Menyiapkan aset tampilan"
if [[ -d node_modules || -f package-lock.json ]] && command -v npm >/dev/null 2>&1; then
    npm ci --silent
    npm run build
    ok "aset dibangun di server"
else
    [[ -f public/build/manifest.json ]] \
        || fatal "public/build/manifest.json tidak ada dan npm tidak tersedia. Bangun aset di laptop lalu unggah folder public/build/."
    ok "memakai aset yang sudah diunggah"
fi

# Berkas ini membuat @vite menunjuk server pengembangan Vite di port 5173.
# Bila ia tertinggal di server, SETIAP halaman tampil tanpa gaya sama sekali.
if [[ -f public/hot ]]; then
    rm -f public/hot
    ok "public/hot dihapus"
fi

# ---------------------------------------------------------------------------
info "Menjalankan migrasi"
php artisan migrate --force
ok "skema basis data mutakhir"

# Paket rilis tidak membawa isi storage/app, jadi pada pemasangan pertama
# foldernya belum ada. mkdir -p aman diulang dan tidak menyentuh isi yang ada.
mkdir -p storage/app/public storage/app/private \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs
php artisan storage:link 2>/dev/null || true

# ---------------------------------------------------------------------------
info "Menyusun ulang cache"
# optimize:clear dulu: cache lama yang masih memuat rute atau config versi
# sebelumnya justru sumber galat yang paling membingungkan setelah deploy.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
ok "config, rute, view, dan event di-cache"

# ---------------------------------------------------------------------------
info "Memuat ulang worker antrean"
# Worker menyimpan kode PHP di memori. Tanpa langkah ini ia terus menjalankan
# versi lama sampai proses aslinya berhenti, dan tidak ada tanda apa pun.
#
# TIDAK ada `supervisorctl restart` di sini, untuk kedua worker. `queue:restart`
# sudah cukup: tiap worker menyelesaikan job yang sedang berjalan, keluar, lalu
# Supervisor (autorestart) menghidupkannya lagi dengan kode baru.
#
# Menambahkan `supervisorctl restart` sesudahnya justru berbahaya. Worker yang
# menganggur keluar hampir seketika karena queue:restart, Supervisor langsung
# memunculkan proses baru, dan SIGTERM dari restart tiba saat proses itu baru
# berumur beberapa milidetik, masih bootstrap. Sinyalnya hilang sebelum Laravel
# memasang penangannya, dan Supervisor menunggu sampai stopwaitsecs. Terjadi
# di server 24 Sep 18.26: worker pelatihan yang menganggur tertahan 11 menit,
# situs tetap dalam mode pemeliharaan, sampai dihentikan dengan SIGKILL.

# PID worker saat ini, satu per baris. `|| true` wajib: skrip ini berjalan dengan
# `set -euo pipefail`, dan grep tanpa hasil (worker belum ada) akan menghentikannya.
pid_worker() {
    command -v supervisorctl >/dev/null 2>&1 || return 0
    sudo supervisorctl status 'analisisteks-worker:*' 'analisisteks-training:*' 2>/dev/null \
        | grep -o 'pid [0-9]*' | awk '{print $2}' | sort || true
}

PID_LAMA="$(pid_worker)"
php artisan queue:restart
ok "sinyal restart dikirim"

# "RUNNING" saja tidak membuktikan kode baru sudah dipakai: proses lama juga
# RUNNING. Yang membuktikan adalah PID yang berganti. Worker menganggur keluar
# dalam hitungan detik (worker pelatihan memakai --sleep=10), jadi ditunggu
# sampai 45 detik. Worker yang sedang mengerjakan job boleh mempertahankan PID
# lamanya sampai job itu selesai; itu dilaporkan, bukan dianggap gagal, karena
# skrip ini tidak menolak berjalan saat ada analisis.
if [[ -n "$PID_LAMA" ]]; then
    for _ in $(seq 1 15); do
        sleep 3
        PID_BARU="$(pid_worker)"
        SISA="$(comm -12 <(echo "$PID_LAMA") <(echo "$PID_BARU") || true)"
        [[ -z "$SISA" && -n "$PID_BARU" ]] && break
    done

    if [[ -z "$SISA" && -n "$PID_BARU" ]]; then
        ok "semua worker sudah berganti proses dan memakai kode baru"
    else
        printf '    \033[0;33mCATATAN\033[0m %s worker masih memakai proses lama (mungkin sedang mengerjakan job).\n' "$(echo "$SISA" | grep -c . || true)"
        printf '             Ia berganti sendiri setelah job-nya selesai. Pantau: sudo supervisorctl status\n'
    fi
else
    printf '    \033[0;33mCATATAN\033[0m PID worker tidak terbaca (supervisorctl tidak ada atau belum dikonfigurasi).\n'
    printf '             Pastikan worker antrean berjalan: sudo supervisorctl status\n'
fi

# ---------------------------------------------------------------------------
info "Memeriksa hasil pemasangan"
# Sengaja SEBELUM `php artisan up` di trap: bila ada yang gagal, keluarannya
# terbaca lebih dulu daripada situs yang kembali menyala.
php artisan deploy:check --produksi || fatal "Pemeriksaan kesiapan gagal. Perbaiki yang ditandai di atas."

printf '\n\033[1;32mDeploy selesai.\033[0m\n\n'
