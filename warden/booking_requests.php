<?php
// ─────────────────────────────────────────────────
// warden/booking_requests.php — Booking request approval
//   Warden reviews pending room booking requests and can approve or reject them.
//   Approval creates a monthly_fees row and sends a confirmation email to the student.
// ─────────────────────────────────────────────────

require_once '../db.php';
require_once '../auth/mailer.php';
require_role('warden');

$uid   = (int)$_SESSION['user_id'];
$flash = ['type' => '', 'msg' => ''];

// ── Cleanup stray room assignments (runs every load — idempotent + cheap) ──
// Clears users.room_id that doesn't have an approved/active booking behind
// it. Solves the bug where migration piled multiple students into one room.
try {
    $pdo->exec(
        "UPDATE users u
         SET u.room_id = NULL, u.room_status = NULL
         WHERE u.role = 'student'
           AND u.room_id IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM bookings b
               WHERE b.student_id = u.id AND b.room_id = u.room_id
                 AND b.status IN ('approved','active')
           )"
    );
} catch (Exception $e) { /* best-effort cleanup */ }

// Migrate legacy users.room_status='pending' entries into bookings.
// Idempotent. Runs at most once per session to keep page loads fast.
// Force a re-run by visiting ?remigrate=1.
$session_migrated = $_SESSION['legacy_rooms_migrated'] ?? null;
$force_remigrate  = isset($_GET['remigrate']);
if (!$session_migrated || $force_remigrate) try {
    // (1) Already have room_id, just create booking
    //     Skip if ANY booking already exists for this student+room — so
    //     a rejected/cancelled booking is NOT recreated by the migration.
    $legacy_stmt = $pdo->query(
        "SELECT u.id AS sid, u.room_id
         FROM users u
         WHERE u.role = 'student'
           AND u.room_status = 'pending'
           AND u.room_id IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM bookings b
               WHERE b.student_id = u.id AND b.room_id = u.room_id
           )"
    );
    $ins_assigned = $pdo->prepare(
        "INSERT INTO bookings (student_id, room_id, start_date, status, notes)
         VALUES (?, ?, CURDATE(), 'pending_approval', 'Migrated from legacy room request')"
    );
    foreach ($legacy_stmt->fetchAll() as $row) {
        $ins_assigned->execute([(int)$row['sid'], (int)$row['room_id']]);
    }

    // (2) No room_id yet — assign an available room (prefer preferred type)
    //     Skip if any booking exists at all so rejections aren't undone.
    $unassigned = $pdo->query(
        "SELECT u.id AS sid, u.room_preference
         FROM users u
         WHERE u.role = 'student'
           AND u.room_status = 'pending'
           AND u.room_id IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM bookings b WHERE b.student_id = u.id
           )"
    );
    // Only suggest rooms that aren't already at capacity
    $find_pref = $pdo->prepare(
        "SELECT r.id FROM rooms r
         WHERE r.status = 'available' AND r.room_type = ?
           AND (SELECT COUNT(*) FROM users u2 WHERE u2.room_id = r.id) < r.capacity
         ORDER BY r.id ASC LIMIT 1"
    );
    $find_any = $pdo->prepare(
        "SELECT r.id FROM rooms r
         WHERE r.status = 'available'
           AND (SELECT COUNT(*) FROM users u2 WHERE u2.room_id = r.id) < r.capacity
         ORDER BY r.id ASC LIMIT 1"
    );
    $set_room = $pdo->prepare("UPDATE users SET room_id = ? WHERE id = ?");
    $ins_new  = $pdo->prepare(
        "INSERT INTO bookings (student_id, room_id, start_date, status, notes)
         VALUES (?, ?, CURDATE(), 'pending_approval', 'Migrated from legacy room request — room auto-suggested, please review')"
    );
    foreach ($unassigned->fetchAll() as $row) {
        $sid = (int)$row['sid'];
        $pref = trim((string)($row['room_preference'] ?? ''));
        $room_id = null;
        if ($pref !== '') {
            $find_pref->execute([$pref]);
            $rid = $find_pref->fetchColumn();
            if ($rid !== false) $room_id = (int)$rid;
        }
        if ($room_id === null) {
            $find_any->execute();
            $rid = $find_any->fetchColumn();
            if ($rid !== false) $room_id = (int)$rid;
        }
        if ($room_id !== null) {
            $set_room->execute([$room_id, $sid]);
            $ins_new->execute([$sid, $room_id]);
        }
    }
    $_SESSION['legacy_rooms_migrated'] = time();
} catch (Exception $e) { /* migration is best-effort */ }

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');

        $action     = $_POST['action']     ?? '';
        $booking_id = (int)($_POST['booking_id'] ?? 0);

        if (!$booking_id) {
            throw new Exception('Invalid booking ID.');
        }

        // Fetch booking details
        $bstmt = $pdo->prepare(
            'SELECT b.id, b.student_id, b.room_id, b.status,
                    r.capacity, r.price, r.room_number, r.room_type,
                    u.name AS student_name, u.email AS student_email
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             JOIN users u ON u.id = b.student_id
             WHERE b.id = ? LIMIT 1'
        );
        $bstmt->execute([$booking_id]);
        $booking = $bstmt->fetch();

        if (!$booking) {
            throw new Exception('Booking not found.');
        }
        if ($booking['status'] !== 'pending_approval') {
            throw new Exception('This booking is no longer pending.');
        }

        if ($action === 'approve') {
            // Check current occupancy (include the booking being approved)
            $occ_stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings b2
                 WHERE b2.room_id = ?
                   AND b2.status IN ('pending_approval','approved','active')"
            );
            $occ_stmt->execute([$booking['room_id']]);
            $occupancy = (int)$occ_stmt->fetchColumn();
            $capacity  = (int)$booking['capacity'];

            if ($occupancy > $capacity) {
                throw new Exception('Room is already at full capacity. Cannot approve.');
            }

            $pdo->beginTransaction();

            // Approve booking
            $pdo->prepare(
                "UPDATE bookings
                 SET status = 'active', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            )->execute([$uid, $booking_id]);

            // Assign room to student
            $pdo->prepare(
                "UPDATE users SET room_id = ?, room_status = 'approved' WHERE id = ?"
            )->execute([$booking['room_id'], $booking['student_id']]);

            // Mark room occupied if now at or above capacity
            $new_occ_stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM bookings
                 WHERE room_id = ? AND status IN ('approved','active')"
            );
            $new_occ_stmt->execute([$booking['room_id']]);
            $new_occ = (int)$new_occ_stmt->fetchColumn();
            if ($new_occ >= $capacity) {
                $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?")
                    ->execute([$booking['room_id']]);
            }

            // Room audit log
            $pdo->prepare(
                'INSERT INTO room_audit_log (room_id, student_id, event, actor_id, notes)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $booking['room_id'],
                $booking['student_id'],
                'booking_approved',
                $uid,
                'Booking #' . $booking_id . ' approved by warden'
            ]);

            // Create monthly fee row for current month
            $billing_month = date('Y-m-01');
            $due_date      = date('Y-m-10');
            $amount        = max(1000, (float)($booking['price'] ?? 3000));

            $pdo->prepare(
                'INSERT IGNORE INTO monthly_fees (student_id, room_id, amount, billing_month, due_date)
                 VALUES (?, ?, COALESCE(?, 3000), ?, ?)'
            )->execute([
                $booking['student_id'],
                $booking['room_id'],
                $amount,
                $billing_month,
                $due_date
            ]);

            $pdo->commit();

            // Send confirmation email (best-effort)
            try {
                $body_html = render_branded_email([
                    'name'      => $booking['student_name'],
                    'kicker'    => 'Booking Approved',
                    'title'     => 'Your room booking has been approved',
                    'intro'     => 'Congratulations! The warden has approved your room booking at HMS Hostel.',
                    'body_html' => '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                                 . '<tr><td style="padding:8px 0;color:#64748b;width:140px;">Room Number</td>'
                                 .     '<td style="padding:8px 0;font-weight:700;">' . htmlspecialchars($booking['room_number']) . '</td></tr>'
                                 . '<tr style="background:#f8fafc;"><td style="padding:8px 6px;color:#64748b;">Room Type</td>'
                                 .     '<td style="padding:8px 6px;">' . htmlspecialchars($booking['room_type']) . '</td></tr>'
                                 . '<tr><td style="padding:8px 0;color:#64748b;">Monthly Fee</td>'
                                 .     '<td style="padding:8px 0;font-weight:800;color:#10b981;">NPR ' . number_format($amount, 0) . '</td></tr>'
                                 . '<tr style="background:#f8fafc;"><td style="padding:8px 6px;color:#64748b;">Fee Due Date</td>'
                                 .     '<td style="padding:8px 6px;">' . date('d M Y', strtotime($due_date)) . '</td></tr>'
                                 . '</table>',
                    'footnote'  => 'Please log in to your dashboard to view your room details and pay your fees.',
                    'accent'    => '#10b981',
                    'accent2'   => '#059669',
                ]);
                send_app_mail(
                    $booking['student_email'],
                    $booking['student_name'],
                    'Room Booking Approved — HMS',
                    $body_html
                );
            } catch (Exception $mail_ex) {
                // Email failure is non-fatal — booking is already approved
            }

            $flash = ['type' => 'success', 'msg' => 'Booking approved and student notified.'];

        } elseif ($action === 'reject') {

            $pdo->prepare(
                "UPDATE bookings SET status = 'rejected', updated_at = NOW() WHERE id = ?"
            )->execute([$booking_id]);

            // Clear the legacy room_status field so the migration doesn't
            // recreate the request on the next session.
            $pdo->prepare(
                "UPDATE users SET room_status = 'rejected' WHERE id = ?"
            )->execute([$booking['student_id']]);

            // Send rejection email (best-effort)
            try {
                $body_html = render_branded_email([
                    'name'      => $booking['student_name'],
                    'kicker'    => 'Booking Update',
                    'title'     => 'Your room booking could not be approved',
                    'intro'     => 'We regret to inform you that your booking request has been declined.',
                    'body_html' => '<p style="color:#475569;">Your booking for room <strong>'
                                 . htmlspecialchars($booking['room_number']) . '</strong> ('
                                 . htmlspecialchars($booking['room_type'])
                                 . ') has been rejected by the warden.</p>'
                                 . '<p style="color:#475569;">Please log in to your dashboard to browse other available rooms or contact the hostel office for assistance.</p>',
                    'footnote'  => 'If you believe this is an error, please contact the warden directly.',
                    'accent'    => '#ef4444',
                    'accent2'   => '#dc2626',
                ]);
                send_app_mail(
                    $booking['student_email'],
                    $booking['student_name'],
                    'Room Booking Update — HMS',
                    $body_html
                );
            } catch (Exception $mail_ex) {
                // Email failure is non-fatal
            }

            $flash = ['type' => 'success', 'msg' => 'Booking rejected.'];

        } else {
            throw new Exception('Unknown action.');
        }

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = ['type' => 'error', 'msg' => $ex->getMessage()];
    }
}

