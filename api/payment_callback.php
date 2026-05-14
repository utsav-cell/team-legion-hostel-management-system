<?php
// api/payment_callback.php - eSewa v2 payment callback handler
// eSewa redirects here with ?result=success&data=BASE64 or ?result=failure

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/mailer.php';

$pay_base = app_config('PAYMENT_BASE_URL', 'http://localhost/team-legion-hostel-management-system');

// eSewa appends ?data=BASE64 to a clean success_url.
// If eSewa redirects to failure_url it passes ?result=failure&pid=X instead.
// Detect success by the presence of the data parameter.
$data_raw = $_GET['data'] ?? '';
$result   = (!empty($data_raw)) ? 'success' : ($_GET['result'] ?? 'failure');

// ── Failure / cancellation path ──────────────────────────────────
if ($result !== 'success') {
    // Cancel by payment ID (reliable) or UUID (eSewa sometimes passes it)
    $pid  = (int)($_GET['pid'] ?? 0);
    $uuid = $_GET['transaction_uuid'] ?? '';
    try {
        if ($pid > 0) {
            $pdo->prepare("UPDATE payments SET status='cancelled' WHERE id=? AND status='pending'")
                ->execute([$pid]);
        } elseif ($uuid) {
            $pdo->prepare("UPDATE payments SET status='cancelled' WHERE transaction_uuid=? AND status='pending'")
                ->execute([$uuid]);
        }
    } catch (Exception $e) {}
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Payment was cancelled or failed. Please try again.'));
    exit;
}

// ── Success path ─────────────────────────────────────────────────
// $data_raw is already set at the top of this file
if (!$data_raw) {
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Invalid payment response.'));
    exit;
}

// Decode and parse eSewa response
$decoded = base64_decode($data_raw, true);
if (!$decoded) {
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Malformed payment response.'));
    exit;
}
$resp = json_decode($decoded, true);
if (!$resp || empty($resp['transaction_uuid'])) {
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Could not parse payment response.'));
    exit;
}

// Verify eSewa signature
$signed_names = explode(',', $resp['signed_field_names'] ?? '');
$parts = [];
foreach ($signed_names as $fn) {
    $fn = trim($fn);
    if (isset($resp[$fn])) $parts[] = $fn . '=' . $resp[$fn];
}
$sign_string = implode(',', $parts);
$expected    = esewa_sign($sign_string);

if (!hash_equals($expected, $resp['signature'] ?? '')) {
    // Log the mismatch but do not expose details to browser
    error_log('[HMS] eSewa signature mismatch for uuid=' . $resp['transaction_uuid']);
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Payment verification failed. Contact support.'));
    exit;
}

$uuid          = $resp['transaction_uuid'];
$gateway_ref   = $resp['transaction_code'] ?? '';
$resp_status   = $resp['status'] ?? '';

if (strtoupper($resp_status) !== 'COMPLETE') {
    // Mark failed
    try {
        $pdo->prepare("UPDATE payments SET status='failed', gateway_ref=? WHERE transaction_uuid=? AND status='pending'")
            ->execute([$gateway_ref, $uuid]);
    } catch (Exception $e) {}
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Payment not completed by eSewa. Status: ' . $resp_status));
    exit;
}

// Load the pending payments row - try UUID first, fall back to pid
$stmt = $pdo->prepare("SELECT * FROM payments WHERE transaction_uuid = ? AND status = 'pending' LIMIT 1");
$stmt->execute([$uuid]);
$payment = $stmt->fetch();
if (!$payment && !empty($_GET['pid'])) {
    $stmt2 = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt2->execute([(int)$_GET['pid']]);
    $payment = $stmt2->fetch();
}

