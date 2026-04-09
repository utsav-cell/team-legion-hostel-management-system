<?php
// ─────────────────────────────────────────────────
// forgot_password.php — Request Password Reset OTP
// ─────────────────────────────────────────────────

require_once 'dp.php';

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$email) throw new Exception('Email is required.');

        // Find student
        $stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE email = ? AND role = 'student' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$u) {
            // For security, don't reveal if email exists, but here we'll be helpful
            throw new Exception('Email not found or not a student account.');
        }

        $success = "Password reset via OTP is disabled. Please contact your hostel administrator for assistance.";

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
