<?php
require_once '../db.php';
require_role('student');

$uid     = (int)$_SESSION['user_id'];
$success = $error = '';
if (isset($_GET['msg'])) $success = trim($_GET['msg']);
if (isset($_GET['err'])) $error   = trim($_GET['err']);

$ab = $pdo->prepare(
    "SELECT b.id, b.room_id, b.status, b.start_date, b.created_at,
            r.room_number, r.room_type, r.floor, r.price, r.photo AS room_photo
     FROM bookings b
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.student_id = ? AND b.status IN ('pending_approval','approved','active')
     LIMIT 1"
);
$ab->execute([$uid]);
$active_booking = $ab->fetch();

// Extra context for the confirmed-room dashboard view
$confirmed_room_data = null;
if ($active_booking && in_array($active_booking['status'], ['active','approved'], true)) {
    // Current month fee status
    $fee_state = 'unpaid';
    try {
        $fs = $pdo->prepare(
            "SELECT status FROM monthly_fees
             WHERE student_id = ? AND billing_month = DATE_FORMAT(NOW(),'%Y-%m-01')
             LIMIT 1"
        );
        $fs->execute([$uid]);
        $row = $fs->fetch();
        if ($row) $fee_state = $row['status'];
    } catch (Exception $e) {}

    // Total roommates in the same room
    $roommates = 0;
    try {
        $rm = $pdo->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE room_id = ? AND student_id <> ?
               AND status IN ('approved','active')"
        );
        $rm->execute([$active_booking['room_id'], $uid]);
        $roommates = (int)$rm->fetchColumn();
    } catch (Exception $e) {}

    // Last payment
    $last_payment = null;
    try {
        $lp = $pdo->prepare(
            "SELECT amount, paid_at FROM payments
             WHERE student_id = ? AND status = 'success'
             ORDER BY paid_at DESC LIMIT 1"
        );
        $lp->execute([$uid]);
        $last_payment = $lp->fetch();
    } catch (Exception $e) {}

    // Pending transfer request, if any
    $pending_transfer = null;
    try {
        $pt = $pdo->prepare(
            "SELECT rt.created_at, r.room_number AS to_room
             FROM room_transfers rt
             LEFT JOIN rooms r ON r.id = rt.to_room_id
             WHERE rt.student_id = ? AND rt.status = 'pending'
             ORDER BY rt.created_at DESC LIMIT 1"
        );
        $pt->execute([$uid]);
        $pending_transfer = $pt->fetch();
    } catch (Exception $e) {}

    $confirmed_room_data = [
        'fee_state'        => $fee_state,
        'roommates'        => $roommates,
        'last_payment'     => $last_payment,
        'pending_transfer' => $pending_transfer,
    ];
}

// No redirect - students with active rooms can still browse (info banner shown instead)

$f_type  = $_GET['room_type'] ?? '';
$f_floor = $_GET['floor']     ?? '';
$f_max   = $_GET['max_price'] ?? '';

$capacity_filter = "(SELECT COUNT(*) FROM bookings b WHERE b.room_id = rooms.id AND b.status IN ('pending_approval','approved','active')) < rooms.capacity";
$where  = ["status = 'available'", 'deleted_at IS NULL', $capacity_filter];
$params = [];
if (in_array($f_type, ['Single','Double','Triple'], true)) { $where[] = 'room_type = ?'; $params[] = $f_type; }
if ($f_floor !== '' && ctype_digit((string)$f_floor))       { $where[] = 'floor = ?';     $params[] = (int)$f_floor; }
if ($f_max !== '' && is_numeric($f_max) && (float)$f_max>0) { $where[] = 'price <= ?';    $params[] = (float)$f_max; }
$where_sql = 'WHERE ' . implode(' AND ', $where);

