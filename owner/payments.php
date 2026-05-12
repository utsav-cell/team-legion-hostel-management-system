<?php
// owner/payments.php — Payments management for owner
require_once '../db.php';
require_once '../auth/mailer.php';
require_role('owner');

$auth     = get_auth();
$owner_id = (int)$auth['id'];

// Auto mark overdue fees on every load
mark_overdue_fees();

$billing_month = date('Y-m-01');
$billing_label = date('F Y');

$success_msg = '';
$error_msg   = '';

// ── CSV Export (before any output) ────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    csrf_verify($_GET['csrf_token'] ?? '');
    $exp_month = $_GET['month'] ?? $billing_month;
    $rows = $pdo->prepare(
        "SELECT u.name, u.email, r.room_number, mf.billing_month, mf.amount, mf.status, mf.due_date,
                p.paid_at, p.gateway, p.receipt_code
         FROM monthly_fees mf
         JOIN users u ON u.id = mf.student_id
         LEFT JOIN rooms r ON r.id = mf.room_id
         LEFT JOIN payments p ON p.id = mf.paid_payment_id
         WHERE mf.billing_month = ?
         ORDER BY u.name ASC"
    );
    $rows->execute([$exp_month]);
    $data = $rows->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_' . $exp_month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name','Email','Room','Billing Month','Amount (NPR)','Status','Due Date','Paid At','Gateway','Receipt Code']);
    foreach ($data as $d) {
        fputcsv($out, [
            $d['name'], $d['email'], $d['room_number'] ?? '—',
            date('F Y', strtotime($d['billing_month'])),
            number_format($d['amount'], 2), ucfirst($d['status']),
            $d['due_date'] ?? '—',
            $d['paid_at'] ? date('d M Y H:i', strtotime($d['paid_at'])) : '—',
            $d['gateway'] ?? '—', $d['receipt_code'] ?? '—'
        ]);
    }
    fclose($out);
    exit;
}

