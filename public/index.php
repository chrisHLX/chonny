<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// The desktop app's measure-version question, answered without booting Laravel (2026-10-13): apps
// up to 0.5.2 ask it every ten seconds, about 8,600 times a day each, and a full boot per ask would
// spend the one core on it. The version is not secret; a revoked key still learns so from every
// other route (CoachUploadController::version() keeps the full answer for anything not matched here).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/api/coach/version'
    && str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer mc_')) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['version' => App\Http\Services\RoundAnalysisService::VERSION]);
    exit;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
