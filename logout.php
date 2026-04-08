<?php
// ─────────────────────────────────────────────────
// logout.php — Clear session and cookie then redirect
// ─────────────────────────────────────────────────

require_once 'dp.php';

$auth = get_auth();
if ($auth) {
    revoke_user_tokens((int)$auth['id']);
}

// Clear JWT cookie
if (isset($_COOKIE['auth_token'])) {
    setcookie('auth_token', '', auth_cookie_options(time() - 3600));
}

// Clear session
session_unset();
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
?>
