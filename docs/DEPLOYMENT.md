# Panduan Deploy

Langkah pemasangan AnalisisTeks ke server. Ikuti berurutan; bagian yang boleh
dilewati ditandai jelas.

---

## 0. Pahami dulu: ini DUA aplikasi di SATU server

| Bagian | Bahasa | Kebutuhan | Port |
|---|---|---|---|
| `text-analysis-web` | PHP 8.3 / Laravel | MySQL, Nginx, dua worker antrean | 80/443 |
| `nlp-api-service` | Python 3.11 | PyTorch (CPU), ~1,4 GB bobot model | 8001, **hanya 127.0.0.1** |

Aplikasi web **tidak bisa berjalan sendiri**. Setiap analisis memanggil layanan
Python lewat HTTP, dan tanpa layanan itu semua analisis gagal.

> **Shared hosting dan cPanel biasa TIDAK CUKUP.** Layanan NLP memerlukan
> proses Python yang hidup terus dengan sekitar 1,8 GB RAM, dan aplikasi web
> memerlukan worker antrean yang juga hidup terus.

### Rencana yang dipakai

Kedua komponen berada dalam satu repositori: aplikasi web di akar, layanan NLP
di folder `nlp-api-service/`. Hugging Face tidak lagi
menyediakan Space Docker gratis, dan hosting layanan di tempat terpisah
menambah tagihan kedua serta masalah bobot yang hilang saat deploy ulang.

| | Pilihan |
|---|---|
| Server | **Satu VPS** untuk web + layanan NLP: **Rumahweb VPS XL**, zona TechnoVillage (Indonesia) |
| Spesifikasi | 4 vCPU Intel Xeon Gold 6138 @ 2,0 GHz, 7,8 GB RAM, 158 GB disk, **swap dinaikkan ke 4 GB** |
| OS | Ubuntu 24.04 LTS, zona waktu Asia/Jakarta |
| Domain | Subdomain **`text-analyze.ing.biz.id`** (induk `ing.biz.id`, berlaku sampai 23-09-2027). Satu A record, **tanpa `www`**, tanpa subdomain `api.` |
| HTTPS | Let's Encrypt untuk subdomain itu saja |

Spesifikasi lain tetap sah selama memenuhi 4 vCPU / 8 GB. Dua jebakan di bawah
(mirror apt dan swap bawaan) khas image Rumahweb, tetapi pemeriksaannya berlaku
untuk penyedia mana pun.

Mengapa 8 GB dan 4 vCPU:

| Pemakai | Perkiraan RAM |
|---|---|
| Layanan NLP setelah `/api/warmup` | 1,79 GB (terukur) |
| Puncak saat pelatihan ulang | ~3,2 GB (perkiraan) |
| Nginx, PHP-FPM, MySQL, dua worker | ~1,5 GB |
| Analisis topik (tidak mengambil lock, bisa berjalan bersamaan dengan pelatihan) | tambahan, bergantung ukuran korpus |

Kecepatan analisis **terukur di VPS ini** (OMP_NUM_THREADS=3), 3.366 komentar
lewat jalur yang sama dengan aplikasi:

| Jalur | Waktu |
|---|---|
| Sentimen, 68 batch x 50 | 3,6 menit |
| Aspek, 68 batch x 50 | 9,5 menit |
| Topik, satu permintaan utuh (jumlah topik otomatis) | 16,2 menit (972 detik) |
| **Analisis gabungan** | **29,4 menit**, di bawah batas job 60 menit |

Sebanding dengan laptop pengembang (1.885 detik untuk analisis yang sama).
Pemodelan topik tidak dipecah per batch, jadi batas HTTP-nya sendiri
(`NLP_API_TOPIC_TIMEOUT`, 2400 detik) yang menentukan ukuran korpus terbesar
yang bisa dianalisis sekaligus: kira-kira 2,4x korpus di atas.

Pelatihan ulang berjalan di CPU. Terukur di laptop (Ryzen 7 5700U, 8 core):
1,68 detik/sampel sentimen, 1,06 aspek. vCPU di VPS bersama diperkirakan 2-3x
lebih lambat, jadi pada kolam sekarang satu putaran penuh memakan **41-61
menit**. Makin banyak core, makin pendek.

### Varian lain

1. **Lokal saja.** Untuk demo sidang, jalankan keduanya di laptop dengan
   `composer dev` dan `python run.py`. Lewati panduan ini.
2. **Layanan NLP di server terpisah.** Hanya bila suatu saat bebannya perlu
   dipisah. Server web cukup 1-2 GB RAM, tetapi layanan NLP harus diberi HTTPS
   sendiri (`https://api.domain-anda`), `NLP_API_URL` diarahkan ke sana, dan
   port layanan hanya menerima IP server web. Bobot `models/` harus berada di
   disk permanen server itu.

### Kunci API: satu nilai di dua tempat

Berlaku juga di satu VPS. Port 8001 ditutup firewall, tetapi layanan menolak
berjalan dengan `APP_ENV=production` tanpa kunci, dan kunci adalah lapis kedua
bila firewall suatu saat terbuka.

| Tempat | Nama | Diisi di |
|---|---|---|
| `nlp-api-service` | `API_KEY` | `/opt/nlp-api-service/.env` |
| `text-analysis-web` | `NLP_API_KEY` | `/var/www/analisisteks/.env` |

Keduanya harus **sama persis**. Buat kunci baru untuk produksi, jangan memakai
ulang kunci pengembangan yang ada di laptop:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

`deploy:check --produksi` memeriksa kunci ini terhadap endpoint berkunci.
"Layanan NLP merespons" bisa hijau walaupun kuncinya salah, karena `/health`
sengaja terbuka tanpa kunci.

### Yang diukur pada pelatihan pertama di VPS

Angka 41-61 menit di atas masih perkiraan. Pada putaran pertama di server:

1. **Catat lamanya** dari riwayat di `/admin/training`, lalu setel
   `RETRAIN_SEC_PER_SAMPLE_SENTIMENT` dan `RETRAIN_SEC_PER_SAMPLE_ASPECT` di
   `.env` layanan dengan durasi dibagi jumlah sampel. Nilai itu hanya dipakai
   untuk memperkirakan `Retry-After` saat analisis ditolak; bawaannya (1,7 dan
   1,1) diukur di laptop.
