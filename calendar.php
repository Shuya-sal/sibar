<?php
// calendar.php — Kalender & Rekap Iuran Tahunan
// Memungkinkan warga dan pengurus memilih bulan atau tahun untuk meninjau riwayat pembayaran 12 bulan

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAuth();
$db = getDB();

$role = $user['role'];
$isWarga = ($role === 'warga');

// Filter parameter aman
$selectedYear = sanitizeInt($_GET['year'] ?? 2026, 2026);
if (!in_array($selectedYear, [2025, 2026], true)) {
    $selectedYear = 2026;
}

$selectedMonth = isset($_GET['month']) && $_GET['month'] !== 'all' ? sanitizeInt($_GET['month'], 0) : 'all';
if ($selectedMonth !== 'all' && ($selectedMonth < 1 || $selectedMonth > 12)) {
    $selectedMonth = 'all';
}

// Penentuan target rumah yang dilihat
// Ambil semua kompleks
$allComplexes = $db->query("SELECT id, code, name, address, latitude, longitude FROM complexes ORDER BY id ASC")->fetchAll();
if (empty($allComplexes)) {
    // Fallback jika tabel complexes belum ada / kosong
    $allComplexes = [['id'=>1, 'code'=>'graha-asri', 'name'=>'Komplek Graha Asri RT 04', 'address'=>'', 'latitude'=>-6.208763, 'longitude'=>106.845599]];
}

// Filter komplek & blok
$selectedComplexId = isset($_GET['complex_id']) ? sanitizeInt($_GET['complex_id'], 1) : 1;
$selectedBlock = isset($_GET['block']) && preg_match('/^[A-Z0-9]+$/', strtoupper($_GET['block'])) ? strtoupper($_GET['block']) : 'all';

