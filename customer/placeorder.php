<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google_drive.php';
require_once __DIR__ . '/../config/image.php';
require_once __DIR__ . '/../includes/shared_inventory_functions.php';

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
$items_to_process = [];

if ($checkout_source == 'buynow' && isset($_SESSION['buy_now'])) {
    $items_to_process = $_SESSION['buy_now'];
} elseif (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $items_to_process = $_SESSION['cart'];
} else {
    header("Location: dashboard.php");
    exit();
}

$grand_total = 0;
$total_qty = 0;
foreach ($items_to_process as $item) {
    $grand_total += (float)$item['price'] * (int)$item['qty'];
    $total_qty += (int)$item['qty'];
}

if (isset($_POST['place_order'])) {
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_scheme = trim($_POST['payment_scheme'] ?? 'full_payment');

    if ($grand_total >= 5000 && $payment_method === 'Cash on Delivery (COD)') {
        die("<script>alert('Error: COD is unavailable for orders ₱5,000 and above.'); history.back();</script>");
    }

    $fullname = trim($_POST['fullname']);
    $surname = trim($_POST['surname']);
    $contact_number = trim($_POST['contact_number']);
    $tel_no = !empty($_POST['tel_no']) ? trim($_POST['tel_no']) : null;
    
    // Automatic: Kukunin ang email ng customer na ginamit sa pag-login
    $email = !empty($customerEmail) ? $customerEmail : trim($_POST['email'] ?? '');
    if (empty($email) && $loggedInUserId > 0) {
        try {
            $stmt_u = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $stmt_u->execute([$loggedInUserId]);
            $email = trim((string) $stmt_u->fetchColumn());
        } catch (Throwable $e) {}
    }
    if (empty($email) && !empty($_SESSION['email'])) {
        $email = trim((string) $_SESSION['email']);
    }

    $full_address = trim($_POST['full_address']);
    $landmark = trim($_POST['landmark']);
    
    $cart_json = json_encode($items_to_process);

    $proof_of_payment = null;
    
    // Retrieve OCR Extracted Values
    $ref_no = !empty($_POST['ref_no']) ? substr(trim($_POST['ref_no']), 0, 50) : null;
    $receiptdt = !empty($_POST['receiptdt']) ? trim($_POST['receiptdt']) : null;
    $sent_to = !empty($_POST['sent_to']) ? trim($_POST['sent_to']) : null;
    $detected_paid_amount = !empty($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0;

    // ONLINE PAYMENTS VALIDATION (GCash / Maya)
    if (in_array($payment_method, ['GCash', 'Maya'])) {
        // Ensure customer uploaded a proof of payment screenshot
        if (empty($_FILES['proof_of_payment']['name'])) {
            die("<script>alert('Order Failed: Please upload your proof of payment receipt screenshot before submitting.'); history.back();</script>");
        }
        $paid_amount = $detected_paid_amount;
    } else {
        $paid_amount = 0;
    }

    // UPLOAD CUSTOMER PROOF OF PAYMENT DIRECTLY TO GOOGLE DRIVE / LOCAL FOLDER
    if (in_array($payment_method, ['GCash', 'Maya'])) {
        if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['proof_of_payment']['tmp_name'];
            $file_name = $_FILES['proof_of_payment']['name'];
            
            if (!empty($tmp) && file_exists($tmp)) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $file_mime_check = mime_content_type($tmp);

                if (in_array($file_mime_check, $allowed_types)) {
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $new_filename = "proof_" . time() . "_" . rand(1000, 9999) . "." . $ext;

                    // Dedicated Folder ID para sa Customer Receipt
                    $customerFolderId = "1K63T1f7Z2JucT5lgyBuNobLIRJZFYNno";

                    try {
                        if (function_exists('uploadProductImageToDrive')) {
                            $file_id = uploadProductImageToDrive($tmp, $new_filename, $customerFolderId);
                            if (!empty($file_id)) {
                                $proof_of_payment = "https://drive.google.com/uc?export=view&id=" . $file_id;
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("CUSTOMER PROOF GOOGLE DRIVE UPLOAD ERROR: " . $e->getMessage());
                    }

                    // Fallback to local uploads kung sakaling mag-fail ang Drive API
                    if (empty($proof_of_payment)) {
                        $target_dir = "../uploads/proofs/";
                        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                        
                        if (move_uploaded_file($tmp, $target_dir . $new_filename)) {
                            $proof_of_payment = "uploads/proofs/" . $new_filename;
                        }
                    }
                }
            }
        }
    }

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

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
            ':total_amount' => $grand_total,
            ':paid_amount' => $paid_amount,
            ':cart' => $cart_json,
            ':payment_method' => $payment_method,
            ':payment_scheme' => $payment_scheme,
            ':ref_no' => $ref_no,
            ':proof_of_payment' => $proof_of_payment,
            ':receiptdt' => $receiptdt,
            ':sent_to' => $sent_to
        ]);

        $order_id = $pdo->lastInsertId();

        // Insert order items & update stock
        foreach ($items_to_process as $item) {
            $qty = (int)$item['qty'];
            $price = (float)$item['price'];
            $product_id = (int)$item['id'];
            $name = $item['name'];
            $v_id = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $v_name = !empty($item['variant_name']) ? $item['variant_name'] : null;
            $subtotal = $price * $qty;

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
                    $stock_stmt = $pdo->prepare("UPDATE product_variants SET stock = GREATEST(stock - :qty, 0) WHERE id = :v_id");
                    $stock_stmt->execute([':qty' => $qty, ':v_id' => $v_id]);
                } else {
                    $stock_stmt = $pdo->prepare("UPDATE products SET stock = GREATEST(stock - :qty, 0) WHERE id = :product_id");
                    $stock_stmt->execute([':qty' => $qty, ':product_id' => $product_id]);
                }
            }
        }

        $pdo->commit();

        if ($checkout_source == 'buynow') { 
            unset($_SESSION['buy_now']); 
        } else { 
            unset($_SESSION['cart']); 
        }

        $_SESSION['message'] = "<b>Order Placed Successfully! </b><br>Your Order Number is: <span class='text-primary fw-bold'>#$order_id</span>";
        header("Location: dashboard.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Checkout error: " . $e->getMessage());
        
        echo "<div style='color:red; padding:20px; font-family:sans-serif;'>";
        echo "<h3>Checkout Processing Failed</h3>";
        echo "<p><b>Error Details:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><a href='javascript:history.back()'>Go back and try again</a></p>";
        echo "</div>";
        exit();
    }
}
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
                            <h6 class="mb-0 fw-bold small text-truncate"><?php echo htmlspecialchars($item['name']); ?></h6>
                            <small class="text-muted">Qty: <?php echo (int)$item['qty']; ?> × ₱<?php echo number_format((float)$item['price'], 2); ?></small>
                        </div>
                        <div class="text-end fw-bold small ms-2">₱<?php echo number_format((float)$item['price'] * (int)$item['qty'], 2); ?></div>
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
            <h4 class="mb-4 text-primary fw-bold"><i class="bi bi-bag-check-fill"></i> Delivery & Payment Details</h4>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . (isset($_GET['from']) ? '?from=' . htmlspecialchars($_GET['from']) : ''); ?>" method="POST" enctype="multipart/form-data">
                
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
                            <?php foreach ($saved_addresses as $sIndex => $sAddr): ?>
                                <div class="saved-address-card d-flex align-items-start gap-3 <?php echo (!empty($sAddr['is_default']) || $sIndex === 0) ? 'selected' : ''; ?>"
                                     onclick="selectSavedAddress(<?php echo (int) $sAddr['id']; ?>)">
                                    <div class="form-check mt-1">
                                        <input class="form-check-input saved-address-radio" type="radio" name="saved_address_id" 
                                               id="addr_radio_<?php echo (int) $sAddr['id']; ?>" 
                                               value="<?php echo (int) $sAddr['id']; ?>"
                                               <?php echo (!empty($sAddr['is_default']) || $sIndex === 0) ? 'checked' : ''; ?>>
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
                        pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                         <label class="form-label">Last Name</label>
                        <input type="text" name="surname" id="input_surname" class="form-control" 
                        pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" id="input_contact_number" class="form-control" placeholder="09XXXXXXXXX"
                        maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                    </div>
                    <div class="col-12 col-sm-6">
                       <label class="form-label">Telephone (Optional)</label>
                        <input type="text" name="tel_no" id="input_tel_no" class="form-control" 
                       oninput="this.value = this.value.replace(/[^0-9-]/g, '')">
                    </div>
                    <!-- Customer Email automatically bound to logged-in account -->
                    <input type="hidden" name="email" id="input_email" value="<?php echo htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-12">
                        <label class="form-label">Complete Delivery Address</label>
                        <textarea name="full_address" id="input_full_address" class="form-control" rows="2" required></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Landmark</label>
                        <input type="text" name="landmark" id="input_landmark" class="form-control" required>
                    </div>
                    
                    <!-- PAYMENT SECTION (GCASH & MAYA ONLY) -->
                    <div class="col-12">
                        <?php if ($grand_total >= 5000): ?>
                            <div id="payment_option_box" class="alert alert-info p-3">
                                <p class="fw-bold small text-danger mb-2">Note: Orders ₱5,000+ require payment scheme selection.</p>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_scheme" value="50_percent" checked id="opt1" onchange="updatePaymentDisplay()">
                                    <label class="form-check-label" for="opt1">50% Downpayment</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_scheme" value="full_payment" id="opt2" onchange="updatePaymentDisplay()">
                                    <label class="form-check-label" for="opt2">Full Payment</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <label class="form-label fw-bold">Mode of Payment</label>
                        <?php if ($grand_total < 5000): ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="Cash on Delivery (COD)" onclick="togglePaymentFields()" id="cod_radio" required><label class="form-check-label" for="cod_radio">Cash On Delivery</label></div>
                        <?php endif; ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="GCash" onclick="togglePaymentFields()" id="gcash_radio"><label class="form-check-label" for="gcash_radio">GCash</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="payment_method" value="Maya" onclick="togglePaymentFields()" id="maya_radio"><label class="form-check-label" for="maya_radio">Maya</label></div>
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
                        <input type="hidden" name="ref_no" id="ref_no_input">
                        <input type="hidden" name="paid_amount" id="paid_amount_input">
                        <input type="hidden" name="receiptdt" id="receiptdt_input">
                        <input type="hidden" name="sent_to" id="sent_to_input">

                        <!-- OCR Status Message Box -->
                        <div id="ocr_status" class="alert alert-info py-2 d-none small">
                            <span id="ocr_status_text">Scanning receipt image via OCR...</span>
                        </div>

                        <label class="form-label small fw-bold">Upload Proof of Payment (GCash / Maya / Bank Transfer Screenshot)</label>
                        <input type="file" name="proof_of_payment" id="proof_of_payment_input" class="form-control mb-1" accept=".jpg,.jpeg,.png,.webp" onchange="processReceiptOCR(this)">
                        <small class="text-muted d-block mb-2"><i>Note: Upload direct official screenshot from GCash, Maya, or Bank Transfer.(Jpeg, Png, Webp)</i></small>
                    </div>

                    <div class="col-12 form-check ps-4">
                        <input type="checkbox" class="form-check-input" id="termsCheckbox" onclick="toggleSubmitButton()">
                       <label class="form-check-label" for="termsCheckbox" style="font-size: 0.85rem;">
                           I have read and agree to the Terms and Conditions and Privacy Notice. <a href="tac.php" target="_blank" class="text-decoration-underline">Terms and Conditions</a> 
                        </label>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="place_order" id="submitBtn" class="btn btn-success w-100 py-2 mb-2" disabled>Confirm & Place Order</button>
                        <button type="button" class="btn btn-secondary w-100" onclick="window.location.href='cart.php';">Cancel</button>
                    </div>
                </div>
            </form>
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

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
const grandTotal = <?php echo $grand_total; ?>;

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
        if (proofInput) proofInput.required = true;

        if (selectedMethod === 'GCash') {
            if (gcashCard) gcashCard.classList.remove('d-none');
            if (mayaCard) mayaCard.classList.add('d-none');
        } else {
            if (gcashCard) gcashCard.classList.add('d-none');
            if (mayaCard) mayaCard.classList.remove('d-none');
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
        document.getElementById('sent_to_input').value = "";
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
    }
}

