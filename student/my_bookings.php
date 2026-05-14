<?php
require_once '../db.php';
require_role('student');

$uid     = (int)$_SESSION['user_id'];
$success = $error = '';
if (isset($_GET['msg'])) $success = trim($_GET['msg']);
if (isset($_GET['err'])) $error   = trim($_GET['err']);

// Handle booking cancellation (student can cancel a pending_approval booking)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $bid = (int)($_POST['booking_id'] ?? 0);
        if (!$bid) throw new Exception('Invalid booking.');

        // Confirm the booking belongs to this student and is still pending
        $chk = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ? AND student_id = ? LIMIT 1");
        $chk->execute([$bid, $uid]);
        $row = $chk->fetch();
        if (!$row) throw new Exception('Booking not found.');
        if ($row['status'] !== 'pending_approval') throw new Exception('Only pending bookings can be cancelled.');

        $pdo->prepare("UPDATE bookings SET status='cancelled', updated_at=NOW() WHERE id = ? AND student_id = ?")
            ->execute([$bid, $uid]);

        header('Location: my_bookings.php?msg=' . urlencode('Booking #' . $bid . ' has been cancelled.'));
        exit;
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

$stmt = $pdo->prepare(
    "SELECT b.id, b.status, b.start_date, b.created_at, b.notes AS booking_notes,
            r.room_number, r.room_type, r.floor, r.price
     FROM bookings b
     JOIN rooms r ON r.id = b.room_id
     WHERE b.student_id = ?
     ORDER BY b.created_at DESC"
);
$stmt->execute([$uid]);
$bookings = $stmt->fetchAll();

// ── Stats for the dashboard header ──────────────────────────────────────────
$stats_total      = count($bookings);
$stats_active     = 0;
$stats_pending    = 0;
$stats_cancelled  = 0;
$stats_current    = null;
foreach ($bookings as $b) {
    if (in_array($b['status'], ['active','approved'], true)) {
        $stats_active++;
        if (!$stats_current) $stats_current = $b;
    } elseif ($b['status'] === 'pending_approval')                                $stats_pending++;
    elseif (in_array($b['status'], ['cancelled','rejected'], true))                $stats_cancelled++;
}

// Days since first booking
$stats_days_at_hms = 0;
if ($bookings) {
    $first = end($bookings);
    $stats_days_at_hms = max(0, (int)floor((time() - strtotime($first['created_at'])) / 86400));
}