// Ambil semua rumah di komplek terpilih
$stmtHousesInCx = $db->prepare("
    SELECT h.id, h.block, h.number, h.lane, h.complex_id, u.full_name, u.username
    FROM houses h
    LEFT JOIN users u ON u.house_id = h.id AND u.role = 'warga'
    WHERE h.complex_id = :cid OR h.complex_id IS NULL
    ORDER BY h.block ASC, CAST(h.number AS UNSIGNED) ASC
");
$stmtHousesInCx->execute([':cid' => $selectedComplexId]);
$allHousesInCx = $stmtHousesInCx->fetchAll();

// Daftar blok unik dari komplek terpilih
$allBlocks = array_values(array_unique(array_column($allHousesInCx, 'block')));
sort($allBlocks);

// Filter per blok
$allHouses = ($selectedBlock === 'all')
    ? $allHousesInCx
    : array_filter($allHousesInCx, fn($h) => $h['block'] === $selectedBlock);
$allHouses = array_values($allHouses);

if ($isWarga) {
    // Warga hanya boleh melihat rumahnya sendiri (Anti-IDOR)
    $targetHouseId = (int)$user['house_id'];
} else {
    $requestedHouseId = isset($_GET['house_id']) ? sanitizeInt($_GET['house_id'], 0) : 0;
    if ($requestedHouseId > 0 && in_array($requestedHouseId, array_column($allHouses, 'id'), false)) {
        $targetHouseId = $requestedHouseId;
    } elseif (!empty($allHouses)) {
        $targetHouseId = (int)$allHouses[0]['id'];
    } else {
        $targetHouseId = 4; // fallback B3-12
    }
}

// Ambil info rumah terpilih
$stmtTargetHouse = $db->prepare("
    SELECT h.id, h.block, h.number, h.lane, h.address, h.status_huni, u.full_name, u.phone, u.username
    FROM houses h
    LEFT JOIN users u ON u.house_id = h.id AND u.role = 'warga'
    WHERE h.id = :id
    LIMIT 1
");
$stmtTargetHouse->execute([':id' => $targetHouseId]);
$targetHouse = $stmtTargetHouse->fetch();

if (!$targetHouse) {
    die("Data unit rumah tidak ditemukan.");
}

// Ambil fee types
$feeTypes = $db->query("SELECT id, code, name, amount, icon FROM fee_types ORDER BY id ASC")->fetchAll();

// Ambil semua tagihan untuk rumah & tahun yang dipilih
$stmtBills = $db->prepare("
    SELECT b.id, b.house_id, b.fee_type_id, b.period_year, b.period_month, b.amount, b.status, b.due_date,
           f.name AS fee_name, f.code AS fee_code, f.icon AS fee_icon,
           p.receipt_no, p.amount_paid, p.payment_method, p.paid_at
    FROM bills b
    JOIN fee_types f ON f.id = b.fee_type_id
    LEFT JOIN payments p ON p.bill_id = b.id
    WHERE b.house_id = :house_id AND b.period_year = :year
    ORDER BY b.period_month ASC, b.fee_type_id ASC
");
$stmtBills->execute([
    ':house_id' => $targetHouseId,
    ':year'     => $selectedYear
]);
$rawBills = $stmtBills->fetchAll();

// Kelompokkan tagihan per bulan (1..12)
$monthBills = [];
for ($m = 1; $m <= 12; $m++) {
    $monthBills[$m] = [
        'bills'       => [],
        'total_due'   => 0,
        'total_paid'  => 0,
        'paid_count'  => 0,
        'unpaid_count'=> 0,
        'status'      => 'empty', // 'paid', 'unpaid', 'partial', 'upcoming', 'empty'
        'latest_paid' => null,
        'receipt_no'  => null,
    ];
}

foreach ($rawBills as $b) {
    $m = (int)$b['period_month'];
    if ($m >= 1 && $m <= 12) {
        $monthBills[$m]['bills'][] = $b;
        $monthBills[$m]['total_due'] += (float)$b['amount'];
        if ($b['status'] === 'paid') {
            $monthBills[$m]['total_paid'] += (float)$b['amount'];
            $monthBills[$m]['paid_count']++;
            if (!empty($b['paid_at'])) {
                $monthBills[$m]['latest_paid'] = $b['paid_at'];
            }
            if (!empty($b['receipt_no'])) {
                $monthBills[$m]['receipt_no'] = $b['receipt_no'];
            }
        } else {
            $monthBills[$m]['unpaid_count']++;
        }
    }
}

// Tentukan status tiap bulan
$currentYearNow = 2026;
$currentMonthNow = 10;

for ($m = 1; $m <= 12; $m++) {
    $item = &$monthBills[$m];
    $count = count($item['bills']);
    if ($count === 0) {
        $item['status'] = 'empty';
    } elseif ($item['unpaid_count'] === 0) {
        $item['status'] = 'paid';
    } elseif ($item['paid_count'] > 0 && $item['unpaid_count'] > 0) {
        $item['status'] = 'partial';
    } else {
        if ($selectedYear === $currentYearNow && $m > $currentMonthNow) {
            $item['status'] = 'upcoming';
        } else {
            $item['status'] = 'unpaid';
        }
    }
}
unset($item);

// Metrik Tahunan
$annualTotalDue = 0;
$annualTotalPaid = 0;
$annualTotalUnpaid = 0;
$annualPaidMonths = 0;

for ($m = 1; $m <= 12; $m++) {
    $annualTotalDue += $monthBills[$m]['total_due'];
    $annualTotalPaid += $monthBills[$m]['total_paid'];
    $annualTotalUnpaid += ($monthBills[$m]['total_due'] - $monthBills[$m]['total_paid']);
    if ($monthBills[$m]['status'] === 'paid') {
        $annualPaidMonths++;
    }
}
$annualCompliance = $annualTotalDue > 0 ? round(($annualTotalPaid / $annualTotalDue) * 100) : 0;

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kalender Iuran Tahunan — SIBAR</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/monitoring.css">
  <style>
    /* Styling Khusus Kalender Tahunan Apple Minimalist */
    .cal-controls-card {
      background: var(--bg-card);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 16px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      box-shadow: var(--shadow-sm);
      margin-bottom: 20px;
    }

    .cal-filter-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .cal-filter-label {
      font-size: 11.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-tertiary);
    }

    .year-pills, .month-pills {
      display: flex;
      gap: 6px;
      background: var(--bg-subtle);
      padding: 3px;
      border-radius: var(--radius-pill);
      border: 1px solid var(--border-subtle);
    }

    .pill-link {
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      border-radius: var(--radius-pill);
      text-decoration: none;
      transition: all 0.15s ease;
      display: inline-block;
      white-space: nowrap;
    }

    .pill-link:hover {
      color: var(--text-primary);
    }

    .pill-link.active {
      background: #ffffff;
      color: var(--text-primary);
      font-weight: 600;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    .house-select {
      height: 38px;
      padding: 0 14px;
      border-radius: 12px;
      border: 1px solid var(--border-subtle);
      background: var(--bg-subtle);
      font-size: 13px;
      color: var(--text-primary);
      outline: none;
      font-family: inherit;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .house-select:focus {
      border-color: var(--accent-blue);
      background: #fff;
      box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.12);
    }

    /* Grid 12 Bulan Apple HIG */
    .kpi-row {
      display: grid !important;
      grid-template-columns: repeat(4, 1fr) !important;
      gap: 14px !important;
      margin-bottom: 24px !important;
    }

    @media (max-width: 1100px) {
      .kpi-row {
        grid-template-columns: repeat(2, 1fr) !important;
      }
    }

    @media (max-width: 860px) {
      .kpi-row {
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
      }
      .kpi-row .kpi-card:last-child {
        grid-column: span 1 !important;
      }
    }

    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }

    @media (max-width: 1100px) {
      .calendar-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    @media (max-width: 768px) {
      .calendar-grid {
        grid-template-columns: 1fr;
        gap: 12px;
      }
      .cal-controls-card {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        padding: 14px;
      }
      .cal-filter-group {
        flex-direction: column;
        align-items: stretch;
      }
      .year-pills, .month-pills {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
    }

    .month-card {
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 16px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      transition: all 0.15s ease;
      position: relative;
    }

    .month-card:hover {
      box-shadow: var(--shadow-hover);
      border-color: rgba(0, 113, 227, 0.25);
      transform: translateY(-2px);
    }

    .month-card.is-current {
      border: 2px solid var(--accent-blue);
      box-shadow: 0 4px 14px rgba(0, 113, 227, 0.12);
    }

    .month-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 8px;
    }

    .month-name {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-primary);
      letter-spacing: -0.01em;
    }

    .month-year-sub {
      font-size: 11px;
      color: var(--text-tertiary);
      margin-top: 2px;
    }

    .month-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      padding: 3px 8px;
      border-radius: var(--radius-pill);
      white-space: nowrap;
    }

    .month-badge.paid {
      background: var(--badge-paid-bg);
      color: var(--badge-paid-text);
      border: 1px solid var(--badge-paid-border);
    }

    .month-badge.unpaid {
      background: var(--badge-unpaid-bg);
      color: var(--badge-unpaid-text);
      border: 1px solid var(--badge-unpaid-border);
    }

    .month-badge.partial {
      background: #fff8ee;
      color: #b25e00;
      border: 1px solid rgba(255, 149, 0, 0.25);
    }

    .month-badge.upcoming {
      background: #f2f2f7;
      color: var(--text-tertiary);
      border: 1px solid var(--border-subtle);
    }

    /* Rincian Item Tagihan di Kartu Bulan */
    .month-fee-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      background: var(--bg-subtle);
      border-radius: var(--radius-sm);
      padding: 10px;
    }

    .month-fee-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 11.5px;
      color: var(--text-secondary);
    }

    .month-fee-label {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .month-fee-status {
      font-weight: 600;
      font-size: 11px;
    }

    .month-fee-status.paid {
      color: #34c759;
    }

    .month-fee-status.unpaid {
      color: #ff9500;
    }

    .month-card-footer {
      border-top: 1px solid var(--border-subtle);
      padding-top: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
    }

    .month-total-wrap {
      display: flex;
      flex-direction: column;
    }

    .month-total-sub {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: var(--text-tertiary);
      font-weight: 600;
    }

    .month-total-val {
      font-size: 13.5px;
      font-weight: 700;
      color: var(--text-primary);
    }

    .btn-receipt-link {
      padding: 5px 11px;
      font-size: 11px;
      font-weight: 600;
      color: var(--accent-blue);
      background: var(--accent-blue-subtle);
      border: 1px solid rgba(0, 113, 227, 0.2);
      border-radius: var(--radius-pill);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.12s ease;
    }

    .btn-receipt-link:hover {
      background: var(--accent-blue);
      color: #fff;
    }

    .btn-pay-month {
      padding: 5px 12px;
      font-size: 11px;
      font-weight: 600;
      color: #fff;
      background: var(--accent-blue);
      border: none;
      border-radius: var(--radius-pill);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.12s ease;
    }

    .btn-pay-month:hover {
      background: var(--accent-blue-hover);
    }
  </style>
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
      <div class="nav-label">Navigasi Utama</div>
      <?php if ($isWarga): ?>
        <a href="dashboard.php" class="nav-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          Dashboard Warga
        </a>
      <?php else: ?>
        <a href="monitoring.php" class="nav-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          Peta Rumah (Monitoring)
        </a>
      <?php endif; ?>

      <a href="calendar.php" class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
        Kalender &amp; Rekap Tahunan
      </a>

      <?php if ($role === 'super_admin'): ?>
        <div class="nav-label">Administrasi</div>
        <a href="users.php" class="nav-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Kelola Akun
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
        <div class="user-name"><?= e($user['full_name']) ?></div>
        <div class="house-tag" style="margin-top:3px;"><span class="role-badge <?= e($role) ?>"><?= e(roleLabel($role)) ?></span></div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-wrapper">
    <header class="top-header">
      <div class="breadcrumb">
        <span>SIBAR</span>
        <span>/</span>
        <span class="current">Kalender &amp; Rekap Tahunan</span>
      </div>
      <div class="header-actions">
        <div class="period-chip">
          <div class="period-dot"></div>
          <span>Tahun: <strong><?= e($selectedYear) ?></strong></span>
        </div>
      </div>
    </header>

    <div class="content-body">

      <!-- WELCOME HERO REKAP TAHUNAN -->
      <section class="household-hero">
        <div class="hero-meta">
          <h2>Kalender Iuran Tahunan <?= e($selectedYear) ?></h2>
          <p>
            Rekapitulasi 12 bulan untuk unit <strong>Blok <?= e($targetHouse['block']) ?> / No. <?= e($targetHouse['number']) ?></strong> 
            (<?= e($targetHouse['full_name'] ?: 'Belum Terdaftar') ?>) — <?= e($targetHouse['lane']) ?>.
          </p>
          <div class="hero-badges">
            <span class="hero-tag">Unit: Blok <?= e($targetHouse['block']) ?>/<?= e($targetHouse['number']) ?></span>
            <span class="hero-tag">Status Huni: <?= ucfirst(e($targetHouse['status_huni'] ?: 'Tetap')) ?></span>
            <span class="hero-tag">Kepatuhan: <?= $annualCompliance ?>%</span>
          </div>
        </div>
        <div class="hero-summary-box">
          <div class="sub">Total Lunas Tahun <?= e($selectedYear) ?></div>
          <div class="amount"><?= $annualPaidMonths ?>/12 <span style="font-size:14px; color:var(--text-tertiary); font-weight:500;">Bulan</span></div>
          <span class="status-badge <?= $annualTotalUnpaid === 0 ? 'paid' : 'unpaid' ?>">
            <?= $annualTotalUnpaid === 0 ? 'Semua bulan lunas' : 'Sisa tertunggak: ' . formatRupiah($annualTotalUnpaid) ?>
          </span>
        </div>
      </section>

      <!-- KONTROL PEMILIHAN TAHUN, KOMPLEK, BLOK, RUMAH, BULAN -->
      <div class="cal-controls-card">
        <!-- Tahun -->
        <div class="cal-filter-group">
          <span class="cal-filter-label">Tahun:</span>
          <div class="year-pills">
            <a href="calendar.php?year=2026&complex_id=<?= $selectedComplexId ?>&block=<?= e($selectedBlock) ?>&house_id=<?= $targetHouseId ?>&month=<?= e($selectedMonth) ?>"
               class="pill-link <?= $selectedYear === 2026 ? 'active' : '' ?>">2026 (Aktif)</a>
            <a href="calendar.php?year=2025&complex_id=<?= $selectedComplexId ?>&block=<?= e($selectedBlock) ?>&house_id=<?= $targetHouseId ?>&month=<?= e($selectedMonth) ?>"
               class="pill-link <?= $selectedYear === 2025 ? 'active' : '' ?>">2025 (Arsip)</a>
          </div>
        </div>

        <?php if (!$isWarga): ?>
          <!-- Komplek -->
          <div class="cal-filter-group">
            <span class="cal-filter-label">Komplek:</span>
            <form method="GET" action="calendar.php" style="display:inline;">
              <input type="hidden" name="year" value="<?= e($selectedYear) ?>">
              <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
              <select name="complex_id" class="house-select" onchange="this.form.submit()">
                <?php foreach ($allComplexes as $cx): ?>
                  <option value="<?= $cx['id'] ?>" <?= $cx['id'] == $selectedComplexId ? 'selected' : '' ?>>
                    <?= e($cx['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <!-- Filter Blok (pill link) -->
          <?php if (!empty($allBlocks)): ?>
            <div class="cal-filter-group" style="flex-wrap:wrap;">
              <span class="cal-filter-label">Blok:</span>
              <div class="year-pills" style="flex-wrap:wrap;">
                <a href="calendar.php?year=<?= $selectedYear ?>&complex_id=<?= $selectedComplexId ?>&block=all&month=<?= e($selectedMonth) ?>"
                   class="pill-link <?= $selectedBlock === 'all' ? 'active' : '' ?>">Semua Blok</a>
                <?php foreach ($allBlocks as $blk): ?>
                  <a href="calendar.php?year=<?= $selectedYear ?>&complex_id=<?= $selectedComplexId ?>&block=<?= urlencode($blk) ?>&month=<?= e($selectedMonth) ?>"
                     class="pill-link <?= $selectedBlock === $blk ? 'active' : '' ?>">Blok <?= e($blk) ?></a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Unit Rumah -->
          <div class="cal-filter-group">
            <span class="cal-filter-label">Unit Rumah:</span>
            <form method="GET" action="calendar.php" style="display:inline;">
              <input type="hidden" name="year" value="<?= e($selectedYear) ?>">
              <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
              <input type="hidden" name="complex_id" value="<?= $selectedComplexId ?>">
              <input type="hidden" name="block" value="<?= e($selectedBlock) ?>">
              <select name="house_id" class="house-select" onchange="this.form.submit()">
                <?php foreach ($allHouses as $h): ?>
                  <option value="<?= $h['id'] ?>" <?= $h['id'] == $targetHouseId ? 'selected' : '' ?>>
                    Blok <?= e($h['block']) ?> / No. <?= e($h['number']) ?> — <?= e($h['full_name'] ?: 'Kosong') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        <?php endif; ?>

        <!-- Tampilan Bulan -->
        <div class="cal-filter-group">
          <span class="cal-filter-label">Tampilan:</span>
          <div class="month-pills">
            <a href="calendar.php?year=<?= $selectedYear ?>&complex_id=<?= $selectedComplexId ?>&block=<?= e($selectedBlock) ?>&house_id=<?= $targetHouseId ?>&month=all"
               class="pill-link <?= $selectedMonth === 'all' ? 'active' : '' ?>">12 Bulan</a>
            <a href="calendar.php?year=<?= $selectedYear ?>&complex_id=<?= $selectedComplexId ?>&block=<?= e($selectedBlock) ?>&house_id=<?= $targetHouseId ?>&month=10"
               class="pill-link <?= $selectedMonth === 10 ? 'active' : '' ?>">Bulan Ini (Okt)</a>
          </div>
        </div>
      </div>

      <!-- KPI METRIK TAHUNAN -->
      <section class="kpi-row" style="margin-bottom:20px;">
        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Total Kewajiban (<?= e($selectedYear) ?>)</span>
            <div class="kpi-icon-wrap">📋</div>
          </div>
          <div class="kpi-value"><?= formatRupiah($annualTotalDue) ?></div>
          <div class="kpi-sub">12 bulan x 3 pos iuran wajib</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Sudah Dilunasi</span>
            <div class="kpi-icon-wrap" style="color:#34c759;">✓</div>
          </div>
          <div class="kpi-value" style="color:#34c759;"><?= formatRupiah($annualTotalPaid) ?></div>
          <div class="kpi-sub"><?= $annualPaidMonths ?> dari 12 bulan lunas</div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Sisa Tertunggak</span>
            <div class="kpi-icon-wrap" style="color:#ff9500;">⏳</div>
          </div>
          <div class="kpi-value" style="color:<?= $annualTotalUnpaid > 0 ? '#ff9500' : 'var(--text-primary)' ?>;">
            <?= formatRupiah($annualTotalUnpaid) ?>
          </div>
          <div class="kpi-sub"><?= $annualTotalUnpaid > 0 ? 'Perlu penyelesaian' : 'Tidak ada tunggakan' ?></div>
        </div>

        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Tingkat Kepatuhan</span>
            <div class="kpi-icon-wrap">📊</div>
          </div>
          <div class="kpi-value"><?= $annualCompliance ?>%</div>
          <div class="kpi-sub">
            <div style="height:5px; background:#e5e5ea; border-radius:10px; margin-top:4px; overflow:hidden;">
              <div style="height:100%; width:<?= $annualCompliance ?>%; background:var(--accent-blue); border-radius:10px;"></div>
            </div>
          </div>
        </div>
      </section>

      <!-- GRID KALENDER 12 BULAN -->
      <div class="calendar-grid">
        <?php
        $monthsToDisplay = ($selectedMonth === 'all') ? range(1, 12) : [(int)$selectedMonth];
        foreach ($monthsToDisplay as $m):
            $mInfo = $monthBills[$m];
            $isCur = ($selectedYear === $currentYearNow && $m === $currentMonthNow);
            $status = $mInfo['status'];
            $statusLabel = match($status) {
                'paid'     => 'Lunas',
                'unpaid'   => 'Belum Lunas',
                'partial'  => 'Sebagian',
                'upcoming' => 'Mendatang',
                default    => 'Belum Terbit'
            };
        ?>
          <div class="month-card <?= $isCur ? 'is-current' : '' ?>">
            <div>
              <div class="month-card-header">
                <div>
                  <div class="month-name"><?= $monthNames[$m] ?></div>
                  <div class="month-year-sub"><?= e($selectedYear) ?> <?= $isCur ? '· <em>Bulan Berjalan</em>' : '' ?></div>
                </div>
                <span class="month-badge <?= e($status) ?>"><?= e($statusLabel) ?></span>
              </div>

              <!-- Rincian 3 Pos Iuran -->
              <div class="month-fee-list" style="margin-top:12px;">
                <?php if (empty($mInfo['bills'])): ?>
                  <div style="font-size:11.5px; color:var(--text-tertiary); text-align:center; padding:8px 0;">
                    Belum ada data tagihan
                  </div>
                <?php else: ?>
                  <?php foreach ($mInfo['bills'] as $b): ?>
                    <div class="month-fee-row">
                      <span class="month-fee-label">
                        <span><?= e($b['fee_icon']) ?></span>
                        <span><?= e($b['fee_name']) ?></span>
                      </span>
                      <span class="month-fee-status <?= $b['status'] === 'paid' ? 'paid' : 'unpaid' ?>">
                        <?= $b['status'] === 'paid' ? '✓ Lunas' : formatRupiah($b['amount']) ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Footer Kartu Bulan -->
            <div class="month-card-footer">
              <div class="month-total-wrap">
                <span class="month-total-sub">Total Iuran</span>
                <span class="month-total-val"><?= formatRupiah($mInfo['total_due']) ?></span>
              </div>

              <div>
                <?php if ($mInfo['status'] === 'paid' && !empty($mInfo['receipt_no'])): ?>
                  <a href="receipt.php?receipt_no=<?= urlencode($mInfo['receipt_no']) ?>" class="btn-receipt-link" target="_blank" title="Cetak Kwitansi">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    Kwitansi
                  </a>
                <?php elseif ($isWarga && in_array($mInfo['status'], ['unpaid', 'partial'], true)): ?>
                  <a href="dashboard.php" class="btn-pay-month" title="Buka Dashboard untuk Bayar">
                    Bayar
                  </a>
                <?php else: ?>
                  <span style="font-size:11px; color:var(--text-tertiary);">
                    <?= $mInfo['latest_paid'] ? date('d/m/y', strtotime($mInfo['latest_paid'])) : '—' ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    </div>
  </main>
</div>

<!-- APPLE IOS NATIVE BOTTOM TAB BAR -->
<nav class="ios-bottom-tabbar">
  <?php if ($isWarga): ?>
    <a href="dashboard.php" class="tabbar-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
      <span>Dashboard</span>
    </a>
  <?php else: ?>
    <a href="monitoring.php" class="tabbar-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
      <span>Peta Rumah</span>
    </a>
  <?php endif; ?>
  
  <a href="calendar.php" class="tabbar-item active">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
    <span>Kalender</span>
  </a>

  <?php if ($role === 'super_admin'): ?>
    <a href="users.php" class="tabbar-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      <span>Kelola Akun</span>
    </a>
  <?php endif; ?>

  <a href="logout.php" class="tabbar-item" style="color:var(--accent-red);">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    <span>Keluar</span>
  </a>
</nav>

</body>
</html>
