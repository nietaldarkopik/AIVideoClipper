# Migrasi ke Cloudflare Tunnel (menghapus ketergantungan pada server VPS)

Tujuan: `existcode.excoder.site` (dan subdomain lain yang sekarang lewat VPS) langsung sampai ke komputer
ini lewat **Cloudflare Tunnel**, tanpa server perantara `149.129.234.76` dan tanpa terowongan WireGuard.

Kondisi sekarang (hasil pengecekan DNS & komputer):

```
DNS (nameserver dns-parking.com, kemungkinan Hostinger)
  existcode / clipper.app / app / www.app / naqdi .excoder.site  ──A──►  149.129.234.76  (VPS Alibaba, Jakarta)
                                                                          └─ WireGuard ─► PC ini (10.1.1.6) ─► Apache :80 ─► API :8000 / Next :3001
```
TLS saat ini diputus di VPS; Apache di komputer ini hanya melayani HTTP :80 (tidak ada redirect HTTPS).

Setelah migrasi:

```
Cloudflare (DNS + TLS)  ──►  cloudflared (layanan Windows di PC ini, koneksi KELUAR)  ──►  Apache :80  ──► sama seperti sekarang
```

## 0. Baca ini dulu: dua hal yang menentukan apakah opsi ini cocok

### 0.1 Zona `excoder.site` bukan hanya ClipWize
Agar Cloudflare Tunnel bisa dipakai, DNS zona domain itu harus **seluruhnya** berpindah ke Cloudflare (ganti
nameserver). Zona Anda memuat website, email Hostinger, dan subdomain lain (`api`, `admin`, `ftp`). Semua harus
disalin persis; salah salin berarti email atau website mati. Daftarnya ada di bagian 2.

Alternatif tanpa risiko itu: **daftarkan domain terpisah** di Cloudflare khusus untuk aplikasi ini
(mis. `clipwize.xyz`). Konsekuensinya semua yang terikat ke nama lama harus diganti: `APP_URL`, `FRONTEND_URL`,
`NEXT_PUBLIC_API_URL`, URI redirect OAuth Google/Facebook/TikTok, verifikasi domain TikTok, dan base URL di skill
Claude Desktop. Dengan memindahkan zona yang sama, **nama host tidak berubah, jadi tidak ada yang perlu diganti.**

### 0.2 Batas Cloudflare: unggahan besar akan gagal
Lalu lintas lewat Cloudflare (proxy, wajib untuk tunnel) dibatasi **100 MB per request** pada paket Free/Pro.
Endpoint ini mendukung file besar dan akan menjawab `413` bila lewat Cloudflare:

| Endpoint | Batas aplikasi | Lewat Cloudflare Free |
|---|---|---|
| `POST /api/projects/{id}/videos` (upload file) | ±5 GB | **100 MB** |
| `POST /api/clips/import` | 2 GB | **100 MB** |
| `POST /api/assistant/attachments` | 20 MB/file | aman |
| Impor video lewat **URL** (`url=`) | server yang mengunduh | **aman** (tidak lewat Cloudflare) |

Solusi: untuk video besar pakai impor lewat URL, atau sediakan hostname unggah terpisah yang tidak lewat tunnel
(mis. `upload.excoder.site` tetap ke VPS — artinya VPS belum bisa dimatikan sepenuhnya). Selain itu, Cloudflare
membatasi penyajian video/berkas besar lewat paket gratis dalam ketentuan layanannya; periksa ketentuan terbaru
sebelum mengalirkan banyak video (hasil render disajikan lewat `/api/media/...`).

## 1. Subdomain yang melewati VPS dan akan pindah ke Tunnel

Dari konfigurasi Apache (`httpd-vhosts.conf`) dan DNS, kelima nama ini sekarang menuju VPS:

| Hostname | Dilayani Apache lokal ke |
|---|---|
| `existcode.excoder.site` | `/api` → :8000, sisanya → :3001 (ClipWize) |
| `clipper.app.excoder.site` | sama (ClipWize) |
| `www.app.excoder.site` | sama (alias ClipWize) |
| `app.excoder.site` | situs statis/PHP `www/app.excoder.site` |
| `naqdi.excoder.site` | aplikasi lain di port 3100 |

