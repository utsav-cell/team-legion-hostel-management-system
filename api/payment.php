<?php
// api/payment.php - eSewa v2 payment initiation
// Accepts POST: action=esewa_init, monthly_fee_id, csrf_token
// Creates a pending payments row and renders an auto-submitting form to eSewa.

require_once __DIR__ . '/../db.php';

$auth = get_auth();
if (!$auth) { http_response_code(401); die('Not authenticated.'); }
if ($auth['role'] === 'warden') { http_response_code(403); die('Wardens cannot access the payment system.'); }

$mode = $_GET['mode'] ?? ($_POST['action'] ?? '');

if ($mode === 'esewa_init' || isset($_POST['action']) && $_POST['action'] === 'esewa_init') {
    csrf_verify($_POST['csrf_token'] ?? '');

    if ($auth['role'] !== 'student') {
        http_response_code(403); die('Only students can initiate payments.');
    }

    $student_id    = (int)$auth['id'];
    $monthly_fee_id = (int)($_POST['monthly_fee_id'] ?? 0);
    if ($monthly_fee_id < 1) {
        header('Location: ../student/payments.php?error=' . urlencode('Invalid fee reference.'));
        exit;
    }

    // Load the fee and verify it belongs to this student
    $stmt = $pdo->prepare(
        "SELECT mf.*, r.room_number, r.room_type
         FROM monthly_fees mf
         JOIN rooms r ON r.id = mf.room_id
         WHERE mf.id = ? AND mf.student_id = ? AND mf.status IN ('unpaid','overdue')
         LIMIT 1"
    );
    $stmt->execute([$monthly_fee_id, $student_id]);
    $fee = $stmt->fetch();

    if (!$fee) {
        header('Location: ../student/payments.php?error=' . urlencode('Fee not found or already paid.'));
        exit;
    }

    // Check for an existing pending payment for this fee (avoid duplicates)
    $dup = $pdo->prepare(
        "SELECT id FROM payments
         WHERE student_id = ? AND billing_month = ? AND status = 'pending'
         ORDER BY initiated_at DESC LIMIT 1"
    );
    $dup->execute([$student_id, $fee['billing_month']]);
    $existing = $dup->fetch();

    // Cancel any previous pending attempts - eSewa rejects reused UUIDs
    if ($existing) {
        $pdo->prepare("UPDATE payments SET status='cancelled' WHERE student_id=? AND billing_month=? AND status='pending'")
            ->execute([$student_id, $fee['billing_month']]);
    }

    // Always generate a fresh UUID for each attempt
    $uuid = 'HMS-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));

    $ins = $pdo->prepare(
        "INSERT INTO payments (student_id, room_id, amount, billing_month, status, gateway, transaction_uuid, initiated_at)
         VALUES (?, ?, ?, ?, 'pending', 'esewa', ?, NOW())"
    );
    $ins->execute([$student_id, $fee['room_id'], $fee['amount'], $fee['billing_month'], $uuid]);
    $payment = [
        'id'               => $pdo->lastInsertId(),
        'transaction_uuid' => $uuid,
        'amount'           => $fee['amount'],
    ];

    $amount        = number_format((float)$fee['amount'], 2, '.', '');
    $uuid          = $payment['transaction_uuid'];
    $merchant_code = app_config('ESEWA_MERCHANT', 'EPAYTEST');
    $esewa_base    = app_config('ESEWA_BASE', 'https://rc-epay.esewa.com.np');
    $pay_base      = app_config('PAYMENT_BASE_URL', 'http://localhost/team-legion-hostel-management-system');

    $sign_string = "total_amount={$amount},transaction_uuid={$uuid},product_code={$merchant_code}";
    $signature   = esewa_sign($sign_string);

    $pid         = (int)$payment['id'];
    // Success URL must have NO query params - eSewa appends ?data=BASE64 directly.
    // A second ? in the URL causes PHP to lose the data parameter entirely.
    $success_url = $pay_base . '/api/payment_callback.php';
    $failure_url = $pay_base . '/api/payment_callback.php?result=failure&pid=' . $pid;
    $form_url    = $esewa_base . '/api/epay/main/v2/form';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to eSewa - HMS</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f6f8fb;
               display: flex; align-items: center; justify-content: center;
               min-height: 100vh; margin: 0; color: #0f172a; }
        .box { background: #fff; border: 1px solid rgba(15,23,42,0.08);
               border-radius: 14px; padding: 2.5rem 2rem; text-align: center;
               max-width: 400px; box-shadow: 0 4px 20px rgba(15,23,42,0.08); }
        .spinner { width: 36px; height: 36px; border: 3px solid #eef2ff;
                   border-top-color: #6366f1; border-radius: 50%;
                   animation: spin .8s linear infinite; margin: 0 auto 1.25rem; }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { color: #64748b; font-size: 0.9rem; margin: 0 0 0.5rem; }
        small { color: #94a3b8; font-size: 0.78rem; }
    </style>
</head>
<body>
<div class="box">
    <div class="spinner"></div>
    <p>Redirecting to eSewa</p>
    <p style="font-weight:700;">NPR <?= number_format((float)$fee['amount'], 0) ?></p>
    <small>Please do not press Back or refresh.</small>
    <form id="esewa-form" method="POST" action="<?= htmlspecialchars($form_url) ?>" style="display:none;">
        <input type="hidden" name="amount"                    value="<?= $amount ?>">
        <input type="hidden" name="tax_amount"                value="0">
        <input type="hidden" name="total_amount"              value="<?= $amount ?>">
        <input type="hidden" name="transaction_uuid"          value="<?= htmlspecialchars($uuid) ?>">
        <input type="hidden" name="product_code"              value="<?= htmlspecialchars($merchant_code) ?>">
        <input type="hidden" name="product_service_charge"    value="0">
        <input type="hidden" name="product_delivery_charge"   value="0">
        <input type="hidden" name="success_url"               value="<?= htmlspecialchars($success_url) ?>">
        <input type="hidden" name="failure_url"               value="<?= htmlspecialchars($failure_url) ?>">
        <input type="hidden" name="signed_field_names"        value="total_amount,transaction_uuid,product_code">
        <input type="hidden" name="signature"                 value="<?= htmlspecialchars($signature) ?>">
    </form>
</div>
<script>
    setTimeout(function() { document.getElementById('esewa-form').submit(); }, 800);
</script>
</body>
</html>
<?php
    exit;
}

// Unknown mode
http_response_code(400);
die('Unknown payment mode.');
