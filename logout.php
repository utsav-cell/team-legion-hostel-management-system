<?php
// ─────────────────────────────────────────────────
// logout.php — Clear session and cookie then redirect
// ─────────────────────────────────────────────────

require_once 'dp.php';

// Clear JWT cookie
if (isset($_COOKIE['auth_token'])) {
    setcookie('auth_token', '', time() - 3600, '/');
}

// Clear session
session_unset();
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
?>
