<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/config/db.php';

/**
 * Emit a JSON response and terminate execution cleanly.
 */
function ocrRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Normalize Philippine mobile numbers into 09XXXXXXXXX when recognizable.
 */
function ocrNormalizePhone(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $trimmed);
    if ($digits === null || $digits === '') {
        return '';
    }

    if (strlen($digits) === 12 && strpos($digits, '639') === 0) {
        return '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
        return '0' . $digits;
    }
    if (strlen($digits) === 11 && strpos($digits, '09') === 0) {
        return $digits;
    }

    return '';
}

/**
 * Normalize a payment reference number and reject plain mobile numbers.
 */
function ocrNormalizeRefNo(string $raw): string
{
    $withoutDate = preg_replace('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[\s,\.]*\d{0,2}[\s,\.]*\d{2,4}.*$/i', '', trim($raw));
    $cleaned = preg_replace('/[^A-Za-z0-9]/', '', (string) $withoutDate);
    if ($cleaned === null || strlen($cleaned) < 6 || strlen($cleaned) > 35) {
        return '';
    }

    $digitsOnly = preg_replace('/\D+/', '', $cleaned);
    if (
        $digitsOnly === $cleaned &&
        (
            (strlen($digitsOnly) === 11 && strpos($digitsOnly, '09') === 0) ||
            (strlen($digitsOnly) === 12 && strpos($digitsOnly, '639') === 0)
        )
    ) {
        return '';
    }

    return ctype_alpha($cleaned) ? '' : strtoupper($cleaned);
}

/**
 * Normalize extracted currency amount to a positive two-decimal string.
 */
function ocrNormalizeAmount($raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }

    if (is_numeric($raw)) {
        $val = (float) $raw;
        return $val > 0 ? number_format($val, 2, '.', '') : '';
    }

    $str = str_replace([',', 'PHP', 'php', '₱', ' '], '', (string) $raw);
    if (preg_match('/(\d+(?:\.\d{1,2})?)/', $str, $m)) {
        $val = (float) $m[1];
        return $val > 0 ? number_format($val, 2, '.', '') : '';
    }

    return '';
}

/**
 * Normalize extracted receipt date/time into PH timezone (Y-m-d H:i or Y-m-d).
 */
function ocrNormalizeReceiptIso(string $isoRaw, string $dtRaw): string
{
    $tz = new DateTimeZone('Asia/Manila');

    foreach ([$isoRaw, $dtRaw] as $candidate) {
        $val = trim($candidate);
        if ($val === '') {
            continue;
        }

        // Clean ordinal suffixes (e.g. "26th") and extra commas/spaces
        $cleaned = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $val);
        $cleaned = preg_replace('/\s+/', ' ', str_replace(['•', '|'], ' ', (string) $cleaned));

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?$/', $cleaned)) {
            $dt = date_create_immutable($cleaned, $tz);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d H:i');
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleaned)) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $cleaned, $tz);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        $dt = date_create_immutable($cleaned, $tz);
        if ($dt instanceof DateTimeImmutable) {
            $hasTime = (bool) preg_match('/\d{1,2}:\d{2}/', $cleaned);
            return $dt->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d');
        }
    }

    return '';
}

/**
 * Check whether a normalized payment reference number already exists in orders (any status).
 */
function ocrIsDuplicateRefNo(PDO $pdo, string $normalizedRef): bool
{
    if ($normalizedRef === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM orders
         WHERE UPPER(REGEXP_REPLACE(COALESCE(ref_no, ''), '[^A-Za-z0-9]', '', 'g')) = :ref_norm
         LIMIT 1"
    );
    $stmt->execute([':ref_norm' => $normalizedRef]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Send receipt image to a specific Gemini Flash model and decode the JSON body.
 */
function callGeminiReceiptModel(string $model, string $apiKey, string $mimeType, string $base64Data, string $prompt): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

    $requestBody = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data' => $base64Data,
                        ],
                    ],
                ],
            ],
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'temperature' => 0,
        ],
    ]);

    if ($requestBody === false) {
        return ['ok' => false, 'http_code' => 500, 'retryable' => false, 'error' => 'Failed to encode OCR payload.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'http_code' => 0, 'retryable' => true, 'error' => 'Gemini network error: ' . $curlErr];
    }

    $decoded = json_decode($responseBody, true);
    if ($httpCode !== 200 || !is_array($decoded)) {
        $apiMsg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        $retryable = in_array($httpCode, [404, 429, 500, 502, 503, 504], true);
        return ['ok' => false, 'http_code' => $httpCode, 'retryable' => $retryable, 'error' => $apiMsg];
    }

    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        return ['ok' => false, 'http_code' => 502, 'retryable' => true, 'error' => 'Empty response from Gemini model.'];
    }

    // Strip optional markdown code fences just in case the model wraps JSON output
    $cleanJson = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim($text));
    $parsed = json_decode((string) $cleanJson, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'http_code' => 502, 'retryable' => true, 'error' => 'Malformed JSON returned by Gemini model.'];
    }

    return ['ok' => true, 'http_code' => 200, 'data' => $parsed];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ocrRespond(405, ['success' => false, 'fallback' => false, 'error' => 'Method not allowed.']);
}

