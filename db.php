<?php
// ─────────────────────────────────────────────────
// dp.php — Core Database & Security Layer
// Include this file on every page
// ─────────────────────────────────────────────────

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hostel2');
define('JWT_SECRET', 'hms_secure_key_2024_change_this');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) die('Database connection failed: ' . mysqli_connect_error());
mysqli_set_charset($conn, 'utf8mb4');

// XSS Prevention — use on ALL output of user/db data
function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Session start
if (session_status() === PHP_SESSION_NONE) session_start();

// CSRF Token Generation
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// CSRF Token Verification
function csrf_verify($token) {
    if (empty($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        die('Security error: Invalid CSRF token. Please go back and try again.');
    }
}

// JWT encode
function jwt_encode($payload) {
    $header    = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload   = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

// JWT decode
function jwt_decode($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $valid = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($valid, $sig)) return false;
    return json_decode(base64url_decode($payload), true);
}

function base64url_encode($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function base64url_decode($d) { return base64_decode(strtr($d, '-_', '+/')); }

// Set auth cookie and session on login
function set_auth($user) {
    $token = jwt_encode([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'role'  => $user['role'],
        'photo' => $user['photo'] ?? 'default.png',
        'exp'   => time() + (86400 * 30)
    ]);
    setcookie('auth_token', $token, time() + (86400 * 30), '/', '', false, true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_photo'] = $user['photo'] ?? 'default.png';
}

// Get auth from JWT cookie
function get_auth() {
    if (!empty($_COOKIE['auth_token'])) {
        $d = jwt_decode($_COOKIE['auth_token']);
        if ($d && $d['exp'] > time()) return $d;
    }
    return false;
}

// Require a specific role — redirect if not matched
function require_role($role) {
    $auth = get_auth();
    if (!$auth || $auth['role'] !== $role) {
        header('Location: ../login.php');
        exit;
    }
    $_SESSION['user_id']    = $auth['id'];
    $_SESSION['user_role']  = $auth['role'];
    $_SESSION['user_name']  = $auth['name'];
    $_SESSION['user_photo'] = $auth['photo'] ?? 'default.png';
}

// Render top navigation sidebar
function render_sidebar($active) {
    $auth  = get_auth();
    $role  = $auth['role']  ?? '';
    $name  = $auth['name']  ?? 'User';

    $nav = [
        'student' => [
            ['href' => 'dashboard.php',       'label' => 'Dashboard'],
            ['href' => 'room.php',             'label' => 'My Room'],
            ['href' => 'my_attendance.php',    'label' => 'Attendance'],
            ['href' => 'my_complaints.php',    'label' => 'Complaints'],
            ['href' => 'food_routine.php',     'label' => 'Food Routine'],
        ],
        'warden' => [
            ['href' => 'dashboard.php',          'label' => 'Dashboard'],
            ['href' => 'room_requests.php',      'label' => 'Room Requests'],
            ['href' => 'list_student.php',       'label' => 'Student List'],
            ['href' => 'student_attendance.php', 'label' => 'Attendance'],
            ['href' => 'complaints.php',         'label' => 'Complaints'],
        ],
        'owner' => [
            ['href' => 'dashboard.php',         'label' => 'Dashboard'],
            ['href' => 'report_attendance.php', 'label' => 'Attendance Report'],
            ['href' => 'manage_food.php',       'label' => 'Manage Food'],
        ]
    ];

    $links = $nav[$role] ?? [];
    ob_start();
    ?>
    <nav class="navbar">
        <div class="navbar-brand">HMS</div>
        <div class="navbar-nav">
            <?php foreach ($links as $link): ?>
                <a href="<?= e($link['href']) ?>"
                   class="<?= ($active === $link['href']) ? 'active' : '' ?>">
                   <?= e($link['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="navbar-user">
            <div style="text-align:right; margin-right:0.75rem;">
                <p style="font-size:0.75rem; color:var(--text-muted);
                           font-weight:700; text-transform:uppercase;">WELCOME</p>
                <p style="font-size:0.8125rem; font-weight:800;
                           color:#0f172a;"><?= e($name) ?></p>
            </div>
            <a href="../logout.php"
               style="margin-left:1.25rem; color:#be123c;
                      font-weight:800; font-size:0.75rem;">Logout</a>
        </div>
    </nav>
    <div style="margin-top:5rem;"></div>
    <?php
    return ob_get_clean();
}
?>
