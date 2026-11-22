<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';

date_default_timezone_set('Asia/Manila');

function logOAuthAction($conn, int $userId, string $username, string $role, string $action, string $details): void
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
        error_log('OAuth Audit log error: ' . $e->getMessage());
    }
}

function redirectStaffRole(string $role): void
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
        default:
            header('Location: login.php');
            exit;
    }
}

if (isset($_GET['error'])) {
    $_SESSION['message'] = 'Google sign-in was cancelled or encountered an error.';
    header('Location: login.php?auth=1');
    exit;
}

if (empty($_GET['code'])) {
    header('Location: login.php?auth=1');
    exit;
}

try {
    $clientId = $_ENV['GOOGLE_LOGIN_CLIENT_ID']
        ?? getenv('GOOGLE_LOGIN_CLIENT_ID')
        ?? $_ENV['GOOGLE_CLIENT_ID']
        ?? getenv('GOOGLE_CLIENT_ID');

    $clientSecret = $_ENV['GOOGLE_LOGIN_CLIENT_SECRET']
        ?? getenv('GOOGLE_LOGIN_CLIENT_SECRET')
        ?? $_ENV['GOOGLE_CLIENT_SECRET']
        ?? getenv('GOOGLE_CLIENT_SECRET');

    $redirectUri = $_SESSION['google_oauth_redirect_uri']
        ?? ($_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI'));

    $client = new Google\Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri($redirectUri);

    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        throw new Exception($token['error_description'] ?? $token['error']);
    }

    $client->setAccessToken($token);

    $oauth2 = new Google\Service\Oauth2($client);
    $googleUser = $oauth2->userinfo->get();

    $email = filter_var($googleUser->email, FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Invalid email address received from Google.');
    }

    $fullname = trim((string) ($googleUser->name ?? ''));
    $picture  = (string) ($googleUser->picture ?? '');

    // Check if user already exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if (($user['status'] ?? 'Active') !== 'Active') {
            $_SESSION['message'] = 'Your account is deactivated. Please contact support.';
            header('Location: login.php?auth=1');
            exit;
        }

        // Update profile picture if user has none
        if (empty($user['profile_image']) && !empty($picture)) {
            try {
                $updatePic = $pdo->prepare("UPDATE users SET profile_image = :pic WHERE id = :id");
                $updatePic->execute([':pic' => $picture, ':id' => $user['id']]);
            } catch (Throwable $ignore) {}
        }

        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['username']  = (string) $user['username'];
        $_SESSION['full_name'] = (string) ($user['fullname'] ?? $user['username']);
        $_SESSION['role']      = (string) $user['role'];
        $_SESSION['message']   = 'Welcome back, ' . htmlspecialchars((string) $_SESSION['full_name'], ENT_QUOTES, 'UTF-8') . '!';

        logOAuthAction(
            $pdo,
            (int) $user['id'],
            (string) $user['username'],
            (string) $user['role'],
            'Google Login',
            'Customer logged in via Google OAuth'
        );

        if ((string) $user['role'] === 'customer') {
            header('Location: login.php');
            exit;
        }

        redirectStaffRole((string) $user['role']);
    } else {
        // Register new customer in users table
        $baseUsername = strtolower(explode('@', $email)[0]);
        $baseUsername = preg_replace('/[^a-z0-9_]/', '', $baseUsername);
        if (empty($baseUsername)) {
            $baseUsername = 'customer';
        }

        $username = $baseUsername;
        $counter = 1;
        $checkUserStmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");

        while (true) {
            $checkUserStmt->execute([':u' => $username]);
            if (!$checkUserStmt->fetch()) {
                break;
            }
            $username = $baseUsername . $counter;
            $counter++;
        }

        $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $displayName = !empty($fullname) ? $fullname : $username;

        $insertStmt = $pdo->prepare("
            INSERT INTO users (fullname, username, email, password, role, status, profile_image)
            VALUES (:fullname, :username, :email, :password, 'customer', 'Active', :profile_image)
            RETURNING id
        ");

        $insertStmt->execute([
            ':fullname'      => $displayName,
            ':username'      => $username,
            ':email'         => $email,
            ':password'      => $dummyPassword,
            ':profile_image' => !empty($picture) ? $picture : null
        ]);

        $newUserId = (int) $insertStmt->fetchColumn();

        session_regenerate_id(true);
        $_SESSION['user_id']   = $newUserId;
        $_SESSION['username']  = $username;
        $_SESSION['full_name'] = $displayName;
        $_SESSION['role']      = 'customer';
        $_SESSION['message']   = 'Account created! Welcome to R. Taipan Hardware, ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '!';

        logOAuthAction(
            $pdo,
            $newUserId,
            $username,
            'customer',
            'Google Register',
            'New customer account registered via Google OAuth'
        );

        header('Location: login.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('Google OAuth Callback Error: ' . $e->getMessage());
    $_SESSION['message'] = 'Google Login failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    header('Location: login.php?auth=1');
    exit;
}
