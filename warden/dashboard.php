<?php
// warden/dashboard.php — Warden portal dashboard
require_once '../db.php';
require_role('warden');

$name  = $_SESSION['user_name'];
$photo = $_SESSION['user_photo'] ?? 'default.png';
$today = date('Y-m-d');

// Avatar initials
$parts    = array_values(array_filter(explode(' ', trim($name))));
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));
$photo_url = ($photo !== 'default.png') ? '../uploads/students/' . rawurlencode($photo) : null;

// Total students
$total_students = 0;
try {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
} catch (Exception $e) {}

// Room occupancy
$occupied_rooms = $total_rooms = 0;
try {
    $rs = $pdo->query("SELECT COUNT(*) AS total, SUM(status='occupied') AS occupied FROM rooms")->fetch();
    $total_rooms    = (int)($rs['total']    ?? 0);
    $occupied_rooms = (int)($rs['occupied'] ?? 0);
} catch (Exception $e) {}

// Today's attendance
$present_today = $absent_today = 0;
try {
    $att = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM attendance WHERE date = ? GROUP BY status");
    $att->execute([$today]);
    foreach ($att->fetchAll() as $row) {
        if ($row['status'] === 'present') $present_today = (int)$row['cnt'];
        if ($row['status'] === 'absent')  $absent_today  = (int)$row['cnt'];
    }
} catch (Exception $e) {}
$marked_today   = $present_today + $absent_today;
$attendance_pct = $total_students > 0 ? round($present_today / $total_students * 100, 1) : 0;

// Open complaints
$open_complaints = 0;
try {
    $open_complaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status = 'open'")->fetchColumn();
} catch (Exception $e) {}

// Pending bookings
$pending_bookings = 0;
try {
    $pending_bookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending_approval'")->fetchColumn();
} catch (Exception $e) {}

// Pending leaves
$pending_leaves = 0;
try {
    $pending_leaves = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE warden_status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

// Pending room transfers
$pending_transfers = 0;
try {
    $pending_transfers = (int)$pdo->query("SELECT COUNT(*) FROM room_transfers WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

// Recent students
$recent_students = [];
try {
    $rs2 = $pdo->query(
        "SELECT id, name, student_phone, room_preference, created_at, photo
         FROM users WHERE role = 'student'
         ORDER BY created_at DESC LIMIT 5"
    );
    $recent_students = $rs2->fetchAll();
} catch (Exception $e) {}

// Students with low attendance (<70%) in last 30 days
$low_attendance = [];
try {
    $la_from = date('Y-m-d', strtotime('-29 days'));
    $la_stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.photo,
                COUNT(a.id) AS marked,
                SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_cnt,
                MAX(CASE WHEN a.status='present' THEN a.date END) AS last_present
         FROM users u
         JOIN attendance a ON a.student_id = u.id AND a.date BETWEEN ? AND CURDATE()
         WHERE u.role='student'
         GROUP BY u.id
         HAVING marked > 0 AND (present_cnt / marked) < 0.7
         ORDER BY (present_cnt / marked) ASC, marked DESC
         LIMIT 6"
    );
    $la_stmt->execute([$la_from]);
    $low_attendance = $la_stmt->fetchAll();
} catch (Exception $e) { /* attendance table missing */ }

// Recent open complaints (last 4 unresolved)
$recent_complaints = [];
try {
    $rc = $pdo->query(
        "SELECT c.id, c.subject, c.message, c.created_at,
                u.id AS student_id, u.name AS student_name, u.photo
         FROM complaints c
         JOIN users u ON u.id = c.student_id
         WHERE c.status = 'open'
         ORDER BY c.created_at DESC
         LIMIT 4"
    );
    $recent_complaints = $rc->fetchAll();
} catch (Exception $e) { /* table may not exist */ }

// Students on leave today (approved, covering today's date)
$on_leave_today = [];
try {
    $lt = $pdo->query(
        "SELECT l.start_date, l.end_date, l.reason,
                u.id AS student_id, u.name, u.photo
         FROM leaves l
         JOIN users u ON u.id = l.student_id
         WHERE l.warden_status = 'approved'
           AND CURDATE() BETWEEN l.start_date AND l.end_date
         ORDER BY l.end_date ASC
         LIMIT 6"
    );
    $on_leave_today = $lt->fetchAll();
} catch (Exception $e) { /* table may not exist or different schema */ }

// ── Month-calendar attendance view ──
$today_str = date('Y-m-d');

// Selected month from ?month=YYYY-MM (default: current month)
$cal_month_param = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $cal_month_param)) $cal_month_param = date('Y-m');
$cal_first = $cal_month_param . '-01';
$cal_last  = date('Y-m-t', strtotime($cal_first));
$cal_title = date('F Y', strtotime($cal_first));
$prev_month = date('Y-m', strtotime("$cal_first -1 month"));
$next_month = date('Y-m', strtotime("$cal_first +1 month"));
$is_current_month = ($cal_month_param === date('Y-m'));