$status_label = [
    'pending_approval' => 'Pending Approval',
    'approved'         => 'Approved',
    'active'           => 'Active',
    'rejected'         => 'Rejected',
    'cancelled'        => 'Cancelled',
];
$status_badge = [
    'pending_approval' => 'badge-pending',
    'approved'         => 'badge-green',
    'active'           => 'badge-green',
    'rejected'         => 'badge-red',
    'cancelled'        => 'badge-red',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>My Bookings - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .bookings-list { display:grid; gap:0.875rem; }

        /* Cleaner card - no icon box, better use of space */
        .booking-card {
            background:#fff; border:1.5px solid var(--border);
            border-radius:12px; padding:1.1rem 1.4rem;
            box-shadow:var(--shadow-soft);
            display:grid;
            grid-template-columns:1fr auto;
            gap:1rem;
            align-items:center;
            transition:box-shadow 0.15s ease;
        }
        .booking-card:hover { box-shadow:var(--shadow); }

        .booking-main {}
        .booking-title {
            font-size:1rem; font-weight:700; color:var(--text);
            display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;
        }
        .booking-type-tag {
            font-size:0.72rem; font-weight:600; color:var(--muted);
            background:var(--panel-alt); border:1px solid var(--border);
            padding:0.1rem 0.5rem; border-radius:4px;
        }
        .booking-sub {
            font-size:0.78rem; color:var(--muted);
            display:flex; flex-wrap:wrap; gap:0 1rem;
        }
        .booking-sub span { white-space:nowrap; }
        .booking-sub span + span::before { content:' · '; opacity:0.5; }

        .booking-notes {
            margin-top:0.5rem; font-size:0.76rem; color:var(--muted);
            padding:0.3rem 0.65rem; background:var(--panel-alt);
            border-radius:6px; border:1px solid var(--border);
        }

        /* Right side */
        .booking-side {
            display:flex; flex-direction:column; align-items:flex-end;
            gap:0.5rem; flex-shrink:0; min-width:120px;
        }
        .booking-price {
            font-size:1.05rem; font-weight:800; color:var(--text);
            letter-spacing:-0.01em; line-height:1;
        }
        .booking-price small { font-size:0.7rem; font-weight:500; color:var(--muted); }
        .booking-actions { display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; justify-content:flex-end; }

        /* Empty state */
        .empty-state {
            text-align:center; padding:4rem 2rem;
            background:#fff; border:1.5px solid var(--border);
            border-radius:14px; box-shadow:var(--shadow-soft);
        }
        .empty-state h3 { font-size:1rem; font-weight:700; color:var(--text); margin-bottom:0.4rem; }
        .empty-state p  { font-size:0.85rem; color:var(--muted); margin-bottom:1.25rem; }

        .cancel-form { display:inline; margin:0; }

        /* Stats grid */
        .mb-stats-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:1rem; margin-bottom:1.5rem; }
        .mb-stat { background:#fff; border:1.5px solid var(--border); border-radius:14px; box-shadow:var(--shadow-soft); padding:1.05rem 1.15rem; display:flex; align-items:center; gap:0.85rem; transition:all 0.15s ease; }
        .mb-stat:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,0.06); }
        .mb-stat-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .mb-stat-icon svg { width:17px; height:17px; stroke:currentColor; fill:none; stroke-width:2; }
        .mb-stat-value { font-size:1.45rem; font-weight:800; color:var(--text); letter-spacing:-0.02em; line-height:1.1; }
        .mb-stat-label { font-size:0.7rem; font-weight:700; letter-spacing:0.06em; color:var(--muted); text-transform:uppercase; margin-top:3px; }
        @media (max-width: 900px) { .mb-stats-grid { grid-template-columns:repeat(2, 1fr); } }
        @media (max-width: 480px) { .mb-stats-grid { grid-template-columns:1fr; } }

        /* Section heading */
        .mb-section-head { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; margin: 1.5rem 0 0.9rem; }
        .mb-section-head h2 { font-size:1.02rem; font-weight:800; color:var(--text); letter-spacing:-0.01em; margin:0; }
        .mb-section-head .pill { font-size:0.7rem; font-weight:800; color:var(--primary); background:var(--primary-soft); padding:0.2rem 0.6rem; border-radius:999px; letter-spacing:0.05em; text-transform:uppercase; }

        /* Quick actions */
        .mb-quick-actions { display:grid; grid-template-columns: repeat(4, 1fr); gap:0.85rem; margin-top:1.5rem; }
        @media (max-width: 900px) { .mb-quick-actions { grid-template-columns:repeat(2, 1fr); } }
        @media (max-width: 480px) { .mb-quick-actions { grid-template-columns:1fr; } }
        .mb-quick { display:flex; align-items:center; gap:0.8rem; background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:0.95rem 1.05rem; text-decoration:none; color:var(--text); transition:all 0.15s ease; box-shadow:var(--shadow-soft); }
        .mb-quick:hover { transform:translateY(-2px); border-color:var(--primary); box-shadow:0 10px 24px rgba(99,102,241,0.12); }
        .mb-quick-icon { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .mb-quick-icon svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; }
        .mb-quick-title { font-size:0.85rem; font-weight:700; color:var(--text); line-height:1.2; }
        .mb-quick-desc { font-size:0.72rem; color:var(--muted); margin-top:3px; }

        /* Timeline card */
        .mb-timeline-card { background:#fff; border:1.5px solid var(--border); border-radius:14px; box-shadow:var(--shadow-soft); padding:1.3rem 1.4rem; margin-top:1.5rem; }
        .mb-timeline-card h3 { font-size:1rem; font-weight:800; color:var(--text); margin:0 0 1rem; letter-spacing:-0.01em; }
        .mb-timeline { position:relative; padding-left:1.5rem; }
        .mb-timeline::before { content:''; position:absolute; left:7px; top:8px; bottom:8px; width:2px; background:var(--border); }
        .mb-tl-item { position:relative; padding-bottom:1rem; }
        .mb-tl-item:last-child { padding-bottom:0; }
        .mb-tl-dot { position:absolute; left:-1.5rem; top:5px; width:16px; height:16px; border-radius:50%; background:#fff; border:3px solid var(--primary); }
        .mb-tl-dot.green  { border-color:#10b981; }
        .mb-tl-dot.amber  { border-color:#f59e0b; }
        .mb-tl-dot.red    { border-color:#ef4444; }
        .mb-tl-dot.gray   { border-color:#94a3b8; }
        .mb-tl-title { font-size:0.88rem; font-weight:700; color:var(--text); }
        .mb-tl-meta  { font-size:0.76rem; color:var(--muted); margin-top:2px; }
    </style>
</head>
<body>
<?= render_sidebar('my_bookings.php') ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <h1>My Bookings</h1>
        <a href="browse_rooms.php" class="btn btn-secondary" style="align-self:center;">Browse Rooms</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($bookings): ?>
    <!-- Stats Row -->
    <div class="mb-stats-grid">
        <div class="mb-stat">
            <div class="mb-stat-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                <svg viewBox="0 0 24 24"><path d="M16 4h-2V2h-4v2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/></svg>
            </div>
            <div>
                <div class="mb-stat-value"><?= $stats_total ?></div>
                <div class="mb-stat-label">Total Bookings</div>
            </div>
        </div>
        <div class="mb-stat">
            <div class="mb-stat-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div>
                <div class="mb-stat-value"><?= $stats_active ?></div>
                <div class="mb-stat-label">Currently Active</div>
            </div>
        </div>
        <div class="mb-stat">
            <div class="mb-stat-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <div class="mb-stat-value"><?= $stats_pending ?></div>
                <div class="mb-stat-label">Pending Approval</div>
            </div>
        </div>
        <div class="mb-stat">
            <div class="mb-stat-icon" style="background:rgba(236,72,153,0.1);color:#ec4899;">
                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div>
                <div class="mb-stat-value"><?= $stats_days_at_hms ?></div>
                <div class="mb-stat-label">Days With HMS</div>
            </div>
        </div>
    </div>

    <div class="mb-section-head">
        <h2>Your Bookings</h2>
        <span class="pill"><?= $stats_total ?> total</span>
    </div>
    <?php endif; ?>

    <?php if (!$bookings): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="var(--muted)" stroke-width="1.5" style="display:block;margin:0 auto 1rem;opacity:0.5;">
                <path d="M3 12h18M3 6h18M3 18h18"/><rect x="2" y="3" width="20" height="18" rx="2"/>
            </svg>
            <h3>No bookings yet</h3>
            <p>You have not made any room booking requests. Browse available rooms to get started.</p>
            <a href="browse_rooms.php" class="btn btn-primary">Browse Available Rooms</a>
        </div>
    <?php else: ?>
        <div class="bookings-list">
            <?php foreach ($bookings as $b):
                $st         = $b['status'];
                $badge_cls  = $status_badge[$st]  ?? 'badge-pending';
                $label      = $status_label[$st]  ?? ucfirst($st);
                $is_pending = ($st === 'pending_approval');
                $is_active  = in_array($st, ['active','approved'], true);
            ?>
            <div class="booking-card">
                <!-- Main info -->
                <div class="booking-main">
                    <div class="booking-title">
                        Room <?= e($b['room_number']) ?>
                        <span class="booking-type-tag"><?= e($b['room_type']) ?></span>
                        <span class="badge <?= $badge_cls ?>" style="margin-left:0.2rem;"><?= $label ?></span>
                    </div>
                    <div class="booking-sub">
                        <span>Floor <?= (int)$b['floor'] ?></span>
                        <span>Booked <?= date('d M Y', strtotime($b['created_at'])) ?></span>
                        <?php if ($b['start_date']): ?><span>Check-in <?= date('d M Y', strtotime($b['start_date'])) ?></span><?php endif; ?>
                        <span>Ref #<?= (int)$b['id'] ?></span>
                    </div>
                    <?php if (!empty($b['booking_notes'])): ?>
                        <div class="booking-notes"><?= e($b['booking_notes']) ?></div>
                    <?php endif; ?>
                </div>
                <!-- Side: price + actions -->
                <div class="booking-side">
                    <div class="booking-price">NPR <?= number_format((float)$b['price'], 0) ?><small> /mo</small></div>
                    <div class="booking-actions">
                        <?php if ($is_active): ?>
                            <a href="room.php" class="btn btn-secondary" style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.85rem;">View Room</a>
                        <?php endif; ?>
                        <?php if ($is_pending): ?>
                            <form method="post" class="cancel-form" data-confirm="Cancel this booking request for Room <?= e($b['room_number']) ?>?">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                <button class="btn btn-ghost" style="font-size:0.78rem;min-height:30px;padding:0.3rem 0.85rem;color:var(--danger);">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Timeline of booking events -->
        <div class="mb-timeline-card">
            <h3>Booking Timeline</h3>
            <div class="mb-timeline">
                <?php foreach ($bookings as $b):
                    $tl_label = $status_label[$b['status']] ?? ucfirst($b['status']);
                    $tl_dot   = match($b['status']) {
                        'active','approved' => 'green',
                        'pending_approval'  => 'amber',
                        'rejected','cancelled' => 'red',
                        default => 'gray',
                    };
                ?>
                <div class="mb-tl-item">
                    <span class="mb-tl-dot <?= $tl_dot ?>"></span>
                    <div class="mb-tl-title">Room <?= e($b['room_number']) ?> &middot; <?= e($tl_label) ?></div>
                    <div class="mb-tl-meta">
                        <?= e(date('d M Y, g:i A', strtotime($b['created_at']))) ?>
                        &middot; Ref #<?= (int)$b['id'] ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mb-section-head" style="margin-top:1.8rem;">
            <h2>Quick Actions</h2>
        </div>
        <div class="mb-quick-actions" style="margin-top:0;">
            <a href="room.php" class="mb-quick">
                <span class="mb-quick-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                    <svg viewBox="0 0 24 24"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>
                </span>
                <div>
                    <div class="mb-quick-title">View My Room</div>
                    <div class="mb-quick-desc">Room details &amp; status</div>
                </div>
            </a>
            <a href="payments.php" class="mb-quick">
                <span class="mb-quick-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                </span>
                <div>
                    <div class="mb-quick-title">Pay Fees</div>
                    <div class="mb-quick-desc">View bills &amp; pay online</div>
                </div>
            </a>
            <a href="request_transfer.php" class="mb-quick">
                <span class="mb-quick-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                    <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </span>
                <div>
                    <div class="mb-quick-title">Request Transfer</div>
                    <div class="mb-quick-desc">Switch to another room</div>
                </div>
            </a>
            <a href="my_complaints.php" class="mb-quick">
                <span class="mb-quick-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;">
                    <svg viewBox="0 0 24 24"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>
                </span>
                <div>
                    <div class="mb-quick-title">Raise Complaint</div>
                    <div class="mb-quick-desc">Report an issue</div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../chatbot/widget.php'; ?>
<script src="../js/script.js"></script>
</body>
</html>
