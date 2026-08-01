<?php
require_once __DIR__ . '/../config/helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-API-Sign, X-API-Timestamp, X-API-Nonce');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$start_time = microtime(true);

$_api_log_data = null;

function api_response($code, $message, $data = null, $http_code = 200) {
    global $start_time, $api_auth, $_api_log_data;
    $response = [
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'timestamp' => time(),
    ];
    $response_time = round((microtime(true) - $start_time) * 1000);
    $response['response_time_ms'] = $response_time;
    http_response_code($http_code);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
    $log_endpoint = $_SERVER['REQUEST_URI'] ?? '';
    $log_params = file_get_contents('php://input') ?: json_encode($_REQUEST);
    $api_key_id = isset($api_auth['api_key_id']) ? $api_auth['api_key_id'] : null;
    $user_id = isset($api_auth['user_id']) ? $api_auth['user_id'] : null;
    
    $_api_log_data = [
        'api_key_id' => $api_key_id,
        'user_id' => $user_id,
        'method' => $_SERVER['REQUEST_METHOD'],
        'endpoint' => substr($log_endpoint, 0, 200),
        'params' => substr($log_params, 0, 5000),
        'ip' => get_real_ip(),
        'status_code' => $code,
        'response_time' => $response_time,
        'error_msg' => $code !== 0 ? substr($message, 0, 255) : '',
    ];
    
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    register_shutdown_function(function() use (&$_api_log_data) {
        if ($_api_log_data !== null) {
            try {
                @Database::insert('api_request_logs', $_api_log_data);
            } catch (Exception $e) {}
            $_api_log_data = null;
        }
    });
    
    exit;
}

function api_error($code, $message, $http_code = 400) {
    api_response($code, $message, null, $http_code);
}

function api_success($data = null, $message = 'success') {
    api_response(0, $message, $data, 200);
}

function get_real_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return trim($ip);
}

function api_get_input() {
    static $input = null;
    if ($input === null) {
        $raw = file_get_contents('php://input');
        $input = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_merge($_GET, $_POST, $input);
}

function api_param($key, $default = null, $required = false) {
    $input = api_get_input();
    if (!isset($input[$key]) || $input[$key] === '') {
        if ($required) {
            api_error(40001, "参数 {$key} 不能为空");
        }
        return $default;
    }
    return $input[$key];
}

$api_auth = null;

function api_authenticate() {
    global $api_auth;
    
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $api_sign = $_SERVER['HTTP_X_API_SIGN'] ?? '';
    $api_timestamp = intval($_SERVER['HTTP_X_API_TIMESTAMP'] ?? 0);
    $api_nonce = $_SERVER['HTTP_X_API_NONCE'] ?? '';
    
    if (empty($api_key)) {
        api_error(40101, '缺少 X-API-Key 头', 401);
    }
    
    migrate_new_tables();
    
    $key_row = Database::fetch("SELECT * FROM api_keys WHERE api_key = ? LIMIT 1", [$api_key]);
    if (!$key_row) {
        api_error(40102, 'API Key 无效', 401);
    }
    
    if ($key_row['status'] === 'pending') {
        api_error(40106, 'API Key 正在审核中，请等待管理员审核通过', 401);
    }
    if ($key_row['status'] === 'rejected') {
        $reason = !empty($key_row['reject_reason']) ? '：' . $key_row['reject_reason'] : '';
        api_error(40107, 'API Key 审核未通过' . $reason, 401);
    }
    if ($key_row['status'] !== 'active') {
        api_error(40103, 'API Key 已被禁用', 401);
    }
    
    if (!empty($key_row['expires_at']) && strtotime($key_row['expires_at']) < time()) {
        api_error(40104, 'API Key 已过期', 401);
    }
    
    if (!empty($key_row['ip_whitelist'])) {
        $ip = get_real_ip();
        $whitelist = array_map('trim', explode(',', $key_row['ip_whitelist']));
        if (!in_array($ip, $whitelist)) {
            api_error(40105, 'IP地址不在白名单内', 403);
        }
    }
    
    if (!empty($api_sign)) {
        if (empty($api_timestamp) || abs(time() - $api_timestamp) > 300) {
            api_error(40106, '请求时间戳无效或超时', 401);
        }
        if (empty($api_nonce)) {
            api_error(40107, '缺少 Nonce', 401);
        }
        
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $body = file_get_contents('php://input') ?: '';
        $sign_string = $api_key . $method . $uri . $api_timestamp . $api_nonce . $body;
        $expected_sign = hash_hmac('sha256', $sign_string, $key_row['api_secret']);
        
        if (!hash_equals($expected_sign, $api_sign)) {
            api_error(40108, '签名验证失败', 401);
        }
    }
    
    $minute_start = floor(time() / 60) * 60;
    $request_count = Database::fetch("SELECT COUNT(*) as cnt FROM api_request_logs 
        WHERE api_key_id = ? AND created_at >= FROM_UNIXTIME(?)", 
        [$key_row['id'], $minute_start]);
    
    $rate_limit = intval($key_row['rate_limit'] ?? 100);
    if ($rate_limit > 0 && intval($request_count['cnt'] ?? 0) >= $rate_limit) {
        api_error(42901, '请求频率超限，请稍后再试', 429);
    }
    
    Database::query("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?", [$key_row['id']]);
    
    $api_auth = [
        'api_key_id' => $key_row['id'],
        'user_id' => $key_row['user_id'],
        'api_key' => $key_row['api_key'],
        'rate_limit' => $rate_limit,
    ];
    
    return $api_auth;
}

$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri_path = str_replace('/api/index.php', '', $request_uri);
$uri_path = str_replace('/api/', '/', $uri_path);
$uri_path = trim($uri_path, '/');

$public_endpoints = ['ping', 'auth/token'];
$needs_auth = !in_array($uri_path, $public_endpoints);

if ($needs_auth) {
    api_authenticate();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($uri_path === 'ping' || $uri_path === '') {
    api_success([
        'service' => 'guojici Cloud API',
        'version' => '1.0.0',
        'time' => date('Y-m-d H:i:s'),
        'endpoints' => [
            'vms' => '/api/vms - 虚拟机管理',
            'hosts' => '/api/hosts - 主机列表',
            'orders' => '/api/orders - 订单管理',
            'billing' => '/api/billing - 账单查询',
            'packages' => '/api/packages - 套餐列表',
        ],
    ]);
}

$route_segments = explode('/', $uri_path);
$resource = $route_segments[0] ?? '';
$resource_id = $route_segments[1] ?? null;
$action = $route_segments[2] ?? null;

if ($resource === 'vms' || $resource === 'hosts') {
    require_once __DIR__ . '/api_vms.php';
    handle_vm_api($method, $resource_id, $action);
} elseif ($resource === 'orders') {
    require_once __DIR__ . '/api_orders.php';
    handle_order_api($method, $resource_id, $action);
} elseif ($resource === 'billing') {
    require_once __DIR__ . '/api_billing.php';
    handle_billing_api($method, $resource_id, $action);
} elseif ($resource === 'packages') {
    require_once __DIR__ . '/api_packages.php';
    handle_package_api($method, $resource_id, $action);
} elseif ($resource === 'users') {
    require_once __DIR__ . '/api_users.php';
    handle_user_api($method, $resource_id, $action);
} else {
    api_error(40401, '接口不存在: ' . $uri_path, 404);
}