2. **Pantau memori** dengan `free -h` selama pelatihan. Bila swap terpakai
   banyak, kurangi `OMP_NUM_THREADS` atau naikkan paket VPS.

---

## 0b. Amankan server LEBIH DULU

Kerjakan bagian ini begitu VPS menyala, sebelum memasang apa pun. VPS publik
mulai menerima percobaan tebak kata sandi SSH beberapa menit setelah dibuat,
dan login `root` dengan kata sandi adalah cara paling umum VPS murah diambil
alih. Kalau itu terjadi, basis data koreksi - satu-satunya data yang tak
tergantikan - ikut hilang.

Contoh di bawah memakai laptop Windows 11 (PowerShell) dan Ubuntu 24.04.
Ganti `IP` dengan alamat VPS dan `deploy` dengan nama pengguna pilihan Anda.

### 0) Pastikan server benar-benar menerima pembaruan keamanan

Lakukan paling awal, sebelum apa pun. **Image Rumahweb memakai mirror
`cermin.rumahweb.id` yang tidak menyajikan `noble-updates` dan
`noble-security`.** Akibatnya `apt upgrade` melaporkan "0 upgraded" tanpa galat
apa pun, padahal server tertahan di paket rilis April 2024, termasuk
`openssh-server` yang rentan **CVE-2024-6387 (regreSSHion, eksekusi kode dari
jarak jauh sebelum login)**.

```bash
sudo apt update
apt-cache policy openssh-server
```

Baris `Candidate` harus berakhiran `ubuntu13.` diikuti angka (mis.
`1:9.6p1-3ubuntu13.19`). Bila hanya `1:9.6p1-3ubuntu13` tanpa titik, sumber
apt-nya tidak memuat pembaruan. Ganti ke arsip resmi Ubuntu:

```bash
# cadangkan sumber bawaan penyedia
sudo cp /etc/apt/sources.list.d/ubuntu.sources /etc/apt/ubuntu.sources.bawaan.bak
sudo nano /etc/apt/sources.list.d/ubuntu.sources
```

```
Types: deb
URIs: http://archive.ubuntu.com/ubuntu/
Suites: noble noble-updates noble-backports
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg

Types: deb
URIs: http://security.ubuntu.com/ubuntu/
Suites: noble-security
Components: main restricted universe multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
```

Bila server memakai `/etc/apt/sources.list` gaya lama, kosongkan berkas itu
(atau beri tanda `#` pada semua barisnya) supaya mirror lama tidak ikut dibaca.

```bash
sudo apt update
apt-cache policy openssh-server      # Candidate kini ubuntu13.x
sudo apt full-upgrade -y
sudo reboot
```

Setelah menyala lagi, `apt-cache policy openssh-server` harus menunjukkan versi
`Installed` yang sama dengan `Candidate`.

### 1) Kunci SSH di laptop

```powershell
# PowerShell di laptop. Tekan Enter untuk lokasi bawaan; isi passphrase.
ssh-keygen -t ed25519 -C "laptop-skripsi"
```

Windows tidak punya `ssh-copy-id`, jadi salin kunci publiknya begini (sekali
ini masih memakai kata sandi root dari penyedia VPS):

```powershell
type $env:USERPROFILE\.ssh\id_ed25519.pub | ssh root@IP "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

Beberapa penyedia (termasuk Contabo) juga menerima kunci publik saat memesan.
Kalau sudah diisi di sana, langkah ini dilewati.

### 2) Pengguna sudo selain root

```bash
# di server, sebagai root
adduser deploy
usermod -aG sudo deploy

mkdir -p /home/deploy/.ssh
cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys
```

**Uji di terminal KEDUA, jangan tutup yang pertama:**

```powershell
ssh deploy@IP
sudo -v        # harus meminta kata sandi deploy lalu berhasil
```

Lanjutkan hanya bila keduanya berhasil. Seluruh sisa panduan ini dijalankan
sebagai `deploy` dengan `sudo`, bukan sebagai `root`.

### 3) Matikan login kata sandi dan login root

```bash
sudo nano /etc/ssh/sshd_config.d/01-hardening.conf
```

```
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
```

Namanya harus **`01-`**, bukan `99-`. sshd memakai nilai **pertama** yang ia
baca, dan berkas di folder itu dibaca urut nama. Image cloud Ubuntu sering
membawa `50-cloud-init.conf` berisi `PasswordAuthentication yes`; berkas
bernama `99-` dibaca sesudahnya dan kalah tanpa pesan apa pun.

```bash
sudo sshd -t                        # periksa sintaks; harus diam
sudo systemctl restart ssh
sudo sshd -T | grep -E '^(passwordauthentication|kbdinteractiveauthentication|permitrootlogin) '
```

Keluaran terakhir harus `no` untuk ketiganya. Itu nilai yang benar-benar
berlaku, bukan yang tertulis di berkas.

> **PERINGATAN.** Uji lagi di terminal kedua: `ssh deploy@IP` harus tetap
> masuk. Baru tutup sesi pertama. Kalau konfigurasinya salah dan sesi pertama
> sudah ditutup, server terkunci dari luar dan satu-satunya jalan masuk adalah
> konsol VNC di panel penyedia.

### 4) Firewall

`OpenSSH` **wajib diizinkan sebelum** `ufw enable`. Urutan terbalik memutus
sesi SSH yang sedang dipakai.

Image VPS yang baru dibuat belum pernah memperbarui daftar paketnya, sehingga
`apt install ufw` gagal dengan "has no installation candidate". Karena itu
`apt update` dulu, walau langkah 0 sudah menjalankannya: jangan mengandalkan
urutan bagian yang dikerjakan.

```bash
sudo apt update
sudo apt install -y ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

Port 8001 (layanan NLP) dan 3306 (MySQL) **tidak** dibuka. Keduanya hanya
mendengar di 127.0.0.1.

### 5) fail2ban

```bash
sudo apt update
sudo apt install -y fail2ban
sudo nano /etc/fail2ban/jail.local
```

```ini
[sshd]
enabled = true
# Ubuntu 24.04 tidak selalu menulis /var/log/auth.log; baca dari journald.
# Tanpa baris ini fail2ban bisa gagal start tanpa ada yang menyadari.
backend = systemd
maxretry = 5
bantime = 1h
```

