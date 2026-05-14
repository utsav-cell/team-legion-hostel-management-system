<?php
// ─────────────────────────────────────────────────
// owner/report_attendance.php — Full Attendance Report
// ─────────────────────────────────────────────────

require_once '../db.php';
require_role_any(['owner', 'warden']);

$name = $_SESSION['user_name'];

// Safe validated filters
$f_date    = trim($_GET['filter_date']    ?? '');
$f_status  = trim($_GET['filter_status']  ?? '');
$f_student = trim($_GET['filter_student'] ?? '');

if ($f_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) $f_date = '';
if (!in_array($f_status, ['', 'present', 'absent'])) $f_status = '';

// ── Per-student attendance percentage (for donut buckets) ─────────────────────
$all_students_raw = $pdo->query(
    "SELECT u.id, u.name
     FROM users u WHERE u.role='student' ORDER BY u.name ASC"
)->fetchAll();

$total_days_stmt = $pdo->query("SELECT COUNT(DISTINCT date) AS d FROM attendance");
$days_marked     = (int)($total_days_stmt->fetch()['d'] ?? 0);
$total_students  = count($all_students_raw);

// Bucket counters for donut
$bucket_excellent = 0; // >= 90%
$bucket_good      = 0; // 70–89%
$bucket_average   = 0; // 50–69%
$bucket_poor      = 0; // < 50%
$bucket_unmarked  = 0; // no attendance at all

$grand_present    = 0;
$grand_total_rec  = 0;

if ($days_marked > 0) {
    $per_student_stmt = $pdo->query(
        "SELECT student_id,
                SUM(status='present') AS present_days,
                COUNT(*) AS total_days
         FROM attendance
         GROUP BY student_id"
    );
    $per_student = [];
    foreach ($per_student_stmt->fetchAll() as $r) {
        $per_student[(int)$r['student_id']] = $r;
        $grand_present   += (int)$r['present_days'];
        $grand_total_rec += (int)$r['total_days'];
    }

    foreach ($all_students_raw as $s) {
        $sid = (int)$s['id'];
        if (!isset($per_student[$sid])) {
            $bucket_unmarked++;
            continue;
        }
        $pct = ($per_student[$sid]['total_days'] > 0)
            ? (int)$per_student[$sid]['present_days'] / $per_student[$sid]['total_days'] * 100
            : 0;
        if ($pct >= 90)      $bucket_excellent++;
        elseif ($pct >= 70)  $bucket_good++;
        elseif ($pct >= 50)  $bucket_average++;
        else                 $bucket_poor++;
    }
} else {
    $bucket_unmarked = $total_students;
}

$overall_pct = ($grand_total_rec > 0)
    ? round($grand_present / $grand_total_rec * 100, 1) : 0;

// ── SVG donut helper ───────────────────────────────────────────────────────────
function report_donut_svg(array $segs): string {
    $total = array_sum(array_column($segs, 'val'));
    $r = 75; $cx = 95; $cy = 95;
    $circ = 2 * M_PI * $r;
    if ($total === 0) {
        return sprintf('<circle cx="%d" cy="%d" r="%d" fill="none" stroke="#e2e8f0" stroke-width="20"/>', $cx, $cy, $r);
    }
    $svg = '';
    $offset = 0;
    foreach ($segs as $seg) {
        if ($seg['val'] <= 0) continue;
        $pct  = $seg['val'] / $total;
        $dash = $pct * $circ;
        $gap  = $circ - $dash;
        $rot  = -90 + ($offset / $total) * 360;
        $svg .= sprintf(
            '<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="20" '
          . 'stroke-dasharray="%.4f %.4f" '
          . 'transform="rotate(%.4f %d %d)"/>',
            $cx, $cy, $r, $seg['color'],
            $dash, $gap,
            $rot, $cx, $cy
        );
        $offset += $seg['val'];
    }
    return $svg;
}

