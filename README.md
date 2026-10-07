# SIBAR (Sistem Informasi Iuran Warga)

Aplikasi web pengelolaan iuran warga mandiri berbasis per unit rumah / Kepala Keluarga (KK). Dirancang khusus untuk pencatatan dan pembayaran iuran **Jaga Malam**, **Jaga Siang**, dan **Sampah** dengan antarmuka minimalis elegan bertema **Apple HIG / macOS Design**.

---

## Fitur Utama

1. **Autentikasi Multi-Role**
   - **Warga (Kepala Keluarga)**: akun per nomor rumah (misal `b3-12`) untuk melihat tagihan & membayar iuran.
   - **Satpam Jaga Siang** (`satpam_siang`): memantau status iuran jaga siang seluruh rumah.
   - **Satpam Jaga Malam** (`satpam_malam`): memantau status iuran jaga malam seluruh rumah.
   - **Petugas Sampah** (`sampah`): memantau status iuran sampah seluruh rumah.
   - **Super Admin** (`superadmin`): akses seluruh konten — semua pos iuran, semua rumah, semua transaksi.
2. **Dashboard Warga (Minimalis Apple)**
   - **Bagian Tengah (Fokus Utama)**: tagihan **belum dibayar di bulan berjalan** (Jaga Malam, Jaga Siang, Sampah) + tombol bayar per pos / pelunasan sekaligus.
   - **Bagian Bawah**: **history pembayaran lunas** lengkap dengan kwitansi digital.
3. **Peta Monitoring per Blok & Jalur (Petugas & Admin)**
   - Tampilan rumah dikelompokkan **per blok dan jalur** (A1/B3/C2, Jalur Utama Timur/Tengah/Selatan).
   - Setiap nomor rumah tampil sebagai chip berwarna: hijau (lunas), oranye (ada yang belum), abu (belum ada KK).
   - **Klik nomor rumah** → panel detail berisi identitas Kepala Keluarga (nama, HP, akun, status huni) + ringkasan status pembayaran sesuai lingkup role.
   - Progress bar lunas per blok sebagai indikator visual cepat.
4. **Simulasi Pembayaran & Kwitansi Digital**
   - Metode bayar: QRIS Instant, BCA Virtual Account, Mandiri Virtual Account.
   - Kwitansi resmi otomatis, dapat dicetak / disimpan PDF.
5. **Keamanan Bawaan**
   - Prepared statement PDO (kebal SQL Injection).
   - Proteksi sesi & token CSRF pada transaksi POST.
   - Role guard: petugas tidak bisa membuka dashboard warga; warga tidak bisa membuka peta monitoring; kwitansi hanya bisa diakses pemilik rumahnya.
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

Password untuk **semua akun**: `password123`

| Username | Role | Keterangan |
| :--- | :--- | :--- |
| `a1-05` | Warga (KK) | Blok A1 No. 05 — Bpk. Budi Santoso |
| `a1-06` | Warga (KK) | Blok A1 No. 06 — Ibu Dewi Lestari |
| `b3-11` | Warga (KK) | Blok B3 No. 11 — Bpk. Agus Wijaya |
| `b3-12` | Warga (KK) | Blok B3 No. 12 — Bpk. Hendra Pratama (ada riwayat Juli–Okt) |
| `b3-13` | Warga (KK) | Blok B3 No. 13 — Bpk. Rudi Hartono |
| `c2-08` | Warga (KK) | Blok C2 No. 08 — Ibu Siti Rahma |
| `c2-09` | Warga (KK) | Blok C2 No. 09 — Bpk. Joko Susilo |
| `satpam_siang` | Satpam Jaga Siang | Bpk. Tono Wibowo — pantau iuran jaga siang |
| `satpam_malam` | Satpam Jaga Malam | Bpk. Slamet Riyadi — pantau iuran jaga malam |
| `sampah` | Petugas Sampah | Bpk. Darma Putra — pantau iuran sampah |
| `superadmin` | Super Admin | Admin RT 04 — akses semua konten & semua pos iuran |

---

## Struktur Direktori

```
sibar/
├── assets/
│   └── css/
│       ├── style.css           # Desain Apple Minimalist & layout responsif
│       └── monitoring.css      # Peta rumah per blok & jalur + drawer detail
├── config/
│   ├── database.php            # Koneksi PDO (MySQL XAMPP + Fallback SQLite) + seed
│   └── auth.php                # Autentikasi sesi, role guard & helper lingkup role
├── database.sql                # DDL & Seed Data awal untuk MySQL / XAMPP
├── index.php                   # Entry router
├── login.php                   # Halaman masuk (semua role)
├── logout.php                  # Halaman keluar sesi
├── dashboard.php               # Dashboard warga KK (tengah: tagihan, bawah: history)
├── monitoring.php              # Peta rumah per blok & jalur (petugas + super admin)
├── process_payment.php         # Handler transaksi pelunasan & pencatatan kwitansi
├── receipt.php                 # Kwitansi digital siap cetak / PDF
└── README.md                   # Petunjuk instalasi dan dokumentasi
```

---

## Lisensi
MIT License. Dikembangkan untuk pengelolaan kas warga yang transparan dan tertib.
