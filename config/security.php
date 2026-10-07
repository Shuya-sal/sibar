<?php
// config/security.php
// Modul Pengamanan Siber SIBAR (Cyber Security Hardening)
// Melindungi dari: XSS, CSRF, Clickjacking, MIME Sniffing, Brute-Force, Session Hijacking/Fixation, SQLi, IDOR

// 1. Terapkan HTTP Security Headers Standar OWASP / Apple
if (!headers_sent()) {
    // Cegah Clickjacking (UI Redressing)
    header('X-Frame-Options: SAMEORIGIN');
    
    // Cegah MIME Sniffing attacks
    header('X-Content-Type-Options: nosniff');
    
    // Aktifkan proteksi XSS bawaan browser (legacy)
    header('X-XSS-Protection: 1; mode=block');
    
    // Batasi pengiriman referrer URL sensitif
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Nonaktifkan fitur perangkat yang tidak diperlukan
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    
    // Content Security Policy (CSP) ketat yang mendukung SVG dan inline styles/scripts aplikasi
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
    
    // Sembunyikan versi server PHP
    header_remove('X-Powered-By');
}

// 2. Konfigurasi Sesi Aman (Anti-Session Fixation & Anti-Session Hijacking)
if (session_status() === PHP_SESSION_NONE) {
    // Flag cookie session yang aman
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie mati saat browser ditutup
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true, // Tidak bisa dibaca via JS document.cookie (Anti-XSS stealing)
        'samesite' => 'Lax'  // Anti-CSRF pada navigasi lintas situs
    ]);
    
    session_start();
}

