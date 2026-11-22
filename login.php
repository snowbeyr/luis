<?php
session_start();
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Manila');

$error = '';
$success = '';
$openAuthModal = isset($_GET['auth']) && ($_GET['auth'] === '1' || $_GET['auth'] === 'register');
$authTab = (($_GET['mode'] ?? '') === 'register' || ($_GET['auth'] ?? '') === 'register') ? 'register' : 'login';

$isLoggedIn = !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
$currentRole = (string) ($_SESSION['role'] ?? '');

/**
 * Redirect staff/unknown roles appropriately; customers stay on login.php (customer dashboard).
 */
function redirectStaffOrInvalidRole(string $role): void
{
    switch ($role) {
        case 'owner':
        case 'super_admin':
            header('Location: owner/dashboard.php');
            exit;

        case 'admin':
            header('Location: admin/dashboard.php');
            exit;

        case 'cashier':
            header('Location: cashier/dashboard.php');
            exit;

        case 'customer':
            // Customer dashboard is rendered directly on login.php
            return;

        default:
            session_unset();
            session_destroy();
            header('Location: login.php?auth=1&err=role');
            exit;
    }
}

/**
 * Audit trail logging. A failed audit insert must not interrupt auth flow.
 */
function logAction($conn, int $userId, string $username, string $role, string $action, string $details): void
{
    try {
        $sql = "INSERT INTO audit_log
                (user_id, username, role, action, details, ip_address)
                VALUES (:user_id, :username, :role, :action, :details, :ip)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':user_id'  => $userId,
            ':username' => $username,
            ':role'     => $role,
            ':action'   => $action,
            ':details'  => $details,
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/*
 * If already logged in as staff, send to their dashboard.
 */
if ($isLoggedIn && $currentRole !== 'customer') {
    redirectStaffOrInvalidRole($currentRole);
}

/*
 * Handle Customer Registration (always assigns role = 'customer').
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $openAuthModal = true;
    $authTab = 'register';

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if ($fullName === '' || $username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'Please fill in all registration fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'Password must contain at least 1 uppercase letter (A–Z).';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'Password must contain at least 1 lowercase letter (a–z).';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least 1 number (0–9).';
    } elseif (!preg_match('/[\W_]/', $password)) {
        $error = 'Password must contain at least 1 special character (!@#$%^&*).';
    } elseif (preg_match('/\s/', $password)) {
        $error = 'Password cannot contain spaces.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $checkStmt = $pdo->prepare(
                "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1"
            );
            $checkStmt->execute([
                ':username' => $username,
                ':email'    => $email
            ]);

            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'Username or email is already registered.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare(
                    "INSERT INTO users (fullname, username, email, password, role, status)
                     VALUES (:fullname, :username, :email, :password, 'customer', 'Active')
                     RETURNING id"
                );
                $insertStmt->execute([
                    ':fullname' => $fullName,
                    ':username' => $username,
                    ':email'    => $email,
                    ':password' => $hashedPassword
                ]);

                $newUserId = (int) $insertStmt->fetchColumn();

                session_regenerate_id(true);
                $_SESSION['user_id']   = $newUserId;
                $_SESSION['username']  = $username;
                $_SESSION['full_name'] = $fullName;
                $_SESSION['role']      = 'customer';
                $_SESSION['message']   = 'Account created! Welcome to R. Taipan Hardware.';

                logAction(
                    $pdo,
                    $newUserId,
                    $username,
                    'customer',
                    'Register',
                    'New customer account created'
                );

                header('Location: login.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Registration error: ' . $e->getMessage());
            $error = 'Unable to create account right now. Please try again later.';
        }
    }
}

/*
 * Handle Existing Account Login (staff & customers).
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $openAuthModal = true;
    $authTab = 'login';

    $loginInput = trim((string) ($_POST['username'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');

    if ($loginInput === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $sql = "SELECT *
                    FROM users
                    WHERE (username = :login_user OR email = :login_email)
                      AND status = 'Active'
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':login_user'  => $loginInput,
                ':login_email' => $loginInput
            ]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                logAction(
                    $pdo,
                    0,
                    $loginInput,
                    'Guest/Unknown',
                    'Login Failed',
                    'Attempted login with non-existent account or inactive status'
                );

                $error = 'Invalid username/email or password.';
            } else {
                $failedAttempts = (int) ($user['failed_attempts'] ?? 0);
                $currentTime    = date('Y-m-d H:i:s');
                $lockoutUntil   = $user['lockout_until'] ?? null;

                if (!empty($lockoutUntil) && $lockoutUntil > $currentTime) {
                    $remainingMinutes = max(
                        1,
                        (int) ceil((strtotime($lockoutUntil) - time()) / 60)
                    );

                    $error = "Account is temporarily locked. Please try again in about {$remainingMinutes} minute(s).";

                    logAction(
                        $pdo,
                        (int) $user['id'],
                        (string) $user['username'],
                        (string) $user['role'],
                        'Login Failed (Locked)',
                        'Attempted login on a temporarily locked account'
                    );
                } elseif (password_verify($password, (string) $user['password'])) {
                    $resetStmt = $pdo->prepare(
                        "UPDATE users
                         SET failed_attempts = 0, lockout_until = NULL
                         WHERE id = :id"
                    );
                    $resetStmt->execute([':id' => $user['id']]);

                    session_regenerate_id(true);

                    $_SESSION['user_id']   = (int) $user['id'];
                    $_SESSION['username']  = (string) $user['username'];
                    $_SESSION['full_name'] = (string) ($user['fullname'] ?? $user['username']);
                    $_SESSION['role']      = (string) $user['role'];
                    $_SESSION['email']     = (string) ($user['email'] ?? '');
                    $_SESSION['profile_image'] = (string) ($user['profile_image'] ?? '');

                    logAction(
                        $pdo,
                        (int) $user['id'],
                        (string) $user['username'],
                        (string) $user['role'],
                        'Login',
                        'User successfully logged into the system'
                    );

                    if ((string) $user['role'] === 'customer') {
                        $_SESSION['message'] = 'Welcome back, ' . htmlspecialchars((string) $_SESSION['full_name'], ENT_QUOTES, 'UTF-8') . '!';
                        header('Location: login.php');
                        exit;
                    }

                    redirectStaffOrInvalidRole((string) $user['role']);
                } else {
                    $newFailedAttempts = $failedAttempts + 1;

                    if ($newFailedAttempts >= 5) {
                        $lockoutTime = date('Y-m-d H:i:s', time() + 300);

                        $lockStmt = $pdo->prepare(
                            "UPDATE users
                             SET failed_attempts = :attempts,
                                 lockout_until = :lockout
                             WHERE id = :id"
                        );
                        $lockStmt->execute([
                            ':attempts' => $newFailedAttempts,
                            ':lockout'  => $lockoutTime,
                            ':id'       => $user['id']
                        ]);

                        $error = 'Account temporarily locked after 5 failed login attempts. Please try again after 5 minutes.';

                        logAction(
                            $pdo,
                            (int) $user['id'],
                            (string) $user['username'],
                            (string) $user['role'],
                            'Account Locked',
                            'Account temporarily locked after 5 failed login attempts'
                        );
                    } else {
                        $updateStmt = $pdo->prepare(
                            "UPDATE users
                             SET failed_attempts = :attempts
                             WHERE id = :id"
                        );
                        $updateStmt->execute([
                            ':attempts' => $newFailedAttempts,
                            ':id'       => $user['id']
                        ]);

                        $attemptsLeft = 5 - $newFailedAttempts;
                        $error = "Invalid username/email or password. You have {$attemptsLeft} attempt(s) left before temporary lockout.";

                        logAction(
                            $pdo,
                            (int) $user['id'],
                            (string) $user['username'],
                            (string) $user['role'],
                            'Login Failed',
                            "Invalid password attempt ({$newFailedAttempts}/5)"
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Login processing error: ' . $e->getMessage());
            $error = 'Unable to process login right now. Please try again later.';
        }
    }
}

$isLoggedIn = !empty($_SESSION['user_id']);

/*
 * Load Customer Dashboard Products
 */
$category = $_GET['category'] ?? '';
$sort = $_GET['sort'] ?? '';
$is_home = (!isset($_GET['view']) && $category == '' && !isset($_GET['sort']));
$sort_label = '';

try {
    $sql = "
        SELECT
            p.id,
            p.product_name,
            p.category,
            p.price,
            p.stock,
            p.image,
            p.price AS min_price,
            p.price AS max_price,
            0 AS variant_count,
            COALESCE(SUM(oi.qty), 0) AS total_sold
        FROM public.products p
        LEFT JOIN public.order_items oi ON p.id = oi.product_id
        WHERE p.is_archived = 0
    ";

    $params = [];

    if ($category !== '') {
        $sql .= " AND p.category = :category";
        $params[':category'] = $category;
    }

    $sql .= " GROUP BY p.id, p.product_name, p.category, p.price, p.stock, p.image";

    switch ($sort) {
        case 'price_low':
            $sql .= " ORDER BY p.price ASC";
            $sort_label = "(Price: Low to High)";
            break;

        case 'price_high':
            $sql .= " ORDER BY p.price DESC";
            $sort_label = "(Price: High to Low)";
            break;

        case 'az':
            $sql .= " ORDER BY p.product_name ASC";
            $sort_label = "(A-Z)";
            break;

        case 'za':
            $sql .= " ORDER BY p.product_name DESC";
            $sort_label = "(Z-A)";
            break;

        case 'newest':
            $sql .= " ORDER BY p.id DESC";
            $sort_label = "(Newest Arrivals)";
            break;

        case 'oldest':
            $sql .= " ORDER BY p.id ASC";
            $sort_label = "(Oldest Stock)";
            break;

        default:
            if ($is_home) {
                $sql .= " ORDER BY total_sold DESC, p.id DESC";
            } else {
                $sql .= " ORDER BY p.id DESC";
            }
            break;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Dashboard product load error: ' . $e->getMessage());
    echo '<div class="alert alert-danger m-3">Database error: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</div>';
    $products = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R. Taipan Hardware</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
        }

        .navbar-custom {
            background: #051c3c;
            padding: 15px 0;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #214a9c !important;
        }

        .dropdown-menu {
            border: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .product-card {
            transition: 0.3s;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .product-img {
            height: 200px;
            object-fit: cover;
        }

        #imageZoomModal {
            z-index: 2050 !important;
        }

        #authModal {
            z-index: 2100 !important;
        }

        .brand-logo-sm {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #051c3c;
        }

        .btn-primary-navy {
            background: #051c3c;
            color: #fff;
            font-weight: 600;
        }

        .btn-primary-navy:hover {
            background: #0b3267;
            color: #fff;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: #6c757d;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin: 18px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }

        .divider:not(:empty)::before { margin-right: 12px; }
        .divider:not(:empty)::after { margin-left: 12px; }

        .user-dropdown-btn {
            transition: background 0.2s ease;
        }

        .user-dropdown-btn:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .auth-input-group {
            position: relative;
        }

        .auth-input-group .auth-input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1rem;
            pointer-events: none;
            z-index: 3;
        }

        .auth-input-group .form-control {
            padding-left: 40px;
        }

        .auth-input-group .form-control.has-right-btn {
            padding-right: 42px;
        }

        .auth-input-group .auth-input-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 0;
            color: #6c757d;
            cursor: pointer;
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-input-group .auth-input-btn:hover {
            color: #212529;
        }

        /* Checklist Styles */
        .password-checklist {
            font-size: 0.8rem;
            margin-top: 6px;
            padding-left: 0;
            list-style: none;
            margin-bottom: 0;
        }
        .password-checklist li {
            color: #6c757d;
            margin-bottom: 3px;
            transition: color 0.2s ease;
        }
        .password-checklist li.valid {
            color: #198754;
            font-weight: 600;
        }
        .password-checklist li.valid::before {
            content: "✓ ";
        }
        .password-checklist li::before {
            content: "• ";
        }
        }
    </style>
</head>

<body>
    <div id="notif-container"
        style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999;"></div>

    <?php if (isset($_SESSION['message'])) { ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x shadow"
            style="z-index:9999; min-width:320px; max-width: 90%; margin-top: 20px; display: flex; align-items: center; justify-content: space-between;"
            role="alert">
            <span style="flex-grow: 1; text-align: center; font-weight: 500;">
                <?php echo $_SESSION['message']; ?>
            </span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"
                style="margin-left: 15px;"></button>
        </div>
        <?php unset($_SESSION['message']);
    } ?>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="login.php">
                <img src="assets/logo.jpg"
                    style="width:40px; height:40px; border-radius:50%; border:2px solid white;">
                <strong>R. Taipan Hardware</strong>
            </a>

            <!-- Mobile View: User menu / Log in button katabi ng burger icon -->
            <div class="d-flex d-lg-none align-items-center gap-2">
                <?php if ($isLoggedIn): ?>
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle gap-1"
                            id="userMobileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if (!empty($_SESSION['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_image'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="Avatar" class="rounded-circle" style="width: 28px; height: 28px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-white text-dark d-flex align-items-center justify-content-center"
                                    style="width: 28px; height: 28px; font-size: 0.85rem;">
                                    <i class="bi bi-person-fill text-secondary"></i>
                                </div>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userMobileDropdown" style="border-radius: 12px; min-width: 175px;">
                            <li class="px-3 py-2 border-bottom">
                                <span class="d-block small text-muted">Signed in as</span>
                                <strong class="small text-dark text-truncate d-block" style="max-width: 180px;">
                                    <?php echo htmlspecialchars((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Account'), ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                            </li>
                            <li>
                                <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="customer/profile.php">
                                    <i class="bi bi-person text-secondary"></i>
                                    <span>My Account</span>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="customer/my_purchases.php">
                                    <i class="bi bi-bag-check text-secondary"></i>
                                    <span>My Purchase</span>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item py-2 small d-flex align-items-center gap-2 text-danger" href="logout.php">
                                    <i class="bi bi-box-arrow-right"></i>
                                    <span>Logout</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <button type="button" class="btn btn-warning btn-sm fw-semibold text-dark"
                        onclick="openAuthModal('login')">
                        Log in
                    </button>
                <?php endif; ?>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="login.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="login.php?view=all">All Products</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="catDropdown" role="button"
                            data-bs-toggle="dropdown">Categories</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="login.php?category=Tools">Tools</a></li>
                            <li><a class="dropdown-item" href="login.php?category=Electrical">Electrical</a></li>
                            <li><a class="dropdown-item" href="login.php?category=Construction">Construction</a></li>
                            <li><a class="dropdown-item" href="login.php?category=Plumbing">Plumbing</a></li>
                            <li><a class="dropdown-item" href="login.php?category=Paint and Supplies">Paint & Supplies</a></li>
                            <li><a class="dropdown-item" href="login.php?category=Chemicals">Chemicals</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-warning" href="#" id="sortDropdown"
                            role="button" data-bs-toggle="dropdown">Sort By</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?sort=price_low&category=<?php echo urlencode($category); ?>">Price: Low to High</a></li>
                            <li><a class="dropdown-item" href="?sort=price_high&category=<?php echo urlencode($category); ?>">Price: High to Low</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="?sort=az&category=<?php echo urlencode($category); ?>">A-Z (Name)</a></li>
                            <li><a class="dropdown-item" href="?sort=za&category=<?php echo urlencode($category); ?>">Z-A (Name)</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="?sort=newest&category=<?php echo urlencode($category); ?>">Newest Arrivals</a></li>
                            <li><a class="dropdown-item" href="?sort=oldest&category=<?php echo urlencode($category); ?>">Oldest Stock</a></li>
                        </ul>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="customer/aboutus.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#footer">Contact</a></li>
                </ul>

                <form class="d-flex me-3 my-2 my-lg-0" onsubmit="return false;">
                    <input class="form-control form-control-sm" type="search" id="searchInput"
                        placeholder="Search...">
                </form>

                <div class="d-flex align-items-center gap-2">
                    <a href="customer/cart.php" id="navbarCartLink" class="btn btn-warning btn-sm position-relative fw-semibold">
                        🛒 Cart
                        <?php
                        $cartCount = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
                        if ($cartCount > 0) {
                            ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $cartCount; ?>
                            </span>
                        <?php } ?>
                    </a>

                    <!-- Desktop View: User Menu Dropdown (Shopee-style) -->
                    <div class="d-none d-lg-flex align-items-center">
                        <?php if ($isLoggedIn): ?>
                            <div class="dropdown">
                                <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle gap-2 px-2 py-1 rounded user-dropdown-btn"
                                    id="userMenuDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <?php if (!empty($_SESSION['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($_SESSION['profile_image'], ENT_QUOTES, 'UTF-8'); ?>"
                                            alt="Profile" class="rounded-circle border border-light" style="width: 30px; height: 30px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-white text-dark d-flex align-items-center justify-content-center"
                                            style="width: 30px; height: 30px; font-size: 0.9rem;">
                                            <i class="bi bi-person-fill text-secondary"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span class="small fw-semibold text-white">
                                        <?php echo htmlspecialchars((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Account'), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userMenuDropdown" style="border-radius: 12px; min-width: 175px;">
                                    <li>
                                        <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="customer/profile.php">
                                            <i class="bi bi-person text-secondary fs-6"></i>
                                            <span>My Account</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="customer/my_purchases.php">
                                            <i class="bi bi-bag-check text-secondary fs-6"></i>
                                            <span>My Purchase</span>
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <a class="dropdown-item py-2 small d-flex align-items-center gap-2 text-danger" href="logout.php">
                                            <i class="bi bi-box-arrow-right fs-6"></i>
                                            <span>Logout</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-light btn-sm fw-semibold text-nowrap"
                                onclick="openAuthModal('login')">
                                Log in
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Floating Login / Create Account / Google Auth Modal -->
    <div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 430px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-body p-4">
                    <!-- Top Brand Header: Logo + Title (Left), Close Button (Right) -->
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div class="d-flex align-items-center gap-2">
                            <img src="assets/logo.jpg" alt="R. Taipan Hardware" class="rounded" style="width: 34px; height: 34px; object-fit: cover;">
                            <span class="fw-bold fs-6">R. Taipan Hardware</span>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- Headline & Subtitle -->
                    <div class="mb-4">
                        <h4 class="fw-bold mb-1" id="authModalTitle"><?php echo $authTab === 'register' ? 'Create an account' : 'Welcome back!'; ?></h4>
                        <p class="text-muted small mb-0" id="authModalSubtitle"><?php echo $authTab === 'register' ? 'Sign up to manage your orders.' : 'Sign in to manage your account.'; ?></p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger text-center py-2 small mb-3" role="alert">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Existing Account Login Form -->
                    <form method="POST" action="login.php" id="loginFormSection"
                        class="<?php echo $authTab === 'login' ? '' : 'd-none'; ?>" autocomplete="on">
                        <div class="mb-3">
                            <label for="username" class="form-label small fw-semibold">Email address / Username</label>
                            <div class="auth-input-group">
                                <i class="bi bi-envelope auth-input-icon"></i>
                                <input type="text" name="username" id="username" class="form-control"
                                    placeholder="Enter username or email" autocomplete="username" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="passwordField" class="form-label small fw-semibold mb-0">Password</label>
                                <a href="forgotPassword.php" class="small text-decoration-none">Forgot password?</a>
                            </div>
                            <div class="auth-input-group">
                                <i class="bi bi-lock auth-input-icon"></i>
                                <input type="password" name="password" id="passwordField" class="form-control has-right-btn"
                                    placeholder="Enter your password" autocomplete="current-password" required>
                                <button class="auth-input-btn" type="button" id="togglePasswordBtn" aria-label="Show password">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" name="login" value="1" class="btn btn-primary-navy w-100 py-2 fw-semibold d-flex align-items-center justify-content-center gap-2">
                            <span>Sign in</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>

                        <div class="text-center mt-3">
                            <span class="small text-muted">Don't have an account? </span>
                            <a href="javascript:void(0)" onclick="switchAuthTab('register')" class="small text-decoration-none fw-semibold">Create one</a>
                        </div>
                    </form>

                    <!-- Create Customer Account Form -->
                    <form method="POST" action="login.php" id="registerFormSection"
                        class="<?php echo $authTab === 'register' ? '' : 'd-none'; ?>" autocomplete="on">
                        <div class="mb-2">
                            <label for="full_name" class="form-label small fw-semibold">Full Name</label>
                            <div class="auth-input-group">
                                <i class="bi bi-person auth-input-icon"></i>
                                <input type="text" name="full_name" id="full_name" class="form-control"
                                    placeholder="Enter your full name" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="reg_username" class="form-label small fw-semibold">Username</label>
                            <div class="auth-input-group">
                                <i class="bi bi-at auth-input-icon"></i>
                                <input type="text" name="username" id="reg_username" class="form-control"
                                    placeholder="Choose a username" autocomplete="username" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="email" class="form-label small fw-semibold">Email Address</label>
                            <div class="auth-input-group">
                                <i class="bi bi-envelope auth-input-icon"></i>
                                <input type="email" name="email" id="email" class="form-control"
                                    placeholder="name@example.com" autocomplete="email" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="reg_password" class="form-label small fw-semibold">Password</label>
                            <div class="auth-input-group">
                                <i class="bi bi-lock auth-input-icon"></i>
                                <input type="password" name="password" id="reg_password" class="form-control has-right-btn"
                                    placeholder="At least 8 characters" autocomplete="new-password" minlength="8" required>
                                <button class="auth-input-btn" type="button" id="toggleRegPasswordBtn" aria-label="Show password">
                                    <i class="bi bi-eye" id="toggleRegPasswordIcon"></i>
                                </button>
                            </div>
                            <!-- Real-time Checklist Validation -->
                            <ul class="password-checklist" id="regPasswordChecklist">
                                <li id="regLengthReq">At least 8 characters</li>
                                <li id="regUpperReq">At least 1 uppercase letter (A–Z)</li>
                                <li id="regLowerReq">At least 1 lowercase letter (a–z)</li>
                                <li id="regNumberReq">At least 1 number (0–9)</li>
                                <li id="regSpecialReq">At least 1 special character (!@#$%^&*)</li>
                                <li id="regSpaceReq">No spaces allowed</li>
                            </ul>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label small fw-semibold">Confirm Password</label>
                            <div class="auth-input-group">
                                <i class="bi bi-shield-check auth-input-icon"></i>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control has-right-btn"
                                    placeholder="Re-enter your password" autocomplete="new-password" minlength="8" required>
                                <button class="auth-input-btn" type="button" id="toggleRegConfirmPasswordBtn" aria-label="Show password">
                                    <i class="bi bi-eye" id="toggleRegConfirmPasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" name="register" value="1" class="btn btn-primary-navy w-100 py-2 fw-semibold d-flex align-items-center justify-content-center gap-2">
                            <span>Create Account</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>

                        <div class="text-center mt-3">
                            <span class="small text-muted">Already have an account? </span>
                            <a href="javascript:void(0)" onclick="switchAuthTab('login')" class="small text-decoration-none fw-semibold">Sign in</a>
                        </div>
                    </form>

                    <div class="divider">OR CONTINUE WITH</div>

                    <a href="google-auth.php" class="btn btn-outline-dark w-100 py-2 fw-semibold d-flex align-items-center justify-content-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.36 24 12 24z"/>
                            <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 9.99 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/>
                            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.36 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                        </svg>
                        <span>Continue with Google</span>
                    </a>

                    <p class="text-center text-muted mt-3 mb-0" style="font-size: 0.75rem;">
                        By continuing, you agree to our <a href="#" class="text-muted text-decoration-underline">Terms of Service</a> and <a href="#" class="text-muted text-decoration-underline">Privacy Policy</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="imageZoomModal" tabindex="-1" style="z-index: 2050;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0">
                <div class="text-center">
                    <img id="zoomImage" src="" class="img-fluid rounded shadow" style="max-height: 85vh;">
                </div>
                <div class="text-center mt-3">
                    <button type="button" class="btn px-4 py-2 fw-bold shadow"
                        style="background:#6c757d; color:#fff; border:0;" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 id="productSectionTitle">
                <?php
                if ($category != '') {
                    echo "Category: " . htmlspecialchars($category);
                } else if (isset($_GET['view'])) {
                    echo "All Products";
                } else if ($is_home) {
                    echo "Top Selling Items";
                } else {
                    echo "Product List";
                }
                if ($sort != '') {
                    echo " <span class='text-muted' style='font-size: 16px;'>$sort_label</span>";
                }
                ?>
            </h4>
        </div>

        <div class="row row-cols-2 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4" id="productResults">
            <?php if (!empty($products)) { ?>
                <?php foreach ($products as $row) {
                    $price_display = "₱" . number_format($row['min_price'], 2);
                    if ($row['variant_count'] > 0 && $row['min_price'] != $row['max_price']) {
                        $price_display .= " - ₱" . number_format($row['max_price'], 2);
                    }

                    $p_image = $row['image'] ?? '';
                    $img_src = 'assets/images/no-image.png';

                    if (!empty($p_image)) {
                        $image_value = trim($p_image);

                        if (stripos($image_value, 'data:image/') === 0) {
                            $img_src = $image_value;
                        } elseif (filter_var($image_value, FILTER_VALIDATE_URL)) {
                            $parsed_url = parse_url($image_value);
                            $host = strtolower($parsed_url['host'] ?? '');

                            if ($host === 'drive.google.com' || $host === 'docs.google.com') {
                                parse_str($parsed_url['query'] ?? '', $query_params);
                                $file_id = '';

                                if (!empty($query_params['id'])) {
                                    $file_id = $query_params['id'];
                                } elseif (preg_match('#/file/d/([A-Za-z0-9_-]+)#', $parsed_url['path'] ?? '', $matches)) {
                                    $file_id = $matches[1];
                                }

                                if ($file_id !== '') {
                                    $img_src = 'drive-image.php?id=' . urlencode($file_id);
                                }
                            } else {
                                $img_src = $image_value;
                            }
                        } elseif (preg_match('/^[A-Za-z0-9_-]{20,}$/', $image_value) && !str_contains($image_value, '/')) {
                            $img_src = 'drive-image.php?id=' . urlencode($image_value);
                        } else {
                            $clean_path = ltrim($image_value, '/');
                            $clean_path = preg_replace('#^(\.\./)+#', '', $clean_path);
                            $img_src = $clean_path;
                        }
                    }

                    $mainStock = (int) ($row['stock'] ?? 0);
                    ?>
                    <div class="col">
                        <div class="card product-card shadow-sm h-100">
                            <img src="<?php echo htmlspecialchars($img_src, ENT_QUOTES, 'UTF-8'); ?>"
                                class="card-img-top product-img"
                                alt="<?php echo htmlspecialchars($row['product_name']); ?>"
                                onerror="this.onerror=null; this.src='assets/images/no-image.png';"
                                loading="lazy">
                            <div class="card-body d-flex flex-column text-center">
                                <h6 class="card-title fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($row['product_name']); ?></h6>
                                <p class="text-success fw-bold fs-6 mb-1"><?php echo $price_display; ?></p>
                                <div class="d-flex justify-content-center small mb-3">
                                    <?php if ($mainStock > 0): ?>
                                        <span class="text-muted">Stock: <strong class="text-dark"><?php echo $mainStock; ?></strong></span>
                                    <?php else: ?>
                                        <span class="text-danger fw-semibold">Out of Stock</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-auto">
                                    <button type="button" class="btn btn-outline-primary w-100 py-2 fw-bold"
                                        onclick="openProductModal(<?php echo $row['id']; ?>)">
                                        <i class="bi bi-eye"></i> View Product
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <div class="col-12 text-center py-5">
                    <div class="alert alert-light border shadow-sm">No products found!</div>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="modal fade" id="productDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 15px; overflow: hidden; border: none;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-2">
                    <div id="modalLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted small">Loading product details...</p>
                    </div>

                    <div id="modalProductContent" class="row d-none">
                        <div class="col-md-6 text-center mb-3 mb-md-0">
                            <div class="p-2 border rounded bg-light d-flex align-items-center justify-content-center"
                                style="height: 330px;">
                                <img id="modalMainImage" src="" class="img-fluid"
                                    style="max-height: 100%; object-fit: contain; cursor: pointer;"
                                    onclick="openModalImageZoom()"
                                    onerror="this.onerror=null; this.src='assets/images/no-image.png';">
                            </div>
                        </div>

                        <div class="col-md-6 d-flex flex-column">
                            <span id="modalCategory" class="badge bg-secondary align-self-start mb-2"></span>
                            <h4 id="modalProductName" class="fw-bold mb-1"></h4>

                            <h3 class="text-success fw-bold mb-0">₱<span id="modalDisplayPrice">0.00</span></h3>
                            <p class="text-muted small mb-2">Available Stock: <span id="modalDisplayStock"
                                    class="fw-bold">0</span></p>

                            <div
                                class="mb-2 bg-light p-2 rounded border d-flex justify-content-between align-items-center">
                                <span class="text-secondary small fw-bold">Total:</span>
                                <span class="text-success fw-bold fs-5">₱<span id="modalTotalPrice">0.00</span></span>
                            </div>

                            <hr class="my-1">
                            <div class="small text-muted mb-2" style="max-height: 90px; overflow-y: auto;">
                                <strong>Product Description:</strong><br>
                                <span id="modalDescription" class="text-dark"></span>
                            </div>
                            <hr class="my-1">

                            <div class="mt-auto">
                                <div class="mb-2" id="variantDropdownContainer">
                                    <label class="form-label small fw-bold mb-1">Select Variant:</label>
                                    <select id="modalVariantSelect" class="form-select form-select-sm"
                                        onchange="updateModalVariantDetails()">
                                    </select>
                                </div>

                                <div class="mb-3" style="max-width: 120px;">
                                    <label class="form-label small fw-bold mb-1">Quantity:</label>
                                    <input type="number" id="modalQtyInput" class="form-control form-control-sm"
                                        value="1" min="1" oninput="calculateLiveTotal()" onchange="validateQtyInput()">
                                </div>

                                <a href="#" id="modalAddToCartBtn" class="btn btn-warning w-100 mb-2 py-2 fw-bold">Add
                                    to Cart</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="notif-box"
        style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; display: none;">
        <div class="alert alert-success shadow d-flex align-items-center" role="alert" style="padding: 10px 20px;">
            <i class="bi bi-check-circle-fill me-2"></i>
            <span>Successfully added to cart!</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        const SHOULD_OPEN_AUTH_MODAL = <?php echo $openAuthModal ? 'true' : 'false'; ?>;

        let authModalInstance = null;
        function getAuthModal() {
            if (!authModalInstance) {
                authModalInstance = new bootstrap.Modal(document.getElementById('authModal'));
            }
            return authModalInstance;
        }

        function switchAuthTab(tab) {
            const loginForm = document.getElementById('loginFormSection');
            const registerForm = document.getElementById('registerFormSection');
            const tabLoginBtn = document.getElementById('tabLoginBtn');
            const tabRegisterBtn = document.getElementById('tabRegisterBtn');
            const title = document.getElementById('authModalTitle');
            const subtitle = document.getElementById('authModalSubtitle');

            if (tab === 'register') {
                loginForm?.classList.add('d-none');
                registerForm?.classList.remove('d-none');
                tabLoginBtn?.classList.remove('active');
                tabRegisterBtn?.classList.add('active');
                if (title) title.textContent = 'Create an account';
                if (subtitle) subtitle.textContent = 'Sign up to manage your orders.';
            } else {
                registerForm?.classList.add('d-none');
                loginForm?.classList.remove('d-none');
                tabRegisterBtn?.classList.remove('active');
                tabLoginBtn?.classList.add('active');
                if (title) title.textContent = 'Welcome back!';
                if (subtitle) subtitle.textContent = 'Sign in to manage your account.';
            }
        }

        function openAuthModal(tab = 'login') {
            switchAuthTab(tab);
            getAuthModal().show();
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (SHOULD_OPEN_AUTH_MODAL && !IS_LOGGED_IN) {
                openAuthModal(<?php echo json_encode($authTab); ?>);
            }
        });

        // Password visibility toggle inside floating modal
        const togglePasswordBtn = document.getElementById('togglePasswordBtn');
        const passwordField = document.getElementById('passwordField');
        const togglePasswordIcon = document.getElementById('togglePasswordIcon');

        togglePasswordBtn?.addEventListener('click', function () {
            const showPassword = passwordField.type === 'password';
            passwordField.type = showPassword ? 'text' : 'password';
            togglePasswordIcon.classList.toggle('bi-eye', !showPassword);
            togglePasswordIcon.classList.toggle('bi-eye-slash', showPassword);
            togglePasswordBtn.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
        });

        // Setup Password Visibility Toggle for Registration
        function setupPasswordToggle(btnId, fieldId, iconId) {
            const btn = document.getElementById(btnId);
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            if (btn && field && icon) {
                btn.addEventListener('click', function () {
                    const isPassword = field.getAttribute('type') === 'password';
                    field.setAttribute('type', isPassword ? 'text' : 'password');
                    icon.classList.toggle('bi-eye', !isPassword);
                    icon.classList.toggle('bi-eye-slash', isPassword);
                    btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                });
            }
        }
        setupPasswordToggle('toggleRegPasswordBtn', 'reg_password', 'toggleRegPasswordIcon');
        setupPasswordToggle('toggleRegConfirmPasswordBtn', 'confirm_password', 'toggleRegConfirmPasswordIcon');

        // Real-time Checklist Validation for Registration
        const regPasswordInput = document.getElementById('reg_password');
        const regLengthReq = document.getElementById('regLengthReq');
        const regUpperReq = document.getElementById('regUpperReq');
        const regLowerReq = document.getElementById('regLowerReq');
        const regNumberReq = document.getElementById('regNumberReq');
        const regSpecialReq = document.getElementById('regSpecialReq');
        const regSpaceReq = document.getElementById('regSpaceReq');

        if (regPasswordInput) {
            regPasswordInput.addEventListener('input', function() {
                const val = regPasswordInput.value;

                // 1. Length check (8+ chars)
                if (val.length >= 8) {
                    regLengthReq?.classList.add('valid');
                } else {
                    regLengthReq?.classList.remove('valid');
                }

                // 2. Uppercase check
                if (/[A-Z]/.test(val)) {
                    regUpperReq?.classList.add('valid');
                } else {
                    regUpperReq?.classList.remove('valid');
                }

                // 3. Lowercase check
                if (/[a-z]/.test(val)) {
                    regLowerReq?.classList.add('valid');
                } else {
                    regLowerReq?.classList.remove('valid');
                }

                // 4. Number check
                if (/[0-9]/.test(val)) {
                    regNumberReq?.classList.add('valid');
                } else {
                    regNumberReq?.classList.remove('valid');
                }

                // 5. Special character check
                if (/[\W_]/.test(val)) {
                    regSpecialReq?.classList.add('valid');
                } else {
                    regSpecialReq?.classList.remove('valid');
                }

                // 6. No spaces check
                if (val.length > 0 && !/\s/.test(val)) {
                    regSpaceReq?.classList.add('valid');
                } else {
                    regSpaceReq?.classList.remove('valid');
                }
            });
        }

        // Validate on Registration Submit
        const registerFormEl = document.getElementById('registerFormSection');
        if (registerFormEl) {
            registerFormEl.addEventListener('submit', function(e) {
                const pwd = document.getElementById('reg_password')?.value || '';
                const confirmPwd = document.getElementById('confirm_password')?.value || '';

                if (pwd.length < 8 || !/[A-Z]/.test(pwd) || !/[a-z]/.test(pwd) || !/[0-9]/.test(pwd) || !/[\W_]/.test(pwd) || /\s/.test(pwd)) {
                    e.preventDefault();
                    alert('Please make sure your password satisfies all the requirements.');
                    document.getElementById('reg_password')?.focus();
                    return false;
                }

                if (pwd !== confirmPwd) {
                    e.preventDefault();
                    alert('Passwords do not match.');
                    document.getElementById('confirm_password')?.focus();
                    return false;
                }
            });
        }

        // Block Add to Cart, Cart, Checkout, and Place Order when not logged in
        document.addEventListener('click', function (e) {
            const guardedEl = e.target.closest('#modalAddToCartBtn, #navbarCartLink, .btn-add-to-cart, .btn-checkout, .btn-place-order');
            if (guardedEl && !IS_LOGGED_IN) {
                e.preventDefault();
                e.stopPropagation();
                openAuthModal('login');
            }
        }, true);

        document.addEventListener('submit', function (e) {
            const form = e.target.closest('form[data-transaction-form]');
            if (form && !IS_LOGGED_IN) {
                e.preventDefault();
                openAuthModal('login');
            }
        }, true);

        function resolveImagePath(rawImage) {
            if (!rawImage || rawImage.trim() === '') {
                return 'assets/images/no-image.png';
            }
            var val = rawImage.trim();

            if (val.startsWith('data:image/') || val.startsWith('http://') || val.startsWith('https://')) {
                if (val.includes('drive.google.com') || val.includes('docs.google.com')) {
                    var fileId = '';
                    var match = val.match(/[\?&]id=([^&]+)/);
                    if (match) {
                        fileId = match[1];
                    } else {
                        var matchPath = val.match(/\/file\/d\/([A-Za-z0-9_-]+)/);
                        if (matchPath) fileId = matchPath[1];
                    }
                    if (fileId) {
                        return 'drive-image.php?id=' + encodeURIComponent(fileId);
                    }
                }
                return val;
            }

            if (/^[A-Za-z0-9_-]{20,}$/.test(val) && !val.includes('/')) {
                return 'drive-image.php?id=' + encodeURIComponent(val);
            }

            return val.replace(/^(\.\.\/)+/, '').replace(/^\/+/, '');
        }

        function liveSearch() {
            let searchInput = document.getElementById("searchInput");
            let searchValue = searchInput.value.trim();
            let titleHeader = document.getElementById("productSectionTitle");

            if (titleHeader) {
                if (searchValue !== "") {
                    titleHeader.innerHTML = `Search Results for: <span class="text-primary">"${escapeHtml(searchValue)}"</span>`;
                } else {
                    titleHeader.innerHTML = `<?php
                        if ($category != '') {
                            echo "Category: " . htmlspecialchars($category);
                        } else if (isset($_GET['view'])) {
                            echo "All Products";
                        } else if ($is_home) {
                            echo "Top Selling Items";
                        } else {
                            echo "Product List";
                        }
                        if ($sort != '') {
                            echo " <span class='text-muted' style='font-size: 16px;'>$sort_label</span>";
                        }
                    ?>`;
                }
            }

            let xhr = new XMLHttpRequest();
            xhr.open("POST", "customer/livesearch.php", true);
            xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            xhr.onload = function () {
                document.getElementById("productResults").innerHTML = this.responseText;
            };
            xhr.send("search=" + encodeURIComponent(searchValue));
        }

        function escapeHtml(text) {
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        const searchInputField = document.getElementById("searchInput");
        if (searchInputField) {
            searchInputField.addEventListener("keyup", liveSearch);
            searchInputField.addEventListener("input", liveSearch);
            searchInputField.addEventListener("search", liveSearch);
        }

        function openProductModal(productId) {
            var myModal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
            document.getElementById('modalLoading').classList.remove('d-none');
            document.getElementById('modalProductContent').classList.add('d-none');
            myModal.show();

            fetch(`customer/getProductDetails.php?id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        myModal.hide();
                        return;
                    }

                    var select = document.getElementById('modalVariantSelect');
                    var dropdownContainer = document.getElementById('variantDropdownContainer');
                    select.setAttribute('data-product-id', data.product.id);

                    document.getElementById('modalProductName').innerText = data.product.product_name;
                    document.getElementById('modalCategory').innerText = data.product.category;

                    var mainProductImgPath = resolveImagePath(data.product.image);
                    document.getElementById('modalMainImage').src = mainProductImgPath;

                    select.innerHTML = "";

                    if (!data.variants || data.variants.length === 0) {
                        dropdownContainer.classList.add('d-none');

                        document.getElementById('modalDescription').innerText = data.product.product_description || "No description available.";
                        document.getElementById('modalDisplayPrice').innerText = parseFloat(data.product.price).toLocaleString(undefined, { minimumFractionDigits: 2 });
                        document.getElementById('modalDisplayStock').innerText = data.product.stock;

                        var qtyInput = document.getElementById('modalQtyInput');
                        if (parseInt(data.product.stock) <= 0) {
                            qtyInput.value = 0; qtyInput.disabled = true;
                        } else {
                            qtyInput.disabled = false; qtyInput.max = data.product.stock; qtyInput.value = 1;
                        }

                        calculateLiveTotal();
                    } else {
                        dropdownContainer.classList.remove('d-none');

                        var defaultOpt = document.createElement('option');
                        defaultOpt.value = "MAIN_PRODUCT";

                        var mainItemName = data.product.product_name.toUpperCase();
                        defaultOpt.innerText = `${mainItemName} (₱${parseFloat(data.product.price).toLocaleString(undefined, { minimumFractionDigits: 2 })})`;

                        defaultOpt.setAttribute('data-price', data.product.price);
                        defaultOpt.setAttribute('data-stock', data.product.stock);

                        var mainDesc = data.product.product_description ? data.product.product_description : "No description available.";
                        defaultOpt.setAttribute('data-desc', mainDesc);
                        defaultOpt.setAttribute('data-image', mainProductImgPath);
                        select.appendChild(defaultOpt);

                        data.variants.forEach(v => {
                            var opt = document.createElement('option');
                            opt.value = v.id;
                            opt.innerText = v.variant_name.toUpperCase();
                            opt.setAttribute('data-price', v.price);
                            opt.setAttribute('data-stock', v.stock);

                            var vDesc = (v.variant_description && v.variant_description.trim() !== "") ? v.variant_description : data.product.product_description;
                            opt.setAttribute('data-desc', vDesc ? vDesc : "No description available.");

                            var vImg = v.variant_image ? resolveImagePath(v.variant_image) : mainProductImgPath;
                            opt.setAttribute('data-image', vImg);

                            select.appendChild(opt);
                        });

                        updateModalVariantDetails();
                    }

                    document.getElementById('modalLoading').classList.add('d-none');
                    document.getElementById('modalProductContent').classList.remove('d-none');
                })
                .catch(err => {
                    console.error(err);
                    alert("Error loading product data.");
                    myModal.hide();
                });
        }

        function updateModalVariantDetails() {
            var select = document.getElementById('modalVariantSelect');
            if (select.options.length === 0 || select.value === "") return;

            var selectedOption = select.options[select.selectedIndex];
            var variantName = selectedOption.innerText;
            var baseProductName = document.getElementById('modalProductName').innerText;

            if (select.value !== "MAIN_PRODUCT") {
                document.getElementById('modalProductName').innerText = variantName;
            } else {
                document.getElementById('modalProductName').innerText = baseProductName;
            }

            var price = parseFloat(selectedOption.getAttribute('data-price')).toFixed(2);
            var stock = parseInt(selectedOption.getAttribute('data-stock'));
            var description = selectedOption.getAttribute('data-desc');
            var imageSrc = selectedOption.getAttribute('data-image');

            document.getElementById('modalDescription').innerText = description;
            document.getElementById('modalDisplayPrice').innerText = parseFloat(price).toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('modalDisplayStock').innerText = stock;

            if (imageSrc) {
                document.getElementById('modalMainImage').src = imageSrc;
            }

            var qtyInput = document.getElementById('modalQtyInput');
            if (stock <= 0) {
                qtyInput.max = 0; qtyInput.value = 0; qtyInput.disabled = true;
            } else {
                qtyInput.disabled = false; qtyInput.max = stock;
                if (parseInt(qtyInput.value) <= 0 || qtyInput.value == "") { qtyInput.value = 1; }
            }
            calculateLiveTotal();
        }

        function calculateLiveTotal() {
            var priceText = document.getElementById('modalDisplayPrice').innerText.replace(/,/g, '');
            var price = parseFloat(priceText) || 0;

            var qtyInput = document.getElementById('modalQtyInput');

            if (qtyInput.value === "") {
                document.getElementById('modalTotalPrice').innerText = "0.00";
                updateLinkParameters();
                return;
            }

            var qty = parseInt(qtyInput.value) || 0;
            var maxStock = parseInt(qtyInput.max) || 0;

            if (qty > maxStock) {
                qty = maxStock;
                qtyInput.value = maxStock;
            }

            var total = price * qty;
            document.getElementById('modalTotalPrice').innerText = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            updateLinkParameters();
        }

        function validateQtyInput() {
            var qtyInput = document.getElementById('modalQtyInput');
            var maxStock = parseInt(qtyInput.max) || 0;
            var qty = parseInt(qtyInput.value) || 0;

            if (qty < 1 && maxStock > 0) {
                qtyInput.value = 1;
                calculateLiveTotal();
            }
        }

        function updateLinkParameters() {
            var select = document.getElementById('modalVariantSelect');
            var dropdownContainer = document.getElementById('variantDropdownContainer');
            var productId = select.getAttribute('data-product-id');
            var qty = document.getElementById('modalQtyInput').value;

            if (dropdownContainer.classList.contains('d-none') || select.value === "MAIN_PRODUCT") {
                document.getElementById('modalAddToCartBtn').href = `customer/addtocart.php?id=${productId}&qty=${qty}`;
            } else {
                var variantId = select.value;
                document.getElementById('modalAddToCartBtn').href = `customer/addtocart.php?id=${productId}&variant_id=${variantId}&qty=${qty}`;
            }
        }

        function openModalImageZoom() {
            const imgSrc = document.getElementById('modalMainImage').src;
            document.getElementById('zoomImage').src = imgSrc;

            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());

            var modal = new bootstrap.Modal(document.getElementById('imageZoomModal'), {
                backdrop: true,
                keyboard: true
            });

            modal.show();
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('notif') && urlParams.get('notif') === 'added') {
            const notif = document.getElementById('notif-box');
            notif.style.display = 'block';

            setTimeout(() => {
                notif.style.display = 'none';
                window.history.replaceState({}, document.title, window.location.pathname);
            }, 3000);
        }
    </script>

    <footer id="footer" class="bg-dark text-white mt-5 pt-5 pb-3">
        <div class="container">
            <div class="row">
                <div class="col-md-3 text-center text-md-start">
                    <img src="assets/logo.jpg"
                        style="width:70px; height:70px; border-radius:50%; border:2px solid #ccc;">
                    <h5 class="mt-2">R. Taipan Construction Supply and Services</h5>
                    <p class="text-muted small">Your trusted supplier of roofing and construction materials.</p>
                </div>
                <div class="col-md-3">
                    <h5>Contact Us</h5>
                    <p class="small">📞 0907 456 5632<br>📧 rtaipancssopc@gmail.com</p><br>
                    <h5>Store Hours</h5>
                    <p class="small">⏰ 8:00AM - 5PM | MON. - SAT.</p>
                </div>
                <div class="col-md-3">
                    <h5>Branches</h5>
                    <p class="small mb-0">📍 190 Cabrera Rd. Sitio Burol Brgy. San Juan Taytay, Rizal (Main Branch)</p>
                    <a href="https://www.google.com/maps/place/Ronel+Steel+Roof+Trading+and+Hardware+Supply/@14.5602582,121.1374471,17z/data=!3m1!4b1!4m6!3m5!1s0x3397c727501f019b:0xe2dfa8a1b3aec65f!8m2!3d14.5602583!4d121.142318!16s%2Fg%2F11t2lf3b8r?entry=ttu&g_ep=EgoyMDI2MDQyOS4wIKXMDSoASAFQAw%3D%3D"
                        target="_blank" class="text-info small d-block mb-2">View Map</a>
                    <p class="small mb-0">📍 Gerona-Guimba Rd. Purok Masagana Brgy. Abagon Gerona Tarlac</p>
                    <a href="https://maps.app.goo.gl/EV3Bm6fdCaLFzzFi" target="_blank" class="text-info small">View
                        Map</a>
                    <p class="small mb-0">📍 Sitio Bato-bato 1 Brgy. San Juan Taytay, Rizal</p>
                    <a href="https://www.google.com/maps/place/R.+TAIPAN+CONSTRUCTION+SUPPLY+%26+SERVICES/@14.5644077,121.148692,149m/data=!3m1!1e3!4m15!1m8!3m7!1s0x3397c742f1b3202b:0x1fac35e429b5b598!2sCabrera+Rd,+Taytay,+1920+Rizal!3b1!8m2!3d14.5645132!4d121.1468918!16s%2Fg%2F1tg76l83!3m5!1s0x3397c70059cfff9b:0xd3229729a93f8cf5!8m2!3d14.5643595!4d121.1495273!16s%2Fg%2F11yrsfnwtl?entry=ttu&g_ep=EgoyMDI2MDQyOS4wIKXMDSoASAFQAw%3D%3D"
                        class="text-info small">View Map</a>
                </div>
                <div class="col-md-3 mb-4">
                    <h5>Follow Us on Facebook</h5>
                    <a href="https://www.facebook.com/share/1Acq7mfhEZ/?mibextid=wwXIfr" class="text-info small">View
                        Facebook Page</a><br><br>
                    <h5>Need Help?</h5>
                    <a href="customer/customerService.php" target="_blank" class="btn btn-primary btn-sm shadow-sm">
                        <i class="bi bi-telephone-fill"></i> Customer Service
                    </a>
                </div>
            </div>
            <hr>
            <div class="text-center small">© 2026 R. Taipan Hardware Store. All Rights Reserved.</div>
        </div>
    </footer>
</body>

</html>