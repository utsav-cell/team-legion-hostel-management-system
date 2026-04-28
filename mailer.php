<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

function build_mailer() {
    $host = mailer_config('SMTP_HOST');
    $user = mailer_config('SMTP_USER');
    $pass = mailer_config('SMTP_PASS');
    $port = (int) mailer_config('SMTP_PORT', 587);
    $secure = mailer_config('SMTP_SECURE', 'tls');
    $fromEmail = mailer_config('SMTP_FROM_EMAIL', $user);
    $fromName = mailer_config('SMTP_FROM_NAME', 'HMS');

    if (!$host || !$user || !$pass) {
        throw new Exception('SMTP is not configured.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $pass;
    $mail->Port = $port;
    $mail->SMTPSecure = $secure;
    $mail->setFrom($fromEmail, $fromName);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

function send_app_mail($to, $toName, $subject, $htmlBody, $altBody = '') {
    $mail = build_mailer();
    $mail->addAddress($to, $toName ?: '');
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody ?: strip_tags($htmlBody);
    return $mail->send();
}