if (!$payment) {
    // Already processed (duplicate callback) - try to find it as success
    $s2 = $pdo->prepare("SELECT receipt_code FROM payments WHERE transaction_uuid = ? AND status = 'success' LIMIT 1");
    $s2->execute([$uuid]);
    $existing = $s2->fetch();
    if ($existing) {
        header('Location: ' . $pay_base . '/student/payments.php?success=1&receipt=' . urlencode($existing['receipt_code']));
    } else {
        header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Payment record not found.'));
    }
    exit;
}

// Optional: verify with eSewa status API for extra certainty
$esewa_status_base = app_config('ESEWA_STATUS_BASE', 'https://rc.esewa.com.np');
$merchant          = app_config('ESEWA_MERCHANT', 'EPAYTEST');
$status_url        = $esewa_status_base . '/api/epay/transaction-tax-bill/status/?product_code='
                   . urlencode($merchant) . '&total_amount=' . urlencode(number_format((float)$payment['amount'], 2, '.', ''))
                   . '&transaction_uuid=' . urlencode($uuid);
try {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $raw = @file_get_contents($status_url, false, $ctx);
    if ($raw) {
        $api_resp = json_decode($raw, true);
        if ($api_resp && isset($api_resp['status']) && strtoupper($api_resp['status']) !== 'COMPLETE') {
            $pdo->prepare("UPDATE payments SET status='failed', gateway_ref=? WHERE id=?")->execute([$gateway_ref, $payment['id']]);
            header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('eSewa status check failed.'));
            exit;
        }
    }
} catch (Exception $e) {
    // Status API unreachable - proceed with signature-verified success
}

// All checks passed - finalize the payment
$receipt_code = generate_receipt_code($payment['billing_month']);

$pdo->beginTransaction();
try {
    // Update payment row
    $pdo->prepare(
        "UPDATE payments SET status='success', gateway_ref=?, receipt_code=?, paid_at=NOW() WHERE id=?"
    )->execute([$gateway_ref, $receipt_code, $payment['id']]);

    // Update monthly_fees row
    $pdo->prepare(
        "UPDATE monthly_fees SET status='paid', paid_payment_id=? WHERE student_id=? AND billing_month=? AND status IN('unpaid','overdue')"
    )->execute([$payment['id'], $payment['student_id'], $payment['billing_month']]);

    // Sync legacy users.fee_status
    $pdo->prepare("UPDATE users SET fee_status='paid' WHERE id=?")->execute([$payment['student_id']]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[HMS] Payment finalization failed: ' . $e->getMessage());
    header('Location: ' . $pay_base . '/student/payments.php?error=' . urlencode('Payment recorded but database update failed. Contact support with code: ' . $uuid));
    exit;
}

// Send receipt email (non-fatal if it fails)
try {
    $stu = $pdo->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
    $stu->execute([$payment['student_id']]);
    $student = $stu->fetch();

    $rm = $pdo->prepare("SELECT r.room_number, r.room_type FROM rooms r WHERE r.id = ? LIMIT 1");
    $rm->execute([$payment['room_id']]);
    $room = $rm->fetch();

    if ($student && !empty($student['email'])) {
        $html = render_receipt_email([
            'name'          => $student['name'],
            'receipt_code'  => $receipt_code,
            'amount'        => $payment['amount'],
            'billing_month' => date('F Y', strtotime($payment['billing_month'])),
            'room'          => 'Room ' . ($room['room_number'] ?? '?') . ' - ' . ($room['room_type'] ?? ''),
            'gateway_ref'   => $gateway_ref,
            'paid_on'       => date('d M Y, h:i A'),
            'gateway'       => 'eSewa',
        ]);
        send_app_mail(
            $student['email'], $student['name'],
            'Payment Receipt - ' . $receipt_code . ' - HMS',
            $html
        );
    }
} catch (Exception $e) {
    error_log('[HMS] Receipt email failed: ' . $e->getMessage());
}

// Redirect to student payments page with success
header('Location: ' . $pay_base . '/student/payments.php?success=1&receipt=' . urlencode($receipt_code));
exit;