Di Cloudflare, kelimanya **bukan lagi record A ke `149.129.234.76`**, melainkan CNAME ke
`<ID-TUNNEL>.cfargotunnel.com` dengan **proxy menyala (awan oranye)**. Cloudflare membuatnya otomatis saat Anda
menambah *Public Hostname* di dashboard — syaratnya record A lama dengan nama yang sama sudah dihapus lebih dulu.

## 2. Record DNS yang harus ada di Cloudflare (hasil pembacaan dari nameserver saat ini)

Semua record di bawah ini disalin dengan **status "DNS only" (awan abu-abu)**, kecuali bagian 1 yang menjadi tunnel.
Jangan menyalakan proxy pada record email atau pada `www` (CDN Hostinger). Ini hasil pembacaan; **sumber yang paling
benar adalah editor zona DNS di Hostinger** — cocokkan dengannya karena saya hanya bisa membaca nama yang saya
tebak (zona tidak bisa diunduh utuh dari luar).

| Nama | Tipe | Nilai | Catatan |
|---|---|---|---|
| `@` (excoder.site) | A | `153.92.12.225` dan `185.124.137.5` | TTL 60: dikelola otomatis Hostinger, **angka bisa berubah**; cek ulang saat migrasi |
| `@` | AAAA | `2a02:4780:3b:4335:6601:249:324a:59e7`, `2a02:4780:1b:4fe0:4dd6:40a1:6dab:236f` | idem |
| `@` | MX | `5 mx1.hostinger.com`, `10 mx2.hostinger.com` | email |
| `@` | TXT | `v=spf1 include:_spf.mail.hostinger.com ~all` | SPF |
| `www` | CNAME | `www.excoder.site.cdn.hstgr.net` | **DNS only** |
| `ftp` | A | `153.92.11.121` | |
| `autodiscover` | CNAME | `autodiscover.mail.hostinger.com` | email |
| `autoconfig` | CNAME | `autoconfig.mail.hostinger.com` | email |
| `hostingermail-a._domainkey` | CNAME | `hostingermail-a.dkim.mail.hostinger.com` | DKIM |
| `hostingermail-b._domainkey` | CNAME | `hostingermail-b.dkim.mail.hostinger.com` | DKIM |
| `hostingermail-c._domainkey` | CNAME | `hostingermail-c.dkim.mail.hostinger.com` | DKIM |
| `_dmarc` | TXT | `v=DMARC1; p=none` | |
| `api` | A | `91.108.119.182`, `153.92.12.218` | |
| `api` | AAAA | `2a02:4780:3a:f6b8:6164:9c63:e9e2:5d82`, `2a02:4780:1c:5ac9:d175:19c0:ca0b:978` | |
| `api` | MX | `5 mx1.hostinger.co.id`, `10 mx2.hostinger.co.id` | |
| `api` | TXT | `v=spf1 include:_spf.mail.hostinger.com ~all` | |
| `admin` | A | `88.223.91.133`, `153.92.12.94` | |
| `admin` | AAAA | `2a02:4780:1b:5c0e:ed9d:4131:dae:ec08`, `2a02:4780:1c:5344:c63b:357e:d231:6bfb` | |
| `admin` | MX | `5 mx1.hostinger.co.id`, `10 mx2.hostinger.co.id` | |
| `admin` | TXT | `v=spf1 include:_spf.mail.hostinger.com ~all` | |
| `app` | TXT | `tiktok-developers-site-verification=4mr2I9xDrP4Z9owDaNF7yhbWclzmebNN` | **wajib dipertahankan** (verifikasi TikTok) |
| `clipper.app` | TXT | `tiktok-developers-site-verification=3EnMk1NYuCbhnHAsSlqfqbYZqpeyoeMe` | **wajib dipertahankan** |

Subdomain lain yang mungkin ada (Hostinger, panel, dll.) tidak terlihat dari luar. Gunakan **impor otomatis**
Cloudflare saat menambah situs, lalu bandingkan baris demi baris dengan editor zona Hostinger.