function toggleSubmitButton() {
    const checkbox = document.getElementById("termsCheckbox");
    const submitBtn = document.getElementById("submitBtn");
    if (checkbox && submitBtn) {
        submitBtn.disabled = !checkbox.checked;
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
            let cleanedCandidate = afterLabel.replace(/[^A-Za-z0-9]/g, '');
            if (cleanedCandidate.length >= 8 && cleanedCandidate.length <= 30 && !isPhone(afterLabel)) {
                return cleanedCandidate;
            }

            for (let j = 1; j <= 2 && (i + j) < lines.length; j++) {
                let nextLine = lines[i + j].trim();
                let nextClean = nextLine.replace(/[^A-Za-z0-9]/g, '');
                if (/^(?:Amount|PHP|₱|Date|Time|Sent|To|From|Fee)/i.test(nextLine)) break;
                if (nextClean.length >= 8 && nextClean.length <= 30 && !isPhone(nextLine)) {
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

async function processReceiptOCR(fileInput) {
    const file = fileInput.files[0];
    if (!file) return;

    const statusBox = document.getElementById('ocr_status');
    const statusText = document.getElementById('ocr_status_text');
    const refInput = document.getElementById('ref_no_input');
    const paidInput = document.getElementById('paid_amount_input');
    const dtInput = document.getElementById('receiptdt_input');
    const sentToInput = document.getElementById('sent_to_input');

    refInput.value = "";
    paidInput.value = "";
    dtInput.value = "";
    sentToInput.value = "";

    statusBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
    statusBox.classList.add('alert-info');
    statusText.innerHTML = '<div class="spinner-border spinner-border-sm me-1" role="status"></div> Scanning receipt image via OCR...';

    const renderDetailsSummary = (ref, amt, reqAmt, sentTo, dt) => {
        let h = '<hr class="my-2"><div class="small text-dark">';
        if (ref) h += `<div>Ref No: <b>${ref}</b></div>`;
        if (amt) {
            let fAmt = parseFloat(amt).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            let fReq = reqAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            h += `<div>Detected Payment Amount: <b>₱${fAmt}</b> (Required: ₱${fReq})</div>`;
        }
        if (sentTo) h += `<div>Sent To Number: <b>${sentTo}</b></div>`;
        if (dt) h += `<div>Date & Time: <b>${dt}</b></div>`;
        h += '</div>';
        return h;
    };

    try {
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

        const text = result.data.text || '';
        console.log("OCR Extracted Raw Text:\n", text);

        const lowerText = text.toLowerCase();
        const requiredAmount = getRequiredPaymentAmount();

        // 1. Detect Phone Number (ONLY the 2 official numbers are accepted)
        // Official GCash Number: 09073595023
        // Official Maya Number:  09682770376
        const officialNumbers = ['09073595023', '09682770376'];

        let detectedSentTo = '';
        let isOfficialRecipient = false;

        // Collect all potential phone numbers from lines and text
        const phoneRegex = /(?:\+?63\s*9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|09\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|\b9\d{9}\b)/g;
        let allPhoneMatches = text.match(phoneRegex) || [];

        let candidatePhones = [];
        for (let rawP of allPhoneMatches) {
            let digits = rawP.replace(/\D/g, '');
            if (digits.startsWith('63') && digits.length === 12) {
                digits = '0' + digits.substring(2);
            } else if (digits.length === 10 && digits.startsWith('9')) {
                digits = '0' + digits;
            }
            if (digits.length === 11 && digits.startsWith('09')) {
                if (!candidatePhones.includes(digits)) {
                    candidatePhones.push(digits);
                }
            }
        }

        // Check if any candidate matches an official number
        for (let p of candidatePhones) {
            if (officialNumbers.includes(p)) {
                detectedSentTo = p;
                isOfficialRecipient = true;
                break;
            }
        }

        // Also check if text literally contains the continuous digits of official numbers
        if (!isOfficialRecipient) {
            const cleanDigitsOnly = text.replace(/\D/g, '');
            if (cleanDigitsOnly.includes('09073595023') || cleanDigitsOnly.includes('639073595023')) {
                detectedSentTo = '09073595023';
                isOfficialRecipient = true;
            } else if (cleanDigitsOnly.includes('09682770376') || cleanDigitsOnly.includes('639682770376')) {
                detectedSentTo = '09682770376';
                isOfficialRecipient = true;
            }
        }

        // If neither official number is detected, store the first non-official number found (number lang, huwag name)
        if (!isOfficialRecipient && candidatePhones.length > 0) {
            detectedSentTo = candidatePhones[0];
        }

        // 2. Extract Reference Number
        let detectedRef = extractReferenceNumber(text);

        // 3. Extract Exact Payment Amount
        let detectedAmount = extractExactPaymentAmount(text, requiredAmount);

        // 4. Extract Date & Time
        let detectedDateTime = '';
        let dateTimeMatch = text.match(/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}(?:\s*,?\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?)?\b/i);
        if (!dateTimeMatch) {
            dateTimeMatch = text.match(/(?:\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{2,4})\s*(?:\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?)?/i);
        }
        if (dateTimeMatch) {
            detectedDateTime = dateTimeMatch[0].trim();
        }

        // Populate hidden inputs with detected values so backend receives them
        if (detectedRef) refInput.value = detectedRef;
        if (detectedAmount) paidInput.value = detectedAmount;
        if (detectedDateTime) dtInput.value = detectedDateTime;
        if (detectedSentTo) sentToInput.value = detectedSentTo;

        // 5. Evaluate Recipient Number:
        // Kapag wala dyan sa dalawang number ang nadetect, lalabas na ang Incorrect Recipient Number note
        let isIncorrectRecipient = !isOfficialRecipient;

        // 6. Evaluate the 4 Validation States:
        statusBox.classList.remove('alert-info', 'alert-success', 'alert-danger', 'alert-warning');

        if (isIncorrectRecipient) {
            // STATE 4: Incorrect Recipient Number
            statusBox.classList.add('alert-warning');
            statusText.innerHTML = `
                <div class="fw-bold fs-6 mb-1 text-dark">
                    <i class="bi bi-exclamation-diamond-fill text-warning me-1"></i>Incorrect Recipient Number
                </div>
                <div>Your payment appears to have been sent to a different account number instead of the official account number displayed in our QR code. Payments sent to unauthorized account numbers cannot be automatically verified and will require manual review by our staff before your order can be approved.</div>
                ${renderDetailsSummary(detectedRef, detectedAmount, requiredAmount, detectedSentTo, detectedDateTime)}
            `;
        } else if (!detectedAmount || parseFloat(detectedAmount) <= 0 || text.trim().length < 15) {
            // STATE 3: Unable to Read Receipt
            statusBox.classList.add('alert-warning');
            statusText.innerHTML = `
                <div class="fw-bold fs-6 mb-1 text-dark">
                    <i class="bi bi-question-circle-fill text-warning me-1"></i>Unable to Read Receipt
                </div>
                <div>We couldn't accurately read your receipt. Please make sure the uploaded image is clear and readable. Your payment will require manual verification. Our staff will also check whether the payment was sent to the correct account number displayed in our official QR code.</div>
                ${renderDetailsSummary(detectedRef, detectedAmount, requiredAmount, detectedSentTo, detectedDateTime)}
            `;
        } else {
            const parsedAmount = parseFloat(detectedAmount);
            if (Math.abs(parsedAmount - requiredAmount) <= 0.05) {
                // STATE 1: Exact Amount Detected
                statusBox.classList.add('alert-success');
                statusText.innerHTML = `
                    <div class="fw-bold fs-6 mb-1 text-success">
                        <i class="bi bi-check-circle-fill me-1"></i>Exact Amount Detected
                    </div>
                    <div>Your payment amount matches the expected amount. Please ensure that your payment was sent to the correct account number displayed in our official QR code. Our staff will verify your payment before approving your order.</div>
                    ${renderDetailsSummary(detectedRef, detectedAmount, requiredAmount, detectedSentTo, detectedDateTime)}
                `;
            } else {
                // STATE 2: Amount Mismatch Detected
                statusBox.classList.add('alert-warning');
                statusText.innerHTML = `
                    <div class="fw-bold fs-6 mb-1 text-dark">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>Amount Mismatch Detected
                    </div>
                    <div>The detected payment amount does not match the expected amount. You may still submit your order, but our staff will manually review your payment before approval. Please also ensure that the payment was sent to the correct account number displayed in our official QR code.</div>
                    ${renderDetailsSummary(detectedRef, detectedAmount, requiredAmount, detectedSentTo, detectedDateTime)}
                `;
            }
        }

    } catch (error) {
        console.error("OCR Reading Error:", error);
        statusBox.classList.remove('alert-info', 'alert-success', 'alert-danger');
        statusBox.classList.add('alert-warning');
        statusText.innerHTML = `
            <div class="fw-bold fs-6 mb-1 text-dark">
                <i class="bi bi-question-circle-fill text-warning me-1"></i>Unable to Read Receipt
            </div>
            <div>We couldn't accurately read your receipt. Please make sure the uploaded image is clear and readable. Your payment will require manual verification. Our staff will also check whether the payment was sent to the correct account number displayed in our official QR code.</div>
        `;
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

document.addEventListener('DOMContentLoaded', function() {
    togglePaymentFields();

    if (Array.isArray(SAVED_ADDRESSES) && SAVED_ADDRESSES.length > 0) {
        // Find default or first saved address
        const defaultAddr = SAVED_ADDRESSES.find(a => a.is_default === true || a.is_default === 't' || a.is_default == 1) || SAVED_ADDRESSES[0];
        if (defaultAddr) {
            selectSavedAddress(defaultAddr.id);
        }
    }
});

document.querySelector('form').addEventListener('submit', function(e) {
    const selectedRadio = document.querySelector('input[name="payment_method"]:checked');
    if (selectedRadio && (selectedRadio.value === 'GCash' || selectedRadio.value === 'Maya')) {
        const proofInput = document.getElementById('proof_of_payment_input');
        if (!proofInput || !proofInput.files || proofInput.files.length === 0) {
            alert("Please upload your proof of payment receipt before submitting your order.");
            e.preventDefault();
            return false;
        }
    }

    const contact = document.querySelector('input[name="contact_number"]').value;
    if (!contact.startsWith('09') || contact.length !== 11) {
        alert("Contact number must start with '09' with 11 digits total");
        e.preventDefault();
        return false;
    }
});
</script>
</body>
</html>