```bash
sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```

### 6) Pembaruan keamanan otomatis

```bash
dpkg -l unattended-upgrades          # di 24.04 biasanya sudah terpasang
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades   # pilih Yes
```

Pembaruan dipasang otomatis, tetapi server **tidak** di-reboot sendiri. Sesekali
periksa `ls /var/run/reboot-required`; bila berkas itu ada, reboot di jam sepi
dan pastikan tidak ada pelatihan ulang yang sedang berjalan.

---

## 1. Siapkan server

Contoh berikut memakai Ubuntu 24.04 LTS.

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 (bawaan Ubuntu 24.04) beserta ekstensi yang dipakai aplikasi
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath unzip git

# Basis data dan web server
sudo apt install -y mysql-server nginx

# Python 3.11 untuk layanan NLP. Ubuntu 24.04 membawa 3.12, sementara
# layanan dikembangkan dan diuji pada 3.11 (runtime.txt), jadi ambil dari PPA.
sudo add-apt-repository -y ppa:deadsnakes/ppa
sudo apt install -y python3.11 python3.11-venv python3.11-dev build-essential

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Swap 4 GB

Jaring pengaman bila pelatihan ulang dan analisis besar bertemu. Tanpa swap,
kernel mematikan proses yang paling boros memori - biasanya layanan NLP - di
tengah pelatihan.

Periksa dulu swap yang sudah ada. Image Rumahweb membawa swap bawaan hanya
**255 MB**, terlalu kecil untuk menjadi jaring pengaman apa pun:

```bash
swapon --show
free -h
```

Bila swap bawaan berupa berkas `/swapfile`, matikan dan buat ulang berukuran
4 GB. Bila berupa partisi (`/dev/...`), biarkan saja dan tambahkan berkas
`/swapfile` di sampingnya; keduanya dipakai bersama.

```bash
# hanya bila /swapfile sudah ada
sudo swapoff /swapfile
sudo rm /swapfile
sudo sed -i '\#^/swapfile #d' /etc/fstab

sudo fallocate -l 4G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

swapon --show                         # total kini sekitar 4 GB
```

Node.js hanya diperlukan bila aset dibangun **di server**. Anda juga boleh
membangunnya di laptop lalu mengunggah folder `public/build` (lihat langkah 4).

### Resolver DNS

Resolver bawaan Rumahweb **gagal kira-kira 1 dari 20 kueri** (terukur). Aplikasi
memanggil Gemini, YouTube, dan Hugging Face dari server, jadi kegagalan
resolver muncul sebagai galat acak "could not resolve host" pada fitur-fitur
itu. Dahulukan resolver publik:

Cara mengubahnya bergantung pada siapa yang mengelola `/etc/resolv.conf`:

```bash
ls -l /etc/resolv.conf
systemctl is-active systemd-resolved
```

**Cabang A: berkas biasa, systemd-resolved `inactive` (image Rumahweb).**
Berkasnya dibangkitkan netplan sekali saat server dibuat, lalu tidak disentuh
lagi, jadi menyuntingnya langsung sudah benar. Cadangkan dulu:

```bash
sudo cp /etc/resolv.conf /etc/resolv.conf.bawaan.bak
sudo nano /etc/resolv.conf
```

```
nameserver 1.1.1.1
nameserver 8.8.8.8
# resolver bawaan penyedia di belakang, sebagai cadangan
nameserver <resolver-bawaan>
```

Samakan juga daftar `nameservers` di berkas netplan (di Rumahweb:
`/etc/netplan/99-virt-eth0.yaml`), **tanpa** `netplan apply`. Gunanya supaya
`netplan apply` suatu hari nanti tidak mengembalikan resolver lama.

**Cabang B: symlink ke `../run/systemd/resolve/stub-resolv.conf`.** Jangan
sunting `/etc/resolv.conf`; isinya ditulis ulang saat reboot atau pembaruan
DHCP. Atur lewat systemd-resolved:

```bash
sudo mkdir -p /etc/systemd/resolved.conf.d
sudo nano /etc/systemd/resolved.conf.d/dns.conf
```

```ini
[Resolve]
DNS=1.1.1.1 8.8.8.8
FallbackDNS=1.0.0.1 8.8.4.4
```

```bash
sudo systemctl restart systemd-resolved
resolvectl status | grep -A2 "DNS Servers"
```

Pada kedua cabang, periksa lagi setelah reboot bahwa urutannya bertahan.

Uji dengan beberapa kueri berturut-turut, bukan satu:

```bash
for i in $(seq 1 20); do getent hosts generativelanguage.googleapis.com >/dev/null || echo "gagal $i"; done
```

Tidak ada keluaran berarti semua berhasil.

### Naikkan batas unggah PHP

Berkas analisis boleh sampai 10 MB, sementara bawaan PHP sering 2 MB. Sunting
`/etc/php/8.3/fpm/php.ini`:

```ini
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 120
memory_limit = 512M
```

Lalu `sudo systemctl restart php8.3-fpm`.

---

## 2. Siapkan basis data

```sql
CREATE DATABASE analisis_teks_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'analisis'@'localhost' IDENTIFIED BY '<kata-sandi-kuat>';
GRANT ALL PRIVILEGES ON analisis_teks_db.* TO 'analisis'@'localhost';
FLUSH PRIVILEGES;
```

`utf8mb4` wajib, bukan `utf8`: komentar YouTube penuh emoji, dan `utf8` versi
MySQL tidak bisa menyimpannya.

---

Pastikan MySQL hanya mendengar di localhost. Bawaan paket Ubuntu memang
begitu, tetapi periksa, karena port 3306 yang terbuka ke internet adalah pintu
langsung ke seluruh data koreksi:

```bash
sudo ss -tlnp | grep 3306     # harus 127.0.0.1:3306, bukan 0.0.0.0:3306
```

---

## 3. Pasang aplikasi web

```bash
cd /var/www
sudo git clone https://github.com/iman-alifa/text-analysis-web analisisteks
cd analisisteks

# --no-dev membuang paket pengembangan (PHPUnit, Pint, Faker) dari server
composer install --no-dev --optimize-autoloader

cp .env.production.example .env
nano .env          # isi setiap <...>
php artisan key:generate

php artisan migrate --force
php artisan storage:link
```