$page     = max(1,(int)($_GET['page'] ?? 1));
$per_page = 9;
$cnt      = $pdo->prepare("SELECT COUNT(*) FROM rooms $where_sql");
$cnt->execute($params);
$total       = (int)$cnt->fetchColumn();
$total_pages = max(1,(int)ceil($total/$per_page));
$offset      = ($page-1)*$per_page;

$stmt = $pdo->prepare(
    "SELECT id, room_number, room_type, floor, capacity, price, notes, photo,
            (SELECT COUNT(*) FROM bookings b WHERE b.room_id = rooms.id AND b.status IN ('pending_approval','approved','active')) AS taken
     FROM rooms $where_sql
     ORDER BY price ASC, room_number ASC LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

$qs = function(array $extra) use ($f_type,$f_floor,$f_max) {
    return http_build_query(array_merge(['room_type'=>$f_type,'floor'=>$f_floor,'max_price'=>$f_max],$extra));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Browse Rooms - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        /* Confirmed-room dashboard view */
        .br-hero { display:grid; grid-template-columns: 1.4fr 1fr; gap:1.25rem; margin-bottom:1.5rem; }
        @media (max-width: 980px) { .br-hero { grid-template-columns: 1fr; } }

        .br-hero-card {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 60%, #4338ca 100%);
            color: #fff; border-radius: 18px;
            padding: 1.75rem 1.85rem 1.6rem;
            box-shadow: 0 18px 40px -12px rgba(79,70,229,0.45);
        }
        .br-hero-card::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(800px circle at 100% 0%, rgba(255,255,255,0.18), transparent 50%);
            pointer-events: none;
        }
        .br-hero-status {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: rgba(255,255,255,0.18);
            padding: 0.3rem 0.85rem; border-radius: 999px;
            font-size: 0.72rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase;
            backdrop-filter: blur(8px);
        }
        .br-hero-status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #4ade80; }
        .br-hero-num { font-size: 3.25rem; font-weight: 900; letter-spacing: -0.04em; line-height: 1; margin: 1rem 0 0.35rem; }
        .br-hero-sub { font-size: 0.92rem; opacity: 0.85; font-weight: 500; }
        .br-hero-meta { display: flex; gap: 1.6rem; margin-top: 1.4rem; flex-wrap: wrap; }
        .br-hero-meta-item { font-size: 0.78rem; opacity: 0.8; line-height: 1.3; }
        .br-hero-meta-item b { display: block; font-size: 0.98rem; font-weight: 700; color: #fff; opacity: 1; letter-spacing: -0.01em; margin-top: 2px; }
        .br-hero-actions { display: flex; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .br-hero-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.55rem 1rem; border-radius: 9px;
            font-size: 0.84rem; font-weight: 700; text-decoration: none;
            transition: all 0.15s ease; backdrop-filter: blur(6px);
        }
        .br-hero-btn-primary { background: #fff; color: #4338ca; }
        .br-hero-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.2); }
        .br-hero-btn-ghost { background: rgba(255,255,255,0.16); color: #fff; border: 1px solid rgba(255,255,255,0.25); }
        .br-hero-btn-ghost:hover { background: rgba(255,255,255,0.24); }
        .br-hero-btn svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        /* Side stats column */
        .br-side-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
        .br-stat-tile {
            background: #fff; border: 1.5px solid var(--border);
            border-radius: 14px; padding: 1rem 1.1rem; box-shadow: var(--shadow-soft);
            transition: all 0.15s ease;
        }
        .br-stat-tile:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
        .br-stat-tile-head { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.7rem; }
        .br-stat-tile-icon { width: 32px; height: 32px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .br-stat-tile-icon svg { width: 15px; height: 15px; stroke: currentColor; fill: none; stroke-width: 2; }
        .br-stat-tile-label { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; color: var(--muted); text-transform: uppercase; }
        .br-stat-tile-value { font-size: 1.3rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1.1; }
        .br-stat-tile-meta { font-size: 0.74rem; color: var(--muted); margin-top: 4px; }

        /* Quick actions row */
        .br-quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.85rem; margin-bottom: 1.5rem; }
        @media (max-width: 900px) { .br-quick-actions { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .br-quick-actions { grid-template-columns: 1fr; } }
        .br-quick {
            display: flex; align-items: center; gap: 0.8rem;
            background: #fff; border: 1.5px solid var(--border);
            border-radius: 12px; padding: 1rem 1.1rem;
            text-decoration: none; color: var(--text);
            transition: all 0.15s ease; box-shadow: var(--shadow-soft);
        }
        .br-quick:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: 0 10px 24px rgba(99,102,241,0.12); }
        .br-quick-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .br-quick-icon svg { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; }
        .br-quick-title { font-size: 0.85rem; font-weight: 700; color: var(--text); line-height: 1.2; }
        .br-quick-desc { font-size: 0.72rem; color: var(--muted); margin-top: 3px; }

        /* Amenities card */
        .br-amenities {
            background: #fff; border: 1.5px solid var(--border);
            border-radius: 14px; box-shadow: var(--shadow-soft);
            padding: 1.4rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        .br-amenities-head {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 0.25rem;
        }
        .br-amenities-head h3 { font-size: 1.05rem; font-weight: 800; color: var(--text); letter-spacing: -0.01em; margin: 0; }
        .br-amenities-head .pill { font-size: 0.7rem; font-weight: 800; color: var(--primary); background: var(--primary-soft); padding: 0.2rem 0.6rem; border-radius: 999px; letter-spacing: 0.05em; text-transform: uppercase; }
        .br-amenities-sub { font-size: 0.82rem; color: var(--muted); margin-bottom: 1.1rem; }
        .br-amenities-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;
        }
        @media (max-width: 900px) { .br-amenities-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .br-amenities-grid { grid-template-columns: 1fr; } }
        .br-amenity {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.7rem 0.85rem;
            background: var(--panel-alt); border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.15s ease;
        }
        .br-amenity:hover { background: #fff; border-color: var(--primary); transform: translateY(-1px); }
        .br-amenity-icon { width: 28px; height: 28px; border-radius: 7px; background: #fff; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--primary); }
        .br-amenity-icon svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
        .br-amenity-text { font-size: 0.82rem; font-weight: 600; color: var(--text); }

        .br-pending-banner {
            display: flex; gap: 0.7rem; align-items: center;
            background: #fef3c7; border: 1.5px solid #fcd34d;
            border-radius: 12px; padding: 0.85rem 1.1rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-soft);
        }
        .br-pending-banner svg { width: 18px; height: 18px; stroke: #b45309; fill: none; stroke-width: 2; flex-shrink: 0; }
        .br-pending-banner-body { flex: 1; font-size: 0.85rem; color: #78350f; }
        .br-pending-banner-body b { font-weight: 700; }

        .filter-bar { display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.25rem;align-items:end;background:#fff;padding:1rem 1.25rem;border:1.5px solid var(--border);border-radius:12px; }
        .filter-bar label { display:block;font-size:0.7rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px; }
        .filter-bar select, .filter-bar input { padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:0.9rem; }

        .room-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem; }
        @media(max-width:1024px){ .room-grid{grid-template-columns:repeat(2,1fr);} }
        @media(max-width:600px)  { .room-grid{grid-template-columns:1fr;} }

        .room-card { background:#fff;border:1.5px solid var(--border);border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:var(--shadow-soft);transition:transform 0.2s ease,box-shadow 0.2s ease; }
        .room-card:hover { transform:translateY(-3px);box-shadow:var(--shadow); }

        .room-img { position:relative; }
        .room-img img { width:100%;height:190px;object-fit:cover;display:block; }
        .room-img-overlay { position:absolute;inset:0;background:linear-gradient(to top,rgba(15,23,42,0.55) 0%,transparent 55%); }
        .room-type-badge { position:absolute;top:0.7rem;left:0.7rem;background:rgba(15,23,42,0.7);color:#fff;font-size:0.68rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;padding:0.22rem 0.6rem;border-radius:999px;backdrop-filter:blur(6px); }
        .room-floor-badge { position:absolute;bottom:0.7rem;right:0.7rem;background:rgba(15,23,42,0.65);color:rgba(255,255,255,0.85);font-size:0.7rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:999px;backdrop-filter:blur(4px); }
        .room-avail-badge { position:absolute;top:0.7rem;right:0.7rem;background:#ef4444;color:#fff;font-size:0.65rem;font-weight:800;letter-spacing:0.05em;text-transform:uppercase;padding:0.2rem 0.55rem;border-radius:999px; }

        .room-body { padding:1.1rem 1.25rem 1.25rem;display:flex;flex-direction:column;flex:1; }
        .room-num-row { display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.5rem; }
        .room-number { font-size:0.8rem;font-weight:700;color:var(--muted); }
        .room-price-tag { font-size:1.35rem;font-weight:800;color:var(--text);letter-spacing:-0.02em;line-height:1; }
        .room-price-tag span { font-size:0.75rem;font-weight:500;color:var(--muted); }
        .room-capacity { font-size:0.78rem;color:var(--muted);margin-bottom:0.875rem; }
        .room-capacity strong { color:var(--text); }
        .room-features { list-style:none;display:grid;gap:0.35rem;margin-bottom:1.1rem; }
        .room-features li { display:flex;align-items:center;gap:0.55rem;font-size:0.8rem;color:#334155; }
        .feat-dot { display:inline-block;flex-shrink:0;width:6px;height:6px;border-radius:2px;background:var(--primary);opacity:0.7; }
        .room-notes { font-size:0.78rem;color:var(--muted);margin-bottom:0.875rem;padding:0.5rem 0.75rem;background:var(--panel-alt);border-radius:7px; }
        .pagination { display:flex;gap:0.4rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap; }
        .pagination a, .pagination span { padding:0.4rem 0.85rem;border-radius:8px;border:1.5px solid var(--border);color:var(--text);font-weight:700;font-size:0.82rem;text-decoration:none;transition:background 0.15s ease; }
        .pagination a:hover { background:var(--panel-alt); }
        .pagination .active { background:var(--primary);color:#fff;border-color:var(--primary); }
    </style>
</head>
<body>
<?= render_sidebar('browse_rooms.php') ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header"><h1>Browse Rooms</h1></div>

    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($active_booking && $confirmed_room_data):
        // ── Rich dashboard view: student has an active/approved booking ──
        $cd = $confirmed_room_data;
        $fee_label = match($cd['fee_state']) { 'paid' => 'Paid', 'overdue' => 'Overdue', default => 'Unpaid' };
        $fee_color = match($cd['fee_state']) {
            'paid'    => ['bg' => 'rgba(16,185,129,0.1)', 'fg' => '#059669'],
            'overdue' => ['bg' => 'rgba(239,68,68,0.1)',  'fg' => '#dc2626'],
            default   => ['bg' => 'rgba(245,158,11,0.1)', 'fg' => '#b45309'],
        };
        $checkin_date = $active_booking['start_date'] ?: $active_booking['created_at'];
    ?>
        <!-- HERO + SIDE STATS -->
        <div class="br-hero">
            <div class="br-hero-card">
                <span class="br-hero-status">Confirmed</span>
                <div class="br-hero-num">Room <?= e($active_booking['room_number'] ?? '?') ?></div>
                <div class="br-hero-sub">
                    <?= e($active_booking['room_type'] ?? '') ?>
                    <?php if ($active_booking['floor']): ?> &middot; Floor <?= (int)$active_booking['floor'] ?><?php endif; ?>
                </div>
                <div class="br-hero-meta">
                    <div class="br-hero-meta-item">Check-in<b><?= e(date('d M Y', strtotime($checkin_date))) ?></b></div>
                    <div class="br-hero-meta-item">Monthly Fee<b>NPR <?= number_format((float)($active_booking['price'] ?? 0), 0) ?></b></div>
                    <div class="br-hero-meta-item">Roommates<b><?= (int)$cd['roommates'] ?></b></div>
                </div>
                <div class="br-hero-actions">
                    <a href="room.php" class="br-hero-btn br-hero-btn-primary">
                        <svg viewBox="0 0 24 24"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>
                        View My Room
                    </a>
                    <a href="my_bookings.php" class="br-hero-btn br-hero-btn-ghost">
                        <svg viewBox="0 0 24 24"><path d="M16 4h-2V2h-4v2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/></svg>
                        Booking Details
                    </a>
                </div>
            </div>

            <div class="br-side-stats">
                <div class="br-stat-tile">
                    <div class="br-stat-tile-head">
                        <span class="br-stat-tile-icon" style="background:<?= $fee_color['bg'] ?>;color:<?= $fee_color['fg'] ?>;">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                        </span>
                        <span class="br-stat-tile-label">This month</span>
                    </div>
                    <div class="br-stat-tile-value" style="color:<?= $fee_color['fg'] ?>;"><?= e($fee_label) ?></div>
                    <div class="br-stat-tile-meta"><?= e(date('F Y')) ?></div>
                </div>
                <div class="br-stat-tile">
                    <div class="br-stat-tile-head">
                        <span class="br-stat-tile-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                            <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        </span>
                        <span class="br-stat-tile-label">Last paid</span>
                    </div>
                    <?php if ($cd['last_payment']): ?>
                        <div class="br-stat-tile-value">NPR <?= number_format((float)$cd['last_payment']['amount'], 0) ?></div>
                        <div class="br-stat-tile-meta"><?= e(date('d M Y', strtotime($cd['last_payment']['paid_at']))) ?></div>
                    <?php else: ?>
                        <div class="br-stat-tile-value" style="color:var(--muted);">—</div>
                        <div class="br-stat-tile-meta">No payments yet</div>
                    <?php endif; ?>
                </div>
                <div class="br-stat-tile">
                    <div class="br-stat-tile-head">
                        <span class="br-stat-tile-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                            <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </span>
                        <span class="br-stat-tile-label">Status</span>
                    </div>
                    <div class="br-stat-tile-value" style="color:#059669;">Active</div>
                    <div class="br-stat-tile-meta">Booking ID #<?= (int)$active_booking['id'] ?></div>
                </div>
                <div class="br-stat-tile">
                    <div class="br-stat-tile-head">
                        <span class="br-stat-tile-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                            <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        </span>
                        <span class="br-stat-tile-label">Transfer</span>
                    </div>
                    <?php if ($cd['pending_transfer']): ?>
                        <div class="br-stat-tile-value" style="font-size:1rem;color:#b45309;">Pending</div>
                        <div class="br-stat-tile-meta">to Room <?= e($cd['pending_transfer']['to_room']) ?></div>
                    <?php else: ?>
                        <div class="br-stat-tile-value" style="font-size:1rem;">Available</div>
                        <div class="br-stat-tile-meta"><a href="request_transfer.php" style="color:var(--primary);font-weight:600;">Request now →</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($cd['pending_transfer']): ?>
        <div class="br-pending-banner">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <div class="br-pending-banner-body">
                <b>Transfer request pending</b> &mdash; you've requested a move to Room <?= e($cd['pending_transfer']['to_room']) ?>, submitted <?= e(date('d M Y', strtotime($cd['pending_transfer']['created_at']))) ?>. Awaiting warden review.
            </div>
        </div>
        <?php endif; ?>

        <!-- QUICK ACTIONS -->
        <div class="br-quick-actions">
            <a href="payments.php" class="br-quick">
                <span class="br-quick-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                </span>
                <div>
                    <div class="br-quick-title">Pay Fees</div>
                    <div class="br-quick-desc">View bills &amp; pay via eSewa</div>
                </div>
            </a>
            <a href="request_transfer.php" class="br-quick">
                <span class="br-quick-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                    <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </span>
                <div>
                    <div class="br-quick-title">Request Transfer</div>
                    <div class="br-quick-desc">Move to a different room</div>
                </div>
            </a>
            <a href="my_complaints.php" class="br-quick">
                <span class="br-quick-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                    <svg viewBox="0 0 24 24"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>
                </span>
                <div>
                    <div class="br-quick-title">Raise Complaint</div>
                    <div class="br-quick-desc">Report any issue</div>
                </div>
            </a>
            <a href="request_leave.php" class="br-quick">
                <span class="br-quick-icon" style="background:rgba(236,72,153,0.1);color:#ec4899;">
                    <svg viewBox="0 0 24 24"><circle cx="18" cy="15" r="3"/><path d="M13.172 5.172a4 4 0 0 0-5.656 0l-6 6a4 4 0 1 0 5.656 5.656l6-6"/></svg>
                </span>
                <div>
                    <div class="br-quick-title">Request Leave</div>
                    <div class="br-quick-desc">Apply for time off</div>
                </div>
            </a>
        </div>

        <!-- AMENITIES -->
        <div class="br-amenities">
            <div class="br-amenities-head">
                <h3>What's included with your room</h3>
                <span class="pill">All-inclusive</span>
            </div>
            <div class="br-amenities-sub">Everything covered by your monthly fee &mdash; no hidden charges.</div>
            <div class="br-amenities-grid">
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor"/></svg></span>
                    <span class="br-amenity-text">100 Mbps WiFi</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg></span>
                    <span class="br-amenity-text">3 Meals / Day</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
                    <span class="br-amenity-text">Laundry 2×/Week</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
                    <span class="br-amenity-text">Power Backup</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="br-amenity-text">24/7 Security</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span>
                    <span class="br-amenity-text">Furnished Room</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                    <span class="br-amenity-text">Quiet Study Zone</span>
                </div>
                <div class="br-amenity">
                    <span class="br-amenity-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
                    <span class="br-amenity-text">Daily Housekeeping</span>
                </div>
            </div>
        </div>

    <?php elseif ($active_booking): ?>
        <!-- Pending approval state — keep the original compact notice -->
        <div style="background:#fff;border:1.5px solid var(--border);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;box-shadow:var(--shadow-soft);">
            <div style="width:38px;height:38px;border-radius:9px;background:var(--warning-soft);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" style="width:18px;height:18px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div style="flex:1;min-width:180px;">
                <div style="font-weight:700;font-size:0.875rem;">Booking request pending approval</div>
                <div style="font-size:0.78rem;color:var(--muted);margin-top:2px;">
                    Your booking for<?= $active_booking['room_number'] ? ' Room ' . e($active_booking['room_number']) : ' a room' ?> is awaiting warden approval.
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:0.6rem;flex-shrink:0;">
                <span class="badge badge-pending">Pending Approval</span>
                <a href="my_bookings.php" class="btn btn-secondary" style="min-height:32px;padding:0.3rem 0.85rem;font-size:0.8rem;">View Booking</a>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // Show room grid only if student has no active/approved booking
    $show_grid = !$active_booking || !in_array($active_booking['status'], ['active','approved'], true);
    if ($show_grid):
        $type_images = [
            'Single'=>'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=600',
            'Double'=>'https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=80&w=600',
            'Triple'=>'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&q=80&w=600',
        ];
        $type_features = [
            'Single'=>['Private room - 1 student only','Single bed with wardrobe','Personal study desk','Attached or shared bathroom','Maximum privacy'],
            'Double'=>['Shared room - 2 students','2 beds with individual wardrobes','Individual study desks','Shared bathroom','Best value for money'],
            'Triple'=>['Shared room - 3 students','3 beds with wardrobes','Shared study area','Shared bathroom','Community atmosphere'],
        ];
        ?>
        <form method="get" class="filter-bar">
            <div><label>Type</label>
                <select name="room_type">
                    <option value="">Any</option>
                    <option value="Single" <?= $f_type==='Single'?'selected':'' ?>>Single</option>
                    <option value="Double" <?= $f_type==='Double'?'selected':'' ?>>Double</option>
                    <option value="Triple" <?= $f_type==='Triple'?'selected':'' ?>>Triple</option>
                </select>
            </div>
            <div><label>Floor</label><input type="number" name="floor" min="1" max="20" value="<?= e((string)$f_floor) ?>" placeholder="any"></div>
            <div><label>Max price (NPR)</label><input type="number" name="max_price" min="100" step="500" value="<?= e((string)$f_max) ?>" placeholder="any"></div>
            <div><button class="btn btn-primary">Apply</button></div>
            <div><a class="btn btn-secondary" href="browse_rooms.php">Reset</a></div>
            <div style="margin-left:auto;align-self:center;color:var(--muted);font-size:0.85rem;"><?= (int)$total ?> available</div>
        </form>

        <?php if (!$rooms): ?>
            <div class="card" style="text-align:center;padding:3rem;color:var(--muted);">No available rooms match those filters.</div>
        <?php else: ?>
            <div class="room-grid">
                <?php foreach ($rooms as $r):
                    $type  = $r['room_type'];
                    $left  = (int)$r['capacity']-(int)$r['taken'];
                    $img   = !empty($r['photo']) ? '../uploads/rooms/'.e($r['photo']) : ($type_images[$type] ?? $type_images['Single']);
                    $feats = $type_features[$type] ?? $type_features['Single'];
                    $is_last = ($left===1&&(int)$r['capacity']>1);
                ?>
                <div class="room-card">
                    <div class="room-img">
                        <img src="<?= $img ?>" alt="<?= e($type) ?> Room" onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=600'">
                        <div class="room-img-overlay"></div>
                        <span class="room-type-badge"><?= e($type) ?></span>
                        <span class="room-floor-badge">Floor <?= (int)$r['floor'] ?></span>
                        <?php if($is_last): ?><span class="room-avail-badge">LAST BED</span><?php endif; ?>
                    </div>
                    <div class="room-body">
                        <div class="room-num-row">
                            <span class="room-number">Room <?= e($r['room_number']) ?></span>
                            <span class="room-price-tag">NPR <?= number_format((float)$r['price'],0) ?><span> /mo</span></span>
                        </div>
                        <div class="room-capacity"><?= (int)$r['taken'] ?> of <?= (int)$r['capacity'] ?> beds taken<?php if($left>0): ?> &bull; <strong><?= $left ?> bed<?= $left>1?'s':'' ?> available</strong><?php endif; ?></div>
                        <ul class="room-features">
                            <?php foreach($feats as $f): ?>
                                <li><span class="feat-dot"></span><?= e($f) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if($r['notes']): ?><div class="room-notes"><?= e($r['notes']) ?></div><?php endif; ?>
                        <form method="post" action="book_room.php" data-confirm="Submit a booking request for Room <?= e($r['room_number']) ?>?" style="margin-top:auto;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-primary" style="width:100%;">Book Room <?= e($r['room_number']) ?></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if($total_pages>1): ?>
                <div class="pagination">
                    <?php if($page>1): ?><a href="?<?= $qs(['page'=>$page-1]) ?>">&lsaquo;</a><?php endif; ?>
                    <?php for($i=1;$i<=$total_pages;$i++):
                        if($i===$page): ?><span class="active"><?= $i ?></span>
                        <?php else: ?><a href="?<?= $qs(['page'=>$i]) ?>"><?= $i ?></a><?php endif;
                    endfor; ?>
                    <?php if($page<$total_pages): ?><a href="?<?= $qs(['page'=>$page+1]) ?>">&rsaquo;</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../chatbot/widget.php'; ?>
<script src="../js/script.js"></script>
</body>
</html>
