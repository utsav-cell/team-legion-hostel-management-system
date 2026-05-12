<?php
// ─────────────────────────────────────────────────
// auth/verify.php — Email OTP verification (FR-S2-003)
//   - Max 3 wrong attempts per OTP
//   - 10-minute expiry, countdown timer
//   - Resend OTP button enabled after 30s
//   - On success → redirect to login
// ─────────────────────────────────────────────────

require_once __DIR__ . '/../db.php';

$error = $success = '';
$email_prefill = trim($_GET['email'] ?? $_POST['email'] ?? '');
$expires_at_iso = '';
$attempts_left = 3;
$locked = false;

// Look up the student so we can render the timer + remaining attempts.
if ($email_prefill) {
    $look = $pdo->prepare('SELECT id, otp_expiry, otp_attempts, is_verified FROM users WHERE email = ? LIMIT 1');
    $look->execute([$email_prefill]);
    if ($u = $look->fetch()) {
        if ((int)$u['is_verified'] === 1) {
            $success = 'Your account is already verified. Redirecting to login…';
        }
        if ($u['otp_expiry']) {
            $expires_at_iso = date(DATE_ATOM, strtotime($u['otp_expiry']));
        }
        $attempts_left = max(0, 3 - (int)$u['otp_attempts']);
        $locked = $attempts_left === 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $otp   = trim($_POST['otp'] ?? '');

        if (!$email || !$otp)                       throw new Exception('Email and OTP are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid email address.');
        if (!preg_match('/^\d{6}$/', $otp))         throw new Exception('OTP must be 6 digits.');

        $stmt = $pdo->prepare('SELECT id, otp_code, otp_expiry, otp_attempts, is_verified FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u)                                    throw new Exception('Account not found.');

        if ((int)$u['is_verified'] === 1) {
            header('Location: login.php?msg=' . urlencode('Your account is already verified. Please log in.'));
            exit;
        }
        if (!$u['otp_code'] || !$u['otp_expiry'])   throw new Exception('OTP is not available. Please request a new one.');
        if ((int)$u['otp_attempts'] >= 3)           throw new Exception('Too many wrong attempts. Click "Resend OTP" to get a new code.');
        if (strtotime($u['otp_expiry']) < time())   throw new Exception('OTP has expired. Click "Resend OTP" to get a new code.');

        if (!hash_equals($u['otp_code'], $otp)) {
            $pdo->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([(int)$u['id']]);
            $left = max(0, 3 - ((int)$u['otp_attempts'] + 1));
            throw new Exception('Invalid OTP. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.');
        }

        // Success — clear OTP fields, mark verified, redirect to login.
        $pdo->prepare('UPDATE users SET is_verified = 1, otp_code = NULL, otp_expiry = NULL, otp_attempts = 0 WHERE id = ?')
            ->execute([(int)$u['id']]);
        header('Location: login.php?msg=' . urlencode('Email verified! Please log in.'));
        exit;
    } catch (Exception $ex) {
        $error = $ex->getMessage();
        // Re-fetch attempts for display
        if ($email_prefill) {
            $look = $pdo->prepare('SELECT otp_expiry, otp_attempts FROM users WHERE email = ? LIMIT 1');
            $look->execute([$email_prefill]);
            if ($u2 = $look->fetch()) {
                $attempts_left = max(0, 3 - (int)$u2['otp_attempts']);
                $locked = $attempts_left === 0;
                if ($u2['otp_expiry']) $expires_at_iso = date(DATE_ATOM, strtotime($u2['otp_expiry']));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=18">
    <style>
        body { background:#f8fafc; display:flex; align-items:center; justify-content:center; min-height:100vh; font-family:'Outfit', sans-serif; }
        .auth-card { background:white; padding:3rem; border-radius:24px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1); width:100%; max-width:460px; }
        .auth-header h1 { font-size:2rem; font-weight:900; color:var(--brand); margin-bottom:0.5rem; text-align:center; }
        .auth-header p { color:var(--text-muted); text-align:center; margin-bottom:2rem; }
        .otp-meta { display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; color:var(--text-muted); margin-top:0.75rem; }
        .otp-meta .timer.expired { color:#b91c1c; font-weight:700; }
        .resend-btn { background:none; border:1px solid var(--border); color:var(--brand); padding:0.45rem 1rem; border-radius:10px; font-weight:700; font-size:0.8rem; cursor:pointer; }
        .resend-btn:disabled { color:#94a3b8; border-color:#e2e8f0; cursor:not-allowed; }
        .resend-status { font-size:0.78rem; color:var(--text-muted); margin-top:0.5rem; min-height:1em; }
        .resend-status.ok  { color:#15803d; }
        .resend-status.err { color:#b91c1c; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <h1>HMS</h1>
            <p>Verify your email</p>
        </div>

        <?php if ($error):   ?><div class="alert alert-error" style="background:var(--danger-soft); color:#b91c1c; padding:1rem; border-radius:12px; margin-bottom:1.5rem;"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success" style="background:#ecfdf3; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem;"><?= e($success) ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label>Registered Email</label>
                <input type="email" name="email" id="vEmail" required value="<?= e($email_prefill) ?>" placeholder="name@email.com" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;">
            </div>
            <div class="form-group">
                <label>OTP Code</label>
                <input type="text" name="otp" required maxlength="6" inputmode="numeric" placeholder="6-digit code" class="form-control" style="width:100%; padding:0.75rem; border:1px solid var(--border); border-radius:12px;" <?= $locked ? 'disabled' : '' ?>>
            </div>

            <div class="otp-meta">
                <span>
                    Attempts left: <strong id="attemptsLeft"><?= $attempts_left ?></strong> / 3
                    <?php if ($expires_at_iso): ?>
                        · expires in <span class="timer" id="otpTimer">…</span>
                    <?php endif; ?>
                </span>
                <button type="button" class="resend-btn" id="resendBtn" disabled>Resend OTP (30s)</button>
            </div>
            <div class="resend-status" id="resendStatus"></div>

            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1.5rem; background:var(--brand); color:white; border:none; padding:1rem; border-radius:12px; font-weight:700; cursor:pointer;" <?= $locked ? 'disabled' : '' ?>>Verify Email</button>
        </form>

        <div style="text-align:center; margin-top:1.5rem;">
            <a href="login.php" style="color:var(--text-muted); font-size:0.875rem; text-decoration:none;">Back to Login</a>
        </div>
    </div>

<script>
(function(){
    const csrf      = <?= json_encode(csrf_token()) ?>;
    const expiresAt = <?= $expires_at_iso ? json_encode($expires_at_iso) : 'null' ?>;
    const timerEl   = document.getElementById('otpTimer');
    const resendBtn = document.getElementById('resendBtn');
    const resendSt  = document.getElementById('resendStatus');
    const emailEl   = document.getElementById('vEmail');

    // Countdown of OTP expiry
    if (expiresAt && timerEl) {
        const exp = new Date(expiresAt).getTime();
        function tickExp() {
            const left = Math.max(0, Math.floor((exp - Date.now()) / 1000));
            const m = Math.floor(left / 60), s = left % 60;
            timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            if (left === 0) timerEl.classList.add('expired');
        }
        tickExp(); setInterval(tickExp, 1000);
    }

    // Resend cooldown (30s)
    let cooldown = 30;
    function tickCooldown() {
        if (cooldown > 0) {
            resendBtn.textContent = 'Resend OTP (' + cooldown + 's)';
            resendBtn.disabled = true;
            cooldown--;
            setTimeout(tickCooldown, 1000);
        } else {
            resendBtn.textContent = 'Resend OTP';
            resendBtn.disabled = false;
        }
    }
    tickCooldown();

    resendBtn.addEventListener('click', async () => {
        resendSt.className = 'resend-status'; resendSt.textContent = '';
        const email = (emailEl.value || '').trim();
        if (!email) { resendSt.className = 'resend-status err'; resendSt.textContent = 'Enter your email first.'; return; }
        resendBtn.disabled = true; resendBtn.textContent = 'Sending…';
        try {
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('email', email);
            const res = await fetch('resend_otp.php', { method:'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                resendSt.className = 'resend-status ok'; resendSt.textContent = 'New OTP sent. Check your inbox.';
                cooldown = 30; tickCooldown();
                // soft reload to refresh expiry timer + attempts
                setTimeout(() => location.href = 'verify.php?email=' + encodeURIComponent(email), 1500);
            } else {
                resendSt.className = 'resend-status err'; resendSt.textContent = data.message || 'Could not send OTP.';
                cooldown = 30; tickCooldown();
            }
        } catch (e) {
            resendSt.className = 'resend-status err'; resendSt.textContent = 'Connection error.';
            cooldown = 30; tickCooldown();
        }
    });
})();
</script>
</body>
</html>
