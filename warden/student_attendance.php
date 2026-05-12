<?php
// ─────────────────────────────────────────────────
// warden/student_attendance.php — Mark Attendance
// Two-column layout: sticky calendar + donut | student card grid
// ─────────────────────────────────────────────────

require_once '../db.php';
require_once '../auth/mailer.php';
require_role_any(['warden', 'owner']);

$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// ── CSV Download ────────────────────────────────────────────────────────────
if (!empty($_GET['download_csv'])) {
    $csv_date = trim($_GET['download_csv']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $csv_date)) $csv_date = $today;

    $all_users = $pdo->query(
        "SELECT u.id, u.name, u.student_phone FROM users u WHERE u.role='student' ORDER BY u.name ASC"
    )->fetchAll();

    $att_map = [];
    $a_stmt  = $pdo->prepare("SELECT student_id, status FROM attendance WHERE date = ?");
    $a_stmt->execute([$csv_date]);
    foreach ($a_stmt->fetchAll() as $row) $att_map[(int)$row['student_id']] = $row['status'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . $csv_date . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Name', 'Phone', 'Status', 'Date']);
    $i = 1;
    foreach ($all_users as $s) {
        fputcsv($out, [
            $i++,
            $s['name'],
            $s['student_phone'] ?? '',
            ucfirst($att_map[(int)$s['id']] ?? 'unmarked'),
            $csv_date,
        ]);
    }
    fclose($out);
    exit;
}

