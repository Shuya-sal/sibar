<?php
// monitoring.php — Peta Rumah per Blok & Jalur untuk Petugas / Super Admin
// Satpam Siang: jaga_siang | Satpam Malam: jaga_malam | Sampah: sampah | Super Admin: semua
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAnyRole(['satpam_siang', 'satpam_malam', 'sampah', 'super_admin']);
$db = getDB();

$role = $user['role'];
$feeCodes = allowedFeeCodes($role); // null = semua
$periodLabelStr = periodLabel(10, 2026);
$year = 2026;
$month = 10;

// --- Ambil semua rumah dikelompokkan blok & jalur ---
$houses = $db->query("SELECT id, block, number, lane, status_huni FROM houses ORDER BY block ASC, CAST(number AS UNSIGNED) ASC")->fetchAll();

// --- Status iuran Oktober 2026 per rumah (dibatasi sesuai role) ---
$sqlBills = "
    SELECT house_id,
           SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
           SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
           COUNT(*) AS total_count
    FROM bills b
    JOIN fee_types f ON f.id = b.fee_type_id
    WHERE b.period_year = :year AND b.period_month = :month
";
$params = [':year' => $year, ':month' => $month];
if ($feeCodes !== null) {
    $placeholders = [];
    foreach ($feeCodes as $i => $code) {
        $key = ":fc{$i}";
        $placeholders[] = $key;
        $params[$key] = $code;
    }
    $sqlBills .= " AND f.code IN (" . implode(',', $placeholders) . ")";
}
$sqlBills .= " GROUP BY house_id";

$stmtBills = $db->prepare($sqlBills);
$stmtBills->execute($params);
$billStats = [];
foreach ($stmtBills->fetchAll() as $row) {
    $billStats[(int)$row['house_id']] = [
        'paid' => (int)$row['paid_count'],
        'unpaid' => (int)$row['unpaid_count'],
        'total' => (int)$row['total_count'],
    ];
}

// --- Kepala keluarga per rumah ---
$stmtKK = $db->query("SELECT house_id, full_name, phone, username FROM users WHERE role = 'warga' AND house_id IS NOT NULL");
$kkByHouse = [];
foreach ($stmtKK->fetchAll() as $row) {
    $kkByHouse[(int)$row['house_id']] = $row;
}

// --- Ringkasan global ---
$totalHouses = count($houses);
$lunas = 0;
$belum = 0;
$noUser = 0;
foreach ($houses as $h) {
    $hid = (int)$h['id'];
    $hasKK = isset($kkByHouse[$hid]);
    if (!$hasKK) { $noUser++; continue; }
    $stat = $billStats[$hid] ?? ['paid' => 0, 'unpaid' => 0, 'total' => 0];
    if ($stat['total'] === 0) { continue; }
    if ($stat['unpaid'] === 0) { $lunas++; } else { $belum++; }
}

