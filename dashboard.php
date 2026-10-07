<?php
// dashboard.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAuth();
$db = getDB();

// Role warga tetap di dashboard warga; petugas & super admin diarahkan ke peta monitoring
if (canAccessMonitoring($user)) {
    header('Location: monitoring.php');
    exit;
}

$currentYear = 2026;
$currentMonth = 10;
$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$periodLabel = ($monthNames[$currentMonth] ?? 'Oktober') . ' ' . $currentYear;

// 1. Ambil Tagihan Belum Dibayar Bulan Berjalan (Bagian Tengah)
$stmtUnpaid = $db->prepare("
    SELECT b.id AS bill_id, b.amount, b.due_date, b.status,
           f.id AS fee_type_id, f.code, f.name AS fee_name, f.icon, f.description
    FROM bills b
    JOIN fee_types f ON f.id = b.fee_type_id
    WHERE b.house_id = :house_id
      AND b.period_year = :year
      AND b.period_month = :month
      AND b.status = 'unpaid'
    ORDER BY f.id ASC
");
$stmtUnpaid->execute([
    ':house_id' => $user['house_id'],
    ':year' => $currentYear,
    ':month' => $currentMonth
]);
$unpaidBills = $stmtUnpaid->fetchAll();

$totalUnpaid = 0;
foreach ($unpaidBills as $item) {
    $totalUnpaid += (float)$item['amount'];
}
$unpaidCount = count($unpaidBills);

// 2. Ambil Riwayat Pembayaran Lunas (Bagian Bawah)
$stmtHistory = $db->prepare("
    SELECT p.id AS payment_id, p.receipt_no, p.amount_paid, p.payment_method, p.paid_at,
           b.period_month, b.period_year,
           f.name AS fee_name, f.code
    FROM payments p
    JOIN bills b ON b.id = p.bill_id
    JOIN fee_types f ON f.id = b.fee_type_id
    WHERE b.house_id = :house_id
    ORDER BY p.paid_at DESC
");
$stmtHistory->execute([':house_id' => $user['house_id']]);
$historyRows = $stmtHistory->fetchAll();

// Hitung metrik kepatuhan
$stmtPaidCount = $db->prepare("
    SELECT COUNT(DISTINCT b.period_month)
    FROM bills b
    WHERE b.house_id = :house_id AND b.period_year = :year AND b.status = 'paid'
");
$stmtPaidCount->execute([':house_id' => $user['house_id'], ':year' => $currentYear]);
$monthsPaidCount = (int)$stmtPaidCount->fetchColumn();
$compliancePct = round(($monthsPaidCount / max(1, $currentMonth)) * 100);

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — SIBAR Warga</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR (Layout Acuan Gambar dengan Apple Minimalist Style) -->
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
      <a href="dashboard.php" class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        Dashboard
      </a>
      <a href="#unpaidSection" class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        Tagihan Saya
        <?php if ($unpaidCount > 0): ?>
          <span class="nav-badge"><?= $unpaidCount ?> Tagihan Menunggu</span>
        <?php else: ?>
          <span class="nav-badge" style="background:var(--badge-paid-bg); color:var(--badge-paid-text); border-color:var(--badge-paid-border);">Lunas</span>
        <?php endif; ?>
      </a>
      <a href="#historySection" class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg>
        Riwayat Pembayaran
      </a>

      <div class="nav-label">Lingkungan</div>
      <a href="#" class="nav-item" onclick="alert('Jadwal Keamanan: Siang (06:00-18:00), Malam (22:00-05:00)')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Jadwal Jaga Malam & Siang
      </a>
      <a href="#" class="nav-item" onclick="alert('Pengangkutan Sampah: Setiap Senin, Rabu, Jumat pukul 07:30 WIB')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
        Pengangkutan Sampah
      </a>

      <div class="nav-label">Akun Rumah</div>
      <a href="#" class="nav-item" onclick="alert('Unit: Blok <?= htmlspecialchars($user['block']) ?> No. <?= htmlspecialchars($user['number']) ?> - Penghuni: <?= htmlspecialchars($user['full_name']) ?>')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Data Anggota Rumah
      </a>
      <a href="logout.php" class="nav-item" style="color:#d70015;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Keluar (Logout)
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="avatar"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="house-tag">Rumah Blok <?= htmlspecialchars($user['block']) ?> / No. <?= htmlspecialchars($user['number']) ?></div>
      </div>
    </div>
  </aside>

  <!-- MAIN VIEW -->
  <main class="main-wrapper">
    <!-- TOP HEADER -->
    <header class="top-header">
      <div class="breadcrumb">
        <span>SIBAR</span>
        <span>/</span>
        <span class="current">Dashboard Warga</span>
      </div>

      <div class="header-actions">
        <div class="period-chip">
          <div class="period-dot <?= $unpaidCount === 0 ? 'all-paid' : '' ?>"></div>
          <span>Bulan Berjalan: <strong><?= $periodLabel ?></strong></span>
        </div>
        <?php if ($unpaidCount > 0): ?>
          <button class="btn-primary" onclick="openPaymentModal('all', <?= $totalUnpaid ?>, 'Semua Tagihan (Jaga Malam, Jaga Siang, Sampah)')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Bayar Sekaligus
          </button>
        <?php endif; ?>
      </div>
    </header>

    <!-- CONTENT BODY -->
    <div class="content-body">

      <!-- WELCOME HERO BANNER (Minimalist Apple Tone) -->
      <section class="household-hero">
        <div class="hero-meta">
          <h2>Selamat datang, <?= htmlspecialchars($user['full_name']) ?></h2>
          <p>Akun resmi unit rumah <strong>Blok <?= htmlspecialchars($user['block']) ?> No. <?= htmlspecialchars($user['number']) ?></strong>. Pantau kewajiban iuran pos jaga malam, pos jaga siang, dan kebersihan sampah bulan ini langsung dari dashboard.</p>
          <div class="hero-badges">
            <span class="hero-tag">Status Huni: <?= ucfirst(htmlspecialchars($user['status_huni'])) ?></span>
            <span class="hero-tag">Wilayah: RT 04 / RW 08</span>
            <span class="hero-tag">Klaster: Cempaka Indah</span>
          </div>
        </div>
        <div class="hero-summary-box">
          <div class="sub">Total Belum Dibayar</div>
          <div class="amount" id="heroTotalAmount"><?= formatRupiah($totalUnpaid) ?></div>
          <?php if ($unpaidCount > 0): ?>
            <span class="status-badge unpaid" id="heroStatusPill"><?= $unpaidCount ?> Tagihan Menunggu</span>
          <?php else: ?>
            <span class="status-badge paid" id="heroStatusPill">● Semua Tagihan Lunas</span>
          <?php endif; ?>
        </div>
      </section>

      <!-- BARIS ATAS: 3 KPI RINGKASAN PERSENTASE & STATUS (Mengadaptasi Layout Gambar) -->
      <section class="kpi-row">
        <!-- KPI 1 -->
        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Status Iuran Berjalan</span>
            <div class="kpi-icon-wrap">📊</div>
          </div>
          <div class="kpi-value">
            <?= $unpaidCount > 0 ? $unpaidCount . ' Pos Menunggu' : 'Lunas' ?>
          </div>
          <div class="kpi-footer">
            <?php if ($unpaidCount > 0): ?>
              <span class="status-badge unpaid">Jatuh Tempo 15 <?= substr($monthNames[$currentMonth], 0, 3) ?></span>
              <span>Bulan <?= $periodLabel ?></span>
            <?php else: ?>
              <span class="status-badge paid">● Lunas Penuh</span>
              <span>Bulan <?= $periodLabel ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- KPI 2 -->
        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Kepatuhan Iuran <?= $currentYear ?></span>
            <div class="kpi-icon-wrap">🎯</div>
          </div>
          <div class="kpi-value"><?= $compliancePct ?>%</div>
          <div class="kpi-footer">
            <span class="status-badge paid">Tertib Bayar</span>
            <span><?= $monthsPaidCount ?> dari <?= $currentMonth ?> Bulan Terlunasi</span>
          </div>
        </div>

        <!-- KPI 3 -->
        <div class="kpi-card">
          <div class="kpi-header">
            <span class="kpi-title">Status Layanan Lingkungan</span>
            <div class="kpi-icon-wrap">🛡️</div>
          </div>
          <div class="kpi-value">Aktif Normal</div>
          <div class="kpi-footer">
            <span class="status-badge paid">Aman & Bersih</span>
            <span>Ronda Malam, Siang & Sampah</span>
          </div>
        </div>
      </section>

      <!-- BAGIAN TENGAH (SESUAI REQUEST): APA SAJA YANG BELUM DIBAYAR DI BULAN BERJALAN -->
      <section class="section-container" id="unpaidSection">
        <div class="section-header">
          <div class="section-title-wrap">
            <h3>Tagihan Belum Dibayar — Bulan Berjalan (<?= $periodLabel ?>)</h3>
            <p>Daftar kewajiban iuran wajib rumah Anda yang belum terlunasi untuk periode bulan berjalan.</p>
          </div>
          <?php if ($unpaidCount > 0): ?>
            <span class="status-badge unpaid"><?= $unpaidCount ?> Tagihan Menunggu</span>
          <?php else: ?>
            <span class="status-badge paid">● Tidak Ada Tagihan Menunggu</span>
          <?php endif; ?>
        </div>

        <?php if ($unpaidCount > 0): ?>
          <div class="unpaid-grid">
            <?php foreach ($unpaidBills as $b): ?>
              <div class="fee-card">
                <div class="fee-card-top">
                  <div class="fee-type-badge">
                    <div class="fee-icon-circle"><?= htmlspecialchars($b['icon'] ?: '💳') ?></div>
                    <span><?= htmlspecialchars($b['fee_name']) ?></span>
                  </div>
                  <span class="status-badge unpaid">Belum Bayar</span>
                </div>
                <div class="fee-amount-block">
                  <div class="fee-nominal"><?= formatRupiah($b['amount']) ?></div>
                  <div class="fee-due">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Jatuh Tempo: <?= date('d M Y', strtotime($b['due_date'])) ?>
                  </div>
                </div>
                <div class="fee-desc">
                  <?= htmlspecialchars($b['description']) ?>
                </div>
                <div class="fee-card-action">
                  <span style="font-size:11px; color:var(--text-tertiary);">Periode: <?= $periodLabel ?></span>
                  <button type="button" class="btn-card-pay" onclick="openPaymentModal(<?= $b['bill_id'] ?>, <?= $b['amount'] ?>, '<?= htmlspecialchars($b['fee_name']) ?>')">
                    Bayar Pos Ini
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Bulk Action Bar -->
          <div class="unpaid-bulk-action">
            <div class="bulk-info">
              <span>Pelunasan Sekaligus Paket 3 Pos Iuran Wajib</span>
              <span>•</span>
              <span>Total Tagihan: <span class="bulk-total"><?= formatRupiah($totalUnpaid) ?></span></span>
            </div>
            <button type="button" class="btn-primary" onclick="openPaymentModal('all', <?= $totalUnpaid ?>, 'Semua Tagihan (Jaga Malam, Jaga Siang, Sampah)')">
              Bayar Sekaligus (<?= formatRupiah($totalUnpaid) ?>)
            </button>
          </div>
        <?php else: ?>
          <div style="padding:32px; text-align:center; background:var(--bg-subtle); border-radius:var(--radius-md); border:1px dashed var(--border-subtle);">
            <div style="font-size:28px; margin-bottom:8px;">✅</div>
            <div style="font-weight:600; font-size:15px;">Semua Iuran Bulan <?= $periodLabel ?> Sudah Lunas!</div>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Terima kasih atas partisipasi dan kepedulian Anda dalam menjaga keamanan serta kebersihan lingkungan.</div>
          </div>
        <?php endif; ?>
      </section>

      <!-- BAGIAN BAWAH (SESUAI REQUEST): HISTORY PEMBAYARAN YANG SUDAH LUNAS -->
      <section class="section-container" id="historySection">
        <div class="section-header">
          <div class="section-title-wrap">
            <h3>History Pembayaran yang Sudah Lunas</h3>
            <p>Arsip transaksi dan riwayat kwitansi digital resmi untuk unit rumah Blok <?= htmlspecialchars($user['block']) ?> No. <?= htmlspecialchars($user['number']) ?>.</p>
          </div>
          <div style="display:flex; gap:8px;">
            <span class="status-badge paid"><?= count($historyRows) ?> Transaksi Tercatat</span>
          </div>
        </div>

        <div class="history-table-wrapper">
          <table class="apple-table">
            <thead>
              <tr>
                <th>No. Kwitansi</th>
                <th>Periode Tagihan</th>
                <th>Rincian Pos Terbayar</th>
                <th>Total Bayar</th>
                <th>Metode Bayar</th>
                <th>Waktu Pelunasan</th>
                <th>Status</th>
                <th>Kwitansi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($historyRows) > 0): ?>
                <?php foreach ($historyRows as $h): ?>
                  <tr>
                    <td class="invoice-id"><?= htmlspecialchars($h['receipt_no']) ?></td>
                    <td>
                      <div class="period-title"><?= ($monthNames[$h['period_month']] ?? 'Bulan ' . $h['period_month']) ?> <?= $h['period_year'] ?></div>
                      <div class="period-sub">Iuran Bulanan Warga</div>
                    </td>
                    <td>
                      <div>• <?= htmlspecialchars($h['fee_name']) ?></div>
                    </td>
                    <td style="font-weight:600;"><?= formatRupiah($h['amount_paid']) ?></td>
                    <td><?= htmlspecialchars($h['payment_method']) ?></td>
                    <td><?= date('d M Y, H:i', strtotime($h['paid_at'])) ?> WIB</td>
                    <td><span class="status-badge paid">● Lunas</span></td>
                    <td>
                      <a href="receipt.php?receipt_no=<?= urlencode($h['receipt_no']) ?>" target="_blank" class="btn-receipt">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Kwitansi
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" style="text-align:center; padding:24px; color:var(--text-tertiary);">Belum ada riwayat pembayaran yang tercatat.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </main>
</div>

<!-- MODAL PEMBAYARAN -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal-card">
    <div class="modal-header">
      <h4 id="modalTitle">Konfirmasi Pembayaran Iuran</h4>
      <button type="button" class="modal-close" onclick="closePaymentModal()">&times;</button>
    </div>
    <form method="POST" action="process_payment.php" id="paymentForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
      <input type="hidden" name="pay_target" id="modalPayTarget" value="all">

      <div class="modal-body">
        <div class="modal-amount-box">
          <div class="label" id="modalItemLabel">Total Tagihan Terpilih</div>
          <div class="val" id="modalAmountVal"><?= formatRupiah($totalUnpaid) ?></div>
          <div style="font-size:12px; color:var(--text-tertiary); margin-top:3px;">Rumah: Blok <?= htmlspecialchars($user['block']) ?> No. <?= htmlspecialchars($user['number']) ?> (<?= htmlspecialchars($user['full_name']) ?>)</div>
        </div>

        <div style="font-size:11px; font-weight:600; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:0.04em;">
          Pilih Metode Pembayaran
        </div>

        <div class="pay-methods">
          <label class="pay-method-item selected">
            <div class="pay-method-left">
              <input type="radio" name="payment_method" value="QRIS Mandiri Instant" checked>
              <span>QRIS Nasional (BCA, Mandiri, GoPay, OVO)</span>
            </div>
            <span style="font-size:11px; color:var(--badge-paid-text); font-weight:600;">Otomatis</span>
          </label>
          <label class="pay-method-item">
            <div class="pay-method-left">
              <input type="radio" name="payment_method" value="BCA Virtual Account">
              <span>BCA Virtual Account (8271-0812-3456)</span>
            </div>
            <span style="font-size:11px; color:var(--text-tertiary);">Instan</span>
          </label>
          <label class="pay-method-item">
            <div class="pay-method-left">
              <input type="radio" name="payment_method" value="Mandiri Virtual Account">
              <span>Transfer Bank Mandiri (VA Lingkungan)</span>
            </div>
            <span style="font-size:11px; color:var(--text-tertiary);">Instan</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closePaymentModal()">Batal</button>
        <button type="submit" class="btn-primary" id="btnSubmitPayment">Konfirmasi & Bayar</button>
      </div>
    </form>
  </div>
</div>

<!-- TOAST -->
<div class="toast <?= $flashSuccess ? 'show' : '' ?>" id="toastBox">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#34c759" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  <span id="toastText"><?= htmlspecialchars($flashSuccess ?? 'Operasi berhasil dilakukan!') ?></span>
</div>

<script>
  function openPaymentModal(target, amount, title) {
    document.getElementById('modalPayTarget').value = target;
    document.getElementById('modalTitle').innerText = 'Pembayaran: ' + title;
    document.getElementById('modalItemLabel').innerText = title;
    document.getElementById('modalAmountVal').innerText = 'Rp ' + amount.toLocaleString('id-ID');
    document.getElementById('paymentModal').classList.add('active');
  }

  function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
  }

  // Auto-hide toast if displayed
  const toast = document.getElementById('toastBox');
  if (toast.classList.contains('show')) {
    setTimeout(() => {
      toast.classList.remove('show');
    }, 4000);
  }
</script>

</body>
</html>