`--force` diperlukan karena `migrate` menolak berjalan tanpa konfirmasi di
lingkungan produksi.

### Akun administrator pertama

Belum ada seeder untuk ini, jadi daftarkan akun lewat halaman Daftar seperti
pengguna biasa, lalu naikkan perannya:

```bash
php artisan tinker --execute="App\Models\User::where('email','<email-anda>')->update(['role'=>'admin']);"
```

Aplikasi tetap berjalan penuh tanpa akun admin. Peran itu hanya membuka menu
Training Model dan Evaluasi Model.

---

## 4. Bangun aset tampilan

**Di server**, bila Node.js terpasang:

```bash
npm ci
npm run build
```

**Atau di laptop**, lalu unggah hasilnya:

```bash
npm run build
# unggah isi public/build/ ke server
```

### Dua jebakan di folder `public/`

**1. Jangan sampai ada berkas `public/hot` di server.** Berkas itu ditulis
`npm run dev` dan membuat Laravel memuat CSS serta JavaScript dari server
pengembangan di port 5173. Bila berkas itu ikut terbawa sementara server
pengembangan tidak berjalan, **setiap halaman tampil tanpa gaya sama sekali**
dan terlihat seperti rusak. Hapus bila ada:
```bash
rm -f public/hot
```

**2. Jangan membuat folder di `public/` yang namanya sama dengan sebuah rute.**

Web server selalu memeriksa berkas dan folder lebih dulu sebelum menyerahkan
permintaan ke Laravel, jadi folder semacam itu **menelan rutenya sendiri**.
Gejalanya berbeda di tiap server sehingga mudah disalahartikan sebagai rute yang
hilang:

| Server | Gejala |
|---|---|
| Server bawaan PHP | `404 The requested resource /x was not found on this server` |
| Apache | Rewrite dilewati (`RewriteCond %{REQUEST_FILENAME} !-d`), tidak sampai `index.php` |
| nginx | `403`, karena `try_files $uri $uri/` cocok ke foldernya |

`php artisan route:list` tetap menampilkan rutenya dengan normal, jadi
penyebabnya tidak terlihat dari sisi Laravel sama sekali.

Karena itu tangkapan layar pedoman diletakkan di `public/img/panduan/`, bukan
`public/panduan/`. Ada tes yang menjaganya (`PanduanTest`), dengan `storage`
dikecualikan karena symlink `storage:link` memang sengaja menaungi rute
`storage/{path}`.

---

## 5. Izin berkas

```bash
sudo chown -R www-data:www-data /var/www/analisisteks
sudo chmod -R 755 /var/www/analisisteks
sudo chmod -R 775 /var/www/analisisteks/storage
sudo chmod -R 775 /var/www/analisisteks/bootstrap/cache
```

`storage` dan `bootstrap/cache` harus bisa ditulis; sisanya tidak boleh.

`.env` berisi kata sandi basis data dan kunci API, jadi persempit izinnya:

```bash
sudo chown www-data:www-data /var/www/analisisteks/.env
sudo chmod 640 /var/www/analisisteks/.env
```

Akibatnya pengguna `deploy` tidak bisa membacanya. Perintah yang membaca `.env`
(`grep`, `cat`, `backup-db.sh`) harus diawali `sudo`. Di dalam skrip dengan
`set -e`, `grep` yang gagal "Permission denied" menghentikan skrip di tempat,
sebelum langkah sesudahnya (mis. `config:cache`) sempat berjalan.

---

## 6. Web server

Buat `/etc/nginx/sites-available/analisisteks`:

```nginx
server {
    listen 80;
    # Server punya IPv6. Bila record AAAA diisi, Let's Encrypt memvalidasi
    # lewat IPv6 lebih dulu, dan tanpa baris ini validasinya gagal.
    listen [::]:80;
    server_name text-analyze.ing.biz.id;

    # Document root menunjuk ke public/, BUKAN ke akar proyek. Mengarahkannya
    # ke akar akan membuat .env bisa diunduh siapa pun lewat peramban.
    root /var/www/analisisteks/public;
    # index.html untuk halaman anotasi statis (public/anotasi/). Tanpa itu
    # /anotasi/ membalas 403: nginx tidak menemukan index.php di foldernya,
    # dan tautan "Buka halaman anotasi" di admin mengarah ke sana.
    index index.php index.html;

    charset utf-8;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Analisis besar butuh waktu. Tanpa ini nginx memutus permintaan
        # pengiriman analisis di detik ke-60.
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/analisisteks /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### HTTPS

Isi DNS lebih dulu, di panel Rumahweb untuk domain induk `ing.biz.id`:

| Tipe | Nama | Nilai |
|---|---|---|
| A | `text-analyze` | IPv4 VPS |
| AAAA (opsional) | `text-analyze` | IPv6 VPS |

Tunggu sampai `nslookup text-analyze.ing.biz.id` mengembalikan IP VPS, baru
jalankan certbot. Certbot yang dijalankan sebelum DNS menyebar gagal, dan
percobaan gagal yang berulang terkena batas laju Let's Encrypt.

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d text-analyze.ing.biz.id
```

Hanya satu nama, tanpa `www`: subdomain tidak memerlukannya, dan meminta
sertifikat untuk nama yang tidak punya record DNS membuat seluruh permintaan
gagal.

Setelah HTTPS aktif, pastikan `.env` memuat
`APP_URL=https://text-analyze.ing.biz.id` dan `SESSION_SECURE_COOKIE=true`.

### Header keamanan

Pasang **setelah** certbot, karena header HSTS hanya bermakna pada blok HTTPS.
Buat `/etc/nginx/snippets/header-keamanan.conf`:

```nginx
# `always` supaya ikut pada respons galat.
# HSTS tanpa includeSubDomains: subdomain lain di ing.biz.id belum tentu HTTPS.
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
```

lalu tambahkan `include snippets/header-keamanan.conf;` di blok server HTTPS
tepat di bawah `client_max_body_size`, dan `server_tokens off;` di
`/etc/nginx/conf.d/server-tokens.conf` supaya versi nginx tidak diumumkan.

