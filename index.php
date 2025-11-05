<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/vpn.php';

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = str_replace('/vpn_api/', '', $path);
$path = str_replace('index.php/', '', $path);
$path = str_replace('index.php', '', $path);
$path = trim($path, '/');

// Parse query string
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

// Get request body for POST requests
$requestBody = null;
if ($method === 'POST') {
    $requestBody = json_decode(file_get_contents('php://input'), true);
}

// Response helper function
function sendResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// Root endpoint - API info
if (empty($path) || $path === 'index.php') {
    sendResponse(200, [
        'success' => true,
        'message' => 'VPN API Server',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => [
            'GET /' => 'API information',
            'GET /health' => 'Health check',
            'GET /test/db' => 'Database connection test',
            'GET /allocate?ip=[public_ip]' => 'VPN 키 할당받기',
            'POST /release' => 'VPN 키 반납하기',
            'GET /release/all?ip=[public_ip]' => '모든 VPN 키 반납하기',
            'GET /release/all?ip=[public_ip]&delete=true' => 'VPN 서버 완전 삭제 (재설치 시)',
            'GET /status?ip=[public_ip]' => 'VPN 상태 조회',
            'GET /list' => 'VPN 서버 목록 조회',
            'POST /server/register' => 'VPN 서버 등록',
            'POST /keys/register' => 'VPN 키 일괄 등록',
            'POST /cleanup' => '오래된 연결 정리'
        ]
    ]);
}

// Health check endpoint
if ($path === 'health') {
    sendResponse(200, [
        'success' => true,
        'message' => 'API is running',
        'timestamp' => date('Y-m-d H:i:s'),
        'server' => [
            'php_version' => phpversion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
        ]
    ]);
}

// Database test endpoint
if ($path === 'test/db') {
    try {
        $database = new Database();
        $result = $database->testConnection();
        $statusCode = $result['success'] ? 200 : 500;
        sendResponse($statusCode, $result);
    } catch(Exception $e) {
        sendResponse(500, [
            'success' => false,
            'message' => 'Database test failed: ' . $e->getMessage()
        ]);
    }
}

// =============================================
// VPN API Endpoints
// =============================================

$vpnApi = new VpnApi();

// 1. VPN 할당받기
if ($path === 'allocate' && $method === 'GET') {
    $public_ip = $query['ip'] ?? null;
    $result = $vpnApi->allocate($public_ip);
    $statusCode = $result['success'] ? 200 : 404;
    sendResponse($statusCode, $result);
}

// 2. VPN 반납하기
if ($path === 'release' && $method === 'POST') {
    $public_key = $requestBody['public_key'] ?? null;
    $result = $vpnApi->release($public_key);
    $statusCode = $result['success'] ? 200 : 400;
    sendResponse($statusCode, $result);
}

// 2-1. 모든 VPN 키 반납하기 (또는 서버 완전 삭제)
if ($path === 'release/all' && $method === 'GET') {
    $public_ip = $query['ip'] ?? null;
    $delete = isset($query['delete']) && ($query['delete'] === 'true' || $query['delete'] === '1');

    if ($delete) {
        // delete=true인 경우 서버 완전 삭제
        $result = $vpnApi->deleteServer($public_ip);
    } else {
        // 일반 키 반납
        $result = $vpnApi->releaseAll($public_ip);
    }

    $statusCode = $result['success'] ? 200 : 500;
    sendResponse($statusCode, $result);
}

// 3. VPN 상태 조회
if ($path === 'status' && $method === 'GET') {
    $public_ip = $query['ip'] ?? null;
    $result = $vpnApi->status($public_ip);
    $statusCode = $result['success'] ? 200 : 500;
    sendResponse($statusCode, $result);
}

// 4. VPN 서버 목록 조회
if ($path === 'list' && $method === 'GET') {
    $result = $vpnApi->listIPs();
    $statusCode = $result['success'] ? 200 : 500;
    sendResponse($statusCode, $result);
}

// 5. VPN 서버 등록
if ($path === 'server/register' && $method === 'POST') {
    $result = $vpnApi->registerServer($requestBody);
    $statusCode = $result['success'] ? 200 : 400;
    sendResponse($statusCode, $result);
}

// 6. VPN 키 일괄 등록
if ($path === 'keys/register' && $method === 'POST') {
    $result = $vpnApi->registerKeysBulk($requestBody);
    $statusCode = $result['success'] ? 200 : 400;
    sendResponse($statusCode, $result);
}

// 7. 오래된 연결 정리
if ($path === 'cleanup' && $method === 'POST') {
    $minutes = $requestBody['minutes'] ?? 10;
    $result = $vpnApi->cleanup($minutes);
    $statusCode = $result['success'] ? 200 : 500;
    sendResponse($statusCode, $result);
}

// 404 - Endpoint not found
sendResponse(404, [
    'success' => false,
    'message' => 'Endpoint not found',
    'path' => $path,
    'method' => $method
]);
?>
