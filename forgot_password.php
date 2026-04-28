<?php
// ─────────────────────────────────────────────────
// forgot_password.php — Request Password Reset OTP
// ─────────────────────────────────────────────────

require_once 'db.php';
require_once 'mailer.php';

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$email) throw new Exception('Email is required.');

        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u) {
            throw new Exception('Email not found.');
        }

        $otp = (string) random_int(100000, 999999);
        $otp_expiry = date('Y-m-d H:i:s', time() + 600);

        $upd = $pdo->prepare(
            'UPDATE users SET reset_otp = ?, reset_otp_expiry = ? WHERE id = ?'
        );
        $upd->execute([$otp, $otp_expiry, $u['id']]);

        $safe_name = htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8');
        $safe_otp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $body = "<p>Hi {$safe_name},</p>"
            . "<p>Use this OTP to reset your password:</p>"
            . "<h2 style=\"letter-spacing:2px;\">{$safe_otp}</h2>"
            . "<p>This code expires in 10 minutes.</p>";

        send_app_mail($email, $u['name'], 'Reset your password - HMS', $body);

        $success = 'Reset code sent. Check your email.';
        header('Location: reset_password.php?email=' . urlencode($email));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — HMS</title>
    <link rel="stylesheet" href="css/style.css?v=2">
    <style>
        body { background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: 'Outfit', sans-serif; }
        .auth-card { background: white; padding: 3rem; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .auth-header h1 { font-size: 2rem; font-weight: 900; color: var(--brand); margin-bottom: 0.5rem; text-align: center; }
        .auth-header p { color: var(--text-muted); text-align: center; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <h1>HMS</h1>
            <p>Reset your password</p>
        </div>
        
        <?php if ($error): ?><div class="alert alert-error" style="background:var(--danger-soft); color:#b91c1c; padding:1rem; border-radius:12px; margin-bottom:1.5rem;"><?= e($error) ?></div><?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label>Enter Registered Email</label>
                <input type="email" name="email" required placeholder="name@email.com" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:2rem; background:var(--brand); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer;">Send Reset Code</button>
        </form>
        
        <div style="text-align: center; margin-top: 1.5rem;">
            <a href="login.php" style="color: var(--text-muted); font-size: 0.875rem; text-decoration: none;">Back to Login</a>
        </div>
    </div>
</body>
</html>
