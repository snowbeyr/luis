<?php
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';

$clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET');

if (empty($clientId) || empty($clientSecret)) {
    $_SESSION['message'] = 'Google Login configuration is missing. Please check your credentials.';
    header('Location: login.php?auth=1');
    exit;
}

$configuredRedirect = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? '';

// Determine appropriate redirect URI
if (!empty($configuredRedirect) && !empty($host) && strpos($configuredRedirect, $host) !== false) {
    $redirectUri = $configuredRedirect;
} elseif (!empty($configuredRedirect) && strpos($host, 'onrender.com') !== false) {
    $redirectUri = $configuredRedirect;
} else {
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $redirectUri = $protocol . $host . $basePath . '/google-oauth-callback.php';
    if (!empty($configuredRedirect) && (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false)) {
        $redirectUri = $configuredRedirect;
    }
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
