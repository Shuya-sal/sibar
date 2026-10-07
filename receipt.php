<?php
// receipt.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAuth();
$receiptNo = trim($_GET['receipt_no'] ?? '');

if (empty($receiptNo)) {
    die('Nomor kwitansi tidak ditemukan.');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT p.receipt_no, p.amount_paid, p.payment_method, p.paid_at,
           b.period_month, b.period_year,
           f.name AS fee_name, f.code,
           h.block, h.number, h.address,
           u.full_name
    FROM payments p
    JOIN bills b ON b.id = p.bill_id
    JOIN fee_types f ON f.id = b.fee_type_id
    JOIN houses h ON h.id = b.house_id
    JOIN users u ON u.house_id = h.id
    WHERE p.receipt_no = :receipt_no AND b.house_id = :house_id
    LIMIT 1
");
$stmt->execute([
    ':receipt_no' => $receiptNo,
    ':house_id' => $user['house_id']
]);
$data = $stmt->fetch();

if (!$data) {
    die('Kwitansi tidak ditemukan atau Anda tidak memiliki akses ke dokumen ini.');
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$periodStr = ($monthNames[$data['period_month']] ?? $data['period_month']) . ' ' . $data['period_year'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kwitansi — <?= htmlspecialchars($data['receipt_no']) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body {
      background: var(--bg-page);
      display: flex;
      justify-content: center;
      padding: 40px 16px;
    }
    .receipt-container {
      width: 100%;
      max-width: 540px;
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 36px 32px;
    }
    .receipt-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 20px;
      margin-bottom: 24px;
    }
    .receipt-brand h2 {
      font-size: 18px;
      font-weight: 600;
      color: var(--text-primary);
    }
    .receipt-brand p {
      font-size: 11px;
      color: var(--text-tertiary);
      margin-top: 2px;
    }
    .receipt-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      background: var(--badge-paid-bg);
      color: var(--badge-paid-text);
      border: 1px solid var(--badge-paid-border);
      border-radius: var(--radius-pill);
      font-size: 11px;
      font-weight: 600;
    }
    .receipt-meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 24px;
    }
    .meta-box .label {
      font-size: 10.5px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-tertiary);
    }
    .meta-box .val {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
      margin-top: 3px;
    }
    .receipt-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 24px;
    }
    .receipt-table th {
      text-align: left;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-tertiary);
      padding: 8px 0;
      border-bottom: 1px solid var(--border-subtle);
    }
    .receipt-table td {
      padding: 14px 0;
      font-size: 13px;
      border-bottom: 1px solid var(--border-subtle);
    }
    .receipt-total {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 0;
      border-bottom: 1px dashed var(--border-subtle);
    }
    .receipt-total .title {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-primary);
    }
    .receipt-total .amount {
      font-size: 22px;
      font-weight: 700;
      color: var(--accent-blue);
      letter-spacing: -0.02em;
    }
    .receipt-footer {
      margin-top: 24px;
      text-align: center;
      font-size: 11.5px;
      color: var(--text-tertiary);
      line-height: 1.5;
    }
    .print-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 24px;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .receipt-container { box-shadow: none; border: none; padding: 0; }
      .print-actions { display: none; }
    }
  </style>
</head>
<body>

<div class="receipt-container">
  <div class="receipt-header">
    <div class="receipt-brand">
      <h2>SIBAR — Kwitansi Digital</h2>
      <p>RT 04 / RW 08 • Klaster Cempaka Indah</p>
    </div>
    <span class="receipt-badge">● Lunas Terverifikasi</span>
  </div>

  <div class="receipt-meta">
    <div class="meta-box">
      <div class="label">Nomor Transaksi</div>
      <div class="val" style="font-family:ui-monospace, monospace;"><?= htmlspecialchars($data['receipt_no']) ?></div>
    </div>
    <div class="meta-box">
      <div class="label">Tanggal Lunas</div>
      <div class="val"><?= date('d M Y, H:i', strtotime($data['paid_at'])) ?> WIB</div>
    </div>
    <div class="meta-box">
      <div class="label">Kepala Keluarga</div>
      <div class="val"><?= htmlspecialchars($data['full_name']) ?></div>
    </div>
    <div class="meta-box">
      <div class="label">Unit Rumah</div>
      <div class="val">Blok <?= htmlspecialchars($data['block']) ?> No. <?= htmlspecialchars($data['number']) ?></div>
    </div>
  </div>

  <table class="receipt-table">
    <thead>
      <tr>
        <th>Deskripsi Tagihan</th>
        <th>Periode</th>
        <th style="text-align:right;">Nominal</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <div style="font-weight:600;"><?= htmlspecialchars($data['fee_name']) ?></div>
          <div style="font-size:11px; color:var(--text-tertiary);">Metode: <?= htmlspecialchars($data['payment_method']) ?></div>
        </td>
        <td><?= $periodStr ?></td>
        <td style="text-align:right; font-weight:600;"><?= formatRupiah($data['amount_paid']) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="receipt-total">
    <div class="title">Total Pembayaran</div>
    <div class="amount"><?= formatRupiah($data['amount_paid']) ?></div>
  </div>

  <div class="receipt-footer">
    Dokumen ini adalah bukti transaksi digital resmi dari sistem kas iuran RT 04 / RW 08.<br>
    Dicetak otomatis oleh SIBAR.
  </div>

  <div class="print-actions">
    <button class="btn-primary" onclick="window.print()">Cetak / Simpan PDF</button>
    <a href="dashboard.php" class="btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>

</body>
</html>
