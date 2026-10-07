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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $error = 'Sesi keamanan tidak valid. Silakan coba lagi.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = 'Username dan password wajib diisi.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, password_hash, full_name FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => strtolower($username)]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Nomor rumah / akun atau password salah.';
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
        <label for="username">Akun Rumah / Username</label>
        <input type="text" id="username" name="username" class="form-control" placeholder="Contoh: b3-12" required value="<?= htmlspecialchars($_POST['username'] ?? 'b3-12') ?>">
      </div>

      <div class="form-group">
        <label for="password">Kata Sandi</label>
        <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password" required value="password123">
      </div>

      <button type="submit" class="btn-auth-submit">Masuk ke Dashboard</button>
    </form>

    <div class="auth-hint">
      <strong>Akun Uji Coba Default:</strong><br>
      • Blok B3 No. 12: <code>b3-12</code> (Pass: <code>password123</code>)<br>
      • Blok A1 No. 05: <code>a1-05</code> (Pass: <code>password123</code>)<br>
      • Blok C2 No. 08: <code>c2-08</code> (Pass: <code>password123</code>)
    </div>
  </div>
</div>

</body>
</html>