// Scope label untuk header
$scopeLabel = match ($role) {
    'satpam_siang' => 'Iuran Jaga Siang',
    'satpam_malam' => 'Iuran Jaga Malam',
    'sampah'       => 'Iuran Sampah & Kebersihan',
    'super_admin'  => 'Semua Pos Iuran',
    default        => 'Monitoring',
};

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Peta Rumah — SIBAR Monitoring</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/monitoring.css">
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR -->
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
      <a href="monitoring.php" class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
        Peta Rumah (Blok & Jalur)
      </a>

      <div class="nav-label">Pos yang Dipantau</div>
      <?php if ($role === 'super_admin' || $role === 'satpam_malam'): ?>
      <a href="#" class="nav-item" onclick="alert('Rincian ronda malam 22:00 - 05:00. Petugas: Bpk. Slamet Riyadi.')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        Jaga Malam
      </a>
      <?php endif; ?>
      <?php if ($role === 'super_admin' || $role === 'satpam_siang'): ?>
      <a href="#" class="nav-item" onclick="alert('Rincian jaga siang 06:00 - 18:00. Petugas: Bpk. Tono Wibowo.')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/></svg>
        Jaga Siang
      </a>
      <?php endif; ?>
      <?php if ($role === 'super_admin' || $role === 'sampah'): ?>
      <a href="#" class="nav-item" onclick="alert('Jadwal angkut: Senin, Rabu, Jumat pukul 07:30 WIB.')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
        Sampah & Kebersihan
      </a>
      <?php endif; ?>

      <?php if ($role === 'super_admin'): ?>
      <div class="nav-label">Administrasi</div>
      <a href="users.php" class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Kelola Akun (KK, Satpam, Sampah)
      </a>
      <?php endif; ?>

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
        <div class="house-tag" style="margin-top:3px;"><span class="role-badge <?= htmlspecialchars($role) ?>"><?= htmlspecialchars(roleLabel($role)) ?></span></div>
      </div>
    </div>
  </aside>

  <!-- MAIN VIEW -->
  <main class="main-wrapper">
    <header class="top-header">
      <div class="breadcrumb">
        <span>SIBAR</span>
        <span>/</span>
        <span class="current">Peta Rumah — <?= htmlspecialchars($scopeLabel) ?></span>
      </div>
      <div class="header-actions">
        <div class="period-chip">
          <div class="period-dot"></div>
          <span>Periode: <strong><?= $periodLabelStr ?></strong></span>
        </div>
        <?php if ($role === 'super_admin'): ?>
        <a href="users.php" class="btn-primary" style="text-decoration:none;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Kelola Akun
        </a>
        <?php endif; ?>
      </div>
    </header>

    <div class="content-body">

      <!-- HERO RINGKASAN -->
      <section class="household-hero">
        <div class="hero-meta">
          <h2>Peta Status Pembayaran Warga</h2>
          <p>Anda masuk sebagai <strong><?= htmlspecialchars(roleLabel($role)) ?></strong>. Klik nomor rumah untuk melihat identitas kepala keluarga dan rincian status pembayaran <?= htmlspecialchars(strtolower($scopeLabel)) ?> bulan <?= $periodLabelStr ?>.</p>
          <div class="hero-badges">
            <span class="hero-tag">Lingkup: <?= htmlspecialchars($scopeLabel) ?></span>
            <span class="hero-tag">Total Rumah: <?= $totalHouses ?></span>
            <?php if ($noUser > 0): ?>
              <span class="hero-tag"><?= $noUser ?> rumah belum terdaftar KK</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="hero-summary-box">
          <div class="sub">Rekapitulasi Rumah</div>
          <div class="amount"><?= $lunas ?> <span style="font-size:14px; color:var(--text-tertiary); font-weight:500;">Lunas</span> · <?= $belum ?> <span style="font-size:14px; color:var(--text-tertiary); font-weight:500;">Belum</span></div>
          <span class="status-badge <?= $belum > 0 ? 'unpaid' : 'paid' ?>"><?= $belum > 0 ? $belum . ' rumah perlu ditagih' : 'Semua rumah lunas' ?></span>
        </div>
      </section>

      <!-- PETA RUMAH PER BLOK & JALUR -->
      <section class="section-container">
        <div class="section-header">
          <div class="section-title-wrap">
            <h3>Peta Rumah per Blok &amp; Jalur</h3>
            <p>Klik nomor rumah untuk memuat detail kepala keluarga &amp; status pembayaran.</p>
          </div>
          <div class="map-legend">
            <span class="legend-item"><span class="legend-dot lunas"></span> Semua Lunas</span>
            <span class="legend-item"><span class="legend-dot belum"></span> Ada Belum Bayar</span>
            <span class="legend-item"><span class="legend-dot kosong"></span> Belum Ada KK</span>
          </div>
        </div>

        <!-- PENCARIAN PETA RUMAH -->
        <div class="map-search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="text" id="mapSearchInput" placeholder="Cari nama KK, nomor rumah, blok, atau jalur..." oninput="filterMapHouses()" autocomplete="off">
          <button type="button" class="map-search-clear" id="mapSearchClear" onclick="clearMapSearch()" style="display:none;">&times;</button>
        </div>
        <div class="map-search-empty" id="mapSearchEmpty" style="display:none;">
          <div style="font-size:26px;">🔍</div>
          <p><strong>Tidak ada rumah yang cocok.</strong></p>
          <p style="font-size:12px;">Coba kata kunci lain — misalnya nama kepala keluarga, nomor rumah (mis. <em>12</em>), blok (mis. <em>B3</em>), atau jalur.</p>
        </div>

        <div class="map-blocks" id="mapBlocks">
          <?php
          // Kelompokkan rumah berdasarkan blok, lalu jalur
          $blocks = [];
          foreach ($houses as $h) {
              $blocks[$h['block']][$h['lane']][] = $h;
          }
          ksort($blocks);
          ?>
          <?php foreach ($blocks as $blockName => $lanes): ?>
            <?php
            // Statistik blok
            $bTotal = 0; $bPaid = 0;
            foreach ($lanes as $laneHouses) {
                foreach ($laneHouses as $h) {
                    $hid = (int)$h['id'];
                    if (!isset($kkByHouse[$hid])) continue;
                    $stat = $billStats[$hid] ?? ['paid' => 0, 'unpaid' => 0, 'total' => 0];
                    if ($stat['total'] === 0) continue;
                    $bTotal++;
                    if ($stat['unpaid'] === 0) $bPaid++;
                }
            }
            $bPct = $bTotal > 0 ? round(($bPaid / $bTotal) * 100) : 0;
            ?>
            <div class="block-panel">
              <div class="block-panel-header">
                <div class="block-title">
                  <h4>Blok <?= htmlspecialchars($blockName) ?></h4>
                  <?php
                  $laneNames = array_keys($lanes);
                  if (count($laneNames) === 1) {
                      echo '<span class="lane">' . htmlspecialchars($laneNames[0]) . '</span>';
                  } else {
                      echo '<span class="lane">' . count($laneNames) . ' jalur</span>';
                  }
                  ?>
                </div>
                <div class="block-summary">
                  <div class="block-progress-track">
                    <div class="block-progress-fill" style="width: <?= $bPct ?>%;"></div>
                  </div>
                  <span class="block-progress-text"><?= $bPaid ?>/<?= $bTotal ?> lunas (<?= $bPct ?>%)</span>
                </div>
              </div>

              <?php foreach ($lanes as $laneName => $laneHouses): ?>
                <div class="lane-row">
                  <?php if (count($lanes) > 1): ?>
                    <div class="lane-label"><?= htmlspecialchars($laneName) ?></div>
                  <?php endif; ?>
                  <div class="house-chips">
                    <?php foreach ($laneHouses as $h): ?>
                      <?php
                      $hid = (int)$h['id'];
                      $hasKK = isset($kkByHouse[$hid]);
                      $stat = $billStats[$hid] ?? ['paid' => 0, 'unpaid' => 0, 'total' => 0];
                      $isAllPaid = $stat['total'] > 0 && $stat['unpaid'] === 0;
                      ?>
                      <?php if (!$hasKK): ?>
                        <span class="house-chip no-user" title="Belum ada akun KK terdaftar"
                              data-search="<?= htmlspecialchars(strtolower('blok ' . $blockName . ' ' . $laneName . ' no ' . $h['number'] . ' kosong')) ?>">
                          <span class="chip-icon">🏠</span>
                          <span>No. <?= htmlspecialchars($h['number']) ?></span>
                          <span class="chip-status" style="background:#e5e5ea; color:var(--text-tertiary);">Kosong</span>
                        </span>
                      <?php else: ?>
                        <button type="button" class="house-chip <?= $isAllPaid ? 'all-paid' : 'has-unpaid' ?>"
                                data-search="<?= htmlspecialchars(strtolower('blok ' . $blockName . ' ' . $laneName . ' no ' . $h['number'] . ' ' . $kkByHouse[$hid]['full_name'] . ' ' . $kkByHouse[$hid]['username'])) ?>"
                                onclick='openHouseDetail(<?= json_encode([
                                    'house' => $h,
                                    'kk' => $kkByHouse[$hid],
                                    'stat' => $stat,
                                    'period' => $periodLabelStr,
                                    'scope' => $scopeLabel,
                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                          <span class="chip-icon"><?= $isAllPaid ? '🏡' : '🏚️' ?></span>
                          <span class="chip-main">
                            <span class="chip-no">No. <?= htmlspecialchars($h['number']) ?></span>
                            <span class="chip-name"><?= htmlspecialchars($kkByHouse[$hid]['full_name']) ?></span>
                          </span>
                          <span class="chip-status"><?= $isAllPaid ? 'Lunas' : ($stat['unpaid'] . ' Belum') ?></span>
                        </button>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </div>
  </main>