Jangan menaruh `add_header` di dalam blok `location` mana pun: nginx tidak
mewariskan `add_header` ke `location` yang punya `add_header` sendiri, jadi
satu baris di sana diam-diam menghapus seluruh header di atas untuk jalur itu.

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -sI https://text-analyze.ing.biz.id/login | grep -i strict-transport
```

---

## 7. Worker antrean (WAJIB, DUA buah)

Tanpa worker, **setiap analisis berhenti selamanya di status "sedang
diproses"**. Ada dua antrean, dan masing-masing butuh worker sendiri:

| Worker | Antrean | Isi | Batas waktu |
|---|---|---|---|
| `analisisteks-worker` | `default` (koneksi `database`) | analisis, evaluasi | 3.600 dtk per job |
| `analisisteks-training` | `training` (koneksi `training`) | pelatihan ulang | 7.200 dtk per job |

Dipisah karena satu putaran pelatihan di VPS memakan 41-61 menit. Dengan satu
worker bersama, analisis pengguna bahkan tidak dimulai selama itu.

Pasang Supervisor:

```bash
sudo apt install -y supervisor
sudo nano /etc/supervisor/conf.d/analisisteks.conf
```

```ini
[program:analisisteks-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/analisisteks/artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=3700
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/analisisteks/storage/logs/worker.log
stopwaitsecs=3700

[program:analisisteks-training]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/analisisteks/artisan queue:work training --queue=training --sleep=10 --tries=1 --timeout=7300
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/analisisteks/storage/logs/training-worker.log
stopwaitsecs=7300
```

Angka yang tidak boleh diubah sembarangan:

- `--tries=1` - job yang gagal tidak diulang otomatis oleh worker. Analisis
  mengatur percobaan ulangnya sendiri, termasuk menunggu pelatihan selesai.
- `--timeout=3700` / `7300` - sedikit di atas `$timeout` masing-masing job.
- `numprocs=1` di keduanya - layanan NLP hanya punya satu worker, jadi dua
  analisis serentak hanya mengantre di sana dan menggandakan pemakaian RAM.

Rantai batas waktu yang dijaga tes dan `deploy:check`:

```
analisis : HTTP 600  < job 3600 < retry_after 4200 (DB_QUEUE_RETRY_AFTER)
topik    : HTTP 2400 < job 3600
pelatihan: HTTP 6600 < job 7200 < retry_after 7500 (DB_TRAINING_RETRY_AFTER)
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

**Setiap kali kode diperbarui**, kedua worker harus dimuat ulang. Worker
menyimpan kode di memori, jadi perubahan tidak berlaku sampai ia dimulai ulang
(`deploy.sh` sudah melakukannya):

```bash
php artisan queue:restart
```

Itu saja. Tiap worker menyelesaikan job yang sedang berjalan, keluar, lalu
Supervisor menghidupkannya lagi dengan kode baru.

> **Jangan menyusulkan `supervisorctl restart`** sesudah `queue:restart`. Worker
> yang menganggur keluar hampir seketika dan Supervisor langsung memunculkan
> proses baru. SIGTERM dari restart lalu tiba saat proses itu masih bootstrap,
> sinyalnya hilang, dan Supervisor menunggu sampai `stopwaitsecs` (sampai 2 jam
> untuk worker pelatihan). Terjadi di server 24 Sep: situs tertahan di mode
> pemeliharaan 11 menit. `supervisorctl restart` hanya diperlukan setelah
> mengubah berkas konfigurasi Supervisor, dan saat itu jangan jalankan
> `queue:restart` bersamaan.

> Me-restart worker pelatihan **saat pelatihan berjalan** tidak menghentikan
> pelatihan di layanan NLP, tetapi riwayatnya tidak akan tercatat selesai.
> Periksa `/admin/training` lebih dulu.

### Analisis yang datang saat pelatihan berjalan

Bila saklar `RETRAIN_REJECT_INFERENCE=true` di layanan NLP, analisis yang butuh
model yang sedang dilatih langsung dibalas 503 + `Retry-After`. Aplikasi web
melepas job kembali ke antrean sebesar jeda itu dan menampilkan "Model sedang
dilatih ulang. Analisis dilanjutkan otomatis sekitar N menit lagi." Menunggu ini
**tidak** menghabiskan jatah percobaan; batasnya jendela 8 jam sejak analisis
dikirim.

### Penjadwal Laravel (WAJIB untuk pemicu pelatihan)

`routes/console.php` menjadwalkan `analysis:auto-retrain` setiap jam. Tanpa
penjadwal, pemicu pelatihan hanya diperiksa saat pengguna menekan simpan pada
halaman koreksi. Koreksi yang datang berdempetan lalu jatuh dalam jeda 6 jam,
dan bila tidak ada yang menyimpan lagi setelah jeda habis, koreksi itu tidak
pernah memicu apa pun. Terjadi di server 24 Sep: 36 dari 115 koreksi pengguna
asli tidak ikut putaran mana pun.

```bash
echo '* * * * * cd /var/www/analisisteks && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo crontab -u www-data -
sudo crontab -u www-data -l
sudo -u www-data php /var/www/analisisteks/artisan schedule:list
```

Crontab ini milik `www-data`, bukan root: perintah yang dijadwalkan menulis ke
`storage/` dan cache, dan berkas yang dibuat root di sana membuat PHP-FPM gagal
menulisnya belakangan.

---

## 8. Layanan NLP (nlp-api-service)

Kode layanan ada di folder `nlp-api-service/` pada repositori ini. Dijalankan
dengan systemd + venv, bukan
Docker: lebih ringan di satu VPS, log menyatu di `journalctl`, dan `models/`
otomatis berada di disk server.

### Pasang

```bash
sudo useradd --system --create-home --home-dir /opt/nlp-api-service nlp
# Ambil folder nlp-api-service/ dari repositori yang sama dengan aplikasi web
git clone --depth 1 https://github.com/iman-alifa/text-analysis-web /tmp/analisisteks-src
sudo cp -r /tmp/analisisteks-src/nlp-api-service/. /opt/nlp-api-service/
sudo chown -R nlp:nlp /opt/nlp-api-service
cd /opt/nlp-api-service

sudo -u nlp python3.11 -m venv venv
# torch versi CPU DULU. Build CUDA dari PyPI membawa beberapa GB pustaka
# NVIDIA yang tidak terpakai tanpa GPU. 2.9.1+cpu juga build yang melatih
# seluruh checkpoint, jadi bukan kompromi.
sudo -u nlp venv/bin/pip install torch==2.9.1 --index-url https://download.pytorch.org/whl/cpu
sudo -u nlp venv/bin/pip install -r requirements.txt
```

Urutannya penting: `torch==2.9.1` di `requirements.txt` sudah terpenuhi oleh
`2.9.1+cpu`, jadi pip tidak menarik build CUDA di langkah kedua.

### Bobot model

Folder `models/` **tidak ikut Git**. Salin dari laptop (~1,42 GB):

```bash
# dijalankan dari laptop, di folder nlp-api-service
scp -r models/sentiment_retrained \
       models/aspect_retrained.pt \
       models/aspect_retrained.pt.provenance.json \
       models/aspect_baseline.pt \
       user@server:/tmp/models/
# lalu di server
sudo mkdir -p /opt/nlp-api-service/models
sudo mv /tmp/models/* /opt/nlp-api-service/models/
sudo chown -R nlp:nlp /opt/nlp-api-service/models
```

| Berkas | Kenapa |
|---|---|
| `sentiment_retrained/` | bobot sentimen iterasi 6, tokenizer, kalibrasi, provenance |
| `aspect_retrained.pt` + `.provenance.json` | bobot aspek iterasi 6 |
| `aspect_baseline.pt` | **titik berangkat protokol kolam** untuk aspek. Tanpanya pelatihan diam-diam melanjutkan checkpoint, kesalahan yang dulu membatalkan iterasi 2 |

`aspect_retrained.pt.bak_k1` **tidak ikut**; itu eksperimen lama.

Model dasar dari Hugging Face diunduh saat pemasangan, bukan di tengah
pelatihan pertama, dengan revisi yang sama dengan mesin pengembang:

```bash
sudo -u nlp env HF_HOME=/opt/nlp-api-service/.cache/huggingface \
  /opt/nlp-api-service/venv/bin/hf download crypter70/IndoBERT-Sentiment-Analysis \
  --revision e4f806186f3e4adebe369bef85b328bc3c819eb2
sudo -u nlp env HF_HOME=/opt/nlp-api-service/.cache/huggingface \
  /opt/nlp-api-service/venv/bin/hf download indobenchmark/indobert-base-p1 \
  --revision c2cd0b51ddce6580eb35263b39b0a1e5fb0a39e2
```

### Konfigurasi (`/opt/nlp-api-service/.env`)

```
APP_ENV=production
DEBUG=False
API_KEY=<sama persis dengan NLP_API_KEY di web>
HOST=127.0.0.1
PORT=8001
TRUST_PROXY=false
# Mutlak, dengan alasan yang sama seperti HF_HOME di unit systemd: jalur
# relatif bergantung pada direktori kerja proses.
MODEL_PATH=/opt/nlp-api-service/models
# Titik berangkat protokol kolam untuk aspek. Dulu bawaannya kosong: pelatihan
# tidak menemukan bobot dasar, menulis satu WARNING, lalu MELANJUTKAN
# checkpoint - kesalahan yang membatalkan iterasi 2. Layanan kini jatuh ke
# <MODEL_PATH>/aspect_baseline.pt, tetapi tulis eksplisit.
ASPECT_BASELINE_CHECKPOINT=/opt/nlp-api-service/models/aspect_baseline.pt
# Cadangan bobot di luar server (repo privat, satu commit per pelatihan)
HF_WEIGHTS_REPO=imanalifan/skripsi-nlp-weights
HF_TOKEN=<token bertipe WRITE>
# Nyalakan SETELAH aplikasi web terpasang dengan versi yang membaca Retry-After
RETRAIN_REJECT_INFERENCE=true
```

`TRUST_PROXY=false` karena Laravel memanggil lewat loopback tanpa proxy di
depannya.

### Jalankan sebagai layanan

`sudo nano /etc/systemd/system/nlp-api.service`:

```ini
[Unit]
Description=NLP Analysis API
After=network-online.target
Wants=network-online.target

[Service]
User=nlp
WorkingDirectory=/opt/nlp-api-service
EnvironmentFile=/opt/nlp-api-service/.env
# Mutlak. Bawaannya relatif, sehingga lokasi cache ditentukan lingkungan.
Environment=HF_HOME=/opt/nlp-api-service/.cache/huggingface
# Sisakan satu vCPU untuk PHP-FPM dan MySQL. Tanpa ini torch memakai keempat
# vCPU saat pelatihan dan situs terasa beku selama 40-60 menit.
Environment=OMP_NUM_THREADS=3
Environment=MKL_NUM_THREADS=3
ExecStart=/opt/nlp-api-service/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1
Restart=on-failure
RestartSec=5
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nlp-api
sudo systemctl status nlp-api
curl http://127.0.0.1:8001/health
```

- `--workers 1` **wajib**: bobot model hidup di memori proses, dan dua worker
  berarti dua salinan bobot yang menyimpang setelah pelatihan.
- `--host 127.0.0.1` ditulis eksplisit walau bawaannya sudah begitu.
- Me-restart layanan saat pelatihan berjalan membuang pelatihan itu. Checkpoint
  lama tetap utuh; hanya sia-sia.
- **Start dingin bisa sampai 3,5 menit**, walaupun biasanya hitungan detik.
  Terukur 27 Sep: impor torch/transformers/BERTopic butuh 3 menit 27 detik
  karena cache disk baru saja kosong setelah dua berkas bobot 495 MB disalin.
  Tahap aplikasinya sendiri 0,01 detik. Selama itu port 8001 belum terbuka dan
  analisis yang masuk gagal tersambung. Tunggu `/health` sampai 5 menit sebelum
  menyimpulkan layanan rusak:
  `for i in $(seq 1 150); do curl -sf localhost:8001/health && break; sleep 2; done`
- Layanan juga menulis `logs/api.log`. Pastikan direktori itu bisa ditulis
  user `nlp`, atau kosongkan `LOG_FILE` dan andalkan journald.

### Pengerasan dan batas memori

`sudo mkdir -p /etc/systemd/system/nlp-api.service.d` lalu buat
`/etc/systemd/system/nlp-api.service.d/pengerasan.conf`:

```ini
[Service]
# Layanan hanya boleh menulis ke miliknya sendiri; sisa sistem berkas hanya-baca.
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=/opt/nlp-api-service/models /opt/nlp-api-service/.cache /opt/nlp-api-service/logs
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
# Satu pelatihan memuncak ~3,9 GB di atas model yang dimuat (terukur); 5G menekannya
# 4,5 juta kali. Diperlambat di 6 GB, dihentikan di 7 GB,
# sehingga MySQL dan web tetap punya ruang. Restart=on-failure menyalakannya lagi.
MemoryHigh=6G
MemoryMax=7G
# Lebih sedikit arena glibc = lebih sedikit memori bebas yang tertahan setelah
# pelatihan (terukur 2.890 -> 1.698 MB sebelum malloc_trim).
Environment=MALLOC_ARENA_MAX=2
# Tanpa hak istimewa kernel sama sekali; layanan tidak butuh satu pun.
CapabilityBoundingSet=
AmbientCapabilities=
PrivateDevices=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallErrorNumber=EPERM
UMask=0027
# MemoryDenyWriteExecute SENGAJA tidak dipasang: oneDNN (PyTorch CPU) membuat
# kode mesin saat berjalan dan akan gagal di bawah pembatasan itu.
```

```bash
sudo systemctl daemon-reload && sudo systemctl restart nlp-api
systemd-analyze security nlp-api | tail -1     # sekitar 1,7
```

**Batas memori dan pelepasannya satu paket.** Setelah pelatihan, alokator
glibc tidak mengembalikan memori bebas ke sistem operasi. Terukur di server
(26–27 Sep, proses terpisah, 12 langkah pelatihan IndoBERT): puncak 3.879 MB,
setelah `gc.collect()` masih 2.890 MB, setelah `malloc_trim(0)` 331 MB. Layanan
kini memanggil `malloc_trim` sendiri setelah tiap retraining
(`app/utils/training.py: lepas_memori`). Sebelum itu, dua putaran berturut-turut
membuat proses menetap di 2,4 GB RAM + 4,1 GB swap, `MemoryHigh=5G` menekannya
4,5 juta kali, dan evaluasi hold-out sesudahnya gagal karena setiap permintaan
analisis aspek melewati batas 600 detik. Cara memeriksanya:

```bash
P=$(systemctl show -p MainPID --value nlp-api); grep -E 'VmRSS|VmSwap|VmHWM' /proc/$P/status
cat /sys/fs/cgroup/system.slice/nlp-api.service/memory.events   # baris "high" = berapa kali ditekan
```

### Tutup portnya dari internet

Firewall sudah dipasang di bagian 0b dan **tidak** membuka port 8001. Tidak ada
yang perlu ditambahkan di sini. Layanan mendengar di 127.0.0.1 saja, firewall
adalah lapis kedua, dan kunci API lapis ketiga. Periksa:

```bash
sudo ss -tlnp | grep 8001     # harus 127.0.0.1:8001
```

---

## 9. Optimasi produksi

Jalankan **setiap kali selesai memperbarui kode**:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Membersihkannya bila ada yang aneh:

```bash
php artisan optimize:clear
```

> `config:cache` membuat seluruh pemanggilan `env()` di luar berkas config
> mengembalikan null. Aplikasi ini sudah bersih dari itu (sudah diverifikasi),
> jadi perintahnya aman dijalankan.

---

## 10. Uji hasil pemasangan

Cara tercepat, satu perintah:

```bash
php artisan deploy:check --produksi
```

Perintah ini memeriksa APP_KEY, APP_DEBUG, `retry_after` versus timeout job,
aset terbangun, symlink storage, izin tulis, tabrakan folder dengan rute,
folder `.git` di dalam `public/`, koneksi dan migrasi basis data, antrean, serta
layanan NLP. Kode keluarnya bukan nol bila ada yang gagal, jadi ia juga dipakai
`deploy.sh` sebagai gerbang terakhir.

### Pemeriksaan manual

```bash
# 1. Layanan NLP hidup?
curl http://127.0.0.1:8001/health

# 2. Aplikasi bisa menjangkaunya?
php artisan nlp:test

# 3. Worker berjalan?
sudo supervisorctl status
```

Lalu lewat peramban:

1. Buka halaman depan. Bila tampil **tanpa gaya sama sekali**, periksa
   `public/hot` dan `public/build` (langkah 4).
2. Buka `/panduan`. Halaman ini bisa dibuka tanpa masuk.
3. Daftar, masuk, jalankan satu analisis berisi 10 kalimat.
4. Pastikan statusnya berubah menjadi selesai dalam satu menit. Bila berhenti di
   "sedang diproses", workernya belum jalan.

---

## 11. Perawatan rutin

| Hal | Perintah |
|---|---|
| Log aplikasi | `tail -f storage/logs/laravel.log` |
| Log worker | `tail -f storage/logs/worker.log` |
| Log layanan NLP | `sudo journalctl -u nlp-api -f` |
| Job yang gagal | `php artisan queue:failed` |
| Log layanan NLP (berkas) | `tail -f /opt/nlp-api-service/logs/api.log` |
| Log worker pelatihan | `tail -f storage/logs/training-worker.log` |

### Cadangan basis data (WAJIB)

Yang tak tergantikan di seluruh sistem adalah isi basis data: koreksi pengguna,
golden dataset, dan riwayat evaluasi. Bobot model bisa dibangkitkan ulang dari
kolam koreksi (protokol kolam deterministik, seed 42), tetapi koreksinya
sendiri tidak bisa.

`backup-db.sh` membuat dump terkompresi harian, menyimpan 14 hari di server,
dan menyalinnya ke luar server bila `RCLONE_REMOTE` diisi. Cadangan yang hanya
ada di server yang sama ikut hilang bersama servernya, jadi isi variabel itu.

```bash
chmod +x /var/www/analisisteks/backup-db.sh
sudo /var/www/analisisteks/backup-db.sh     # uji sekali

# Jadwalkan pukul 02.30 setiap hari
sudo crontab -e
```

```
30 2 * * * RCLONE_REMOTE=gdrive:analisisteks-backup /var/www/analisisteks/backup-db.sh >> /var/log/analisisteks-backup.log 2>&1
```

`gdrive:` adalah contoh remote rclone yang disiapkan sekali dengan
`rclone config`. Tujuan lain (OneDrive, S3, server lain lewat SFTP) sama saja.

Memulihkan:

```bash
gunzip -c /var/backups/analisisteks/analisis_teks_db-<stempel>.sql.gz | mysql -u analisis -p analisis_teks_db
```

Bobot model tercadang sendiri ke repo Hugging Face privat (`HF_WEIGHTS_REPO`)
setiap pelatihan diterima. Setelah pasang ulang server: kosongkan `models/`,
start layanan, bobotnya tertarik sendiri.

### Berkas yang tumbuh

Berkas yang tumbuh seiring pemakaian dan perlu sesekali dibersihkan:

- `storage/app/public/exports/` — hasil YouTube Scraper
- `storage/app/public/uploads/` — berkas yang diunggah pengguna
- `storage/logs/` — dibatasi sendiri bila `LOG_CHANNEL=daily`

---

## Memperbarui kode setelah terpasang

Seluruh langkahnya sudah dibungkus skrip, karena urutannya penting dan sebagian
mudah terlewat. Lupa `queue:restart` saja sudah cukup membuat worker terus
menjalankan kode lama tanpa satu pun tanda di layar.

```bash
cd /var/www/analisisteks
./deploy.sh                  # menarik dari Git
./deploy.sh --tanpa-git      # bila berkas diunggah manual
```

Skripnya menyalakan halaman pemeliharaan, memasang dependensi, membangun atau
memakai aset yang sudah ada, menjalankan migrasi, menyusun ulang cache, memuat
ulang worker, lalu menutup dengan `deploy:check --produksi`. Situs dikembalikan
menyala lewat `trap` walaupun ada langkah yang gagal di tengah.

## Memasang pertama kali dari paket rilis

Bila server tidak punya Git atau Node.js, bangun paketnya di laptop.
**Pakai `build-release.sh`, jangan `tar` biasa atas folder proyek.** Skripnya
membuang yang tidak boleh ikut: `public/anotasi/.git` (klon repositori
tersendiri di dalam folder yang dilayani web), `.env`, `public/hot`,
`node_modules/`, dan isi `storage/`. `tar` biasa membawa semuanya, dan
`public/anotasi/.git` lalu bisa diunduh siapa pun kecuali nginx memblokir
dotfile. Pemasangan pertama di Rumahweb terkena persis ini; `deploy:check
--produksi` menangkapnya.

```bash
./build-release.sh
```

Hasilnya satu berkas `../analisisteks-<stempel>.tar.gz` (sekitar 600 KB) berisi
kode beserta aset yang sudah dibangun, **tanpa** `vendor/`, `node_modules/`,
`.env`, `.git`, dan isi `storage/`. Lalu di server:

```bash
scp analisisteks-<stempel>.tar.gz user@server:/var/www/
ssh user@server
mkdir -p /var/www/analisisteks && tar -xzf /var/www/analisisteks-<stempel>.tar.gz -C /var/www/analisisteks
cd /var/www/analisisteks
chmod +x deploy.sh backup-db.sh build-release.sh
cp .env.production.example .env && nano .env
php artisan key:generate
./deploy.sh --tanpa-git
```

---

## Daftar periksa sebelum dinyatakan siap

- [ ] `apt-cache policy openssh-server` menunjukkan versi `ubuntu13.x` terpasang
      (bukan rilis April 2024), dan sumber apt memuat `-updates`/`-security`
- [ ] Swap sekitar 4 GB (`swapon --show`), bukan 255 MB bawaan
- [ ] Login root ditolak: `ssh root@IP` gagal
- [ ] Tidak ada sudo tanpa kata sandi: `sudo ls /etc/sudoers.d/` tidak lagi memuat
      berkas `NOPASSWD` sementara (mis. `90-deploy-sementara`), dan `sudo -k; sudo -v`
      meminta kata sandi
- [ ] Login dengan kata sandi ditolak: `ssh -o PubkeyAuthentication=no deploy@IP`
      gagal dengan `Permission denied (publickey)`
- [ ] `sudo sshd -T` menunjukkan `passwordauthentication no` dan `permitrootlogin no`
- [ ] `sudo ufw status` hanya membuka OpenSSH, 80, 443
- [ ] `sudo fail2ban-client status sshd` berjalan
- [ ] MySQL dan layanan NLP hanya mendengar di 127.0.0.1 (`sudo ss -tlnp`)

- [ ] `APP_DEBUG=false` dan `APP_ENV=production`
- [ ] `APP_KEY` terisi
- [ ] `APP_URL=https://text-analyze.ing.biz.id`
- [ ] Document root menunjuk `public/`, bukan akar proyek
- [ ] `.env` tidak bisa diakses lewat peramban (coba `https://domain/.env`)
- [ ] `public/hot` tidak ada
- [ ] `public/build/` ada dan berisi manifest
- [ ] **Dua** worker antrean berjalan (`sudo supervisorctl status`) dan otomatis
      hidup lagi setelah server restart
- [ ] Layanan NLP berjalan di 127.0.0.1:8001 dan port itu tidak dibuka di ufw
- [ ] `models/` berisi `aspect_baseline.pt`, dan model dasar HF sudah diunduh
- [ ] Setelah pelatihan aspek pertama: `journalctl -u nlp-api` memuat
      "Protokol kolam: melatih dari bobot dasar", dan
      `models/aspect_retrained.pt.provenance.json` berisi
      `"trained_from_baseline": true`. Bila tidak, putaran itu melanjutkan
      checkpoint dan hasilnya tidak sebanding dengan iterasi sebelumnya.
- [ ] Swap 4 GB aktif (`free -h`)
- [ ] `RETRAIN_REJECT_INFERENCE=true` di layanan, **setelah** web versi terbaru
      terpasang
- [ ] `NLP_API_KEY` sama persis dengan `API_KEY` di layanan NLP, dan
      `deploy:check` menampilkan "Kunci API diterima layanan (HTTP 200)"
- [ ] HTTPS aktif, `SESSION_SECURE_COOKIE=true`
- [ ] Satu analisis percobaan berhasil dari awal sampai selesai
- [ ] `backup-db.sh` terjadwal di cron dan `RCLONE_REMOTE` menyalin ke luar server
- [ ] `php artisan deploy:check --produksi` lulus tanpa kegagalan
