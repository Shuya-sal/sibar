<?php
// config/database.php
// Konfigurasi Database SIBAR (XAMPP MySQL dengan fallback SQLite)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sibar_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Coba koneksi ke MySQL terlebih dahulu (Standar XAMPP)
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 2,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Jika DB belum ada di MySQL, coba buat database otomatis
        try {
            $rootDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
            $rootPdo = new PDO($rootDsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            initMySQLSchema($pdo);
            return $pdo;
        } catch (PDOException $ex) {
            // Jika MySQL tidak berjalan sama sekali (misal pengujian lokal tanpa service mysqld),
            // fallback ke SQLite agar aplikasi tetap 100% jalan langsung tanpa error mati
            return getSQLiteFallback();
        }
    }
}

function getSQLiteFallback(): PDO {
    static $sqlitePdo = null;
    if ($sqlitePdo !== null) {
        return $sqlitePdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $dbFile = $dataDir . '/sibar.db';
    $needInit = !file_exists($dbFile);

    $sqlitePdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($needInit) {
        initSQLiteSchema($sqlitePdo);
    }

    return $sqlitePdo;
}

function initMySQLSchema(PDO $pdo): void {
    $sqlFile = __DIR__ . '/../database.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $pdo->exec($sql);
    }
}

function initSQLiteSchema(PDO $pdo): void {
    $queries = "
    CREATE TABLE IF NOT EXISTS houses (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      block TEXT NOT NULL,
      number TEXT NOT NULL,
      address TEXT NOT NULL,
      status_huni TEXT DEFAULT 'tetap',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(block, number)
    );

    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      house_id INTEGER NOT NULL,
      username TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      full_name TEXT NOT NULL,
      phone TEXT NOT NULL,
      role TEXT DEFAULT 'warga',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS fee_types (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT NOT NULL UNIQUE,
      name TEXT NOT NULL,
      amount REAL NOT NULL,
      icon TEXT DEFAULT '💰',
      description TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS bills (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      house_id INTEGER NOT NULL,
      fee_type_id INTEGER NOT NULL,
      period_year INTEGER NOT NULL,
      period_month INTEGER NOT NULL,
      amount REAL NOT NULL,
      status TEXT DEFAULT 'unpaid',
      due_date DATE NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE CASCADE,
      FOREIGN KEY (fee_type_id) REFERENCES fee_types(id) ON DELETE CASCADE,
      UNIQUE(house_id, fee_type_id, period_year, period_month)
    );

    CREATE TABLE IF NOT EXISTS payments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      bill_id INTEGER NOT NULL,
      receipt_no TEXT NOT NULL UNIQUE,
      amount_paid REAL NOT NULL,
      payment_method TEXT NOT NULL,
      paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
    );

    -- Seed Rumah
    INSERT OR IGNORE INTO houses (id, block, number, address, status_huni) VALUES
    (1, 'B3', '12', 'Jl. Cempaka Raya No. 12, RT 04 / RW 08', 'tetap'),
    (2, 'A1', '05', 'Jl. Cempaka Raya No. 05, RT 04 / RW 08', 'tetap'),
    (3, 'C2', '08', 'Jl. Cempaka Raya No. 08, RT 04 / RW 08', 'kontrak');

    -- Seed User (Password: password123)
    INSERT OR IGNORE INTO users (id, house_id, username, password_hash, full_name, phone, role) VALUES
    (1, 1, 'b3-12', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Hendra Pratama', '081234567890', 'warga'),
    (2, 2, 'a1-05', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Budi Santoso', '081298765432', 'warga'),
    (3, 3, 'c2-08', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Siti Rahma', '081345678901', 'warga');

    -- Seed Pos Iuran
    INSERT OR IGNORE INTO fee_types (id, code, name, amount, icon, description) VALUES
    (1, 'jaga_malam', 'Iuran Jaga Malam', 50000.00, '🌙', 'Honor ronda malam (22:00 - 05:00), senter, dan pemeliharaan pos ronda kamling.'),
    (2, 'jaga_siang', 'Iuran Jaga Siang', 40000.00, '☀️', 'Penjagaan gerbang utama (06:00 - 18:00), penerimaan kurir paket, dan patroli siang.'),
    (3, 'sampah', 'Iuran Sampah & Kebersihan', 35000.00, '🗑️', 'Pengangkutan sampah rumah tangga 3x seminggu ke TPA dan pembersihan gorong-gorong.');

    -- Seed Tagihan Lunas Juli, Agustus, September 2026
    INSERT OR IGNORE INTO bills (id, house_id, fee_type_id, period_year, period_month, amount, status, due_date) VALUES
    (1, 1, 1, 2026, 7, 50000.00, 'paid', '2026-07-15'),
    (2, 1, 2, 2026, 7, 40000.00, 'paid', '2026-07-15'),
    (3, 1, 3, 2026, 7, 35000.00, 'paid', '2026-07-15'),
    (4, 1, 1, 2026, 8, 50000.00, 'paid', '2026-08-15'),
    (5, 1, 2, 2026, 8, 40000.00, 'paid', '2026-08-15'),
    (6, 1, 3, 2026, 8, 35000.00, 'paid', '2026-08-15'),
    (7, 1, 1, 2026, 9, 50000.00, 'paid', '2026-09-15'),
    (8, 1, 2, 2026, 9, 40000.00, 'paid', '2026-09-15'),
    (9, 1, 3, 2026, 9, 35000.00, 'paid', '2026-09-15'),

    -- Seed Tagihan Belum Dibayar Bulan Berjalan (Oktober 2026)
    (10, 1, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
    (11, 1, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
    (12, 1, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15');

    -- Seed Riwayat Kwitansi
    INSERT OR IGNORE INTO payments (id, bill_id, receipt_no, amount_paid, payment_method, paid_at) VALUES
    (1, 1, 'INV-202607-B312-01', 50000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
    (2, 2, 'INV-202607-B312-02', 40000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
    (3, 3, 'INV-202607-B312-03', 35000.00, 'Tunai via Bendahara', '2026-07-05 19:40:00'),
    (4, 4, 'INV-202608-B312-01', 50000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
    (5, 5, 'INV-202608-B312-02', 40000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
    (6, 6, 'INV-202608-B312-03', 35000.00, 'BCA Virtual Account', '2026-08-02 09:12:00'),
    (7, 7, 'INV-202609-B312-01', 50000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
    (8, 8, 'INV-202609-B312-02', 40000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
    (9, 9, 'INV-202609-B312-03', 35000.00, 'QRIS Instant', '2026-09-03 14:20:00');
    ";

    $pdo->exec($queries);
}

// Helper CSRF Token
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Helper Format Rupiah
function formatRupiah(float|int $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