## 3. Urutan pengerjaan (dirancang agar tidak ada downtime sampai langkah 6)

1. **Cloudflare → Add a site → `excoder.site` → paket Free.** Biarkan hasil pindai otomatis, lalu cocokkan dengan
   tabel bagian 2. Set semuanya **DNS only**. Untuk lima host ClipWize (bagian 1) **biarkan dulu A ke
   `149.129.234.76`** (DNS only): perilaku sama dengan sekarang, VPS masih dipakai.
2. **Ganti nameserver** di registrar (Hostinger → Domain → Nameservers) ke dua nameserver yang diberikan Cloudflare.
   Tunggu status situs di Cloudflare menjadi **Active** (bisa beberapa jam sampai 24+ jam).
3. **Cek semuanya masih hidup:** website `excoder.site`, `www`, email (kirim/terima), `api`/`admin`, dan
   `existcode.excoder.site/login`. Jika ada yang mati, kembalikan nameserver ke `apollo/athena.dns-parking.com`.
4. **Buat tunnel:** Cloudflare Zero Trust → *Networks → Tunnels → Create a tunnel → Cloudflared*, beri nama
   `clipwize`. Pilih Windows; Cloudflare menampilkan perintah pemasangan **dengan token**. Jalankan di
   PowerShell **Administrator** komputer ini. Ini memasang `cloudflared` sebagai **layanan Windows** (jalan otomatis
   saat boot). Token = rahasia; jangan dibagikan.
5. **Public Hostname** (di tunnel yang sama): untuk masing-masing hostname bagian 1, `Service` = `HTTP` →
   `localhost:80`. Apache memilih aplikasi berdasarkan Host, dan Cloudflare meneruskan Host aslinya. Sebelum menambah
   hostname, **hapus dulu record A lama** nama itu (kalau tidak, Cloudflare menolak: *record already exists*).
   Mulai dari **`existcode.excoder.site`** saja dan uji.
6. **Uji:** `https://existcode.excoder.site/login` memuat; login; `/api/auth/me` tanpa token → `401`; buka video hasil
   render. Jika gagal, hapus Public Hostname itu dan buat kembali record A ke `149.129.234.76` (rollback cepat).
7. Pindahkan hostname lain (`clipper.app`, `www.app`, `app`, `naqdi`). Biarkan VPS menyala **± 1 minggu** sebagai
   cadangan, baru dimatikan.

## 4. Yang TIDAK perlu diubah di aplikasi (karena nama host tetap)

- `APP_URL`, `FRONTEND_URL`, `NEXT_PUBLIC_API_URL`, URI redirect OAuth, base URL skill Claude Desktop.
- Aturan Apache (`ProxyPreserveHost On` sudah benar). Laravel tidak memakai `trustProxies` dan memang tidak
  membutuhkannya: URL media dibuat dari `APP_URL`, bukan dari skema permintaan.
- `MEDIA_TRUSTED_HOST_SUFFIXES` (untuk terowongan cepat) tidak dipakai di jalur ini.

## 5. Setelah migrasi: yang berubah untuk Claude Desktop

Domain tetap `existcode.excoder.site`, tetapi alurnya tidak lagi bergantung pada VPS/WireGuard, hanya pada
Cloudflare dan layanan `cloudflared` di komputer ini. Pastikan layanan itu ikut hidup tiap boot
(`Get-Service cloudflared`). Jika komputer mati/tidur, domain tetap tidak bisa diakses — itu tidak berubah.
Izinkan `existcode.excoder.site` di pengaturan jaringan Claude Desktop (sudah, bila skill `clipwize` jalan sekarang).

## 6. Ceklist cepat sebelum mulai

- [ ] Ekspor/foto layar semua record di editor zona DNS Hostinger (sumber kebenaran).
- [ ] Putuskan: pindahkan zona `excoder.site` **atau** domain terpisah (bagian 0.1).
- [ ] Terima batas unggah 100 MB (bagian 0.2) atau siapkan hostname unggah terpisah.
- [ ] Siapkan jendela waktu saat email tidak kritis (propagasi NS tidak instan).
- [ ] Akun Cloudflare gratis, 2FA aktif.