// ── Load pending bookings ─────────────────────────────────────────────────────
$pending = $pdo->query(
    "SELECT b.id, b.created_at, b.start_date, b.notes AS booking_notes,
            r.room_number, r.room_type, r.floor, r.price, r.capacity,
            u.id AS student_id, u.name AS student_name,
            u.email AS student_email, u.student_phone,
            (SELECT COUNT(*) FROM bookings b2
             WHERE b2.room_id = b.room_id
               AND b2.status IN ('pending_approval','approved','active')
            ) AS current_occupancy
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     JOIN users u ON u.id = b.student_id
     WHERE b.status = 'pending_approval'
     ORDER BY b.created_at ASC"
)->fetchAll();

// ── Load recently processed bookings (last 20) ───────────────────────────────
$recent = $pdo->query(
    "SELECT b.id, b.status, b.updated_at,
            r.room_number,
            u.id AS student_id, u.name AS student_name
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     JOIN users u ON u.id = b.student_id
     WHERE b.status IN ('approved','active','rejected','cancelled')
     ORDER BY b.updated_at DESC
     LIMIT 20"
)->fetchAll();

$pending_count = count($pending);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Requests — HMS Warden</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .page-title { font-size: 1.5rem; font-weight: 800; color: var(--text); margin-bottom: 0.25rem; letter-spacing: -0.02em; }
        .page-sub   { font-size: 0.875rem; color: var(--muted); margin: 0; }
        .header-hint { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 0.85rem; background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.18); border-radius: 999px; font-size: 0.78rem; color: var(--primary); font-weight: 600; }
        .header-hint svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }

        .br-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.75rem; }
        .br-stat { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); padding: 1.1rem 1.2rem; display: flex; align-items: center; gap: 0.85rem; transition: all 0.15s ease; }
        .br-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
        .br-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .br-stat-icon svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
        .br-stat-value { font-size: 1.6rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1; }
        .br-stat-label { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; color: var(--muted); text-transform: uppercase; margin-top: 5px; }
        @media (max-width: 900px) { .br-stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .br-stats-grid { grid-template-columns: 1fr; } }

        .br-layout { display: grid; grid-template-columns: 1fr 280px; gap: 1.5rem; margin-bottom: 1.75rem; }
        @media (max-width: 1100px) { .br-layout { grid-template-columns: 1fr; } }

        .br-card { background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-soft); overflow: hidden; }
        .br-card-head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem 0.85rem; border-bottom: 1px solid var(--border); gap: 0.75rem; }
        .br-card-head h2 { font-size: 0.95rem; font-weight: 700; color: var(--text); margin: 0; }
        .br-card-head .pill-count { font-size: 0.72rem; font-weight: 800; padding: 0.2rem 0.55rem; border-radius: 999px; background: rgba(99,102,241,0.12); color: var(--primary); }
        .br-card-body { padding: 1rem 1.25rem 1.25rem; }

        .booking-card { border: 1.5px solid var(--border); border-radius: 12px; padding: 1.15rem 1.3rem; margin-bottom: 0.85rem; transition: all 0.15s ease; background: #fff; }
        .booking-card:hover { border-color: rgba(99,102,241,0.35); box-shadow: 0 4px 14px rgba(15,23,42,0.05); }
        .booking-card:last-child { margin-bottom: 0; }
        .booking-card-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.9rem; }
        .bc-user { display: flex; align-items: center; gap: 0.7rem; }
        .bc-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(99,102,241,0.25); }
        .student-name { font-size: 0.95rem; font-weight: 700; color: var(--text); line-height: 1.2; }
        .student-meta { font-size: 0.78rem; color: var(--muted); margin-top: 2px; }

        .booking-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 0.95rem; background: var(--panel-alt); border-radius: 9px; padding: 0.85rem 1rem; }
        @media (max-width: 600px) { .booking-details { grid-template-columns: repeat(2, 1fr); } }
        .detail-item .detail-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted); }
        .detail-item .detail-value { font-size: 0.92rem; font-weight: 700; color: var(--text); margin-top: 4px; letter-spacing: -0.01em; }

        .capacity-badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.28rem 0.75rem;
            border-radius: 999px;
            font-size: 0.72rem; font-weight: 800;
            border: 1.5px solid transparent;
            letter-spacing: 0.01em;
        }
        .capacity-badge::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .capacity-badge.ok {
            background: rgba(16,185,129,0.1);
            color: #047857;
            border-color: rgba(16,185,129,0.28);
        }
        .capacity-badge.full {
            background: #fef2f2;
            color: #b91c1c;
            border-color: rgba(239,68,68,0.32);
        }
        html[data-theme="dark"] .capacity-badge.ok {
            background: rgba(52,211,153,0.14);
            color: #34d399;
            border-color: rgba(52,211,153,0.3);
        }
        html[data-theme="dark"] .capacity-badge.full {
            background: rgba(248,113,113,0.14);
            color: #fca5a5;
            border-color: rgba(248,113,113,0.32);
        }

        .booking-notes {
            font-size: 0.85rem; color: var(--text);
            background: var(--panel-alt);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem 0.95rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        html[data-theme="dark"] .booking-notes { background: rgba(255,255,255,0.03); }
        .booking-notes strong { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; display: block; margin-bottom: 3px; font-weight: 700; }

        .action-row { display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; padding-top: 0.65rem; border-top: 1px dashed var(--border); }
        .btn-approve { display: inline-flex; align-items: center; gap: 0.4rem; background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; border-radius: 9px; padding: 0.55rem 1.15rem; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.12s ease; box-shadow: 0 2px 8px rgba(16,185,129,0.25); }
        .btn-approve:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.35); }
        .btn-approve:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }
        .btn-approve svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .btn-reject { display: inline-flex; align-items: center; gap: 0.4rem; background: #fff; color: #dc2626; border: 1.5px solid #fecaca; border-radius: 9px; padding: 0.55rem 1.15rem; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.12s ease; }
        .btn-reject:hover { background: #fee2e2; }
        .btn-reject svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        .empty-state { text-align: center; padding: 3rem 1rem 2rem; color: var(--muted); }
        .empty-icon { width: 70px; height: 70px; margin: 0 auto 1rem; border-radius: 50%; background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(99,102,241,0.02)); display: flex; align-items: center; justify-content: center; }
        .empty-icon svg { width: 30px; height: 30px; stroke: var(--primary); fill: none; stroke-width: 1.75; opacity: 0.75; }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 0.35rem; }
        .empty-state p { font-size: 0.84rem; color: var(--muted); max-width: 360px; margin: 0 auto; line-height: 1.5; }

        .tips-card { padding: 1.1rem 1.25rem; }
        .tips-card h3 { font-size: 0.92rem; font-weight: 700; color: var(--text); margin-bottom: 0.25rem; }
        .tips-card .tips-sub { font-size: 0.78rem; color: var(--muted); margin-bottom: 1rem; }
        .tip-item { display: flex; gap: 0.7rem; margin-bottom: 0.85rem; }
        .tip-item:last-child { margin-bottom: 0; }
        .tip-num { width: 22px; height: 22px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); font-size: 0.72rem; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tip-body { font-size: 0.8rem; color: var(--text); line-height: 1.45; }
        .tip-body b { font-weight: 700; }
        .tip-body span { display: block; color: var(--muted); font-size: 0.74rem; margin-top: 2px; }

        .recent-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.875rem; }
        .recent-table thead th { background: var(--panel-alt); padding: 0.7rem 1rem; text-align: left; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.07em; color: var(--muted); text-transform: uppercase; border-bottom: 1.5px solid var(--border); }
        .recent-table tbody td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .recent-table tbody tr { transition: background 0.12s ease; }
        .recent-table tbody tr:hover { background: rgba(99,102,241,0.04); }
        .recent-table tbody tr:nth-child(even) { background: rgba(15,23,42,0.015); }
        .recent-table tbody tr:nth-child(even):hover { background: rgba(99,102,241,0.05); }
        .recent-table tbody tr:last-child td { border-bottom: none; }
        .br-cell-user { display: flex; align-items: center; gap: 0.6rem; }
        .br-mini-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: 0.72rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .br-room-num { font-weight: 700; color: var(--text); }
        .date-col { color: var(--muted); font-size: 0.8rem; }

        .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: capitalize; }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-active { background: rgba(16,185,129,0.12); color: #059669; }
        .badge-approved { background: rgba(99,102,241,0.12); color: #4f46e5; }
        .badge-rejected { background: rgba(239,68,68,0.12); color: #dc2626; }
        .badge-cancelled { background: rgba(100,116,139,0.15); color: #64748b; }
    </style>
</head>
<body>
<?= render_sidebar('booking_requests.php') ?>
<?= render_topbar() ?>

<div class="container">

    <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1.25rem;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php
    // Compute richer stats from $recent
    $stat_approved  = 0;
    $stat_rejected  = 0;
    $stat_cancelled = 0;
    foreach ($recent as $rr) {
        if ($rr['status'] === 'approved' || $rr['status'] === 'active') $stat_approved++;
        elseif ($rr['status'] === 'rejected')  $stat_rejected++;
        elseif ($rr['status'] === 'cancelled') $stat_cancelled++;
    }
    ?>

    <div class="page-header">
        <div>
            <h1 class="page-title">Booking Requests</h1>
            <p class="page-sub">Review students' room booking requests and approve or decline them.</p>
        </div>
        <?php if ($pending_count > 0): ?>
        <span class="header-hint">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= $pending_count ?> awaiting your review
        </span>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="br-stats-grid">
        <div class="br-stat">
            <div class="br-stat-icon" style="background: rgba(245,158,11,0.12); color: #d97706;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="br-stat-value"><?= $pending_count ?></div>
                <div class="br-stat-label">Pending Requests</div>
            </div>
        </div>
        <div class="br-stat">
            <div class="br-stat-icon" style="background: rgba(16,185,129,0.12); color: #059669;">
                <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="br-stat-value"><?= $stat_approved ?></div>
                <div class="br-stat-label">Recently Approved</div>
            </div>
        </div>
        <div class="br-stat">
            <div class="br-stat-icon" style="background: rgba(239,68,68,0.12); color: #dc2626;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="br-stat-value"><?= $stat_rejected ?></div>
                <div class="br-stat-label">Rejected</div>
            </div>
        </div>
        <div class="br-stat">
            <div class="br-stat-icon" style="background: rgba(100,116,139,0.15); color: #475569;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            </div>
            <div>
                <div class="br-stat-value"><?= $stat_cancelled ?></div>
                <div class="br-stat-label">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Pending bookings -->
    <div class="br-card" style="margin-bottom: 1.75rem;">
        <div class="br-card-head">
            <h2>Pending Approval</h2>
            <span class="pill-count"><?= $pending_count ?></span>
        </div>
        <div class="br-card-body">
            <?php if (empty($pending)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <h3>All caught up!</h3>
                    <p>No pending booking requests right now. New requests from students will show up here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending as $b): ?>
                    <?php
                    $cap     = (int)$b['capacity'];
                    $occ     = (int)$b['current_occupancy'];
                    $is_full = $occ > $cap;
                    $initials = strtoupper(substr(trim($b['student_name']), 0, 2));
                    ?>
                    <div class="booking-card">
                        <div class="booking-card-header">
                            <a href="../auth/profile.php?id=<?= (int)$b['student_id'] ?>" class="bc-user" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.75rem;" title="View profile">
                                <div class="bc-avatar"><?= e($initials) ?></div>
                                <div>
                                    <div class="student-name"><?= e($b['student_name']) ?></div>
                                    <div class="student-meta">
                                        <?= e($b['student_email']) ?>
                                        <?php if ($b['student_phone']): ?> &bull; <?= e($b['student_phone']) ?><?php endif; ?>
                                    </div>
                                </div>
                            </a>
                            <div style="font-size:0.78rem;color:var(--muted);text-align:right;">
                                Requested<br>
                                <span style="color:var(--text);font-weight:600;">
                                    <?= e(date('d M Y, g:i A', strtotime($b['created_at']))) ?>
                                </span>
                            </div>
                        </div>

                        <div class="booking-details">
                            <div class="detail-item">
                                <div class="detail-label">Room</div>
                                <div class="detail-value"><?= e($b['room_number']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Type</div>
                                <div class="detail-value"><?= e($b['room_type']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Floor</div>
                                <div class="detail-value"><?= e($b['floor']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Monthly Fee</div>
                                <div class="detail-value">NPR <?= number_format((float)$b['price'], 0) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Start Date</div>
                                <div class="detail-value">
                                    <?= e($b['start_date'] ? date('d M Y', strtotime($b['start_date'])) : 'Not specified') ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Occupancy</div>
                                <div class="detail-value">
                                    <span class="capacity-badge <?= $is_full ? 'full' : 'ok' ?>">
                                        <?= $occ ?> / <?= $cap ?><?= $is_full ? ' &mdash; FULL' : '' ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($b['booking_notes']): ?>
                            <div class="booking-notes">
                                <strong>Student note</strong>
                                <?= e($b['booking_notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="action-row">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                <input type="hidden" name="action"     value="reject">
                                <button type="submit" class="btn-reject"
                                        data-confirm="Reject booking for <?= e($b['student_name']) ?>?">
                                    <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    Reject
                                </button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                <input type="hidden" name="action"     value="approve">
                                <button type="submit" class="btn-approve"
                                        <?= $is_full ? 'disabled title="Room is at full capacity"' : '' ?>>
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

    <!-- Recently processed -->
    <div class="br-card">
        <div class="br-card-head">
            <h2>Recently Processed</h2>
            <span class="pill-count" style="background: rgba(100,116,139,0.12); color: #475569;"><?= count($recent) ?></span>
        </div>
        <?php if (empty($recent)): ?>
            <div class="br-card-body">
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <h3>Nothing here yet</h3>
                    <p>Booking decisions you make will appear here for quick reference.</p>
                </div>
            </div>
        <?php else: ?>
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <?php
                        $bc = 'badge-approved';
                        if ($r['status'] === 'active')    $bc = 'badge-active';
                        if ($r['status'] === 'rejected')  $bc = 'badge-rejected';
                        if ($r['status'] === 'cancelled') $bc = 'badge-cancelled';
                        $r_initials = strtoupper(substr(trim($r['student_name']), 0, 2));
                        ?>
                        <tr>
                            <td style="color:var(--muted);font-weight:600;">#<?= (int)$r['id'] ?></td>
                            <td>
                                <a href="../auth/profile.php?id=<?= (int)$r['student_id'] ?>" class="br-cell-user" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:0.6rem;" title="View profile">
                                    <div class="br-mini-avatar"><?= e($r_initials) ?></div>
                                    <span><?= e($r['student_name']) ?></span>
                                </a>
                            </td>
                            <td><span class="br-room-num"><?= e($r['room_number']) ?></span></td>
                            <td><span class="badge <?= $bc ?>"><?= e($r['status']) ?></span></td>
                            <td class="date-col"><?= e(date('d M Y', strtotime($r['updated_at']))) ?></td>
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