// ── POST handlers ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_fees') {
        $target_month = $_POST['billing_month'] ?? $billing_month;
        $created = generate_monthly_fees($target_month);
        $due = date('Y-m-10', strtotime($target_month));

        // Email all students who just got a fee generated
        $newFees = $pdo->prepare(
            "SELECT mf.*, u.name, u.email, r.room_number
             FROM monthly_fees mf
             JOIN users u ON u.id = mf.student_id
             LEFT JOIN rooms r ON r.id = mf.room_id
             WHERE mf.billing_month = ? AND mf.status = 'unpaid'"
        );
        $newFees->execute([$target_month]);
        foreach ($newFees->fetchAll() as $fee) {
            try {
                $html = render_fee_generated_email([
                    'name'          => $fee['name'],
                    'amount'        => $fee['amount'],
                    'billing_month' => date('F Y', strtotime($fee['billing_month'])),
                    'due_date'      => date('d M Y', strtotime($fee['due_date'])),
                    'room'          => 'Room ' . ($fee['room_number'] ?? '—'),
                    'pay_url'       => '',
                ]);
                send_app_mail($fee['email'], $fee['name'], 'Your hostel fee for ' . date('F Y', strtotime($target_month)) . ' — HMS', $html);
            } catch (Exception $e) { /* non-fatal */ }
        }

        $success_msg = $created > 0
            ? "Generated fees for $created student(s) for " . date('F Y', strtotime($target_month)) . '.'
            : 'Fees already generated for all students this month.';

    } elseif ($action === 'mark_paid_manual') {
        $fee_id = (int)($_POST['fee_id'] ?? 0);
        if ($fee_id < 1) {
            $error_msg = 'Invalid fee record.';
        } else {
            // Create a manual payment record
            $feeRow = $pdo->prepare("SELECT * FROM monthly_fees WHERE id=?");
            $feeRow->execute([$fee_id]);
            $feeRow = $feeRow->fetch();
            if (!$feeRow || $feeRow['status'] === 'paid') {
                $error_msg = 'Fee not found or already marked paid.';
            } else {
                $uuid    = 'MANUAL-' . strtoupper(bin2hex(random_bytes(8)));
                $receipt = generate_receipt_code($feeRow['billing_month']);
                $ins = $pdo->prepare(
                    "INSERT INTO payments (student_id, room_id, amount, billing_month, status, gateway, transaction_uuid, receipt_code, paid_at)
                     VALUES (?, ?, ?, ?, 'success', 'manual', ?, ?, NOW())"
                );
                $ins->execute([$feeRow['student_id'], $feeRow['room_id'], $feeRow['amount'], $feeRow['billing_month'], $uuid, $receipt]);
                $pay_id = (int)$pdo->lastInsertId();

                $upd = $pdo->prepare("UPDATE monthly_fees SET status='paid', paid_payment_id=? WHERE id=?");
                $upd->execute([$pay_id, $fee_id]);

                // Send receipt email
                $studentRow = $pdo->prepare("SELECT u.name, u.email, r.room_number FROM users u LEFT JOIN rooms r ON r.id=? WHERE u.id=?");
                $studentRow->execute([$feeRow['room_id'], $feeRow['student_id']]);
                $sr = $studentRow->fetch();
                if ($sr) {
                    try {
                        $html = render_receipt_email([
                            'name'          => $sr['name'],
                            'receipt_code'  => $receipt,
                            'amount'        => $feeRow['amount'],
                            'billing_month' => date('F Y', strtotime($feeRow['billing_month'])),
                            'room'          => 'Room ' . ($sr['room_number'] ?? '—'),
                            'gateway_ref'   => $uuid,
                            'paid_on'       => date('d M Y'),
                            'gateway'       => 'Manual',
                        ]);
                        send_app_mail($sr['email'], $sr['name'], 'Payment receipt — HMS ' . date('F Y', strtotime($feeRow['billing_month'])), $html);
                    } catch (Exception $e) { /* non-fatal */ }
                }
                $success_msg = 'Payment marked as paid manually. Receipt sent.';
            }
        }

    } elseif ($action === 'send_reminder') {
        $fee_id = (int)($_POST['fee_id'] ?? 0);
        if ($fee_id < 1) {
            $error_msg = 'Invalid fee record.';
        } else {
            $feeRow = $pdo->prepare(
                "SELECT mf.*, u.name, u.email, r.room_number
                 FROM monthly_fees mf
                 JOIN users u ON u.id = mf.student_id
                 LEFT JOIN rooms r ON r.id = mf.room_id
                 WHERE mf.id=?"
            );
            $feeRow->execute([$fee_id]);
            $feeRow = $feeRow->fetch();
            if (!$feeRow) {
                $error_msg = 'Fee record not found.';
            } else {
                try {
                    $html = render_fee_reminder_email([
                        'name'          => $feeRow['name'],
                        'amount'        => $feeRow['amount'],
                        'billing_month' => date('F Y', strtotime($feeRow['billing_month'])),
                        'due_date'      => date('d M Y', strtotime($feeRow['due_date'])),
                        'room'          => 'Room ' . ($feeRow['room_number'] ?? '—'),
                        'pay_url'       => '',
                        'overdue'       => $feeRow['status'] === 'overdue',
                    ]);
                    send_app_mail($feeRow['email'], $feeRow['name'],
                        ($feeRow['status'] === 'overdue' ? 'Overdue fee notice' : 'Fee reminder') . ' — HMS ' . date('F Y', strtotime($feeRow['billing_month'])),
                        $html
                    );
                    $success_msg = 'Reminder sent to ' . htmlspecialchars($feeRow['name']) . '.';
                } catch (Exception $ex) {
                    $error_msg = 'Email failed: ' . htmlspecialchars($ex->getMessage());
                }
            }
        }

    } elseif ($action === 'send_all_reminders') {
        $unpaid_fees = $pdo->prepare(
            "SELECT mf.*, u.name, u.email, r.room_number
             FROM monthly_fees mf
             JOIN users u ON u.id = mf.student_id
             LEFT JOIN rooms r ON r.id = mf.room_id
             WHERE mf.billing_month = ? AND mf.status IN ('unpaid','overdue')"
        );
        $unpaid_fees->execute([$billing_month]);
        $sent = 0;
        foreach ($unpaid_fees->fetchAll() as $fee) {
            try {
                $html = render_fee_reminder_email([
                    'name'          => $fee['name'],
                    'amount'        => $fee['amount'],
                    'billing_month' => date('F Y', strtotime($fee['billing_month'])),
                    'due_date'      => date('d M Y', strtotime($fee['due_date'])),
                    'room'          => 'Room ' . ($fee['room_number'] ?? '—'),
                    'pay_url'       => '',
                    'overdue'       => $fee['status'] === 'overdue',
                ]);
                send_app_mail($fee['email'], $fee['name'],
                    ($fee['status'] === 'overdue' ? 'Overdue fee notice' : 'Fee reminder') . ' — HMS ' . date('F Y', strtotime($fee['billing_month'])),
                    $html
                );
                $sent++;
            } catch (Exception $e) { /* non-fatal */ }
        }
        $success_msg = "Reminders sent to $sent student(s).";
    }
}