// Fetch attendance for the selected month
$cal_daily = [];
try {
    $cd = $pdo->prepare(
        "SELECT DATE(date) AS d,
                SUM(status='present') AS p,
                SUM(status='absent')  AS a
         FROM attendance
         WHERE date BETWEEN ? AND ?
         GROUP BY DATE(date)"
    );
    $cd->execute([$cal_first, $cal_last]);
    foreach ($cd->fetchAll() as $row) {
        $cal_daily[$row['d']] = ['p' => (int)$row['p'], 'a' => (int)$row['a']];
    }
} catch (Exception $e) {}

// Build full calendar grid: start from the Sunday before/on the first of the month
$grid_start = date('Y-m-d', strtotime($cal_first . ' -' . ((int)date('w', strtotime($cal_first))) . ' days'));
$grid_end_dow = (int)date('w', strtotime($cal_last));
$grid_end = date('Y-m-d', strtotime($cal_last . ' +' . (6 - $grid_end_dow) . ' days'));
$cal_cells = [];
$cursor = $grid_start;
while ($cursor <= $grid_end) {
    $in_month = (substr($cursor, 0, 7) === $cal_month_param);
    $row = $cal_daily[$cursor] ?? null;
    $p   = $row['p'] ?? 0;
    $a   = $row['a'] ?? 0;
    $tot = $p + $a;
    $pct = $tot > 0 ? (int)round($p / $tot * 100) : null;
    $is_future = ($cursor > $today_str);
    $bucket = 'unmarked';
    if (!$is_future && $in_month) {
        if ($pct === null)      $bucket = 'unmarked';
        elseif ($pct >= 90)     $bucket = 'excellent';
        elseif ($pct >= 70)     $bucket = 'good';
        elseif ($pct >= 50)     $bucket = 'average';
        else                    $bucket = 'poor';
    }
    $cal_cells[] = [
        'date'      => $cursor,
        'day'       => (int)date('j', strtotime($cursor)),
        'in_month'  => $in_month,
        'is_today'  => ($cursor === $today_str),
        'is_future' => $is_future,
        'present'   => $p,
        'absent'    => $a,
        'pct'       => $pct,
        'bucket'    => $bucket,
    ];
    $cursor = date('Y-m-d', strtotime("$cursor +1 day"));
}

