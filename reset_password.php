<?php
// ─────────────────────────────────────────────────
// reset_password.php — Verify OTP and set new password
// ─────────────────────────────────────────────────

require_once 'db.php';

$error = $success = '';
$email_prefill = trim($_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');
        $pass = $_POST['password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';

        if (!$email || !$otp || !$pass || !$conf) {
            throw new Exception('All fields are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new Exception('OTP must be 6 digits.');
        }
        if (strlen($pass) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }
        if ($pass !== $conf) {
            throw new Exception('Passwords do not match.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, reset_otp, reset_otp_expiry FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u) {
            throw new Exception('Account not found.');
        }
        if (!$u['reset_otp'] || !$u['reset_otp_expiry']) {
            throw new Exception('Reset OTP is not available. Please request a new code.');
        }
        if (!hash_equals($u['reset_otp'], $otp)) {
            throw new Exception('Invalid OTP.');
        }
        if (strtotime($u['reset_otp_expiry']) < time()) {
            throw new Exception('OTP has expired. Please request a new code.');
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $upd = $pdo->prepare(
            'UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expiry = NULL, token_version = token_version + 1 WHERE id = ?'
        );
        $upd->execute([$hash, $u['id']]);

        $success = 'Password updated. You can log in now.';
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — HMS</title>
    <link rel="stylesheet" href="css/style.css?v=2">
    <style>
        body { background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: 'Outfit', sans-serif; }
        .auth-card { background: white; padding: 3rem; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 420px; }
        .auth-header h1 { font-size: 2rem; font-weight: 900; color: var(--brand); margin-bottom: 0.5rem; text-align: center; }
        .auth-header p { color: var(--text-muted); text-align: center; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <h1>HMS</h1>
            <p>Set a new password</p>
        </div>

        <?php if ($error): ?><div class="alert alert-error" style="background:var(--danger-soft); color:#b91c1c; padding:1rem; border-radius:12px; margin-bottom:1.5rem;"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" style="background:#ecfdf3; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem;"><?= e($success) ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label>Registered Email</label>
                <input type="email" name="email" required value="<?= e($email_prefill) ?>" placeholder="name@email.com" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <div class="form-group">
                <label>OTP Code</label>
                <input type="text" name="otp" required maxlength="6" inputmode="numeric" placeholder="6-digit code" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" required minlength="6" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required minlength="6" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1.5rem; background:var(--brand); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer;">Update Password</button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem;">
            <a href="login.php" style="color: var(--text-muted); font-size: 0.875rem; text-decoration: none;">Back to Login</a>
        </div>
    </div>
</body>
</html>