$rawMode = $_POST['mode'] ?? 'payment';
$mode = in_array($rawMode, ['refund', 'check_ref'], true) ? $rawMode : 'payment';

if ($mode === 'refund') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        ocrRespond(403, ['success' => false, 'fallback' => false, 'error' => 'Admin authorization required.']);
    }
} else {
    $hasCustomerContext = !empty($_SESSION['user_id']) || !empty($_SESSION['cart']) || !empty($_SESSION['buy_now']);
    if (!$hasCustomerContext) {
        ocrRespond(403, ['success' => false, 'fallback' => false, 'error' => 'Active checkout session required.']);
    }
}

if ($mode === 'check_ref') {
    $normRef = ocrNormalizeRefNo((string) ($_POST['ref_no'] ?? ''));
    $isDuplicate = $normRef !== '' ? ocrIsDuplicateRefNo($pdo, $normRef) : false;
    ocrRespond(200, [
        'success' => true,
        'ref_no' => $normRef,
        'is_duplicate_ref' => $isDuplicate,
    ]);
}

if (!function_exists('curl_init')) {
    ocrRespond(503, ['success' => false, 'fallback' => true, 'error' => 'cURL extension is unavailable.']);
}

$apiKey = trim((string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''));
if ($apiKey === '') {
    ocrRespond(503, ['success' => false, 'fallback' => true, 'error' => 'GEMINI_API_KEY is not configured.']);
}