// ── Stats ──────────────────────────────────────────
// Today's earnings (payments.paid_at = today, status=success)
$today_earn = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND DATE(paid_at)=CURDATE()"
)->fetchColumn();

// This month earnings
$month_earn = (float)$pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND billing_month=?"
)->execute([$billing_month]) ? 0 : 0;
$me_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND billing_month=?");
$me_stmt->execute([$billing_month]);
$month_earn = (float)$me_stmt->fetchColumn();

// Total all time
$total_earn = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success'")->fetchColumn();

// Pending dues this month
$pending_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM monthly_fees WHERE billing_month=? AND status IN ('unpaid','overdue')");
$pending_stmt->execute([$billing_month]);
$pending_dues = (float)$pending_stmt->fetchColumn();

// Check if fees generated for current month
$fees_generated_stmt = $pdo->prepare("SELECT COUNT(*) FROM monthly_fees WHERE billing_month=?");
$fees_generated_stmt->execute([$billing_month]);
$fees_generated_count = (int)$fees_generated_stmt->fetchColumn();

// Students yet to pay this month
$yet_to_pay = $pdo->prepare(
    "SELECT mf.id AS fee_id, mf.amount, mf.status, mf.due_date, mf.billing_month,
            u.id AS student_id, u.name, u.email, r.room_number
     FROM monthly_fees mf
     JOIN users u ON u.id = mf.student_id
     LEFT JOIN rooms r ON r.id = mf.room_id
     WHERE mf.billing_month = ? AND mf.status IN ('unpaid','overdue')
     ORDER BY mf.status DESC, u.name ASC"
);
$yet_to_pay->execute([$billing_month]);
$yet_to_pay = $yet_to_pay->fetchAll();

// Recent payments — with filter & pagination
$pay_filter = $_GET['pay_filter'] ?? 'all';
$allowed_filters = ['all','success','pending','failed'];
if (!in_array($pay_filter, $allowed_filters, true)) $pay_filter = 'all';

$per_page   = 15;
$cur_page   = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($cur_page - 1) * $per_page;

$pay_where  = ($pay_filter !== 'all') ? "WHERE p.status=" . $pdo->quote($pay_filter) : '';
$pay_count  = (int)$pdo->query("SELECT COUNT(*) FROM payments p $pay_where")->fetchColumn();
$total_pages = max(1, (int)ceil($pay_count / $per_page));

