<?php
// config/database.php
// Konfigurasi Database SIBAR (XAMPP MySQL dengan fallback SQLite)
// Roles: warga, satpam_siang, satpam_malam, sampah, super_admin

require_once __DIR__ . '/security.php';

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
            // Jika MySQL tidak berjalan sama sekali,
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
    CREATE TABLE IF NOT EXISTS complexes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT NOT NULL UNIQUE,
      name TEXT NOT NULL,
      address TEXT NOT NULL,
      latitude REAL NOT NULL,
      longitude REAL NOT NULL,
      city TEXT DEFAULT 'Jakarta Selatan',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS houses (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      complex_id INTEGER DEFAULT 1,
      block TEXT NOT NULL,
      number TEXT NOT NULL,
      address TEXT NOT NULL,
      lane TEXT DEFAULT 'Jalur Utama',
      status_huni TEXT DEFAULT 'tetap',
      latitude REAL,
      longitude REAL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (complex_id) REFERENCES complexes(id) ON DELETE SET NULL,
      UNIQUE(block, number)
    );

    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      house_id INTEGER,
      complex_id INTEGER DEFAULT 1,
      username TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      full_name TEXT NOT NULL,
      phone TEXT NOT NULL,
      role TEXT DEFAULT 'warga',
      latitude REAL,
      longitude REAL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE SET NULL,
      FOREIGN KEY (complex_id) REFERENCES complexes(id) ON DELETE SET NULL
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

    -- Seed Master Komplek Perumahan
    INSERT OR IGNORE INTO complexes (id, code, name, address, latitude, longitude, city) VALUES
    (1, 'graha-asri', 'Komplek Graha Asri RT 04', 'Jl. Cempaka Raya, RT 04 / RW 08', -6.208763, 106.845599, 'Jakarta Selatan'),
    (2, 'bukit-indah', 'Komplek Bukit Indah Asri RT 05', 'Jl. Bukit Indah Raya, RT 05 / RW 08', -6.215500, 106.852000, 'Jakarta Selatan');

    -- Seed Rumah (3 Blok / Jalur)
    INSERT OR IGNORE INTO houses (id, complex_id, block, number, address, lane, status_huni, latitude, longitude) VALUES
    (1, 1, 'A1', '05', 'Jl. Cempaka Raya No. 05, RT 04 / RW 08', 'Jalur Utama Timur',  'tetap', -6.208763, 106.845599),
    (2, 1, 'A1', '06', 'Jl. Cempaka Raya No. 06, RT 04 / RW 08', 'Jalur Utama Timur',  'kontrak', -6.208801, 106.845620),
    (3, 1, 'B3', '11', 'Jl. Cempaka Raya No. 11, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap', -6.208910, 106.845700),
    (4, 1, 'B3', '12', 'Jl. Cempaka Raya No. 12, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap', -6.208945, 106.845735),
    (5, 1, 'B3', '13', 'Jl. Cempaka Raya No. 13, RT 04 / RW 08', 'Jalur Utama Tengah', 'tetap', -6.208980, 106.845770),
    (6, 1, 'C2', '08', 'Jl. Cempaka Raya No. 08, RT 04 / RW 08', 'Jalur Selatan',      'kontrak', -6.209100, 106.845850),
    (7, 1, 'C2', '09', 'Jl. Cempaka Raya No. 09, RT 04 / RW 08', 'Jalur Selatan',      'tetap', -6.209150, 106.845890);

    -- Seed User (Password: password123)
    INSERT OR IGNORE INTO users (id, house_id, username, password_hash, full_name, phone, role) VALUES
    (1, 1, 'a1-05', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Budi Santoso',   '081298765432', 'warga'),
    (2, 2, 'a1-06', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Dewi Lestari',    '081311223344', 'warga'),
    (3, 3, 'b3-11', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Agus Wijaya',    '081355667788', 'warga'),
    (4, 4, 'b3-12', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Hendra Pratama', '081234567890', 'warga'),
    (5, 5, 'b3-13', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Rudi Hartono',   '081399887766', 'warga'),
    (6, 6, 'c2-08', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Ibu Siti Rahma',      '081345678901', 'warga'),
    (7, 7, 'c2-09', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Joko Susilo',    '081377665544', 'warga'),
    (8, NULL, 'satpam_siang', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Tono Wibowo',   '081500011122', 'satpam_siang'),
    (9, NULL, 'satpam_malam', '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Slamet Riyadi', '081500033344', 'satpam_malam'),
    (10, NULL, 'sampah',      '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Bpk. Darma Putra',   '081500055566', 'sampah'),
    (11, NULL, 'superadmin',  '$2y$10$8HwqKjVaLyLof8uQRvZBWeAeWqpK2DOh4CLCxN9ZJPO7fmNDRbyhy', 'Admin RT 04',        '081500077788', 'super_admin');

    -- Seed Pos Iuran
    INSERT OR IGNORE INTO fee_types (id, code, name, amount, icon, description) VALUES
    (1, 'jaga_malam', 'Iuran Jaga Malam', 50000.00, '🌙', 'Honor ronda malam (22:00 - 05:00), senter, dan pemeliharaan pos ronda kamling.'),
    (2, 'jaga_siang', 'Iuran Jaga Siang', 40000.00, '☀️', 'Penjagaan gerbang utama (06:00 - 18:00), penerimaan kurir paket, dan patroli siang.'),
    (3, 'sampah', 'Iuran Sampah & Kebersihan', 35000.00, '🗑️', 'Pengangkutan sampah rumah tangga 3x seminggu ke TPA dan pembersihan gorong-gorong.');

    -- Seed Tagihan B3-12 (house 4) Juli - Oktober 2026
    INSERT OR IGNORE INTO bills (id, house_id, fee_type_id, period_year, period_month, amount, status, due_date) VALUES
    (1, 4, 1, 2026, 7, 50000.00, 'paid', '2026-07-15'),
    (2, 4, 2, 2026, 7, 40000.00, 'paid', '2026-07-15'),
    (3, 4, 3, 2026, 7, 35000.00, 'paid', '2026-07-15'),
    (4, 4, 1, 2026, 8, 50000.00, 'paid', '2026-08-15'),
    (5, 4, 2, 2026, 8, 40000.00, 'paid', '2026-08-15'),
    (6, 4, 3, 2026, 8, 35000.00, 'paid', '2026-08-15'),
    (7, 4, 1, 2026, 9, 50000.00, 'paid', '2026-09-15'),
    (8, 4, 2, 2026, 9, 40000.00, 'paid', '2026-09-15'),
    (9, 4, 3, 2026, 9, 35000.00, 'paid', '2026-09-15'),
    (19, 4, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
    (20, 4, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
    (21, 4, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15');

    -- Seed Tagihan Oktober 2026 rumah lain
    INSERT OR IGNORE INTO bills (id, house_id, fee_type_id, period_year, period_month, amount, status, due_date) VALUES
    (10, 1, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
    (11, 1, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
    (12, 1, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
    (13, 2, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
    (14, 2, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
    (15, 2, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15'),
    (16, 3, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
    (17, 3, 2, 2026, 10, 40000.00, 'paid', '2026-10-15'),
    (18, 3, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
    (22, 5, 1, 2026, 10, 50000.00, 'paid', '2026-10-15'),
    (23, 5, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
    (24, 5, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
    (25, 6, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
    (26, 6, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
    (27, 6, 3, 2026, 10, 35000.00, 'paid', '2026-10-15'),
    (28, 7, 1, 2026, 10, 50000.00, 'unpaid', '2026-10-15'),
    (29, 7, 2, 2026, 10, 40000.00, 'unpaid', '2026-10-15'),
    (30, 7, 3, 2026, 10, 35000.00, 'unpaid', '2026-10-15');

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
    (9, 9, 'INV-202609-B312-03', 35000.00, 'QRIS Instant', '2026-09-03 14:20:00'),
    (10, 10, 'INV-202610-A105-1', 50000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
    (11, 11, 'INV-202610-A105-2', 40000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
    (12, 12, 'INV-202610-A105-3', 35000.00, 'QRIS Instant', '2026-10-02 08:05:00'),
    (13, 14, 'INV-202610-A106-2', 40000.00, 'Tunai via Petugas', '2026-10-04 07:15:00'),
    (14, 16, 'INV-202610-B311-1', 50000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
    (15, 17, 'INV-202610-B311-2', 40000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
    (16, 18, 'INV-202610-B311-3', 35000.00, 'BCA Virtual Account', '2026-10-01 20:31:00'),
    (17, 22, 'INV-202610-B313-1', 50000.00, 'Tunai via Petugas', '2026-10-03 21:10:00'),
    (18, 24, 'INV-202610-B313-3', 35000.00, 'Tunai via Petugas', '2026-10-05 07:40:00'),
    (19, 27, 'INV-202610-C208-3', 35000.00, 'QRIS Instant', '2026-10-04 10:22:00');
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

// Helper Label Role
function roleLabel(string $role): string {
    return match ($role) {
        'warga'        => 'Kepala Keluarga',
        'satpam_siang' => 'Satpam Jaga Siang',
        'satpam_malam' => 'Satpam Jaga Malam',
        'sampah'       => 'Petugas Sampah',
        'super_admin'  => 'Super Admin',
        default        => ucfirst($role),
    };
}

// Helper Label Periode
function periodLabel(?int $month, ?int $year = 2026): string {
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return ($names[$month] ?? 'Bulan ' . $month) . ' ' . ($year ?? 2026);
}