</div>

<!-- DRAWER DETAIL RUMAH -->
<div class="house-detail-overlay" id="houseDetailOverlay">
  <div class="house-detail-panel">
    <div class="modal-grabber"></div>
    <div class="house-detail-header">
      <div>
        <h4 id="detailTitle">Detail Rumah</h4>
        <div class="house-sub" id="detailSubtitle">—</div>
      </div>
      <button type="button" class="modal-close" onclick="closeHouseDetail()">&times;</button>
    </div>
    <div class="house-detail-body" id="detailBody">
      <!-- Diisi via JS -->
    </div>
  </div>
</div>

<!-- APPLE IOS NATIVE BOTTOM TAB BAR -->
<nav class="ios-bottom-tabbar">
  <a href="monitoring.php" class="tabbar-item active">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
    <span>Peta Rumah</span>
  </a>
  <?php if ($role === 'super_admin'): ?>
  <a href="users.php" class="tabbar-item">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    <span>Kelola Akun</span>
  </a>
  <?php endif; ?>
  <a href="logout.php" class="tabbar-item" style="color:var(--accent-red);">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    <span>Keluar</span>
  </a>
</nav>

<!-- TOAST -->
<div class="toast <?= $flashSuccess ? 'show' : '' ?>" id="toastBox">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="toastText"><?= htmlspecialchars($flashSuccess ?? 'Operasi berhasil!') ?></span>
</div>

