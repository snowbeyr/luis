<?php

function sendSystemEmail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $brevoKey = $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY');
    $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? getenv('BREVO_SENDER_EMAIL') ?? 'rtaipancssopc@gmail.com';
    $senderName = 'R. Taipan Hardware';

    // 1. Prioritize Brevo API (Supports sending to any recipient without custom domain)
    if (!empty($brevoKey)) {
        $url = 'https://api.brevo.com/v3/smtp/email';
        $data = [
            'sender' => [
                'name'  => $senderName,
                'email' => $senderEmail
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
            error_log("Brevo API Error ($httpCode): " . $response);
            $json = json_decode((string) $response, true);
            $msg = $json['message'] ?? ($curlErr ?: "Brevo HTTP $httpCode Error");
            return ['success' => false, 'error' => $msg];
        }
    }

    // 2. Fallback to Resend API
    $resendKey = $_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY');
    if (!empty($resendKey)) {
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
            return ['success' => false, 'error' => $msg];
        }
    }

    return ['success' => false, 'error' => 'Mail Configuration Error: BREVO_API_KEY is not defined.'];
}
