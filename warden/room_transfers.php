<?php
// ─────────────────────────────────────────────────
// warden/room_transfers.php — Room transfer request management
//   Warden reviews pending student room-transfer requests and can approve or reject them.
//   Approval atomically swaps room assignments and logs the change.
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('warden');

$uid   = (int)$_SESSION['user_id'];
$flash = ['type' => '', 'msg' => ''];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');

        $action      = $_POST['action']      ?? '';
        $transfer_id = (int)($_POST['transfer_id'] ?? 0);

        if (!$transfer_id) {
            throw new Exception('Invalid transfer ID.');
        }

        // Fetch transfer details
        $tstmt = $pdo->prepare(
            'SELECT rt.id, rt.student_id, rt.from_room_id, rt.to_room_id, rt.status, rt.reason,
                    u.name AS student_name, u.email AS student_email
             FROM room_transfers rt
             JOIN users u ON u.id = rt.student_id
             WHERE rt.id = ? LIMIT 1'
        );
        $tstmt->execute([$transfer_id]);
        $transfer = $tstmt->fetch();

        if (!$transfer) {
            throw new Exception('Transfer request not found.');
        }
        if ($transfer['status'] !== 'pending') {
            throw new Exception('This transfer request has already been processed.');
        }

        if ($action === 'approve') {
            $pdo->beginTransaction();

            // Free the student's old room. Empty → 'available'. Otherwise, if it
            // was previously marked 'occupied' but now has free beds again, flip
            // it back to 'available' so other students can book the open slot.
            if ($transfer['from_room_id']) {
                $oc = $pdo->prepare("SELECT COUNT(*) FROM users WHERE room_id = ? AND id <> ?");
                $oc->execute([$transfer['from_room_id'], $transfer['student_id']]);
                if ((int)$oc->fetchColumn() === 0) {
                    $pdo->prepare(
                        "UPDATE rooms SET status = 'available', student_id = NULL WHERE id = ?"
                    )->execute([$transfer['from_room_id']]);
                } else {
                    $pdo->prepare(
                        "UPDATE rooms r
                         SET r.status = 'available'
                         WHERE r.id = ?
                           AND r.status = 'occupied'
                           AND (SELECT COUNT(*) FROM users u
                                WHERE u.room_id = r.id AND u.id <> ? AND u.role='student') < r.capacity"
                    )->execute([$transfer['from_room_id'], $transfer['student_id']]);
                }
            }

            // Link student to new room. Only flip 'occupied' if it reaches capacity —
            // multi-bed rooms with free beds stay 'available' so others can still book.
            $pdo->prepare(
                "UPDATE rooms SET student_id = ? WHERE id = ?"
            )->execute([$transfer['student_id'], $transfer['to_room_id']]);
            $pdo->prepare(
                "UPDATE rooms r
                 SET r.status = 'occupied'
                 WHERE r.id = ?
                   AND (SELECT COUNT(*) FROM users u
                        WHERE u.room_id = r.id AND u.role='student') >= r.capacity"
            )->execute([$transfer['to_room_id']]);

            // Update user's room_id and mark room_status approved so dashboard banners are correct
            $pdo->prepare(
                "UPDATE users SET room_id = ?, room_status = 'approved' WHERE id = ?"
            )->execute([$transfer['to_room_id'], $transfer['student_id']]);

            // Sync bookings: cancel old active/approved bookings + insert a new approved one
            // so my_bookings.php + dashboard.php reflect the new room instantly.
            $pdo->prepare(
                "UPDATE bookings SET status = 'cancelled', updated_at = NOW()
                 WHERE student_id = ? AND status IN ('pending_approval','approved','active')"
            )->execute([$transfer['student_id']]);
            $pdo->prepare(
                "INSERT INTO bookings (student_id, room_id, start_date, status, approved_by, approved_at)
                 VALUES (?, ?, CURDATE(), 'approved', ?, NOW())"
            )->execute([$transfer['student_id'], $transfer['to_room_id'], $uid]);

            // Mark transfer as approved
            $pdo->prepare(
                "UPDATE room_transfers
                 SET status = 'approved', decided_by = ?, decided_at = NOW()
                 WHERE id = ?"
            )->execute([$uid, $transfer_id]);

            // Room audit log
            $pdo->prepare(
                'INSERT INTO room_audit_log (room_id, student_id, event, from_room_id, to_room_id, actor_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $transfer['to_room_id'],
                $transfer['student_id'],
                'room_transfer_approved',
                $transfer['from_room_id'],
                $transfer['to_room_id'],
                $uid,
                'Transfer #' . $transfer_id . ' approved by warden'
            ]);

            // ── Price-difference reconciliation ──
            // Compare old room price vs new room price. Insert a transfer
            // adjustment if there's a delta. charge = student owes; credit =
            // we owe the student.
            $adj_amount = 0;
            $adj_direction = null;
            $old_price = 0;
            $new_price = 0;
            try {
                $price_stmt = $pdo->prepare("SELECT id, price FROM rooms WHERE id = ?");
                if ($transfer['from_room_id']) {
                    $price_stmt->execute([$transfer['from_room_id']]);
                    if ($p = $price_stmt->fetch()) $old_price = (float)$p['price'];
                }
                $price_stmt->execute([$transfer['to_room_id']]);
                if ($p = $price_stmt->fetch()) $new_price = (float)$p['price'];

                $diff = $new_price - $old_price;
                if ($old_price > 0 && abs($diff) > 0.01) {
                    $adj_direction = $diff > 0 ? 'charge' : 'credit';
                    $adj_amount    = abs($diff);
                    $notes = $adj_direction === 'charge'
                        ? sprintf('Room upgrade: NPR %s → NPR %s. Student owes NPR %s.',
                            number_format($old_price, 0), number_format($new_price, 0), number_format($adj_amount, 0))
                        : sprintf('Room downgrade: NPR %s → NPR %s. Refund of NPR %s due.',
                            number_format($old_price, 0), number_format($new_price, 0), number_format($adj_amount, 0));
                    $pdo->prepare(
                        "INSERT INTO transfer_adjustments
                            (student_id, transfer_id, from_room_id, to_room_id,
                             old_price, new_price, amount, direction, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $transfer['student_id'],
                        $transfer_id,
                        $transfer['from_room_id'],
                        $transfer['to_room_id'],
                        $old_price,
                        $new_price,
                        $adj_amount,
                        $adj_direction,
                        $notes,
                    ]);
                }
            } catch (Exception $adj_ex) {
                // Non-fatal — the transfer itself succeeded.
                // Log silently; admin can compute manually if needed.
            }

            $pdo->commit();

            // Build flash message including the price diff
            if ($adj_direction === 'charge') {
                $flash = ['type' => 'success', 'msg' => sprintf(
                    'Transfer approved. Student owes NPR %s extra (NPR %s → NPR %s).',
                    number_format($adj_amount, 0), number_format($old_price, 0), number_format($new_price, 0)
                )];
            } elseif ($adj_direction === 'credit') {
                $flash = ['type' => 'success', 'msg' => sprintf(
                    'Transfer approved. Student is owed NPR %s refund (NPR %s → NPR %s).',
                    number_format($adj_amount, 0), number_format($old_price, 0), number_format($new_price, 0)
                )];
            } else {
                $flash = ['type' => 'success', 'msg' => 'Room transfer approved successfully.'];
            }

        } elseif ($action === 'reject') {

            $pdo->prepare(
                "UPDATE room_transfers
                 SET status = 'rejected', decided_by = ?, decided_at = NOW()
                 WHERE id = ?"
            )->execute([$uid, $transfer_id]);

            $flash = ['type' => 'success', 'msg' => 'Room transfer request rejected.'];

        } else {
            throw new Exception('Unknown action.');
        }

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['type' => 'error', 'msg' => $ex->getMessage()];
    }
}

