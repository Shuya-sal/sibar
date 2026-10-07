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
