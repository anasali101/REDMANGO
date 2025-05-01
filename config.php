<?php
// Environment configuration
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'db');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'redmango');
define('DB_USER', $_ENV['DB_USER'] ?? 'redmango');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? 'password');

// Telegram Bot configuration
define('TELEGRAM_BOT_TOKEN', $_ENV['TELEGRAM_BOT_TOKEN']);
define('WEBHOOK_URL', $_ENV['WEBHOOK_URL']);

// Application settings
define('BASE_URL', $_ENV['BASE_URL'] ?? 'https://yourdomain.com/');
define('LINK_EXPIRY_HOURS', 48);

// Logging configuration
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? 'INFO');
define('LOG_FILE', __DIR__ . '/logs/redmango.log');

// Security settings
define('HASH_SALT', $_ENV['HASH_SALT'] ?? 'redmango-salt');
define('ENABLE_RECAPTCHA', $_ENV['ENABLE_RECAPTCHA'] ?? false);
define('RECAPTCHA_SITE_KEY', $_ENV['RECAPTCHA_SITE_KEY'] ?? '');
define('RECAPTCHA_SECRET_KEY', $_ENV['RECAPTCHA_SECRET_KEY'] ?? '');

// Initialize Logger
$logger = new Monolog\Logger('redmango');
$logger->pushHandler(new Monolog\Handler\StreamHandler(LOG_FILE, constant('Monolog\Logger::' . LOG_LEVEL)));