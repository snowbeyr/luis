<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_drive.php';
require_once __DIR__ . '/../config/image.php';
require_once __DIR__ . '/../includes/shared_inventory_functions.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

// Helper function para sa Checkout Item Image resolution
function getCheckoutItemImageSrc($image_value) {
    return getProductImageSrc($image_value);
}

// Ensure customer_addresses table exists in PostgreSQL
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_addresses (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            contact_number VARCHAR(20) NOT NULL,
            tel_no VARCHAR(50),
            email VARCHAR(150) NOT NULL,
            full_address TEXT NOT NULL,
            landmark VARCHAR(255) NOT NULL,
            is_default BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_customer_addresses_user_id ON customer_addresses(user_id);
    ");
} catch (PDOException $e) {
    error_log("customer_addresses check notice: " . $e->getMessage());
}

$loggedInUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$loggedInUser = null;
$saved_addresses = [];
$customerEmail = '';

if ($loggedInUserId > 0) {
    try {
        $u_stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $u_stmt->execute([':id' => $loggedInUserId]);
        $loggedInUser = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($loggedInUser) {
            $customerEmail = trim((string) ($loggedInUser['email'] ?? ''));
        }
    } catch (PDOException $e) {
        error_log("Load user error in placeorder: " . $e->getMessage());
    }

    try {
        $addr_stmt = $pdo->prepare("SELECT * FROM customer_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC");
        $addr_stmt->execute([':uid' => $loggedInUserId]);
        $saved_addresses = $addr_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Load saved addresses error: " . $e->getMessage());
    }
}

if ($customerEmail === '' && !empty($_SESSION['email'])) {
    $customerEmail = trim((string) $_SESSION['email']);
}

$checkout_source = isset($_GET['from']) ? $_GET['from'] : 'cart';
$draft_scope = ($checkout_source === 'buynow') ? 'buynow' : 'cart';
$checkout_qs = ($checkout_source === 'buynow') ? '?from=buynow' : '';
$checkout_review_qs = ($checkout_source === 'buynow') ? '?from=buynow&step=review' : '?step=review';

$raw_items_to_process = [];
if ($checkout_source == 'buynow' && isset($_SESSION['buy_now']) && !empty($_SESSION['buy_now'])) {
    $raw_items_to_process = $_SESSION['buy_now'];
} elseif (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $raw_items_to_process = $_SESSION['cart'];
} else {
    header("Location: dashboard.php");
    exit();
}

// Re-hydrate live product/variant details, units, prices, and stock directly from DB
$liveHydration = hydrateCheckoutItemsWithLiveDb($pdo, $raw_items_to_process, false);
$items_to_process = $liveHydration['items'];
$grand_total = (float)$liveHydration['grand_total'];
$total_qty = (int)$liveHydration['total_qty'];
$checkout_issues = $liveHydration['issues'];
$price_changes = $liveHydration['price_changes'];

if (!isset($_SESSION['checkout_draft']) || !is_array($_SESSION['checkout_draft'])) {
    $_SESSION['checkout_draft'] = [];
}
if (!isset($_SESSION['checkout_consumed_tokens']) || !is_array($_SESSION['checkout_consumed_tokens'])) {
    $_SESSION['checkout_consumed_tokens'] = [];
}

/**
 * Normalizes a Philippine mobile number for official recipient verification.
 */
function normalizeCheckoutPhoneDigits(string $raw): string {
    $digits = (string)preg_replace('/\D+/', '', $raw);
    if (strlen($digits) === 12 && strpos($digits, '639') === 0) {
        return '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
        return '0' . $digits;
    }
    return $digits;
}

/**
 * Verifies whether a recipient string matches the expected official number either in full
 * (e.g. 09073595023) or as a masked e-wallet receipt number ending in its last 4 digits
 * (e.g. "09......5023" or "+63 9•• ••• 5023"). Returns normalized official number on match, else ''.
 */
function resolveMatchedCheckoutRecipient(string $rawSentTo, string $expectedRecipient): string {
    $trimmed = trim($rawSentTo);
    if ($trimmed === '' || $expectedRecipient === '') {
        return '';
    }

    $normalizedFull = normalizeCheckoutPhoneDigits($trimmed);
    if ($normalizedFull === $expectedRecipient) {
        return $expectedRecipient;
    }

    // If an unmasked 10/11/12-digit mobile number was detected and differs, reject
    if (preg_match('/^(?:09\d{9}|639\d{9}|9\d{9})$/', (string)preg_replace('/\D+/', '', $trimmed))) {
        return '';
    }

    $last4 = substr($expectedRecipient, -4);
    $digitsOnly = (string)preg_replace('/\D+/', '', $trimmed);

    // Match masked e-wallet strings whose visible digits end in the expected last 4 (e.g., 5023)
    if (
        strlen($digitsOnly) >= 4 &&
        strlen($digitsOnly) <= 9 &&
        substr($digitsOnly, -4) === $last4
    ) {
        $prefixDigits = substr($digitsOnly, 0, -4);
        $validPrefix = ($prefixDigits === '' || $prefixDigits === '09' || $prefixDigits === '639' || $prefixDigits === '9'
            || strpos($expectedRecipient, $prefixDigits) === 0);
        if ($validPrefix) {
            return $expectedRecipient;
        }
    }

    return '';
}

/**
 * Shared validation for payment method, scheme, OCR fields, and receipt screenshot.
 * Used identically on both Step 1 (Review staging) and Step 2 (Final confirmation).
 */
function validateCheckoutPaymentDetails(
    PDO $pdo,
    string $payment_method,
    string $payment_scheme,
    float $grand_total,
    array $meta,
    ?string $stagedProofPath,
    ?array $uploadedFile,
    bool $stageNewUploadToTmp = false
): array {
    $allowedMethods = ['Cash on Delivery (COD)', 'GCash', 'Maya'];
    if (!in_array($payment_method, $allowedMethods, true)) {
        return ['ok' => false, 'title' => 'Payment Method Required', 'message' => 'Please select a valid mode of payment.'];
    }

    if ($grand_total >= 5000 && $payment_method === 'Cash on Delivery (COD)') {
        return [
            'ok' => false,
            'title' => 'Payment Method Unavailable',
            'message' => 'Cash on Delivery (COD) is unavailable for orders ₱5,000.00 and above. Please choose GCash or Maya.',
            'modal_type' => 'warning'
        ];
    }

    if ($grand_total >= 5000) {
        if (!in_array($payment_scheme, ['50_percent', 'full_payment'], true)) {
            $payment_scheme = '50_percent';
        }
    } else {
        $payment_scheme = 'full_payment';
    }

    if (!in_array($payment_method, ['GCash', 'Maya'], true)) {
        return [
            'ok' => true,
            'payment_method' => $payment_method,
            'payment_scheme' => $payment_scheme,
            'paid_amount' => 0.0,
            'ref_no' => null,
            'receiptdt' => null,
            'receipt_iso' => '',
            'sent_to' => null,
            'temp_proof_path' => $stagedProofPath
        ];
    }

    // 1. Verify receipt image (either fresh upload or safely staged temp upload)
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
    $hasFreshUpload = is_array($uploadedFile)
        && !empty($uploadedFile['name'])
        && isset($uploadedFile['error'])
        && (int)$uploadedFile['error'] === UPLOAD_ERR_OK
        && !empty($uploadedFile['tmp_name'])
        && file_exists($uploadedFile['tmp_name']);

    $resolvedTempProof = null;
    if ($hasFreshUpload) {
        $file_mime_check = mime_content_type($uploadedFile['tmp_name']);
        if (!in_array($file_mime_check, $allowed_types, true)) {
            return ['ok' => false, 'title' => 'Invalid File Format', 'message' => 'Please upload a valid receipt image (JPEG, PNG, or WebP).'];
        }
        if ($stageNewUploadToTmp) {
            $tmpDir = __DIR__ . '/../uploads/proofs/tmp/';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }
            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $ext = 'jpg';
            }
            $stagedFilename = 'staged_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $tmpDir . $stagedFilename;
            if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                return ['ok' => false, 'title' => 'Upload Error', 'message' => 'Unable to process your payment screenshot right now. Please try again.'];
            }
            if (!empty($stagedProofPath) && strpos($stagedProofPath, 'uploads/proofs/tmp/') === 0) {
                $oldAbs = __DIR__ . '/../uploads/proofs/tmp/' . basename($stagedProofPath);
                if (file_exists($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $resolvedTempProof = 'uploads/proofs/tmp/' . $stagedFilename;
        }
    } elseif (!empty($stagedProofPath) && strpos($stagedProofPath, 'uploads/proofs/tmp/') === 0) {
        $stagedAbs = __DIR__ . '/../uploads/proofs/tmp/' . basename($stagedProofPath);
        if (file_exists($stagedAbs)) {
            $file_mime_check = mime_content_type($stagedAbs);
            if (in_array($file_mime_check, $allowed_types, true)) {
                $resolvedTempProof = 'uploads/proofs/tmp/' . basename($stagedProofPath);
            }
        }
    }

    if ($resolvedTempProof === null && $stageNewUploadToTmp) {
        return ['ok' => false, 'title' => 'Payment Proof Required', 'message' => 'Please upload your proof of payment receipt screenshot before proceeding.'];
    }
    if ($resolvedTempProof === null && !$stageNewUploadToTmp) {
        return ['ok' => false, 'title' => 'Payment Proof Required', 'message' => 'Your uploaded payment receipt could not be found. Please go back and upload your receipt again.'];
    }

    // 2. Enforce exact payment amount
    $expected_amount = ($grand_total >= 5000 && $payment_scheme === '50_percent')
        ? round($grand_total * 0.5, 2)
        : round($grand_total, 2);
    $detected_paid_amount = isset($meta['paid_amount']) ? (float)$meta['paid_amount'] : 0.0;

    if ($detected_paid_amount <= 0 || abs($detected_paid_amount - $expected_amount) > 0.05) {
        return [
            'ok' => false,
            'title' => 'Invalid Payment Amount',
            'message' => 'The receipt payment amount must exactly match the required amount of ₱' . number_format($expected_amount, 2) . '. Underpayments and overpayments cannot proceed.'
        ];
    }

    // 3. Enforce recipient number matching selected payment method (supports full & masked e-wallet numbers ending in last 4 digits)
    $expected_recipient = ($payment_method === 'GCash') ? '09073595023' : '09682770376';
    $matched_recipient = resolveMatchedCheckoutRecipient((string)($meta['sent_to'] ?? ''), $expected_recipient);
    if ($matched_recipient === '') {
        return [
            'ok' => false,
            'title' => 'Incorrect Recipient Number',
            'message' => 'You selected ' . $payment_method . '. Payment must be sent strictly to our official ' . $payment_method . ' number (' . $expected_recipient . ' / ending in ' . substr($expected_recipient, -4) . ').'
        ];
    }
    $sent_digits = $matched_recipient;

    // 4. Enforce receipt date/time is within today (Asia/Manila) and not future-dated
    $manilaTz = new DateTimeZone('Asia/Manila');
    $nowManila = new DateTimeImmutable('now', $manilaTz);
    $parsedReceiptDt = null;
    $receiptHasTime = false;
    $receiptdt = !empty($meta['receiptdt']) ? trim((string)$meta['receiptdt']) : null;
    $receipt_iso_post = !empty($meta['receipt_iso']) ? trim((string)$meta['receipt_iso']) : '';

    foreach ([$receipt_iso_post, (string)($receiptdt ?? '')] as $dtCandidate) {
        $dtCandidate = trim($dtCandidate);
        if ($dtCandidate === '') {
            continue;
        }
        $cleanedDt = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $dtCandidate);
        $cleanedDt = preg_replace('/\s+/', ' ', str_replace(['•', '|'], ' ', (string)$cleanedDt));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanedDt)) {
            $tmpDt = DateTimeImmutable::createFromFormat('!Y-m-d', $cleanedDt, $manilaTz);
            if ($tmpDt instanceof DateTimeImmutable) {
                $parsedReceiptDt = $tmpDt;
                $receiptHasTime = false;
                break;
            }
        }
        $tmpDt = date_create_immutable($cleanedDt, $manilaTz);
        if ($tmpDt instanceof DateTimeImmutable) {
            $parsedReceiptDt = $tmpDt;
            $receiptHasTime = (bool)preg_match('/\d{1,2}:\d{2}/', $cleanedDt);
            break;
        }
    }

    if (
        !($parsedReceiptDt instanceof DateTimeImmutable) ||
        $parsedReceiptDt->format('Y-m-d') !== $nowManila->format('Y-m-d') ||
        ($receiptHasTime && $parsedReceiptDt->getTimestamp() > ($nowManila->getTimestamp() + 300))
    ) {
        return [
            'ok' => false,
            'title' => 'Invalid Receipt Date & Time',
            'message' => 'Your payment receipt must show today\'s date (' . $nowManila->format('M d, Y') . ') in Philippine time and must not be an older or future-dated transaction.'
        ];
    }

    // 5. Enforce valid & unique reference number
    $ref_raw = !empty($meta['ref_no']) ? trim((string)$meta['ref_no']) : '';
    $ref_norm = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $ref_raw));
    $ref_no = $ref_norm !== '' ? substr($ref_norm, 0, 50) : null;
    $ref_digits_only = (string)preg_replace('/\D+/', '', $ref_norm);
    $is_phone_ref = ($ref_digits_only === $ref_norm) && (
        (strlen($ref_digits_only) === 11 && strpos($ref_digits_only, '09') === 0) ||
        (strlen($ref_digits_only) === 12 && strpos($ref_digits_only, '639') === 0)
    );
    if ($ref_norm === '' || strlen($ref_norm) < 6 || ctype_alpha($ref_norm) || $is_phone_ref) {
        return [
            'ok' => false,
            'title' => 'Missing Reference Number',
            'message' => 'A valid transaction reference number could not be verified from your receipt. Please upload a clearer receipt screenshot.'
        ];
    }

    try {
        $dupStmt = $pdo->prepare(
            "SELECT 1 FROM orders
             WHERE UPPER(REGEXP_REPLACE(COALESCE(ref_no, ''), '[^A-Za-z0-9]', '', 'g')) = :ref_norm
             LIMIT 1"
        );
        $dupStmt->execute([':ref_norm' => $ref_norm]);
        if ($dupStmt->fetchColumn()) {
            return [
                'ok' => false,
                'title' => 'Duplicate Reference Number',
                'message' => 'This reference number has already been used in a previous order. Please upload a new valid payment receipt.'
            ];
        }
    } catch (PDOException $e) {
        error_log("Duplicate ref check error: " . $e->getMessage());
        return [
            'ok' => false,
            'title' => 'Verification Error',
            'message' => 'Unable to verify your payment reference number right now. Please try again.'
        ];
    }

    return [
        'ok' => true,
        'payment_method' => $payment_method,
        'payment_scheme' => $payment_scheme,
        'paid_amount' => $detected_paid_amount,
        'ref_no' => $ref_no,
        'receiptdt' => $receiptdt,
        'receipt_iso' => $receipt_iso_post,
        'sent_to' => $sent_digits,
        'temp_proof_path' => $resolvedTempProof
    ];
}