// ── AJAX single-student save ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    $sid    = (int)($_POST['student_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $date   = trim($_POST['att_date'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;

    if ($sid <= 0 || !in_array($status, ['present', 'absent'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    // Upsert attendance
    $chk = $pdo->prepare("SELECT id FROM attendance WHERE student_id=? AND date=? LIMIT 1");
    $chk->execute([$sid, $date]);
    if ($chk->fetch()) {
        $pdo->prepare("UPDATE attendance SET status=? WHERE student_id=? AND date=?")->execute([$status, $sid, $date]);
    } else {
        $pdo->prepare("INSERT INTO attendance (student_id, date, status) VALUES (?,?,?)")->execute([$sid, $date, $status]);
    }

    // Email on absent
    if ($status === 'absent') {
        try {
            $st = $pdo->prepare("SELECT name, email FROM users WHERE id=? LIMIT 1");
            $st->execute([$sid]);
            $stu = $st->fetch();
            if ($stu && !empty($stu['email'])) {
                $html = render_branded_email([
                    'name'      => $stu['name'],
                    'kicker'    => 'Attendance',
                    'title'     => 'Absence recorded',
                    'intro'     => 'This is to inform you that your attendance has been marked absent.',
                    'body_html' => '<p style="color:#334155;">Date: <strong>' . e($date) . '</strong></p>',
                    'footnote'  => 'If you believe this is an error, please contact the hostel warden.',
                    'accent'    => '#ef4444',
                    'accent2'   => '#dc2626',
                ]);
                send_app_mail($stu['email'], $stu['name'], 'Attendance Absent — ' . $date, $html);
            }
        } catch (Exception $e) {
            // Silently continue — don't fail the AJAX response on mail error
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

// ── BULK SAVE (form submit) ───────────────────────────────────────────────────
$save_msg = $save_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['ajax'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $att_date = trim($_POST['att_date'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) $att_date = $today;

    $data = $_POST['attendance'] ?? [];
    foreach ($data as $sid => $status) {
        $sid    = (int)$sid;
        $status = trim($status);
        if ($sid <= 0 || !in_array($status, ['present', 'absent'])) continue;
        $chk = $pdo->prepare("SELECT id FROM attendance WHERE student_id=? AND date=? LIMIT 1");
        $chk->execute([$sid, $att_date]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE attendance SET status=? WHERE student_id=? AND date=?")->execute([$status, $sid, $att_date]);
        } else {
            $pdo->prepare("INSERT INTO attendance (student_id, date, status) VALUES (?,?,?)")->execute([$sid, $att_date, $status]);
        }
    }
    $save_msg  = 'Attendance saved for ' . date('d M Y', strtotime($att_date)) . '.';
    $save_type = 'success';
}

// ── Calendar params ───────────────────────────────────────────────────────────
$sel_date = trim($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sel_date)) $sel_date = $today;

$cal_year  = (int)($_GET['cal_year']  ?? date('Y', strtotime($sel_date)));
$cal_month = (int)($_GET['cal_month'] ?? date('n', strtotime($sel_date)));
if ($cal_month < 1)  { $cal_month = 12; $cal_year--; }
if ($cal_month > 12) { $cal_month = 1;  $cal_year++; }

$cal_first   = mktime(0, 0, 0, $cal_month, 1,  $cal_year);
$cal_last_d  = (int)date('t', $cal_first);
$cal_month_s = date('Y-m', $cal_first);

// Dates with attendance records this month
$range_start = $cal_month_s . '-01';
$range_end   = $cal_month_s . '-' . str_pad($cal_last_d, 2, '0', STR_PAD_LEFT);
$dot_stmt = $pdo->prepare("SELECT DISTINCT date FROM attendance WHERE date BETWEEN ? AND ?");
$dot_stmt->execute([$range_start, $range_end]);
$dot_dates = array_column($dot_stmt->fetchAll(), 'date');

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page    = 24;
$cur_page    = max(1, (int)($_GET['page'] ?? 1));
$search      = trim($_GET['search'] ?? '');

$all_students_stmt = $pdo->prepare(
    "SELECT id, name, student_phone, photo FROM users
     WHERE role='student' " .
    ($search ? "AND (name LIKE ? OR student_phone LIKE ?)" : "") .
    " ORDER BY name ASC"
);
if ($search) {
    $like = '%' . $search . '%';
    $all_students_stmt->execute([$like, $like]);
} else {
    $all_students_stmt->execute();
}
$all_students   = $all_students_stmt->fetchAll();
$total_students = count($all_students);
$total_pages    = max(1, (int)ceil($total_students / $per_page));
$cur_page       = min($cur_page, $total_pages);
$page_students  = array_slice($all_students, ($cur_page - 1) * $per_page, $per_page);

// ── Attendance for selected date ──────────────────────────────────────────────
$att_stmt = $pdo->prepare("SELECT student_id, status FROM attendance WHERE date=?");
$att_stmt->execute([$sel_date]);
$sel_att = [];
foreach ($att_stmt->fetchAll() as $r) $sel_att[(int)$r['student_id']] = $r['status'];

// Stats for selected date (all students, not just page)
$present_count  = 0; $absent_count = 0; $unmarked_count = 0;
foreach ($all_students as $s) {
    $st = $sel_att[(int)$s['id']] ?? '';
    if ($st === 'present')     $present_count++;
    elseif ($st === 'absent')  $absent_count++;
    else                       $unmarked_count++;
}

// ── Prev/next month URLs ──────────────────────────────────────────────────────
function cal_url($y, $m, $date, $search, $page = 1) {
    $q = ['date' => $date, 'cal_year' => $y, 'cal_month' => $m, 'page' => $page];
    if ($search) $q['search'] = $search;
    return 'student_attendance.php?' . http_build_query($q);
}
$prev_m = $cal_month - 1; $prev_y = $cal_year;
if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
$next_m = $cal_month + 1; $next_y = $cal_year;
if ($next_m > 12) { $next_m = 1;  $next_y++; }

// ── Donut SVG helper ──────────────────────────────────────────────────────────
function donut_segments($present, $absent, $unmarked) {
    $total = $present + $absent + $unmarked;
    if ($total === 0) {
        return '<circle cx="85" cy="85" r="65" fill="none" stroke="#e2e8f0" stroke-width="20"/>';
    }
    $r = 65; $cx = 85; $cy = 85;
    $circ = 2 * M_PI * $r;
    $vals = [
        ['val' => $present,  'color' => '#10b981'],
        ['val' => $absent,   'color' => '#ef4444'],
        ['val' => $unmarked, 'color' => '#e2e8f0'],
    ];
    $svg = '';
    $offset = 0;
    foreach ($vals as $seg) {
        if ($seg['val'] <= 0) continue;
        $pct  = $seg['val'] / $total;
        $dash = $pct * $circ;
        $gap  = $circ - $dash;
        $rot  = -90 + ($offset / $total) * 360;
        $svg .= sprintf(
            '<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="20" '
          . 'stroke-dasharray="%.4f %.4f" stroke-dashoffset="0" '
          . 'transform="rotate(%.4f %d %d)"/>',
            $cx, $cy, $r,
            $seg['color'],
            $dash, $gap,
            $rot, $cx, $cy
        );
        $offset += $seg['val'];
    }
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        /* ── Shell ── */
        .att-shell {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        .att-aside {
            position: sticky;
            top: 80px;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* ── Calendar ── */
        .cal-panel {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            overflow: hidden;
        }
        .cal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem 0.5rem;
            border-bottom: 1px solid var(--border);
        }
        .cal-head-title { font-weight: 800; font-size: 0.9rem; color: var(--text); }
        .cal-nav {
            background: none; border: none; cursor: pointer;
            color: var(--muted); font-size: 1.1rem; padding: 0.2rem 0.4rem;
            border-radius: 6px; transition: background 0.15s, color 0.15s;
            display: flex; align-items: center; justify-content: center;
        }
        .cal-nav:hover { background: var(--panel-alt); color: var(--text); }
        .cal-grid { padding: 0.5rem 0.75rem 0.75rem; }
        .cal-days-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.04em;
            margin-bottom: 0.25rem;
        }
        .cal-cells {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
        }
        .cal-cell {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.3rem 0;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text);
            text-decoration: none;
            position: relative;
        }
        .cal-cell:hover { background: var(--primary-soft); color: var(--primary); }
        .cal-cell.today { color: var(--primary); }
        .cal-cell.selected { background: var(--primary); color: #fff !important; }
        .cal-cell.selected .cal-dot { background: rgba(255,255,255,0.8); }
        .cal-cell.empty { cursor: default; }
        .cal-cell.empty:hover { background: none; }
        .cal-dot {
            width: 5px; height: 5px;
            border-radius: 50%;
            background: var(--primary);
            margin-top: 2px;
        }

        /* ── Donut panel ── */
        .donut-panel {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            padding: 1rem;
        }
        .donut-panel-title { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin-bottom: 0.75rem; }
        .donut-wrap { display: flex; justify-content: center; }
        .donut-legend { display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.85rem; }
        .donut-legend-row { display: flex; align-items: center; gap: 0.55rem; font-size: 0.8rem; color: var(--muted); }
        .donut-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .donut-legend-val { margin-left: auto; font-weight: 700; color: var(--text); }

        /* ── CSV button ── */
        .btn-csv {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.55rem 1rem; background: var(--panel-alt);
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 0.8rem; font-weight: 700; color: var(--muted);
            cursor: pointer; text-decoration: none; transition: background 0.15s, border-color 0.15s;
        }
        .btn-csv:hover { background: #fff; border-color: var(--primary); color: var(--primary); }
        .btn-csv svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; flex-shrink: 0; }

        /* ── Right panel toolbar ── */
        .att-toolbar {
            display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;
            padding: 0.875rem 1.25rem;
            background: #fff; border: 1.5px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow-soft);
            margin-bottom: 1rem;
        }
        .att-toolbar input[type="date"],
        .att-toolbar input[type="search"] {
            border: 1.5px solid var(--border); border-radius: 8px;
            padding: 0.45rem 0.75rem; font-size: 0.82rem;
            font-family: inherit; outline: none; background: var(--panel-alt);
            transition: border-color 0.15s;
        }
        .att-toolbar input:focus { border-color: var(--primary); background: #fff; }
        .att-toolbar input[type="date"] { min-width: 140px; }
        .att-toolbar input[type="search"] { flex: 1; min-width: 160px; }
        .toolbar-spacer { flex: 1; }

        /* ── Stats strip ── */
        .att-stats {
            display: flex; gap: 0.6rem; flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .att-stat-pill {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.4rem 1rem; border-radius: 8px;
            font-size: 0.82rem; font-weight: 600;
            background: #fff; border: 1.5px solid var(--border);
            color: var(--text);
        }
        .att-stat-pill .pill-n { font-weight: 800; font-size: 1rem; }
        .att-stat-pill.present .pill-n { color: var(--success); }
        .att-stat-pill.absent  .pill-n  { color: var(--danger); }

        /* ── Student cards ── */
        .att-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 0.75rem;
        }
        .att-card {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: box-shadow 0.15s;
        }
        .att-card:hover { box-shadow: var(--shadow); }

        .att-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            object-fit: cover; flex-shrink: 0;
            background: var(--primary-soft);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 1rem; color: var(--primary);
            overflow: hidden;
        }
        .att-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .att-card-info { flex: 1; min-width: 0; }
        .att-card-name { font-weight: 700; font-size: 0.875rem; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .att-card-phone { font-size: 0.75rem; color: var(--muted); margin-top: 1px; }

        /* P/A toggle - bold outlined buttons */
        .att-toggle {
            display: flex; gap: 0.3rem;
            margin-top: 0.5rem; width: fit-content;
        }
        .att-toggle label {
            padding: 5px 16px;
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            border: 2px solid var(--border);
            background: transparent;
            color: var(--muted);
            transition: border-color 0.15s, color 0.15s, background 0.15s;
            user-select: none;
        }
        .att-toggle input { display: none; }
        .att-toggle label.present:hover { border-color: var(--success); color: var(--success); }
        .att-toggle label.absent:hover  { border-color: var(--danger);  color: var(--danger); }
        .att-toggle label.present:has(input:checked) {
            border-color: var(--success);
            background: var(--success);
            color: #fff;
            font-weight: 800;
        }
        .att-toggle label.absent:has(input:checked) {
            border-color: var(--danger);
            background: var(--danger);
            color: #fff;
            font-weight: 800;
        }

        /* Save indicator - hidden, only used internally */
        .att-save-ind { display: none; }

        /* ── Pagination ── */
        .att-pagination { display: flex; justify-content: center; align-items: center; gap: 0.4rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .att-pagination a, .att-pagination span {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px; border-radius: 7px;
            font-size: 0.8rem; font-weight: 700; text-decoration: none;
            border: 1.5px solid var(--border); background: #fff; color: var(--text);
            transition: background 0.15s, border-color 0.15s;
            padding: 0 0.5rem;
        }
        .att-pagination a:hover { background: var(--primary-soft); border-color: var(--primary); color: var(--primary); }
        .att-pagination span.current { background: var(--primary); border-color: var(--primary); color: #fff; }
        .att-pagination span.dots { border: none; background: none; }

        /* ── Save all bar ── */
        .att-save-bar { display: flex; align-items: center; gap: 1rem; margin-top: 1.25rem; padding: 1rem 1.25rem; background: #fff; border: 1.5px solid var(--border); border-radius: var(--radius); }

        @media (max-width: 900px) {
            .att-shell { grid-template-columns: 1fr; }
            .att-aside { position: static; }
        }
    </style>
</head>
<body>
<?= render_sidebar('student_attendance.php') ?>
<?= render_topbar() ?>

<div class="container">
    <div class="page-header">
        <h1>Mark Attendance</h1>
    </div>

    <?php if ($save_msg): ?>
        <div class="alert alert-<?= $save_type ?>"><?= e($save_msg) ?></div>
    <?php endif; ?>

    <div class="att-shell">

        <!-- ── LEFT: Sticky calendar + donut + CSV ── -->
        <aside class="att-aside">

            <!-- Calendar card -->
            <div class="cal-panel">
                <div class="cal-head">
                    <a class="cal-nav" href="<?= e(cal_url($prev_y, $prev_m, $sel_date, $search)) ?>" title="Previous month">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                    <span class="cal-head-title"><?= date('F Y', $cal_first) ?></span>
                    <a class="cal-nav" href="<?= e(cal_url($next_y, $next_m, $sel_date, $search)) ?>" title="Next month">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                </div>
                <div class="cal-grid">
                    <div class="cal-days-row">
                        <?php foreach (['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
                            <div><?= $d ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="cal-cells">
                        <?php
                        $start_dow = (int)date('w', $cal_first); // 0=Sun
                        for ($blank = 0; $blank < $start_dow; $blank++):
                        ?>
                            <div class="cal-cell empty"></div>
                        <?php endfor; ?>
                        <?php for ($d = 1; $d <= $cal_last_d; $d++):
                            $cell_date = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $d);
                            $is_today    = ($cell_date === $today);
                            $is_selected = ($cell_date === $sel_date);
                            $has_dot     = in_array($cell_date, $dot_dates);
                            $classes     = 'cal-cell';
                            if ($is_today)    $classes .= ' today';
                            if ($is_selected) $classes .= ' selected';
                            $cell_url = cal_url($cal_year, $cal_month, $cell_date, $search);
                        ?>
                            <a class="<?= $classes ?>" href="<?= e($cell_url) ?>" title="<?= e($cell_date) ?>">
                                <?= $d ?>
                                <?php if ($has_dot): ?><span class="cal-dot"></span><?php endif; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Donut summary card -->
            <div class="donut-panel">
                <div class="donut-panel-title">
                    <?= e(date('d M Y', strtotime($sel_date))) ?> — Summary
                </div>
                <div class="donut-wrap">
                    <svg viewBox="0 0 170 170" width="130" height="130">
                        <?= donut_segments($present_count, $absent_count, $unmarked_count) ?>
                        <text x="85" y="90" text-anchor="middle" font-size="22" font-weight="800" fill="var(--text)" font-family="inherit"><?= $total_students ?></text>
                        <text x="85" y="105" text-anchor="middle" font-size="9" fill="#64748b" font-family="inherit" font-weight="600">TOTAL</text>
                    </svg>
                </div>
                <div class="donut-legend">
                    <div class="donut-legend-row">
                        <span class="donut-legend-dot" style="background:#10b981;"></span>
                        Present
                        <span class="donut-legend-val"><?= $present_count ?></span>
                    </div>
                    <div class="donut-legend-row">
                        <span class="donut-legend-dot" style="background:#ef4444;"></span>
                        Absent
                        <span class="donut-legend-val"><?= $absent_count ?></span>
                    </div>
                    <div class="donut-legend-row">
                        <span class="donut-legend-dot" style="background:#e2e8f0;border:1.5px solid #cbd5e1;"></span>
                        Unmarked
                        <span class="donut-legend-val"><?= $unmarked_count ?></span>
                    </div>
                </div>
            </div>

            <!-- Export CSV -->
            <a href="student_attendance.php?download_csv=<?= urlencode($sel_date) ?>" class="btn-csv">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV for <?= e(date('d M Y', strtotime($sel_date))) ?>
            </a>

        </aside>

        <!-- ── RIGHT: Toolbar + stats + card grid ── -->
        <div>
            <!-- Toolbar -->
            <div class="att-toolbar">
                <form method="get" style="display:contents;" id="filter-form">
                    <input type="hidden" name="cal_year"  value="<?= $cal_year ?>">
                    <input type="hidden" name="cal_month" value="<?= $cal_month ?>">
                    <input type="hidden" name="page"      value="1">
                    <input type="date"   name="date"      value="<?= e($sel_date) ?>"
                           onchange="this.form.submit()" title="Select date">
                    <input type="search" name="search" value="<?= e($search) ?>"
                           placeholder="Search student…" autocomplete="off">
                    <button type="submit" class="btn btn-secondary" style="font-size:0.8rem;">Filter</button>
                </form>
                <div class="toolbar-spacer"></div>
                <button type="button" class="btn btn-secondary" onclick="markAll('present')" style="font-size:0.78rem;">
                    Mark all present
                </button>
                <button type="button" class="btn btn-secondary" onclick="markAll('absent')" style="font-size:0.78rem;color:var(--danger-strong);">
                    Mark all absent
                </button>
            </div>

            <!-- Stats strip -->
            <div class="att-stats">
                <span class="att-stat-pill"><span class="pill-n"><?= $total_students ?></span>Total</span>
                <span class="att-stat-pill present"><span class="pill-n"><?= $present_count ?></span>Present</span>
                <span class="att-stat-pill absent"><span class="pill-n"><?= $absent_count ?></span>Absent</span>
                <span class="att-stat-pill"><span class="pill-n"><?= $unmarked_count ?></span>Unmarked</span>
            </div>

            <!-- Student card form -->
            <form method="post" id="att-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="att_date"   value="<?= e($sel_date) ?>">
                <!-- AJAX CSRF val -->
                <input type="hidden" id="csrf-val"     value="<?= csrf_token() ?>">
                <input type="hidden" id="att-date-val" value="<?= e($sel_date) ?>">

                <div class="att-card-grid" id="att-card-grid">
                    <?php foreach ($page_students as $s):
                        $sid      = (int)$s['id'];
                        $current  = $sel_att[$sid] ?? '';
                        $initials = '';
                        $parts    = array_filter(explode(' ', trim($s['name'])));
                        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1))
                                  . strtoupper(substr($parts[1] ?? '', 0, 1));
                        $card_cls = 'att-card';
                        if ($current === 'present') $card_cls .= ' att-card-present';
                        if ($current === 'absent')  $card_cls .= ' att-card-absent';
                        $photo_src = !empty($s['photo']) && $s['photo'] !== 'default.png'
                            ? '../uploads/students/' . rawurlencode($s['photo']) : null;
                    ?>
                    <div class="<?= $card_cls ?>" id="card-<?= $sid ?>">
                        <!-- Avatar -->
                        <div class="att-avatar" id="av-<?= $sid ?>">
                            <?php if ($photo_src): ?>
                                <img src="<?= e($photo_src) ?>" alt="<?= e($s['name']) ?>"
                                     onerror="this.parentNode.textContent='<?= e($initials) ?>';this.remove();">
                            <?php else: ?>
                                <?= e($initials) ?>
                            <?php endif; ?>
                        </div>
                        <!-- Info + toggle -->
                        <div class="att-card-info">
                            <div class="att-card-name"><?= e($s['name']) ?></div>
                            <div class="att-card-phone"><?= e($s['student_phone'] ?? '—') ?></div>
                            <div class="att-toggle">
                                <label class="present">
                                    <input type="radio" name="attendance[<?= $sid ?>]"
                                           value="present" data-sid="<?= $sid ?>" class="att-radio"
                                           <?= $current === 'present' ? 'checked' : '' ?>>
                                    P
                                </label>
                                <label class="absent">
                                    <input type="radio" name="attendance[<?= $sid ?>]"
                                           value="absent" data-sid="<?= $sid ?>" class="att-radio"
                                           <?= $current === 'absent' ? 'checked' : '' ?>>
                                    A
                                </label>
                            </div>
                            <div class="att-save-ind <?= $current ? 'saved' : '' ?>" id="save-<?= $sid ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="att-pagination">
                    <?php
                    function pag_url($p, $sel_date, $cal_year, $cal_month, $search) {
                        $q = ['date' => $sel_date, 'cal_year' => $cal_year, 'cal_month' => $cal_month, 'page' => $p];
                        if ($search) $q['search'] = $search;
                        return 'student_attendance.php?' . http_build_query($q);
                    }
                    if ($cur_page > 1): ?>
                        <a href="<?= e(pag_url($cur_page - 1, $sel_date, $cal_year, $cal_month, $search)) ?>">&lsaquo;</a>
                    <?php endif;
                    for ($p = 1; $p <= $total_pages; $p++):
                        if ($p === $cur_page): ?>
                            <span class="current"><?= $p ?></span>
                        <?php elseif ($p <= 2 || $p >= $total_pages - 1 || abs($p - $cur_page) <= 1): ?>
                            <a href="<?= e(pag_url($p, $sel_date, $cal_year, $cal_month, $search)) ?>"><?= $p ?></a>
                        <?php elseif (abs($p - $cur_page) === 2): ?>
                            <span class="dots">&hellip;</span>
                        <?php endif;
                    endfor;
                    if ($cur_page < $total_pages): ?>
                        <a href="<?= e(pag_url($cur_page + 1, $sel_date, $cal_year, $cal_month, $search)) ?>">&rsaquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Save all bar -->
                <div class="att-save-bar">
                    <button type="submit" class="btn btn-primary">Save All Attendance</button>
                    <p style="font-size:0.8rem;color:var(--muted);">Changes are also auto-saved as you click each toggle.</p>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var csrf  = document.getElementById('csrf-val').value;
    var aDate = document.getElementById('att-date-val').value;

    function setCardClass(sid, status) {
        var card = document.getElementById('card-' + sid);
        if (!card) return;
        card.classList.remove('att-card-present', 'att-card-absent');
        if (status === 'present') card.classList.add('att-card-present');
        if (status === 'absent')  card.classList.add('att-card-absent');
    }

    // AJAX auto-save on toggle
    document.querySelectorAll('.att-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var sid    = this.getAttribute('data-sid');
            var status = this.value;
            var ind    = document.getElementById('save-' + sid);
            ind.className = 'att-save-ind saving';
            setCardClass(sid, status);

            fetch('student_attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=1&student_id=' + sid
                    + '&status=' + status
                    + '&att_date=' + encodeURIComponent(aDate)
                    + '&csrf_token=' + encodeURIComponent(csrf)
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                ind.className = d.success ? 'att-save-ind saved' : 'att-save-ind error';
            })
            .catch(function () {
                ind.className = 'att-save-ind error';
            });
        });
    });

    // Mark all present / absent - uses styled modal via hmsConfirm
    window.markAll = function (status) {
        var visible = Array.from(document.querySelectorAll('.att-row, .att-card'))
            .filter(function(c){ return c.style.display !== 'none'; });
        var count = document.querySelectorAll('.att-radio[value="' + status + '"]').length;
        var msg = 'Mark ' + count + ' student(s) as ' + status + '?';
        var doIt = function() {
            document.querySelectorAll('.att-radio[value="' + status + '"]').forEach(function (r) {
                if (!r.checked) { r.checked = true; r.dispatchEvent(new Event('change')); }
            });
        };
        if (typeof window.hmsConfirm === 'function') {
            window.hmsConfirm(msg, doIt);
        } else {
            if (confirm(msg)) doIt();
        }
    };
})();
</script>
<script src="../js/script.js"></script>
</body>
</html>
