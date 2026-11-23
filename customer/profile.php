<?php
session_start();
require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['user_id'])) {
    header("Location: ../login.php?auth=1");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$message = "";
$msg_type = "";

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
    error_log("customer_addresses table check notice: " . $e->getMessage());
}

// Fetch current user from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: ../login.php?auth=1");
        exit();
    }
} catch (PDOException $e) {
    error_log("Customer profile load error: " . $e->getMessage());
    die("Database error occurred.");
}

$customerName = trim((string) ($user['fullname'] ?? $user['username']));
$profileImage = $user['profile_image'] ?? '';

// Active Tab ('profile' or 'address')
$currentTab = isset($_GET['tab']) ? strtolower(trim($_GET['tab'])) : 'profile';
if (!in_array($currentTab, ['profile', 'address'], true)) {
    $currentTab = 'profile';
}

// Handle Profile Information Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $phone_number = trim((string) ($_POST['phone_number'] ?? ''));
    $profile_image = $user['profile_image'] ?? '';

    // Remove photo
    if (isset($_POST['remove_profile_image']) && $_POST['remove_profile_image'] == '1') {
        $profile_image = '';
    }
    // Upload new photo (Base64)
    elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['profile_image']['tmp_name'];
        if (!empty($tmp) && file_exists($tmp)) {
            $check_image = getimagesize($tmp);
            if ($check_image !== false) {
                $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($ext, $allowed, true)) {
                    $imageData = file_get_contents($tmp);
                    $profile_image = 'data:image/' . $ext . ';base64,' . base64_encode($imageData);
                } else {
                    $message = "Only JPG, JPEG, PNG, and WEBP formats are allowed.";
                    $msg_type = "danger";
                }
            }
        }
    }

    if ($msg_type !== "danger") {
        if ($fullname === '') {
            $message = "Full name cannot be empty.";
            $msg_type = "danger";
        } else {
            try {
                $update_stmt = $pdo->prepare("
                    UPDATE users
                    SET fullname = :fullname, phone_number = :phone_number, profile_image = :profile_image
                    WHERE id = :id
                ");
                $update_stmt->execute([
                    ':fullname'      => $fullname,
                    ':phone_number'  => $phone_number,
                    ':profile_image' => $profile_image,
                    ':id'            => $user_id
                ]);

                $_SESSION['full_name'] = $fullname;
                $_SESSION['profile_image'] = $profile_image;
                $user['fullname'] = $fullname;
                $user['phone_number'] = $phone_number;
                $user['profile_image'] = $profile_image;
                $customerName = $fullname;
                $profileImage = $profile_image;

                $message = "Profile updated successfully!";
                $msg_type = "success";
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $message = "Failed to update profile.";
                $msg_type = "danger";
            }
        }
    }
    $currentTab = 'profile';
}