// ── Load pending transfer requests ───────────────────────────────────────────
$pending = $pdo->query(
    "SELECT rt.id, rt.student_id, rt.from_room_id, rt.to_room_id,
            rt.reason, rt.created_at,
            u.name AS student_name, u.email,
            rf.room_number  AS from_room_number,
            rt2.room_number AS to_room_number,
            rt2.status      AS to_status,
            rt2.room_type   AS to_room_type,
            rt2.floor       AS to_floor
     FROM room_transfers rt
     JOIN users u ON u.id = rt.student_id
     LEFT JOIN rooms rf  ON rf.id  = rt.from_room_id
     LEFT JOIN rooms rt2 ON rt2.id = rt.to_room_id
     WHERE rt.status = 'pending'
     ORDER BY rt.created_at ASC"
)->fetchAll();

// ── Load recently processed transfers (last 20) ──────────────────────────────
$recent = $pdo->query(
    "SELECT rt.id, rt.status, rt.decided_at, rt.created_at,
            u.id AS student_id, u.name AS student_name,
            rf.room_number  AS from_room_number,
            rt2.room_number AS to_room_number
     FROM room_transfers rt
     JOIN users u ON u.id = rt.student_id
     LEFT JOIN rooms rf  ON rf.id  = rt.from_room_id
     LEFT JOIN rooms rt2 ON rt2.id = rt.to_room_id
     WHERE rt.status IN ('approved','rejected')
     ORDER BY rt.decided_at DESC
     LIMIT 20"
)->fetchAll();

