<?php
// ─────────────────────────────────────────────────
// student/payments.php — View & Pay Monthly Fees
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('student');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['user_name'];

// ── Receipt view mode (full-page, no sidebar) ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'view_receipt' && !empty($_GET['payment_id'])) {
    $pid = (int)$_GET['payment_id'];

    $stmt = $pdo->prepare(
        "SELECT p.*, mf.billing_month AS fee_month,
                u.name AS student_name,
                r.room_number, r.room_type
         FROM payments p
         LEFT JOIN monthly_fees mf ON mf.paid_payment_id = p.id
         LEFT JOIN users u ON u.id = p.student_id
         LEFT JOIN rooms r ON r.id = p.room_id
         WHERE p.id = ? AND p.student_id = ? AND p.status = 'success'
         LIMIT 1"
    );
    $stmt->execute([$pid, $uid]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        header('Location: payments.php?error=' . urlencode('Receipt not found.'));
        exit;
    }

    $billing_label  = $receipt['fee_month'] ? date('F Y', strtotime($receipt['fee_month'])) : date('F Y', strtotime($receipt['billing_month']));
    $paid_date      = $receipt['paid_at'] ? date('d M Y, h:i A', strtotime($receipt['paid_at'])) : 'N/A';
    $amount_fmt     = 'NPR ' . number_format((float)$receipt['amount'], 2);
    $room_label     = $receipt['room_number'] ? 'Room ' . $receipt['room_number'] . ' (' . $receipt['room_type'] . ')' : 'N/A';
    $gateway_label  = strtoupper($receipt['gateway'] ?? 'eSewa');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= e($receipt['receipt_code']) ?> — HMS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', Inter, Arial, sans-serif; background: #f6f8fb; color: #0f172a; padding: 2rem 1rem; min-height: 100vh; }
        .receipt-wrap { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; overflow: hidden; box-shadow: 0 4px 24px rgba(15,23,42,0.09); }
        .receipt-head { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; padding: 2rem 2rem 1.5rem; text-align: center; }
        .receipt-logo { font-size: 2rem; font-weight: 900; letter-spacing: -0.04em; margin-bottom: 0.25rem; }
        .receipt-subtitle { font-size: 0.8rem; opacity: 0.85; font-weight: 500; }
        .receipt-badge { display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(255,255,255,0.18); border-radius: 999px; padding: 0.3rem 0.85rem; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; margin-top: 1rem; }
        .receipt-badge-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; }
        .receipt-body { padding: 1.75rem 2rem; }
        .receipt-code { text-align: center; font-size: 1.05rem; font-weight: 800; letter-spacing: 0.06em; color: #4f46e5; background: #eef2ff; border-radius: 8px; padding: 0.65rem 1rem; margin-bottom: 1.5rem; font-family: 'Courier New', monospace; }
        .receipt-amount { text-align: center; font-size: 2.2rem; font-weight: 900; letter-spacing: -0.03em; color: #0f172a; margin-bottom: 0.35rem; }
        .receipt-amount-label { text-align: center; font-size: 0.78rem; color: #64748b; margin-bottom: 1.75rem; }
        .receipt-table { width: 100%; border-collapse: collapse; font-size: 0.865rem; }
        .receipt-table tr td { padding: 0.6rem 0; border-bottom: 1px solid #f1f5f9; }
        .receipt-table tr:last-child td { border-bottom: none; }
        .receipt-table td:first-child { color: #64748b; font-weight: 500; width: 45%; }
        .receipt-table td:last-child { font-weight: 700; color: #0f172a; text-align: right; }
        .receipt-footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 1.25rem 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .receipt-footer small { font-size: 0.72rem; color: #94a3b8; }
        .btn-print { background: #6366f1; color: #fff; border: none; border-radius: 8px; padding: 0.55rem 1.25rem; font-size: 0.855rem; font-weight: 700; cursor: pointer; transition: background 0.15s; }
        .btn-print:hover { background: #4f46e5; }
        .btn-back { color: #6366f1; font-size: 0.8rem; font-weight: 600; text-decoration: none; }
        .btn-back:hover { text-decoration: underline; }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt-footer .btn-print, .receipt-footer .btn-back { display: none; }
            .receipt-wrap { box-shadow: none; border: none; }
        }
    </style>
</head>
<body>
<div class="receipt-wrap">
    <div class="receipt-head">
        <div class="receipt-logo">HMS</div>
        <div class="receipt-subtitle">Hostel Management System — Official Receipt</div>
        <div class="receipt-badge"><span class="receipt-badge-dot"></span> Payment Confirmed</div>
    </div>
    <div class="receipt-body">
        <div class="receipt-code"><?= e($receipt['receipt_code']) ?></div>
        <div class="receipt-amount"><?= e($amount_fmt) ?></div>
        <div class="receipt-amount-label">Total Amount Paid</div>
        <table class="receipt-table">
            <tr>
                <td>Student Name</td>
                <td><?= e($receipt['student_name']) ?></td>
            </tr>
            <tr>
                <td>Room</td>
                <td><?= e($room_label) ?></td>
            </tr>
            <tr>
                <td>Billing Month</td>
                <td><?= e($billing_label) ?></td>
            </tr>
            <tr>
                <td>Paid On</td>
                <td><?= e($paid_date) ?></td>
            </tr>
            <tr>
                <td>Payment Gateway</td>
                <td><?= e($gateway_label) ?></td>
            </tr>
            <?php if (!empty($receipt['gateway_ref'])): ?>
            <tr>
                <td>Gateway Ref</td>
                <td style="font-family:'Courier New',monospace;font-size:0.8rem;"><?= e($receipt['gateway_ref']) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <div class="receipt-footer">
        <small>HMS Student Residence &bull; Thamel, Kathmandu<br>Keep this receipt for your records.</small>
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <a class="btn-back" href="payments.php">Back to Payments</a>
            <button class="btn-print" onclick="window.print()">Print Receipt</button>
        </div>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── Download receipt redirect ─────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download_receipt' && !empty($_GET['receipt_code'])) {
    $rc = trim($_GET['receipt_code']);
    $stmt = $pdo->prepare(
        "SELECT id FROM payments WHERE receipt_code = ? AND student_id = ? AND status = 'success' LIMIT 1"
    );
    $stmt->execute([$rc, $uid]);
    $row = $stmt->fetch();
    if ($row) {
        header('Location: payments.php?action=view_receipt&payment_id=' . (int)$row['id']);
    } else {
        header('Location: payments.php?error=' . urlencode('Receipt not found.'));
    }
    exit;
}

// ── Student room lookup ───────────────────────────────────────────────────
$room_stmt = $pdo->prepare(
    "SELECT u.room_id, u.fee_status, r.room_number, r.room_type, r.price
     FROM users u
     LEFT JOIN rooms r ON r.id = u.room_id
     WHERE u.id = ?
     LIMIT 1"
);
$room_stmt->execute([$uid]);
$student = $room_stmt->fetch();

$room_id    = (int)($student['room_id'] ?? 0);
$room_price = (float)($student['price'] ?? 0);
$room_num   = $student['room_number'] ?? null;
$room_type  = $student['room_type'] ?? null;

// Fallback: check bookings if room_id not directly set on users
if (!$room_id) {
    $bk = $pdo->prepare(
        "SELECT b.room_id, r.price, r.room_number, r.room_type
         FROM bookings b
         JOIN rooms r ON r.id = b.room_id
         WHERE b.student_id = ? AND b.status IN ('approved','active')
         ORDER BY b.updated_at DESC
         LIMIT 1"
    );
    $bk->execute([$uid]);
    $booking = $bk->fetch();
    if ($booking) {
        $room_id    = (int)$booking['room_id'];
        $room_price = (float)$booking['price'];
        $room_num   = $booking['room_number'];
        $room_type  = $booking['room_type'];
    }
}

// ── Current month fee lookup / auto-create ────────────────────────────────
$billing_month = date('Y-m-01');
$billing_label = date('F Y');
$due_date_str  = date('Y-m-10'); // 10th of current month

$current_fee = null;
if ($room_id) {
    $fee_stmt = $pdo->prepare(
        "SELECT * FROM monthly_fees
         WHERE student_id = ? AND billing_month = DATE_FORMAT(NOW(),'%Y-%m-01')
         LIMIT 1"
    );
    $fee_stmt->execute([$uid]);
    $current_fee = $fee_stmt->fetch();

    // Auto-create if no fee row exists for this month
    if (!$current_fee) {
        $amount = max(1000, $room_price ?: 3000.00);
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO monthly_fees (student_id, room_id, amount, billing_month, due_date)
             VALUES (?, ?, ?, DATE_FORMAT(NOW(),'%Y-%m-01'), DATE_FORMAT(NOW(),'%Y-%m-10'))"
        );
        $ins->execute([$uid, $room_id, $amount]);
        // Re-fetch
        $fee_stmt->execute([$uid]);
        $current_fee = $fee_stmt->fetch();
    }

    // Sync overdue status for this fee if applicable
    if ($current_fee && $current_fee['status'] === 'unpaid' && strtotime($current_fee['due_date']) < time()) {
        $pdo->prepare("UPDATE monthly_fees SET status='overdue' WHERE id=?")->execute([$current_fee['id']]);
        $current_fee['status'] = 'overdue';
    }
}

// ── Payment history ───────────────────────────────────────────────────────
$hist_stmt = $pdo->prepare(
    "SELECT p.*, mf.billing_month AS fee_month
     FROM payments p
     LEFT JOIN monthly_fees mf ON mf.paid_payment_id = p.id
     WHERE p.student_id = ? AND p.status = 'success'
     ORDER BY p.paid_at DESC
     LIMIT 10"
);
$hist_stmt->execute([$uid]);
$payment_history = $hist_stmt->fetchAll();

// ── Aggregate stats for the top row ───────────────────────────────────────
$stats_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_paid,
            COUNT(*)                  AS months_paid,
            MAX(paid_at)              AS last_paid_at
     FROM payments WHERE student_id = ? AND status = 'success'"
);
$stats_stmt->execute([$uid]);
$stats = $stats_stmt->fetch() ?: ['total_paid' => 0, 'months_paid' => 0, 'last_paid_at' => null];

// Year-to-date paid
$ytd_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS ytd_paid
     FROM payments
     WHERE student_id = ? AND status = 'success'
       AND YEAR(paid_at) = YEAR(CURDATE())"
);
$ytd_stmt->execute([$uid]);
$ytd_paid = (float)($ytd_stmt->fetch()['ytd_paid'] ?? 0);

$last_paid_label = $stats['last_paid_at']
    ? date('d M Y', strtotime($stats['last_paid_at']))
    : '—';
$next_due_label = $current_fee
    ? (($current_fee['status'] === 'paid')
        ? date('d M Y', strtotime('first day of next month'))
        : date('d M Y', strtotime($current_fee['due_date'])))
    : '—';

// ── Flash message helpers ─────────────────────────────────────────────────
$flash_success = '';
$flash_error   = '';
if (!empty($_GET['success']) && (int)$_GET['success'] === 1) {
    $receipt_code = !empty($_GET['receipt']) ? trim($_GET['receipt']) : '';
    $flash_success = 'Payment successful! Your fee for ' . $billing_label . ' has been recorded.';
    if ($receipt_code) {
        $flash_success .= ' Receipt: <strong>' . e($receipt_code) . '</strong>';
        // Find payment ID for view link
        $rp = $pdo->prepare("SELECT id FROM payments WHERE receipt_code = ? AND student_id = ? LIMIT 1");
        $rp->execute([$receipt_code, $uid]);
        $rp_row = $rp->fetch();
        if ($rp_row) {
            $flash_success .= ' &nbsp;<a href="payments.php?action=view_receipt&payment_id=' . (int)$rp_row['id'] . '" style="color:var(--primary);font-weight:700;">View Receipt</a>';
        }
    }
}
if (!empty($_GET['error'])) {
    $flash_error = trim($_GET['error']);
}

// ── Days until/since due date ─────────────────────────────────────────────
$days_diff      = 0;
$is_overdue     = false;
$due_display    = '';
if ($current_fee) {
    $due_ts    = strtotime($current_fee['due_date']);
    $today_ts  = strtotime(date('Y-m-d'));
    $days_diff = (int)round(($due_ts - $today_ts) / 86400);
    $is_overdue = ($days_diff < 0 || $current_fee['status'] === 'overdue');
    if ($is_overdue) {
        $due_display = abs($days_diff) . ' day' . (abs($days_diff) !== 1 ? 's' : '') . ' overdue';
    } elseif ($days_diff === 0) {
        $due_display = 'Due today';
    } else {
        $due_display = $days_diff . ' day' . ($days_diff !== 1 ? 's' : '') . ' remaining';
    }
}

// ── Fee status for right panel badge ─────────────────────────────────────
$fee_status_label = 'No room';
$fee_badge_class  = '';
if ($current_fee) {
    $fee_status_label = ucfirst($current_fee['status']);
    $fee_badge_class  = match($current_fee['status']) {
        'paid'    => 'badge-green',
        'overdue' => 'badge-red',
        default   => 'badge-pending',
    };
} elseif ($room_id) {
    $fee_status_label = 'Not Generated';
    $fee_badge_class  = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        .notice-box {
            padding: 0.8rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            background: var(--panel-alt);
            color: var(--muted);
            border: 1px solid var(--border);
            line-height: 1.5;
        }
        .notice-box a { color: var(--primary); font-weight: 600; }

        .bill-card { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); overflow: hidden; margin-bottom: 1.5rem; }
        .bill-card-head { padding: 1rem 1.25rem 0.75rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        .bill-card-title { font-size: 1rem; font-weight: 700; color: var(--text); }
        .bill-card-body { padding: 1.25rem; }

        .bill-amount { font-size: 2.4rem; font-weight: 900; letter-spacing: -0.04em; color: var(--text); line-height: 1; margin: 0.5rem 0 0.25rem; }
        .bill-amount-label { font-size: 0.78rem; color: var(--muted); margin-bottom: 1.25rem; }

        .bill-meta { display: flex; flex-wrap: wrap; gap: 0.6rem 1.5rem; font-size: 0.82rem; color: var(--muted); margin-bottom: 1.25rem; }
        .bill-meta strong { color: var(--text); font-weight: 700; }

        .bill-due { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 999px; }
        .bill-due-ok { background: #dcfce7; color: #15803d; }
        .bill-due-warn { background: #fef3c7; color: #b45309; }
        .bill-due-overdue { background: #fee2e2; color: #b91c1c; }

        .bill-paid-box { display: flex; align-items: center; gap: 0.875rem; padding: 0.75rem; background: #fff; border: 1.5px solid var(--border); border-radius: 10px; }
        .bill-paid-icon { width: 36px; height: 36px; border-radius: 50%; background: rgba(16,185,129,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .bill-paid-icon svg { width: 18px; height: 18px; stroke: var(--success); fill: none; stroke-width: 2.5; }
        .bill-paid-label { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .bill-paid-meta { font-size: 0.78rem; color: var(--muted); margin-top: 1px; }

        .btn-esewa { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: #60bb46; color: #fff; border: none; border-radius: 10px; padding: 0.75rem 1.5rem; font-size: 0.9rem; font-weight: 800; cursor: pointer; transition: background 0.15s, transform 0.1s; text-decoration: none; min-width: 200px; }
        .btn-esewa:hover { background: #4caf35; transform: translateY(-1px); }
        .btn-esewa:active { transform: translateY(0); }
        .btn-esewa svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; }

        .hist-table { width: 100%; border-collapse: collapse; font-size: 0.855rem; }
        .hist-table th { text-align: left; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted); padding: 0 0 0.6rem; border-bottom: 1.5px solid var(--border); }
        .hist-table td { padding: 0.7rem 0; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .hist-table tr:last-child td { border-bottom: none; }
        .hist-table .col-amount { font-weight: 800; color: var(--text); }
        .hist-table .col-date { color: var(--muted); font-size: 0.8rem; }
        .hist-table .col-month { font-weight: 600; }

        .rp-link-list { list-style: none; display: flex; flex-direction: column; gap: 0.15rem; }
        .rp-link-list li a { display: flex; align-items: center; gap: 0.6rem; padding: 0.45rem 0.5rem; border-radius: 7px; font-size: 0.82rem; font-weight: 600; color: var(--text); text-decoration: none; transition: background 0.15s; }
        .rp-link-list li a:hover { background: var(--panel-alt); }
        .rp-link-list li a.rp-link-active { color: var(--primary); background: var(--primary-soft); }
        .rp-link-list li a svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; opacity: 0.7; flex-shrink: 0; }

        .empty-hist { text-align: center; padding: 2rem 1rem; color: var(--muted); font-size: 0.875rem; }

        /* Stats row */
        .pay-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .pay-stat { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.4rem; }
        .pay-stat-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .pay-stat-label { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; color: var(--muted); text-transform: uppercase; }
        .pay-stat-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .pay-stat-icon svg { width: 15px; height: 15px; stroke-width: 2; fill: none; stroke: currentColor; }
        .pay-stat-value { font-size: 1.45rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text); line-height: 1.15; }
        .pay-stat-sub { font-size: 0.72rem; color: var(--muted); font-weight: 500; }
        @media (max-width: 900px) { .pay-stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .pay-stats-grid { grid-template-columns: 1fr; } }

        /* Bill card breathing room */
        .bill-card-head { padding: 1.1rem 1.4rem 0.85rem !important; }
        .bill-card-body { padding: 1.4rem 1.4rem 1.5rem !important; }
        .hist-table th { padding-bottom: 0.7rem !important; }
        .hist-table td { padding: 0.85rem 0 !important; }

        /* Info / includes card */
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.7rem 1.25rem; padding: 0.25rem 0; }
        .info-row { display: flex; align-items: flex-start; gap: 0.65rem; font-size: 0.85rem; color: var(--text); }
        .info-row svg { width: 16px; height: 16px; stroke: var(--success); fill: none; stroke-width: 2.5; flex-shrink: 0; margin-top: 2px; }
        .info-row b { font-weight: 700; }
        .info-row span { color: var(--muted); font-size: 0.78rem; display: block; margin-top: 1px; }
        @media (max-width: 700px) { .info-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?= render_sidebar('payments.php') ?>
<?= render_topbar() ?>

<div class="app-shell">
    <!-- ── Main content ─────────────────────────────────────────────── -->
    <main class="shell-main">
        <div class="page-header">
            <h1>Payments</h1>
        </div>

        <?php if ($flash_error): ?>
            <div class="alert alert-error"><?= e($flash_error) ?></div>
        <?php endif; ?>
        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= $flash_success ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="pay-stats-grid">
            <div class="pay-stat">
                <div class="pay-stat-head">
                    <span class="pay-stat-label">Total Paid</span>
                    <span class="pay-stat-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                    </span>
                </div>
                <div class="pay-stat-value">NPR <?= number_format((float)$stats['total_paid'], 0) ?></div>
                <div class="pay-stat-sub">Across all months</div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-head">
                    <span class="pay-stat-label">This Year</span>
                    <span class="pay-stat-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                        <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </span>
                </div>
                <div class="pay-stat-value">NPR <?= number_format($ytd_paid, 0) ?></div>
                <div class="pay-stat-sub"><?= date('Y') ?> total</div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-head">
                    <span class="pay-stat-label">Months Paid</span>
                    <span class="pay-stat-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                </div>
                <div class="pay-stat-value"><?= (int)$stats['months_paid'] ?></div>
                <div class="pay-stat-sub">Last on <?= e($last_paid_label) ?></div>
            </div>
            <div class="pay-stat">
                <div class="pay-stat-head">
                    <span class="pay-stat-label">Next Due</span>
                    <span class="pay-stat-icon" style="background:<?= $is_overdue ? 'rgba(239,68,68,0.1)' : 'rgba(99,102,241,0.1)' ?>;color:<?= $is_overdue ? '#ef4444' : '#6366f1' ?>;">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                </div>
                <div class="pay-stat-value" style="font-size:1.15rem;"><?= e($next_due_label) ?></div>
                <div class="pay-stat-sub">
                    <?php if ($current_fee && $current_fee['status'] !== 'paid'): ?>
                        <?= e($due_display) ?>
                    <?php elseif ($current_fee && $current_fee['status'] === 'paid'): ?>
                        Current month paid
                    <?php else: ?>
                        No bill generated
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Current Bill Card -->
        <div class="bill-card">
            <div class="bill-card-head">
                <span class="bill-card-title">Current Bill</span>
                <?php if ($current_fee): ?>
                    <span class="badge <?= $fee_badge_class ?>"><?= e(ucfirst($current_fee['status'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="bill-card-body">
                <?php if (!$room_id): ?>
                    <div class="notice-box">
                        No room allocated. <a href="browse_rooms.php">Book a room first.</a>
                    </div>

                <?php elseif (!$current_fee): ?>
                    <div class="notice-box">
                        Fees for <?= e($billing_label) ?> have not been generated yet. Please check back later.
                    </div>

                <?php elseif ($current_fee['status'] === 'paid'): ?>
                    <?php
                    // Find receipt code for this month
                    $paid_rcpt = null;
                    if (!empty($current_fee['paid_payment_id'])) {
                        $rcs = $pdo->prepare("SELECT id, receipt_code FROM payments WHERE id=? AND status='success' LIMIT 1");
                        $rcs->execute([$current_fee['paid_payment_id']]);
                        $paid_rcpt = $rcs->fetch();
                    }
                    ?>
                    <div class="bill-paid-box">
                        <div class="bill-paid-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <div class="bill-paid-label">Fees paid for <?= e($billing_label) ?></div>
                            <?php if ($paid_rcpt): ?>
                                <div class="bill-paid-meta">
                                    Receipt: <?= e($paid_rcpt['receipt_code']) ?> &mdash;
                                    <a href="payments.php?action=view_receipt&payment_id=<?= (int)$paid_rcpt['id'] ?>" style="color:var(--primary);font-weight:700;">View Receipt</a>
                                </div>
                            <?php else: ?>
                                <div class="bill-paid-meta">Payment confirmed for <?= e($billing_label) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <?php $due_css = $is_overdue ? 'bill-due-overdue' : ($days_diff <= 3 ? 'bill-due-warn' : 'bill-due-ok'); ?>
                    <div class="bill-meta">
                        <span>Room <strong><?= e($room_num ?? 'N/A') ?></strong></span>
                        <span>Type <strong><?= e($room_type ?? 'N/A') ?></strong></span>
                        <span>Month <strong><?= e($billing_label) ?></strong></span>
                    </div>

                    <div class="bill-amount">NPR <?= number_format((float)$current_fee['amount'], 0) ?></div>
                    <div class="bill-amount-label">Monthly hostel fee</div>

                    <div style="margin-bottom:1.25rem;">
                        <span class="bill-due <?= $due_css ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Due <?= e(date('d M Y', strtotime($current_fee['due_date']))) ?> &mdash; <?= e($due_display) ?>
                        </span>
                    </div>

                    <form method="post" action="../api/payment.php" data-no-busy="true">
                        <input type="hidden" name="action" value="esewa_init">
                        <input type="hidden" name="monthly_fee_id" value="<?= (int)$current_fee['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn-esewa">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                            Pay NPR <?= number_format((float)$current_fee['amount'], 0) ?> with eSewa
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History Card -->
        <div class="bill-card">
            <div class="bill-card-head">
                <span class="bill-card-title">Payment History</span>
            </div>
            <div class="bill-card-body" style="padding-top:0.75rem;">
                <?php if ($payment_history): ?>
                    <table class="hist-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Amount (NPR)</th>
                                <th>Paid On</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payment_history as $ph):
                                $month_raw = $ph['fee_month'] ?: $ph['billing_month'];
                                $month_lbl = $month_raw ? date('F Y', strtotime($month_raw)) : 'N/A';
                                $paid_on   = $ph['paid_at'] ? date('d M Y', strtotime($ph['paid_at'])) : 'N/A';
                            ?>
                            <tr>
                                <td class="col-month"><?= e($month_lbl) ?></td>
                                <td class="col-amount"><?= number_format((float)$ph['amount'], 0) ?></td>
                                <td class="col-date"><?= e($paid_on) ?></td>
                                <td>
                                    <?php if (!empty($ph['receipt_code'])): ?>
                                        <a href="payments.php?action=view_receipt&payment_id=<?= (int)$ph['id'] ?>"
                                           style="color:var(--primary);font-weight:700;font-size:0.82rem;text-decoration:none;">
                                            View Receipt
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-hist">No payment records found.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- What's Included Card -->
        <div class="bill-card">
            <div class="bill-card-head">
                <span class="bill-card-title">What Your Monthly Fee Covers</span>
                <span style="font-size:0.75rem;color:var(--muted);font-weight:600;">All-inclusive</span>
            </div>
            <div class="bill-card-body">
                <div class="info-grid">
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Furnished Room</b><span>Bed, wardrobe, desk and study lamp.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Three Meals a Day</b><span>Breakfast, lunch and dinner served at the canteen.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>100 Mbps WiFi</b><span>Fiber internet across all floors, no data cap.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Laundry 2&times;/week</b><span>Drop at canteen on Monday and Thursday.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Electricity &amp; Water</b><span>Including 24/7 generator backup.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Daily Housekeeping</b><span>Common areas cleaned every morning.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>24/7 Security</b><span>CCTV, biometric entry and on-site warden.</span></div>
                    </div>
                    <div class="info-row">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <div><b>Quiet Study Zones</b><span>Designated areas open 24 hours.</span></div>
                    </div>
                </div>
                <div class="notice-box" style="margin-top:1.25rem;">
                    Bills are generated on the 1st of each month and due by the 10th. Pay securely via eSewa &mdash; receipts are issued instantly. For any payment-related queries, raise a ticket from <a href="my_complaints.php">Complaints</a>.
                </div>
            </div>
        </div>
    </main>

    <!-- ── Right panel ────────────────────────────────────────────────── -->
    <aside class="right-panel">
        <!-- Fee Status Card -->
        <div class="rp-card">
            <div class="rp-card-title">Fee Status</div>
            <div style="margin-bottom:0.5rem;">
                <span class="badge <?= $fee_badge_class ?>"><?= e($fee_status_label) ?></span>
            </div>
            <?php if ($current_fee): ?>
                <div style="font-size:1.3rem;font-weight:800;color:var(--text);letter-spacing:-0.02em;margin-top:0.5rem;">
                    NPR <?= number_format((float)$current_fee['amount'], 0) ?>
                </div>
                <div style="font-size:0.75rem;color:var(--muted);margin-top:2px;"><?= e($billing_label) ?></div>
                <?php if ($current_fee['status'] !== 'paid' && $due_display): ?>
                    <div style="font-size:0.78rem;font-weight:600;margin-top:0.6rem;color:<?= $is_overdue ? '#b91c1c' : 'var(--muted)' ?>;">
                        <?= e($due_display) ?>
                    </div>
                <?php endif; ?>
            <?php elseif ($room_id): ?>
                <div style="font-size:0.82rem;color:var(--muted);margin-top:0.4rem;">Fee not generated yet.</div>
            <?php else: ?>
                <div style="font-size:0.82rem;color:var(--muted);margin-top:0.4rem;">No room assigned.</div>
            <?php endif; ?>
        </div>

        <!-- Quick Links Card -->
        <div class="rp-card">
            <div class="rp-card-title">Quick Links</div>
            <ul class="rp-link-list">
                <li>
                    <a href="payments.php" class="rp-link-active">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                        Payments
                    </a>
                </li>
                <li>
                    <a href="room.php">
                        <svg viewBox="0 0 24 24"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>
                        My Room
                    </a>
                </li>
                <li>
                    <a href="dashboard.php">
                        <svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/></svg>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="my_complaints.php">
                        <svg viewBox="0 0 24 24"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>
                        Complaints
                    </a>
                </li>
            </ul>
        </div>
    </aside>
</div>

<?php include __DIR__ . '/../chatbot/widget.php'; ?>
<script src="../js/script.js"></script>
</body>
</html>
