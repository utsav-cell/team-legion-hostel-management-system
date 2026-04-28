<?php
// ─────────────────────────────────────────────────
// verify.php — Email OTP Verification
// ─────────────────────────────────────────────────

require_once 'db.php';

$error = $success = '';
$email_prefill = trim($_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');

        if (!$email || !$otp) {
            throw new Exception('Email and OTP are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new Exception('OTP must be 6 digits.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, otp_code, otp_expiry, is_verified FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u) {
            throw new Exception('Account not found.');
        }
        if ((int)$u['is_verified'] === 1) {
            $success = 'Your account is already verified. You can log in.';
        } else {
            if (!$u['otp_code'] || !$u['otp_expiry']) {
                throw new Exception('OTP is not available. Please register again.');
            }
            if (!hash_equals($u['otp_code'], $otp)) {
                throw new Exception('Invalid OTP.');
            }
            if (strtotime($u['otp_expiry']) < time()) {
                throw new Exception('OTP has expired. Please register again.');
            }

            $upd = $pdo->prepare(
                'UPDATE users SET is_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?'
            );
            $upd->execute([$u['id']]);
            $success = 'Email verified successfully. You can now log in.';
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — HMS</title>
    <link rel="stylesheet" href="css/style.css?v=4">
    <style>
        body { background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: 'Outfit', sans-serif; }
        .auth-card { background: white; padding: 3rem; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 460px; }
        .auth-header h1 { font-size: 2rem; font-weight: 900; color: var(--brand); margin-bottom: 0.5rem; text-align: center; }
        .auth-header p { color: var(--text-muted); text-align: center; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <h1>HMS</h1>
            <p>Verify your email</p>
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
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:2rem; background:var(--brand); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer;">Verify Email</button>
        </form>

        <div style="text-align: center; margin-top: 1.5rem;">
            <a href="login.php" style="color: var(--text-muted); font-size: 0.875rem; text-decoration: none;">Back to Login</a>
        </div>
    </div>
</body>
</html>
