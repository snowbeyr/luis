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

// Handle Profile Update
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
                        <a href="profile.php" class="sidebar-menu-link active">
                            <i class="bi bi-person fs-5"></i>
                            <span>My Account</span>
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
                    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                        <div>
                            <h4 class="fw-bold mb-1 text-dark">My Profile</h4>
                            <p class="text-muted small mb-0">Manage and protect your account information.</p>
                        </div>
                        <a href="my_purchases.php" class="btn btn-outline-primary btn-sm fw-semibold">
                            <i class="bi bi-bag-check me-1"></i> View Purchases
                        </a>
                    </div>

                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

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
                </div>
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
    </script>
</body>
</html>