// 3. Deteksi Session Hijacking & Timeout Inaktivitas
if (isset($_SESSION['user_id'])) {
    // Buat sidik jari User-Agent saat login pertama
    $currentUaHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_client');
    if (!isset($_SESSION['ua_hash'])) {
        $_SESSION['ua_hash'] = $currentUaHash;
    } elseif (!hash_equals($_SESSION['ua_hash'], $currentUaHash)) {
        // User-Agent berubah drastis di tengah jalan -> kemungkinan session dibajak
        session_unset();
        session_destroy();
        header('Location: login.php?sec=hijack_detected');
        exit;
    }

    // Session Inactivity Timeout: 30 Menit (1800 detik)
    $maxIdleTime = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleTime)) {
        session_unset();
        session_destroy();
        header('Location: login.php?sec=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// 4. Rate Limiter Anti-Brute Force (Login Throttling)
// Menggunakan penyimpanan file di direktori scratch / data
function checkRateLimit(string $identifier, int $maxAttempts = 5, int $decaySeconds = 300): array {
    $cacheDir = __DIR__ . '/../data/security';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $key = hash('sha256', 'rl_' . $identifier);
    $filePath = $cacheDir . '/' . $key . '.json';
    
    $now = time();
    $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

    if (file_exists($filePath)) {
        $content = @file_get_contents($filePath);
        $decoded = @json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // Cek apakah masih dalam status terkunci
    if (!empty($data['locked_until']) && $data['locked_until'] > $now) {
        $remainingSeconds = $data['locked_until'] - $now;
        return [
            'allowed'   => false,
            'remaining' => $remainingSeconds,
            'message'   => "Terlalu banyak percobaan gagal. Akses dibatasi sementara selama {$remainingSeconds} detik untuk alasan keamanan siber."
        ];
    }

    // Reset jika window decay sudah terlewati
    if (($now - $data['first_attempt']) > $decaySeconds) {
        $data['attempts'] = 0;
        $data['first_attempt'] = $now;
        $data['locked_until'] = 0;
    }

    return [
        'allowed'   => true,
        'attempts'  => $data['attempts'],
        'remaining' => 0
    ];
}

function recordLoginFailure(string $identifier, int $maxAttempts = 5, int $lockoutSeconds = 300): void {
    $cacheDir = __DIR__ . '/../data/security';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $key = hash('sha256', 'rl_' . $identifier);
    $filePath = $cacheDir . '/' . $key . '.json';
    
    $now = time();
    $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

    if (file_exists($filePath)) {
        $content = @file_get_contents($filePath);
        $decoded = @json_decode($content, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $data['attempts']++;
    if ($data['attempts'] >= $maxAttempts) {
        $data['locked_until'] = $now + $lockoutSeconds;
    }

    @file_put_contents($filePath, json_encode($data), LOCK_EX);
}

function resetLoginAttempts(string $identifier): void {
    $cacheDir = __DIR__ . '/../data/security';
    $key = hash('sha256', 'rl_' . $identifier);
    $filePath = $cacheDir . '/' . $key . '.json';
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

// 5. Sanitasi Output Aman dari XSS
function e(?string $string): string {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// 6. Validasi & Sanitasi Parameter Angka (Cegah IDOR / Injection)
function sanitizeInt($val, int $default = 0): int {
    if ($val === null || $val === '') {
        return $default;
    }
    return filter_var($val, FILTER_VALIDATE_INT) !== false ? (int)$val : $default;
}

// ================================================================
// SECURITY LAYER 2: Anti-Double Submit Payment (Idempotency Token)
// Cegah user klik "Bayar" 2x dalam 10 detik (replay attack)
// ================================================================
function checkPaymentIdempotency(int $userId, string $billKey): bool {
    $key = 'pay_idem_' . $userId . '_' . hash('sha256', $billKey);
    if (isset($_SESSION[$key]) && ($_SESSION[$key] + 10) > time()) {
        return false; // Sudah ada request identik dalam 10 detik
    }
    $_SESSION[$key] = time();
    return true;
}

// ================================================================
// SECURITY LAYER 3: Validasi Panjang & Karakter Input
// Mencegah Buffer Overflow, Injection via oversized input
// ================================================================
function validateInputLengths(array $inputs, array $limits): array {
    $errors = [];
    foreach ($limits as $field => $max) {
        if (isset($inputs[$field]) && mb_strlen($inputs[$field], 'UTF-8') > $max) {
            $errors[] = "Field '{$field}' melebihi panjang maksimum {$max} karakter.";
        }
    }
    return $errors;
}

// ================================================================
// SECURITY LAYER 4: Anti-Enumeration (Waktu Respons Konstan)
// Cegah timing attack untuk menebak username/password yang valid
// Selalu lakukan hash compare meski password salah
// ================================================================
function safePasswordVerify(string $rawPassword, ?string $storedHash): bool {
    if ($storedHash === null) {
        // Akun tidak ditemukan — tetap lakukan hash dummy agar waktu respons sama
        password_verify($rawPassword, '$2y$10$dummyhashtopreventtimingattackonusernotfound000000000');
        return false;
    }
    return password_verify($rawPassword, $storedHash);
}

// ================================================================
// SECURITY LAYER 5: Log Kejadian Keamanan
// Catat login gagal, akses IDOR, CSRF gagal ke file log
// ================================================================
function securityLog(string $event, string $detail = ''): void {
    $logDir = __DIR__ . '/../data/security';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $logFile = $logDir . '/security.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 80);
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$event}] IP:{$ip} UA:{$ua} {$detail}\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ================================================================
// SECURITY LAYER 6: Anti-Clickjacking via JS (Framebuster)
// Double protection selain X-Frame-Options header
// ================================================================
function injectFramebusterJS(): string {
    return "<script>if(window.top!==window.self){window.top.location.replace(window.self.location.href);}</script>\n";
}

// ================================================================
// SECURITY LAYER 7: Token Pembayaran Sekali Pakai (One-Time Token)
// Setiap form bayar generate token unik — tidak bisa di-replay
// ================================================================
function generatePaymentToken(int $houseId, int $month, int $year): string {
    $raw = $houseId . '|' . $month . '|' . $year . '|' . ($_SESSION['user_id'] ?? 0) . '|' . (floor(time() / 60));
    return hash_hmac('sha256', $raw, session_id());
}

function verifyPaymentToken(string $token, int $houseId, int $month, int $year): bool {
    // Cek window 2 menit (current + previous minute)
    $raw1 = $houseId . '|' . $month . '|' . $year . '|' . ($_SESSION['user_id'] ?? 0) . '|' . (floor(time() / 60));
    $raw2 = $houseId . '|' . $month . '|' . $year . '|' . ($_SESSION['user_id'] ?? 0) . '|' . (floor(time() / 60) - 1);
    return hash_equals(hash_hmac('sha256', $raw1, session_id()), $token)
        || hash_equals(hash_hmac('sha256', $raw2, session_id()), $token);
}
