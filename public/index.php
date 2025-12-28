<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__  . '/..');
$dotenv->load();

$f3 = \Base::instance();

// Set configuration from environment variables
$f3->set('DEBUG', $_ENV['DEBUG'] ?? 0);
$f3->set('APP_NAME', $_ENV['APP_NAME'] ?? 'Fat-Free API');
$f3->set('APP_VERSION', $_ENV['APP_VERSION'] ?? '1.0.0');
$f3->set('JWT_SECRET', $_ENV['JWT_SECRET']);
$f3->set('JWT_ALGORITHM', $_ENV['JWT_ALGORITHM'] ?? 'HS256');
$f3->set('JWT_EXPIRY', $_ENV['JWT_EXPIRY'] ?? 86400);
$f3->set('CORS_ORIGIN', $_ENV['CORS_ORIGIN'] ?? '*');

// Set timezone
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'UTC');

// Enable error handling in development
if ($f3->get('DEBUG') >= 3) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Set up database connection
$db = new \DB\SQL(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?: 'localhost',
        $_ENV['DB_PORT'] ?: '3306',
        $_ENV['DB_NAME'] ?: 'myapp'
    ),
    $_ENV['DB_USER'],
    $_ENV['DB_PASSWORD']
);
$f3->set('DB', $db);

// Load routes
require_once __DIR__ . '/../app/routes.php';

// Run the application
$f3->run();