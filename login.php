<?php
// ─────────────────────────────────────────────────
// login.php — Login, Registration and 2FA Verification
// ─────────────────────────────────────────────────

require_once 'db.php';

// Redirect already logged in users
$auth = get_auth();
if ($auth) {
    header('Location: ' . $auth['role'] . '/dashboard.php');
    exit;
}

$mode  = $_GET['mode'] ?? 'login';
if ($mode === 'verify') {
    $mode = 'login';
}
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

            $stmt = $pdo->prepare(
                'SELECT id, name, password, role, photo, is_verified, token_version
                 FROM users WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $u = $stmt->fetch();

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

            if (!$name || !$email || !$pass || !$phone) {
                throw new Exception('All fields are required.');
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

            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                throw new Exception('This email is already registered.');
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $ins = $pdo->prepare(
                'INSERT INTO users (name, email, student_phone, password, role, room_preference, is_verified)
                 VALUES (?, ?, ?, ?, \'student\', ?, 1)'
            );

            if ($ins->execute([$name, $email, $phone, $hash, $pref])) {
                $res['success'] = true;
                $res['message'] = "Account created successfully! You can now log in.";
                $res['redirect'] = "?mode=login";
            } else {
                throw new Exception('Registration failed.');
            }
        }


    } catch (Exception $ex) {
        $res['message'] = $ex->getMessage();
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode($res); exit;
    }
    if ($res['success'] && !empty($res['redirect'])) {
        header('Location: ' . $res['redirect']); exit;
    }
    $error = $res['success'] ? '' : $res['message'];
    $success = $res['success'] ? $res['message'] : '';
}

// Support for simulated OTP display on verify screen
$simulated_otp = $_GET['otp_msg'] ?? '';
$verify_email = $_GET['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS — <?= ucfirst($mode) ?></title>
    <link rel="stylesheet" href="css/style.css?v=4">
    <style>
        body {
            background: #f8fafc;
            min-height: 100vh;
            position: relative;
        }
        .auth-container { background: transparent; }
        .auth-card {
            background: rgba(255,255,255,0.93);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.55);
        }
        .auth-toggle { cursor:pointer; color:var(--brand); text-decoration:underline; font-weight:700; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .password-wrapper { position:relative; }
        .toggle-pwd { position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-muted); font-size:0.8rem; background:none; border:none; padding:4px 8px; }
        @media(max-width:600px) { .grid-2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="auth-container" style="<?= $mode === 'register' ? 'padding:2rem 1rem;' : '' ?>">
    <div class="auth-card" style="<?= $mode === 'register' ? 'max-width:600px;' : '' ?>">
        <div class="auth-header">
            <h1>HMS</h1>
            <p><?= $mode === 'login' ? 'Login' : ($mode === 'register' ? 'Create Account' : 'Verify Email') ?></p>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <form method="post">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" id="action" name="action" value="<?= $mode === 'login' ? 'login' : 'register' ?>">

                <?php if ($mode === 'register'): ?>
                    <div class="grid-2">
                        <div class="form-group"><label>Full Name</label><input name="name" type="text" required></div>
                        <div class="form-group"><label>Email Address</label><input name="email" type="email" required></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>Phone Number</label><input name="student_phone" type="text" required></div>
                        <div class="form-group">
                            <label>Room Preference</label>
                            <select name="room_preference">
                                <option value="No Preference">No Preference</option>
                                <option value="Single">Single</option>
                                <option value="Double">Double</option>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group"><label>Email Address</label><input name="email" type="email" required></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper">
                        <input name="password" id="pass1" type="password" required minlength="6">
                        <button type="button" class="toggle-pwd" onclick="togglePwd('pass1', this)">Show</button>
                    </div>
                </div>

                <?php if ($mode === 'login'): ?>
                    <div style="margin-top:-0.5rem; margin-bottom:0.75rem; text-align:right;">
                        <a href="forgot_password.php" class="auth-toggle">Forgot password?</a>
                    </div>
                <?php endif; ?>

                <?php if ($mode === 'register'): ?>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="password-wrapper">
                            <input name="confirm_password" id="pass2" type="password" required>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1.5rem;">
                    <?= $mode === 'login' ? 'Login' : 'Create Account' ?>
                </button>
            </form>

        <div style="margin-top:1.5rem; text-align:center; font-size:0.875rem;">
            <?php if ($mode === 'login'): ?>
                <p>New student? <a href="?mode=register" class="auth-toggle">Register here</a></p>
            <?php else: ?>
                <p>Already have an account? <a href="?mode=login" class="auth-toggle">Back to Login</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function togglePwd(id, btn) {
    const f = document.getElementById(id);
    if (f.type === 'password') { f.type = 'text'; btn.textContent = 'Hide'; }
    else { f.type = 'password'; btn.textContent = 'Show'; }
}
</script>
</body>
</html>
