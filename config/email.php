<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Ensure composer autoloader and environment variables are loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

if (empty($_ENV['SMTP_USER']) && empty($_ENV['BREVO_API_KEY'])) {
    if (class_exists('Dotenv\Dotenv')) {
        $envFile = file_exists(dirname(__DIR__) . '/.env.php') && !file_exists(dirname(__DIR__) . '/.env') ? '.env.php' : '.env';
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__), $envFile);
            $dotenv->safeLoad();
        } catch (Throwable $e) {}
    }
}

/**
 * Sends system emails using multi-driver support:
 * 1. Gmail SMTP (PHPMailer) - Immune to Brevo's IP address whitelist locks
 * 2. Brevo API (Fallback)
 * 3. Resend API (Fallback)
 */
function sendSystemEmail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid recipient email address: ' . htmlspecialchars($toEmail)];
    }

    $senderName = 'R. Taipan Hardware';
    $errors = [];

    // Load environment variables with fallback defaults
    $smtpHost   = $_ENV['SMTP_HOST'] ?? (getenv('SMTP_HOST') ?: 'smtp.gmail.com');
    $smtpUser   = $_ENV['SMTP_USER'] ?? (getenv('SMTP_USER') ?: 'renzf2016@gmail.com');
    $smtpPass   = $_ENV['SMTP_PASS'] ?? (getenv('SMTP_PASS') ?: 'xfoxzotxmuohglwo');
    $smtpPort   = (int) ($_ENV['SMTP_PORT'] ?? (getenv('SMTP_PORT') ?: 587));
    $smtpSecure = strtolower(trim((string) ($_ENV['SMTP_SECURE'] ?? (getenv('SMTP_SECURE') ?: 'tls'))));

    // 1. PRIMARY: Try PHPMailer (Gmail SMTP)
    if (!empty($smtpUser) && !empty($smtpPass)) {
        try {
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                if (file_exists(__DIR__ . '/../libs/src/PHPMailer.php')) {
                    require_once __DIR__ . '/../libs/src/Exception.php';
                    require_once __DIR__ . '/../libs/src/PHPMailer.php';
                    require_once __DIR__ . '/../libs/src/SMTP.php';
                }
            }

            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtpUser;
                $mail->Password   = $smtpPass;
                $mail->Port       = $smtpPort;
                $mail->Timeout    = 8;
                
                if ($smtpSecure === 'ssl' || $smtpPort === 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }

                $mail->setFrom($smtpUser, $senderName);
                $mail->addAddress($toEmail, !empty($toName) ? $toName : $toEmail);
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = strip_tags($htmlBody);

                if ($mail->send()) {
                    return ['success' => true, 'error' => ''];
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'SMTP Error: ' . $e->getMessage();
            error_log('PHPMailer SMTP Error: ' . $e->getMessage());
        }
    }

    // 2. FALLBACK 1: Brevo API
    $brevoKey = $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY');
    $brevoSenderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? (getenv('BREVO_SENDER_EMAIL') ?: 'renzf2016@gmail.com');

    if (!empty($brevoKey)) {
        try {
            $url = 'https://api.brevo.com/v3/smtp/email';
            $data = [
                'sender' => [
                    'name'  => $senderName,
                    'email' => $brevoSenderEmail
                ],
                'to' => [
                    [
                        'email' => $toEmail,
                        'name'  => !empty($toName) ? $toName : $toEmail
                    ]
                ],
                'subject'     => $subject,
                'htmlContent' => $htmlBody
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'api-key: ' . $brevoKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'error' => ''];
            } else {
                $json = json_decode((string) $response, true);
                $msg = $json['message'] ?? ($curlErr ?: "Brevo HTTP $httpCode Error");
                $errors[] = 'Brevo Error: ' . $msg;
                error_log("Brevo API Error ($httpCode): " . $response);
            }
        } catch (Throwable $e) {
            $errors[] = 'Brevo Exception: ' . $e->getMessage();
        }
    }

    // 3. FALLBACK 2: Resend API
    $resendKey = $_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY');
    if (!empty($resendKey)) {
        try {
            $url = 'https://api.resend.com/emails';
            $data = [
                'from'    => $senderName . ' <onboarding@resend.dev>',
                'to'      => [$toEmail],
                'subject' => $subject,
                'html'    => $htmlBody
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $resendKey,
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'error' => ''];
            } else {
                $json = json_decode((string) $response, true);
                $msg = $json['message'] ?? "Resend HTTP $httpCode Error";
                $errors[] = 'Resend Error: ' . $msg;
            }
        } catch (Throwable $e) {
            $errors[] = 'Resend Exception: ' . $e->getMessage();
        }
    }

    $finalError = !empty($errors) ? implode(' | ', $errors) : 'No mail providers configured.';
    return ['success' => false, 'error' => $finalError];
}
