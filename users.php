<?php
// users.php — Pengaturan & Kelola Pengguna (Super Admin Only)
// CRUD: Kepala Keluarga (Warga), Satpam Siang, Satpam Malam, Petugas Sampah
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAnyRole(['super_admin']);
$db = getDB();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ==========================================
// HANDLE ACTIONS (ADD, EDIT, DELETE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) {
        $_SESSION['flash_error'] = 'Token CSRF tidak valid.';
        header('Location: users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // --- 1. TAMBAH PENGGUNA BARU ---
    if ($action === 'add') {
        $role = trim($_POST['role'] ?? 'warga');
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = trim($_POST['password'] ?? 'password123');

        if (empty($fullName) || empty($phone) || empty($username)) {
            $_SESSION['flash_error'] = 'Nama lengkap, nomor HP, dan username wajib diisi.';
            header('Location: users.php');
            exit;
        }

        // Cek username unik
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $stmtCheck->execute([':u' => $username]);
        if ($stmtCheck->fetch()) {
            $_SESSION['flash_error'] = "Username '{$username}' sudah digunakan oleh akun lain.";
            header('Location: users.php');
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $houseId = null;

        try {
            $db->beginTransaction();

            if ($role === 'warga') {
                $houseChoice = $_POST['house_choice'] ?? 'new';
                if ($houseChoice === 'existing') {
                    $houseId = (int)($_POST['existing_house_id'] ?? 0);
                    if ($houseId <= 0) $houseId = null;
                } else {
                    // Buat rumah baru
                    $block = strtoupper(trim($_POST['block'] ?? ''));
                    $number = trim($_POST['number'] ?? '');
                    $lane = trim($_POST['lane'] ?? 'Jalur Utama');
                    $address = trim($_POST['address'] ?? '');
                    $statusHuni = trim($_POST['status_huni'] ?? 'tetap');

                    if (empty($block) || empty($number)) {
                        throw new Exception("Blok dan nomor rumah wajib diisi untuk Kepala Keluarga.");
                    }
                    if (empty($address)) {
                        $address = "Jl. Cempaka Raya No. {$number}, Blok {$block}, RT 04 / RW 08";
                    }

                    // Cek apakah rumah sudah ada
                    $stmtHouse = $db->prepare("SELECT id FROM houses WHERE block = :b AND number = :n LIMIT 1");
                    $stmtHouse->execute([':b' => $block, ':n' => $number]);
                    $existingH = $stmtHouse->fetch();

                    if ($existingH) {
                        $houseId = (int)$existingH['id'];
                    } else {
                        $stmtInsertHouse = $db->prepare("
                            INSERT INTO houses (block, number, lane, address, status_huni)
                            VALUES (:b, :n, :l, :a, :sh)
                        ");
                        $stmtInsertHouse->execute([
                            ':b' => $block,
                            ':n' => $number,
                            ':l' => $lane ?: 'Jalur Utama',
                            ':a' => $address,
                            ':sh' => $statusHuni,
                        ]);
                        $houseId = (int)$db->lastInsertId();

                        // Buatkan tagihan bulan berjalan (Oktober 2026) otomatis untuk 3 pos iuran
                        $stmtFees = $db->query("SELECT id, amount FROM fee_types");
                        $fees = $stmtFees->fetchAll();
                        $stmtBill = $db->prepare("
                            INSERT INTO bills (house_id, fee_type_id, period_year, period_month, amount, status, due_date)
                            VALUES (:hid, :fid, 2026, 10, :amt, 'unpaid', '2026-10-15')
                        ");
                        foreach ($fees as $f) {
                            $stmtBill->execute([
                                ':hid' => $houseId,
                                ':fid' => $f['id'],
                                ':amt' => $f['amount'],
                            ]);
                        }
                    }
                }
            }

            // Simpan akun pengguna
            $stmtInsertUser = $db->prepare("
                INSERT INTO users (house_id, username, password_hash, full_name, phone, role)
                VALUES (:hid, :u, :p, :fn, :ph, :r)
            ");
            $stmtInsertUser->execute([
                ':hid' => $houseId,
                ':u'   => $username,
                ':p'   => $passwordHash,
                ':fn'  => $fullName,
                ':ph'  => $phone,
                ':r'   => $role,
            ]);

            $db->commit();
            $_SESSION['flash_success'] = "Akun {$fullName} ({$username}) dengan peran " . roleLabel($role) . " berhasil ditambahkan!";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'Gagal menambahkan akun: ' . $e->getMessage();
        }

        header('Location: users.php');
        exit;
    }

    // --- 2. EDIT PENGGUNA ---
    if ($action === 'edit') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $role = trim($_POST['role'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($userId <= 0 || empty($fullName) || empty($phone) || empty($username)) {
            $_SESSION['flash_error'] = 'Data yang dikirimkan tidak lengkap.';
            header('Location: users.php');
            exit;
        }

        // Cek username unik milik orang lain
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE username = :u AND id != :id LIMIT 1");
        $stmtCheck->execute([':u' => $username, ':id' => $userId]);
        if ($stmtCheck->fetch()) {
            $_SESSION['flash_error'] = "Username '{$username}' sudah dipakai akun lain.";
            header('Location: users.php');
            exit;
        }

        try {
            $db->beginTransaction();

            // Ambil data user lama
            $stmtOld = $db->prepare("SELECT house_id, role FROM users WHERE id = :id");
            $stmtOld->execute([':id' => $userId]);
            $oldUser = $stmtOld->fetch();

            if (!$oldUser) {
                throw new Exception("Pengguna tidak ditemukan.");
            }

            // Update data rumah jika role warga
            if ($oldUser['role'] === 'warga' && $oldUser['house_id']) {
                $block = strtoupper(trim($_POST['block'] ?? ''));
                $number = trim($_POST['number'] ?? '');
                $lane = trim($_POST['lane'] ?? '');
                $statusHuni = trim($_POST['status_huni'] ?? 'tetap');

                if (!empty($block) && !empty($number)) {
                    $stmtUpHouse = $db->prepare("
                        UPDATE houses
                        SET block = :b, number = :n, lane = :l, status_huni = :sh
                        WHERE id = :hid
                    ");
                    $stmtUpHouse->execute([
                        ':b'   => $block,
                        ':n'   => $number,
                        ':l'   => $lane ?: 'Jalur Utama',
                        ':sh'  => $statusHuni,
                        ':hid' => $oldUser['house_id']
                    ]);
                }
            }

            // Update user
            $params = [
                ':fn' => $fullName,
                ':ph' => $phone,
                ':u'  => $username,
                ':id' => $userId,
            ];

            $sql = "UPDATE users SET full_name = :fn, phone = :ph, username = :u";

            if (!empty($role) && $oldUser['role'] !== 'super_admin') {
                $sql .= ", role = :r";
                $params[':r'] = $role;
            }

            if (!empty($password)) {
                $sql .= ", password_hash = :p";
                $params[':p'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= " WHERE id = :id";
            $stmtUpdate = $db->prepare($sql);
            $stmtUpdate->execute($params);

            $db->commit();
            $_SESSION['flash_success'] = "Data akun {$fullName} ({$username}) berhasil diperbarui!";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'Gagal memperbarui akun: ' . $e->getMessage();
        }

        header('Location: users.php');
        exit;
    }

    // --- 3. HAPUS PENGGUNA ---
    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'ID pengguna tidak valid.';
            header('Location: users.php');
            exit;
        }

        if ($userId === (int)$_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'Anda tidak dapat menghapus akun Anda sendiri.';
            header('Location: users.php');
            exit;
        }

        try {
            $stmtGet = $db->prepare("SELECT full_name, username, role FROM users WHERE id = :id");
            $stmtGet->execute([':id' => $userId]);
            $delUser = $stmtGet->fetch();

            if (!$delUser) {
                $_SESSION['flash_error'] = 'Pengguna tidak ditemukan.';
            } else {
                $stmtDel = $db->prepare("DELETE FROM users WHERE id = :id");
                $stmtDel->execute([':id' => $userId]);
                $_SESSION['flash_success'] = "Akun {$delUser['full_name']} ({$delUser['username']}) berhasil dihapus.";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Gagal menghapus pengguna: ' . $e->getMessage();
        }

        header('Location: users.php');
        exit;
    }
}

// ==========================================
// AMBIL SEMUA DATA UNTUK TAMPILAN
// ==========================================
$stmtUsers = $db->query("
    SELECT u.id, u.username, u.full_name, u.phone, u.role, u.house_id, u.created_at,
           h.block, h.number, h.lane, h.address, h.status_huni
    FROM users u
    LEFT JOIN houses h ON h.id = u.house_id
    ORDER BY
      CASE u.role
        WHEN 'super_admin' THEN 1
        WHEN 'satpam_siang' THEN 2
        WHEN 'satpam_malam' THEN 3
        WHEN 'sampah' THEN 4
        ELSE 5
      END,
      h.block ASC,
      CAST(h.number AS UNSIGNED) ASC,
      u.id ASC
");
$allUsers = $stmtUsers->fetchAll();

// Ambil daftar rumah yang belum ada user-nya (untuk pilihan rumah existing)
$stmtVacantHouses = $db->query("
    SELECT h.id, h.block, h.number, h.lane, h.status_huni
    FROM houses h
    LEFT JOIN users u ON u.house_id = h.id
    WHERE u.id IS NULL
    ORDER BY h.block ASC, CAST(h.number AS UNSIGNED) ASC
");
$vacantHouses = $stmtVacantHouses->fetchAll();

// Statistik counts
$counts = [
    'all'    => count($allUsers),
    'warga'  => 0,
    'satpam' => 0,
    'sampah' => 0,
    'admin'  => 0,
];
foreach ($allUsers as $u) {
    if ($u['role'] === 'warga') $counts['warga']++;
    elseif (in_array($u['role'], ['satpam_siang', 'satpam_malam'])) $counts['satpam']++;
    elseif ($u['role'] === 'sampah') $counts['sampah']++;
    elseif ($u['role'] === 'super_admin') $counts['admin']++;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kelola Akun & Warga — SIBAR Super Admin</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/monitoring.css">
  <style>
    /* Styling Tambahan Pengaturan Akun */
    .admin-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .filter-tabs {
      display: flex;
      gap: 6px;
      background: var(--bg-subtle);
      padding: 4px;
      border-radius: var(--radius-pill);
      border: 1px solid var(--border-subtle);
      overflow-x: auto;
    }

    .filter-tab {
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      background: none;
      border: none;
      border-radius: var(--radius-pill);
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.12s ease;
    }

    .filter-tab.active {
      background: #fff;
      color: var(--text-primary);
      font-weight: 600;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    .search-input-wrap {
      position: relative;
      min-width: 240px;
    }

    .search-input-wrap input {
      width: 100%;
      padding: 7px 12px 7px 32px;
      font-size: 12.5px;
      border-radius: var(--radius-pill);
      border: 1px solid var(--border-subtle);
      background: #fff;
      outline: none;
    }

    .search-input-wrap input:focus {
      border-color: var(--accent-blue);
    }

    .search-input-wrap svg {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 14px;
      height: 14px;
      color: var(--text-tertiary);
    }

    .btn-action-icon {
      background: rgba(0, 0, 0, 0.03);
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: var(--radius-pill);
      padding: 4px 11px;
      font-size: 11.5px;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.12s ease;
      color: var(--text-primary);
      text-decoration: none;
    }

    .btn-action-icon:hover {
      background: #fff;
      border-color: var(--accent-blue);
      color: var(--accent-blue);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    }

    .btn-action-icon.danger {
      color: #d70015;
      background: rgba(215, 0, 21, 0.05);
      border-color: rgba(215, 0, 21, 0.18);
    }

    .btn-action-icon.danger:hover {
      background: #d70015;
      border-color: #d70015;
      color: #fff;
      box-shadow: 0 2px 6px rgba(215, 0, 21, 0.25);
    }

    /* Modal Form Styles */
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .form-hint-text {
      font-size: 11px;
      color: var(--text-tertiary);
      margin-top: 2px;
    }

    .role-option-group {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 8px;
      margin-bottom: 4px;
    }

    .role-radio-btn {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      cursor: pointer;
      font-size: 12px;
      transition: all 0.12s ease;
      background: var(--bg-subtle);
    }

    .role-radio-btn.selected {
      border-color: var(--accent-blue);
      background: var(--accent-blue-subtle);
      color: var(--accent-blue);
      font-weight: 600;
    }

    .house-subform {
      background: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    @media (max-width: 768px) {
      .form-grid-2, .role-option-group { grid-template-columns: 1fr; }
      .search-input-wrap { width: 100%; }
    }
  </style>
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR SUPER ADMIN -->
  <aside class="sidebar">
    <div class="brand-section">
      <div class="brand-logo">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <div class="brand-text">
        <h1>SIBAR</h1>
        <p>Portal Iuran Warga</p>
      </div>
    </div>

    <nav class="nav-menu">
      <div class="nav-label">Monitoring</div>
      <a href="monitoring.php" class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        Peta Rumah (Blok & Jalur)
      </a>

      <div class="nav-label">Administrasi</div>
      <a href="users.php" class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Kelola Akun (KK, Satpam, Sampah)
      </a>

      <div class="nav-label">Akun</div>
      <a href="logout.php" class="nav-item" style="color:#d70015;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Keluar (Logout)
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="avatar"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="house-tag" style="margin-top:3px;"><span class="role-badge super_admin">SUPER ADMIN</span></div>
      </div>
    </div>
  </aside>

  <!-- MAIN WRAPPER -->
  <main class="main-wrapper">
    <header class="top-header">
      <div class="breadcrumb">
        <span>SIBAR</span>
        <span>/</span>
        <span class="current">Kelola Akun &amp; Pengaturan</span>
      </div>
      <div class="header-actions">
        <button type="button" class="btn-primary" onclick="openAddModal()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Tambah Akun Baru
        </button>
      </div>
    </header>

    <div class="content-body">

      <!-- WELCOME HERO -->
      <section class="household-hero">
        <div class="hero-meta">
          <h2>Pusat Pengaturan Pengguna &amp; Rumah</h2>
          <p>Kelola seluruh akun <strong>Kepala Keluarga (Warga)</strong>, <strong>Satpam Jaga Siang &amp; Malam</strong>, serta <strong>Petugas Sampah</strong>. Tambah, edit kredensial/data unit, atau hapus akses pengguna dari sistem.</p>
          <div class="hero-badges">
            <span class="hero-tag">Akses: Super Admin RT 04</span>
            <span class="hero-tag">Total Akun: <?= $counts['all'] ?> Pengguna</span>
            <span class="hero-tag"><?= $counts['warga'] ?> Kepala Keluarga</span>
            <span class="hero-tag"><?= $counts['satpam'] ?> Satpam</span>
            <span class="hero-tag"><?= $counts['sampah'] ?> Petugas Sampah</span>
          </div>
        </div>
        <div class="hero-summary-box">
          <div class="sub">Total Entitas</div>
          <div class="amount"><?= $counts['all'] ?> <span style="font-size:14px; color:var(--text-tertiary); font-weight:500;">Akun</span></div>
          <span class="status-badge paid">● Data Tersinkronisasi</span>
        </div>
      </section>

      <!-- TABEL DAFTAR PENGGUNA -->
      <section class="section-container">
        <div class="section-header">
          <div class="section-title-wrap">
            <h3>Daftar Pengguna Sistem SIBAR</h3>
            <p>Gunakan filter tab dan bilah pencarian untuk menyaring akun yang ingin diatur.</p>
          </div>
          <div class="search-input-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" id="searchInput" placeholder="Cari nama, akun, blok..." onkeyup="filterUserTable()">
          </div>
        </div>

        <div class="admin-toolbar">
          <div class="filter-tabs">
            <button type="button" class="filter-tab active" onclick="setRoleFilter('all', this)">Semua (<?= $counts['all'] ?>)</button>
            <button type="button" class="filter-tab" onclick="setRoleFilter('warga', this)">Kepala Keluarga (<?= $counts['warga'] ?>)</button>
            <button type="button" class="filter-tab" onclick="setRoleFilter('satpam', this)">Satpam (<?= $counts['satpam'] ?>)</button>
            <button type="button" class="filter-tab" onclick="setRoleFilter('sampah', this)">Sampah (<?= $counts['sampah'] ?>)</button>
            <button type="button" class="filter-tab" onclick="setRoleFilter('super_admin', this)">Super Admin (<?= $counts['admin'] ?>)</button>
          </div>
          <span style="font-size:12px; color:var(--text-tertiary);" id="rowCountLabel">Menampilkan <?= count($allUsers) ?> akun</span>
        </div>

        <div class="history-table-wrapper">
          <table class="apple-table" id="usersTable">
            <thead>
              <tr>
                <th>Pengguna</th>
                <th>Username &amp; Kontak</th>
                <th>Peran (Role)</th>
                <th>Alamat Unit / Jalur</th>
                <th>Status Huni</th>
                <th>Terdaftar</th>
                <th style="text-align:right;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allUsers as $u): ?>
                <?php
                $roleCategory = match ($u['role']) {
                    'warga' => 'warga',
                    'satpam_siang', 'satpam_malam' => 'satpam',
                    'sampah' => 'sampah',
                    'super_admin' => 'super_admin',
                    default => 'all'
                };
                ?>
                <tr data-role="<?= htmlspecialchars($u['role']) ?>" data-category="<?= htmlspecialchars($roleCategory) ?>">
                  <td>
                    <div style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($u['full_name']) ?></div>
                    <div style="font-size:11px; color:var(--text-tertiary);">ID: #<?= $u['id'] ?></div>
                  </td>
                  <td>
                    <div style="font-family:ui-monospace, monospace; font-size:12px; font-weight:600; color:var(--text-primary);">
                      <?= htmlspecialchars($u['username']) ?>
                    </div>
                    <div style="font-size:11.5px; color:var(--text-secondary);"><?= htmlspecialchars($u['phone']) ?></div>
                  </td>
                  <td>
                    <span class="role-badge <?= htmlspecialchars($u['role']) ?>">
                      <?= htmlspecialchars(roleLabel($u['role'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($u['role'] === 'warga' && !empty($u['block'])): ?>
                      <div style="font-weight:600; color:var(--text-primary);">
                        Blok <?= htmlspecialchars($u['block']) ?> / No. <?= htmlspecialchars($u['number']) ?>
                      </div>
                      <div style="font-size:11px; color:var(--text-tertiary);">
                        <?= htmlspecialchars($u['lane'] ?: 'Jalur Utama') ?>
                      </div>
                    <?php elseif ($u['role'] === 'satpam_siang' || $u['role'] === 'satpam_malam'): ?>
                      <div style="color:var(--text-secondary);">Pos Jaga Lingkungan</div>
                    <?php elseif ($u['role'] === 'sampah'): ?>
                      <div style="color:var(--text-secondary);">TPS / Kebersihan RT</div>
                    <?php else: ?>
                      <div style="color:var(--text-secondary);">Kantor Pengurus RT</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($u['role'] === 'warga'): ?>
                      <span class="status-badge <?= $u['status_huni'] === 'tetap' ? 'paid' : 'unpaid' ?>">
                        <?= ucfirst(htmlspecialchars($u['status_huni'] ?: 'tetap')) ?>
                      </span>
                    <?php else: ?>
                      <span style="font-size:11px; color:var(--text-tertiary);">Petugas</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:11.5px; color:var(--text-tertiary);">
                    <?= !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '-' ?>
                  </td>
                  <td style="text-align:right; white-space:nowrap;">
                    <button type="button" class="btn-action-icon" onclick='openEditModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Edit
                    </button>
                    <?php if ($u['id'] !== $user['id']): ?>
                      <button type="button" class="btn-action-icon danger" onclick='openDeleteModal(<?= $u['id'] ?>, "<?= htmlspecialchars($u['full_name']) ?>", "<?= htmlspecialchars($u['username']) ?>")'>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        Hapus
                      </button>
                    <?php else: ?>
                      <span style="font-size:11px; color:var(--text-tertiary); padding:0 8px;">(Akun Anda)</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </main>
</div>

<!-- ==========================================
     MODAL TAMBAH AKUN BARU
     ========================================== -->
<div class="modal-overlay" id="addModalOverlay">
  <div class="modal-card" style="width: 520px;">
    <div class="modal-grabber"></div>
    <div class="modal-header">
      <h4>Tambah Akun Pengguna Baru</h4>
      <button type="button" class="modal-close" onclick="closeAddModal()">&times;</button>
    </div>
    <form method="POST" action="users.php" id="addForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
      <input type="hidden" name="action" value="add">

      <div class="modal-body">
        <!-- Pilihan Role -->
        <div>
          <label style="font-size:11px; font-weight:600; text-transform:uppercase; color:var(--text-tertiary); letter-spacing:0.04em;">Pilih Jenis Akun</label>
          <div class="role-option-group" style="margin-top:6px;">
            <label class="role-radio-btn selected" onclick="onRoleChanged('warga')">
              <input type="radio" name="role" value="warga" checked style="display:none;">
              <span>🏠 Kepala Keluarga (Warga)</span>
            </label>
            <label class="role-radio-btn" onclick="onRoleChanged('satpam_siang')">
              <input type="radio" name="role" value="satpam_siang" style="display:none;">
              <span>☀️ Satpam Jaga Siang</span>
            </label>
            <label class="role-radio-btn" onclick="onRoleChanged('satpam_malam')">
              <input type="radio" name="role" value="satpam_malam" style="display:none;">
              <span>🌙 Satpam Jaga Malam</span>
            </label>
            <label class="role-radio-btn" onclick="onRoleChanged('sampah')">
              <input type="radio" name="role" value="sampah" style="display:none;">
              <span>🗑️ Petugas Sampah</span>
            </label>
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label for="add_full_name">Nama Lengkap</label>
            <input type="text" id="add_full_name" name="full_name" class="form-control" placeholder="Bpk. Rahmat Hidayat" required>
          </div>
          <div class="form-group">
            <label for="add_phone">Nomor HP / WhatsApp</label>
            <input type="text" id="add_phone" name="phone" class="form-control" placeholder="081234567890" required>
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label for="add_username">Username Akun</label>
            <input type="text" id="add_username" name="username" class="form-control" placeholder="Contoh: b3-14 atau satpam3" required>
            <div class="form-hint-text">Digunakan saat login ke sistem</div>
          </div>
          <div class="form-group">
            <label for="add_password">Kata Sandi</label>
            <input type="password" id="add_password" name="password" class="form-control" value="password123" required>
            <div class="form-hint-text">Default: <code>password123</code></div>
          </div>
        </div>

        <!-- Bagian Rumah Khusus Warga -->
        <div id="wargaHouseSection" class="house-subform">
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:var(--text-tertiary); letter-spacing:0.04em;">
            Alamat Unit Rumah yang Diwakili
          </div>

          <div style="display:flex; gap:14px; font-size:12px;">
            <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
              <input type="radio" name="house_choice" value="new" checked onchange="toggleHouseChoice('new')">
              <span>Buat Unit Rumah Baru</span>
            </label>
            <?php if (count($vacantHouses) > 0): ?>
              <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                <input type="radio" name="house_choice" value="existing" onchange="toggleHouseChoice('existing')">
                <span>Pilih Rumah Kosong (<?= count($vacantHouses) ?>)</span>
              </label>
            <?php endif; ?>
          </div>

          <div id="newHouseFields">
            <div class="form-grid-2">
              <div class="form-group">
                <label>Blok Rumah</label>
                <input type="text" name="block" id="add_block" class="form-control" placeholder="B3 atau A1" value="B3">
              </div>
              <div class="form-group">
                <label>Nomor Rumah</label>
                <input type="text" name="number" id="add_number" class="form-control" placeholder="14" value="14">
              </div>
            </div>
            <div class="form-grid-2" style="margin-top:8px;">
              <div class="form-group">
                <label>Jalur / Gang</label>
                <input type="text" name="lane" id="add_lane" class="form-control" placeholder="Jalur Utama Tengah" value="Jalur Utama Tengah">
              </div>
              <div class="form-group">
                <label>Status Huni</label>
                <select name="status_huni" class="form-control">
                  <option value="tetap">Pemilik Tetap</option>
                  <option value="kontrak">Kontrak / Sewa</option>
                </select>
              </div>
            </div>
          </div>

          <?php if (count($vacantHouses) > 0): ?>
            <div id="existingHouseFields" style="display:none;">
              <div class="form-group">
                <label>Pilih Unit Rumah Terdaftar</label>
                <select name="existing_house_id" class="form-control">
                  <?php foreach ($vacantHouses as $vh): ?>
                    <option value="<?= $vh['id'] ?>">Blok <?= htmlspecialchars($vh['block']) ?> No. <?= htmlspecialchars($vh['number']) ?> (<?= htmlspecialchars($vh['lane']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeAddModal()">Batal</button>
        <button type="submit" class="btn-primary">Simpan Akun Baru</button>
      </div>
    </form>
  </div>
</div>

<!-- ==========================================
     MODAL EDIT AKUN
     ========================================== -->
<div class="modal-overlay" id="editModalOverlay">
  <div class="modal-card" style="width: 520px;">
    <div class="modal-grabber"></div>
    <div class="modal-header">
      <h4 id="editModalTitle">Edit Data Akun Pengguna</h4>
      <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <form method="POST" action="users.php" id="editForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="user_id" id="edit_user_id" value="">

      <div class="modal-body">
        <div class="form-grid-2">
          <div class="form-group">
            <label for="edit_full_name">Nama Lengkap</label>
            <input type="text" id="edit_full_name" name="full_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="edit_phone">Nomor HP</label>
            <input type="text" id="edit_phone" name="phone" class="form-control" required>
          </div>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label for="edit_username">Username</label>
            <input type="text" id="edit_username" name="username" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="edit_password">Password Baru</label>
            <input type="password" id="edit_password" name="password" class="form-control" placeholder="Kosongkan jika tidak diganti">
          </div>
        </div>

        <div class="form-group" id="editRoleGroup">
          <label for="edit_role">Peran (Role)</label>
          <select name="role" id="edit_role" class="form-control" onchange="onEditRoleChanged(this.value)">
            <option value="warga">Kepala Keluarga (Warga)</option>
            <option value="satpam_siang">Satpam Jaga Siang</option>
            <option value="satpam_malam">Satpam Jaga Malam</option>
            <option value="sampah">Petugas Sampah</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>

        <!-- Detail Rumah jika Warga -->
        <div id="editHouseSection" class="house-subform">
          <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:var(--text-tertiary); letter-spacing:0.04em;">
            Data Unit Rumah
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label>Blok</label>
              <input type="text" name="block" id="edit_block" class="form-control">
            </div>
            <div class="form-group">
              <label>Nomor Rumah</label>
              <input type="text" name="number" id="edit_number" class="form-control">
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label>Jalur / Gang</label>
              <input type="text" name="lane" id="edit_lane" class="form-control">
            </div>
            <div class="form-group">
              <label>Status Huni</label>
              <select name="status_huni" id="edit_status_huni" class="form-control">
                <option value="tetap">Tetap</option>
                <option value="kontrak">Kontrak</option>
              </select>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeEditModal()">Batal</button>
        <button type="submit" class="btn-primary">Simpan Perubahan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==========================================
     MODAL KONFIRMASI HAPUS
     ========================================== -->
<div class="modal-overlay" id="deleteModalOverlay">
  <div class="modal-card" style="width: 400px;">
    <div class="modal-grabber"></div>
    <div class="modal-header">
      <h4>Konfirmasi Hapus Akun</h4>
      <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
    </div>
    <form method="POST" action="users.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="user_id" id="delete_user_id" value="">

      <div class="modal-body">
        <p style="font-size:13px; line-height:1.5; color:var(--text-primary);">
          Apakah Anda yakin ingin menghapus akun <strong id="delete_user_name">—</strong> (<code id="delete_username">—</code>)?
        </p>
        <div style="font-size:11.5px; color:#d70015; background:#fff2f2; padding:8px 12px; border-radius:var(--radius-sm); border:1px solid rgba(255,59,48,0.2); margin-top:8px;">
          Tindakan ini tidak dapat dibatalkan. Pengguna ini tidak akan bisa login lagi ke sistem SIBAR.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Batal</button>
        <button type="submit" class="btn-primary" style="background:#d70015;">Ya, Hapus Akun</button>
      </div>
    </form>
  </div>
</div>

<!-- APPLE IOS NATIVE BOTTOM TAB BAR -->
<nav class="ios-bottom-tabbar">
  <a href="monitoring.php" class="tabbar-item">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
    <span>Peta Rumah</span>
  </a>
  <a href="users.php" class="tabbar-item active">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    <span>Kelola Akun</span>
  </a>
  <a href="logout.php" class="tabbar-item" style="color:var(--accent-red);">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    <span>Keluar</span>
  </a>
</nav>

<!-- TOAST -->
<div class="toast <?= ($flashSuccess || $flashError) ? 'show' : '' ?>" id="toastBox">
  <?php if ($flashSuccess): ?>
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <span><?= htmlspecialchars($flashSuccess) ?></span>
  <?php elseif ($flashError): ?>
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d70015" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span style="color:#ff6b6b;"><?= htmlspecialchars($flashError) ?></span>
  <?php endif; ?>
</div>

<script>
  let activeRoleCategory = 'all';

  function setRoleFilter(category, btn) {
    activeRoleCategory = category;
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    filterUserTable();
  }

  function filterUserTable() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    const rows = document.querySelectorAll('#usersTable tbody tr');
    let visibleCount = 0;

    rows.forEach(tr => {
      const cat = tr.dataset.category;
      const text = tr.innerText.toLowerCase();
      const matchCat = (activeRoleCategory === 'all' || cat === activeRoleCategory);
      const matchQuery = (!q || text.includes(q));

      if (matchCat && matchQuery) {
        tr.style.display = '';
        visibleCount++;
      } else {
        tr.style.display = 'none';
      }
    });

    document.getElementById('rowCountLabel').innerText = 'Menampilkan ' + visibleCount + ' akun';
  }

  // Add Modal logic
  function openAddModal() {
    document.getElementById('addModalOverlay').classList.add('active');
  }
  function closeAddModal() {
    document.getElementById('addModalOverlay').classList.remove('active');
  }

  function onRoleChanged(role) {
    document.querySelectorAll('.role-radio-btn').forEach(btn => {
      const radio = btn.querySelector('input');
      if (radio.value === role) {
        btn.classList.add('selected');
        radio.checked = true;
      } else {
        btn.classList.remove('selected');
      }
    });

    const houseSec = document.getElementById('wargaHouseSection');
    if (role === 'warga') {
      houseSec.style.display = 'flex';
      document.getElementById('add_block').required = true;
      document.getElementById('add_number').required = true;
    } else {
      houseSec.style.display = 'none';
      document.getElementById('add_block').required = false;
      document.getElementById('add_number').required = false;
    }
  }

  function toggleHouseChoice(choice) {
    const newF = document.getElementById('newHouseFields');
    const extF = document.getElementById('existingHouseFields');
    if (choice === 'new') {
      if (newF) newF.style.display = 'block';
      if (extF) extF.style.display = 'none';
    } else {
      if (newF) newF.style.display = 'none';
      if (extF) extF.style.display = 'block';
    }
  }

  // Edit Modal logic
  function openEditModal(userData) {
    document.getElementById('edit_user_id').value = userData.id;
    document.getElementById('edit_full_name').value = userData.full_name || '';
    document.getElementById('edit_phone').value = userData.phone || '';
    document.getElementById('edit_username').value = userData.username || '';
    document.getElementById('edit_password').value = '';
    document.getElementById('edit_role').value = userData.role;

    document.getElementById('editModalTitle').innerText = 'Edit Akun: ' + (userData.full_name || userData.username);

    // Disable role select if editing superadmin
    if (userData.role === 'super_admin') {
      document.getElementById('edit_role').disabled = true;
    } else {
      document.getElementById('edit_role').disabled = false;
    }

    onEditRoleChanged(userData.role);

    if (userData.role === 'warga') {
      document.getElementById('edit_block').value = userData.block || '';
      document.getElementById('edit_number').value = userData.number || '';
      document.getElementById('edit_lane').value = userData.lane || '';
      document.getElementById('edit_status_huni').value = userData.status_huni || 'tetap';
    }

    document.getElementById('editModalOverlay').classList.add('active');
  }

  function onEditRoleChanged(role) {
    const sec = document.getElementById('editHouseSection');
    if (role === 'warga') {
      sec.style.display = 'flex';
    } else {
      sec.style.display = 'none';
    }
  }

  function closeEditModal() {
    document.getElementById('editModalOverlay').classList.remove('active');
  }

  // Delete Modal logic
  function openDeleteModal(id, name, username) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete_user_name').innerText = name;
    document.getElementById('delete_username').innerText = username;
    document.getElementById('deleteModalOverlay').classList.add('active');
  }
  function closeDeleteModal() {
    document.getElementById('deleteModalOverlay').classList.remove('active');
  }

  // Auto-hide toast
  const toast = document.getElementById('toastBox');
  if (toast.classList.contains('show')) {
    setTimeout(() => { toast.classList.remove('show'); }, 4000);
  }
</script>

</body>
</html>
