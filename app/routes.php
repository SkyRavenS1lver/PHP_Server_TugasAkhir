<?php
// app/routes.php
require_once __DIR__ . '/controllers/HealthController.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/SyncController.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// ============================================
// CORS MIDDLEWARE (ALL ROUTES)
// ============================================
$f3->route('GET|POST|PUT|DELETE|PATCH|OPTIONS *', function($f3) {
    header('Access-Control-Allow-Origin: ' . $f3->get('CORS_ORIGIN'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');

    if ($f3->get('VERB') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}, 0); // Priority 0 = runs first

// ============================================
// PUBLIC ROUTES
// ============================================

// Health check
$f3->route('GET /', 'HealthController->index');
$f3->route('GET /health', 'HealthController->index');

// Authentication
$f3->route('POST /api/auth/register', 'AuthController->register');
$f3->route('POST /api/auth/login', 'AuthController->login');

// ============================================
// SYNC ROUTES (Offline-First Architecture)
// ============================================

/**
 * ONE-WAY SYNC: Food Database (Backend → Android)
 * GET /api/sync/foods?last_sync=2024-01-01T00:00:00Z
 * 
 * Returns all food data (data_makanan, urt_list, data_makanan_urt)
 * that changed since last_sync timestamp
 */
$f3->route('GET /api/sync/foods', function($f3) {
    // AuthMiddleware::verify();
    $controller = new SyncController();
    $controller->syncFoods();
});

/**
 * TWO-WAY SYNC: User Profile (Android ↔ Backend)
 * POST /api/sync/profile
 * 
 * Syncs user profile with conflict resolution (server-wins)
 * Body: { "profile": {...}, "updated_at": "..." }
 */
$f3->route('POST /api/sync/profile', function($f3) {
    AuthMiddleware::verify();
    $controller = new SyncController();
    $controller->syncProfile();
});

/**
 * TWO-WAY SYNC: Consumption History (Android ↔ Backend)
 * POST /api/sync/consumptions
 * 
 * Syncs meal logs with conflict resolution
 * Body: { "last_sync": "...", "local_changes": [...] }
 */
$f3->route('POST /api/sync/consumptions', function($f3) {
    AuthMiddleware::verify();
    $controller = new SyncController();
    $controller->syncConsumptions();
});

/**
 * Sync Status Check
 * GET /api/sync/status
 *
 * Returns server timestamp and sync status
 */
$f3->route('GET /api/sync/status', function($f3) {
    AuthMiddleware::verify();
    $controller = new SyncController();
    $controller->status();
});

/**
 * Activity Analysis
 * POST /api/analyze-activity
 *
 * Analyzes activity description and returns activity level
 */
$f3->route('POST /api/analyze-activity', function($f3) {
    // AuthMiddleware::verify();

    $body = json_decode($f3->get('BODY'), true);
    $activity_description = $body['activity_description'] ?? '';

    $ch = curl_init('http://localhost:5000/api/analyze-activity');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['activity_description' => $activity_description]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    header('Content-Type: application/json');
    http_response_code($httpCode);
    echo $response;
});

// ============================================
// 404 HANDLER
// ============================================
$f3->route('GET|POST|PUT|DELETE|PATCH *', function($f3) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Endpoint not found',
        'path' => $f3->get('PATH'),
        'method' => $f3->get('VERB')
    ]);
});