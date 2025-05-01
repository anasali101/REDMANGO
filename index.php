<?php
// Initialize the application
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/link_shortener.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Check if this is a shortened URL request
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $linkManager = new RedMango\LinkShortener($db);
    $originalUrl = $linkManager->resolveShortLink($code);
    
    if ($originalUrl) {
        // Redirect to the original URL
        header("Location: " . $originalUrl);
        exit;
    } else {
        // Link not found or expired, show error page
        include 'error.php';
        exit;
    }
}

// If no code parameter, this is likely a direct access to the index
// Show a simple landing page or redirect to Telegram
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RedMango - URL Shortener</title>
    <link rel="stylesheet" href="/public/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
</head>
<body>
    <div class="container">
        <div class="content-box animate__animated animate__fadeIn">
            <div class="logo animate__animated animate__bounce">
                <img src="/public/img/logo.png" alt="RedMango Logo">
            </div>
            <h1>RedMango URL Shortener</h1>
            <p>Create short, custom URLs that expire after 48 hours.</p>
            <div class="telegram-link">
                <a href="https://t.me/RedMangoBot" class="btn animate__animated animate__pulse animate__infinite">
                    Open RedMango Bot on Telegram
                </a>
            </div>
            <div class="features">
                <div class="feature animate__animated animate__fadeInUp">
                    <h3>Easy to Use</h3>
                    <p>Simply paste your link and get a shortened version instantly.</p>
                </div>
                <div class="feature animate__animated animate__fadeInUp animate__delay-1s">
                    <h3>Custom URLs</h3>
                    <p>Create memorable custom short links.</p>
                </div>
                <div class="feature animate__animated animate__fadeInUp animate__delay-2s">
                    <h3>48-Hour Expiry</h3>
                    <p>All links automatically expire after 48 hours for security.</p>
                </div>
            </div>
        </div>
    </div>
    <script src="/public/js/script.js"></script>
</body>
</html>
