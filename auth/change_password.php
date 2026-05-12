<?php
// -------------------------------------------------
// auth/change_password.php - Password change page
//   Works for all logged-in roles. Verifies the current
//   password, enforces min length and confirmation match,
//   then re-hashes and invalidates all existing JWT cookies
//   so other sessions are forced to re-login.
// -------------------------------------------------

require_once '../db.php';

$auth = get_auth();
if (!$auth) {
    header('Location: login.php');
    exit;
}

$uid   = (int)$auth['id'];
$role  = $auth['role'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');

        $current_password  = $_POST['current_password']  ?? '';
        $new_password      = $_POST['new_password']      ?? '';
        $confirm_password  = $_POST['confirm_password']  ?? '';

        if (!$current_password || !$new_password || !$confirm_password) {
            throw new Exception('All fields are required.');
        }
        if (strlen($new_password) < 6) {
            throw new Exception('New password must be at least 6 characters.');
        }
        if ($new_password !== $confirm_password) {
            throw new Exception('New passwords do not match.');
        }

        // Fetch stored hash
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current_password, $user['password'])) {
            throw new Exception('Current password is incorrect.');
        }

        // Update to new hash
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([$new_hash, $uid]);

        // Invalidate all existing JWT cookies for this user
        revoke_user_tokens($uid);

        // Clear current session too
        session_unset();
        session_destroy();

        header('Location: login.php?msg=' . urlencode('Password changed successfully. Please login with your new password.'));
        exit;

    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// Determine back link by role
$dashboard_url = '../' . $role . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        body { background:#f8fafc; min-height:100vh; }
        .auth-container { background:transparent; }
        .auth-card {
            background:rgba(255,255,255,0.95);
            backdrop-filter:blur(12px);
            border:1px solid rgba(255,255,255,0.55);
        }
        .password-wrapper { position:relative; }
        .toggle-pwd {
            position:absolute; right:10px; top:50%; transform:translateY(-50%);
            cursor:pointer; color:var(--muted); font-size:0.8rem;
            background:none; border:none; padding:4px 8px;
        }
    </style>
</head>
<body>
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <h1>HMS</h1>
            <p>Change Password</p>
        </div>

        <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
                <label>Current Password</label>
                <div class="password-wrapper">
                    <input type="password" id="pass_current" name="current_password" required>
                    <button type="button" class="toggle-pwd" onclick="togglePwd('pass_current', this)">Show</button>
                </div>
            </div>

            <div class="form-group">
                <label>New Password <small style="color:var(--muted);">(min 6 characters)</small></label>
                <div class="password-wrapper">
                    <input type="password" id="pass_new" name="new_password" required minlength="6">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('pass_new', this)">Show</button>
                </div>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="password-wrapper">
                    <input type="password" id="pass_confirm" name="confirm_password" required minlength="6">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('pass_confirm', this)">Show</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1.5rem;">
                Update Password
            </button>
        </form>

        <div style="margin-top:1.25rem;text-align:center;font-size:0.875rem;">
            <a href="<?= e($dashboard_url) ?>" style="color:var(--brand);">Back to Dashboard</a>
        </div>
    </div>
</div>

<script>
function togglePwd(id, btn) {
    var f = document.getElementById(id);
    if (f.type === 'password') { f.type = 'text'; btn.textContent = 'Hide'; }
    else { f.type = 'password'; btn.textContent = 'Show'; }
}
</script>
</body>
</html>