/**
 * Helper to resolve effective customer email.
 */
function resolveCheckoutCustomerEmail(PDO $pdo, int $loggedInUserId, string $customerEmail, string $postedEmail = ''): string {
    $email = $customerEmail !== '' ? $customerEmail : trim($postedEmail);
    if ($email === '' && $loggedInUserId > 0) {
        try {
            $stmt_u = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $stmt_u->execute([$loggedInUserId]);
            $email = trim((string)$stmt_u->fetchColumn());
        } catch (Throwable $e) {}
    }
    if ($email === '' && !empty($_SESSION['email'])) {
        $email = trim((string)$_SESSION['email']);
    }
    return $email;
}

// -------------------------------------------------------------------------
// STEP 1 HANDLER: PLACE ORDER -> VALIDATE & STAGE FOR FLOATING REVIEW (NO DB WRITE)
// -------------------------------------------------------------------------
if (isset($_POST['review_order']) || isset($_POST['review_ajax'])) {
    $isAjaxReview = !empty($_POST['review_ajax']);
    $existingDraft = $_SESSION['checkout_draft'][$draft_scope] ?? [];

    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $surname = trim((string)($_POST['surname'] ?? ''));
    $contact_number = trim((string)($_POST['contact_number'] ?? ''));
    $tel_no = !empty($_POST['tel_no']) ? trim((string)$_POST['tel_no']) : null;
    $email = resolveCheckoutCustomerEmail($pdo, $loggedInUserId, $customerEmail, (string)($_POST['email'] ?? ''));
    $full_address = trim((string)($_POST['full_address'] ?? ''));
    $landmark = trim((string)($_POST['landmark'] ?? ''));
    $saved_address_id = !empty($_POST['saved_address_id']) ? (int)$_POST['saved_address_id'] : null;
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $payment_scheme = trim((string)($_POST['payment_scheme'] ?? 'full_payment'));

    $paymentMeta = [
        'ref_no' => $_POST['ref_no'] ?? ($existingDraft['ref_no'] ?? ''),
        'paid_amount' => $_POST['paid_amount'] ?? ($existingDraft['paid_amount'] ?? ''),
        'receiptdt' => $_POST['receiptdt'] ?? ($existingDraft['receiptdt'] ?? ''),
        'receipt_iso' => $_POST['receipt_iso'] ?? ($existingDraft['receipt_iso'] ?? ''),
        'sent_to' => $_POST['sent_to'] ?? ($existingDraft['sent_to'] ?? ''),
    ];

    // Save entered form fields immediately so any error or Back-to-Edit preserves customer edits
    $_SESSION['checkout_draft'][$draft_scope] = [
        'ready_for_review' => false,
        'saved_address_id' => $saved_address_id,
        'fullname' => $fullname,
        'surname' => $surname,
        'contact_number' => $contact_number,
        'tel_no' => $tel_no,
        'email' => $email,
        'full_address' => $full_address,
        'landmark' => $landmark,
        'payment_method' => $payment_method,
        'payment_scheme' => $payment_scheme,
        'ref_no' => trim((string)$paymentMeta['ref_no']),
        'paid_amount' => trim((string)$paymentMeta['paid_amount']),
        'receiptdt' => trim((string)$paymentMeta['receiptdt']),
        'receipt_iso' => trim((string)$paymentMeta['receipt_iso']),
        'sent_to' => trim((string)$paymentMeta['sent_to']),
        'temp_proof_path' => $existingDraft['temp_proof_path'] ?? null,
        'terms_accepted' => true,
        'confirm_token' => $existingDraft['confirm_token'] ?? bin2hex(random_bytes(32)),
    ];

    $respondReviewError = function (string $title, string $message, string $type = 'danger') use ($isAjaxReview, $checkout_qs): void {
        if ($isAjaxReview) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => false,
                'title' => $title,
                'message' => $message,
                'type' => $type,
            ]);
            exit();
        }
        $_SESSION['app_modal'] = [
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ];
        header("Location: placeorder.php" . $checkout_qs);
        exit();
    };

    if ($checkout_source !== 'buynow' && $grand_total < 3000) {
        $respondReviewError('Minimum Order Not Met', 'Minimum order requirement of ₱3,000.00 is required for delivery checkout. Please add more items in your cart.', 'warning');
    }

    if (!$liveHydration['can_checkout']) {
        $respondReviewError('Stock or Item Unavailable', $checkout_issues[0] ?? 'Some items in your cart are unavailable or exceed available stock. Please update your cart.');
    }

    if ($fullname === '' || $surname === '' || $full_address === '' || $landmark === '') {
        $respondReviewError('Incomplete Information', 'Please complete all required customer and delivery address fields.', 'warning');
    }

    if (!preg_match('/^09\d{9}$/', $contact_number)) {
        $respondReviewError('Invalid Contact Number', "Contact number must start with '09' and contain 11 digits total.", 'warning');
    }

    $payCheck = validateCheckoutPaymentDetails(
        $pdo,
        $payment_method,
        $payment_scheme,
        $grand_total,
        $paymentMeta,
        $existingDraft['temp_proof_path'] ?? null,
        $_FILES['proof_of_payment'] ?? null,
        true
    );

    if (!$payCheck['ok']) {
        $respondReviewError($payCheck['title'], $payCheck['message'], $payCheck['modal_type'] ?? 'danger');
    }

    $confirmToken = bin2hex(random_bytes(32));

    // Stage fully validated draft for Review modal & final submission
    $_SESSION['checkout_draft'][$draft_scope] = [
        'ready_for_review' => true,
        'saved_address_id' => $saved_address_id,
        'fullname' => $fullname,
        'surname' => $surname,
        'contact_number' => $contact_number,
        'tel_no' => $tel_no,
        'email' => $email,
        'full_address' => $full_address,
        'landmark' => $landmark,
        'payment_method' => $payCheck['payment_method'],
        'payment_scheme' => $payCheck['payment_scheme'],
        'paid_amount' => $payCheck['paid_amount'],
        'ref_no' => $payCheck['ref_no'],
        'receiptdt' => $payCheck['receiptdt'],
        'receipt_iso' => $payCheck['receipt_iso'],
        'sent_to' => $payCheck['sent_to'],
        'temp_proof_path' => $payCheck['temp_proof_path'],
        'terms_accepted' => true,
        'confirm_token' => $confirmToken,
        'staged_at' => time(),
    ];

    if ($isAjaxReview) {
        $schemeLabel = ($grand_total >= 5000 && $payCheck['payment_scheme'] === '50_percent')
            ? '50% Downpayment'
            : 'Full Payment';
        $amountDueNow = ($grand_total >= 5000 && $payCheck['payment_scheme'] === '50_percent')
            ? round($grand_total * 0.5, 2)
            : round($grand_total, 2);

        $reviewItemsPayload = [];
        foreach ($items_to_process as $it) {
            $reviewItemsPayload[] = [
                'id' => (int)$it['id'],
                'product_title' => (string)$it['product_title'],
                'variant_name' => $it['variant_name'],
                'unit' => (string)$it['unit'],
                'qty' => (int)$it['qty'],
                'price' => (float)$it['price'],
                'subtotal' => (float)$it['subtotal'],
                'available_stock' => (int)$it['available_stock'],
                'is_active' => (bool)$it['is_active'],
                'image_src' => getCheckoutItemImageSrc($it['image'] ?? ''),
            ];
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'confirm_token' => $confirmToken,
            'can_finalize' => ($liveHydration['can_checkout'] && !($checkout_source !== 'buynow' && $grand_total < 3000)),
            'items' => $reviewItemsPayload,
            'total_qty' => $total_qty,
            'grand_total' => $grand_total,
            'price_changes' => $price_changes,
            'issues' => $checkout_issues,
            'customer' => [
                'full_name' => trim($fullname . ' ' . $surname),
                'contact_number' => $contact_number,
                'tel_no' => $tel_no ?: '',
                'email' => $email,
            ],
            'delivery' => [
                'mode' => 'Standard Delivery',
                'full_address' => $full_address,
                'landmark' => $landmark,
            ],
            'payment' => [
                'method' => $payCheck['payment_method'],
                'scheme' => $payCheck['payment_scheme'],
                'scheme_label' => $schemeLabel,
                'amount_due' => $amountDueNow,
                'paid_amount' => (float)$payCheck['paid_amount'],
                'ref_no' => (string)($payCheck['ref_no'] ?? ''),
                'receiptdt' => (string)($payCheck['receiptdt'] ?? ''),
                'temp_proof_src' => !empty($payCheck['temp_proof_path']) ? ('../uploads/proofs/tmp/' . basename((string)$payCheck['temp_proof_path'])) : '',
            ],
        ]);
        exit();
    }

    header("Location: placeorder.php" . $checkout_review_qs);
    exit();
}

// -------------------------------------------------------------------------
// STEP 2 HANDLER: FINAL CONFIRM & PLACE ORDER (ATOMIC DB VALIDATION + INSERT)
// -------------------------------------------------------------------------
if (isset($_POST['confirm_place_order'])) {
    $postedToken = trim((string)($_POST['confirm_token'] ?? ''));

    // Duplicate / double-click / refresh submission guard
    if ($postedToken !== '' && isset($_SESSION['checkout_consumed_tokens'][$postedToken])) {
        $alreadyOrderId = (int)$_SESSION['checkout_consumed_tokens'][$postedToken];
        $_SESSION['message'] = "<b>Order Placed Successfully! </b><br>Your Order Number is: <span class='text-primary fw-bold'>#$alreadyOrderId</span>";
        header("Location: dashboard.php");
        exit();
    }

    $draft = $_SESSION['checkout_draft'][$draft_scope] ?? null;
    if (
        !is_array($draft)
        || empty($draft['ready_for_review'])
        || empty($draft['confirm_token'])
        || !hash_equals((string)$draft['confirm_token'], $postedToken)
    ) {
        $_SESSION['app_modal'] = [
            'title' => 'Session Expired or Duplicate Submission',
            'message' => 'Please review your order details again before placing your order.',
            'type' => 'warning',
        ];
        header("Location: placeorder.php" . $checkout_qs);
        exit();
    }

    $redirectReviewError = function (string $title, string $message, string $type = 'danger') use ($checkout_qs): void {
        $_SESSION['app_modal'] = [
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ];
        header("Location: placeorder.php" . $checkout_qs);
        exit();
    };

    if (empty($_POST['confirm_order_checkbox'])) {
        $redirectReviewError('Confirmation Required', 'Please check the confirmation box verifying that all items, quantities, and order details are correct.', 'warning');
    }

    $fullname = trim((string)($draft['fullname'] ?? ''));
    $surname = trim((string)($draft['surname'] ?? ''));
    $contact_number = trim((string)($draft['contact_number'] ?? ''));
    $tel_no = !empty($draft['tel_no']) ? trim((string)$draft['tel_no']) : null;
    $email = resolveCheckoutCustomerEmail($pdo, $loggedInUserId, $customerEmail, (string)($draft['email'] ?? ''));
    $full_address = trim((string)($draft['full_address'] ?? ''));
    $landmark = trim((string)($draft['landmark'] ?? ''));
    $payment_method = trim((string)($draft['payment_method'] ?? ''));
    $payment_scheme = trim((string)($draft['payment_scheme'] ?? 'full_payment'));

    if ($fullname === '' || $surname === '' || !preg_match('/^09\d{9}$/', $contact_number) || $full_address === '' || $landmark === '') {
        $redirectReviewError('Invalid Checkout Details', 'Customer or delivery details are incomplete. Please edit your checkout information.');
    }

    $proof_of_payment = null;
    $stagedTempRelPath = !empty($draft['temp_proof_path']) ? (string)$draft['temp_proof_path'] : null;

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        // Re-validate ALL items, prices, active flags, stock, and shared conversion rules with DB row locks
        $lockedHydration = hydrateCheckoutItemsWithLiveDb($pdo, $raw_items_to_process, true);
        $lockedItems = $lockedHydration['items'];
        $lockedGrandTotal = (float)$lockedHydration['grand_total'];

        if ($checkout_source !== 'buynow' && $lockedGrandTotal < 3000) {
            $pdo->rollBack();
            $redirectReviewError('Minimum Order Not Met', 'Updated order total (₱' . number_format($lockedGrandTotal, 2) . ') is below the ₱3,000.00 minimum requirement. Please return to your cart.', 'warning');
        }

        if (!$lockedHydration['can_checkout']) {
            $pdo->rollBack();
            $redirectReviewError('Stock Changed During Review', $lockedHydration['issues'][0] ?? 'Stock availability or item status changed. Please return to your cart.');
        }

        // Re-validate payment against latest locked grand total & duplicate reference number inside transaction
        $payCheck = validateCheckoutPaymentDetails(
            $pdo,
            $payment_method,
            $payment_scheme,
            $lockedGrandTotal,
            [
                'ref_no' => $draft['ref_no'] ?? '',
                'paid_amount' => $draft['paid_amount'] ?? 0,
                'receiptdt' => $draft['receiptdt'] ?? '',
                'receipt_iso' => $draft['receipt_iso'] ?? '',
                'sent_to' => $draft['sent_to'] ?? '',
            ],
            $stagedTempRelPath,
            null,
            false
        );

        if (!$payCheck['ok']) {
            $pdo->rollBack();
            $redirectReviewError($payCheck['title'], $payCheck['message'], $payCheck['modal_type'] ?? 'danger');
        }

        // Persist receipt proof to Google Drive / permanent local uploads folder ONLY upon final confirmation
        if (in_array($payCheck['payment_method'], ['GCash', 'Maya'], true) && !empty($payCheck['temp_proof_path'])) {
            $stagedFilenameSafe = basename($payCheck['temp_proof_path']);
            $stagedAbsPath = __DIR__ . '/../uploads/proofs/tmp/' . $stagedFilenameSafe;
            if (file_exists($stagedAbsPath)) {
                $ext = strtolower(pathinfo($stagedFilenameSafe, PATHINFO_EXTENSION)) ?: 'jpg';
                $new_filename = "proof_" . time() . "_" . rand(1000, 9999) . "." . $ext;
                $customerFolderId = "1K63T1f7Z2JucT5lgyBuNobLIRJZFYNno";

                try {
                    if (function_exists('uploadProductImageToDrive')) {
                        $file_id = uploadProductImageToDrive($stagedAbsPath, $new_filename, $customerFolderId);
                        if (!empty($file_id)) {
                            $proof_of_payment = "https://drive.google.com/uc?export=view&id=" . $file_id;
                        }
                    }
                } catch (Throwable $e) {
                    error_log("CUSTOMER PROOF GOOGLE DRIVE UPLOAD ERROR: " . $e->getMessage());
                }

                if (empty($proof_of_payment)) {
                    $target_dir = __DIR__ . "/../uploads/proofs/";
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0775, true);
                    }
                    $permDest = $target_dir . $new_filename;
                    if (@rename($stagedAbsPath, $permDest) || (@copy($stagedAbsPath, $permDest) && @unlink($stagedAbsPath))) {
                        $proof_of_payment = "uploads/proofs/" . $new_filename;
                    }
                } elseif (file_exists($stagedAbsPath)) {
                    @unlink($stagedAbsPath);
                }
            }
        }

        $cart_json = json_encode(array_values($lockedItems));

        // Insert order into database
        $order_query = "INSERT INTO orders 
            (fullname, surname, contact_number, tel_no, email, full_address, landmark, total_amount, paid_amount, cart, payment_method, payment_scheme, ref_no, proof_of_payment, receiptdt, sent_to, order_date, status) 
            VALUES 
            (:fullname, :surname, :contact_number, :tel_no, :email, :full_address, :landmark, :total_amount, :paid_amount, :cart, :payment_method, :payment_scheme, :ref_no, :proof_of_payment, :receiptdt, :sent_to, CURRENT_TIMESTAMP, 'pending')";

        $stmt = $pdo->prepare($order_query);
        $stmt->execute([
            ':fullname' => $fullname,
            ':surname' => $surname,
            ':contact_number' => $contact_number,
            ':tel_no' => $tel_no,
            ':email' => $email,
            ':full_address' => $full_address,
            ':landmark' => $landmark,
            ':total_amount' => $lockedGrandTotal,
            ':paid_amount' => $payCheck['paid_amount'],
            ':cart' => $cart_json,
            ':payment_method' => $payCheck['payment_method'],
            ':payment_scheme' => $payCheck['payment_scheme'],
            ':ref_no' => $payCheck['ref_no'],
            ':proof_of_payment' => $proof_of_payment,
            ':receiptdt' => $payCheck['receiptdt'],
            ':sent_to' => $payCheck['sent_to']
        ]);

        $order_id = $pdo->lastInsertId();

        // Insert order items & atomically deduct stock
        foreach ($lockedItems as $item) {
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            $product_id = (int)$item['id'];
            $name = (string)$item['name'];
            $v_id = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $v_name = !empty($item['variant_name']) ? (string)$item['variant_name'] : null;
            $subtotal = round($price * $qty, 2);

            $item_stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, variant_id, variant_name, price, qty, subtotal) 
                                        VALUES (:order_id, :product_id, :product_name, :variant_id, :variant_name, :price, :qty, :subtotal)");
            $item_stmt->execute([
                ':order_id' => $order_id,
                ':product_id' => $product_id,
                ':product_name' => $name,
                ':variant_id' => $v_id,
                ':variant_name' => $v_name,
                ':price' => $price,
                ':qty' => $qty,
                ':subtotal' => $subtotal
            ]);

            $linked_source = getLinkedSourceInfo($pdo, $product_id, $v_id);
            if ($linked_source) {
                $idemp = "online_order_{$order_id}_" . ($v_id ? "v_{$v_id}" : "p_{$product_id}");
                list($ded_ok, $ded_msg, $new_stk) = deductSharedStock(
                    $pdo,
                    $linked_source['source_id'],
                    $product_id,
                    $v_id,
                    $qty,
                    'Online Order Deduction',
                    $order_id,
                    $email ?: 'Customer Online',
                    "Online Order #$order_id for {$linked_source['source_name']}",
                    $idemp
                );
                if (!$ded_ok) {
                    throw new Exception($ded_msg);
                }
            } else {
                if ($v_id !== null) {
                    $stock_stmt = $pdo->prepare("UPDATE product_variants SET stock = stock - :qty WHERE id = :v_id AND stock >= :qty_chk");
                    $stock_stmt->execute([':qty' => $qty, ':v_id' => $v_id, ':qty_chk' => $qty]);
                } else {
                    $stock_stmt = $pdo->prepare("UPDATE products SET stock = stock - :qty WHERE id = :product_id AND stock >= :qty_chk");
                    $stock_stmt->execute([':qty' => $qty, ':product_id' => $product_id, ':qty_chk' => $qty]);
                }
                if ($stock_stmt->rowCount() !== 1) {
                    throw new Exception("Insufficient stock for '{$item['display_name']}'. Please return to your cart and adjust quantities.");
                }
            }
        }

        $pdo->commit();

        // Mark token as consumed so double-click/refresh never creates duplicate orders
        $_SESSION['checkout_consumed_tokens'][(string)$draft['confirm_token']] = (int)$order_id;
        if (count($_SESSION['checkout_consumed_tokens']) > 20) {
            $_SESSION['checkout_consumed_tokens'] = array_slice($_SESSION['checkout_consumed_tokens'], -20, null, true);
        }

        if ($checkout_source == 'buynow') {
            unset($_SESSION['buy_now']);
        } else {
            unset($_SESSION['cart']);
        }
        unset($_SESSION['checkout_draft'][$draft_scope]);

        $_SESSION['message'] = "<b>Order Placed Successfully! </b><br>Your Order Number is: <span class='text-primary fw-bold'>#$order_id</span>";
        header("Location: dashboard.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Checkout error: " . $e->getMessage());
        $redirectReviewError('Order Confirmation Failed', $e->getMessage(), 'danger');
    }
}

