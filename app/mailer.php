<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function send_verification_email(array $config, string $recipient, string $code): void
{
    $mailConfig = $config['mail'] ?? [];
    foreach (['username', 'app_password', 'from'] as $key) {
        if (empty($mailConfig[$key])) {
            throw new RuntimeException('Email delivery is not configured. Add Gmail SMTP settings to config/config.php.');
        }
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer is not installed. Run composer install in the website folder.');
    }
    require_once $autoload;

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = (string)$mailConfig['username'];
    $mail->Password = str_replace(' ', '', (string)$mailConfig['app_password']);
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 15;
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->setFrom((string)$mailConfig['from'], (string)($mailConfig['from_name'] ?? 'Z.C. commonplace'));
    $mail->addAddress($recipient);
    $mail->isHTML(false);
    $mail->Subject = 'Your Z.C. commonplace verification code';
    $mail->Body = "Your 6-digit email verification code is: {$code}\n\nThis code expires in 10 minutes. If you did not create this account, you can ignore this email.";
    try {
        $mail->send();
    } catch (Throwable $error) {
        // Preserve the SMTP response for troubleshooting while redacting credentials.
        $smtpDetail = trim((string)$mail->ErrorInfo);
        $exceptionDetail = trim($error->getMessage());
        $detail = $smtpDetail !== '' ? $smtpDetail : $exceptionDetail;
        $detail = str_ireplace(
            [(string)$mailConfig['app_password'], (string)$mailConfig['username']],
            ['[REDACTED]', '[SENDER EMAIL]'],
            $detail
        );

        if (stripos($exceptionDetail, 'could not authenticate') !== false ||
            stripos($exceptionDetail, 'authentication') !== false ||
            stripos($detail, '535') !== false ||
            stripos($detail, '5.7.8') !== false) {
            throw new RuntimeException(
                'Gmail SMTP authentication failed. Verify mail.username is the full sending Gmail address and mail.app_password is a current Google App Password for that same account. SMTP detail: ' . $detail,
                0,
                $error
            );
        }

        throw new RuntimeException('Could not send verification email. SMTP detail: ' . $detail, 0, $error);
    }
}
