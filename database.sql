-- SIBAR (Sistem Informasi Iuran Warga)
-- Database Schema for MySQL / MariaDB (XAMPP Compatible)

CREATE DATABASE IF NOT EXISTS `sibar_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sibar_db`;

-- 1. Tabel Rumah (Units)
CREATE TABLE IF NOT EXISTS `houses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `block` VARCHAR(10) NOT NULL,
  `number` VARCHAR(10) NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `status_huni` ENUM('tetap', 'kontrak') DEFAULT 'tetap',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_house` (`block`, `number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabel Pengguna (Kepala Keluarga / Pengurus)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `house_id` INT NOT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `role` ENUM('warga', 'pengurus', 'admin') DEFAULT 'warga',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`house_id`) REFERENCES `houses`(`id`) ON DELETE CASCADE
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
-- ==========================================

-- Data Rumah
INSERT INTO `houses` (`id`, `block`, `number`, `address`, `status_huni`) VALUES
(1, 'B3', '12', 'Jl. Cempaka Raya No. 12, RT 04 / RW 08', 'tetap'),
(2, 'A1', '05', 'Jl. Cempaka Raya No. 05, RT 04 / RW 08', 'tetap'),
(3, 'C2', '08', 'Jl. Cempaka Raya No. 08, RT 04 / RW 08', 'kontrak')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Data Pengguna: Kepala Keluarga
-- Password default: 'password123'
INSERT INTO `users` (`id`, `house_id`, `username`, `password_hash`, `full_name`, `phone`, `role`) VALUES
(1, 1, 'b3-12', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Hendra Pratama', '081234567890', 'warga'),
(2, 2, 'a1-05', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Budi Santoso', '081298765432', 'warga'),
(3, 3, 'c2-08', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Siti Rahma', '081345678901', 'warga')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Master Pos Iuran
INSERT INTO `fee_types` (`id`, `code`, `name`, `amount`, `icon`, `description`) VALUES
(1, 'jaga_malam', 'Iuran Jaga Malam', 50000.00, '🌙', 'Honor petugas ronda malam (22:00 - 05:00), senter, dan pemeliharaan pos ronda kamling.'),
(2, 'jaga_siang', 'Iuran Jaga Siang', 40000.00, '☀️', 'Penjagaan gerbang utama portal (06:00 - 18:00), penerimaan kurir paket, dan patroli siang.'),
(3, 'sampah', 'Iuran Sampah & Kebersihan', 35000.00, '🗑️', 'Pengangkutan sampah rumah tangga 3x seminggu ke TPA dan pembersihan gorong-gorong saluran air.')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Data Riwayat Lunas (Bulan Lalu: Juli, Agustus, September 2026) untuk Rumah B3-12
INSERT INTO `bills` (`id`, `house_id`, `fee_type_id`, `period_year`, `period_month`, `amount`, `status`, `due_date`) VALUES
(1, 1, 1, 2026, 7, 50000.00, 'paid', '2026-07-15'),
(2, 1, 2, 2026, 7, 40000.00, 'paid', '2026-07-15'),
(3, 1, 3, 2026, 7, 35000.00, 'paid', '2026-07-15'),
(4, 1, 1, 2026, 8, 50000.00, 'paid', '2026-08-15'),
(5, 1, 2, 2026, 8, 40000.00, 'paid', '2026-08-15'),
(6, 1, 3, 2026, 8, 35000.00, 'paid', '2026-08-15'),
(7, 1, 1, 2026, 9, 50000.00, 'paid', '2026-09-15'),
(8, 1, 2, 2026, 9, 40000.00, 'paid', '2026-09-15'),
(9, 1, 3, 2026, 9, 35000.00, 'paid', '2026-09-15'),

-- Tagihan Belum Dibayar Bulan Berjalan (Oktober 2026) untuk Rumah B3-12
(10, 1, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
(11, 1, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
(12, 1, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Riwayat Pembayaran Lunas
INSERT INTO `payments` (`id`, `bill_id`, `receipt_no`, `amount_paid`, `payment_method`, `paid_at`) VALUES
(1, 1, 'INV-202607-B312-01', 50000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(2, 2, 'INV-202607-B312-02', 40000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(3, 3, 'INV-202607-B312-03', 35000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
(4, 4, 'INV-202608-B312-01', 50000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(5, 5, 'INV-202608-B312-02', 40000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(6, 6, 'INV-202608-B312-03', 35000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
(7, 7, 'INV-202609-B312-01', 50000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
(8, 8, 'INV-202609-B312-02', 40000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
(9, 9, 'INV-202609-B312-03', 35000.00, 'QRIS Instant', '2026-09-03 14:20:00')
ON DUPLICATE KEY UPDATE `id`=`id`;
