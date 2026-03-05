<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/jwt.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/profile.php';
require_once __DIR__ . '/routes/classes.php';
require_once __DIR__ . '/routes/qr.php';
require_once __DIR__ . '/routes/attendance.php';
require_once __DIR__ . '/routes/leave_requests.php';

// ── CORS ──────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:4173',
    'http://localhost:8080',
    'http://project.reazon.com'
    'http://hajirihub.reazon.me'
    'https://hajirihub-api-production.up.railway.app',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
} else {
    header('Access-Control-Allow-Origin: http://localhost:5173');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function jsonOk(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw ?: '{}', true) ?? [];
}

function generateUUID(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Router ────────────────────────────────────────────────────────────────────
$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = rtrim($uri, '/');
$segments = array_values(array_filter(explode('/', $uri)));

// Remove a base path prefix if served under /api
if (($segments[0] ?? '') === 'api') {
    array_shift($segments);
}

$resource = $segments[0] ?? '';

try {
    match(true) {
        $resource === 'auth'        => handleAuth($method, $segments),
        $resource === 'profile'     => handleProfile($method, $segments),
        $resource === 'classes'     => handleClasses($method, $segments),
        $resource === 'qr-sessions' => handleQr($method, $segments),
        $resource === 'attendance'      => handleAttendance($method, $segments),
        $resource === 'leave-requests'  => handleLeaveRequests($method, $segments),
        $resource === 'health'      => jsonOk(['status' => 'ok', 'time' => date('c')]),
        default                     => jsonError('Endpoint not found', 404),
    };
} catch (Throwable $e) {
    error_log('Hajiri API error: ' . $e->getMessage());
    jsonError('Internal server error', 500);
}
