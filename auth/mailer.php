<?php
// ─────────────────────────────────────────────────
// auth/mailer.php — Outgoing email helpers
//   Wraps PHPMailer (Gmail SMTP) plus three template functions every other
//   page uses to compose emails:
//     • render_branded_email()  — generic gradient-header card layout
//     • render_receipt_email()  — payment receipt with code + line items
//     • send_app_mail()         — actually fires the email and logs result
//   All sends are appended to logs/smtp.log so failures are debuggable.
// ─────────────────────────────────────────────────

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP;

/**
 * Read a config key with sensible fallback chain:
 *   app_config() (config.php) → environment variable → $default.
 */
function mailer_config($key, $default = null) {
    if (function_exists('app_config')) {
        return app_config($key, $default);
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $default;
}

/**
 * Append a single timestamped line to logs/smtp.log. Creates the dir if missing.
 * Used by build_mailer() to surface every SMTP exchange + success/failure.
 */
function mailer_log($msg) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/smtp.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Construct a configured PHPMailer instance ready for ->send().
 *
 * Uses Gmail SMTP with TLS on port 587. Disables peer verification because
 * XAMPP on Windows ships without a CA bundle (Gmail's cert otherwise fails
 * the handshake). Safe for local dev — tighten in production.
 *
 * Throws if SMTP credentials aren't configured in config.php.
 */
function build_mailer() {
    $host      = mailer_config('SMTP_HOST');
    $user      = mailer_config('SMTP_USER');
    $pass      = mailer_config('SMTP_PASS');
    $port      = (int) mailer_config('SMTP_PORT', 587);
    $secure    = mailer_config('SMTP_SECURE', 'tls');
    $fromEmail = mailer_config('SMTP_FROM_EMAIL', $user);
    $fromName  = mailer_config('SMTP_FROM_NAME', 'HMS');

    if (!$host || !$user || !$pass) {
        throw new Exception('SMTP is not configured. Set SMTP_HOST, SMTP_USER and SMTP_PASS in config.php.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->Port       = $port;
    $mail->SMTPSecure = $secure ?: false;
    $mail->Timeout    = 15;

    // XAMPP/Windows usually ships without a trusted CA bundle, so Gmail's TLS
    // handshake fails with "stream_socket_enable_crypto(): SSL operation failed".
    // Allow unverified peer for local development so OTP mail actually leaves the box.
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function ($str, $level) {
        mailer_log('SMTP[' . $level . '] ' . trim($str));
    };

    $mail->setFrom($fromEmail, $fromName);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

/**
 * Generic branded email wrapper. Use this for every transactional email the
 * app sends so they all look like they came from the same product.
 *
 * Required keys in $opts:
 *   - name      Recipient first name / display name
 *   - title     Big headline shown in the gradient header (e.g. "Booking approved")
 *   - intro     Short sentence under the greeting
 *   - body_html Already-escaped HTML for the main message (paragraphs, tables, etc.)
 * Optional:
 *   - kicker    Small uppercase label above the title (e.g. "ATTENDANCE")
 *   - cta_url   If present, renders a button
 *   - cta_label Button label (default: "Open dashboard")
 *   - footnote  Small grey text under the body (e.g. instructions / disclaimer)
 *   - accent    Tailwind-ish hex for header gradient (defaults to brand blue)
 */
function render_branded_email(array $opts): string {
    $name      = htmlspecialchars($opts['name']      ?? 'there');
    $title     = htmlspecialchars($opts['title']     ?? 'HMS Hostel');
    $intro     = htmlspecialchars($opts['intro']     ?? '');
    $kicker    = htmlspecialchars($opts['kicker']    ?? 'HMS Student Hostel');
    $body      = $opts['body_html'] ?? '';
    $cta_url   = $opts['cta_url']   ?? '';
    $cta_label = htmlspecialchars($opts['cta_label'] ?? 'Open dashboard');
    $footnote  = $opts['footnote']  ?? '';
    $accent    = $opts['accent']    ?? '#3b82f6';
    $accent2   = $opts['accent2']   ?? '#1d4ed8';

    $cta_html = '';
    if ($cta_url) {
        $url = htmlspecialchars($cta_url);
        $cta_html = <<<HTML
        <div style="margin:22px 0 6px;">
          <a href="{$url}" style="display:inline-block; background:{$accent}; color:#fff !important; text-decoration:none; padding:12px 22px; border-radius:10px; font-weight:700; font-size:14px;">{$cta_label}</a>
        </div>
HTML;
    }

    $foot_html = $footnote ? '<div style="margin-top:18px; padding:12px 14px; background:#f8fafc; border-left:4px solid '.$accent.'; border-radius:6px; font-size:12.5px; color:#475569; line-height:1.55;">'.$footnote.'</div>' : '';
    $issued = date('d M Y, h:i A');

    return <<<HTML
<div style="font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; padding:24px;">
  <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 6px 20px rgba(15,23,42,.08);">
    <div style="background:linear-gradient(135deg,{$accent},{$accent2}); color:#fff; padding:26px 32px;">
      <div style="font-size:11.5px; letter-spacing:0.2em; opacity:.85; text-transform:uppercase; font-weight:700;">{$kicker}</div>
      <div style="font-size:22px; font-weight:800; margin-top:6px; line-height:1.25;">{$title}</div>
    </div>

    <div style="padding:26px 32px; color:#0f172a; font-size:14.5px; line-height:1.6;">
      <p style="margin:0 0 4px;">Hi <strong>{$name}</strong>,</p>
      <p style="margin:0 0 16px; color:#475569;">{$intro}</p>
      <div style="color:#334155;">{$body}</div>
      {$cta_html}
      {$foot_html}
    </div>

    <div style="padding:14px 32px; background:#0f172a; color:#94a3b8; font-size:11px; text-align:center;">
      Sent {$issued} · HMS Student Portal · This is an automated message — do not reply.
    </div>
  </div>
</div>
HTML;
}

/**
 * Send an HTML email through the configured Gmail SMTP. Logs the result
 * to logs/smtp.log so failures can be diagnosed without re-running the flow.
 *
 * @param string $to        Recipient email address
 * @param string $toName    Display name for the recipient
 * @param string $subject   Subject line
 * @param string $htmlBody  HTML payload (use render_branded_email() for consistency)
 * @param string $altBody   Optional plain-text fallback (auto-stripped from HTML if empty)
 * @return bool             True on success
 * @throws Exception        Wraps any PHPMailer error so callers can catch a single type
 */
/**
 * Payment receipt email. Sent to the student when a payment is confirmed.
 */
function render_receipt_email(array $d): string {
    $name        = htmlspecialchars($d['name']        ?? 'Student');
    $receipt     = htmlspecialchars($d['receipt_code']?? '');
    $amount      = number_format((float)($d['amount']  ?? 0), 0);
    $month       = htmlspecialchars($d['billing_month']?? '');
    $room        = htmlspecialchars($d['room']         ?? '');
    $gateway_ref = htmlspecialchars($d['gateway_ref']  ?? 'N/A');
    $paid_on     = htmlspecialchars($d['paid_on']      ?? date('d M Y'));
    $gateway     = strtoupper($d['gateway'] ?? 'eSewa');

    $body = <<<HTML
<table style="width:100%; border-collapse:collapse; font-size:14px;">
  <tr><td style="padding:8px 0; color:#64748b; width:140px;">Receipt Code</td>
      <td style="padding:8px 0; font-weight:700; font-family:monospace; color:#1e293b;">{$receipt}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:8px 6px; color:#64748b;">Amount Paid</td>
      <td style="padding:8px 6px; font-weight:800; font-size:18px; color:#10b981;">NPR {$amount}</td></tr>
  <tr><td style="padding:8px 0; color:#64748b;">Billing Month</td>
      <td style="padding:8px 0; font-weight:600;">{$month}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:8px 6px; color:#64748b;">Room</td>
      <td style="padding:8px 6px;">{$room}</td></tr>
  <tr><td style="padding:8px 0; color:#64748b;">Gateway</td>
      <td style="padding:8px 0;">{$gateway}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:8px 6px; color:#64748b;">Transaction Ref</td>
      <td style="padding:8px 6px; font-family:monospace; font-size:13px;">{$gateway_ref}</td></tr>
  <tr><td style="padding:8px 0; color:#64748b;">Paid On</td>
      <td style="padding:8px 0;">{$paid_on}</td></tr>
</table>
HTML;

    return render_branded_email([
        'name'      => $name,
        'kicker'    => 'Payment Confirmed',
        'title'     => 'Your payment receipt',
        'intro'     => 'Thank you. Your hostel fee payment has been received and confirmed.',
        'body_html' => $body,
        'footnote'  => 'Keep this receipt code for your records. Contact the hostel office if you have any questions.',
        'accent'    => '#10b981',
        'accent2'   => '#059669',
    ]);
}

/**
 * Fee reminder email. Sent by owner manually or auto on overdue.
 */
function render_fee_reminder_email(array $d): string {
    $name     = htmlspecialchars($d['name']     ?? 'Student');
    $amount   = number_format((float)($d['amount'] ?? 0), 0);
    $month    = htmlspecialchars($d['billing_month'] ?? '');
    $due_date = htmlspecialchars($d['due_date']  ?? '');
    $room     = htmlspecialchars($d['room']      ?? '');
    $pay_url  = htmlspecialchars($d['pay_url']   ?? '');

    $overdue = !empty($d['overdue']);
    $accent  = $overdue ? '#ef4444' : '#f59e0b';
    $accent2 = $overdue ? '#dc2626' : '#d97706';
    $kicker  = $overdue ? 'Overdue Notice' : 'Fee Reminder';
    $title   = $overdue ? 'Your hostel fee is overdue' : 'Your hostel fee is due soon';
    $intro   = $overdue
        ? 'This is a reminder that your hostel fee payment is past its due date.'
        : 'This is a friendly reminder that your hostel fee payment is due.';

    $body = "<table style='width:100%; border-collapse:collapse; font-size:14px;'>"
          . "<tr><td style='padding:8px 0; color:#64748b; width:130px;'>Room</td><td style='padding:8px 0; font-weight:600;'>{$room}</td></tr>"
          . "<tr style='background:#f8fafc;'><td style='padding:8px 6px; color:#64748b;'>Billing Month</td><td style='padding:8px 6px; font-weight:600;'>{$month}</td></tr>"
          . "<tr><td style='padding:8px 0; color:#64748b;'>Amount Due</td><td style='padding:8px 0; font-weight:800; font-size:18px; color:{$accent};'>NPR {$amount}</td></tr>"
          . "<tr style='background:#f8fafc;'><td style='padding:8px 6px; color:#64748b;'>Due Date</td><td style='padding:8px 6px;'>{$due_date}</td></tr>"
          . "</table>";

    return render_branded_email([
        'name'      => $name,
        'kicker'    => $kicker,
        'title'     => $title,
        'intro'     => $intro,
        'body_html' => $body,
        'cta_url'   => $pay_url,
        'cta_label' => 'Pay Now via eSewa',
        'footnote'  => 'If you have already made the payment, please ignore this notice. Contact the hostel office if you need help.',
        'accent'    => $accent,
        'accent2'   => $accent2,
    ]);
}

/**
 * Fee generated email. Sent when a new monthly fee row is created for the student.
 */
function render_fee_generated_email(array $d): string {
    $name     = htmlspecialchars($d['name']     ?? 'Student');
    $amount   = number_format((float)($d['amount'] ?? 0), 0);
    $month    = htmlspecialchars($d['billing_month'] ?? '');
    $due_date = htmlspecialchars($d['due_date']  ?? '');
    $room     = htmlspecialchars($d['room']      ?? '');
    $pay_url  = htmlspecialchars($d['pay_url']   ?? '');

    $body = "<table style='width:100%; border-collapse:collapse; font-size:14px;'>"
          . "<tr><td style='padding:8px 0; color:#64748b; width:130px;'>Room</td><td style='padding:8px 0; font-weight:600;'>{$room}</td></tr>"
          . "<tr style='background:#f8fafc;'><td style='padding:8px 6px; color:#64748b;'>Billing Month</td><td style='padding:8px 6px; font-weight:600;'>{$month}</td></tr>"
          . "<tr><td style='padding:8px 0; color:#64748b;'>Amount Due</td><td style='padding:8px 0; font-weight:800; font-size:18px; color:#6366f1;'>NPR {$amount}</td></tr>"
          . "<tr style='background:#f8fafc;'><td style='padding:8px 6px; color:#64748b;'>Due Date</td><td style='padding:8px 6px;'>{$due_date}</td></tr>"
          . "</table>";

    return render_branded_email([
        'name'      => $name,
        'kicker'    => 'Monthly Fee',
        'title'     => 'Your fee for ' . $month . ' is ready',
        'intro'     => 'Your monthly hostel fee has been generated. Please pay by the due date to avoid late charges.',
        'body_html' => $body,
        'cta_url'   => $pay_url,
        'cta_label' => 'Pay Now via eSewa',
        'footnote'  => 'Contact the hostel office if you believe there is an error in the amount.',
        'accent'    => '#6366f1',
        'accent2'   => '#4f46e5',
    ]);
}

function send_app_mail($to, $toName, $subject, $htmlBody, $altBody = '') {
    try {
        $mail = build_mailer();
        $mail->addAddress($to, $toName ?: '');
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        if (!$mail->send()) {
            $err = $mail->ErrorInfo ?: 'Unknown SMTP error';
            mailer_log("FAIL to=$to subject=\"$subject\" :: $err");
            throw new Exception('Mailer error: ' . $err);
        }
        mailer_log("OK   to=$to subject=\"$subject\"");
        return true;
    } catch (PHPMailerException $ex) {
        mailer_log("EXC  to=$to subject=\"$subject\" :: " . $ex->getMessage());
        throw new Exception($ex->getMessage(), (int) $ex->getCode());
    }
}
