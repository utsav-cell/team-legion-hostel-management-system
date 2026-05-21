<?php
// auth/resend_otp.php — regenerate OTP and resend the email.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/mailer.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    csrf_verify($_POST['csrf_token'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email required.');
    }

    $stmt = $pdo->prepare('SELECT id, name, is_verified FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u)                              throw new Exception('Account not found.');
    if ((int)$u['is_verified'] === 1)     throw new Exception('Account already verified — just log in.');

    $otp        = (string) random_int(100000, 999999);
    $otp_expiry = date('Y-m-d H:i:s', time() + 600);

    $body = '<div style="text-align:center; margin:8px 0 18px;">'
          .   '<div style="display:inline-block; background:#eef4ff; padding:18px 28px; border-radius:14px; border:2px dashed #3b82f6;">'
          .     '<div style="font-family:\'Courier New\',monospace; font-size:34px; font-weight:900; color:#1d4ed8; letter-spacing:0.45em; padding-left:0.45em;">' . htmlspecialchars($otp) . '</div>'
          .   '</div>'
          . '</div>'
          . '<p style="margin:0;">This code expires in <strong>10 minutes</strong>. The previous code is no longer valid.</p>';
    $html = render_branded_email([
        'name'      => $u['name'],
        'kicker'    => 'New verification code',
        'title'     => 'Here\'s a fresh OTP for your account',
        'intro'     => 'You asked for a new code. Use the one below to verify your email.',
        'body_html' => $body,
        'footnote'  => 'Didn\'t request this? You can safely ignore — no changes have been made to your account.',
    ]);
    try {
        send_app_mail($email, $u['name'], 'Your new HMS verification code: ' . $otp, $html);
    } catch (Exception $mailEx) {
        mailer_log('RESEND fail email=' . $email . ' :: ' . $mailEx->getMessage());
        throw new Exception('We couldn\'t resend the code right now. Please try again in a few moments.');
    }

    $pdo->prepare('UPDATE users SET otp_code = ?, otp_expiry = ?, otp_created_at = NOW(), otp_attempts = 0 WHERE id = ?')
        ->execute([$otp, $otp_expiry, (int)$u['id']]);

    echo json_encode(['success' => true, 'message' => 'OTP resent.']);
} catch (Throwable $ex) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
}
