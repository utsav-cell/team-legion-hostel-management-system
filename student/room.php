<?php
// ─────────────────────────────────────────────────
// student/room.php — View My Room Details
// Reads from the active/approved booking (canonical) and falls back to
// users.room_id (legacy). Shows photo, description, stats, roommates,
// payment status and quick actions to fill the page.
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role('student');

$uid = (int)$_SESSION['user_id'];

// ── Room lookup — prefer the active/approved booking, fall back to users.room_id ──
$room = null;
$booking = null;
try {
    $bs = $pdo->prepare(
        "SELECT b.id AS booking_id, b.start_date, b.created_at, b.status AS booking_status,
                r.id AS rid, r.room_number, r.room_type, r.floor, r.capacity,
                r.price, r.notes, r.photo, r.status AS room_status_db
         FROM bookings b
         JOIN rooms r ON r.id = b.room_id
         WHERE b.student_id = ? AND b.status IN ('approved','active')
         ORDER BY b.created_at DESC LIMIT 1"
    );
    $bs->execute([$uid]);
    $booking = $bs->fetch();

    if ($booking) {
        $room = $booking;
    } else {
        $us = $pdo->prepare(
            "SELECT r.id AS rid, r.room_number, r.room_type, r.floor, r.capacity,
                    r.price, r.notes, r.photo, u.room_status
             FROM users u LEFT JOIN rooms r ON r.id = u.room_id
             WHERE u.id = ? LIMIT 1"
        );
        $us->execute([$uid]);
        $row = $us->fetch();
        if ($row && $row['rid']) $room = $row;
    }
} catch (Exception $e) {}

// User room_status (display banner)
$user_room_status = 'pending';
try {
    $rs = $pdo->prepare("SELECT room_status FROM users WHERE id = ? LIMIT 1");
    $rs->execute([$uid]);
    $rrow = $rs->fetch();
    $user_room_status = $rrow['room_status'] ?? 'pending';
} catch (Exception $e) {}

$is_approved = ($user_room_status === 'approved') || ($booking && in_array($booking['booking_status'], ['active','approved'], true));

// ── Roommates ────────────────────────────────────────────────────────────────
$roommates = [];
if ($room && !empty($room['rid'])) {
    try {
        $rm = $pdo->prepare(
            "SELECT DISTINCT u.id, u.name, u.email, u.photo
             FROM bookings b
             JOIN users u ON u.id = b.student_id
             WHERE b.room_id = ? AND b.student_id <> ?
               AND b.status IN ('approved','active')"
        );
        $rm->execute([$room['rid'], $uid]);
        $roommates = $rm->fetchAll();
    } catch (Exception $e) {}
}

// ── Current month fee status ─────────────────────────────────────────────────
$fee_state = null;
$fee_amount = null;
try {
    $fs = $pdo->prepare(
        "SELECT status, amount FROM monthly_fees
         WHERE student_id = ? AND billing_month = DATE_FORMAT(NOW(),'%Y-%m-01')
         LIMIT 1"
    );
    $fs->execute([$uid]);
    if ($fr = $fs->fetch()) {
        $fee_state  = $fr['status'];
        $fee_amount = (float)$fr['amount'];
    }
} catch (Exception $e) {}

// ── Pending transfer ─────────────────────────────────────────────────────────
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