// Handle Address Module Actions (Add, Edit, Delete, Set Default)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_action'])) {
    $action = trim($_POST['address_action']);

    if ($action === 'save_address') {
        $first_name = trim((string) ($_POST['first_name'] ?? ''));
        $last_name = trim((string) ($_POST['last_name'] ?? ''));
        $contact_number = trim((string) ($_POST['contact_number'] ?? ''));
        $tel_no = trim((string) ($_POST['tel_no'] ?? ''));
        $full_address = trim((string) ($_POST['full_address'] ?? ''));
        $landmark = trim((string) ($_POST['landmark'] ?? ''));
        $is_default = !empty($_POST['is_default']) ? true : false;
        $address_id = !empty($_POST['address_id']) ? (int) $_POST['address_id'] : 0;

        // FIXED: Strictly locked to the customer's logged-in account email
        $fixed_email = trim((string) ($user['email'] ?? ''));

        if ($first_name === '' || $last_name === '' || $contact_number === '' || $full_address === '' || $landmark === '') {
            $message = "Please complete all required address fields.";
            $msg_type = "danger";
        } elseif (strlen($contact_number) < 10) {
            $message = "Please enter a valid contact number (at least 10-11 digits).";
            $msg_type = "danger";
        } else {
            try {
                // If customer has no existing addresses, force first one to default
                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE user_id = :uid");
                $count_stmt->execute([':uid' => $user_id]);
                $addrCount = (int) $count_stmt->fetchColumn();
                if ($addrCount === 0) {
                    $is_default = true;
                }

                // If marked as default, clear existing default for this user
                if ($is_default) {
                    $reset_stmt = $pdo->prepare("UPDATE customer_addresses SET is_default = FALSE WHERE user_id = :uid");
                    $reset_stmt->execute([':uid' => $user_id]);
                }

                if ($address_id > 0) {
                    // Update existing address
                    $upd_stmt = $pdo->prepare("
                        UPDATE customer_addresses
                        SET first_name = :first_name,
                            last_name = :last_name,
                            contact_number = :contact_number,
                            tel_no = :tel_no,
                            email = :email,
                            full_address = :full_address,
                            landmark = :landmark,
                            is_default = :is_default
                        WHERE id = :id AND user_id = :uid
                    ");
                    $upd_stmt->execute([
                        ':first_name'     => $first_name,
                        ':last_name'      => $last_name,
                        ':contact_number' => $contact_number,
                        ':tel_no'         => $tel_no !== '' ? $tel_no : null,
                        ':email'          => $fixed_email,
                        ':full_address'   => $full_address,
                        ':landmark'       => $landmark,
                        ':is_default'     => $is_default ? 'true' : 'false',
                        ':id'             => $address_id,
                        ':uid'            => $user_id
                    ]);
                    $message = "Address updated successfully!";
                    $msg_type = "success";
                } else {
                    // Insert new address
                    $ins_stmt = $pdo->prepare("
                        INSERT INTO customer_addresses 
                        (user_id, first_name, last_name, contact_number, tel_no, email, full_address, landmark, is_default)
                        VALUES 
                        (:uid, :first_name, :last_name, :contact_number, :tel_no, :email, :full_address, :landmark, :is_default)
                    ");
                    $ins_stmt->execute([
                        ':uid'            => $user_id,
                        ':first_name'     => $first_name,
                        ':last_name'      => $last_name,
                        ':contact_number' => $contact_number,
                        ':tel_no'         => $tel_no !== '' ? $tel_no : null,
                        ':email'          => $fixed_email,
                        ':full_address'   => $full_address,
                        ':landmark'       => $landmark,
                        ':is_default'     => $is_default ? 'true' : 'false'
                    ]);
                    $message = "New address added successfully!";
                    $msg_type = "success";
                }
            } catch (PDOException $e) {
                error_log("Address save error: " . $e->getMessage());
                $message = "Failed to save address.";
                $msg_type = "danger";
            }
        }
        $currentTab = 'address';
    } elseif ($action === 'delete_address') {
        $del_id = (int) ($_POST['address_id'] ?? 0);
        try {
            // Check if deleted address was default
            $chk = $pdo->prepare("SELECT is_default FROM customer_addresses WHERE id = :id AND user_id = :uid");
            $chk->execute([':id' => $del_id, ':uid' => $user_id]);
            $wasDefault = (bool) $chk->fetchColumn();

            $del_stmt = $pdo->prepare("DELETE FROM customer_addresses WHERE id = :id AND user_id = :uid");
            $del_stmt->execute([':id' => $del_id, ':uid' => $user_id]);

            // If it was default, promote latest remaining address to default
            if ($wasDefault) {
                $rem_stmt = $pdo->prepare("
                    UPDATE customer_addresses 
                    SET is_default = TRUE 
                    WHERE id = (SELECT id FROM customer_addresses WHERE user_id = :uid ORDER BY id DESC LIMIT 1)
                ");
                $rem_stmt->execute([':uid' => $user_id]);
            }

            $message = "Address removed successfully.";
            $msg_type = "success";
        } catch (PDOException $e) {
            error_log("Address delete error: " . $e->getMessage());
            $message = "Failed to delete address.";
            $msg_type = "danger";
        }
        $currentTab = 'address';
    } elseif ($action === 'set_default') {
        $set_id = (int) ($_POST['address_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE customer_addresses SET is_default = FALSE WHERE user_id = :uid")->execute([':uid' => $user_id]);
            $pdo->prepare("UPDATE customer_addresses SET is_default = TRUE WHERE id = :id AND user_id = :uid")->execute([':id' => $set_id, ':uid' => $user_id]);
            $message = "Default address updated!";
            $msg_type = "success";
        } catch (PDOException $e) {
            error_log("Set default address error: " . $e->getMessage());
            $message = "Failed to set default address.";
            $msg_type = "danger";
        }
        $currentTab = 'address';
    }
}

// Fetch all saved addresses for this customer
$userAddresses = [];
try {
    $addr_stmt = $pdo->prepare("SELECT * FROM customer_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC");
    $addr_stmt->execute([':uid' => $user_id]);
    $userAddresses = $addr_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Customer addresses fetch error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - R. Taipan Hardware</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f6f7fb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .navbar-custom { background: #051c3c; padding: 14px 0; }
        
        /* Shopee-style Sidebar */
        .sidebar-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #eef0f4;
            padding: 24px 20px;
        }

        .user-avatar-sm {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            color: #4b5563;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-menu-link:hover {
            background-color: #f1f5f9;
            color: #051c3c;
        }

        .sidebar-menu-link.active {
            background-color: #051c3c;
            color: #fff;
            font-weight: 600;
        }

        .sidebar-menu-link.active i {
            color: #ffc107;
        }

        .profile-content-card {
            border: 1px solid #eef0f4;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            background: #fff;
        }

        .avatar-lg {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #dee2e6;
        }

        .btn-navy {
            background: #051c3c;
            color: #fff;
            font-weight: 600;
        }

        .btn-navy:hover {
            background: #0b3267;
            color: #fff;
        }

        /* Address Item Styles */
        .address-card-item {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #fff;
        }

        .address-card-item:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .address-card-item.is-default-card {
            border-color: #051c3c;
            background-color: #fcfdfd;
        }

        .badge-default-addr {
            background-color: #e0e7ff;
            color: #2b3a8f;
            border: 1px solid #c7d2fe;
            font-weight: 600;
            font-size: 0.72rem;
            padding: 4px 8px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="../login.php">
                <img src="../assets/logo.jpg" style="width:36px; height:36px; border-radius:50%; border:2px solid white;">
                <strong>R. Taipan Hardware</strong>
            </a>
            <div class="ms-auto d-flex align-items-center gap-3">
                <a href="cart.php" class="btn btn-warning btn-sm position-relative fw-semibold">
                    🛒 Cart
                    <?php
                    $cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
                    if ($cartCount > 0) {
                        echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">' . $cartCount . '</span>';
                    }
                    ?>
                </a>
                <a href="../login.php" class="btn btn-outline-light btn-sm fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Back to Shop
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container py-4">
        <div class="row g-4">
            <!-- Left Sidebar -->
            <div class="col-lg-3 col-md-4">
                <div class="sidebar-card">
                    <!-- User Profile Info -->
                    <div class="d-flex align-items-center gap-3 pb-3 mb-3 border-bottom">
                        <?php if (!empty($profileImage)): ?>
                            <img src="<?php echo htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Avatar" class="user-avatar-sm" id="sidebarAvatar">
                        <?php else: ?>
                            <div class="user-avatar-sm bg-light text-secondary d-flex align-items-center justify-content-center fs-4" id="sidebarAvatar">
                                <i class="bi bi-person-fill"></i>
                            </div>
                        <?php endif; ?>

                        <div class="overflow-hidden">
                            <h6 class="fw-bold mb-0 text-truncate"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></h6>
                            <span class="text-muted small">Customer Account</span>
                        </div>
                    </div>

                    <!-- Navigation Links -->
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php?tab=profile" class="sidebar-menu-link <?php echo $currentTab === 'profile' ? 'active' : ''; ?>">
                            <i class="bi bi-person fs-5"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="profile.php?tab=address" class="sidebar-menu-link <?php echo $currentTab === 'address' ? 'active' : ''; ?>">
                            <i class="bi bi-geo-alt fs-5"></i>
                            <span>My Addresses</span>
                        </a>
                        <a href="my_purchases.php" class="sidebar-menu-link">
                            <i class="bi bi-bag-check fs-5"></i>
                            <span>My Purchase</span>
                        </a>
                        <a href="../logout.php" class="sidebar-menu-link text-danger mt-2">
                            <i class="bi bi-box-arrow-right fs-5"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Right Profile Content -->
            <div class="col-lg-9 col-md-8">
                <div class="profile-content-card p-4 p-md-5">
                    
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show mb-4" role="alert">
                            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($currentTab === 'address'): ?>
                        <!-- ================= TAB: MY ADDRESSES ================= -->
                        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                                    <i class="bi bi-geo-alt-fill text-danger"></i> My Addresses
                                </h4>
                                <p class="text-muted small mb-0">Manage your delivery addresses for seamless checkout.</p>
                            </div>
                            <button type="button" class="btn btn-navy btn-sm fw-semibold" onclick="openAddAddressModal()">
                                <i class="bi bi-plus-lg me-1"></i> Add New Address
                            </button>
                        </div>

                        <?php if (empty($userAddresses)): ?>
                            <div class="text-center py-5">
                                <div class="mb-3 text-secondary">
                                    <i class="bi bi-geo-alt" style="font-size: 3.5rem; opacity: 0.35;"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No Delivery Addresses Saved Yet</h5>
                                <p class="text-muted small mx-auto mb-4" style="max-width: 440px;">
                                    Save your delivery address now so you won't need to retype your information every time you checkout at R. Taipan Hardware.
                                </p>
                                <button type="button" class="btn btn-navy px-4 fw-semibold" onclick="openAddAddressModal()">
                                    <i class="bi bi-plus-lg me-1"></i> Add Your Address
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($userAddresses as $addr): ?>
                                    <div class="address-card-item p-3 p-md-4 <?php echo !empty($addr['is_default']) ? 'is-default-card' : ''; ?>">
                                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <h6 class="fw-bold mb-0 text-dark">
                                                    <?php echo htmlspecialchars($addr['first_name'] . ' ' . $addr['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </h6>
                                                <span class="text-muted small">|</span>
                                                <span class="text-secondary small fw-medium">
                                                    <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($addr['contact_number'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                                <?php if (!empty($addr['tel_no'])): ?>
                                                    <span class="text-muted small">
                                                        (Tel: <?php echo htmlspecialchars($addr['tel_no'], ENT_QUOTES, 'UTF-8'); ?>)
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($addr['is_default'])): ?>
                                                    <span class="badge-default-addr">Default</span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="d-flex align-items-center gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 small fw-semibold"
                                                        onclick='openEditAddressModal(<?php echo json_encode($addr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                    <i class="bi bi-pencil me-1"></i>Edit
                                                </button>

                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this address?');">
                                                    <input type="hidden" name="address_action" value="delete_address">
                                                    <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2 small">
                                                        <i class="bi bi-trash me-1"></i>Delete
                                                    </button>
                                                </form>

                                                <?php if (empty($addr['is_default'])): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="address_action" value="set_default">
                                                        <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary py-1 px-2 small fw-semibold">
                                                            Set as Default
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="text-muted small mb-1">
                                            <span class="fw-semibold text-secondary">Email:</span>
                                            <span><?php echo htmlspecialchars($addr['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="text-dark small mb-1">
                                            <i class="bi bi-geo-alt text-danger me-1"></i>
                                            <strong>Address:</strong> <?php echo htmlspecialchars($addr['full_address'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="text-muted small">
                                            <i class="bi bi-pin-map text-secondary me-1"></i>
                                            <strong>Landmark:</strong> <?php echo htmlspecialchars($addr['landmark'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ================= TAB: MY PROFILE ================= -->
                        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-1 text-dark">My Profile</h4>
                                <p class="text-muted small mb-0">Manage and protect your account information.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <a href="profile.php?tab=address" class="btn btn-outline-secondary btn-sm fw-semibold">
                                    <i class="bi bi-geo-alt me-1"></i> My Addresses
                                </a>
                                <a href="my_purchases.php" class="btn btn-outline-primary btn-sm fw-semibold">
                                    <i class="bi bi-bag-check me-1"></i> View Purchases
                                </a>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <!-- Profile Image Section -->
                            <div class="d-flex align-items-center gap-3 mb-4">
                                <?php if (!empty($user['profile_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['profile_image'], ENT_QUOTES, 'UTF-8'); ?>"
                                         alt="Profile Avatar" class="avatar-lg" id="avatarPreview">
                                <?php else: ?>
                                    <div class="avatar-lg bg-light text-secondary d-flex align-items-center justify-content-center fs-1" id="avatarPreview">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label for="profile_image" class="btn btn-outline-secondary btn-sm fw-semibold mb-1">
                                        <i class="bi bi-camera me-1"></i> Change Photo
                                    </label>
                                    <input type="file" name="profile_image" id="profile_image" class="d-none" accept="image/*" onchange="previewImage(this)">
                                    <div class="form-text small">Accepted formats: JPG, PNG, WEBP.</div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Full Name</label>
                                    <input type="text" name="fullname" class="form-control"
                                           value="<?php echo htmlspecialchars((string) ($user['fullname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Username</label>
                                    <input type="text" class="form-control bg-light"
                                           value="<?php echo htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Email Address</label>
                                    <input type="email" class="form-control bg-light"
                                           value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Phone Number</label>
                                    <input type="text" name="phone_number" class="form-control"
                                           placeholder="e.g. 09123456789"
                                           value="<?php echo htmlspecialchars((string) ($user['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-end gap-2">
                                <a href="../login.php" class="btn btn-light px-4">Cancel</a>
                                <button type="submit" name="update_profile" value="1" class="btn btn-navy px-4">
                                    <i class="bi bi-check2-circle me-1"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Address Modal -->
    <div class="modal fade" id="addressModal" tabindex="-1" aria-labelledby="addressModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold" id="addressModalLabel">
                        <i class="bi bi-geo-alt me-1 text-danger"></i> Add New Address
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addressForm">
                    <input type="hidden" name="address_action" value="save_address">
                    <input type="hidden" name="address_id" id="modal_address_id" value="">
                    
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" id="modal_first_name" class="form-control" 
                                       placeholder="e.g. Juan"
                                       pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" id="modal_last_name" class="form-control" 
                                       placeholder="e.g. Dela Cruz"
                                       pattern="[A-Za-z\s]+" title="Letters only" oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Contact Number <span class="text-danger">*</span></label>
                                <input type="text" name="contact_number" id="modal_contact_number" class="form-control" 
                                       placeholder="09XXXXXXXXX" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                                <div class="form-text small">11-digit mobile number (e.g. 09123456789)</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Telephone Number (Optional)</label>
                                <input type="text" name="tel_no" id="modal_tel_no" class="form-control" 
                                       placeholder="e.g. 02-8123456" oninput="this.value = this.value.replace(/[^0-9-]/g, '')">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Email Address (Account Fixed)</label>
                                <input type="email" class="form-control bg-light" 
                                       value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                <div class="form-text text-muted small"><i class="bi bi-lock-fill me-1"></i>Fixed: Linked to your current logged-in customer account.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Complete Delivery Address <span class="text-danger">*</span></label>
                                <textarea name="full_address" id="modal_full_address" class="form-control" rows="3" 
                                          placeholder="House/Unit No., Street name, Barangay, Municipality/City, Province" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Landmark <span class="text-danger">*</span></label>
                                <input type="text" name="landmark" id="modal_landmark" class="form-control" 
                                       placeholder="e.g. Near Barangay Hall / Beside Sari-sari Store" required>
                            </div>
                            <div class="col-12">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_default" value="1" id="modal_is_default">
                                    <label class="form-check-label small fw-semibold" for="modal_is_default">
                                        Set as default delivery address
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-navy btn-sm px-4 fw-semibold">
                            <i class="bi bi-check2 me-1"></i> Save Address
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatarPreview');
                    if (preview.tagName.toLowerCase() === 'img') {
                        preview.src = e.target.result;
                    } else {
                        const newImg = document.createElement('img');
                        newImg.src = e.target.result;
                        newImg.className = 'avatar-lg';
                        newImg.id = 'avatarPreview';
                        preview.parentNode.replaceChild(newImg, preview);
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        let addressModalInstance = null;
        function getAddressModal() {
            if (!addressModalInstance) {
                addressModalInstance = new bootstrap.Modal(document.getElementById('addressModal'));
            }
            return addressModalInstance;
        }

        function openAddAddressModal() {
            document.getElementById('addressModalLabel').innerHTML = '<i class="bi bi-geo-alt me-1 text-danger"></i> Add New Address';
            document.getElementById('modal_address_id').value = '';
            document.getElementById('modal_first_name').value = '';
            document.getElementById('modal_last_name').value = '';
            document.getElementById('modal_contact_number').value = '';
            document.getElementById('modal_tel_no').value = '';
            document.getElementById('modal_full_address').value = '';
            document.getElementById('modal_landmark').value = '';
            document.getElementById('modal_is_default').checked = false;
            
            getAddressModal().show();
        }

        function openEditAddressModal(addr) {
            document.getElementById('addressModalLabel').innerHTML = '<i class="bi bi-pencil me-1 text-primary"></i> Edit Address';
            document.getElementById('modal_address_id').value = addr.id || '';
            document.getElementById('modal_first_name').value = addr.first_name || '';
            document.getElementById('modal_last_name').value = addr.last_name || '';
            document.getElementById('modal_contact_number').value = addr.contact_number || '';
            document.getElementById('modal_tel_no').value = addr.tel_no || '';
            document.getElementById('modal_full_address').value = addr.full_address || '';
            document.getElementById('modal_landmark').value = addr.landmark || '';
            document.getElementById('modal_is_default').checked = (addr.is_default === true || addr.is_default === 't' || addr.is_default == 1);
            
            getAddressModal().show();
        }
    </script>
</body>
</html>