// Month summary
$cal_total_p = array_sum(array_column($cal_daily, 'p'));
$cal_total_a = array_sum(array_column($cal_daily, 'a'));
$cal_total   = $cal_total_p + $cal_total_a;
$cal_avg_pct = $cal_total > 0 ? (int)round($cal_total_p / $cal_total * 100) : null;
$cal_days_marked = count($cal_daily);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warden Dashboard &mdash; HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .att-chart-card { padding: 1.2rem 1.4rem 1.3rem; margin-bottom: 1.25rem; }
        .att-chart-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.1rem; flex-wrap: wrap; }
        .att-chart-head h3 { font-size: 1.05rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.015em; }
        .att-chart-head .att-window { font-size: 0.78rem; font-weight: 600; color: var(--muted); }

        /* 2-column row: calendar + side panel */
        .dash-row-2col {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.25rem;
            align-items: start;
        }
        @media (max-width: 1100px) { .dash-row-2col { grid-template-columns: 1fr; } }
        .dash-row-2col > .card { margin-bottom: 0; }

        /* Side panel — stacks multiple cards in the right column */
        .dash-side {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .dash-side > .card { margin-bottom: 0; }

        /* Month calendar — compact */
        .att-chart-card { padding: 1.1rem 1.25rem 1.2rem; }
        .att-chart-card .att-chart-head h3 { font-size: 0.98rem; }

        .cal-nav { display: flex; align-items: center; gap: 0.35rem; }
        .cal-nav .cal-month-label { font-size: 0.84rem; font-weight: 800; color: var(--text); letter-spacing: -0.01em; min-width: 105px; text-align: center; }
        .cal-nav a, .cal-nav .nav-disabled {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px;
            border-radius: 7px;
            background: var(--panel-alt);
            border: 1.5px solid var(--border);
            color: var(--text);
            text-decoration: none;
            transition: all 0.12s ease;
        }
        .cal-nav a:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-soft); }
        .cal-nav .nav-disabled { opacity: 0.4; cursor: not-allowed; }
        .cal-nav svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        .cal-summary { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.85rem; font-size: 0.78rem; color: var(--muted); }
        .cal-summary .cs-num { font-size: 0.92rem; font-weight: 800; color: var(--text); margin-right: 0.25rem; letter-spacing: -0.01em; }

        .cal-dow {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-bottom: 4px;
        }
        .cal-dow span {
            font-size: 0.62rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            text-align: center;
            padding: 0.25rem 0;
        }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .cal-cell {
            aspect-ratio: 1;
            display: flex; flex-direction: column;
            justify-content: space-between;
            padding: 0.35rem 0.4rem;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            background: var(--panel);
            cursor: default;
            position: relative;
            transition: border-color 0.12s ease, background 0.12s ease;
        }
        html[data-theme="dark"] .cal-cell { border-color: rgba(148,163,184,0.35); }
        .cal-cell.outside { opacity: 0.3; background: transparent; border-style: dashed; }
        .cal-cell.today { border-color: var(--primary); border-width: 3px; }
        .cal-cell.has-data:hover { border-color: var(--text); }
        html[data-theme="dark"] .cal-cell.has-data:hover { border-color: #f1f5f9; }

        .cal-day-num { font-size: 0.78rem; font-weight: 800; color: var(--text); letter-spacing: -0.01em; line-height: 1; }
        .cal-cell.today .cal-day-num { color: var(--primary); }
        .cal-pct {
            font-size: 0.66rem; font-weight: 800;
            letter-spacing: -0.01em;
            display: inline-flex; align-items: center;
            gap: 0.2rem;
        }
        .cal-pct::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .cal-meta { font-size: 0.6rem; color: var(--muted); font-weight: 600; letter-spacing: 0.02em; }

        /* Bucket: bold colored borders, no fills */
        .cal-cell.b-excellent { border-color: #10b981; background: rgba(16,185,129,0.06); }
        .cal-cell.b-good      { border-color: #3b82f6; background: rgba(59,130,246,0.05); }
        .cal-cell.b-average   { border-color: #f59e0b; background: rgba(245,158,11,0.06); }
        .cal-cell.b-poor      { border-color: #ef4444; background: rgba(239,68,68,0.06); }
        .cal-cell.b-excellent .cal-pct { color: #059669; }
        .cal-cell.b-good      .cal-pct { color: #1d4ed8; }
        .cal-cell.b-average   .cal-pct { color: #b45309; }
        .cal-cell.b-poor      .cal-pct { color: #b91c1c; }

        html[data-theme="dark"] .cal-cell.b-excellent { border-color: #34d399; background: rgba(52,211,153,0.1); }
        html[data-theme="dark"] .cal-cell.b-good      { border-color: #60a5fa; background: rgba(96,165,250,0.1); }
        html[data-theme="dark"] .cal-cell.b-average   { border-color: #fbbf24; background: rgba(251,191,36,0.1); }
        html[data-theme="dark"] .cal-cell.b-poor      { border-color: #f87171; background: rgba(248,113,113,0.1); }
        html[data-theme="dark"] .cal-cell.b-excellent .cal-pct { color: #34d399; }
        html[data-theme="dark"] .cal-cell.b-good      .cal-pct { color: #93c5fd; }
        html[data-theme="dark"] .cal-cell.b-average   .cal-pct { color: #fcd34d; }
        html[data-theme="dark"] .cal-cell.b-poor      .cal-pct { color: #fca5a5; }

        /* On-leave-today card */
        .leave-today-card { padding: 1.1rem 1.25rem 1.2rem; }
        .leave-today-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
        .leave-today-head h3 { font-size: 0.98rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.015em; }
        .leave-today-head .lt-count {
            display: inline-flex; align-items: center;
            padding: 0.22rem 0.6rem;
            background: rgba(245,158,11,0.14);
            color: #b45309;
            border: 1px solid rgba(245,158,11,0.28);
            border-radius: 999px;
            font-size: 0.7rem; font-weight: 800;
        }
        html[data-theme="dark"] .leave-today-head .lt-count { background: rgba(251,191,36,0.18); color: #fbbf24; border-color: rgba(251,191,36,0.3); }

        .leave-list {
            display: flex; flex-direction: column;
            gap: 0.55rem;
            max-height: 360px;
            overflow-y: auto;
        }
        .leave-item {
            display: flex; align-items: center; gap: 0.7rem;
            padding: 0.65rem 0.85rem;
            background: #fefce8;
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: 10px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        html[data-theme="dark"] .leave-item { background: rgba(251,191,36,0.07); border-color: rgba(251,191,36,0.22); }
        .leave-item:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(245,158,11,0.1); }
        .leave-item .leave-avatar {
            width: 34px; height: 34px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700; font-size: 0.72rem;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
        }
        .leave-item .leave-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 999px; }
        .leave-item .leave-body { min-width: 0; flex: 1; }
        .leave-item .leave-name { font-weight: 700; color: var(--text); font-size: 0.86rem; line-height: 1.2; }
        .leave-item .leave-meta { font-size: 0.72rem; color: var(--muted); margin-top: 2px; line-height: 1.3; }
        .leave-item .leave-meta strong { color: var(--text); font-weight: 700; }

        .leave-empty {
            display: flex; align-items: center; gap: 1rem;
            padding: 1.1rem 1.25rem;
            background: var(--panel-alt);
            border: 1.5px solid var(--border);
            border-radius: 12px;
        }
        html[data-theme="dark"] .leave-empty { background: rgba(255,255,255,0.03); }
        .leave-empty-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(16,185,129,0.14);
            color: #059669;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        html[data-theme="dark"] .leave-empty-icon { background: rgba(52,211,153,0.18); color: #34d399; }
        .leave-empty-icon svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .leave-empty-text { min-width: 0; }
        .leave-empty-text h4 { font-size: 0.95rem; font-weight: 800; color: var(--text); margin: 0 0 0.15rem; letter-spacing: -0.01em; }
        .leave-empty-text p  { font-size: 0.82rem; color: var(--muted); margin: 0; line-height: 1.4; }

        /* Open complaints widget */
        .complaints-card { padding: 1.1rem 1.25rem 1.2rem; }
        .complaints-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
        .complaints-head h3 { font-size: 0.98rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.015em; }
        .complaints-head .cm-actions { display: flex; align-items: center; gap: 0.5rem; }
        .complaints-head .cm-count {
            display: inline-flex; align-items: center;
            padding: 0.22rem 0.6rem;
            background: rgba(239,68,68,0.12);
            color: #b91c1c;
            border: 1px solid rgba(239,68,68,0.28);
            border-radius: 999px;
            font-size: 0.7rem; font-weight: 800;
        }
        html[data-theme="dark"] .complaints-head .cm-count { background: rgba(248,113,113,0.16); color: #fca5a5; border-color: rgba(248,113,113,0.3); }
        .complaints-head .cm-view-all {
            font-size: 0.75rem; font-weight: 700; color: var(--primary); text-decoration: none;
        }
        .complaints-head .cm-view-all:hover { text-decoration: underline; }

        .complaints-list { display: flex; flex-direction: column; gap: 0.55rem; }
        .complaint-item {
            display: flex; gap: 0.7rem;
            padding: 0.65rem 0.85rem;
            background: #fef2f2;
            border: 1px solid rgba(239,68,68,0.18);
            border-radius: 10px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        html[data-theme="dark"] .complaint-item { background: rgba(248,113,113,0.07); border-color: rgba(248,113,113,0.22); }
        .complaint-item:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(239,68,68,0.1); }
        .complaint-avatar {
            width: 32px; height: 32px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700; font-size: 0.7rem;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
        }
        .complaint-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 999px; }
        .complaint-body { min-width: 0; flex: 1; }
        .complaint-name { font-weight: 700; color: var(--text); font-size: 0.83rem; line-height: 1.2; display: flex; align-items: baseline; gap: 0.4rem; }
        .complaint-name .cm-date { font-size: 0.68rem; font-weight: 500; color: var(--muted); margin-left: auto; flex-shrink: 0; }
        .complaint-subj { font-size: 0.78rem; color: var(--text); margin-top: 2px; line-height: 1.3; font-weight: 600; }
        .complaint-snip { font-size: 0.72rem; color: var(--muted); margin-top: 2px; line-height: 1.35; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

        .complaint-empty {
            display: flex; align-items: center; gap: 1rem;
            padding: 1.1rem 1.25rem;
            background: var(--panel-alt);
            border: 1.5px solid var(--border);
            border-radius: 12px;
        }
        html[data-theme="dark"] .complaint-empty { background: rgba(255,255,255,0.03); }
        .complaint-empty-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(16,185,129,0.14);
            color: #059669;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        html[data-theme="dark"] .complaint-empty-icon { background: rgba(52,211,153,0.18); color: #34d399; }
        .complaint-empty-icon svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .complaint-empty-text h4 { font-size: 0.95rem; font-weight: 800; color: var(--text); margin: 0 0 0.15rem; letter-spacing: -0.01em; }
        .complaint-empty-text p  { font-size: 0.8rem; color: var(--muted); margin: 0; line-height: 1.4; }

        /* Students-to-watch card */
        .watch-card { padding: 1.1rem 1.25rem 1.2rem; }
        .watch-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.9rem; flex-wrap: wrap; }
        .watch-head h3 { font-size: 0.98rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.015em; }
        .watch-head .wh-meta { font-size: 0.72rem; color: var(--muted); font-weight: 600; }
        .watch-head .wh-link { font-size: 0.75rem; font-weight: 700; color: var(--primary); text-decoration: none; }
        .watch-head .wh-link:hover { text-decoration: underline; }

        .watch-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .watch-item {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.7rem 0.85rem;
            border-radius: 10px;
            position: relative;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .watch-item:hover { transform: translateY(-1px); box-shadow: 0 4px 10px var(--wi-shadow, rgba(15,23,42,0.1)); }

        /* Whole-card tinted background — soft, humanized */
        .watch-item.severity-bad {
            background: #fef2f2;
            border: 1px solid rgba(239,68,68,0.22);
            --wi-shadow: rgba(239,68,68,0.1);
        }
        .watch-item.severity-warn {
            background: #fefce8;
            border: 1px solid rgba(245,158,11,0.22);
            --wi-shadow: rgba(245,158,11,0.1);
        }
        html[data-theme="dark"] .watch-item.severity-bad {
            background: rgba(248,113,113,0.08);
            border-color: rgba(248,113,113,0.22);
        }
        html[data-theme="dark"] .watch-item.severity-warn {
            background: rgba(251,191,36,0.08);
            border-color: rgba(251,191,36,0.22);
        }

        .watch-avatar {
            width: 32px; height: 32px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700; font-size: 0.7rem;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
        }
        .watch-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 999px; }
        .watch-body { min-width: 0; flex: 1; }
        .watch-name { font-weight: 700; color: var(--text); font-size: 0.84rem; line-height: 1.2; }
        .watch-meta { font-size: 0.7rem; color: var(--muted); margin-top: 2px; line-height: 1.3; }
        .watch-pct {
            font-weight: 800; font-size: 0.88rem;
            letter-spacing: -0.01em;
            flex-shrink: 0;
        }
        .watch-pct.bad  { color: #dc2626; }
        .watch-pct.warn { color: #d97706; }
        html[data-theme="dark"] .watch-pct.bad  { color: #fca5a5; }
        html[data-theme="dark"] .watch-pct.warn { color: #fcd34d; }

        .watch-empty {
            display: flex; align-items: center; gap: 1rem;
            padding: 1.1rem 1.25rem;
            background: var(--panel-alt);
            border: 1.5px solid var(--border);
            border-radius: 12px;
        }
        html[data-theme="dark"] .watch-empty { background: rgba(255,255,255,0.03); }
        .watch-empty-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(16,185,129,0.14);
            color: #059669;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        html[data-theme="dark"] .watch-empty-icon { background: rgba(52,211,153,0.18); color: #34d399; }
        .watch-empty-icon svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2.5; }
        .watch-empty-text h4 { font-size: 0.95rem; font-weight: 800; color: var(--text); margin: 0 0 0.15rem; letter-spacing: -0.01em; }
        .watch-empty-text p  { font-size: 0.82rem; color: var(--muted); margin: 0; line-height: 1.4; }
    </style>
</head>
<body>
<?= render_sidebar('dashboard.php') ?>
<?= render_topbar('Dashboard') ?>

<div class="app-shell">
    <main class="shell-main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
            </div>
            <div class="ph-date">
                <span class="ph-day"><?= date('l') ?></span>
                <span class="ph-full"><?= date('d M Y') ?></span>
            </div>
        </div>

        <!-- Stats grid -->
        <div class="stats-grid" style="margin-bottom:1.75rem;">

            <a class="stat-box stat-link stat-indigo" href="room_requests.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('bed') ?></div>
                <div class="stat-label">Occupancy</div>
                <div class="stat-value"><?= $occupied_rooms ?>/<?= $total_rooms ?></div>
                <div class="stat-meta">Occupied rooms</div>
            </a>

            <a class="stat-box stat-link stat-green" href="student_attendance.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('checkmark') ?></div>
                <div class="stat-label">Today's Attendance</div>
                <div class="stat-value" data-count="<?= $attendance_pct ?>" data-suffix="%"><?= $attendance_pct ?>%</div>
                <div class="stat-meta"><?= $present_today ?> of <?= $total_students ?> marked</div>
            </a>

            <a class="stat-box stat-link stat-amber" href="booking_requests.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('clipboard') ?></div>
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-value" data-count="<?= $pending_bookings ?>"><?= $pending_bookings ?></div>
                <div class="stat-meta">Need approval</div>
            </a>

            <a class="stat-box stat-link stat-red" href="complaints.php">
                <div class="stat-icon-bubble"><?= get_svg_icon('alert') ?></div>
                <div class="stat-label">Open Complaints</div>
                <div class="stat-value" data-count="<?= $open_complaints ?>"><?= $open_complaints ?></div>
                <div class="stat-meta">Awaiting review</div>
            </a>

        </div>

        <!-- Side-by-side: calendar + on-leave-today -->
        <div class="dash-row-2col">

        <!-- Attendance month calendar -->
        <div class="card att-chart-card">
            <div class="att-chart-head">
                <h3>Attendance Calendar</h3>
                <div class="cal-nav">
                    <a href="?month=<?= e($prev_month) ?>" title="Previous month" aria-label="Previous month">
                        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                    <span class="cal-month-label"><?= e($cal_title) ?></span>
                    <?php if (!$is_current_month): ?>
                        <a href="?month=<?= e($next_month) ?>" title="Next month" aria-label="Next month">
                            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    <?php else: ?>
                        <span class="nav-disabled" aria-disabled="true">
                            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cal-summary">
                <span><span class="cs-num"><?= $cal_avg_pct !== null ? $cal_avg_pct . '%' : '—' ?></span>Month avg</span>
                <span><span class="cs-num"><?= $cal_days_marked ?></span>Days marked</span>
                <span><span class="cs-num"><?= $cal_total_p ?></span>Total present</span>
                <span><span class="cs-num"><?= $cal_total_a ?></span>Total absent</span>
            </div>

            <div class="cal-dow">
                <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="cal-grid">
                <?php foreach ($cal_cells as $c):
                    $classes = ['cal-cell'];
                    if (!$c['in_month'])             $classes[] = 'outside';
                    if ($c['is_today'])              $classes[] = 'today';
                    if ($c['in_month'] && !$c['is_future'] && $c['pct'] !== null) {
                        $classes[] = 'has-data';
                        $classes[] = 'b-' . $c['bucket'];
                    }
                ?>
                <div class="<?= implode(' ', $classes) ?>"
                     <?php if ($c['in_month'] && $c['pct'] !== null): ?>
                        title="<?= e(date('D, d M Y', strtotime($c['date']))) ?> &middot; <?= $c['pct'] ?>% (<?= $c['present'] ?>P / <?= $c['absent'] ?>A)"
                     <?php elseif ($c['in_month'] && !$c['is_future']): ?>
                        title="<?= e(date('D, d M Y', strtotime($c['date']))) ?> &middot; Not marked"
                     <?php endif; ?>>
                    <span class="cal-day-num"><?= $c['day'] ?></span>
                    <?php if ($c['in_month'] && $c['pct'] !== null): ?>
                        <span class="cal-pct"><?= $c['pct'] ?>%</span>
                    <?php elseif ($c['in_month'] && !$c['is_future']): ?>
                        <span class="cal-meta">—</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Side stack: on-leave + open complaints -->
        <div class="dash-side">

        <!-- On leave today -->
        <div class="card leave-today-card">
            <div class="leave-today-head">
                <h3>Students On Leave Today</h3>
                <?php if (count($on_leave_today) > 0): ?>
                <span class="lt-count"><?= count($on_leave_today) ?> <?= count($on_leave_today) === 1 ? 'student' : 'students' ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($on_leave_today)): ?>
                <div class="leave-empty">
                    <div class="leave-empty-icon">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="leave-empty-text">
                        <h4>Everyone's accounted for</h4>
                        <p>No approved leaves cover today &mdash; all students should be on premises.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="leave-list">
                    <?php foreach ($on_leave_today as $lv):
                        $lphoto = $lv['photo'] ?? 'default.png';
                        $lparts = array_values(array_filter(explode(' ', trim($lv['name']))));
                        $linit  = strtoupper(substr($lparts[0] ?? 'S', 0, 1)) . strtoupper(substr($lparts[1] ?? '', 0, 1));
                        $ret_ts = strtotime($lv['end_date']);
                        $days_left = max(0, (int)floor(($ret_ts - strtotime('today')) / 86400));
                        $returns = $days_left === 0 ? 'Back tomorrow' : ($days_left === 1 ? 'Back in 1 day' : "Back in {$days_left} days");
                    ?>
                    <div class="leave-item">
                        <a href="../auth/profile.php?id=<?= (int)$lv['student_id'] ?>" class="leave-avatar" title="View profile">
                            <?php if ($lphoto && $lphoto !== 'default.png'): ?>
                                <img src="../uploads/students/<?= rawurlencode($lphoto) ?>" alt="<?= e($lv['name']) ?>" onerror="this.parentNode.textContent='<?= e($linit) ?>'">
                            <?php else: ?><?= e($linit) ?><?php endif; ?>
                        </a>
                        <div class="leave-body">
                            <div class="leave-name"><?= e($lv['name']) ?></div>
                            <div class="leave-meta">
                                <?= e(date('d M', strtotime($lv['start_date']))) ?> &rarr; <?= e(date('d M', $ret_ts)) ?>
                                &middot; <strong><?= e($returns) ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Open complaints -->
        <div class="card complaints-card">
            <div class="complaints-head">
                <h3>Open Complaints</h3>
                <div class="cm-actions">
                    <?php if (count($recent_complaints) > 0): ?>
                        <span class="cm-count"><?= $open_complaints ?> total</span>
                        <a href="complaints.php" class="cm-view-all">View all &rsaquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($recent_complaints)): ?>
                <div class="complaint-empty">
                    <div class="complaint-empty-icon">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="complaint-empty-text">
                        <h4>All clear</h4>
                        <p>No open complaints. Nicely handled.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="complaints-list">
                    <?php foreach ($recent_complaints as $c):
                        $cphoto = $c['photo'] ?? 'default.png';
                        $cparts = array_values(array_filter(explode(' ', trim($c['student_name']))));
                        $cinit  = strtoupper(substr($cparts[0] ?? 'S', 0, 1)) . strtoupper(substr($cparts[1] ?? '', 0, 1));
                        $ago_ts = strtotime($c['created_at']);
                        $diff_d = max(0, (int)floor((time() - $ago_ts) / 86400));
                        $ago    = $diff_d === 0 ? 'Today' : ($diff_d === 1 ? '1d ago' : "{$diff_d}d ago");
                    ?>
                    <div class="complaint-item">
                        <a href="../auth/profile.php?id=<?= (int)$c['student_id'] ?>" class="complaint-avatar" title="View profile">
                            <?php if ($cphoto && $cphoto !== 'default.png'): ?>
                                <img src="../uploads/students/<?= rawurlencode($cphoto) ?>" alt="<?= e($c['student_name']) ?>" onerror="this.parentNode.textContent='<?= e($cinit) ?>'">
                            <?php else: ?><?= e($cinit) ?><?php endif; ?>
                        </a>
                        <div class="complaint-body">
                            <div class="complaint-name">
                                <?= e($c['student_name']) ?>
                                <span class="cm-date"><?= e($ago) ?></span>
                            </div>
                            <?php if (!empty($c['subject'])): ?>
                                <div class="complaint-subj"><?= e($c['subject']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($c['message'])): ?>
                                <div class="complaint-snip"><?= e($c['message']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Students to watch (low attendance) -->
        <div class="card watch-card">
            <div class="watch-head">
                <h3>Students to Watch</h3>
                <?php if (!empty($low_attendance)): ?>
                    <a href="../owner/report_attendance.php" class="wh-link">Full report &rsaquo;</a>
                <?php else: ?>
                    <span class="wh-meta">Last 30 days</span>
                <?php endif; ?>
            </div>
            <?php if (empty($low_attendance)): ?>
                <div class="watch-empty">
                    <div class="watch-empty-icon">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="watch-empty-text">
                        <h4>Everyone's on track</h4>
                        <p>No students below 70% attendance over the last 30 days.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="watch-list">
                    <?php foreach ($low_attendance as $w):
                        $pct = $w['marked'] > 0 ? (int)round($w['present_cnt'] / $w['marked'] * 100) : 0;
                        $wphoto = $w['photo'] ?? 'default.png';
                        $wparts = array_values(array_filter(explode(' ', trim($w['name']))));
                        $winit  = strtoupper(substr($wparts[0] ?? 'S', 0, 1)) . strtoupper(substr($wparts[1] ?? '', 0, 1));
                        $sev_cls = $pct < 50 ? 'severity-bad' : 'severity-warn';
                        $pct_cls = $pct < 50 ? 'bad' : 'warn';
                    ?>
                    <div class="watch-item <?= $sev_cls ?>">
                        <a href="../auth/profile.php?id=<?= (int)$w['id'] ?>" class="watch-avatar" title="View profile">
                            <?php if ($wphoto && $wphoto !== 'default.png'): ?>
                                <img src="../uploads/students/<?= rawurlencode($wphoto) ?>" alt="<?= e($w['name']) ?>" onerror="this.parentNode.textContent='<?= e($winit) ?>'">
                            <?php else: ?><?= e($winit) ?><?php endif; ?>
                        </a>
                        <div class="watch-body">
                            <div class="watch-name"><?= e($w['name']) ?></div>
                            <div class="watch-meta"><?= (int)$w['present_cnt'] ?> / <?= (int)$w['marked'] ?> days</div>
                        </div>
                        <span class="watch-pct <?= $pct_cls ?>"><?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        </div><!-- /dash-side -->

        </div><!-- /dash-row-2col -->

        <!-- Recent students table -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">Recently Registered Students</div>
                    <div class="card-subtitle">Latest 5 new student registrations.</div>
                </div>
                <a href="list_student.php" class="btn btn-secondary" style="flex-shrink:0;">View All</a>
            </div>
            <?php if (empty($recent_students)): ?>
                <p style="color:var(--muted);font-size:0.875rem;">No students registered yet.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Room Preference</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_students as $s):
                            $sphoto = $s['photo'] ?? 'default.png';
                            $sparts = array_values(array_filter(explode(' ', trim($s['name']))));
                            $sinit  = strtoupper(substr($sparts[0] ?? 'S', 0, 1)) . strtoupper(substr($sparts[1] ?? '', 0, 1));
                        ?>
                        <tr>
                            <td>
                                <a href="../auth/profile.php?id=<?= (int)$s['id'] ?>"
                                   style="display:flex;align-items:center;gap:0.65rem;text-decoration:none;color:inherit;">
                                    <div class="rp-avatar" style="width:34px;height:34px;font-size:0.72rem;">
                                        <?php if ($sphoto && $sphoto !== 'default.png'): ?>
                                            <img src="../uploads/students/<?= rawurlencode($sphoto) ?>" alt="<?= e($s['name']) ?>" onerror="this.parentNode.textContent='<?= e($sinit) ?>'">
                                        <?php else: ?><?= e($sinit) ?><?php endif; ?>
                                    </div>
                                    <span style="font-weight:600;"><?= e($s['name']) ?></span>
                                </a>
                            </td>
                            <td><?= e($s['student_phone'] ?? '') ?></td>
                            <td><?= e($s['room_preference'] ?? '') ?></td>
                            <td style="color:var(--muted);font-size:0.82rem;"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Right panel -->
    <aside class="right-panel">

        <!-- Profile card -->
        <div class="rp-card">
            <div class="rp-card-title">Your Profile</div>
            <a href="../auth/profile.php" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.85rem;text-decoration:none;color:inherit;">
                <div class="rp-avatar">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" alt="<?= e($name) ?>" onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                    <?php else: ?><?= e($initials) ?><?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:0.9rem;"><?= e($name) ?></div>
                    <div style="font-size:0.78rem;color:var(--muted);">Warden</div>
                </div>
            </a>
            <hr class="rp-divider">
            <div style="display:flex;gap:1.25rem;margin-bottom:0.75rem;">
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $total_students ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Total students</div>
                </div>
                <div>
                    <div style="font-size:1.25rem;font-weight:700;color:var(--text);line-height:1;"><?= $present_today ?></div>
                    <div style="font-size:0.72rem;color:var(--muted);">Present today</div>
                </div>
            </div>
        </div>

        <!-- Action Items -->
        <div class="rp-card">
            <div class="rp-card-title">Action Items</div>
            <a href="booking_requests.php" class="rp-link-row">
                Booking Requests
                <span class="rp-link-count<?= $pending_bookings > 0 ? ' amber' : '' ?>"><?= $pending_bookings ?></span>
            </a>
            <a href="room_transfers.php" class="rp-link-row">
                Transfer Requests
                <span class="rp-link-count<?= $pending_transfers > 0 ? ' amber' : '' ?>"><?= $pending_transfers ?></span>
            </a>
            <a href="manage_leaves.php" class="rp-link-row">
                Pending Leaves
                <span class="rp-link-count<?= $pending_leaves > 0 ? ' amber' : '' ?>"><?= $pending_leaves ?></span>
            </a>
            <a href="complaints.php" class="rp-link-row">
                Open Complaints
                <span class="rp-link-count<?= $open_complaints > 0 ? ' red' : '' ?>"><?= $open_complaints ?></span>
            </a>
            <a href="list_student.php" class="rp-link-row">
                Student List
                <span class="rp-link-count"><?= $total_students ?></span>
            </a>
            <a href="../owner/report_attendance.php" class="rp-link-row">
                Attendance Report
                <span style="color:var(--muted);font-size:0.78rem;">&rsaquo;</span>
            </a>
        </div>

    </aside>
</div>

<?= render_footer() ?>
<script src="../js/script.js"></script>
</body>
</html>
