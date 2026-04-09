<?php
// ─────────────────────────────────────────────────
// dp.php — Core Database & Security Layer
// Include this file on every page
// ─────────────────────────────────────────────────

$localConfig = [];
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

function app_config($key, $default = null) {
    global $localConfig;
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $localConfig[$key] ?? $default;
}

define('DB_HOST', app_config('DB_HOST', '127.0.0.1'));
define('DB_USER', app_config('DB_USER', 'root'));
define('DB_PASS', app_config('DB_PASS', ''));
define('DB_NAME', app_config('DB_NAME', 'hostel2'));
define('JWT_SECRET', app_config('JWT_SECRET', 'hms_secure_key_2024_change_this'));
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>HMS Setup Required</title>
        <style>
            body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;min-height:100vh;display:grid;place-items:center;padding:24px}
            .setup-card{max-width:820px;background:rgba(15,23,42,.88);border:1px solid rgba(255,255,255,.08);border-radius:28px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
            h1{margin:0 0 12px;font-size:clamp(2rem,4vw,3rem)}
            p{color:#cbd5e1;line-height:1.7}
            code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#020617;color:#f8fafc;border-radius:16px}
            pre{padding:18px;overflow:auto;border:1px solid rgba(255,255,255,.08)}
            .pill{display:inline-block;padding:8px 14px;border-radius:999px;background:#2563eb;color:#ffffff;font-weight:800;margin-bottom:16px}
            ul{padding-left:20px;color:#cbd5e1}
            a{color:#60a5fa}
        </style>
    </head>
    <body>
        <div class="setup-card">
            <span class="pill">Localhost setup needed</span>
            <h1>Database connection failed</h1>
            <p>HMS could not connect to MySQL using <strong><?= htmlspecialchars(DB_HOST) ?></strong> / database <strong><?= htmlspecialchars(DB_NAME) ?></strong>.</p>
            <ul>
                <li>Start Apache and MySQL in XAMPP or Laragon.</li>
                <li>Create the database by importing <strong>database_schema.sql</strong>.</li>
                <li>If your localhost password or database name is different, copy <strong>config.example.php</strong> to <strong>config.php</strong> and update it.</li>
            </ul>
            <pre>mysql -u root -p &lt; database_schema.sql</pre>
            <p>MySQL error: <?= htmlspecialchars(mysqli_connect_error()) ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// Auto-migration: Ensure new columns and tables exist
if (!mysqli_query($conn, "SELECT is_verified FROM users LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN otp_code VARCHAR(6) DEFAULT NULL AFTER password");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0 AFTER otp_code");
    mysqli_query($conn, "UPDATE users SET is_verified = 1"); // Mark existing as verified
}
if (!mysqli_query($conn, "SELECT reset_otp FROM users LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN reset_otp VARCHAR(6) DEFAULT NULL AFTER is_verified");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN reset_otp_expiry DATETIME DEFAULT NULL AFTER reset_otp");
}
if (!mysqli_query($conn, "SELECT token_version FROM users LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER photo");
}
if (!mysqli_query($conn, "SELECT fee_status FROM users LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN fee_status ENUM('paid','unpaid') DEFAULT 'unpaid' AFTER room_preference");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `enquiries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `source` VARCHAR(50) DEFAULT 'Website Form',
  `status` ENUM('unread', 'read', 'replied') DEFAULT 'unread',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `daily_routine` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `time_slot` VARCHAR(50) NOT NULL,
  `activity` VARCHAR(255) NOT NULL,
  `is_school_hours` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (!mysqli_query($conn, "SELECT warden_status FROM leaves LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE leaves ADD COLUMN warden_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER reason");
    mysqli_query($conn, "ALTER TABLE leaves ADD COLUMN owner_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER warden_status");
    mysqli_query($conn, "ALTER TABLE leaves DROP COLUMN status");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `staff` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `allocation` ENUM('Room','Canteen','Toilets','Garden','General') DEFAULT 'General',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (!mysqli_query($conn, "SELECT allocation FROM staff LIMIT 1")) {
        mysqli_query($conn, "ALTER TABLE staff ADD COLUMN allocation ENUM('Room','Canteen','Toilets','Garden','General') DEFAULT 'General' AFTER role");
}
if (mysqli_query($conn, "SELECT assigned_area FROM staff LIMIT 1")) {
        mysqli_query($conn, "UPDATE staff SET allocation = assigned_area WHERE allocation IS NULL OR allocation = ''");
}

// Populate staff if empty
$chk_staff = mysqli_query($conn, "SELECT COUNT(*) FROM staff");
if ($chk_staff && mysqli_fetch_row($chk_staff)[0] == 0) {
    mysqli_query($conn, "INSERT INTO staff (name, role, allocation) VALUES 
        ('Ram Bahadur', 'Cleaner', 'Toilets'),
        ('Shiva Thapa', 'Cook', 'Canteen'),
        ('Maya Devi', 'Helper', 'Canteen'),
        ('Kaji Sherpa', 'Gardener', 'Garden'),
        ('Bhim Thapa', 'Security', 'General'),
        ('Hari Prasad', 'Cleaner', 'Room')");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `leaves` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `reason` TEXT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `warden_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `owner_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `final_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");


// Seed routine if empty
$check_routine = mysqli_query($conn, "SELECT id FROM daily_routine LIMIT 1");
if (mysqli_num_rows($check_routine) == 0) {
    mysqli_query($conn, "INSERT INTO daily_routine (time_slot, activity, is_school_hours) VALUES 
        ('06:00 AM - 07:00 AM', 'Wake up, Exercise & Morning Study', 0),
        ('07:00 AM - 03:00 PM', 'School/College Hours', 1),
        ('03:30 PM - 05:00 PM', 'Snacks & Personal Refreshment', 0),
        ('05:00 PM - 07:00 PM', 'Evening Guided Study', 0),
        ('07:30 PM - 08:30 PM', 'Dinner', 0),
        ('09:00 PM', 'Lights Out / Final Personal Study', 0)");
}

// Seed Students & Rooms if empty
$check_students = mysqli_query($conn, "SELECT id FROM users WHERE role='student'");
if (mysqli_num_rows($check_students) < 5) {
    // Rooms 101-115
    for ($i = 101; $i <= 115; $i++) {
        $type = ($i % 2 == 0) ? 'Double' : 'Single';
        mysqli_query($conn, "INSERT IGNORE INTO rooms (room_number, room_type, floor, status) VALUES ('$i', '$type', 1, 'available')");
    }
    // Rooms 201-215
    for ($i = 201; $i <= 215; $i++) {
        $type = ($i % 2 == 0) ? 'Double' : 'Single';
        mysqli_query($conn, "INSERT IGNORE INTO rooms (room_number, room_type, floor, status) VALUES ('$i', '$type', 2, 'available')");
    }

    $nepali_names = [
        'Aadarsh Thapa', 'Binita Shrestha', 'Chandra Gurung', 'Deepika Rai', 'Eshita Tamang',
        'Ganesh Mahat', 'Hari Prasad', 'Ishwar Khatri', 'Janaki Pandey', 'Kiran Lama',
        'Laxmi Devi', 'Manish Kc', 'Nabin Regmi', 'Ojaswi Shah', 'Pramod Giri',
        'Ramesh BK', 'Sita Ram', 'Tulsi Ram', 'Umesh Magar', 'Vivek Rana'
    ];
    $pass = password_hash('Test1234', PASSWORD_DEFAULT);
    foreach ($nepali_names as $idx => $name) {
        $email = strtolower(explode(' ', $name)[0]) . $idx . '@student.com';
        $phone = '98' . rand(10000000, 99999999);
        mysqli_query($conn, "INSERT IGNORE INTO users (name, email, password, role, student_phone, room_status, is_verified) 
                           VALUES ('$name', '$email', '$pass', 'student', '$phone', 'pending', 1)");
    }
}

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
function base64url_decode($d) {
    $d = strtr($d, '-_', '+/');
    $pad = strlen($d) % 4;
    if ($pad) {
        $d .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($d);
}

function auth_cookie_options($expires) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
}

// Set auth cookie and session on login
function set_auth($user) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $tokenVersion = (int)($user['token_version'] ?? 0);
    $token = jwt_encode([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'role'  => $user['role'],
        'photo' => $user['photo'] ?? 'default.png',
        'tv'    => $tokenVersion,
        'exp'   => time() + (86400 * 30)
    ]);
    setcookie('auth_token', $token, auth_cookie_options(time() + (86400 * 30)));
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_photo'] = $user['photo'] ?? 'default.png';
}

// Get auth from JWT cookie
function get_auth() {
    if (!empty($_COOKIE['auth_token'])) {
        $d = jwt_decode($_COOKIE['auth_token']);
        if ($d && !empty($d['exp']) && $d['exp'] > time()) {
            if (!isset($d['tv'])) {
                setcookie('auth_token', '', auth_cookie_options(time() - 3600));
                return false;
            }
            $currentVersion = get_user_token_version((int)$d['id']);
            if ($currentVersion !== null && (int)$d['tv'] === $currentVersion) {
                return $d;
            }
        }
        setcookie('auth_token', '', auth_cookie_options(time() - 3600));
    }
    return false;
}

function get_user_token_version($userId) {
    global $pdo;
    if (!$userId) return null;
    $stmt = $pdo->prepare('SELECT token_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['token_version'] : null;
}

function revoke_user_tokens($userId) {
    global $pdo;
    if (!$userId) return;
    $stmt = $pdo->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?');
    $stmt->execute([$userId]);
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
function render_sidebar($active_page) {
    $auth = get_auth();
    if (!$auth) return '';

    $menus = [
        'student' => [
            ['href' => 'dashboard.php',     'label' => 'Dashboard'],
            ['href' => 'room.php',          'label' => 'My Room'],
            ['href' => 'my_attendance.php', 'label' => 'Attendance'],
            ['href' => 'my_routine.php',    'label' => 'Daily Routine'],
            ['href' => 'request_leave.php', 'label' => 'Request Leave'],
            ['href' => 'my_complaints.php', 'label' => 'Complaints'],
        ],
        'warden' => [
            ['href' => 'dashboard.php',          'label' => 'Dashboard'],
            ['href' => 'room_requests.php',      'label' => 'Manage Rooms'],
            ['href' => 'student_attendance.php', 'label' => 'Mark Attendance'],
            ['href' => 'manage_leaves.php',      'label' => 'Student Leaves'],
            ['href' => 'complaints.php',         'label' => 'Complaints'],
        ],
        'owner' => [
            ['href' => 'dashboard.php',         'label' => 'Dashboard'],
            ['href' => 'enquiries.php',         'label' => 'Enquiries'],
            ['href' => 'manage_routine.php',    'label' => 'Manage Routine'],
            ['href' => 'manage_staff.php',      'label' => 'Manage Staff'],
            ['href' => 'manage_leaves.php',     'label' => 'Student Leaves'],
            ['href' => 'student_fees.php',      'label' => 'Fee Tracking'],
            ['href' => 'report_attendance.php', 'label' => 'Attendance Report'],
        ]
    ];

    $role_menu = $menus[$auth['role']] ?? [];
    $roleLabel = ucfirst($auth['role']);
    $html = '<div class="sidebar">';
    $html .= '<div class="sidebar-branding">';
    $html .= '<a class="sidebar-logo" href="dashboard.php">HMS</a>';
    $html .= '<div class="sidebar-meta"><span class="sidebar-kicker">Student Residence</span><span class="sidebar-role">' . e($roleLabel) . ' Portal</span></div>';
    $html .= '</div>';
    $html .= '<button class="sidebar-toggle" type="button" aria-label="Toggle menu">☰</button>';
    $html .= '<ul class="sidebar-menu">';
    foreach ($role_menu as $m) {
        $active = ($active_page == $m['href']) ? 'class="active"' : '';
        $html .= '<li><a href="' . $m['href'] . '" ' . $active . '>' . $m['label'] . '</a></li>';
    }
    $html .= '<li class="sidebar-spacer"></li>';
    $html .= '<li><span class="sidebar-user">' . e($auth['name']) . '</span></li>';
    $html .= '<li><a href="../logout.php" class="logout-btn">Logout</a></li>';
    $html .= '</ul></div>';
    return $html;
}
?>
