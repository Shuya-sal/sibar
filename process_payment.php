<?php
// process_payment.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) {
    die('Invalid CSRF token');
}

$payTarget = $_POST['pay_target'] ?? 'all';
$paymentMethod = trim($_POST['payment_method'] ?? 'QRIS Instant');
$db = getDB();

$currentYear = 2026;
$currentMonth = 10;

try {
    $db->beginTransaction();

    if ($payTarget === 'all') {
        // Ambil semua tagihan unpaid rumah ini di bulan berjalan
        $stmt = $db->prepare("
            SELECT id, amount, fee_type_id
            FROM bills
            WHERE house_id = :house_id
              AND period_year = :year
              AND period_month = :month
              AND status = 'unpaid'
        ");
        $stmt->execute([
            ':house_id' => $user['house_id'],
            ':year' => $currentYear,
            ':month' => $currentMonth
        ]);
        $unpaidList = $stmt->fetchAll();

        if (empty($unpaidList)) {
            $db->rollBack();
            header('Location: dashboard.php');
            exit;
        }

        $stmtUpdate = $db->prepare("UPDATE bills SET status = 'paid' WHERE id = :id");
        $stmtInsert = $db->prepare("
            INSERT INTO payments (bill_id, receipt_no, amount_paid, payment_method, paid_at)
            VALUES (:bill_id, :receipt_no, :amount_paid, :payment_method, :paid_at)
        ");

        $now = date('Y-m-d H:i:s');
        $seq = 1;
        foreach ($unpaidList as $b) {
            $receiptNo = sprintf('INV-%04d%02d-%s%s-%02d', 
                $currentYear, 
                $currentMonth, 
                preg_replace('/[^a-zA-Z0-9]/', '', $user['block']),
                preg_replace('/[^a-zA-Z0-9]/', '', $user['number']),
                $b['fee_type_id']
            );

            // Update status bill
            $stmtUpdate->execute([':id' => $b['id']]);

            // Insert payment
            $stmtInsert->execute([
                ':bill_id' => $b['id'],
                ':receipt_no' => $receiptNo,
                ':amount_paid' => $b['amount'],
                ':payment_method' => $paymentMethod,
                ':paid_at' => $now
            ]);
            $seq++;
        }

        $_SESSION['flash_success'] = 'Seluruh tagihan bulan ' . $currentMonth . '/' . $currentYear . ' berhasil dilunasi!';
    } else {
        // Single bill
        $billId = (int)$payTarget;
        $stmt = $db->prepare("
            SELECT id, amount, fee_type_id
            FROM bills
            WHERE id = :id AND house_id = :house_id AND status = 'unpaid'
            LIMIT 1
        ");
        $stmt->execute([':id' => $billId, ':house_id' => $user['house_id']]);
        $bill = $stmt->fetch();

        if (!$bill) {
            $db->rollBack();
            header('Location: dashboard.php');
            exit;
        }

        $receiptNo = sprintf('INV-%04d%02d-%s%s-%02d', 
            $currentYear, 
            $currentMonth, 
            preg_replace('/[^a-zA-Z0-9]/', '', $user['block']),
            preg_replace('/[^a-zA-Z0-9]/', '', $user['number']),
            $bill['fee_type_id']
        );

        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE bills SET status = 'paid' WHERE id = :id")->execute([':id' => $bill['id']]);
        $db->prepare("
            INSERT INTO payments (bill_id, receipt_no, amount_paid, payment_method, paid_at)
            VALUES (:bill_id, :receipt_no, :amount_paid, :payment_method, :paid_at)
        ")->execute([
            ':bill_id' => $bill['id'],
            ':receipt_no' => $receiptNo,
            ':amount_paid' => $bill['amount'],
            ':payment_method' => $paymentMethod,
            ':paid_at' => $now
        ]);

        $_SESSION['flash_success'] = 'Pembayaran tagihan berhasil! Kwitansi ' . $receiptNo . ' telah diterbitkan.';
    }

    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['flash_success'] = 'Gagal memproses pembayaran: ' . $e->getMessage();
}

header('Location: dashboard.php');
exit;
