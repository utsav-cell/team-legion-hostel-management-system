<?php
// ─────────────────────────────────────────────────
// login.php — Login, Registration and 2FA Verification
// ─────────────────────────────────────────────────

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/mailer.php';

// Redirect already logged in users
$auth = get_auth();
if ($auth) {
    header('Location: ../' . $auth['role'] . '/dashboard.php');
    exit;
}

$mode  = $_GET['mode'] ?? 'login';
if ($mode === 'verify') {
    $mode = 'login';
}
$error = $success = '';
if (isset($_GET['msg'])) $success = trim($_GET['msg']);

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
            if (!(int)$u['is_verified']) {
                throw new Exception('Please verify your email using the OTP sent to you.');
            }

            set_auth($u);
            $res['success']  = true;
            $res['redirect'] = '../' . $u['role'] . '/dashboard.php';
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
            $pw_err = validate_strong_password($pass);
            if ($pw_err) throw new Exception($pw_err);
            if ($pass !== $conf) {
                throw new Exception('Passwords do not match.');
            }

            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                throw new Exception('This email is already registered.');
            }

            $photo_name = 'default.png';
            if (!empty($_FILES['photo']['name'])) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'])) throw new Exception('Only JPG/PNG allowed.');
                $photo_name = 'user_' . time() . '.' . $ext;
                // Photos live under the project-root uploads/ folder so all roles
                // (student/warden/owner) read from a single canonical path.
                $upload_dir = __DIR__ . '/../uploads/students';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . '/' . $photo_name)) {
                    throw new Exception('Failed to upload photo.');
                }
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $otp = (string) random_int(100000, 999999);
            $otp_expiry = date('Y-m-d H:i:s', time() + 600);

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO users (name, email, student_phone, password, role, room_preference, photo, is_verified, otp_code, otp_expiry, otp_created_at, otp_attempts)
                     VALUES (?, ?, ?, ?, \'student\', ?, ?, 0, ?, ?, NOW(), 0)'
                );
                if (!$ins->execute([$name, $email, $phone, $hash, $pref, $photo_name, $otp, $otp_expiry])) {
                    throw new Exception('Registration failed.');
                }

                $safe_otp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
                $body = '<div style="text-align:center; margin:8px 0 18px;">'
                      .   '<div style="display:inline-block; background:#eef4ff; padding:18px 28px; border-radius:14px; border:2px dashed #3b82f6;">'
                      .     '<div style="font-family:\'Courier New\',monospace; font-size:34px; font-weight:900; color:#1d4ed8; letter-spacing:0.45em; padding-left:0.45em;">' . $safe_otp . '</div>'
                      .   '</div>'
                      . '</div>'
                      . '<p style="margin:0;">This code expires in <strong>10 minutes</strong>. Type it on the verification page to finish creating your account.</p>';
                $html = render_branded_email([
                    'name'      => $name,
                    'kicker'    => 'Verify your email',
                    'title'     => 'Welcome to HMS — confirm it\'s you',
                    'intro'     => 'Almost done. Use this one-time code to activate your hostel account.',
                    'body_html' => $body,
                    'footnote'  => 'Didn\'t request this? You can safely ignore — your address won\'t be added until the code is entered.',
                ]);
                send_app_mail($email, $name, 'Your HMS verification code: ' . $otp, $html);

                $pdo->commit();
            } catch (Exception $regEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new Exception('Could not send verification email. ' . $regEx->getMessage());
            }

            $res['success']  = true;
            $res['message']  = 'Account created! Check your email for the OTP to verify your account.';
            $res['redirect'] = 'verify.php?email=' . urlencode($email);
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
    <link rel="stylesheet" href="../css/style.css?v=34">
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
        .pw-hint {
            display:block; margin-top:.45rem;
            font-size:.75rem; color:var(--text-muted); line-height:1.4;
        }
        /* Shake the card when the server returned a login error — drawing
           the eye to the toast that's about to fade in. */
        .auth-card.has-error { animation: shake .55s cubic-bezier(0.36, 0.07, 0.19, 0.97); }
        @keyframes shake {
            10%,90% { transform: translateX(-1px); }
            20%,80% { transform: translateX(2px); }
            30%,50%,70% { transform: translateX(-4px); }
            40%,60% { transform: translateX(4px); }
        }
        /* Persistent inline error banner (not hijacked by the toast layer) */
        .login-error {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            background: var(--panel-alt);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.65rem 0.85rem;
            font-size: 0.85rem;
            font-weight: 500;
            line-height: 1.4;
            margin-bottom: 1rem;
        }
        .login-error .login-error-icon {
            flex: 0 0 auto;
            width: 16px;
            height: 16px;
            stroke: var(--muted);
            fill: none;
            stroke-width: 2;
        }
        @media(max-width:600px) { .grid-2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="auth-container" style="<?= $mode === 'register' ? 'padding:2rem 1rem;' : '' ?>">
    <div class="auth-card<?= $error ? ' has-error' : '' ?>" style="<?= $mode === 'register' ? 'max-width:600px;' : '' ?>">
        <div class="auth-header">
            <h1>HMS</h1>
            <p><?= $mode === 'login' ? 'Login' : ($mode === 'register' ? 'Create Account' : 'Verify Email') ?></p>
        </div>

        <?php if ($error): ?>
            <div class="login-error" role="alert" aria-live="assertive">
                <svg class="login-error-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
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
                    <div class="form-group">
                        <label>Profile Photo</label>
                        <input name="photo" type="file" required accept="image/*">
                        <small style="color:var(--text-muted);">Please upload a clear face photo</small>
                    </div>
                <?php else: ?>
                    <div class="form-group"><label>Email Address</label><input name="email" type="email" required></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper">
                        <input name="password" id="pass1" type="password" required minlength="6"
                               <?= $mode === 'register' ? 'pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{6,}" title="At least 6 characters with an uppercase letter, a lowercase letter and a number."' : '' ?>>
                        <button type="button" class="toggle-pwd" onclick="togglePwd('pass1', this)">Show</button>
                    </div>
                    <?php if ($mode === 'register'): ?>
                        <small class="pw-hint"><?= e(password_rules_hint()) ?></small>
                    <?php endif; ?>
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
