<?php
// login.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if (getCurrentUser()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

// Notifikasi keamanan sesi
if (isset($_GET['sec'])) {
    if ($_GET['sec'] === 'timeout') {
        $error = 'Sesi Anda telah berakhir karena tidak ada aktivitas selama 30 menit. Silakan masuk kembali.';
    } elseif ($_GET['sec'] === 'hijack_detected') {
        $error = 'Peringatan Keamanan: Terdeteksi perubahan perangkat/jaringan. Sesi telah dihentikan untuk melindungi akun Anda.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $error = 'Sesi keamanan CSRF tidak valid. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $rawPass = (string)($_POST['password'] ?? '');
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateLimitKey = $clientIp . '_' . $username;

        // Rate Limiter Anti-Brute Force
        $rateCheck = checkRateLimit($rateLimitKey, 5, 300);
        if (!$rateCheck['allowed']) {
            $error = $rateCheck['message'];
        } elseif (empty($username) || empty($rawPass)) {
            $error = 'Username dan kata sandi wajib diisi.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, password_hash, full_name, role FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($rawPass, $user['password_hash'])) {
                // Berhasil login: reset percobaan gagal
                resetLoginAttempts($rateLimitKey);

                // Anti-Session Fixation: regenerate session id baru secara kriptografis
                session_regenerate_id(true);

                // Simpan identitas & sidik jari sesi
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['ua_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_client');
                $_SESSION['last_activity'] = time();

                // Redirect sesuai role
                if (in_array($user['role'], ['satpam_siang', 'satpam_malam', 'sampah', 'super_admin'], true)) {
                    header('Location: monitoring.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                // Catat kegagalan login untuk mendeteksi serangan brute force
                recordLoginFailure($rateLimitKey, 5, 300);
                $error = 'Nomor rumah / akun atau kata sandi tidak cocok. Silakan periksa kembali.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk — SIBAR Portal Iuran Warga</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-header">
      <div class="logo-badge">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <h2>SIBAR</h2>
      <p>Sistem Informasi Iuran Keamanan & Sampah</p>
    </div>

    <?php if ($error): ?>
      <div class="auth-alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" class="auth-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

      <div class="form-group">
        <label for="username">Akun / Username</label>
        <input type="text" id="username" name="username" class="form-control" placeholder="Contoh: b3-12 atau satpam_siang" required value="<?= htmlspecialchars($_POST['username'] ?? 'b3-12') ?>">
      </div>

      <div class="form-group">
        <label for="password">Kata Sandi</label>
        <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password" required value="password123">
      </div>

      <button type="submit" class="btn-auth-submit">Masuk ke Dashboard</button>
    </form>

    <div class="auth-hint">
      <div style="font-size:11px; font-weight:700; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px;">
        Pilih Cepat Akun Demo (Password: <code>password123</code>):
      </div>
      <div class="quick-login-grid">
        <button type="button" class="quick-chip" onclick="fillQuick('b3-12')">🏠 b3-12 (KK)</button>
        <button type="button" class="quick-chip" onclick="fillQuick('a1-05')">🏠 a1-05 (KK)</button>
        <button type="button" class="quick-chip" onclick="fillQuick('satpam_siang')">☀️ Satpam Siang</button>
        <button type="button" class="quick-chip" onclick="fillQuick('satpam_malam')">🌙 Satpam Malam</button>
        <button type="button" class="quick-chip" onclick="fillQuick('sampah')">🗑️ Sampah</button>
        <button type="button" class="quick-chip" onclick="fillQuick('superadmin')">⚡ Super Admin</button>
      </div>
    </div>
  </div>
</div>

<script>
  function fillQuick(u) {
    document.getElementById('username').value = u;
    document.getElementById('password').value = 'password123';
  }
</script>

</body>
</html>