// -------------------------------------------------------------------------
// CHECKOUT FORM PRE-FILL & STATE FLAGS
// -------------------------------------------------------------------------
$checkout_draft = $_SESSION['checkout_draft'][$draft_scope] ?? [];
$has_saved_draft = !empty($checkout_draft);
$staged_proof_rel = (!empty($checkout_draft['temp_proof_path']) && file_exists(__DIR__ . '/../uploads/proofs/tmp/' . basename((string)$checkout_draft['temp_proof_path'])))
    ? ('uploads/proofs/tmp/' . basename((string)$checkout_draft['temp_proof_path']))
    : '';
$min_order_unmet = ($checkout_source !== 'buynow' && $grand_total < 3000);
$open_review_on_load = (isset($_GET['step']) && $_GET['step'] === 'review' && !empty($checkout_draft['ready_for_review']));
$buyNowFirstItem = !empty($items_to_process) ? reset($items_to_process) : null;
$buyNowEditUrl = ($buyNowFirstItem && !empty($buyNowFirstItem['id'])) ? ('productDescription.php?id=' . (int)$buyNowFirstItem['id']) : 'dashboard.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Hardware System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Tesseract.js OCR Engine -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

    <style>
        html, body {
            height: 100%;
            margin: 0;
            background-color: #f8f9fa;
        }

        .checkout-wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }

        /* SUMMARY PANEL (Fixed Header & Footer, Independent Scrollable Items) */
        .summary-pane {
            flex-shrink: 0;
            z-index: 1020;
            background: #ffffff;
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
        }

        /* Mobile: Scrollable ang listahan kapag naka-expand (max 30% ng screen height para kita pa rin form) */
        .summary-items-list {
            max-height: 30vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-right: 4px;
        }

        /* FORM PANEL (Independent Scrollable User Inputs) */
        .form-pane {
            flex: 1 1 auto;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 1rem;
        }

        /* DESKTOP & TABLET VIEW (>= 768px) */
        @media (min-width: 768px) {
            .checkout-wrapper {
                flex-direction: row;
                max-width: 1200px;
                margin: 0 auto;
                padding: 1.5rem;
                gap: 1.5rem;
            }

            .summary-pane {
                width: 42%;
                max-width: 450px;
                height: 100%;
                border-bottom: none;
                border-radius: 0.5rem;
                box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
                overflow: hidden; /* Para hindi mag-scroll ang buong card, items lang */
            }

            .summary-items-list {
                flex: 1 1 auto;
                max-height: none; /* Sasakupin lahat ng bakanteng gitna */
                overflow-y: auto; /* Hiwalay na scrollbar para sa items */
                padding-right: 6px;
            }

            .form-pane {
                width: 58%;
                height: 100%;
                padding: 0;
                border-radius: 0.5rem;
                overflow-y: auto; /* Hiwalay na scrollbar para sa form inputs */
            }

            /* Laging nakabukas ang items list sa desktop */
            #mobileItemsCollapse {
                display: block !important;
            }
        }

        /* Custom subtle scrollbar para sa parehong panels */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background-color: rgba(0, 0, 0, 0.35);
        }

        /* Saved Addresses in Checkout */
        .saved-address-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .saved-address-card:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .saved-address-card.selected {
            border-color: #051c3c;
            background-color: #f0f4fa;
            box-shadow: 0 0 0 1px #051c3c;
        }

        .address-autofill-flash {
            animation: fieldPulse 1.2s ease-in-out;
        }

        @keyframes fieldPulse {
            0% { background-color: #e0f2fe; border-color: #0284c7; }
            50% { background-color: #f0fdf4; border-color: #22c55e; }
            100% { background-color: #fff; border-color: #dee2e6; }
        }

        /* Payment Details Light Card */
        .payment-details-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            color: #1e293b;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
            margin-bottom: 16px;
        }
        .payment-details-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .payment-details-icon {
            width: 34px;
            height: 34px;
            background-color: #10b981;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
        }
        .payment-inner-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .payment-field-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .payment-field-label {
            font-size: 0.72rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .payment-field-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }
        .qr-preview-thumb {
            width: 105px;
            height: 105px;
            object-fit: contain;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 4px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .qr-preview-thumb:hover {
            transform: scale(1.04);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .btn-copy-clip {
            color: #64748b;
            transition: color 0.2s, transform 0.1s;
        }
        .btn-copy-clip:hover {
            color: #0f172a;
            transform: scale(1.1);
        }

        /* Step Progress & Review Order Page */
        .checkout-stepper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 50rem;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #64748b;
        }
        .step-pill.done {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }
        .step-pill.active {
            background: #051c3c;
            border-color: #051c3c;
            color: #ffffff;
        }
        .review-receipt-thumb {
            max-width: 96px;
            max-height: 96px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            cursor: zoom-in;
        }
        #reviewOrderModal .modal-content {
            border-radius: 16px;
            overflow: hidden;
        }
        #reviewOrderModal .modal-header {
            background: #051c3c;
            color: #ffffff;
        }
        #receiptPreviewModal {
            z-index: 1070;
        }
    </style>
</head>
<body>

