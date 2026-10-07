# SIBAR (Sistem Informasi Iuran Warga)

Aplikasi web pengelolaan iuran warga mandiri berbasis per unit rumah / Kepala Keluarga (KK). Dirancang khusus untuk pencatatan dan pembayaran iuran **Jaga Malam**, **Jaga Siang**, dan **Sampah** dengan antarmuka minimalis elegan bertema **Apple HIG / macOS Design**.

---

## Fitur Utama

1. **Autentikasi Akun Per Rumah / KK**
   - Tiap kepala keluarga memiliki akun login yang mewakili nomor unit rumahnya (misal: `b3-12`, `a1-05`, `c2-08`).
2. **Dashboard Sesuai Tata Letak Acuan (Minimalis Apple)**
   - **Header & Ringkasan KPI Atas**: Status kepatuhan iuran, indikator layanan keamanan & kebersihan berjalan.
   - **Bagian Tengah (Fokus Utama)**: Menampilkan tagihan yang **belum dibayar di bulan berjalan** (Jaga Malam, Jaga Siang, dan Sampah) dilengkapi tombol bayar per pos dan fitur pelunasan sekaligus.
   - **Bagian Bawah**: Menampilkan **history pembayaran yang sudah lunas** lengkap dengan rincian pos, metode bayar, dan kwitansi digital.
3. **Simulasi Pembayaran & Kwitansi Digital**
   - Pilihan metode bayar: QRIS Instant, BCA Virtual Account, Mandiri Virtual Account.
   - Kwitansi resmi otomatis dapat dicetak atau disimpan sebagai PDF.
4. **Keamanan Bawaan**
   - Prepared statement PDO (kebal SQL Injection).
   - Proteksi sesi & validasi token CSRF pada transaksi POST.
   - Sanitasi XSS (`htmlspecialchars`) pada seluruh output.

---

## Panduan Menjalankan di XAMPP (Localhost)

### 1. Salin Berkas ke Direktori XAMPP
Salin atau clone repositori ini ke dalam folder `htdocs`:
* **Windows**: `C:\xampp\htdocs\sibar`
* **macOS**: `/Applications/XAMPP/xamppfiles/htdocs/sibar`
* **Linux**: `/opt/lampp/htdocs/sibar`

```bash
cd C:\xampp\htdocs
git clone https://github.com/Shuya-sal/sibar.git
```

### 2. Nyalakan Service Apache & MySQL
Buka **XAMPP Control Panel** lalu klik tombol **Start** pada:
- **Apache**
- **MySQL**

### 3. Impor Database (phpMyAdmin)
1. Buka browser dan akses: `http://localhost/phpmyadmin`
2. Klik tab **Import** (atau buat database baru bernama `sibar_db`).
3. Pilih berkas `database.sql` yang ada di dalam repositori ini.
4. Klik tombol **Go / Kirim** untuk mengeksekusi skema dan seed data.

> *Catatan: Jika MySQL belum dikonfigurasi, sistem secara otomatis memiliki mode fallback SQLite di folder `data/sibar.db` sehingga tetap bisa diuji tanpa konfigurasi database yang rumit.*

### 4. Akses Aplikasi
Buka browser dan buka alamat:
```
http://localhost/sibar
```

---

## Akun Uji Coba Default

| Username / Akun | Kata Sandi | Unit Rumah | Nama Kepala Keluarga |
| :--- | :--- | :--- | :--- |
| `b3-12` | `password123` | Blok B3 No. 12 | Bpk. Hendra Pratama |
| `a1-05` | `password123` | Blok A1 No. 05 | Bpk. Budi Santoso |
| `c2-08` | `password123` | Blok C2 No. 08 | Ibu Siti Rahma |

---

## Struktur Direktori

```
sibar/
├── assets/
│   └── css/
│       └── style.css            # Desain Apple Minimalist & layout responsif
├── config/
│   ├── database.php            # Koneksi PDO (MySQL XAMPP + Fallback SQLite)
│   └── auth.php                # Middleware autentikasi sesi warga
├── database.sql                # DDL & Seed Data awal untuk MySQL / XAMPP
├── index.php                   # Entry router
├── login.php                   # Halaman masuk Kepala Keluarga
├── logout.php                  # Halaman keluar sesi
├── dashboard.php               # Dashboard utama (tengah: tagihan, bawah: history)
├── process_payment.php         # Handler transaksi pelunasan & pencatatan kwitansi
├── receipt.php                 # Kwitansi digital siap cetak / PDF
└── README.md                   # Petunjuk instalasi dan dokumentasi
```

---

## Lisensi
MIT License. Dikembangkan untuk pengelolaan kas warga yang transparan dan tertib.
