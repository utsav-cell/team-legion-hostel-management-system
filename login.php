<?php
// ─────────────────────────────────────────────────
// login.php — Login and Student Registration
// ─────────────────────────────────────────────────

require_once 'dp.php';

// Redirect already logged in users
$auth = get_auth();
if ($auth) {
    header('Location: ' . $auth['role'] . '/dashboard.php');
    exit;
}

$mode  = $_GET['mode'] ?? 'login';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = ['success' => false, 'message' => ''];

    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';

        // ── LOGIN ──────────────────────────────────
        if ($action === 'login') {
            $email = trim($_POST['email'] ?? '');
            $pass  = $_POST['password'] ?? '';

            if (!$email || !$pass) {
                throw new Exception('Email and password are required.');
            }

            // Use prepared statement — SQL injection prevention
            $stmt = mysqli_prepare($conn,
                'SELECT id, name, password, role, photo
                 FROM users WHERE email = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$u || !password_verify($pass, $u['password'])) {
                throw new Exception('Invalid email or password.');
            }

            set_auth($u);
            $res['success']  = true;
            $res['redirect'] = $u['role'] . '/dashboard.php';
        }

        // ── REGISTRATION ───────────────────────────
        if ($action === 'register') {
            $name  = trim($_POST['name']  ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['student_phone'] ?? '');
            $pref  = trim($_POST['room_preference'] ?? 'No Preference');
            $pass  = $_POST['password']  ?? '';
            $conf  = $_POST['confirm_password'] ?? '';

            // Validate required fields
            if (!$name || !$email || !$pass || !$phone) {
                throw new Exception('Name, email, phone and password are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            if (strlen($pass) < 6) {
                throw new Exception('Password must be at least 6 characters.');
            }
            if ($pass !== $conf) {
                throw new Exception('Passwords do not match.');
            }

            // Check email not already registered
            $chk = mysqli_prepare($conn,
                'SELECT id FROM users WHERE email = ? LIMIT 1');
            mysqli_stmt_bind_param($chk, 's', $email);
            mysqli_stmt_execute($chk);
            mysqli_stmt_store_result($chk);
            if (mysqli_stmt_num_rows($chk) > 0) {
                throw new Exception('This email address is already registered.');
            }
            mysqli_stmt_close($chk);

            // Hash password securely
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            // Insert new student
            $ins = mysqli_prepare($conn,
                'INSERT INTO users
                 (name, email, student_phone, password, role, room_preference)
                 VALUES (?, ?, ?, ?, \'student\', ?)');
            mysqli_stmt_bind_param($ins, 'sssss',
                $name, $email, $phone, $hash, $pref);

            if (mysqli_stmt_execute($ins)) {
                $res['success'] = true;
                $res['message'] = 'Account created! You can now login.';
            } else {
                throw new Exception('Registration failed. Please try again.');
            }
            mysqli_stmt_close($ins);
        }

    } catch (Exception $ex) {
        $res['message'] = $ex->getMessage();
    }

    // Handle AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }

    // Handle normal form submit
    if ($res['success'] && !empty($res['redirect'])) {
        header('Location: ' . $res['redirect']);
        exit;
    }
    $error   = $res['success'] ? '' : $res['message'];
    $success = $res['success'] ? $res['message'] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — <?= $mode === 'login' ? 'Login' : 'Register' ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .auth-toggle { cursor:pointer; color:var(--brand);
                       text-decoration:underline; font-weight:700; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .password-wrapper { position:relative; }
        .toggle-pwd { position:absolute; right:10px; top:50%;
                      transform:translateY(-50%); cursor:pointer;
                      color:var(--text-muted); font-size:0.8rem;
                      background:none; border:none; padding:4px 8px; }
        .toggle-pwd:hover { color:var(--brand); }
        @media(max-width:600px) { .grid-2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="auth-container"
     style="<?= $mode === 'register' ? 'padding:4rem 2rem;' : '' ?>">
    <div class="auth-card"
         style="<?= $mode === 'register' ? 'max-width:560px;' : '' ?>">

        <div class="auth-header">
            <h1>HMS</h1>
            <p><?= $mode === 'login' ? 'Login to your account' : 'Create student account' ?></p>
        </div>

        <!-- Alert messages -->
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post" id="auth-form">
            <input type="hidden" name="csrf_token"
                   value="<?= csrf_token() ?>">
            <input type="hidden" name="action"
                   value="<?= $mode === 'login' ? 'login' : 'register' ?>">

            <?php if ($mode === 'register'): ?>
                <!-- REGISTRATION FIELDS -->
                <div class="grid-2">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input name="name" type="text" required
                               placeholder="Your full name">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input name="email" type="email" required
                               placeholder="Used for login">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input name="student_phone" type="text" required
                               placeholder="Mobile number">
                    </div>
                    <div class="form-group">
                        <label>Room Preference</label>
                        <select name="room_preference">
                            <option value="No Preference">No Preference</option>
                            <option value="Single">Single Room</option>
                            <option value="Double">Double Room</option>
                        </select>
                    </div>
                </div>
            <?php else: ?>
                <!-- LOGIN EMAIL FIELD -->
                <div class="form-group">
                    <label>Email Address</label>
                    <input name="email" type="email" required
                           placeholder="Enter your email">
                </div>
            <?php endif; ?>

            <!-- PASSWORD -->
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input name="password" id="pass1" type="password"
                           required placeholder="••••••••"
                           minlength="6">
                    <button type="button" class="toggle-pwd"
                            onclick="togglePwd('pass1', this)">Show</button>
                </div>
            </div>

            <?php if ($mode === 'register'): ?>
            <!-- CONFIRM PASSWORD -->
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="password-wrapper">
                    <input name="confirm_password" id="pass2"
                           type="password" required
                           placeholder="Repeat password">
                    <button type="button" class="toggle-pwd"
                            onclick="togglePwd('pass2', this)">Show</button>
                </div>
                <small id="pass-match" style="font-size:0.75rem;"></small>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"
                    style="width:100%; margin-top:1.5rem;">
                <?= $mode === 'login' ? 'Login' : 'Create Account' ?>
            </button>
        </form>

        <div style="margin-top:1.5rem; text-align:center; font-size:0.875rem;">
            <?php if ($mode === 'login'): ?>
                <p style="color:var(--text-muted);">
                    New student?
                    <a href="?mode=register" class="auth-toggle">
                        Register here
                    </a>
                </p>
            <?php else: ?>
                <p style="color:var(--text-muted);">
                    Already have an account?
                    <a href="?mode=login" class="auth-toggle">Back to Login</a>
                </p>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="js/script.js"></script>
<script>
function togglePwd(id, btn) {
    const f = document.getElementById(id);
    if (f.type === 'password') { f.type = 'text';     btn.textContent = 'Hide'; }
    else                       { f.type = 'password'; btn.textContent = 'Show'; }
}

// Live password match check
const p2 = document.getElementById('pass2');
if (p2) {
    p2.addEventListener('input', function () {
        const p1  = document.getElementById('pass1').value;
        const msg = document.getElementById('pass-match');
        if (!this.value) { msg.textContent = ''; return; }
        if (this.value === p1) {
            msg.textContent = '✓ Passwords match';
            msg.style.color = '#16a34a';
        } else {
            msg.textContent = '✗ Passwords do not match';
            msg.style.color = '#be123c';
        }
    });
}
</script>
</body>
</html>