<div class="checkout-wrapper">
    <!-- ORDER SUMMARY PANEL -->
    <div class="summary-pane p-3 p-md-4">
        <!-- Fixed Header -->
        <div class="d-flex justify-content-between align-items-center mb-2 mb-md-3 flex-shrink-0">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-cart-check text-primary me-1"></i> Items to Purchase
                <span class="badge bg-secondary rounded-pill ms-1"><?php echo $total_qty; ?></span>
            </h5>
            <!-- Toggle button para sa mobile -->
            <button class="btn btn-sm btn-outline-secondary d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#mobileItemsCollapse" aria-expanded="false" aria-controls="mobileItemsCollapse">
                View Items <i class="bi bi-chevron-down"></i>
            </button>
        </div>

        <!-- Independent Scrollable Items List -->
        <div class="collapse summary-items-list custom-scroll" id="mobileItemsCollapse">
            <div class="pt-2 pt-md-0">
                <?php foreach ($items_to_process as $item): ?>
                    <?php $item_img_src = getCheckoutItemImageSrc($item['image'] ?? ''); ?>
                    <div class="d-flex align-items-center mb-2 mb-md-3 pb-2 pb-md-3 border-bottom">
                       <img src="<?php echo htmlspecialchars($item_img_src, ENT_QUOTES, 'UTF-8'); ?>" 
                            alt="<?php echo htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            class="rounded me-3" 
                            style="width: 45px; height: 45px; object-fit: cover;"
                            onerror="this.onerror=null; this.src='../assets/images/no-image.png';"
                            loading="lazy">
                        <div class="flex-grow-1 overflow-hidden">
                            <h6 class="mb-0 fw-bold small text-truncate"><?php echo htmlspecialchars($item['display_name']); ?></h6>
                            <small class="text-muted">Unit: <?php echo htmlspecialchars($item['unit']); ?> · Qty: <?php echo (int)$item['qty']; ?> × ₱<?php echo number_format((float)$item['price'], 2); ?></small>
                        </div>
                        <div class="text-end fw-bold small ms-2">₱<?php echo number_format((float)$item['subtotal'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Fixed Total Amount Footer -->
        <div class="mt-auto pt-2 bg-white flex-shrink-0 border-top">
            <div class="d-flex justify-content-between align-items-center small text-secondary">
                <span>Subtotal Amount:</span>
                <span>₱<?php echo number_format($grand_total, 2); ?></span>
            </div>

            <?php if ($grand_total >= 5000): ?>
                <div id="summary_breakdown" class="mt-2 pt-2 border-top">
                    <div class="d-flex justify-content-between align-items-center small text-muted mb-1">
                        <span>Payment Scheme:</span>
                        <span id="label_scheme" class="fw-bold text-dark">50% Downpayment</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center fs-5 fw-bold text-dark">
                        <span id="label_due_text">Amount to Pay Now:</span>
                        <span class="text-primary" id="display_due_amount">₱<?php echo number_format($grand_total * 0.5, 2); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center fs-5 fw-bold text-dark mt-2 pt-2 border-top">
                    <span>Total Amount:</span>
                    <span class="text-primary">₱<?php echo number_format($grand_total, 2); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- INDEPENDENT SCROLLABLE DELIVERY & PAYMENT FORM -->
    <div class="form-pane custom-scroll">
        <div class="card border-0 shadow-sm p-3 p-md-4 mb-4 mb-md-0">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h4 class="mb-0 text-primary fw-bold"><i class="bi bi-bag-check-fill"></i> Delivery & Payment Details</h4>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">Step 1 of 2: Checkout</span>
            </div>

            <?php if ($min_order_unmet || !empty($checkout_issues)): ?>
                <div class="alert alert-danger py-2 small mb-3">
                    <div class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Cart Notice</div>
                    <?php if ($min_order_unmet): ?>
                        <div>Minimum order requirement of ₱3,000.00 is required for delivery checkout.</div>
                    <?php endif; ?>
                    <?php foreach ($checkout_issues as $iss): ?>
                        <div><?= htmlspecialchars($iss, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($price_changes)): ?>
                <div class="alert alert-info py-2 small mb-3">
                    <div class="fw-bold"><i class="bi bi-info-circle-fill me-1"></i> Catalog Price Update</div>
                    <?php foreach ($price_changes as $pc): ?>
                        <div><?= htmlspecialchars($pc, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . htmlspecialchars($checkout_qs, ENT_QUOTES, 'UTF-8'); ?>" method="POST" enctype="multipart/form-data" id="checkoutStepForm">
                
                <?php if (!empty($saved_addresses)): ?>
                    <div class="mb-4 p-3 bg-light border rounded-3">
                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                            <label class="form-label fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                                <i class="bi bi-geo-alt-fill text-danger"></i> Choose Delivery Address
                            </label>
                            <a href="profile.php?tab=address" target="_blank" class="small text-primary text-decoration-none fw-semibold">
                                <i class="bi bi-gear me-1"></i>Manage Addresses
                            </a>
                        </div>
                        <p class="small text-muted mb-2">Select a saved address below to auto-fill your delivery details instantly:</p>

                        <div class="d-flex flex-column gap-2" id="savedAddressesContainer">
                            <?php foreach ($saved_addresses as $sIndex => $sAddr):
                                $isCardSelected = $has_saved_draft
                                    ? ((int)($checkout_draft['saved_address_id'] ?? 0) === (int)$sAddr['id'])
                                    : (!empty($sAddr['is_default']) || $sIndex === 0);
                            ?>
                                <div class="saved-address-card d-flex align-items-start gap-3 <?php echo $isCardSelected ? 'selected' : ''; ?>"
                                     onclick="selectSavedAddress(<?php echo (int) $sAddr['id']; ?>)">
                                    <div class="form-check mt-1">
                                        <input class="form-check-input saved-address-radio" type="radio" name="saved_address_id" 
                                               id="addr_radio_<?php echo (int) $sAddr['id']; ?>" 
                                               value="<?php echo (int) $sAddr['id']; ?>"
                                               <?php echo $isCardSelected ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="flex-grow-1 small">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <strong class="text-dark fs-6"><?php echo htmlspecialchars($sAddr['first_name'] . ' ' . $sAddr['last_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="text-secondary fw-medium"><?php echo htmlspecialchars($sAddr['contact_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($sAddr['tel_no'])): ?>
                                                <span class="text-muted small">(Tel: <?php echo htmlspecialchars($sAddr['tel_no'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                            <?php endif; ?>
                                            <?php if (!empty($sAddr['is_default'])): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.72rem;">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-secondary mt-1">
                                            <i class="bi bi-pin-map text-danger me-1"></i><?php echo htmlspecialchars($sAddr['full_address'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                            <span class="fw-semibold">Landmark:</span> <?php echo htmlspecialchars($sAddr['landmark'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($loggedInUserId > 0): ?>
                        <div class="alert alert-light border d-flex align-items-center justify-content-between p-3 mb-4 rounded-3 flex-wrap gap-2">
                            <div class="small text-muted">
                                <i class="bi bi-info-circle text-primary me-1"></i>
                                Save your delivery address in your <a href="profile.php?tab=address" target="_blank" class="fw-semibold text-primary">Profile</a> for instant 1-click checkout!
                            </div>
                            <a href="profile.php?tab=address" target="_blank" class="btn btn-outline-primary btn-sm py-1 px-3 fw-semibold">
                                + Add Address
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">First Name</label>
                        <input type="text" name="fullname" id="input_fullname" class="form-control" 
                        value="<?= htmlspecialchars((string)($checkout_draft['fullname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                         <label class="form-label">Last Name</label>
                        <input type="text" name="surname" id="input_surname" class="form-control" 
                        value="<?= htmlspecialchars((string)($checkout_draft['surname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" id="input_contact_number" class="form-control" placeholder="09XXXXXXXXX"
                        value="<?= htmlspecialchars((string)($checkout_draft['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                       <label class="form-label">Telephone (Optional)</label>
                        <input type="text" name="tel_no" id="input_tel_no" class="form-control" 
                        value="<?= htmlspecialchars((string)($checkout_draft['tel_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       oninput="this.value = this.value.replace(/[^0-9-]/g, '')">
                    </div>
                    <!-- Customer Email automatically bound to logged-in account -->
                    <input type="hidden" name="email" id="input_email" value="<?php echo htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-12">
                        <label class="form-label">Complete Delivery Address</label>
                        <textarea name="full_address" id="input_full_address" class="form-control" rows="2" required><?= htmlspecialchars((string)($checkout_draft['full_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Landmark</label>
                        <input type="text" name="landmark" id="input_landmark" class="form-control" value="<?= htmlspecialchars((string)($checkout_draft['landmark'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    
                    <!-- PAYMENT SECTION (GCASH & MAYA ONLY) -->
                    <?php
                        $draftScheme = (string)($checkout_draft['payment_scheme'] ?? '50_percent');
                        $draftMethod = (string)($checkout_draft['payment_method'] ?? '');
                    ?>
                    <div class="col-12">
                        <?php if ($grand_total >= 5000): ?>
                            <div id="payment_option_box" class="alert alert-info p-3">
                                <p class="fw-bold small text-danger mb-2">Note: Orders ₱5,000+ require payment scheme selection.</p>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_scheme" value="50_percent" <?= ($draftScheme !== 'full_payment') ? 'checked' : ''; ?> id="opt1" onchange="updatePaymentDisplay()">
                                    <label class="form-check-label" for="opt1">50% Downpayment</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_scheme" value="full_payment" <?= ($draftScheme === 'full_payment') ? 'checked' : ''; ?> id="opt2" onchange="updatePaymentDisplay()">
                                    <label class="form-check-label" for="opt2">Full Payment</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <label class="form-label fw-bold">Mode of Payment</label>
                        <?php if ($grand_total < 5000): ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="Cash on Delivery (COD)" <?= ($draftMethod === 'Cash on Delivery (COD)') ? 'checked' : ''; ?> onclick="togglePaymentFields()" id="cod_radio" required><label class="form-check-label" for="cod_radio">Cash On Delivery</label></div>
                        <?php endif; ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="GCash" <?= ($draftMethod === 'GCash') ? 'checked' : ''; ?> onclick="togglePaymentFields()" id="gcash_radio" <?= ($grand_total >= 5000) ? 'required' : ''; ?>><label class="form-check-label" for="gcash_radio">GCash</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="Maya" <?= ($draftMethod === 'Maya') ? 'checked' : ''; ?> onclick="togglePaymentFields()" id="maya_radio"><label class="form-check-label" for="maya_radio">Maya</label></div>
                        <div id="cod_message" class="alert alert-warning mt-2 d-none">Pay exact amount upon delivery.</div>
                    </div>

                    <!-- ONLINE PAYMENT FIELDS WITH AUTOMATIC TESSERACT OCR SCANNER -->
                    <div id="online_payment_fields" class="col-12 d-none bg-light p-3 border rounded">
                        <!-- GCASH ACCOUNT DETAILS CARD (LIGHT THEME) -->
                        <div id="gcash_details_card" class="payment-details-card d-none">
                            <div class="payment-details-header">
                                <div class="payment-details-icon">
                                    <i class="bi bi-phone"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">GCash Account Details</h6>
                            </div>

                            <!-- QR & Scan Instruction Box -->
                            <div class="payment-inner-box d-flex align-items-center gap-3">
                                <img src="../assets/qr/gcash.jpg" 
                                     alt="GCash QR Code" 
                                     class="qr-preview-thumb rounded shadow-sm"
                                     onclick="openQrEnlargeModal('GCash')"
                                     onerror="this.src='../assets/images/no-image.png';">
                                <div class="flex-grow-1">
                                    <div class="small fw-bold text-primary text-uppercase mb-1" style="letter-spacing: 0.5px;">SCAN QR CODE OR SEND TO NUMBER</div>
                                    <div class="small text-muted mb-2" style="font-size: 0.82rem; line-height: 1.4;">Open your GCash app, tap "Scan QR" or use "Express Send" to the number below.</div>
                                    <span role="button" class="text-success small fw-semibold d-inline-flex align-items-center" onclick="openQrEnlargeModal('GCash')">
                                        <i class="bi bi-zoom-in me-1"></i>Tap QR to enlarge
                                    </span>
                                </div>
                            </div>

                            <!-- GCash Account Name -->
                            <div class="payment-field-box">
                                <div>
                                    <div class="payment-field-label">GCASH ACCOUNT NAME</div>
                                    <div class="payment-field-value" id="val_gcash_name">Ronil Taipan</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none btn-copy-clip p-1" onclick="copyToClipboard('Ronil Taipan', this)" title="Copy Name">
                                    <i class="bi bi-copy fs-5"></i>
                                </button>
                            </div>

                            <!-- GCash Number -->
                            <div class="payment-field-box mb-0">
                                <div>
                                    <div class="payment-field-label">GCASH NUMBER</div>
                                    <div class="payment-field-value" id="val_gcash_num">09073595023</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none btn-copy-clip p-1" onclick="copyToClipboard('09073595023', this)" title="Copy Number">
                                    <i class="bi bi-copy fs-5"></i>
                                </button>
                            </div>
                        </div>

                        <!-- MAYA ACCOUNT DETAILS CARD (LIGHT THEME) -->
                        <div id="maya_details_card" class="payment-details-card d-none">
                            <div class="payment-details-header">
                                <div class="payment-details-icon" style="background-color: #06b6d4;">
                                    <i class="bi bi-wallet2"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Maya Account Details</h6>
                            </div>

                            <!-- QR & Scan Instruction Box -->
                            <div class="payment-inner-box d-flex align-items-center gap-3">
                                <img src="../assets/qr/maya.jpg" 
                                     alt="Maya QR Code" 
                                     class="qr-preview-thumb rounded shadow-sm"
                                     onclick="openQrEnlargeModal('Maya')"
                                     onerror="this.src='../assets/images/no-image.png';">
                                <div class="flex-grow-1">
                                    <div class="small fw-bold text-info text-uppercase mb-1" style="letter-spacing: 0.5px;">SCAN QR CODE OR SEND TO NUMBER</div>
                                    <div class="small text-muted mb-2" style="font-size: 0.82rem; line-height: 1.4;">Open your Maya app, tap "Scan QR" or send money to the number below.</div>
                                    <span role="button" class="text-info small fw-semibold d-inline-flex align-items-center" onclick="openQrEnlargeModal('Maya')">
                                        <i class="bi bi-zoom-in me-1"></i>Tap QR to enlarge
                                    </span>
                                </div>
                            </div>

                            <!-- Maya Account Name -->
                            <div class="payment-field-box">
                                <div>
                                    <div class="payment-field-label">MAYA ACCOUNT NAME</div>
                                    <div class="payment-field-value" id="val_maya_name">Melanie Taipan</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none btn-copy-clip p-1" onclick="copyToClipboard('Melanie Taipan', this)" title="Copy Name">
                                    <i class="bi bi-copy fs-5"></i>
                                </button>
                            </div>

                            <!-- Maya Number -->
                            <div class="payment-field-box mb-0">
                                <div>
                                    <div class="payment-field-label">MAYA NUMBER</div>
                                    <div class="payment-field-value" id="val_maya_num">09682770376</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-decoration-none btn-copy-clip p-1" onclick="copyToClipboard('09682770376', this)" title="Copy Number">
                                    <i class="bi bi-copy fs-5"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Hidden Inputs: Automatically saved to Database -->
                        <input type="hidden" name="ref_no" id="ref_no_input" value="<?= htmlspecialchars((string)($checkout_draft['ref_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="paid_amount" id="paid_amount_input" value="<?= htmlspecialchars((string)($checkout_draft['paid_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="receiptdt" id="receiptdt_input" value="<?= htmlspecialchars((string)($checkout_draft['receiptdt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="receipt_iso" id="receipt_iso_input" value="<?= htmlspecialchars((string)($checkout_draft['receipt_iso'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="sent_to" id="sent_to_input" value="<?= htmlspecialchars((string)($checkout_draft['sent_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- OCR Status Message Box -->
                        <div id="ocr_status" class="alert alert-info py-2 d-none small">
                            <span id="ocr_status_text">Scanning receipt image via OCR...</span>
                        </div>

                        <label class="form-label small fw-bold">Upload Proof of Payment (GCash / Maya / Bank Transfer Screenshot)</label>
                        <input type="file" name="proof_of_payment" id="proof_of_payment_input" class="form-control mb-1" accept=".jpg,.jpeg,.png,.webp" onchange="processReceiptOCR(this)">
                        <small class="text-muted d-block mb-2"><i>Note: Upload direct official screenshot from GCash, Maya, or Bank Transfer.(Jpeg, Png, Webp)</i></small>
                    </div>

                    <div class="col-12 form-check ps-4">
                        <input type="checkbox" class="form-check-input" id="termsCheckbox" onclick="toggleSubmitButton()" <?= !empty($checkout_draft['terms_accepted']) ? 'checked' : ''; ?>>
                       <label class="form-check-label" for="termsCheckbox" style="font-size: 0.85rem;">
                           I have read and agree to the Terms and Conditions and Privacy Notice. <a href="tac.php" target="_blank" class="text-decoration-underline">Terms and Conditions</a> 
                        </label>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="review_order" id="submitBtn" class="btn btn-primary w-100 py-2 mb-2 fw-semibold" <?= (!empty($checkout_draft['terms_accepted']) && !$min_order_unmet && $liveHydration['can_checkout']) ? '' : 'disabled'; ?>>
                            <i></i> Place Order
                        </button>
                        <button type="button" class="btn btn-outline-secondary w-100" onclick="window.location.href='<?= $checkout_source === 'buynow' ? 'dashboard.php' : 'cart.php'; ?>';">
                            <i></i> <?= $checkout_source === 'buynow' ? 'Cancel' : 'Back to Edit Cart'; ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- FLOATING REVIEW ORDER & FINAL CONFIRMATION MODAL -->
<div class="modal fade" id="reviewOrderModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="reviewOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-3 border-bottom-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="reviewOrderModalLabel">
                        <i class="bi bi-clipboard2-check me-2"></i>Review &amp; Confirm Your Order
                    </h5>
                    <small class="text-white-50">Please check all items, units, quantities, delivery details, and payment information carefully.</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-3 p-md-4 bg-light">
                <div id="reviewPriceChangesAlert" class="alert alert-info border-info shadow-sm mb-3 d-none">
                    <div class="fw-bold small mb-1"><i class="bi bi-info-circle-fill me-1"></i> Latest Catalog Prices Applied</div>
                    <ul id="reviewPriceChangesList" class="mb-0 small ps-3"></ul>
                </div>

                <div id="reviewIssuesAlert" class="alert alert-danger border-danger shadow-sm mb-3 d-none">
                    <div class="fw-bold small mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i> Cannot Finalize Order</div>
                    <ul id="reviewIssuesList" class="mb-0 small ps-3"></ul>
                </div>

                <div class="row g-3">
                    <!-- LEFT: ORDER ITEMS TABLE -->
                    <div class="col-12 col-lg-7">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white border-bottom py-2.5 d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-dark"><i class="bi bi-box-seam text-primary me-1"></i> Order Items</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1" id="reviewTotalQtyBadge">0 items</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0 small">
                                        <thead class="table-light text-uppercase text-muted" style="font-size: 0.72rem;">
                                            <tr>
                                                <th class="ps-3">Product &amp; Variant</th>
                                                <th>Unit</th>
                                                <th class="text-center">Qty</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end pe-3">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reviewItemsTbody"></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-light border-top p-3 small">
                                <div class="d-flex justify-content-between text-muted mb-1">
                                    <span>Total Quantity</span>
                                    <span class="fw-bold text-dark" id="reviewFooterTotalQty">0</span>
                                </div>
                                <div class="d-flex justify-content-between text-muted mb-1">
                                    <span>Payment Scheme</span>
                                    <span class="fw-bold text-dark" id="reviewFooterScheme">Full Payment</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center fs-5 fw-bold text-dark pt-2 border-top mt-2">
                                    <span>Final Order Total</span>
                                    <span class="text-primary" id="reviewFooterGrandTotal">₱0.00</span>
                                </div>
                                <div id="reviewFooterDownpaymentRow" class="d-flex justify-content-between align-items-center fw-bold text-success mt-1 d-none">
                                    <span>Amount Paid / Due Now (50% Downpayment)</span>
                                    <span id="reviewFooterAmountDue">₱0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: CUSTOMER, DELIVERY, PAYMENT + CONFIRMATION -->
                    <div class="col-12 col-lg-5">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-white border-bottom py-2.5 fw-bold text-dark">
                                <i class="bi bi-person-lines-fill text-primary me-1"></i> Customer, Delivery &amp; Payment
                            </div>
                            <div class="card-body small">
                                <div class="mb-2 pb-2 border-bottom">
                                    <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.7rem;">Customer Information</div>
                                    <div class="fw-bold text-dark" id="revCustName"></div>
                                    <div class="text-secondary">Mobile: <strong id="revCustContact"></strong></div>
                                    <div class="text-secondary d-none" id="revCustTelWrap">Telephone: <span id="revCustTel"></span></div>
                                    <div class="text-secondary d-none" id="revCustEmailWrap">Email: <span id="revCustEmail"></span></div>
                                </div>

                                <div class="mb-2 pb-2 border-bottom">
                                    <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.7rem;">Delivery Information</div>   
                                    <div class="text-dark fw-medium" id="revDelivAddr"></div>
                                    <div class="text-muted mt-1">Landmark: <strong id="revDelivLandmark"></strong></div>
                                </div>

                                <div>
                                    <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.7rem;">Payment Details</div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary">Payment Method:</span>
                                        <span class="fw-bold text-dark" id="revPayMethod"></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-secondary">Payment Scheme:</span>
                                        <span class="fw-semibold text-dark" id="revPayScheme"></span>
                                    </div>
                                    <div id="revOnlinePayMeta" class="d-none">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-secondary">Paid Amount:</span>
                                            <span class="fw-bold text-success" id="revPayPaid"></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-secondary">Reference No.:</span>
                                            <span class="fw-bold font-monospace" id="revPayRef"></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1 d-none" id="revPayDtWrap">
                                            <span class="text-secondary">Receipt Date:</span>
                                            <span id="revPayDt"></span>
                                        </div>
                                        <div id="revProofWrap" class="mt-2 pt-2 border-top d-flex align-items-center gap-2 d-none">
                                            <img id="revProofThumb" src="" alt="Payment Receipt" class="review-receipt-thumb" onclick="openReceiptPreviewModal(this.src)" onerror="this.style.display='none';">
                                            <div class="text-muted">
                                                <div class="fw-semibold text-success"><i class="bi bi-patch-check-fill me-1"></i>Receipt Verified</div>
                                                <div style="font-size: 0.75rem;">Click receipt to enlarge</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="revCodMeta" class="d-flex justify-content-between d-none">
                                        <span class="text-secondary">Payable Upon Delivery:</span>
                                        <span class="fw-bold text-dark" id="revCodAmount"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ORDER CONFIRMATION REMINDER -->
                        <div class="alert alert-warning border-warning shadow-sm mb-3 py-2.5" role="alert">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-exclamation-triangle-fill fs-5 text-warning-emphasis flex-shrink-0 mt-0.5"></i>
                                <div style="font-size: 0.8rem;">
                                    <div class="fw-bold text-dark mb-1">Order Confirmation Reminder</div>
                                    <p class="mb-0 text-dark">
                                        Are you sure these are the items you want to purchase? Please review your order carefully. Once you place your order, you will no longer be able to edit the items, quantities, or order details. If you make a mistake after placing your order, you will need to submit a cancellation request and place a new order.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- FINAL CONFIRMATION FORM -->
                        <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) . htmlspecialchars($checkout_qs, ENT_QUOTES, 'UTF-8'); ?>" id="finalConfirmForm">
                            <input type="hidden" name="confirm_place_order" value="1">
                            <input type="hidden" name="confirm_token" id="modal_confirm_token" value="">

                            <div class="card border-0 shadow-sm p-3 bg-white">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="confirm_order_checkbox" value="1" id="finalConfirmCheckbox">
                                    <label class="form-check-label small fw-semibold text-dark" for="finalConfirmCheckbox">
                                        I have reviewed my order and confirmed that all items, quantities, and details are correct.
                                    </label>
                                </div>

                                <button type="submit" id="finalSubmitBtn" class="btn btn-success w-100 py-2 fw-semibold mb-2" disabled>
                                    <i class="bi bi-check2-circle me-1"></i> Confirm and Place Order
                                </button>

                                <div class="d-flex flex-column flex-sm-row gap-2">
                                    <a href="<?= htmlspecialchars($checkout_source !== 'buynow' ? 'cart.php' : $buyNowEditUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm w-100 fw-semibold">
                                        <i class="bi bi-cart me-1"></i> Back to Edit Cart
                                    </a>
                                    <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold" data-bs-dismiss="modal">
                                        <i class="bi bi-pencil-square me-1"></i> Edit Checkout Details
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QR ENLARGE MODAL (LIGHT THEME) -->
<div class="modal fade" id="qrEnlargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg bg-white" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="qrModalTitle">
                    <i class="bi bi-qr-code text-success"></i> Payment QR Code
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <img id="qrModalImg" src="" alt="QR Code" class="img-fluid rounded-3 bg-white p-2 border shadow-sm" style="max-height: 480px; width: auto;">
                <div class="mt-3 small text-muted" id="qrModalSubtitle">
                    Scan using your mobile e-wallet app to pay.
                </div>
            </div>
            <div class="modal-footer border-top py-2 justify-content-between bg-light">
                <span class="small text-dark" id="qrModalAccountInfo"></span>
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- REVIEW RECEIPT PREVIEW MODAL -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg bg-white" style="border-radius: 16px;">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-dark"><i class="bi bi-receipt me-2 text-primary"></i>Uploaded Payment Receipt</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3">
                <img id="receiptPreviewImg" src="" alt="Payment Receipt" class="img-fluid rounded border shadow-sm" style="max-height: 75vh;">
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
const grandTotal = <?php echo json_encode((float)$grand_total); ?>;
const CHECKOUT_ACTION_URL = <?php echo json_encode($_SERVER['PHP_SELF'] . $checkout_qs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const HAS_CHECKOUT_DRAFT = <?php echo $has_saved_draft ? 'true' : 'false'; ?>;
const CAN_CHECKOUT_STEP1 = <?php echo (!$min_order_unmet && $liveHydration['can_checkout']) ? 'true' : 'false'; ?>;
const OPEN_REVIEW_ON_LOAD = <?php echo $open_review_on_load ? 'true' : 'false'; ?>;
const HAS_STAGED_PROOF = <?php echo ($staged_proof_rel !== '') ? 'true' : 'false'; ?>;
const STAGED_PROOF_METHOD = <?php echo json_encode((string)($checkout_draft['payment_method'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const STAGED_PROOF_AMOUNT = <?php echo json_encode((float)($checkout_draft['paid_amount'] ?? 0)); ?>;
const STAGED_PROOF_REF = <?php echo json_encode((string)($checkout_draft['ref_no'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let currentReviewCanFinalize = false;
let currentTempObjectUrl = null;

function openReceiptPreviewModal(imgSrc) {
    const imgEl = document.getElementById('receiptPreviewImg');
    const modalEl = document.getElementById('receiptPreviewModal');
    if (imgEl && modalEl) {
        imgEl.src = imgSrc;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function canReuseStagedProofForSelection() {
    if (!HAS_STAGED_PROOF) return false;
    const proofInput = document.getElementById('proof_of_payment_input');
    if (proofInput && proofInput.files && proofInput.files.length > 0) return false;
    const selectedRadio = document.querySelector('input[name="payment_method"]:checked');
    const method = selectedRadio ? selectedRadio.value : '';
    if (!method || method !== STAGED_PROOF_METHOD) return false;
    return Math.abs(getRequiredPaymentAmount() - STAGED_PROOF_AMOUNT) <= 0.05;
}

function getRequiredPaymentAmount() {
    if (grandTotal >= 5000) {
        const schemeRadio = document.querySelector('input[name="payment_scheme"]:checked');
        if (schemeRadio && schemeRadio.value === '50_percent') {
            return grandTotal * 0.5;
        }
    }
    return grandTotal;
}

function copyToClipboard(text, btnElement) {
    if (!navigator.clipboard) {
        fallbackCopy(text, btnElement);
        return;
    }
    navigator.clipboard.writeText(text).then(() => {
        showCopyFeedback(btnElement);
    }).catch(err => {
        fallbackCopy(text, btnElement);
    });
}

function fallbackCopy(text, btnElement) {
    const tempInput = document.createElement('input');
    tempInput.value = text;
    document.body.appendChild(tempInput);
    tempInput.select();
    try {
        document.execCommand('copy');
        showCopyFeedback(btnElement);
    } catch (e) {
        console.error('Copy failed', e);
    }
    document.body.removeChild(tempInput);
}

function showCopyFeedback(btnElement) {
    if (!btnElement) return;
    const origHtml = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="bi bi-check2 text-success fs-5"></i>';
    setTimeout(() => {
        btnElement.innerHTML = origHtml;
    }, 1800);
}

function openQrEnlargeModal(type) {
    const modalEl = document.getElementById('qrEnlargeModal');
    if (!modalEl) return;
    const titleEl = document.getElementById('qrModalTitle');
    const imgEl = document.getElementById('qrModalImg');
    const infoEl = document.getElementById('qrModalAccountInfo');

    if (type === 'GCash') {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-qr-code text-success"></i> GCash Official QR Code';
        if (imgEl) imgEl.src = '../assets/qr/gcash.jpg';
        if (infoEl) infoEl.innerHTML = 'Account: <b>Ronil Taipan</b> (09073595023)';
    } else {
        if (titleEl) titleEl.innerHTML = '<i class="bi bi-qr-code text-info"></i> Maya Official QR Code';
        if (imgEl) imgEl.src = '../assets/qr/maya.jpg';
        if (infoEl) infoEl.innerHTML = 'Account: <b>Melanie Taipan</b> (09682770376)';
    }

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

function togglePaymentFields() {
    const selectedRadio = document.querySelector('input[name="payment_method"]:checked');
    if (!selectedRadio) return;
    
    const selectedMethod = selectedRadio.value;
    const onlineFields = document.getElementById('online_payment_fields');
    const codMessage = document.getElementById('cod_message');
    const optionBox = document.getElementById('payment_option_box');
    const proofInput = document.getElementById('proof_of_payment_input');
    const gcashCard = document.getElementById('gcash_details_card');
    const mayaCard = document.getElementById('maya_details_card');

    if (selectedMethod === 'GCash' || selectedMethod === 'Maya') {
        if (onlineFields) onlineFields.classList.remove('d-none');
        if (codMessage) codMessage.classList.add('d-none');
        if (optionBox) optionBox.classList.remove('d-none');

        if (selectedMethod === 'GCash') {
            if (gcashCard) gcashCard.classList.remove('d-none');
            if (mayaCard) mayaCard.classList.add('d-none');
        } else {
            if (gcashCard) gcashCard.classList.add('d-none');
            if (mayaCard) mayaCard.classList.remove('d-none');
        }

        if (proofInput && proofInput.files && proofInput.files.length > 0) {
            proofInput.required = true;
            processReceiptOCR(proofInput);
        } else if (canReuseStagedProofForSelection()) {
            if (proofInput) proofInput.required = false;
            ocrValidationState = { scanning: false, passed: true, reasonTitle: '', reasonMessage: '' };
            const statusBox = document.getElementById('ocr_status');
            const statusText = document.getElementById('ocr_status_text');
            if (statusBox && statusText) {
                statusBox.classList.remove('d-none', 'alert-info', 'alert-warning', 'alert-danger');
                statusBox.classList.add('alert-success');
                statusText.innerHTML = `<div class="fw-bold text-success"><i class="bi bi-check-circle-fill me-1"></i>Previously Uploaded Receipt Ready (Ref: ${escapeOcrHtml(STAGED_PROOF_REF)})</div><div class="small">Upload a new image only if you want to replace your existing receipt.</div>`;
            }
        } else {
            if (proofInput) proofInput.required = true;
            const statusBox = document.getElementById('ocr_status');
            if (statusBox && (!proofInput || !proofInput.files || proofInput.files.length === 0)) {
                statusBox.classList.add('d-none');
            }
            if (typeof resetOcrValidationState === 'function') resetOcrValidationState();
        }
    } else if (selectedMethod === 'Cash on Delivery (COD)') {
        if (onlineFields) onlineFields.classList.add('d-none');
        if (codMessage) codMessage.classList.remove('d-none');
        if (optionBox) optionBox.classList.add('d-none');
        if (proofInput) proofInput.required = false;
        if (gcashCard) gcashCard.classList.add('d-none');
        if (mayaCard) mayaCard.classList.add('d-none');
        
        document.getElementById('ref_no_input').value = "";
        document.getElementById('paid_amount_input').value = "";
        document.getElementById('receiptdt_input').value = "";
        const isoEl = document.getElementById('receipt_iso_input');
        if (isoEl) isoEl.value = "";
        document.getElementById('sent_to_input').value = "";
        const statusBox = document.getElementById('ocr_status');
        if (statusBox) statusBox.classList.add('d-none');
        if (typeof resetOcrValidationState === 'function') resetOcrValidationState();
    }
}

function updatePaymentDisplay() {
    const schemeRadio = document.querySelector('input[name="payment_scheme"]:checked');
    if (!schemeRadio) return;

    const scheme = schemeRadio.value;
    const labelScheme = document.getElementById('label_scheme');
    const dueText = document.getElementById('label_due_text');
    const displayDue = document.getElementById('display_due_amount');

    if (scheme === '50_percent') {
        let half = grandTotal * 0.5;
        if (labelScheme) labelScheme.innerText = "50% Downpayment";
        if (dueText) dueText.innerText = "Amount to Pay Now:";
        if (displayDue) displayDue.innerText = "₱" + half.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    } else {
        if (labelScheme) labelScheme.innerText = "Full Payment";
        if (dueText) dueText.innerText = "Total Amount to Pay:";
        if (displayDue) displayDue.innerText = "₱" + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    const proofInput = document.getElementById('proof_of_payment_input');
    if (proofInput && proofInput.files.length > 0) {
        processReceiptOCR(proofInput);
    } else {
        togglePaymentFields();
    }
}

function toggleSubmitButton() {
    const checkbox = document.getElementById("termsCheckbox");
    const submitBtn = document.getElementById("submitBtn");
    if (checkbox && submitBtn) {
        submitBtn.disabled = (!checkbox.checked || !CAN_CHECKOUT_STEP1);
    }
}

// Helper: Extract Exact Payment Amount from OCR Text (avoids fees and balances)
function extractExactPaymentAmount(text, requiredAmount) {
    if (!text) return '';
    const cleanText = text.replace(/\r/g, '');
    const lines = cleanText.split('\n').map(l => l.trim()).filter(l => l.length > 0);

    const isIgnoredLine = (line) => {
        return /(?:fee|service\s*fee|convenience\s*fee|balance|available\s*balance|remaining\s*balance|credit|discount)/i.test(line);
    };

    const cleanNum = (str) => {
        return str.replace(/,/g, '').replace(/\s+/g, '').replace(/\.\s+/g, '.').trim();
    };

    const amtPattern = /(?:PHP|₱)?\s*([0-9]{1,3}(?:[,\s][0-9]{3})*(?:\.\s*[0-9]{2})|[0-9]+(?:\.\s*[0-9]{2}))/gi;
    let candidates = [];

    // Pass 1: Labeled amount lines (e.g. "Total Amount Sent", "Amount Sent", "Amount Paid", "Payment Amount", "Amount", "Sent")
    const labelRegex = /(?:Total\s*Amount\s*(?:Sent|Paid)?|Amount\s*(?:Sent|Paid)?|Sent\s*Amount|Payment\s*Amount|Total\s*Paid|Amount|Sent|Total)\b/i;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (isIgnoredLine(line)) continue;

        if (labelRegex.test(line)) {
            // Check current line
            let matches = [...line.matchAll(amtPattern)];
            for (let m of matches) {
                let val = parseFloat(cleanNum(m[1]));
                if (!isNaN(val) && val > 0) {
                    candidates.push({ val: val, priority: 1, line: line });
                }
            }

            // Check next line
            if (i + 1 < lines.length && !isIgnoredLine(lines[i + 1])) {
                let nextMatches = [...lines[i + 1].matchAll(amtPattern)];
                for (let m of nextMatches) {
                    let val = parseFloat(cleanNum(m[1]));
                    if (!isNaN(val) && val > 0) {
                        candidates.push({ val: val, priority: 2, line: lines[i + 1] });
                    }
                }
            }
        }
    }

    // Pass 2: Lines with Currency symbols PHP / ₱ that are NOT fee lines
    for (let line of lines) {
        if (isIgnoredLine(line)) continue;
        if (/(?:PHP|₱)/i.test(line)) {
            let matches = [...line.matchAll(amtPattern)];
            for (let m of matches) {
                let val = parseFloat(cleanNum(m[1]));
                if (!isNaN(val) && val > 0) {
                    candidates.push({ val: val, priority: 3, line: line });
                }
            }
        }
    }

    // Pass 3: All other monetary values on non-fee lines
    for (let line of lines) {
        if (isIgnoredLine(line)) continue;
        let matches = [...line.matchAll(amtPattern)];
        for (let m of matches) {
            let val = parseFloat(cleanNum(m[1]));
            if (!isNaN(val) && val > 0) {
                candidates.push({ val: val, priority: 4, line: line });
            }
        }
    }

    if (candidates.length === 0) return '';

    // If any candidate matches requiredAmount exactly (tolerance 0.05)
    for (let c of candidates) {
        if (Math.abs(c.val - requiredAmount) <= 0.05) {
            return c.val.toFixed(2);
        }
    }

    // Filter out trivial amounts (fees <= 25 if required > 50)
    let filtered = candidates.filter(c => {
        if (requiredAmount > 50 && c.val <= 25) return false;
        return true;
    });

    if (filtered.length > 0) {
        filtered.sort((a, b) => a.priority - b.priority);
        return filtered[0].val.toFixed(2);
    }

    return candidates[0].val.toFixed(2);
}

// Helper: Extract Reference Number across GCash, Maya, and Bank receipts
function extractReferenceNumber(text) {
    if (!text) return '';
    const cleanText = text.replace(/\r/g, '');
    const lines = cleanText.split('\n').map(l => l.trim()).filter(l => l.length > 0);

    let phoneDigits = [];
    for (let line of lines) {
        let pMatches = line.match(/(?:\+?63\s*9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|09\d{2}[\s\-]?\d{3}[\s\-]?\d{4})/g);
        if (pMatches) {
            for (let pm of pMatches) {
                phoneDigits.push(pm.replace(/\D/g, ''));
            }
        }
    }

    const isPhone = (str) => {
        let digits = str.replace(/\D/g, '');
        if (phoneDigits.includes(digits)) return true;
        if (digits.length === 11 && digits.startsWith('09')) return true;
        if (digits.length === 12 && digits.startsWith('639')) return true;
        return false;
    };

    const refLabelRegex = /(?:Ref(?:\.|erence)?\s*(?:No\.?|ID|Num(?:ber)?|Code)?|Transaction\s*(?:ID|No\.?|Number)?|Trace\s*(?:No\.?|Number)?|InstaPay\s*Ref(?:erence)?|Confirmation\s*(?:No\.?|ID|Number)?)\s*:?/i;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (refLabelRegex.test(line)) {
            let afterLabel = line.replace(refLabelRegex, '').trim();
            afterLabel = afterLabel
                .replace(/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[\s,\.]*\d{1,2}[\s,\.]*\d{2,4}.*$/i, '')
                .replace(/\b\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{2,4}.*$/i, '')
                .replace(/\b\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?.*$/i, '')
                .trim();
            let cleanedCandidate = afterLabel.replace(/[^A-Za-z0-9]/g, '');
            if (cleanedCandidate.length >= 8 && cleanedCandidate.length <= 30 && !isPhone(afterLabel)) {
                return cleanedCandidate;
            }

            for (let j = 1; j <= 2 && (i + j) < lines.length; j++) {
                let nextLine = lines[i + j].trim();
                let cleanNext = nextLine
                    .replace(/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[\s,\.]*\d{1,2}[\s,\.]*\d{2,4}.*$/i, '')
                    .replace(/\b\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{2,4}.*$/i, '')
                    .trim();
                let nextClean = cleanNext.replace(/[^A-Za-z0-9]/g, '');
                if (/^(?:Amount|PHP|₱|Date|Time|Sent|To|From|Fee)/i.test(nextLine)) break;
                if (nextClean.length >= 8 && nextClean.length <= 30 && !isPhone(cleanNext)) {
                    return nextClean;
                }
            }
        }
    }

    // GCash 13-digit pattern (e.g. 0023 123 456789 or 9023 123 456789)
    const gcash13Pattern = /\b([0-9][0-9\s\-]{11,16}[0-9])\b/g;
    let m;
    while ((m = gcash13Pattern.exec(cleanText)) !== null) {
        let digits = m[1].replace(/\D/g, '');
        if (digits.length === 13 && !isPhone(digits)) {
            return digits;
        }
    }

    // Maya 12-char pattern (e.g. ABCD 1234 EFGH)
    const mayaPattern = /\b([A-Za-z0-9]{4}[\s\-][A-Za-z0-9]{4}[\s\-][A-Za-z0-9]{4})\b/g;
    while ((m = mayaPattern.exec(cleanText)) !== null) {
        let candidate = m[1].replace(/[\s\-]/g, '');
        if (candidate.length === 12 && !isPhone(candidate)) {
            return candidate.toUpperCase();
        }
    }

    // Standalone 9 to 16 digit numbers (bank confirmation / InstaPay / trace)
    const standaloneNumberPattern = /\b\d{9,16}\b/g;
    while ((m = standaloneNumberPattern.exec(cleanText)) !== null) {
        let num = m[0].trim();
        if (!isPhone(num) && !num.startsWith('09') && !num.startsWith('639')) {
            return num;
        }
    }

    return '';
}

function escapeOcrHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

async function callGeminiReceiptOCR(file, mode) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 42000);
    try {
        const formData = new FormData();
        formData.append('receipt', file);
        formData.append('mode', mode || 'payment');

        const response = await fetch('../ocr-receipt.php', {
            method: 'POST',
            body: formData,
            signal: controller.signal
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || !payload.success || !payload.data) {
            throw new Error((payload && payload.error) ? payload.error : ('HTTP ' + response.status));
        }
        return payload;
    } finally {
        clearTimeout(timer);
    }
}

let ocrValidationState = {
    scanning: false,
    passed: false,
    reasonTitle: 'Payment Receipt Verification Required',
    reasonMessage: 'Please upload a valid payment receipt screenshot matching your selected payment method, amount, recipient, and today\'s date.'
};

function resetOcrValidationState(reasonTitle, reasonMessage) {
    ocrValidationState = {
        scanning: false,
        passed: false,
        reasonTitle: reasonTitle || 'Payment Receipt Verification Required',
        reasonMessage: reasonMessage || 'Please upload a valid payment receipt screenshot before submitting your order.'
    };
}

function getExpectedRecipientInfo() {
    const selectedRadio = document.querySelector('input[name="payment_method"]:checked');
    const method = selectedRadio ? selectedRadio.value : '';
    if (method === 'GCash') return { method: 'GCash', expectedNumber: '09073595023' };
    if (method === 'Maya') return { method: 'Maya', expectedNumber: '09682770376' };
    return { method: method, expectedNumber: '' };
}

function normalizePhMobileClient(raw) {
    let digits = String(raw || '').replace(/\D/g, '');
    if (digits.startsWith('63') && digits.length === 12) {
        digits = '0' + digits.substring(2);
    } else if (digits.length === 10 && digits.startsWith('9')) {
        digits = '0' + digits;
    }
    return (digits.length === 11 && digits.startsWith('09')) ? digits : '';
}

function findMaskedRecipientMatch(textOrValue, officialNumber, isDirectField = false) {
    if (!textOrValue || !officialNumber) return '';
    const str = String(textOrValue).trim();
    if (!str) return '';

    const fullNorm = normalizePhMobileClient(str);
    if (fullNorm) {
        return (fullNorm === officialNumber) ? officialNumber : '';
    }

    const last4 = officialNumber.slice(-4);
    const escapedLast4 = last4.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    // Direct sent_to field from Gemini (e.g. "09......5023", "+63 9•• ••• 5023", "••••5023")
    if (isDirectField) {
        const digitsOnly = str.replace(/\D/g, '');
        if (digitsOnly.length >= 4 && digitsOnly.length <= 9 && digitsOnly.endsWith(last4)) {
            const prefix = digitsOnly.slice(0, -4);
            if (!prefix || prefix === '09' || prefix === '639' || prefix === '9' || officialNumber.startsWith(prefix)) {
                return str;
            }
        }
    }

    // Masked patterns inside full OCR raw_text (e.g. "+63 9•• ••• 5023", "09......5023", "•••• 5023")
    const maskedPatterns = [
        new RegExp('(?:\\+?63[\\s\\-]*9|09)[.*•●◦·∙°oOxX*\\-\\s_]{1,18}' + escapedLast4 + '\\b', 'i'),
        new RegExp('[.*•●◦·∙°oOxX*]{2,}[\\s\\-]*' + escapedLast4 + '\\b', 'i')
    ];
    for (const pat of maskedPatterns) {
        const m = str.match(pat);
        if (m && m[0]) {
            return m[0].trim();
        }
    }

    return '';
}

async function checkDuplicateRefClient(refNo) {
    if (!refNo) return false;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
        const formData = new FormData();
        formData.append('mode', 'check_ref');
        formData.append('ref_no', refNo);

        const response = await fetch('../ocr-receipt.php', {
            method: 'POST',
            body: formData,
            signal: controller.signal
        });
        const payload = await response.json().catch(() => null);
        return Boolean(response.ok && payload && payload.success && payload.is_duplicate_ref);
    } catch (e) {
        console.warn("Duplicate ref check warning:", e);
        return false;
    } finally {
        clearTimeout(timer);
    }
}

function parseManilaDateCandidate(rawStr) {
    if (!rawStr) return null;
    const str = String(rawStr)
        .replace(/(\d+)(st|nd|rd|th)\b/gi, '$1')
        .replace(/[•|]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (!str) return null;

    const months = {
        jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3,
        apr: 4, april: 4, may: 5, jun: 6, june: 6,
        jul: 7, july: 7, aug: 8, august: 8, sep: 9, sept: 9, september: 9,
        oct: 10, october: 10, nov: 11, november: 11, dec: 12, december: 12
    };

    const makeManila = (year, month, day, hour, minute, hasTime) => {
        if (year < 2020 || year > 2100 || month < 1 || month > 12 || day < 1 || day > 31) return null;
        const h = hasTime ? (hour || 0) : 0;
        const min = hasTime ? (minute || 0) : 0;
        if (h < 0 || h > 23 || min < 0 || min > 59) return null;

        // Validate real calendar day without local timezone drift
        const probe = new Date(Date.UTC(year, month - 1, day));
        if (probe.getUTCFullYear() !== year || (probe.getUTCMonth() + 1) !== month || probe.getUTCDate() !== day) {
            return null;
        }

        const pad2 = n => String(n).padStart(2, '0');
        const dateStr = `${year}-${pad2(month)}-${pad2(day)}`;
        const iso = hasTime ? `${dateStr} ${pad2(h)}:${pad2(min)}` : dateStr;
        const epochMs = Date.UTC(year, month - 1, day, h - 8, min, 0);
        return { dateStr, iso, hasTime, epochMs };
    };

    const parseTimePart = (tStr) => {
        if (!tStr) return { hasTime: false, hour: 0, minute: 0 };
        let tm = tStr.match(/(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
        if (!tm) {
            tm = tStr.match(/\b(\d{1,2})(\d{2})\s*(AM|PM)\b/i);
        }
        if (!tm) return { hasTime: false, hour: 0, minute: 0 };
        let h = parseInt(tm[1], 10);
        const min = parseInt(tm[2], 10);
        const mer = (tm[3] || '').toUpperCase();
        if (mer === 'PM' && h < 12) h += 12;
        if (mer === 'AM' && h === 12) h = 0;
        return { hasTime: true, hour: h, minute: min };
    };

    // 1. ISO format YYYY-MM-DD [HH:MM]
    let m = str.match(/(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:AM|PM))?))?/i);
    if (m) {
        const tp = parseTimePart(m[4]);
        const res = makeManila(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10), tp.hour, tp.minute, tp.hasTime);
        if (res) return res;
    }

    // 2. Month DD, YYYY [HH:MM AM/PM] (supports glued OCR spacing like Nov19,2026)
    m = str.match(/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s*\d{1,2}\s*,?\s*\d{4}(?:[\s,]*(?:\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:AM|PM))?|\d{3,4}\s*(?:AM|PM)))?/i);
    if (m) {
        const mp = m[0].match(/([A-Za-z]+)\s*(\d{1,2})\s*,?\s*(\d{4})(?:[\s,]*(.+))?/i);
        if (mp && months[mp[1].toLowerCase()]) {
            const tp = parseTimePart(mp[4]);
            const res = makeManila(parseInt(mp[3], 10), months[mp[1].toLowerCase()], parseInt(mp[2], 10), tp.hour, tp.minute, tp.hasTime);
            if (res) return res;
        }
    }

    // 3. DD Month YYYY [HH:MM AM/PM]
    m = str.match(/\b(\d{1,2})\s+([A-Za-z]+),?\s+(\d{4})(?:[\s,]+(\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?))?/i);
    if (m && months[m[2].toLowerCase()]) {
        const tp = parseTimePart(m[4]);
        const res = makeManila(parseInt(m[3], 10), months[m[2].toLowerCase()], parseInt(m[1], 10), tp.hour, tp.minute, tp.hasTime);
        if (res) return res;
    }

    // 4. Numeric MM/DD/YYYY or DD/MM/YYYY [HH:MM AM/PM]
    m = str.match(/\b(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2,4})(?:[\s,]+(\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?))?/i);
    if (m) {
        let p1 = parseInt(m[1], 10);
        let p2 = parseInt(m[2], 10);
        let yr = parseInt(m[3], 10);
        if (yr < 100) yr += 2000;
        let mo = p1;
        let dy = p2;
        if (p1 > 12 && p2 <= 12) {
            dy = p1;
            mo = p2;
        }
        const tp = parseTimePart(m[4]);
        const res = makeManila(yr, mo, dy, tp.hour, tp.minute, tp.hasTime);
        if (res) return res;
    }

    return null;
}

function validateReceiptDateManila(receiptIso, receiptDt, rawText) {
    const todayManila = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    }).format(new Date());

    let parsed = parseManilaDateCandidate(receiptIso) || parseManilaDateCandidate(receiptDt);
    if (!parsed && rawText) {
        const lines = rawText.split(/\n+/);
        for (const line of lines) {
            parsed = parseManilaDateCandidate(line);
            if (parsed) break;
        }
    }

    if (!parsed) {
        return {
            valid: false,
            iso: '',
            reason: 'We could not verify a valid transaction date from your receipt. Please upload a clear screenshot showing today\'s transaction date.'
        };
    }

    if (parsed.dateStr !== todayManila) {
        return {
            valid: false,
            iso: parsed.iso,
            reason: `This receipt is dated ${parsed.dateStr}, which is not today (${todayManila}, Philippine time). Only receipts from today are accepted.`
        };
    }

    if (parsed.hasTime && parsed.epochMs > (Date.now() + 5 * 60 * 1000)) {
        return {
            valid: false,
            iso: parsed.iso,
            reason: 'The receipt timestamp appears to be in the future. Please upload a valid transaction receipt from today.'
        };
    }

    return { valid: true, iso: parsed.iso, reason: '' };
}

function parseReceiptTextFields(text, requiredAmount, expectedRecipient) {
    const officialNumbers = ['09073595023', '09682770376'];
    let detectedSentTo = '';
    let isExpectedRecipient = false;

    const phoneRegex = /(?:\+?63\s*9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|09\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|\b9\d{9}\b)/g;
    let allPhoneMatches = (text || '').match(phoneRegex) || [];

    let candidatePhones = [];
    for (let rawP of allPhoneMatches) {
        let digits = normalizePhMobileClient(rawP);
        if (digits && !candidatePhones.includes(digits)) {
            candidatePhones.push(digits);
        }
    }

    const cleanDigitsOnly = (text || '').replace(/\D/g, '');
    if (expectedRecipient) {
        const exp63 = '63' + expectedRecipient.substring(1);
        if (
            candidatePhones.includes(expectedRecipient) ||
            cleanDigitsOnly.includes(expectedRecipient) ||
            cleanDigitsOnly.includes(exp63)
        ) {
            detectedSentTo = expectedRecipient;
            isExpectedRecipient = true;
        } else {
            const maskedMatch = findMaskedRecipientMatch(text || '', expectedRecipient, false);
            if (maskedMatch) {
                detectedSentTo = maskedMatch;
                isExpectedRecipient = true;
            }
        }
    }

    if (!isExpectedRecipient) {
        for (let offNum of officialNumbers) {
            if (offNum === expectedRecipient) continue;
            const off63 = '63' + offNum.substring(1);
            if (
                candidatePhones.includes(offNum) ||
                cleanDigitsOnly.includes(offNum) ||
                cleanDigitsOnly.includes(off63)
            ) {
                detectedSentTo = offNum;
                break;
            }
            const maskedOther = findMaskedRecipientMatch(text || '', offNum, false);
            if (maskedOther) {
                detectedSentTo = maskedOther;
                break;
            }
        }
    }

    if (!detectedSentTo && candidatePhones.length > 0) {
        detectedSentTo = candidatePhones[0];
    }

    let detectedRef = extractReferenceNumber(text);
    let detectedAmount = extractExactPaymentAmount(text, requiredAmount);

    let detectedDateTime = '';
    let dateTimeMatch = (text || '').match(/(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s*\d{1,2}\s*,?\s*\d{4}(?:[\s,]*(?:\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:AM|PM))?|\d{3,4}\s*(?:AM|PM)))?/i);
    if (!dateTimeMatch) {
        dateTimeMatch = (text || '').match(/\b\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*,?\s+\d{4}(?:[\s,]*(?:\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:AM|PM))?|\d{3,4}\s*(?:AM|PM)))?/i);
    }
    if (!dateTimeMatch) {
        dateTimeMatch = (text || '').match(/(?:\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{2,4})\s*(?:\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?)?/i);
    }
    if (dateTimeMatch) {
        detectedDateTime = dateTimeMatch[0].trim();
    }

    return { detectedRef, detectedAmount, detectedDateTime, detectedSentTo, isExpectedRecipient };
}

async function processReceiptOCR(fileInput) {
    const file = fileInput.files[0];
    if (!file) {
        resetOcrValidationState();
        return;
    }

    const statusBox = document.getElementById('ocr_status');
    const statusText = document.getElementById('ocr_status_text');
    const refInput = document.getElementById('ref_no_input');
    const paidInput = document.getElementById('paid_amount_input');
    const dtInput = document.getElementById('receiptdt_input');
    const isoInput = document.getElementById('receipt_iso_input');
    const sentToInput = document.getElementById('sent_to_input');

    refInput.value = "";
    paidInput.value = "";
    dtInput.value = "";
    if (isoInput) isoInput.value = "";
    sentToInput.value = "";

    ocrValidationState = {
        scanning: true,
        passed: false,
        reasonTitle: 'Receipt Verification In Progress',
        reasonMessage: 'Please wait while we finish verifying your payment receipt.'
    };

    statusBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
    statusBox.classList.add('alert-info');
    statusText.innerHTML = '<div class="spinner-border spinner-border-sm me-1" role="status"></div> Scanning receipt with AI OCR...';

    const renderDetailsSummary = (ref, amt, reqAmt, sentTo, dt) => {
        let h = '<hr class="my-2"><div class="small text-dark">';
        if (ref) h += `<div>Ref No: <b>${escapeOcrHtml(ref)}</b></div>`;
        if (amt) {
            let fAmt = parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            let fReq = reqAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            h += `<div>Detected Payment Amount: <b>₱${fAmt}</b> (Required: ₱${fReq})</div>`;
        }
        if (sentTo) h += `<div>Sent To Number: <b>${escapeOcrHtml(sentTo)}</b></div>`;
        if (dt) h += `<div>Date & Time: <b>${escapeOcrHtml(dt)}</b></div>`;
        h += '</div>';
        return h;
    };

    const failValidation = (alertClass, iconClass, title, message, detailsHtml) => {
        ocrValidationState = {
            scanning: false,
            passed: false,
            reasonTitle: title,
            reasonMessage: message
        };
        statusBox.classList.remove('alert-info', 'alert-success', 'alert-danger', 'alert-warning');
        statusBox.classList.add(alertClass);
        statusText.innerHTML = `
            <div class="fw-bold fs-6 mb-1 ${alertClass === 'alert-danger' ? 'text-danger' : 'text-dark'}">
                <i class="bi ${iconClass} me-1"></i>${escapeOcrHtml(title)}
            </div>
            <div>${escapeOcrHtml(message)}</div>
            ${detailsHtml || ''}
        `;
    };

    try {
        const { method: selectedMethod, expectedNumber: expectedRecipient } = getExpectedRecipientInfo();
        const requiredAmount = getRequiredPaymentAmount();

        let text = '';
        let detectedRef = '';
        let detectedAmount = '';
        let detectedDateTime = '';
        let detectedReceiptIso = '';
        let detectedSentTo = '';
        let isExpectedRecipient = false;
        let isReceipt = true;
        let isDuplicateRef = false;

        try {
            const geminiRes = await callGeminiReceiptOCR(file, 'payment');
            const gData = geminiRes.data || {};
            text = (gData.raw_text || '').trim();
            isReceipt = gData.is_receipt !== false;

            const parsedFromText = parseReceiptTextFields(text, requiredAmount, expectedRecipient);
            detectedRef = (gData.ref_no || '').trim() || parsedFromText.detectedRef;
            detectedAmount = (gData.amount || '').trim() || parsedFromText.detectedAmount;
            detectedDateTime = (gData.receipt_dt || '').trim() || parsedFromText.detectedDateTime;
            detectedReceiptIso = (gData.receipt_iso || '').trim();

            if (gData.ref_no) {
                isDuplicateRef = Boolean(gData.is_duplicate_ref);
            } else if (detectedRef) {
                isDuplicateRef = await checkDuplicateRefClient(detectedRef);
            }

            const gRawSent = (gData.sent_to || '').trim();
            const gSentDigits = normalizePhMobileClient(gRawSent);
            if (gSentDigits) {
                detectedSentTo = gSentDigits;
                isExpectedRecipient = Boolean(expectedRecipient && gSentDigits === expectedRecipient);
            } else if (expectedRecipient && findMaskedRecipientMatch(gRawSent, expectedRecipient, true)) {
                detectedSentTo = gRawSent;
                isExpectedRecipient = true;
            } else if (parsedFromText.isExpectedRecipient) {
                detectedSentTo = parsedFromText.detectedSentTo || gRawSent;
                isExpectedRecipient = true;
            } else {
                detectedSentTo = parsedFromText.detectedSentTo || gRawSent;
                isExpectedRecipient = false;
            }

            console.log("Gemini OCR Extracted (" + (geminiRes.model || 'gemini') + "):", gData);
        } catch (geminiErr) {
            console.warn("Gemini OCR unavailable, falling back to Tesseract.js:", geminiErr);
            statusText.innerHTML = '<div class="spinner-border spinner-border-sm me-1" role="status"></div> Scanning receipt image via fallback OCR...';

            const result = await Tesseract.recognize(
                file,
                'eng',
                {
                    logger: m => {
                        if (m.status === 'recognizing text') {
                            let progress = Math.round(m.progress * 100);
                            statusText.innerHTML = `<div class="spinner-border spinner-border-sm me-1" role="status"></div> Reading text: ${progress}%`;
                        }
                    }
                }
            );

            text = result.data.text || '';
            console.log("Tesseract Fallback OCR Raw Text:\n", text);

            const parsedFromText = parseReceiptTextFields(text, requiredAmount, expectedRecipient);
            detectedRef = parsedFromText.detectedRef;
            detectedAmount = parsedFromText.detectedAmount;
            detectedDateTime = parsedFromText.detectedDateTime;
            detectedSentTo = parsedFromText.detectedSentTo;
            isExpectedRecipient = parsedFromText.isExpectedRecipient;

            if (detectedRef) {
                isDuplicateRef = await checkDuplicateRefClient(detectedRef);
            }
        }

        const dateCheck = validateReceiptDateManila(detectedReceiptIso, detectedDateTime, text);
        if (!detectedDateTime && dateCheck.iso) {
            detectedDateTime = dateCheck.iso;
        }

        // Populate hidden inputs with detected values so backend receives them
        if (detectedRef) refInput.value = detectedRef;
        if (detectedAmount) paidInput.value = detectedAmount;
        if (detectedDateTime) dtInput.value = detectedDateTime;
        if (isoInput && dateCheck.iso) isoInput.value = dateCheck.iso;
        if (detectedSentTo) sentToInput.value = detectedSentTo;

        const detailsHtml = renderDetailsSummary(detectedRef, detectedAmount, requiredAmount, detectedSentTo, detectedDateTime);
        const hasReadableReceiptContent = (text.trim().length >= 15) || Boolean(detectedRef || detectedAmount || detectedSentTo);

        // Strict Blocking Validation Checks
        if (!isReceipt || (!hasReadableReceiptContent && !detectedSentTo)) {
            failValidation(
                'alert-danger',
                'bi-question-circle-fill text-danger',
                'Unable to Read Receipt',
                'We could not verify a valid payment receipt from this image. Please upload a clear screenshot before placing your order.',
                detailsHtml
            );
        } else if (!expectedRecipient || !isExpectedRecipient) {
            const methodLabel = selectedMethod || 'selected payment method';
            const expectedText = expectedRecipient ? `our official ${methodLabel} number (${expectedRecipient})` : 'our official account number';
            failValidation(
                'alert-danger',
                'bi-exclamation-diamond-fill text-danger',
                'Incorrect Recipient Number',
                `You selected ${methodLabel}, so the receipt must show payment sent strictly to ${expectedText}. Please upload the correct receipt to continue.`,
                detailsHtml
            );
        } else if (!detectedRef) {
            failValidation(
                'alert-danger',
                'bi-file-earmark-x-fill text-danger',
                'Missing Reference Number',
                'No valid transaction reference number was detected on the receipt. Please upload a clearer receipt screenshot that shows the reference number.',
                detailsHtml
            );
        } else if (isDuplicateRef) {
            failValidation(
                'alert-danger',
                'bi-shield-exclamation text-danger',
                'Duplicate Reference Number',
                'This reference number has already been used for a previous order. Reusing receipts or reference numbers is not allowed.',
                detailsHtml
            );
        } else if (!dateCheck.valid) {
            failValidation(
                'alert-danger',
                'bi-calendar-x-fill text-danger',
                'Invalid Receipt Date & Time',
                dateCheck.reason,
                detailsHtml
            );
        } else if (!detectedAmount || parseFloat(detectedAmount) <= 0) {
            failValidation(
                'alert-danger',
                'bi-exclamation-circle-fill text-danger',
                'Unable to Read Payment Amount',
                'We could not read the payment amount from your receipt. Please upload a clearer screenshot to continue.',
                detailsHtml
            );
        } else {
            const parsedAmount = parseFloat(detectedAmount);
            if (Math.abs(parsedAmount - requiredAmount) > 0.05) {
                const reqFormatted = requiredAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                failValidation(
                    'alert-danger',
                    'bi-exclamation-triangle-fill text-danger',
                    'Amount Mismatch Detected',
                    `The detected payment amount does not match the required amount of ₱${reqFormatted}. Both underpayments and overpayments cannot proceed.`,
                    detailsHtml
                );
            } else {
                // ALL CHECKS PASSED
                ocrValidationState = {
                    scanning: false,
                    passed: true,
                    reasonTitle: '',
                    reasonMessage: ''
                };
                statusBox.classList.remove('alert-info', 'alert-warning', 'alert-danger');
                statusBox.classList.add('alert-success');
                statusText.innerHTML = `
                    <div class="fw-bold fs-6 mb-1 text-success">
                        <i class="bi bi-check-circle-fill me-1"></i>Payment Receipt Verified
                    </div>
                    <div>Your receipt matches the required amount, recipient (${escapeOcrHtml(selectedMethod)}), today's date, and a unique reference number. You may now confirm and place your order.</div>
                    ${detailsHtml}
                `;
            }
        }

    } catch (error) {
        console.error("OCR Reading Error:", error);
        failValidation(
            'alert-danger',
            'bi-question-circle-fill text-danger',
            'Unable to Read Receipt',
            'An error occurred while reading your receipt. Please upload a clearer screenshot before placing your order.',
            ''
        );
    }
}

// ================= SAVED ADDRESS AUTO-FILL LOGIC =================
const SAVED_ADDRESSES = <?php echo json_encode($saved_addresses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function selectSavedAddress(addressId) {
    if (!Array.isArray(SAVED_ADDRESSES)) return;

    // Update radio check state
    const radio = document.getElementById('addr_radio_' + addressId);
    if (radio) {
        radio.checked = true;
    }

    // Update visual card highlighting
    document.querySelectorAll('.saved-address-card').forEach(card => card.classList.remove('selected'));
    if (radio) {
        const card = radio.closest('.saved-address-card');
        if (card) card.classList.add('selected');
    }

    const addr = SAVED_ADDRESSES.find(a => parseInt(a.id) === parseInt(addressId));
    if (!addr) return;

    const fields = [
        { id: 'input_fullname', val: addr.first_name || '' },
        { id: 'input_surname', val: addr.last_name || '' },
        { id: 'input_contact_number', val: addr.contact_number || '' },
        { id: 'input_tel_no', val: addr.tel_no || '' },
        { id: 'input_full_address', val: addr.full_address || '' },
        { id: 'input_landmark', val: addr.landmark || '' }
    ];

    fields.forEach(f => {
        const el = document.getElementById(f.id);
        if (el) {
            el.value = f.val;
            el.classList.remove('address-autofill-flash');
            void el.offsetWidth; // trigger reflow for animation
            el.classList.add('address-autofill-flash');
        }
    });
}

function formatPeso(val) {
    return '₱' + Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function populateAndShowReviewModal(data) {
    currentReviewCanFinalize = Boolean(data.can_finalize);

    const tokenInput = document.getElementById('modal_confirm_token');
    if (tokenInput) tokenInput.value = data.confirm_token || '';

    const finalCheckbox = document.getElementById('finalConfirmCheckbox');
    const finalBtn = document.getElementById('finalSubmitBtn');
    if (finalCheckbox) {
        finalCheckbox.checked = false;
        finalCheckbox.disabled = !currentReviewCanFinalize;
    }
    if (finalBtn) {
        finalBtn.disabled = true;
        finalBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm and Place Order';
    }

    // Price change notice
    const pcAlert = document.getElementById('reviewPriceChangesAlert');
    const pcList = document.getElementById('reviewPriceChangesList');
    if (pcAlert && pcList) {
        if (Array.isArray(data.price_changes) && data.price_changes.length > 0) {
            pcList.innerHTML = data.price_changes.map(msg => `<li>${escapeOcrHtml(msg)}</li>`).join('');
            pcAlert.classList.remove('d-none');
        } else {
            pcAlert.classList.add('d-none');
        }
    }

    // Blocking issues notice
    const issAlert = document.getElementById('reviewIssuesAlert');
    const issList = document.getElementById('reviewIssuesList');
    if (issAlert && issList) {
        if (Array.isArray(data.issues) && data.issues.length > 0) {
            issList.innerHTML = data.issues.map(msg => `<li>${escapeOcrHtml(msg)}</li>`).join('');
            issAlert.classList.remove('d-none');
        } else {
            issAlert.classList.add('d-none');
        }
    }

    // Items table
    const tbody = document.getElementById('reviewItemsTbody');
    if (tbody) {
        tbody.innerHTML = (data.items || []).map(item => `
            <tr>
                <td class="ps-3">
                    <div class="d-flex align-items-center gap-2">
                        <img src="${escapeOcrHtml(item.image_src || '../assets/images/no-image.png')}"
                             alt="${escapeOcrHtml(item.product_title)}"
                             class="rounded border flex-shrink-0"
                             style="width: 44px; height: 44px; object-fit: cover;"
                             onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                        <div>
                            <div class="fw-bold text-dark">${escapeOcrHtml(item.product_title)}</div>
                            ${item.variant_name ? `<span class="badge bg-light text-dark border mt-1" style="font-size: 0.7rem;">Variant: ${escapeOcrHtml(item.variant_name)}</span>` : ''}
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-secondary-subtle text-dark border" style="font-size: 0.72rem;">${escapeOcrHtml(item.unit)}</span></td>
                <td class="text-center fw-bold">${Number(item.qty)}</td>
                <td class="text-end">${formatPeso(item.price)}</td>
                <td class="text-end fw-bold text-primary pe-3">${formatPeso(item.subtotal)}</td>
            </tr>
        `).join('');
    }

    const totalQty = Number(data.total_qty || 0);
    const qtyBadge = document.getElementById('reviewTotalQtyBadge');
    if (qtyBadge) qtyBadge.textContent = `Total Qty: ${totalQty} item${totalQty === 1 ? '' : 's'}`;
    const footerQty = document.getElementById('reviewFooterTotalQty');
    if (footerQty) footerQty.textContent = String(totalQty);

    const pay = data.payment || {};
    const footerScheme = document.getElementById('reviewFooterScheme');
    if (footerScheme) footerScheme.textContent = pay.scheme_label || 'Full Payment';
    const footerTotal = document.getElementById('reviewFooterGrandTotal');
    if (footerTotal) footerTotal.textContent = formatPeso(data.grand_total);

    const downRow = document.getElementById('reviewFooterDownpaymentRow');
    const downAmt = document.getElementById('reviewFooterAmountDue');
    if (downRow && downAmt) {
        if (Number(data.grand_total || 0) >= 5000 && pay.scheme === '50_percent') {
            downAmt.textContent = formatPeso(pay.amount_due);
            downRow.classList.remove('d-none');
        } else {
            downRow.classList.add('d-none');
        }
    }

    // Customer & Delivery info
    const cust = data.customer || {};
    const deliv = data.delivery || {};
    document.getElementById('revCustName').textContent = cust.full_name || '';
    document.getElementById('revCustContact').textContent = cust.contact_number || '';
    const telWrap = document.getElementById('revCustTelWrap');
    if (telWrap) {
        telWrap.classList.toggle('d-none', !cust.tel_no);
        if (cust.tel_no) document.getElementById('revCustTel').textContent = cust.tel_no;
    }
    const emailWrap = document.getElementById('revCustEmailWrap');
    if (emailWrap) {
        emailWrap.classList.toggle('d-none', !cust.email);
        if (cust.email) document.getElementById('revCustEmail').textContent = cust.email;
    }

    document.getElementById('revDelivAddr').textContent = deliv.full_address || '';
    document.getElementById('revDelivLandmark').textContent = deliv.landmark || '';

    // Payment details
    document.getElementById('revPayMethod').textContent = pay.method || '';
    document.getElementById('revPayScheme').textContent = pay.scheme_label || 'Full Payment';
    const onlineMeta = document.getElementById('revOnlinePayMeta');
    const codMeta = document.getElementById('revCodMeta');
    if (pay.method === 'GCash' || pay.method === 'Maya') {
        if (onlineMeta) onlineMeta.classList.remove('d-none');
        if (codMeta) codMeta.classList.add('d-none');
        document.getElementById('revPayPaid').textContent = formatPeso(pay.paid_amount);
        document.getElementById('revPayRef').textContent = pay.ref_no || '-';

        const dtWrap = document.getElementById('revPayDtWrap');
        if (dtWrap) {
            dtWrap.classList.toggle('d-none', !pay.receiptdt);
            if (pay.receiptdt) document.getElementById('revPayDt').textContent = pay.receiptdt;
        }

        const proofWrap = document.getElementById('revProofWrap');
        const proofThumb = document.getElementById('revProofThumb');
        const proofInput = document.getElementById('proof_of_payment_input');
        let previewUrl = pay.temp_proof_src || '';
        if (proofInput && proofInput.files && proofInput.files[0]) {
            if (currentTempObjectUrl) URL.revokeObjectURL(currentTempObjectUrl);
            currentTempObjectUrl = URL.createObjectURL(proofInput.files[0]);
            previewUrl = currentTempObjectUrl;
        }
        if (proofWrap && proofThumb) {
            if (previewUrl) {
                proofThumb.src = previewUrl;
                proofThumb.style.display = '';
                proofWrap.classList.remove('d-none');
            } else {
                proofWrap.classList.add('d-none');
            }
        }
    } else {
        if (onlineMeta) onlineMeta.classList.add('d-none');
        if (codMeta) codMeta.classList.remove('d-none');
        document.getElementById('revCodAmount').textContent = formatPeso(data.grand_total);
    }

    const modalEl = document.getElementById('reviewOrderModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('checkoutStepForm')) {
        updatePaymentDisplay();
        togglePaymentFields();
        toggleSubmitButton();

        if (!HAS_CHECKOUT_DRAFT && Array.isArray(SAVED_ADDRESSES) && SAVED_ADDRESSES.length > 0) {
            const defaultAddr = SAVED_ADDRESSES.find(a => a.is_default === true || a.is_default === 't' || a.is_default == 1) || SAVED_ADDRESSES[0];
            if (defaultAddr) {
                selectSavedAddress(defaultAddr.id);
            }
        }
    }

    const finalCheckbox = document.getElementById('finalConfirmCheckbox');
    const finalBtn = document.getElementById('finalSubmitBtn');
    const finalForm = document.getElementById('finalConfirmForm');
    if (finalCheckbox && finalBtn) {
        finalBtn.disabled = !(finalCheckbox.checked && currentReviewCanFinalize);
        finalCheckbox.addEventListener('change', function() {
            finalBtn.disabled = !(finalCheckbox.checked && currentReviewCanFinalize);
        });
    }
    if (finalForm && finalBtn) {
        let isSubmittingFinal = false;
        finalForm.addEventListener('submit', function(e) {
            if (isSubmittingFinal || !finalCheckbox || !finalCheckbox.checked || !currentReviewCanFinalize) {
                e.preventDefault();
                return false;
            }
            isSubmittingFinal = true;
            // Hidden input name="confirm_place_order" stays submitted even when button is disabled for double-click lock
            finalBtn.disabled = true;
            finalBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Placing Order...';
        });
    }

    if (OPEN_REVIEW_ON_LOAD) {
        document.getElementById('checkoutStepForm')?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
});

let isPreparingReview = false;
document.getElementById('checkoutStepForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    if (isPreparingReview) return false;

    const selectedRadio = document.querySelector('input[name="payment_method"]:checked');
    if (selectedRadio && (selectedRadio.value === 'GCash' || selectedRadio.value === 'Maya')) {
        const proofInput = document.getElementById('proof_of_payment_input');
        const hasNewFile = Boolean(proofInput && proofInput.files && proofInput.files.length > 0);
        const reusingStaged = canReuseStagedProofForSelection();

        if (!hasNewFile && !reusingStaged) {
            showAppAlert("Please upload your proof of payment receipt before proceeding to review your order.", {
                title: 'Payment Proof Required',
                type: 'warning',
                onClose: function () {
                    proofInput?.focus();
                }
            });
            return false;
        }

        if (ocrValidationState.scanning) {
            showAppAlert("Your payment receipt is still being verified. Please wait a moment before proceeding.", {
                title: 'Verification In Progress',
                type: 'info'
            });
            return false;
        }

        if (!ocrValidationState.passed) {
            showAppAlert(
                ocrValidationState.reasonMessage || "Your payment receipt did not pass verification. Please upload a valid receipt screenshot.",
                {
                    title: ocrValidationState.reasonTitle || 'Receipt Verification Failed',
                    type: 'danger',
                    onClose: function () {
                        proofInput?.focus();
                    }
                }
            );
            return false;
        }
    }

    const contact = document.querySelector('input[name="contact_number"]').value;
    if (!contact.startsWith('09') || contact.length !== 11) {
        showAppAlert("Contact number must start with '09' and contain 11 digits total.", {
            title: 'Invalid Contact Number',
            type: 'warning',
            onClose: function () {
                document.querySelector('input[name="contact_number"]')?.focus();
            }
        });
        return false;
    }

    const submitBtn = document.getElementById('submitBtn');
    const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    isPreparingReview = true;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading Review...';
    }

    try {
        const formData = new FormData(this);
        formData.append('review_ajax', '1');
        const resp = await fetch(CHECKOUT_ACTION_URL, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data || !data.ok) {
            showAppAlert((data && data.message) ? data.message : 'Unable to prepare your order review. Please check your details.', {
                title: (data && data.title) ? data.title : 'Checkout Validation Error',
                type: (data && data.type) ? data.type : 'danger'
            });
            return false;
        }
        populateAndShowReviewModal(data);
    } catch (err) {
        console.error('Prepare review error:', err);
        showAppAlert('Unable to connect to the server to review your order. Please try again.', {
            title: 'Network Error',
            type: 'danger'
        });
    } finally {
        isPreparingReview = false;
        if (submitBtn) {
            submitBtn.innerHTML = origBtnHtml;
            toggleSubmitButton();
        }
    }
    return false;
});
</script>
<?php require_once __DIR__ . '/../includes/app_modal.php'; ?>
</body>
</html>