<script>
  // ===== PENCARIAN PETA RUMAH =====
  function filterMapHouses() {
    const q = (document.getElementById('mapSearchInput').value || '').toLowerCase().trim();
    const clearBtn = document.getElementById('mapSearchClear');
    const emptyBox = document.getElementById('mapSearchEmpty');
    clearBtn.style.display = q ? 'flex' : 'none';

    let totalVisible = 0;

    document.querySelectorAll('#mapBlocks .block-panel').forEach(panel => {
      let panelVisible = 0;

      panel.querySelectorAll('.lane-row').forEach(laneRow => {
        let laneVisible = 0;

        laneRow.querySelectorAll('.house-chip').forEach(chip => {
          const text = chip.dataset.search || chip.innerText.toLowerCase();
          const match = !q || text.includes(q);
          chip.style.display = match ? '' : 'none';
          if (match) { laneVisible++; totalVisible++; }
        });

        laneRow.style.display = laneVisible > 0 ? '' : 'none';
        panelVisible += laneVisible;
      });

      panel.style.display = panelVisible > 0 ? '' : 'none';
    });

    emptyBox.style.display = (q && totalVisible === 0) ? 'block' : 'none';
  }

  function clearMapSearch() {
    document.getElementById('mapSearchInput').value = '';
    filterMapHouses();
    document.getElementById('mapSearchInput').focus();
  }

  const esc = (s) => {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
  };

  function openHouseDetail(payload) {
    const { house, kk, stat, period, scope } = payload;
    const overlay = document.getElementById('houseDetailOverlay');
    const title = document.getElementById('detailTitle');
    const subtitle = document.getElementById('detailSubtitle');
    const body = document.getElementById('detailBody');

    title.innerHTML = 'Rumah Blok ' + esc(house.block) + ' No. ' + esc(house.number);
    subtitle.textContent = (house.lane || 'Jalur Utama') + ' • Periode ' + period;

    const totalBills = stat.total || 0;
    const paidCount = stat.paid || 0;
    const unpaidCount = stat.unpaid || 0;
    const statusPill = totalBills === 0
      ? '<span class="status-badge" style="background:#e5e5ea; color:var(--text-tertiary);">Belum Ada Tagihan</span>'
      : (unpaidCount === 0
          ? '<span class="status-badge paid">● Lunas Semua</span>'
          : '<span class="status-badge unpaid">' + unpaidCount + ' Pos Belum Bayar</span>');

    body.innerHTML = `
      <div>
        <div class="detail-section-title">Identitas Kepala Keluarga</div>
        <div class="detail-kv-grid">
          <div class="detail-kv"><div class="label">Nama KK</div><div class="val">${esc(kk.full_name)}</div></div>
          <div class="detail-kv"><div class="label">No. HP</div><div class="val">${esc(kk.phone)}</div></div>
          <div class="detail-kv"><div class="label">Akun</div><div class="val">${esc(kk.username)}</div></div>
          <div class="detail-kv"><div class="label">Status Huni</div><div class="val">${esc((house.status_huni || 'tetap').charAt(0).toUpperCase() + (house.status_huni || 'tetap').slice(1))}</div></div>
        </div>
      </div>

      <div>
        <div class="detail-section-title">Status Pembayaran — ${esc(scope)}</div>
        <div style="margin-bottom:10px;">${statusPill}</div>
        <table class="detail-bill-table">
          <thead>
            <tr>
              <th>Ringkasan</th>
              <th>Jumlah Pos</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Total tagihan ${esc(period)}</td><td class="amount">${totalBills}</td></tr>
            <tr><td>Sudah lunas</td><td class="amount">${paidCount}</td></tr>
            <tr><td>Belum dibayar</td><td class="amount" style="color:${unpaidCount > 0 ? 'var(--badge-unpaid-text)' : 'var(--badge-paid-text)'};">${unpaidCount}</td></tr>
          </tbody>
        </table>
      </div>

      <div style="background:var(--bg-subtle); border:1px solid var(--border-subtle); border-radius:var(--radius-sm); padding:12px 14px; font-size:11.5px; color:var(--text-secondary); line-height:1.5;">
        Petugas dapat mencatat pembayaran tunai langsung di lapangan. Konfirmasi pelunasan resmi tetap dicatat melalui akun warga atau oleh Super Admin.
      </div>
    `;

    overlay.classList.add('active');
  }

  function closeHouseDetail() {
    document.getElementById('houseDetailOverlay').classList.remove('active');
  }

  // Klik luar menutup drawer
  document.getElementById('houseDetailOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeHouseDetail();
  });

  const toast = document.getElementById('toastBox');
  if (toast.classList.contains('show')) {
    setTimeout(() => toast.classList.remove('show'), 4000);
  }
</script>

</body>
</html>