$recent_payments = $pdo->query(
    "SELECT p.id, p.amount, p.billing_month, p.status, p.gateway, p.receipt_code,
            p.paid_at, p.initiated_at, p.transaction_uuid,
            u.name AS student_name, u.email AS student_email, r.room_number
     FROM payments p
     JOIN users u ON u.id = p.student_id
     LEFT JOIN rooms r ON r.id = p.room_id
     $pay_where
     ORDER BY p.initiated_at DESC
     LIMIT $per_page OFFSET $offset"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — Payments</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        .filter-tabs { display:flex; gap:0.5rem; flex-wrap:wrap; }
        .filter-tab {
            padding:0.4rem 1rem; border-radius:999px; font-size:0.82rem; font-weight:600;
            border:1px solid var(--border); background:var(--panel); color:var(--muted);
            text-decoration:none; transition:all 0.15s;
        }
        .filter-tab:hover { background:var(--primary-soft); color:var(--primary); border-color:transparent; }
        .filter-tab.active { background:var(--primary); color:#fff; border-color:transparent; }

        .fees-pill {
            display:inline-flex; align-items:center; gap:0.4rem;
            padding:0.35rem 0.85rem; border-radius:999px; font-size:0.82rem; font-weight:700;
        }
        .fees-pill.generated { background:rgba(16,185,129,0.12); color:#065f46; border:1px solid rgba(16,185,129,0.25); }
        .fees-pill.not-generated { background:rgba(245,158,11,0.1); color:#78350f; border:1px solid rgba(245,158,11,0.2); }

        .pager { display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; margin-top:1.1rem; }
        .pager a, .pager span {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:34px; height:32px; padding:0 0.65rem;
            border-radius:8px; border:1px solid var(--border); font-size:0.82rem; font-weight:600;
            background:var(--panel); color:var(--muted); text-decoration:none; transition:all 0.15s;
        }
        .pager a:hover { background:var(--primary-soft); color:var(--primary); border-color:transparent; }
        .pager span.current { background:var(--primary); color:#fff; border-color:transparent; }
        .pager span.dots { background:none; border-color:transparent; cursor:default; }
    </style>
</head>
<body>
<?= render_sidebar('payments.php') ?>
<?= render_topbar('Payments') ?>

<div class="container">
    <div id="toast-container"></div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" data-auto-toast="1"><?= e($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error" data-auto-toast="1"><?= e($error_msg) ?></div>
    <?php endif; ?>

    <!-- Page header with export button -->
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1>Payments</h1>
        </div>
        <a href="?export=csv&month=<?= urlencode($billing_month) ?>&csrf_token=<?= csrf_token() ?>"
           class="btn btn-secondary" style="flex-shrink:0;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>

    <!-- Stat cards -->
    <div class="stats-grid" style="margin-bottom:1.75rem;">
        <div class="stat-box stat-green">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
            </div>
            <div class="stat-label">Today's Earnings</div>
            <div class="stat-value">NPR <?= number_format($today_earn, 0) ?></div>
            <div class="stat-meta"><?= date('d M Y') ?></div>
        </div>
        <div class="stat-box stat-indigo">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-label">This Month</div>
            <div class="stat-value">NPR <?= number_format($month_earn, 0) ?></div>
            <div class="stat-meta"><?= $billing_label ?></div>
        </div>
        <div class="stat-box stat-green">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div class="stat-label">Total Collected</div>
            <div class="stat-value">NPR <?= number_format($total_earn, 0) ?></div>
            <div class="stat-meta">All time</div>
        </div>
        <div class="stat-box stat-amber">
            <div class="stat-icon-bubble">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>
            </div>
            <div class="stat-label">Pending Dues</div>
            <div class="stat-value">NPR <?= number_format($pending_dues, 0) ?></div>
            <div class="stat-meta"><?= count($yet_to_pay) ?> student(s) this month</div>
        </div>
    </div>

    <!-- Fees generated widget -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
            <div class="card-title">Monthly Fees — <?= $billing_label ?></div>
            <!-- Always show generate button; confirmation popup if already generated -->
            <form method="post" <?= $fees_generated_count > 0
                ? 'data-confirm="Fees for '.$billing_label.' have already been generated for '.$fees_generated_count.' student(s). Running again only adds rows for new students without one. Continue?"'
                : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="generate_fees">
                <input type="hidden" name="billing_month" value="<?= e($billing_month) ?>">
                <button type="submit" class="btn btn-primary">
                    <?= get_svg_icon('money') ?> Generate Fees for <?= $billing_label ?>
                </button>
            </form>
            <?php if ($fees_generated_count > 0): ?>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                <form method="post" style="display:inline;"
                      data-confirm="Send fee reminders to all students who have not paid for <?= htmlspecialchars($billing_label, ENT_QUOTES) ?>?">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="send_all_reminders">
                    <button type="submit" class="btn btn-secondary">
                        Send All Reminders (<?= count($yet_to_pay) ?>)
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Students yet to pay -->
    <?php if (!empty($yet_to_pay)): ?>
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <div>
                <div class="card-title">Students Yet to Pay</div>
                <div class="card-subtitle"><?= $billing_label ?> — <?= count($yet_to_pay) ?> pending</div>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Room</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yet_to_pay as $fp): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= e($fp['name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--muted);"><?= e($fp['email']) ?></div>
                        </td>
                        <td style="color:var(--muted);">
                            <?= $fp['room_number'] ? 'Room ' . e($fp['room_number']) : '—' ?>
                        </td>
                        <td style="font-weight:700;">NPR <?= number_format($fp['amount'], 0) ?></td>
                        <td style="font-size:0.82rem;color:var(--muted);">
                            <?= date('d M Y', strtotime($fp['due_date'])) ?>
                        </td>
                        <td>
                            <?php if ($fp['status'] === 'overdue'): ?>
                                <span class="badge badge-overdue">Overdue</span>
                            <?php else: ?>
                                <span class="badge badge-unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                <!-- Send Reminder -->
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="send_reminder">
                                    <input type="hidden" name="fee_id" value="<?= $fp['fee_id'] ?>">
                                    <button type="submit" class="btn btn-secondary"
                                            style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.75rem;">
                                        Remind
                                    </button>
                                </form>
                                <!-- Mark Paid Manually -->
                                <form method="post" style="display:inline;"
                                      data-confirm="Mark NPR <?= number_format($fp['amount'],0) ?> as manually paid for <?= htmlspecialchars($fp['name'], ENT_QUOTES) ?>?">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="mark_paid_manual">
                                    <input type="hidden" name="fee_id" value="<?= $fp['fee_id'] ?>">
                                    <button type="submit" class="btn btn-success"
                                            style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.75rem;">
                                        Mark Paid
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent payments -->
    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:0.75rem;">
            <div>
                <div class="card-title">Recent Payments</div>
                <div class="card-subtitle">All payment transactions — <?= $pay_count ?> total</div>
            </div>
            <div class="filter-tabs">
                <?php foreach (['all'=>'All','success'=>'Paid','pending'=>'Pending','failed'=>'Failed'] as $fk => $fl): ?>
                <a href="?pay_filter=<?= $fk ?>&page=1" class="filter-tab <?= $pay_filter === $fk ? 'active' : '' ?>"><?= $fl ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($recent_payments)): ?>
            <p style="color:var(--muted);font-size:0.875rem;">No payment records found.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Room</th>
                        <th>Billing Month</th>
                        <th>Amount</th>
                        <th>Gateway</th>
                        <th>Status</th>
                        <th>Paid At</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $p): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;font-size:0.875rem;"><?= e($p['student_name']) ?></div>
                            <div style="font-size:0.77rem;color:var(--muted);"><?= e($p['student_email']) ?></div>
                        </td>
                        <td style="color:var(--muted);">
                            <?= $p['room_number'] ? 'Room ' . e($p['room_number']) : '—' ?>
                        </td>
                        <td style="font-size:0.85rem;"><?= date('M Y', strtotime($p['billing_month'])) ?></td>
                        <td style="font-weight:700;">NPR <?= number_format($p['amount'], 0) ?></td>
                        <td>
                            <?php if ($p['gateway'] === 'esewa'): ?>
                                <span class="badge badge-esewa">eSewa</span>
                            <?php else: ?>
                                <span class="badge badge-manual">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $pCls = match($p['status']) {
                                'success'   => 'badge-paid',
                                'pending'   => 'badge-unpaid',
                                'failed'    => 'badge-red',
                                'cancelled' => 'badge-red',
                                default     => ''
                            };
                            ?>
                            <span class="badge <?= $pCls ?>"><?= e(ucfirst($p['status'])) ?></span>
                        </td>
                        <td style="font-size:0.82rem;color:var(--muted);">
                            <?= $p['paid_at'] ? date('d M Y', strtotime($p['paid_at'])) : '—' ?>
                        </td>
                        <td style="font-family:monospace;font-size:0.75rem;color:var(--muted);">
                            <?= $p['receipt_code'] ? e($p['receipt_code']) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pager">
            <?php if ($cur_page > 1): ?>
                <a href="?pay_filter=<?= $pay_filter ?>&page=<?= $cur_page - 1 ?>">&lsaquo;</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++):
                if ($i === 1 || $i === $total_pages || abs($i - $cur_page) <= 2): ?>
                    <?php if ($i === $cur_page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?pay_filter=<?= $pay_filter ?>&page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php elseif (abs($i - $cur_page) === 3): ?>
                    <span class="dots">&hellip;</span>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($cur_page < $total_pages): ?>
                <a href="?pay_filter=<?= $pay_filter ?>&page=<?= $cur_page + 1 ?>">&rsaquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<?= render_footer() ?>

<script src="../js/script.js"></script>
<script>
(function(){
    // Auto-toast alerts
    const tc = document.getElementById('toast-container');
    document.querySelectorAll('[data-auto-toast]').forEach(function(el) {
        const t = document.createElement('div');
        t.className = el.className + ' toast';
        t.innerHTML = el.textContent + '<button class="toast-close" onclick="this.parentNode.remove()">&times;</button>';
        t.style.display = 'block';
        tc.appendChild(t);
        el.remove();
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 5000);
    });

    // Sidebar toggle
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
})();
</script>
</body>
</html>