$pending_count = count($pending);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Transfers — HMS Warden</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .page-title { font-size: 1.5rem; font-weight: 800; color: var(--text); margin-bottom: 0.25rem; letter-spacing: -0.02em; }
        .page-sub   { font-size: 0.875rem; color: var(--muted); margin-bottom: 1.5rem; }

        /* Stats grid */
        .rt-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.75rem; }
        .rt-stat { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); padding: 1.1rem 1.2rem; display: flex; align-items: center; gap: 0.85rem; transition: all 0.15s ease; }
        .rt-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
        .rt-stat-icon { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .rt-stat-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; }
        .rt-stat-value { font-size: 1.6rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1; }
        .rt-stat-label { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; color: var(--muted); text-transform: uppercase; margin-top: 5px; }
        @media (max-width: 900px) { .rt-stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .rt-stats-grid { grid-template-columns: 1fr; } }

        /* Two-column layout for pending + tips */
        .rt-layout { display: grid; grid-template-columns: 1fr 280px; gap: 1.5rem; margin-bottom: 1.75rem; }
        @media (max-width: 1100px) { .rt-layout { grid-template-columns: 1fr; } }

        .rt-card { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); overflow: hidden; }
        .rt-card-head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem 0.85rem; border-bottom: 1px solid var(--border); gap: 0.75rem; }
        .rt-card-head h2 { font-size: 0.95rem; font-weight: 700; color: var(--text); margin: 0; }
        .rt-card-head .pill-count { font-size: 0.72rem; font-weight: 800; padding: 0.2rem 0.55rem; border-radius: 999px; background: rgba(99,102,241,0.12); color: var(--primary); }
        .rt-card-body { padding: 1rem 1.25rem 1.25rem; }

        /* Transfer card (each pending row) */
        .transfer-card { border: 1.5px solid var(--border); border-radius: 12px; padding: 1.15rem 1.3rem; margin-bottom: 0.85rem; transition: all 0.15s ease; background: #fff; }
        .transfer-card:hover { border-color: rgba(99,102,241,0.35); box-shadow: 0 4px 14px rgba(15,23,42,0.05); }
        .transfer-card:last-child { margin-bottom: 0; }
        .transfer-card-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.9rem; }
        .tc-user { display: flex; align-items: center; gap: 0.7rem; }
        .tc-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(99,102,241,0.25); }
        .student-name { font-size: 0.95rem; font-weight: 700; color: var(--text); line-height: 1.2; }
        .student-meta { font-size: 0.78rem; color: var(--muted); margin-top: 2px; }

        .transfer-route { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.95rem; flex-wrap: wrap; }
        .room-chip { display: inline-flex; align-items: center; gap: 0.4rem; background: var(--panel-alt); border: 1.5px solid var(--border); border-radius: 9px; padding: 0.45rem 0.85rem; font-size: 0.86rem; font-weight: 700; color: var(--text); }
        .room-chip svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; }
        .room-chip.to-chip { background: rgba(99,102,241,0.08); border-color: rgba(99,102,241,0.3); color: var(--primary); }
        .arrow-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--panel-alt); color: var(--muted); }
        .arrow-icon svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        .warn-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #fef3c7; color: #b45309; }
        .warn-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        .transfer-reason {
            font-size: 0.85rem; color: var(--text);
            background: var(--panel-alt);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem 0.95rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        html[data-theme="dark"] .transfer-reason { background: rgba(255,255,255,0.03); }
        .transfer-reason strong { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; display: block; margin-bottom: 3px; font-weight: 700; }

        .action-row { display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; padding-top: 0.65rem; border-top: 1px dashed var(--border); }
        .btn-approve { display: inline-flex; align-items: center; gap: 0.4rem; background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; border-radius: 9px; padding: 0.55rem 1.15rem; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.12s ease; box-shadow: 0 2px 8px rgba(16,185,129,0.25); }
        .btn-approve:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.35); }
        .btn-approve svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .btn-reject { display: inline-flex; align-items: center; gap: 0.4rem; background: #fff; color: #dc2626; border: 1.5px solid #fecaca; border-radius: 9px; padding: 0.55rem 1.15rem; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.12s ease; }
        .btn-reject:hover { background: #fee2e2; }
        .btn-reject svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        /* Empty state */
        .empty-state { text-align: center; padding: 3rem 1rem 2rem; color: var(--muted); }
        .empty-icon { width: 70px; height: 70px; margin: 0 auto 1rem; border-radius: 50%; background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(99,102,241,0.02)); display: flex; align-items: center; justify-content: center; }
        .empty-icon svg { width: 30px; height: 30px; stroke: var(--primary); fill: none; stroke-width: 1.75; opacity: 0.75; }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 0.35rem; }
        .empty-state p { font-size: 0.84rem; color: var(--muted); max-width: 360px; margin: 0 auto; line-height: 1.5; }

        /* Tips card */
        .tips-card { padding: 1.1rem 1.25rem; }
        .tips-card h3 { font-size: 0.92rem; font-weight: 700; color: var(--text); margin-bottom: 0.25rem; }
        .tips-card .tips-sub { font-size: 0.78rem; color: var(--muted); margin-bottom: 1rem; }
        .tip-item { display: flex; gap: 0.7rem; margin-bottom: 0.85rem; }
        .tip-item:last-child { margin-bottom: 0; }
        .tip-num { width: 22px; height: 22px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); font-size: 0.72rem; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tip-body { font-size: 0.8rem; color: var(--text); line-height: 1.45; }
        .tip-body b { font-weight: 700; }
        .tip-body span { display: block; color: var(--muted); font-size: 0.74rem; margin-top: 2px; }

        /* Recent table */
        .recent-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.875rem; }
        .recent-table thead th { background: var(--panel-alt); padding: 0.7rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.07em; color: var(--muted); text-transform: uppercase; border-bottom: 1.5px solid var(--border); }
        .recent-table tbody td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .recent-table tbody tr { transition: background 0.12s ease; }
        .recent-table tbody tr:hover { background: rgba(99,102,241,0.04); }
        .recent-table tbody tr:nth-child(even) { background: rgba(15,23,42,0.015); }
        .recent-table tbody tr:nth-child(even):hover { background: rgba(99,102,241,0.05); }
        .recent-table tbody tr:last-child td { border-bottom: none; }
        .recent-table .rt-cell-user { display: flex; align-items: center; gap: 0.6rem; }
        .recent-table .rt-mini-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .recent-table .rt-room { font-weight: 700; color: var(--text); }
        .recent-table .rt-arrow { color: var(--muted); margin: 0 0.3rem; }
        .recent-table .date-col { color: var(--muted); font-size: 0.8rem; }

        .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: capitalize; }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-approved { background: rgba(16,185,129,0.12); color: #059669; }
        .badge-rejected { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .badge-pending  { background: rgba(245,158,11,0.12); color: #b45309; }
    </style>
</head>
<body>
<?= render_sidebar('room_transfers.php') ?>
<?= render_topbar() ?>

<div class="container">

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.25rem;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <h1 class="page-title">Room Transfer Requests</h1>
    <div class="page-sub">Review and decide on student-submitted room transfer requests.</div>

    <?php
    // Compute stat counts
    $approved_total = 0; $rejected_total = 0;
    foreach ($recent as $rr) {
        if ($rr['status'] === 'approved') $approved_total++;
        if ($rr['status'] === 'rejected') $rejected_total++;
    }
    ?>

    <!-- Stats -->
    <div class="rt-stats-grid">
        <div class="rt-stat">
            <div class="rt-stat-icon" style="background:rgba(245,158,11,0.1);color:#d97706;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="rt-stat-value"><?= $pending_count ?></div>
                <div class="rt-stat-label">Pending</div>
            </div>
        </div>
        <div class="rt-stat">
            <div class="rt-stat-icon" style="background:rgba(16,185,129,0.1);color:#059669;">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div class="rt-stat-value"><?= $approved_total ?></div>
                <div class="rt-stat-label">Approved</div>
            </div>
        </div>
        <div class="rt-stat">
            <div class="rt-stat-icon" style="background:rgba(239,68,68,0.1);color:#dc2626;">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <div>
                <div class="rt-stat-value"><?= $rejected_total ?></div>
                <div class="rt-stat-label">Rejected</div>
            </div>
        </div>
        <div class="rt-stat">
            <div class="rt-stat-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            </div>
            <div>
                <div class="rt-stat-value"><?= count($recent) ?></div>
                <div class="rt-stat-label">Processed</div>
            </div>
        </div>
    </div>

    <!-- Pending + Tips -->
    <div class="rt-layout">
        <div class="rt-card">
            <div class="rt-card-head">
                <h2>Pending Approval</h2>
                <span class="pill-count"><?= $pending_count ?> waiting</span>
            </div>
            <div class="rt-card-body">
                <?php if (empty($pending)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h3>All caught up</h3>
                        <p>There are no pending room transfer requests right now. Student-submitted requests will appear here for your review.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending as $t):
                        $to_unavailable = ($t['to_status'] !== 'available');
                        $parts = array_values(array_filter(explode(' ', trim($t['student_name']))));
                        $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
                    ?>
                        <div class="transfer-card">
                            <div class="transfer-card-header">
                                <a href="../auth/profile.php?id=<?= (int)$t['student_id'] ?>" class="tc-user" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.75rem;" title="View profile">
                                    <div class="tc-avatar"><?= e($initials) ?></div>
                                    <div>
                                        <div class="student-name"><?= e($t['student_name']) ?></div>
                                        <div class="student-meta"><?= e($t['email']) ?></div>
                                    </div>
                                </a>
                                <div style="font-size:0.76rem;color:var(--muted);text-align:right;">
                                    Requested<br><strong style="color:var(--text);font-weight:700;"><?= e(date('d M Y, g:i A', strtotime($t['created_at']))) ?></strong>
                                </div>
                            </div>

                            <div class="transfer-route">
                                <span class="room-chip">
                                    <svg viewBox="0 0 24 24"><path d="M3 6a2 2 0 0 0 0 4h18a2 2 0 0 0 0-4H3z"/><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10"/></svg>
                                    From: <?= $t['from_room_number'] ? 'Room ' . e($t['from_room_number']) : 'No room' ?>
                                </span>
                                <span class="arrow-icon">
                                    <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </span>
                                <span class="room-chip to-chip">
                                    <svg viewBox="0 0 24 24"><path d="M3 6a2 2 0 0 0 0 4h18a2 2 0 0 0 0-4H3z"/><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10"/></svg>
                                    To: Room <?= e($t['to_room_number'] ?? 'N/A') ?>
                                    <?php if ($t['to_room_type']): ?>&middot; <?= e($t['to_room_type']) ?><?php endif; ?>
                                    <?php if ($t['to_floor']): ?>&middot; F<?= e($t['to_floor']) ?><?php endif; ?>
                                </span>
                                <?php if ($to_unavailable): ?>
                                    <span class="warn-badge">Target is <?= e($t['to_status'] ?? 'unavailable') ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($t['reason']): ?>
                                <div class="transfer-reason">
                                    <strong>Reason</strong>
                                    <?= e($t['reason']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="action-row">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token"   value="<?= csrf_token() ?>">
                                    <input type="hidden" name="transfer_id"  value="<?= (int)$t['id'] ?>">
                                    <input type="hidden" name="action"       value="reject">
                                    <button type="submit" class="btn-reject"
                                            data-confirm="Reject transfer request for <?= e($t['student_name']) ?>?">
                                        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Reject
                                    </button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token"   value="<?= csrf_token() ?>">
                                    <input type="hidden" name="transfer_id"  value="<?= (int)$t['id'] ?>">
                                    <input type="hidden" name="action"       value="approve">
                                    <button type="submit" class="btn-approve"
                                            data-confirm="Approve room transfer for <?= e($t['student_name']) ?>? This will move the student from room <?= e($t['from_room_number'] ?? 'none') ?> to room <?= e($t['to_room_number'] ?? 'N/A') ?>.">
                                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                        Approve
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tips card -->
        <div class="rt-card tips-card">
            <h3>How transfers work</h3>
            <div class="tips-sub">Quick reference for reviewing requests.</div>
            <div class="tip-item">
                <div class="tip-num">1</div>
                <div class="tip-body">
                    <b>Check availability</b>
                    <span>Target rooms marked as occupied or maintenance cannot be approved.</span>
                </div>
            </div>
            <div class="tip-item">
                <div class="tip-num">2</div>
                <div class="tip-body">
                    <b>Read the reason</b>
                    <span>Students give a short explanation. Look for valid concerns like noise, floor or roommate fit.</span>
                </div>
            </div>
            <div class="tip-item">
                <div class="tip-num">3</div>
                <div class="tip-body">
                    <b>Approve to apply</b>
                    <span>On approval, the student's room number updates instantly and their booking is re-issued.</span>
                </div>
            </div>
            <div class="tip-item">
                <div class="tip-num">4</div>
                <div class="tip-body">
                    <b>Need to act yourself?</b>
                    <span>You can also initiate a transfer from <a href="list_student.php" style="color:var(--primary);font-weight:700;">Student List</a>.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recently processed -->
    <div class="rt-card">
        <div class="rt-card-head">
            <h2>Recently Processed</h2>
            <span class="pill-count" style="background:rgba(100,116,139,0.12);color:#475569;"><?= count($recent) ?> records</span>
        </div>

        <?php if (empty($recent)): ?>
            <div class="empty-state" style="padding:2.5rem 1rem;">
                <div class="empty-icon">
                    <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </div>
                <h3>No history yet</h3>
                <p>Approved and rejected transfers will be listed here.</p>
            </div>
        <?php else: ?>
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Route</th>
                        <th>Status</th>
                        <th>Decided</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r):
                        $parts = array_values(array_filter(explode(' ', trim($r['student_name']))));
                        $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
                    ?>
                        <tr>
                            <td>
                                <a href="../auth/profile.php?id=<?= (int)$r['student_id'] ?>" class="rt-cell-user" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.6rem;" title="View profile">
                                    <div class="rt-mini-avatar"><?= e($initials) ?></div>
                                    <div>
                                        <div style="font-weight:600;color:var(--text);"><?= e($r['student_name']) ?></div>
                                        <div style="color:var(--muted);font-size:0.74rem;">#<?= (int)$r['id'] ?></div>
                                    </div>
                                </a>
                            </td>
                            <td>
                                <span class="rt-room"><?= e($r['from_room_number'] ?? '—') ?></span>
                                <span class="rt-arrow">→</span>
                                <span class="rt-room"><?= e($r['to_room_number'] ?? '—') ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span>
                            </td>
                            <td class="date-col">
                                <?= $r['decided_at'] ? e(date('d M Y', strtotime($r['decided_at']))) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<?= render_footer() ?>

<script src="../js/script.js"></script>
</body>
</html>
