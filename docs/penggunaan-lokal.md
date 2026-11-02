# Menggunakan ClipWize secara lokal (saat domain / DNS / proxy mati)

Dokumen ini untuk situasi: `https://existcode.excoder.site` tidak bisa dibuka (DNS bermasalah,
tunnel/reverse proxy mati, internet putus), tetapi komputer yang menjalankan aplikasi masih menyala.
Semua yang ada di domain itu sebenarnya berjalan di komputer ini, jadi aplikasi tetap bisa dipakai
lewat alamat lokal.

> Cara menyalakan semua layanan secara umum ada di `README.md` (bagian *Running it*). Dokumen ini
> fokus pada: **apa yang rusak, alamat lokalnya, cara memakai, dan apa yang tidak bisa tanpa domain.**

## 1. Cara kerja jalur publik vs lokal

```
JALUR PUBLIK (butuh DNS + tunnel/proxy hidup)
  browser/aplikasi lain ──► https://existcode.excoder.site ──► Apache (WAMP, port 80)
                                                                 ├─ /api/*  ──► Laravel API   127.0.0.1:8000
                                                                 └─ lainnya ──► Next.js (prod) 127.0.0.1:3001

JALUR LOKAL (tidak butuh domain sama sekali)
  browser ──► http://localhost:3000  (Next.js dev)   ┐
  browser ──► http://localhost:3001  (Next.js prod)  ├──► API langsung ke http://localhost:8000/api
  aplikasi lain di komputer ini ──► http://localhost:8000/api
  perangkat lain di jaringan LAN ──► http://<IP-LAN-komputer-ini>:8000/api
```

Database, file media, queue, dan akun pengguna **sama persis** di kedua jalur — hanya pintu masuknya
yang beda. Data yang dibuat lewat jalur lokal langsung terlihat di domain begitu domain hidup lagi.

## 2. Cek dulu: yang mati domainnya atau aplikasinya?

Jalankan dari komputer yang menjalankan aplikasi (PowerShell atau Git Bash):

```bash
curl -s -o /dev/null -w "API lokal      : %{http_code}\n" http://localhost:8000/api/auth/me      # 401 = API hidup (belum login)
curl -s -o /dev/null -w "Frontend prod  : %{http_code}\n" http://localhost:3001/login            # 200 = hidup
curl -s -o /dev/null -w "Frontend dev   : %{http_code}\n" http://localhost:3000/login            # 200 = hidup (jika dinyalakan)
curl -s -m 15 -o /dev/null -w "Lewat domain   : %{http_code}\n" https://existcode.excoder.site/login
```

| Hasil | Artinya | Tindakan |
|---|---|---|
| API lokal `401`, domain gagal/timeout | Aplikasi sehat, hanya DNS/tunnel/proxy yang mati | Pakai jalur lokal (bagian 4–6) |
| API lokal tidak merespons | Laravel mati | Jalankan `start-all` (bagian 3) |
| API lokal `500` | Ada error aplikasi/DB | Lihat `backend/storage/logs/laravel.log`; pastikan PostgreSQL hidup |
| Domain `200` tetapi halaman putih / `500` di `/_next/static/...` | Build frontend tidak sinkron | Lihat bagian 8 |

## 3. Menyalakan semua layanan

Dari folder proyek:

```powershell
.\start-all.ps1        # atau:  start-all.bat
.\stop-all.ps1         # menghentikan semuanya (jika ada proses sisa / port bentrok)
```

Skrip membuka satu jendela per proses:

| Layanan | Port | Wajib? | Catatan |
|---|---|---|---|
| Laravel API (`php artisan serve`) | **8000** | ya | Mendengar di `0.0.0.0`, jadi bisa diakses dari LAN |
| Queue worker `default` | – | ya | Tanpa ini render, analisis, riset, chat asisten **tidak diproses** |
| Queue `batch-downloads`, `publish` | – | ya | Unduh batch & posting ke sosmed |
| Scheduler (`schedule:work`) | – | ya | Penjadwalan posting, pembersihan job macet |
| Redis | 6379 | ya | Antrean & cache. Kalau mati, queue berhenti |
| Next.js dev | **3000** | – | Selalu memakai kode terbaru |
| Next.js prod (`next start`) | **3001** | – | Yang dilayani domain publik. Perlu `npm run build` setelah ubah kode |
| PostgreSQL | 5432 | ya | Layanan Windows (sudah otomatis jalan) |
| 9Router | 20128 | untuk fitur AI | **Tidak** dinyalakan `start-all`; jalankan terpisah |
| whisper-engine / face-tracker | 8100 / 8200 | opsional | Sesuai pengaturan di `backend/.env` |