// Helper: avatar initials
function rm_initials($name) {
    $parts = array_values(array_filter(explode(' ', trim($name))));
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

// Default photo by room type
$type_photos = [
    'Single' => 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&q=80&w=900',
    'Double' => 'https://images.unsplash.com/photo-1555854877-bab0e564b8d5?auto=format&fit=crop&q=80&w=900',
    'Triple' => 'https://images.unsplash.com/photo-1484154218962-a197022b5858?auto=format&fit=crop&q=80&w=900',
];
$room_photo_url = null;
if ($room) {
    if (!empty($room['photo'])) {
        $room_photo_url = '../uploads/rooms/' . rawurlencode($room['photo']);
    } else {
        $room_photo_url = $type_photos[$room['room_type'] ?? 'Single'] ?? $type_photos['Single'];
    }
}

// Type description blurbs
$type_descriptions = [
    'Single' => 'A private room exclusively yours. Quiet, focused and ideal for deep study sessions. Includes a single bed, wardrobe, study desk and reading lamp.',
    'Double' => 'A shared room for two students. Each resident gets their own bed, wardrobe and study desk. The most popular choice — social yet still private.',
    'Triple' => 'A community-oriented shared room for three students. Three beds with individual wardrobes and a shared study area. Great for making lasting friendships.',
];
$room_description = $room['notes'] ?? null;
if (!$room_description && $room) {
    $room_description = $type_descriptions[$room['room_type'] ?? 'Single'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Room — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .mr-status-banner { display:flex; align-items:center; gap:1rem; padding:0.95rem 1.2rem; border-radius:12px; margin-bottom:1.5rem; border:1.5px solid var(--border); background:#fff; box-shadow:var(--shadow-soft); }
        .mr-status-icon { width:42px; height:42px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .mr-status-icon svg { width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:2.5; }
        .mr-status-title { font-weight:700; font-size:0.92rem; color:var(--text); letter-spacing:-0.01em; }
        .mr-status-sub { font-size:0.78rem; color:var(--muted); margin-top:2px; }

        /* Hero: photo on the left, info on the right */
        .mr-hero { display:grid; grid-template-columns: 1.1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; }
        @media (max-width: 980px) { .mr-hero { grid-template-columns: 1fr; } }

        .mr-photo-wrap { position:relative; border-radius:16px; overflow:hidden; box-shadow: 0 10px 30px -10px rgba(15,23,42,0.18); min-height:300px; background:var(--panel-alt); }
        .mr-photo-wrap img { width:100%; height:100%; min-height:300px; object-fit:cover; display:block; }
        .mr-photo-gradient { position:absolute; inset:0; background:linear-gradient(to top, rgba(15,23,42,0.65) 0%, rgba(15,23,42,0.1) 45%, transparent 70%); }
        .mr-photo-content { position:absolute; left:1.4rem; right:1.4rem; bottom:1.25rem; color:#fff; }
        .mr-photo-type { display:inline-flex; align-items:center; gap:0.3rem; background:rgba(255,255,255,0.18); backdrop-filter:blur(8px); padding:0.3rem 0.8rem; border-radius:999px; font-size:0.7rem; font-weight:800; letter-spacing:0.07em; text-transform:uppercase; margin-bottom:0.7rem; }
        .mr-photo-num { font-size:2.6rem; font-weight:900; letter-spacing:-0.03em; line-height:1; margin-bottom:0.25rem; }
        .mr-photo-meta { font-size:0.95rem; opacity:0.9; font-weight:500; }

        /* Info column */
        .mr-info-card { display:flex; flex-direction:column; gap:0.9rem; }
        .mr-info-box { background:#fff; border:1.5px solid var(--border); border-radius:14px; padding:1.15rem 1.3rem; box-shadow:var(--shadow-soft); transition:all 0.15s ease; }
        .mr-info-box:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,0.06); }
        .mr-info-head { display:flex; align-items:center; gap:0.7rem; margin-bottom:0.5rem; }
        .mr-info-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .mr-info-icon svg { width:15px; height:15px; stroke:currentColor; fill:none; stroke-width:2; }
        .mr-info-label { font-size:0.7rem; font-weight:700; letter-spacing:0.06em; color:var(--muted); text-transform:uppercase; }
        .mr-info-value { font-size:1.4rem; font-weight:800; color:var(--text); letter-spacing:-0.02em; line-height:1.1; }
        .mr-info-sub { font-size:0.78rem; color:var(--muted); margin-top:3px; }
        .mr-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.9rem; }

        /* Section heading */
        .mr-section-head { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; margin:1.6rem 0 0.9rem; }
        .mr-section-head h2 { font-size:1.02rem; font-weight:800; color:var(--text); letter-spacing:-0.01em; margin:0; }
        .mr-section-head .pill { font-size:0.7rem; font-weight:800; color:var(--primary); background:var(--primary-soft); padding:0.2rem 0.6rem; border-radius:999px; letter-spacing:0.05em; text-transform:uppercase; }

        /* Description card */
        .mr-description { background:#fff; border:1.5px solid var(--border); border-radius:14px; padding:1.3rem 1.4rem; box-shadow:var(--shadow-soft); margin-bottom:1.5rem; }
        .mr-description h3 { font-size:1rem; font-weight:800; color:var(--text); margin:0 0 0.4rem; letter-spacing:-0.01em; }
        .mr-description p  { font-size:0.9rem; color:var(--text); line-height:1.65; margin:0 0 1rem; }
        .mr-description-foot { display:flex; align-items:center; gap:0.6rem; padding-top:0.85rem; border-top:1px dashed var(--border); font-size:0.78rem; color:var(--muted); }
        .mr-description-foot strong { color:var(--text); }

        /* Roommates */
        .mr-rm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:0.85rem; }
        .mr-rm-card { display:flex; align-items:center; gap:0.85rem; background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:0.85rem 1rem; box-shadow:var(--shadow-soft); transition:all 0.15s ease; }
        .mr-rm-card:hover { transform:translateY(-2px); border-color:var(--primary); box-shadow:0 8px 20px rgba(99,102,241,0.1); }
        .mr-rm-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#6366f1,#4f46e5); color:#fff; font-weight:700; font-size:0.95rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 6px rgba(99,102,241,0.25); }
        .mr-rm-name { font-size:0.88rem; font-weight:700; color:var(--text); line-height:1.2; }
        .mr-rm-email { font-size:0.74rem; color:var(--muted); margin-top:2px; word-break:break-word; }
        .mr-rm-empty { background:var(--panel-alt); border:1px solid var(--border); border-radius:12px; padding:1.5rem 1.25rem; text-align:center; color:var(--muted); font-size:0.85rem; }
        .mr-rm-empty b { color:var(--text); font-weight:700; display:block; margin-bottom:3px; }

        /* Amenities & rules */
        .mr-two-col { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem; }
        @media (max-width: 900px) { .mr-two-col { grid-template-columns:1fr; } }
        .mr-side-card { background:#fff; border:1.5px solid var(--border); border-radius:14px; padding:1.25rem 1.35rem; box-shadow:var(--shadow-soft); }
        .mr-side-card h3 { font-size:0.95rem; font-weight:800; color:var(--text); margin:0 0 0.85rem; letter-spacing:-0.01em; }
        .mr-amenities-list { display:grid; grid-template-columns:1fr 1fr; gap:0.55rem 0.75rem; }
        .mr-amenity-row { display:flex; align-items:center; gap:0.5rem; font-size:0.82rem; color:var(--text); }
        .mr-amenity-row svg { width:14px; height:14px; stroke:#10b981; fill:none; stroke-width:2.5; flex-shrink:0; }

        .mr-rules-list { list-style:none; padding:0; margin:0; }
        .mr-rules-list li { display:flex; align-items:flex-start; gap:0.6rem; padding:0.55rem 0; font-size:0.85rem; color:var(--text); border-bottom:1px dashed var(--border); }
        .mr-rules-list li:last-child { border-bottom:none; }
        .mr-rules-num { width:20px; height:20px; border-radius:50%; background:var(--primary-soft); color:var(--primary); font-size:0.68rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; }

        /* Quick actions */
        .mr-quick-actions { display:grid; grid-template-columns:repeat(4, 1fr); gap:0.85rem; }
        @media (max-width: 900px) { .mr-quick-actions { grid-template-columns:repeat(2, 1fr); } }
        @media (max-width: 480px) { .mr-quick-actions { grid-template-columns:1fr; } }
        .mr-quick { display:flex; align-items:center; gap:0.8rem; background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:0.95rem 1.05rem; text-decoration:none; color:var(--text); transition:all 0.15s ease; box-shadow:var(--shadow-soft); }
        .mr-quick:hover { transform:translateY(-2px); border-color:var(--primary); box-shadow:0 10px 24px rgba(99,102,241,0.12); }
        .mr-quick-icon { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .mr-quick-icon svg { width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:2; }
        .mr-quick-title { font-size:0.85rem; font-weight:700; color:var(--text); line-height:1.2; }
        .mr-quick-desc { font-size:0.72rem; color:var(--muted); margin-top:3px; }

        /* Transfer-pending banner */
        .mr-transfer-banner { display:flex; gap:0.7rem; align-items:center; background:#fef3c7; border:1.5px solid #fcd34d; border-radius:12px; padding:0.85rem 1.1rem; margin-bottom:1.25rem; box-shadow:var(--shadow-soft); }
        .mr-transfer-banner svg { width:18px; height:18px; stroke:#b45309; fill:none; stroke-width:2; flex-shrink:0; }
        .mr-transfer-banner-body { flex:1; font-size:0.85rem; color:#78350f; }
        .mr-transfer-banner-body b { font-weight:700; }
    </style>
</head>
<body>
<?php echo render_sidebar('room.php'); ?>
<?= render_topbar() ?>
<div class="container">
    <div class="page-header"><h1>My Room</h1></div>

    <?php if ($room && $room['room_number']): ?>
        <!-- Status Banner -->
        <div class="mr-status-banner">
            <div class="mr-status-icon" style="background:<?= $is_approved?'rgba(16,185,129,0.1)':'rgba(245,158,11,0.1)' ?>;color:<?= $is_approved?'#059669':'#b45309' ?>;">
                <?php if ($is_approved): ?>
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div class="mr-status-title"><?= $is_approved ? 'Room Confirmed' : 'Pending Approval' ?></div>
                <div class="mr-status-sub"><?= $is_approved ? 'Your room is active. Welcome to HMS!' : 'Your room is assigned and waiting for warden approval.' ?></div>
            </div>
            <span class="badge <?= $is_approved?'badge-green':'badge-pending' ?>" style="flex-shrink:0;"><?= $is_approved?'Active':'Pending' ?></span>
        </div>

        <?php if ($pending_transfer): ?>
        <div class="mr-transfer-banner">
            <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            <div class="mr-transfer-banner-body">
                <b>Transfer request pending</b> &mdash; You've requested a move to Room <?= e($pending_transfer['to_room']) ?>, submitted <?= e(date('d M Y', strtotime($pending_transfer['created_at']))) ?>. Awaiting warden review.
            </div>
        </div>
        <?php endif; ?>

        <!-- HERO: Photo + Info -->
        <div class="mr-hero">
            <div class="mr-photo-wrap">
                <img src="<?= e($room_photo_url) ?>" alt="Room <?= e($room['room_number']) ?>">
                <div class="mr-photo-gradient"></div>
                <div class="mr-photo-content">
                    <span class="mr-photo-type"><?= e($room['room_type'] ?? 'Standard') ?> Sharing</span>
                    <div class="mr-photo-num">Room <?= e($room['room_number']) ?></div>
                    <div class="mr-photo-meta">Floor <?= e((string)($room['floor'] ?? '—')) ?> &middot; <?= (int)($room['capacity'] ?? 1) ?>-person capacity</div>
                </div>
            </div>

            <div class="mr-info-card">
                <div class="mr-info-grid">
                    <div class="mr-info-box">
                        <div class="mr-info-head">
                            <span class="mr-info-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                                <svg viewBox="0 0 24 24"><path d="M3 6a2 2 0 0 0 0 4h18a2 2 0 0 0 0-4H3z"/><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10"/></svg>
                            </span>
                            <span class="mr-info-label">Room Number</span>
                        </div>
                        <div class="mr-info-value"><?= e($room['room_number']) ?></div>
                        <div class="mr-info-sub">Floor <?= e((string)($room['floor'] ?? '—')) ?></div>
                    </div>
                    <div class="mr-info-box">
                        <div class="mr-info-head">
                            <span class="mr-info-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <span class="mr-info-label">Occupancy</span>
                        </div>
                        <div class="mr-info-value"><?= 1 + count($roommates) ?> / <?= (int)($room['capacity'] ?? 1) ?></div>
                        <div class="mr-info-sub"><?= count($roommates) ?> roommate<?= count($roommates) === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="mr-info-box">
                        <div class="mr-info-head">
                            <span class="mr-info-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                            </span>
                            <span class="mr-info-label">Monthly Fee</span>
                        </div>
                        <div class="mr-info-value">NPR <?= number_format((float)($room['price'] ?? 0), 0) ?></div>
                        <div class="mr-info-sub">per month, all-inclusive</div>
                    </div>
                    <div class="mr-info-box">
                        <div class="mr-info-head">
                            <span class="mr-info-icon" style="background:<?= $fee_state==='paid'?'rgba(16,185,129,0.1)':($fee_state==='overdue'?'rgba(239,68,68,0.1)':'rgba(245,158,11,0.1)') ?>;color:<?= $fee_state==='paid'?'#059669':($fee_state==='overdue'?'#dc2626':'#b45309') ?>;">
                                <svg viewBox="0 0 24 24"><path d="M16 4h-2V2h-4v2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/></svg>
                            </span>
                            <span class="mr-info-label">This Month</span>
                        </div>
                        <div class="mr-info-value" style="color:<?= $fee_state==='paid'?'#059669':($fee_state==='overdue'?'#dc2626':'var(--text)') ?>;">
                            <?= $fee_state ? ucfirst($fee_state) : 'No bill' ?>
                        </div>
                        <div class="mr-info-sub"><?= e(date('F Y')) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Description -->
        <div class="mr-description">
            <h3>About this room</h3>
            <p><?= e($room_description) ?></p>
            <div class="mr-description-foot">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" style="width:14px;height:14px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>Booking ID <strong>#<?= isset($booking['booking_id']) ? (int)$booking['booking_id'] : '—' ?></strong></span>
                <?php if ($booking && !empty($booking['start_date'])): ?>
                <span>&middot;</span>
                <span>Check-in <strong><?= e(date('d M Y', strtotime($booking['start_date']))) ?></strong></span>
                <?php endif; ?>
                <span style="margin-left:auto;">Status <strong><?= e(ucfirst($user_room_status)) ?></strong></span>
            </div>
        </div>

        <!-- Roommates -->
        <div class="mr-section-head">
            <h2>Your Roommates</h2>
            <span class="pill"><?= count($roommates) ?> sharing</span>
        </div>
        <?php if ($roommates): ?>
            <div class="mr-rm-grid" style="margin-bottom:1.5rem;">
                <?php foreach ($roommates as $rm): ?>
                    <div class="mr-rm-card">
                        <div class="mr-rm-avatar"><?= e(rm_initials($rm['name'])) ?></div>
                        <div style="min-width:0;">
                            <div class="mr-rm-name"><?= e($rm['name']) ?></div>
                            <div class="mr-rm-email"><?= e($rm['email']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="mr-rm-empty" style="margin-bottom:1.5rem;">
                <b>You have the room to yourself</b>
                <?php if ((int)($room['capacity'] ?? 1) > 1): ?>
                    A roommate may be assigned later — check back if your room is shared-capacity.
                <?php else: ?>
                    This is a single-occupancy room — private and quiet.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Amenities + Rules side by side -->
        <div class="mr-two-col">
            <div class="mr-side-card">
                <h3>What's Included</h3>
                <div class="mr-amenities-list">
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> 100 Mbps WiFi</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Three meals/day</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Laundry 2×/week</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Daily housekeeping</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Power backup</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> 24/7 security</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Furnished room</div>
                    <div class="mr-amenity-row"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Quiet study zones</div>
                </div>
            </div>

            <div class="mr-side-card">
                <h3>Hostel Guidelines</h3>
                <ul class="mr-rules-list">
                    <li><span class="mr-rules-num">1</span><span>Keep your room clean and tidy at all times.</span></li>
                    <li><span class="mr-rules-num">2</span><span>Curfew: 8:00 PM &mdash; main gate closes at this time.</span></li>
                    <li><span class="mr-rules-num">3</span><span>Visitors allowed in common lounge 9 AM &ndash; 8 PM with prior warden notice.</span></li>
                    <li><span class="mr-rules-num">4</span><span>Quiet hours 10 PM &ndash; 6 AM &mdash; respect your roommates and floor.</span></li>
                    <li><span class="mr-rules-num">5</span><span>Report damage or issues via the Complaints section.</span></li>
                </ul>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mr-section-head">
            <h2>Quick Actions</h2>
        </div>
        <div class="mr-quick-actions">
            <a href="payments.php" class="mr-quick">
                <span class="mr-quick-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/></svg>
                </span>
                <div>
                    <div class="mr-quick-title">Pay Fees</div>
                    <div class="mr-quick-desc">View &amp; pay your bill</div>
                </div>
            </a>
            <a href="request_transfer.php" class="mr-quick">
                <span class="mr-quick-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;">
                    <svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                </span>
                <div>
                    <div class="mr-quick-title">Request Transfer</div>
                    <div class="mr-quick-desc">Move to another room</div>
                </div>
            </a>
            <a href="my_complaints.php" class="mr-quick">
                <span class="mr-quick-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                    <svg viewBox="0 0 24 24"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>
                </span>
                <div>
                    <div class="mr-quick-title">Raise Complaint</div>
                    <div class="mr-quick-desc">Report any issue</div>
                </div>
            </a>
            <a href="request_leave.php" class="mr-quick">
                <span class="mr-quick-icon" style="background:rgba(236,72,153,0.1);color:#ec4899;">
                    <svg viewBox="0 0 24 24"><circle cx="18" cy="15" r="3"/><path d="M13.172 5.172a4 4 0 0 0-5.656 0l-6 6a4 4 0 1 0 5.656 5.656l6-6"/></svg>
                </span>
                <div>
                    <div class="mr-quick-title">Request Leave</div>
                    <div class="mr-quick-desc">Apply for time off</div>
                </div>
            </a>
        </div>

    <?php else: ?>
        <div class="card" style="text-align:center; padding:5rem 2rem;">
            <h2 style="margin-bottom:0.5rem; font-weight:800;">No Room Allocated</h2>
            <p style="color:var(--text-muted); max-width:400px; margin:0 auto 2rem;">
                Your registration is complete. The Warden will assign you a room shortly, or you can browse available rooms.
            </p>
            <a href="browse_rooms.php" class="btn btn-primary">Browse Available Rooms</a>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../chatbot/widget.php'; ?>
<script src="../js/script.js"></script>
</body>
</html>
