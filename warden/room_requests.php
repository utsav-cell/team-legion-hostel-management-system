<?php
// ─────────────────────────────────────────────────
// warden/room_requests.php — DEPRECATED
// Merged into booking_requests.php (same workflow).
// This file now redirects to the canonical page.
// ─────────────────────────────────────────────────
require_once '../db.php';
require_role('warden');

header('Location: booking_requests.php', true, 301);
exit;