$donut_segs = [
    ['val' => $bucket_excellent, 'color' => '#10b981', 'label' => '90–100%'],
    ['val' => $bucket_good,      'color' => '#3b82f6', 'label' => '70–89%'],
    ['val' => $bucket_average,   'color' => '#f59e0b', 'label' => '50–69%'],
    ['val' => $bucket_poor,      'color' => '#ef4444', 'label' => '<50%'],
    ['val' => $bucket_unmarked,  'color' => '#e2e8f0', 'label' => 'Unmarked'],
];

// ── Per-student aggregated records (one row per student) ─────────────────────
$today_d = date('Y-m-d');
$f_from  = trim($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
$f_to    = trim($_GET['to']   ?? $today_d);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = $today_d;
if ($f_from > $f_to) [$f_from,$f_to] = [$f_to,$f_from];

$sql = "SELECT u.id, u.name, u.email, u.student_phone, u.photo,
               COUNT(a.id) AS marked,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_cnt,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent_cnt,
               MAX(CASE WHEN a.status='present' THEN a.date END)    AS last_present
        FROM users u
        LEFT JOIN attendance a ON a.student_id=u.id AND a.date BETWEEN ? AND ?
        WHERE u.role='student'";
$sq_params = [$f_from, $f_to];
if ($f_student) { $sql .= " AND u.name LIKE ?"; $sq_params[] = '%'.$f_student.'%'; }
$sql .= " GROUP BY u.id ORDER BY u.name ASC";

$stmt = $pdo->prepare($sql); $stmt->execute($sq_params);
$all_rows = $stmt->fetchAll();

// Compute pct + bucket per student
foreach ($all_rows as &$row) {
    $m = (int)$row['marked']; $p = (int)$row['present_cnt'];
    $row['pct'] = $m > 0 ? round($p/$m*100) : null;
    $row['bucket'] = match(true) {
        $row['pct'] === null => 'unmarked',
        $row['pct'] >= 90   => 'excellent',
        $row['pct'] >= 70   => 'good',
        $row['pct'] >= 50   => 'average',
        default             => 'poor',
    };
}
unset($row);

// Distinct attendance dates (for By Date filter dropdown)
$dates = [];
try {
    $dates_stmt = $pdo->query("SELECT DISTINCT date FROM attendance ORDER BY date DESC LIMIT 60");
    $dates = array_column($dates_stmt->fetchAll(), 'date');
} catch (Exception $e) { /* attendance table may not exist */ }

$total_rows = count($all_rows);

function bucket_color_r(string $b): string {
    return match($b) { 'excellent'=>'#10b981','good'=>'#3b82f6','average'=>'#f59e0b','poor'=>'#ef4444',default=>'#94a3b8' };
}
function bucket_label_r(string $b): string {
    return match($b) { 'excellent'=>'Excellent','good'=>'Good','average'=>'Average','poor'=>'Poor',default=>'No data' };
}
function student_inits_r(string $n): string {
    $p=array_values(array_filter(explode(' ',trim($n))));
    return strtoupper(substr($p[0]??'S',0,1)).strtoupper(substr($p[1]??'',0,1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        /* ── Bucket palette (works in both modes) ── */
        :root {
            --att-excellent: #10b981;
            --att-good:      #3b82f6;
            --att-average:   #f59e0b;
            --att-poor:      #ef4444;
            --att-unmarked:  #cbd5e1;
        }
        html[data-theme="dark"] {
            --att-excellent: #34d399;
            --att-good:      #60a5fa;
            --att-average:   #fbbf24;
            --att-poor:      #f87171;
            --att-unmarked:  #475569;
        }

        /* ── Vibrant KPI tiles ── */
        .rep-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin: 0.5rem 0 1.5rem;
        }
        .rep-kpi {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.15rem 1.3rem;
            display: flex; align-items: center; gap: 0.95rem;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 6px 16px rgba(15,23,42,0.05);
            position: relative;
            overflow: hidden;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        html[data-theme="dark"] .rep-kpi { box-shadow: 0 1px 2px rgba(0,0,0,0.3), 0 6px 16px rgba(0,0,0,0.3); }
        .rep-kpi:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(15,23,42,0.06), 0 14px 32px var(--kpi-shadow, rgba(99,102,241,0.18)); }
        .rep-kpi::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, var(--kpi-tint, rgba(99,102,241,0.08)) 0%, transparent 65%);
            pointer-events: none;
        }
        .rep-kpi-icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--kpi-icon-bg, rgba(99,102,241,0.14));
            color: var(--kpi-color, var(--primary));
            flex-shrink: 0;
            z-index: 1;
        }
        .rep-kpi-icon svg { width: 22px; height: 22px; stroke-width: 2; }
        .rep-kpi-body { z-index: 1; min-width: 0; }
        .rep-kpi-val { font-size: 1.85rem; font-weight: 800; letter-spacing: -0.03em; color: var(--text); line-height: 1.05; }
        .rep-kpi-lbl { font-size: 0.74rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.09em; margin-top: 4px; }

        /* Per-theme KPI tints */
        .rep-kpi-indigo { --kpi-color: #6366f1; --kpi-icon-bg: rgba(99,102,241,0.14); --kpi-tint: rgba(99,102,241,0.1); --kpi-shadow: rgba(99,102,241,0.22); }
        .rep-kpi-amber  { --kpi-color: #d97706; --kpi-icon-bg: rgba(245,158,11,0.16); --kpi-tint: rgba(245,158,11,0.1); --kpi-shadow: rgba(245,158,11,0.22); }
        .rep-kpi-green  { --kpi-color: #059669; --kpi-icon-bg: rgba(16,185,129,0.14); --kpi-tint: rgba(16,185,129,0.1); --kpi-shadow: rgba(16,185,129,0.22); }
        .rep-kpi-red    { --kpi-color: #dc2626; --kpi-icon-bg: rgba(239,68,68,0.14);  --kpi-tint: rgba(239,68,68,0.1);  --kpi-shadow: rgba(239,68,68,0.22); }
        html[data-theme="dark"] .rep-kpi-indigo { --kpi-color: #818cf8; --kpi-icon-bg: rgba(129,140,248,0.18); --kpi-tint: rgba(129,140,248,0.12); }
        html[data-theme="dark"] .rep-kpi-amber  { --kpi-color: #fbbf24; --kpi-icon-bg: rgba(251,191,36,0.18);  --kpi-tint: rgba(251,191,36,0.12); }
        html[data-theme="dark"] .rep-kpi-green  { --kpi-color: #34d399; --kpi-icon-bg: rgba(52,211,153,0.18);  --kpi-tint: rgba(52,211,153,0.12); }
        html[data-theme="dark"] .rep-kpi-red    { --kpi-color: #f87171; --kpi-icon-bg: rgba(248,113,113,0.18); --kpi-tint: rgba(248,113,113,0.12); }

        @media (max-width: 900px) { .rep-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 520px) { .rep-kpi-grid { grid-template-columns: 1fr; } }

        /* ── Donut + legend chart card (no overview-strip label) ── */
        .rep-chart-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px rgba(15,23,42,0.06);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        html[data-theme="dark"] .rep-chart-card { box-shadow: 0 1px 2px rgba(0,0,0,0.3), 0 8px 24px rgba(0,0,0,0.35); }
        .rep-chart-row {
            display: grid; grid-template-columns: 240px 1fr;
            align-items: center;
            padding: 1.6rem 1.85rem;
            gap: 2.25rem;
        }
        .rep-donut-wrap { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; }
        .rep-donut-wrap svg text { fill: var(--text); }
        .rep-donut-wrap svg text.donut-sub { fill: var(--muted); }

        .rep-legend { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1.25rem; }
        .rep-legend-row {
            display: flex; align-items: center; gap: 0.85rem;
            padding: 0.85rem 1rem;
            background: var(--leg-bg, rgba(99,102,241,0.06));
            border: 1.5px solid var(--leg-border, rgba(99,102,241,0.18));
            border-radius: 12px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: default;
        }
        .rep-legend-row:hover { transform: translateY(-2px); box-shadow: 0 8px 20px var(--leg-shadow, rgba(99,102,241,0.18)); }
        .rep-legend-dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; background: var(--leg-color, var(--primary)); box-shadow: 0 0 0 4px var(--leg-bg, rgba(99,102,241,0.06)); }
        .rep-legend-text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
        .rep-legend-count { font-weight: 800; color: var(--text); font-size: 1.1rem; letter-spacing: -0.015em; }
        .rep-legend-count .lc-sub { font-weight: 500; color: var(--muted); font-size: 0.78rem; margin-left: 0.3rem; }
        .rep-legend-lbl { font-size: 0.8rem; color: var(--muted); }
        .rep-legend-lbl strong { color: var(--text); font-weight: 700; }

        /* Legend per-bucket vibrant themes */
        .leg-green { --leg-color:#10b981; --leg-bg:rgba(16,185,129,0.08);  --leg-border:rgba(16,185,129,0.25); --leg-shadow:rgba(16,185,129,0.22); }
        .leg-blue  { --leg-color:#3b82f6; --leg-bg:rgba(59,130,246,0.08);  --leg-border:rgba(59,130,246,0.25); --leg-shadow:rgba(59,130,246,0.22); }
        .leg-amber { --leg-color:#f59e0b; --leg-bg:rgba(245,158,11,0.1);   --leg-border:rgba(245,158,11,0.28); --leg-shadow:rgba(245,158,11,0.22); }
        .leg-red   { --leg-color:#ef4444; --leg-bg:rgba(239,68,68,0.08);   --leg-border:rgba(239,68,68,0.25);  --leg-shadow:rgba(239,68,68,0.22); }
        .leg-slate { --leg-color:#94a3b8; --leg-bg:rgba(100,116,139,0.08); --leg-border:rgba(100,116,139,0.25); --leg-shadow:rgba(100,116,139,0.18); }

        html[data-theme="dark"] .leg-green { --leg-color:#34d399; --leg-bg:rgba(52,211,153,0.1); --leg-border:rgba(52,211,153,0.3); }
        html[data-theme="dark"] .leg-blue  { --leg-color:#60a5fa; --leg-bg:rgba(96,165,250,0.1); --leg-border:rgba(96,165,250,0.3); }
        html[data-theme="dark"] .leg-amber { --leg-color:#fbbf24; --leg-bg:rgba(251,191,36,0.1); --leg-border:rgba(251,191,36,0.3); }
        html[data-theme="dark"] .leg-red   { --leg-color:#f87171; --leg-bg:rgba(248,113,113,0.1); --leg-border:rgba(248,113,113,0.3); }
        html[data-theme="dark"] .leg-slate { --leg-color:#94a3b8; --leg-bg:rgba(148,163,184,0.08); --leg-border:rgba(148,163,184,0.25); }

        /* Filter card — bright + capable in dark */
        .rep-filter-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px rgba(15,23,42,0.06);
            padding: 1.2rem 1.4rem 1.3rem;
            margin-bottom: 1.5rem;
        }
        html[data-theme="dark"] .rep-filter-card { box-shadow: 0 1px 2px rgba(0,0,0,0.3), 0 8px 24px rgba(0,0,0,0.35); }
        .rep-filter-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; gap: 0.5rem; flex-wrap: wrap; }
        .rep-filter-head h2 { font-size: 1.05rem; font-weight: 800; color: var(--text); margin: 0; letter-spacing: -0.015em; }
        .rep-filter-grid {
            display: grid; grid-template-columns: 1fr 1fr 2fr;
            gap: 1rem;
        }
        @media (max-width: 760px) { .rep-filter-grid { grid-template-columns: 1fr; } }
        .rep-field label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--text); margin-bottom: 0.35rem; }
        .rep-field input, .rep-field select {
            width: 100%; padding: 0.6rem 0.9rem;
            border: 1.5px solid var(--border); border-radius: 10px;
            background: var(--panel); color: var(--text);
            font-size: 0.9rem; font-weight: 500; font-family: inherit;
            transition: border 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
        }
        .rep-field input:focus, .rep-field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        .rep-field .rep-search-wrap { position: relative; }
        .rep-field .rep-search-wrap svg { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--muted); pointer-events: none; }
        .rep-field .rep-search-wrap input { padding-left: 2.4rem; }

        .rep-active-line {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;
            font-size: 0.84rem; color: var(--muted);
            padding-top: 0.85rem; border-top: 1px solid var(--border);
        }
        .rep-active-line strong { color: var(--text); font-weight: 800; }
        .rep-reset-btn {
            background: none; border: none; color: var(--primary); font-weight: 700;
            cursor: pointer; padding: 0; font-family: inherit; font-size: 0.84rem;
        }
        .rep-reset-btn:hover { text-decoration: underline; }

        tr.rep-row-hidden { display: none; }

        .rep-print-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.5rem 1rem;
            background: var(--panel); color: var(--text);
            border: 1.5px solid var(--border); border-radius: 10px;
            font-weight: 600; font-size: 0.85rem;
            cursor: pointer; transition: all 0.12s ease;
        }
        .rep-print-btn:hover { border-color: var(--primary); color: var(--primary); }
        .rep-print-btn svg { width: 14px; height: 14px; }

        /* Table card head */
        .rep-table-card { overflow: hidden; }
        .rep-table-head {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 1rem 1.4rem;
            background: linear-gradient(135deg, var(--primary-soft) 0%, transparent 70%);
            border-bottom: 1px solid var(--border);
        }
        .rep-table-head .card-title { font-size: 1rem; font-weight: 800; color: var(--text); }
        .rep-row-pill {
            display: inline-flex; align-items: center; padding: 0.2rem 0.65rem;
            background: var(--primary); color: #fff;
            border-radius: 999px; font-size: 0.72rem; font-weight: 800;
        }

        /* Dark-mode tint for table head gradient */
        html[data-theme="dark"] .rep-table-head {
            background: linear-gradient(135deg, rgba(99,102,241,0.2) 0%, transparent 80%);
        }

        @media (max-width: 760px) {
            .rep-chart-row { grid-template-columns: 1fr; padding: 1.25rem; }
            .rep-legend { grid-template-columns: 1fr; }
        }
        @media print {
            .no-print { display: none !important; }
            .container { margin: 0; padding: 0; max-width: 100%; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
<?php echo render_sidebar('report_attendance.php'); ?>
<?= render_topbar() ?>
<div class="container">
    <h1 class="page-title">Attendance Report</h1>

    <!-- ── Vibrant KPI tiles ── -->
    <div class="rep-kpi-grid">
        <div class="rep-kpi rep-kpi-indigo">
            <div class="rep-kpi-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="rep-kpi-body">
                <div class="rep-kpi-val"><?= $total_students ?></div>
                <div class="rep-kpi-lbl">Total Students</div>
            </div>
        </div>
        <div class="rep-kpi rep-kpi-amber">
            <div class="rep-kpi-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="rep-kpi-body">
                <div class="rep-kpi-val"><?= $days_marked ?></div>
                <div class="rep-kpi-lbl">Days Marked</div>
            </div>
        </div>
        <div class="rep-kpi rep-kpi-green">
            <div class="rep-kpi-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="rep-kpi-body">
                <div class="rep-kpi-val"><?= $grand_present ?></div>
                <div class="rep-kpi-lbl">Total Present</div>
            </div>
        </div>
        <div class="rep-kpi <?= $overall_pct >= 70 ? 'rep-kpi-green' : ($overall_pct >= 50 ? 'rep-kpi-amber' : 'rep-kpi-red') ?>">
            <div class="rep-kpi-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="rep-kpi-body">
                <div class="rep-kpi-val"><?= $overall_pct ?>%</div>
                <div class="rep-kpi-lbl">Overall Rate</div>
            </div>
        </div>
    </div>

    <!-- ── Donut + legend (vibrant) ── -->
    <div class="rep-chart-card">
        <div class="rep-chart-row">
            <div class="rep-donut-wrap">
                <svg viewBox="0 0 190 190" width="210" height="210">
                    <?= report_donut_svg($donut_segs) ?>
                    <text x="95" y="100" text-anchor="middle" font-size="32" font-weight="800" font-family="inherit"><?= $total_students ?></text>
                    <text x="95" y="121" text-anchor="middle" font-size="9" class="donut-sub" font-family="inherit" font-weight="700" letter-spacing="0.18em">STUDENTS</text>
                </svg>
            </div>

            <div class="rep-legend">
                <?php
                $legend_items = [
                    ['theme' => 'green',  'label' => 'Excellent', 'range' => '90–100%', 'count' => $bucket_excellent],
                    ['theme' => 'blue',   'label' => 'Good',      'range' => '70–89%',  'count' => $bucket_good],
                    ['theme' => 'amber',  'label' => 'Average',   'range' => '50–69%',  'count' => $bucket_average],
                    ['theme' => 'red',    'label' => 'Poor',      'range' => '<50%',    'count' => $bucket_poor],
                    ['theme' => 'slate',  'label' => 'Unmarked',  'range' => 'No data', 'count' => $bucket_unmarked],
                ];
                foreach ($legend_items as $li):
                ?>
                    <div class="rep-legend-row leg-<?= $li['theme'] ?>">
                        <div class="rep-legend-dot"></div>
                        <div class="rep-legend-text">
                            <div class="rep-legend-count"><?= $li['count'] ?><span class="lc-sub">student<?= $li['count'] !== 1 ? 's' : '' ?></span></div>
                            <div class="rep-legend-lbl"><strong><?= $li['label'] ?></strong> &middot; <?= $li['range'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="rep-filter-card no-print">
        <div class="rep-filter-head">
            <h2>Filter records</h2>
            <button type="button" onclick="window.print()" class="rep-print-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Report
            </button>
        </div>
        <div class="rep-filter-grid">
            <div class="rep-field">
                <label for="rep-date">By Date</label>
                <select id="rep-date">
                    <option value="">All Dates</option>
                    <?php foreach ($dates as $dt): ?>
                        <option value="<?= e($dt) ?>"><?= e(date('d M Y', strtotime($dt))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rep-field">
                <label for="rep-status">By Status</label>
                <select id="rep-status">
                    <option value="">All</option>
                    <option value="excellent">Excellent (90–100%)</option>
                    <option value="good">Good (70–89%)</option>
                    <option value="average">Average (50–69%)</option>
                    <option value="poor">Poor (&lt;50%)</option>
                    <option value="unmarked">Unmarked</option>
                </select>
            </div>
            <div class="rep-field">
                <label for="rep-search">Search Student</label>
                <div class="rep-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="rep-search" type="text" placeholder="Start typing a name…" autocomplete="off">
                </div>
            </div>
        </div>
        <div class="rep-active-line">
            <span id="rep-match-count">Showing all <?= $total_rows ?> students</span>
            <button type="button" id="rep-reset" class="rep-reset-btn">Reset filters</button>
        </div>
    </div>

    <!-- Per-student aggregated table -->
    <div class="card rep-table-card" style="padding:0;">
        <div class="rep-table-head">
            <div class="card-title">Student Attendance</div>
            <span class="rep-row-pill"><?= $total_rows ?></span>
        </div>
        <?php if ($all_rows): ?>
            <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none;">
                <table id="rep-table" data-paginate="15">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Days Marked</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Last Present</th>
                            <th>Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_rows as $i => $r):
                        $pct = $r['pct'];
                        $color = bucket_color_r($r['bucket']);
                        $photo = $r['photo'] ?? 'default.png';
                    ?>
                        <tr data-name="<?= e(strtolower($r['name'])) ?>" data-bucket="<?= e($r['bucket']) ?>">
                            <td style="color:var(--muted);font-size:0.82rem;"><?= $i + 1 ?></td>
                            <td>
                                <a href="../auth/profile.php?id=<?= (int)$r['id'] ?>" style="display:flex;align-items:center;gap:0.6rem;text-decoration:none;color:inherit;" title="View profile">
                                    <?php if ($photo && $photo !== 'default.png'): ?>
                                        <img src="../uploads/students/<?= e($photo) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;color:var(--primary);flex-shrink:0;"><?= e(student_inits_r($r['name'])) ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700;font-size:0.875rem;"><?= e($r['name']) ?></div>
                                        <div style="font-size:0.72rem;color:var(--muted);"><?= e($r['email']) ?></div>
                                    </div>
                                </a>
                            </td>
                            <td><?= (int)$r['marked'] ?></td>
                            <td style="color:var(--success);font-weight:700;"><?= (int)$r['present_cnt'] ?></td>
                            <td style="color:var(--danger);font-weight:700;"><?= (int)$r['absent_cnt'] ?></td>
                            <td style="font-size:0.82rem;color:var(--muted);"><?= $r['last_present'] ? e(date('d M Y', strtotime($r['last_present']))) : '—' ?></td>
                            <td style="font-weight:700;color:<?= $color ?>;"><?= $pct !== null ? $pct . '%' : '—' ?></td>
                            <td><span class="badge" style="background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>44;"><?= e(bucket_label_r($r['bucket'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:var(--muted); text-align:center; padding:2.5rem 1rem;">
                No attendance records found for this date range.
            </p>
        <?php endif; ?>
    </div>
</div>

<script src="../js/script.js"></script>
<script>
(function(){
    // Live AJAX-style filter — debounced, no submit needed.
    const searchInput = document.getElementById('rep-search');
    const statusSel   = document.getElementById('rep-status');
    const resetBtn    = document.getElementById('rep-reset');
    const matchCount  = document.getElementById('rep-match-count');
    const table       = document.getElementById('rep-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    let debounceTimer = null;
    function applyFilter() {
        const q      = (searchInput.value || '').trim().toLowerCase();
        const bucket = statusSel.value || '';
        let visible = 0;
        rows.forEach((tr) => {
            const name = tr.dataset.name || '';
            const buck = tr.dataset.bucket || '';
            const matchName  = !q || name.includes(q);
            const matchBuck  = !bucket || buck === bucket;
            if (matchName && matchBuck) {
                tr.classList.remove('rep-row-hidden');
                visible++;
            } else {
                tr.classList.add('rep-row-hidden');
            }
        });
        matchCount.textContent = (q || bucket)
            ? `Showing ${visible} of ${rows.length} students`
            : `Showing all ${rows.length} students`;

        // Re-trigger pagination using the filtered set
        rebuildPagination(visible);
    }

    // Replace the global paginator with one that respects our filtered visibility.
    let perPage = parseInt(table.dataset.paginate, 10) || 15;
    let currentPage = 1;
    let pag = null;

    function visibleRows() { return rows.filter(r => !r.classList.contains('rep-row-hidden')); }

    function rebuildPagination(visibleCount) {
        const vis = visibleRows();
        const totalPages = Math.max(1, Math.ceil(vis.length / perPage));
        if (currentPage > totalPages) currentPage = totalPages;

        if (!pag) {
            pag = document.createElement('div');
            pag.className = 'hms-pagination';
            const insertAfter = table.closest('.table-wrap') || table;
            insertAfter.parentNode.insertBefore(pag, insertAfter.nextSibling);
        }
        pag.innerHTML = '';
        const info  = document.createElement('span');
        info.className = 'hms-pag-info';
        const pages = document.createElement('div');
        pages.className = 'hms-pag-pages';
        pag.appendChild(info);
        pag.appendChild(pages);

        // Hide everything, show only current-page slice of visible
        rows.forEach(r => { if (!r.classList.contains('rep-row-hidden')) r.style.display = 'none'; });
        const start = (currentPage - 1) * perPage;
        const end   = start + perPage;
        vis.slice(start, end).forEach(r => r.style.display = '');

        const shownStart = vis.length === 0 ? 0 : start + 1;
        const shownEnd   = Math.min(end, vis.length);
        info.innerHTML = vis.length === 0
            ? 'No matches'
            : `Showing <strong>${shownStart}–${shownEnd}</strong> of <strong>${vis.length}</strong>`;

        if (vis.length <= perPage) return;

        const addPage = (p) => {
            if (p === currentPage) {
                const s = document.createElement('span');
                s.className = 'hms-pag-current';
                s.textContent = p;
                pages.appendChild(s);
            } else {
                const a = document.createElement('a');
                a.href = '#'; a.textContent = p;
                a.addEventListener('click', (e) => { e.preventDefault(); currentPage = p; rebuildPagination(); });
                pages.appendChild(a);
            }
        };
        const addDots = () => {
            const s = document.createElement('span');
            s.className = 'hms-pag-disabled';
            s.style.border = 'none'; s.style.background = 'transparent';
            s.textContent = '…';
            pages.appendChild(s);
        };

        const prev = document.createElement(currentPage === 1 ? 'span' : 'a');
        prev.textContent = '‹';
        if (currentPage === 1) prev.className = 'hms-pag-disabled';
        else { prev.href = '#'; prev.addEventListener('click', (e) => { e.preventDefault(); currentPage--; rebuildPagination(); }); }
        pages.appendChild(prev);

        if (totalPages <= 7) {
            for (let p = 1; p <= totalPages; p++) addPage(p);
        } else {
            addPage(1);
            if (currentPage > 3) addDots();
            const startP = Math.max(2, currentPage - 1);
            const endP   = Math.min(totalPages - 1, currentPage + 1);
            for (let p = startP; p <= endP; p++) addPage(p);
            if (currentPage < totalPages - 2) addDots();
            addPage(totalPages);
        }

        const next = document.createElement(currentPage === totalPages ? 'span' : 'a');
        next.textContent = '›';
        if (currentPage === totalPages) next.className = 'hms-pag-disabled';
        else { next.href = '#'; next.addEventListener('click', (e) => { e.preventDefault(); currentPage++; rebuildPagination(); }); }
        pages.appendChild(next);
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => { currentPage = 1; applyFilter(); }, 120);
        });
    }
    if (statusSel) {
        statusSel.addEventListener('change', () => { currentPage = 1; applyFilter(); });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (statusSel) statusSel.value = '';
            const dateSel = document.getElementById('rep-date');
            if (dateSel && dateSel.value) {
                // Date filter is server-side — reload without it
                window.location.href = 'report_attendance.php';
                return;
            }
            currentPage = 1;
            applyFilter();
        });
    }

    // Date filter still does a server hop (because attendance is keyed by date)
    const dateSel = document.getElementById('rep-date');
    if (dateSel) {
        dateSel.addEventListener('change', () => {
            const v = dateSel.value;
            const url = new URL(window.location.href);
            if (v) url.searchParams.set('filter_date', v);
            else url.searchParams.delete('filter_date');
            window.location.href = url.toString();
        });
        // Restore selection from URL if present
        const params = new URLSearchParams(window.location.search);
        if (params.get('filter_date')) dateSel.value = params.get('filter_date');
    }

    // Disable the global paginator for this table (we manage our own)
    table.removeAttribute('data-paginate');
    applyFilter(); // initial render
})();
</script>
</body>
</html>
