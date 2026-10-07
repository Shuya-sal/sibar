-- SIBAR (Sistem Informasi Iuran Warga)
-- Database Schema for MySQL / MariaDB (XAMPP Compatible)
-- Roles: warga, satpam_siang, satpam_malam, sampah, super_admin

CREATE DATABASE IF NOT EXISTS `sibar_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sibar_db`;

-- 1. Tabel Rumah (Units per Blok & Jalur)
CREATE TABLE IF NOT EXISTS `houses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `block` VARCHAR(10) NOT NULL,
  `number` VARCHAR(10) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `lane` VARCHAR(100) DEFAULT 'Jalur Utama',
  `status_huni` ENUM('tetap', 'kontrak') DEFAULT 'tetap',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_house` (`block`, `number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabel Pengguna (Warga KK, Satpam, Petugas Sampah, Super Admin)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `house_id` INT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `role` ENUM('warga', 'satpam_siang', 'satpam_malam', 'sampah', 'super_admin') DEFAULT 'warga',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`house_id`) REFERENCES `houses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabel Master Pos Iuran (Fee Types)
CREATE TABLE IF NOT EXISTS `fee_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `icon` VARCHAR(10) DEFAULT '💰',
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabel Tagihan (Monthly Bills per House)
CREATE TABLE IF NOT EXISTS `bills` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `house_id` INT NOT NULL,
  `fee_type_id` INT NOT NULL,
  `period_year` INT NOT NULL,
  `period_month` INT NOT NULL,
  `amount` DECIMAL(12, 2) NOT NULL,
  `status` ENUM('unpaid', 'paid') DEFAULT 'unpaid',
  `due_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`house_id`) REFERENCES `houses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_house_fee_period` (`house_id`, `fee_type_id`, `period_year`, `period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Tabel Pembayaran & Kwitansi
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_id` INT NOT NULL,
  `receipt_no` VARCHAR(60) NOT NULL UNIQUE,
  `amount_paid` DECIMAL(12, 2) NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL,
  `paid_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`) REFERENCES `bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- SEED DATA AWAL (DEMO & TESTING)
-- Password semua akun: password123
-- ==========================================

-- Data Rumah (3 Blok / Jalur)
INSERT INTO `houses` (`id`, `block`, `number`, `address`, `lane`, `status_huni`) VALUES
(1, 'A1', '05', 'Jl. Cempaka Raya No. 05, RT 04 / RW 08', 'Jalur Utama Timur',  'tetap'),
(2, 'A1', '06', 'Jl. Cempaka Raya No. 06, RT 04 / RW 08', 'Jalur Utama Timur',  'kontrak'),
(3, 'B3', '11', 'Jl. Cempaka Raya No. 11, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap'),
(4, 'B3', '12', 'Jl. Cempaka Raya No. 12, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap'),
(5, 'B3', '13', 'Jl. Cempaka Raya No. 13, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap'),
(6, 'C2', '08', 'Jl. Cempaka Raya No. 08, RT 04 / RW 08', 'Jalur Selatan',      'kontrak'),
(7, 'C2', '09', 'Jl. Cempaka Raya No. 09, RT 04 / RW 08', 'Jalur Selatan',      'tetap')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Data Pengguna Warga (Kepala Keluarga)
INSERT INTO `users` (`id`, `house_id`, `username`, `password_hash`, `full_name`, `phone`, `role`) VALUES
(1, 1, 'a1-05', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Budi Santoso',   '081298765432', 'warga'),
(2, 2, 'a1-06', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Dewi Lestari',    '081311223344', 'warga'),
(3, 3, 'b3-11', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Agus Wijaya',    '081355667788', 'warga'),
(4, 4, 'b3-12', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Hendra Pratama', '081234567890', 'warga'),
(5, 5, 'b3-13', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Rudi Hartono',   '081399887766', 'warga'),
(6, 6, 'c2-08', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Siti Rahma',      '081345678901', 'warga'),
(7, 7, 'c2-09', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Joko Susilo',    '081377665544', 'warga'),
-- Akun Petugas & Super Admin (tanpa rumah)
(8, NULL, 'satpam_siang', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Tono Wibowo',   '081500011122', 'satpam_siang'),
(9, NULL, 'satpam_malam', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Slamet Riyadi', '081500033344', 'satpam_malam'),
(10, NULL, 'sampah',      '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Darma Putra',   '081500055566', 'sampah'),
(11, NULL, 'superadmin',  '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Admin RT 04',        '081500077788', 'super_admin')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Master Pos Iuran
INSERT INTO `fee_types` (`id`, `code`, `name`, `amount`, `icon`, `description`) VALUES
(1, 'jaga_malam', 'Iuran Jaga Malam', 50000.00, '🌙', 'Honor ronda malam (22:00 - 05:00), senter, dan pemeliharaan pos ronda kamling.'),
(2, 'jaga_siang', 'Iuran Jaga Siang', 40000.00, '☀️', 'Penjagaan gerbang utama (06:00 - 18:00), penerimaan kurir paket, dan patroli siang.'),
(3, 'sampah', 'Iuran Sampah & Kebersihan', 35000.00, '🗑️', 'Pengangkutan sampah rumah tangga 3x seminggu ke TPA dan pembersihan gorong-gorong.')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Riwayat Lunas (Juli - September 2026) untuk Rumah B3-12 (house_id = 4)
INSERT INTO `bills` (`id`, `house_id`, `fee_type_id`, `period_year`, `period_month`, `amount`, `status`, `due_date`) VALUES
(1, 4, 1, 2026, 7, 50000.00, 'paid', '2026-07-15'),
(2, 4, 2, 2026, 7, 40000.00, 'paid', '2026-07-15'),
(3, 4, 3, 2026, 7, 35000.00, 'paid', '2026-07-15'),
(4, 4, 1, 2026, 8, 50000.00, 'paid', '2026-08-15'),
(5, 4, 2, 2026, 8, 40000.00, 'paid', '2026-08-15'),
(6, 4, 3, 2026, 8, 35000.00, 'paid', '2026-08-15'),
(7, 4, 1, 2026, 9, 50000.00, 'paid', '2026-09-15'),
(8, 4, 2, 2026, 9, 40000.00, 'paid', '2026-09-15'),
(9, 4, 3, 2026, 9, 35000.00, 'paid', '2026-09-15'),

-- Tagihan Bulan Berjalan (Oktober 2026) — Seluruh Rumah
-- A1-05 : semua lunas
(10, 1, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
(11, 1, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
(12, 1, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
-- A1-06 : malam & sampah belum
(13, 2, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
(14, 2, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
(15, 2, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15'),
-- B3-11 : semua lunas
(16, 3, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
(17, 3, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
(18, 3, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
-- B3-12 : semua belum (demo dashboard warga)
(19, 4, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
(20, 4, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
(21, 4, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15'),
-- B3-13 : siang belum
(22, 5, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
(23, 5, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
(24, 5, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
-- C2-08 : malam & siang belum
(25, 6, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
(26, 6, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
(27, 6, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
-- C2-09 : semua belum
(28, 7, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
(29, 7, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
(30, 7, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Riwayat Pembayaran Lunas
INSERT INTO `payments` (`id`, `bill_id`, `receipt_no`, `amount_paid`, `payment_method`, `paid_at`) VALUES
-- B3-12 (Juli - September)
(1, 1, 'INV-202607-B312-01', 50000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(2, 2, 'INV-202607-B312-02', 40000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(3, 3, 'INV-202607-B312-03', 35000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(4, 4, 'INV-202608-B312-01', 50000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(5, 5, 'INV-202608-B312-02', 40000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(6, 6, 'INV-202608-B312-03', 35000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(7, 7, 'INV-202609-B312-01', 50000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
(8, 8, 'INV-202609-B312-02', 40000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
(9, 9, 'INV-202609-B312-03', 35000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
-- Oktober: A1-05 semua
(10, 10, 'INV-202610-A105-1', 50000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
(11, 11, 'INV-202610-A105-2', 40000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
(12, 12, 'INV-202610-A105-3', 35000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
-- Oktober: A1-06 siang
(13, 14, 'INV-202610-A106-2', 40000.00, 'Tunai via Petugas', '2026-10-04 07:15:00'),
-- Oktober: B3-11 semua
(14, 16, 'INV-202610-B311-1', 50000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
(15, 17, 'INV-202610-B311-2', 40000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
(16, 18, 'INV-202610-B311-3', 35000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
-- Oktober: B3-13 malam & sampah
(17, 22, 'INV-202610-B313-1', 50000.00, 'Tunai via Petugas', '2026-10-03 21:10:00'),
(18, 24, 'INV-202610-B313-3', 35000.00, 'Tunai via Petugas', '2026-10-05 07:40:00'),
-- Oktober: C2-08 sampah
(19, 27, 'INV-202610-C208-3', 35000.00, 'QRIS Instant', '2026-10-04 10:22:00')
ON DUPLICATE KEY UPDATE `id`=`id`;
