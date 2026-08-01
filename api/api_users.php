<?php
function handle_user_api($method, $resource_id, $action) {
    global $api_auth;
    $uid = $api_auth['user_id'];
    
    if ($method === 'GET' && $resource_id === 'info') {
        $user = Database::fetch("SELECT id, username, email, phone, balance, status, created_at, last_login_at FROM users WHERE id = ?", [$uid]);
        if (!$user) {
            api_error(40406, '用户不存在', 404);
        }
        api_success([
            'id' => intval($user['id']),
            'username' => $user['username'],
            'email' => $user['email'] ?? '',
            'phone' => $user['phone'] ?? '',
            'balance' => floatval($user['balance'] ?? 0),
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'last_login_at' => $user['last_login_at'] ?? null,
        ]);
    }
    
    if ($method === 'GET' && $resource_id === 'stats') {
        $host_count = Database::fetch("SELECT COUNT(*) as cnt FROM hosts WHERE user_id = ? AND status NOT IN ('deleted','cancelled')", [$uid]);
        $running_count = Database::fetch("SELECT COUNT(*) as cnt FROM hosts WHERE user_id = ? AND status = 'running'", [$uid]);
        $order_count = Database::fetch("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ?", [$uid]);
        $balance = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
        
        api_success([
            'total_hosts' => intval($host_count['cnt']),
            'running_hosts' => intval($running_count['cnt']),
            'total_orders' => intval($order_count['cnt']),
            'balance' => floatval($balance['balance'] ?? 0),
        ]);
    }
    
    if ($method === 'POST' && $resource_id === 'api_keys' && !$action) {
        create_api_key($uid);
    }
    
    if ($method === 'GET' && $resource_id === 'api_keys') {
        list_api_keys($uid);
    }
    
    if ($method === 'DELETE' && $resource_id === 'api_keys' && $action) {
        delete_api_key($uid, $action);
    }
    
    api_error(40406, '接口不存在', 404);
}

function create_api_key($uid) {
    migrate_new_tables();
    
    $key_name = api_param('name', 'API Key', true);
    $ip_whitelist = api_param('ip_whitelist', '');
    $rate_limit = min(1000, max(10, intval(api_param('rate_limit', 100))));
    $expires_at = api_param('expires_at', null);
    
    $api_key = 'sk_' . substr(md5($uid . time() . rand()), 0, 32);
    $api_secret = bin2hex(random_bytes(32));
    
    $id = Database::insert('api_keys', [
        'user_id' => $uid,
        'key_name' => $key_name,
        'api_key' => $api_key,
        'api_secret' => $api_secret,
        'status' => 'active',
        'ip_whitelist' => $ip_whitelist,
        'rate_limit' => $rate_limit,
        'expires_at' => $expires_at ?: null,
    ]);
    
    api_success([
        'id' => $id,
        'key_name' => $key_name,
        'api_key' => $api_key,
        'api_secret' => $api_secret,
        'rate_limit' => $rate_limit,
        'expires_at' => $expires_at,
        'message' => '请妥善保存 api_secret，只显示一次',
    ], 'API Key 创建成功');
}

function list_api_keys($uid) {
    migrate_new_tables();
    
    $keys = Database::fetchAll("SELECT id, key_name, api_key, status, rate_limit, ip_whitelist, last_used_at, created_at, expires_at 
        FROM api_keys WHERE user_id = ? ORDER BY id DESC", [$uid]);
    
    foreach ($keys as &$k) {
        unset($k['api_secret']);
    }
    
    api_success(['list' => $keys, 'total' => count($keys)]);
}

function delete_api_key($uid, $key_id) {
    migrate_new_tables();
    
    $key = Database::fetch("SELECT id FROM api_keys WHERE id = ? AND user_id = ?", [$key_id, $uid]);
    if (!$key) {
        api_error(40407, 'API Key 不存在', 404);
    }
    
    Database::query("DELETE FROM api_keys WHERE id = ? AND user_id = ?", [$key_id, $uid]);
    
    api_success(null, '删除成功');
}