if (empty($_FILES['receipt']) || !isset($_FILES['receipt']['error']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    ocrRespond(400, ['success' => false, 'fallback' => false, 'error' => 'No valid receipt image uploaded.']);
}

$tmpPath = $_FILES['receipt']['tmp_name'];
$fileSize = (int) ($_FILES['receipt']['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > 10 * 1024 * 1024) {
    ocrRespond(400, ['success' => false, 'fallback' => false, 'error' => 'Receipt image must be under 10 MB.']);
}

$mimeType = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $tmpPath);
        if (is_string($detected)) {
            $mimeType = strtolower(trim($detected));
        }
        finfo_close($finfo);
    }
}
if ($mimeType === 'image/jpg') {
    $mimeType = 'image/jpeg';
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
if (!in_array($mimeType, $allowedMimes, true)) {
    ocrRespond(400, ['success' => false, 'fallback' => false, 'error' => 'Unsupported image format.']);
}

$rawImage = file_get_contents($tmpPath);
if ($rawImage === false || $rawImage === '') {
    ocrRespond(400, ['success' => false, 'fallback' => false, 'error' => 'Unable to read uploaded receipt image.']);
}
$base64Data = base64_encode($rawImage);

$prompt = <<<PROMPT
You are a precise Philippine payment and refund receipt OCR extractor (GCash, Maya, InstaPay, PESONet, BPI, BDO, UnionBank, GoTyme, SeaBank, etc.).
Inspect the image carefully and return ONLY a single valid JSON object with these exact keys:
{
  "is_receipt": boolean,
  "provider": string,
  "ref_no": string,
  "amount": string,
  "sent_to": string,
  "receipt_dt": string,
  "receipt_iso": string,
  "raw_text": string
}

Rules:
- "is_receipt": true if this is a payment, transfer, or bank/e-wallet transaction receipt; false for unrelated photos or blank images.
- "provider": wallet or bank name if visible (e.g. "GCash", "Maya", "InstaPay", "Bank Transfer"), else "".
- "ref_no": exact transaction Reference Number / Ref No. / Transaction ID / Trace No. / Confirmation No. with spaces removed (uppercase alphanumeric). NEVER return a mobile phone number (09XXXXXXXXX or +639XXXXXXXXX) as "ref_no". Use "" if not clearly visible.
- "amount": exact primary amount sent or paid as plain numeric string with 2 decimals (e.g. "1250.00"), ignoring transfer fees, convenience fees, discounts, or remaining wallet balance. Use "" if not clearly visible.
- "sent_to": recipient mobile number or recipient account number shown under Sent To / Recipient / Mobile Number / Account Number (if Philippine mobile number, return normalized 11 digits starting with 09 like "09073595023" when unmasked; if partially masked or not visible, keep what is visible or "").
- "receipt_dt": exact date and time string printed on the receipt (e.g. "Nov 26, 2026, 2:15 PM"), else "".
- "receipt_iso": transaction timestamp converted to 24-hour "YYYY-MM-DD HH:MM" (or "YYYY-MM-DD" if time is not shown), else "".
- "raw_text": faithful plain-text transcription of the main readable lines on the receipt separated by newlines.
PROMPT;

$models = [
    'gemini-3.5-flash',
    'gemini-3-flash-preview',
    'gemini-3.7-flash',
    'gemini-3.8-flash',
];

$lastError = 'Gemini OCR request failed.';
$usedModel = '';
$parsedData = null;

foreach ($models as $model) {
    $res = callGeminiReceiptModel($model, $apiKey, $mimeType, $base64Data, $prompt);
    if ($res['ok']) {
        $usedModel = $model;
        $parsedData = $res['data'];
        break;
    }

    $lastError = $res['error'] ?? $lastError;
    if (empty($res['retryable'])) {
        break;
    }
}

if (!is_array($parsedData)) {
    error_log('Gemini OCR fallback triggered (' . $mode . '): ' . $lastError);
    ocrRespond(503, [
        'success' => false,
        'fallback' => true,
        'error' => $lastError,
    ]);
}

$rawText = trim((string) ($parsedData['raw_text'] ?? ''));
$sentToRaw = trim((string) ($parsedData['sent_to'] ?? ''));
$normalizedSentTo = ocrNormalizePhone($sentToRaw);
if ($normalizedSentTo === '' && $rawText !== '') {
    $cleanDigits = preg_replace('/\D+/', '', $rawText);
    if (is_string($cleanDigits)) {
        if (strpos($cleanDigits, '09073595023') !== false || strpos($cleanDigits, '639073595023') !== false) {
            $normalizedSentTo = '09073595023';
        } elseif (strpos($cleanDigits, '09682770376') !== false || strpos($cleanDigits, '639682770376') !== false) {
            $normalizedSentTo = '09682770376';
        }
    }
}

$refNoNorm = ocrNormalizeRefNo((string) ($parsedData['ref_no'] ?? ''));
$receiptDt = trim((string) ($parsedData['receipt_dt'] ?? ''));
$receiptIso = ocrNormalizeReceiptIso(trim((string) ($parsedData['receipt_iso'] ?? '')), $receiptDt);

$outData = [
    'is_receipt' => isset($parsedData['is_receipt']) ? (bool) $parsedData['is_receipt'] : true,
    'provider' => trim((string) ($parsedData['provider'] ?? '')),
    'ref_no' => $refNoNorm,
    'is_duplicate_ref' => ($mode === 'payment' && $refNoNorm !== '') ? ocrIsDuplicateRefNo($pdo, $refNoNorm) : false,
    'amount' => ocrNormalizeAmount($parsedData['amount'] ?? ''),
    'sent_to' => $normalizedSentTo !== '' ? $normalizedSentTo : (preg_replace('/\D+/', '', $sentToRaw) !== '' ? $sentToRaw : ''),
    'receipt_dt' => $receiptDt,
    'receipt_iso' => $receiptIso,
    'raw_text' => $rawText,
];

ocrRespond(200, [
    'success' => true,
    'source' => 'gemini',
    'model' => $usedModel,
    'data' => $outData,
]);