Checklist cepat setelah menyalakan:

```bash
curl -s -o /dev/null -w "API:        %{http_code}\n" http://localhost:8000/api/auth/me     # 401 = ok
tools/redis/redis-cli.exe ping 2>/dev/null || echo "(redis-cli tidak ada; pastikan jendela 'clipper: redis' terbuka)"
curl -s -o /dev/null -w "9Router:    %{http_code}\n" http://localhost:20128/api/health      # 200 = ok
netstat -ano | findstr "LISTENING" | findstr ":8000 :3000 :3001 :6379"
```

## 4. Memakai aplikasi lewat browser (tanpa domain)

1. Buka **http://localhost:3000** (dev) atau **http://localhost:3001** (prod).
2. **Login ulang.** Sesi login disimpan per alamat; login di domain tidak terbawa ke `localhost`.
3. Semua halaman, termasuk **Asisten AI** (`/assistant`) dan **Video Kartun** (`/cartoon-videos`), bekerja seperti biasa.

Bagaimana frontend memilih alamat API: frontend dibangun dengan alamat API publik
(`NEXT_PUBLIC_API_URL` di `frontend/.env.local`). Sejak perubahan terbaru, jika aplikasi dibuka lewat
`localhost`, `127.x`, atau IP jaringan lokal (`10.x`, `192.168.x`, `172.16–31.x`), frontend otomatis
memanggil API di **host yang sama pada port 8000**. Dibuka lewat domain, perilakunya tidak berubah.
Port API lain? Set `NEXT_PUBLIC_LOCAL_API_PORT` (misalnya `8100`) sebelum menjalankan/membangun frontend.

> Penting: perubahan ini baru aktif di **dev (3000)** begitu dinyalakan, tetapi untuk **prod (3001)**
> harus `npm run build` lalu restart `npm run start:prod` (bagian 8).

URL gambar/video (thumbnail, hasil render) juga mengikuti alamat yang dipakai mengakses API:
lewat `http://localhost:8000` hasilnya `http://localhost:8000/api/media/...`, lewat domain tetap
domain. Tidak perlu mengubah `APP_URL`. Hanya alamat lokal/LAN yang diikuti; Host lain diabaikan.

## 5. Memakai API dari aplikasi lain

Autentikasi sama seperti di domain (bearer token — lihat `docs/api-integration-guide.md`), hanya base
URL-nya yang diganti:

| Dari mana | Base URL |
|---|---|
| Komputer yang sama | `http://localhost:8000/api` |
| Perangkat lain di jaringan yang sama | `http://<IP-LAN>:8000/api` |
| Domain publik (saat hidup) | `https://existcode.excoder.site/api` |

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"EMAIL","password":"PASSWORD"}'
```

- **Token yang sama berlaku di semua alamat** karena database-nya sama. Aplikasi lain cukup mengganti
  base URL (idealnya lewat variabel konfigurasi), tanpa login ulang.
- **IP LAN**: jalankan `ipconfig`, ambil *IPv4 Address* adaptor yang dipakai (mis. `192.168.1.20`).
  Perangkat lain harus satu jaringan. Jika tidak tembus, buka port di firewall (PowerShell **Administrator**):

  ```powershell
  netsh advfirewall firewall add rule name="ClipWize API 8000" dir=in action=allow protocol=TCP localport=8000 profile=private
  ```

  Hapus kembali bila tidak diperlukan: `netsh advfirewall firewall delete rule name="ClipWize API 8000"`.
- CORS mengizinkan semua origin untuk `/api/*`; panggilan dari browser aplikasi lain tidak perlu pengaturan tambahan.
- Mengekspos port 8000 ke LAN berarti siapa pun di jaringan itu bisa mencoba login. Gunakan hanya di
  jaringan tepercaya, dan jangan pernah port-forward 8000 ke internet.

## 6. Yang TIDAK bisa (atau terbatas) tanpa domain

| Fitur | Status saat domain mati | Alasan |
|---|---|---|
| Menyambungkan akun sosmed **baru** (YouTube, Facebook, TikTok) | ❌ Tidak bisa | URI redirect OAuth (`GOOGLE_REDIRECT_URI`, `FACEBOOK_REDIRECT_URI`, `TIKTOK_REDIRECT_URI`) terdaftar ke domain di konsol masing-masing platform |
| Login dengan TikTok | ❌ Tidak bisa | Alasan sama; pakai email + password |
| Posting/penjadwalan lewat akun yang **sudah tersambung** | ✅ Bisa | Token tersimpan di database; hanya butuh internet ke platform, bukan domain |
| Fitur AI (chat, naskah, gambar, TTS, video kartun) | ✅ Bisa | Lewat 9Router lokal (`localhost:20128`) ke penyedia AI — butuh internet & 9Router hidup |
| Unduh video dari URL (yt-dlp) | ✅ Bisa bila internet ada | |
| Riset konten (Google Trends, HN, dll.) | ✅ Bisa bila internet ada | |
| Tanpa internet sama sekali | ⚠️ Sebagian | Pengolahan lokal (FFmpeg, clip, editor) jalan; semua yang memanggil layanan luar gagal |

## 7. Menjalankan perintah manual (tanpa `start-all`)

```bash
tools/redis/redis-server.exe                                   # Redis
cd backend && php artisan serve --host=0.0.0.0 --port=8000     # API (0.0.0.0 agar bisa dari LAN)
cd backend && php artisan queue:work redis --queue=default
cd backend && php artisan queue:work redis --queue=batch-downloads
cd backend && php artisan queue:work redis --queue=publish,default
cd backend && php artisan schedule:work
cd frontend && npm run dev                                     # http://localhost:3000
```

Setelah menarik kode baru: `cd backend && php artisan migrate` (tabel baru fitur Video Kartun & Asisten AI),
lalu restart queue worker (`php artisan queue:restart`).

## 8. Masalah umum

| Gejala | Penyebab & solusi |
|---|---|
| `/_next/static/chunks/….js` → **500**, halaman putih | Server prod (`next start`) masih memuat build lama setelah `npm run build`. **Restart** `npm run start:prod`. Aturan: *setiap build = restart prod*. |
| Halaman baru (mis. `/assistant`) → "page not found" di port 3001 / domain | Build prod belum memuat kode baru: `cd frontend && npm run build`, lalu restart `npm run start:prod`. |
| Dev (3000) dan build (3001) saling merusak | Keduanya memakai folder `.next` yang sama. Jangan `npm run build` saat `npm run dev` aktif; matikan dev dulu. |
| Port 8000 / 3000 / 3001 sudah dipakai | Jalankan `.\stop-all.ps1`, lalu `start-all` lagi. |
| Job (render, riset, chat asisten) menggantung "memproses" selamanya | Redis atau queue worker mati. Nyalakan Redis lalu queue worker. |
| Narasi/TTS gagal `502` "Provider 'claude' does not support TTS" | `NINE_ROUTER_TTS_MODEL` di `backend/.env` diisi model chat (mis. `Kombo`). Isi dengan model TTS, mis. `gemini/gemini-2.5-flash-preview-tts,openai/tts-1`. |
| Gambar/video tidak tampil di jalur lokal | Pastikan API diakses lewat `localhost`/IP LAN (bukan domain). Setelah mengubah `.env`: `php artisan config:clear`. |
| Perubahan `.env` tidak terasa | `php artisan config:clear`, lalu restart queue worker. |
| `SQLSTATE … connection refused` | PostgreSQL (layanan Windows) mati; nyalakan lewat `services.msc`. |
| Aplikasi lain menolak sertifikat/HTTPS ke `localhost` | Jalur lokal memakai **HTTP**, bukan HTTPS. Pakai `http://`. |

## 9. Akses dari Claude Desktop (sandbox) saat domain mati

Kode yang dijalankan Claude Desktop (analisis, skrip, skill seperti `clipwize`) berjalan di **sandbox
terisolasi**, bukan di komputer ini. Konsekuensinya:

- `localhost`, `127.0.0.1`, `host.docker.internal`, atau IP LAN **tidak** menunjuk ke komputer Anda
  dari dalam sandbox. Semua lalu lintas keluar dipaksa lewat proxy yang hanya meneruskan domain yang
  diizinkan, dan menjawab **`403`** untuk sisanya.
- Karena itu `curl` ke alamat lokal dari sandbox menghasilkan `403` dari **proxy**, bukan dari API
  ClipWize (API sendiri menjawab `401` untuk `/auth/me` tanpa token — lihat bagian 2). Pesan 403 itu
  tidak berarti aplikasi rusak dan tidak bisa diperbaiki dengan mengganti nama host.
- Jadi untuk Claude Desktop **jalur lokal (bagian 4–6) tidak berlaku**. Satu-satunya jalan adalah
  alamat publik yang bisa dijangkau dari internet dan sudah diizinkan di pengaturan jaringan sandbox.

Jalur publik saat ini: `existcode.excoder.site` → IP publik `149.129.234.76` → terowongan (WireGuard /
Tailscale) → Apache di komputer ini → API :8000. Titik gagalnya: DNS, server perantara di IP itu,
terowongan, dan Apache lokal. Yang bisa dilakukan dari komputer ini hanya memastikan bagian terakhir
sehat (bagian 2); tiga lainnya ada di luar komputer.

### Jalur cadangan (disarankan disiapkan SEBELUM domain mati)

Buat nama publik kedua yang **tidak bergantung pada DNS dan server perantara `excoder.site`**, lalu:
(a) izinkan domainnya di pengaturan jaringan Claude Desktop (nama menu: *Network egress / allowed
domains*, bisa berbeda per versi), dan (b) jadikan base URL API di skill/skrip Anda mudah diganti.

| Opsi | Domain yang perlu diizinkan | Kelebihan | Kekurangan |
|---|---|---|---|
| Cloudflare Tunnel *quick* (`cloudflared tunnel --url http://localhost:8000`) | `*.trycloudflare.com` | Gratis, tanpa akun, jalan dalam hitungan menit | URL **berubah setiap dijalankan**; cocok darurat, bukan permanen |
| Cloudflare Tunnel *named* (akun Cloudflare + domain di Cloudflare) | hostname tetap Anda | URL stabil, TLS otomatis | Perlu akun dan domain di Cloudflare |
| Tailscale **Funnel** (`tailscale funnel 8000`) | `*.ts.net` | URL stabil `https://<mesin>.<tailnet>.ts.net`, tidak lewat `excoder.site` | Tailscale harus **aktif/login** (saat dicek, statusnya `NoState`) dan Funnel harus diaktifkan di admin Tailscale |

Catatan keamanan untuk semua opsi: ini membuat API terbuka ke internet. Gunakan **user khusus integrasi**
(bukan akun admin), simpan token hanya di Claude Desktop, cabut token (`POST /api/auth/logout`) bila
bocor, dan matikan terowongan saat tidak dibutuhkan. Login dibatasi 5 percobaan/menit per email+IP,
tetapi token yang sudah terbit **tidak kedaluwarsa**.

Ketika Claude Desktop memakai URL cadangan, bentuk API-nya identik: ganti hanya base URL
(`https://<host-cadangan>/api`) — token yang sama tetap berlaku karena backend dan database-nya sama.

### Menyalakan jalur cadangan (Cloudflare Tunnel cepat)

`cloudflared` sudah terpasang di komputer ini (via `winget`). Skrip siap pakai ada di `tools/tunnel/`.
Sengaja **tidak** dijalankan otomatis oleh `start-all`, karena ini membuka API ke internet.

```powershell
cd tools\tunnel
.\start-tunnel.ps1        # mencetak:  API base URL : https://kata-acak.trycloudflare.com/api
.\stop-tunnel.ps1         # menutup terowongan (hanya proses cloudflared yang dicatat skrip ini)
```

Langkah lengkap:

1. Pastikan API hidup (`start-all.ps1`; skrip akan menolak jika port 8000 tidak menjawab).
2. Jalankan `start-tunnel.ps1` dan salin **API base URL** yang dicetak. URL ini **berubah setiap kali
   dijalankan** dan hanya hidup selama proses berjalan. URL terakhir juga tersimpan di
   `tools/tunnel/current-url.txt`.
3. Di Claude Desktop, izinkan domain `*.trycloudflare.com` di pengaturan jaringan sandbox (sekali saja).
4. Berikan base URL itu ke skill/skrip yang memanggil API (token yang sama tetap berlaku).
5. Selesai dipakai: `stop-tunnel.ps1`.

Agar URL gambar/video di respons API ikut memakai host terowongan (bukan domain yang sedang mati),
`backend/.env` harus berisi `MEDIA_TRUSTED_HOST_SUFFIXES=.trycloudflare.com` (sudah diisi di komputer ini;
default kosong untuk instalasi lain). Jika diisi `.ts.net` juga, hal yang sama berlaku untuk Tailscale Funnel.
Lakukan `php artisan config:clear` setelah mengubahnya.

Menguji dari komputer ini setelah terowongan hidup (harus `401`, artinya API terjangkau dan menolak tanpa token):

```bash
curl -s -o /dev/null -w "%{http_code}\n" "$(cat tools/tunnel/current-url.txt)/api/auth/me"
```

Batasan: terowongan cepat tidak punya jaminan uptime dan tidak cocok sebagai jalur permanen; untuk URL tetap
pakai Cloudflare Tunnel bernama atau Tailscale Funnel (tabel di atas).
