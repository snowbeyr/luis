<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';

$clientId = $_ENV['GOOGLE_LOGIN_CLIENT_ID']
    ?? getenv('GOOGLE_LOGIN_CLIENT_ID')
    ?? $_ENV['GOOGLE_CLIENT_ID']
    ?? getenv('GOOGLE_CLIENT_ID');

$clientSecret = $_ENV['GOOGLE_LOGIN_CLIENT_SECRET']
    ?? getenv('GOOGLE_LOGIN_CLIENT_SECRET')
    ?? $_ENV['GOOGLE_CLIENT_SECRET']
    ?? getenv('GOOGLE_CLIENT_SECRET');

if (empty($clientId) || empty($clientSecret)) {
    $_SESSION['message'] = 'Google Login configuration is missing. Please check your credentials.';
    header('Location: login.php?auth=1');
    exit;
}

$configuredRedirect = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$protocol = $isHttps ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? '';

// Determine appropriate redirect URI
if (!empty($configuredRedirect) && (strpos($host, 'onrender.com') !== false || strpos($configuredRedirect, $host) !== false)) {
    // Always use the exact configured redirect URI on Render/production
    $redirectUri = $configuredRedirect;
} else {
    // Localhost or dynamic host
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $redirectUri = $protocol . $host . $basePath . '/google-oauth-callback.php';
}

$_SESSION['google_oauth_redirect_uri'] = $redirectUri;

$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);

$client->addScope('email');
$client->addScope('profile');
$client->setPrompt('select_account');

$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit;
