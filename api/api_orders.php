<?php
function handle_order_api($method, $resource_id, $action) {
    global $api_auth;
    $uid = $api_auth['user_id'];
    
    if ($method === 'GET' && !$resource_id) {
        $page = max(1, intval(api_param('page', 1)));
        $page_size = min(100, max(1, intval(api_param('page_size', 20))));
        $status = api_param('status', '');
        
        $where = "WHERE user_id = ?";
        $params = [$uid];
        
        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        
        $total = Database::fetch("SELECT COUNT(*) as cnt FROM orders $where", $params);
        $offset = ($page - 1) * $page_size;
        $orders = Database::fetchAll("SELECT * FROM orders $where ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, [$page_size, $offset]));
        
        $list = [];
        foreach ($orders as $o) {
            $list[] = format_order_data($o);
        }
        
        api_success([
            'list' => $list,
            'total' => intval($total['cnt']),
            'page' => $page,
            'page_size' => $page_size,
        ]);
    }
    
    if ($method === 'GET' && $resource_id) {
        $order = get_order_by_identifier($resource_id, $uid);
        if (!$order) {
            api_error(40403, '订单不存在', 404);
        }
        api_success(format_order_data($order));
    }
    
    if ($method === 'POST' && $resource_id && $action === 'renew') {
        order_renew_api($resource_id, $uid);
    }
    
    api_error(40403, '接口不存在', 404);
}

function get_order_by_identifier($id, $uid) {
    if (is_numeric($id)) {
        return Database::fetch("SELECT * FROM orders WHERE id = ? AND user_id = ?", [$id, $uid]);
    }
    return Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [$id, $uid]);
}

function format_order_data($order) {
    return [
        'id' => intval($order['id']),
        'order_no' => $order['order_no'] ?? '',
        'package_id' => intval($order['package_id'] ?? 0),
        'package_name' => $order['package_name'] ?? '',
        'billing_cycle' => $order['billing_cycle'] ?? '',
        'amount' => floatval($order['amount'] ?? 0),
        'status' => $order['status'] ?? '',
        'payment_method' => $order['payment_method'] ?? '',
        'quantity' => intval($order['quantity'] ?? 1),
        'paid_at' => $order['paid_at'] ?? null,
        'created_at' => $order['created_at'] ?? '',
        'remark' => $order['remark'] ?? '',
    ];
}

function order_renew_api($id, $uid) {
    $order = get_order_by_identifier($id, $uid);
    if (!$order) {
        api_error(40403, '订单不存在', 404);
    }
    
    $billing_cycle = api_param('billing_cycle', 'monthly');
    $cycle_map = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
    $months = $cycle_map[$billing_cycle] ?? 1;
    
    $pkg = Database::fetch("SELECT * FROM packages WHERE id = ?", [$order['package_id']]);
    if (!$pkg) {
        api_error(40011, '套餐不存在');
    }
    
    $amount = floatval($pkg['price'] ?? 0) * $months;
    
    $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
    if (floatval($user['balance'] ?? 0) < $amount) {
        api_error(40003, '余额不足，需要 ' . $amount . ' 元');
    }
    
    $host = Database::fetch("SELECT * FROM hosts WHERE order_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1", [$order['id']]);
    if (!$host) {
        api_error(40012, '未找到关联主机');
    }
    
    Database::query("START TRANSACTION");
    try {
        Database::query("UPDATE users SET balance = balance - ? WHERE id = ?", [$amount, $uid]);
        
        $old_expire = $host['expire_at'] ? strtotime($host['expire_at']) : time();
        $new_expire = max(time(), $old_expire) + $months * 30 * 86400;
        
        $was_suspended = in_array($host['status'], ['suspended', 'suspended_traffic']);
        $new_status = $was_suspended ? 'running' : $host['status'];
        
        Database::update('hosts', [
            'expire_at' => date('Y-m-d H:i:s', $new_expire),
            'status' => $new_status,
        ], 'id = ?', [$host['id']]);
        
        $renew_order_no = 'RENEW' . date('YmdHis') . rand(100, 999);
        $renew_order_id = Database::insert('orders', [
            'order_no' => $renew_order_no,
            'user_id' => $uid,
            'package_id' => $order['package_id'],
            'package_name' => $pkg['name'],
            'billing_cycle' => $billing_cycle,
            'amount' => $amount,
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => 'balance',
            'quantity' => 1,
            'remark' => '续费主机 #' . $host['id'],
        ]);
        
        create_billing_record_api($uid, $host['id'], $renew_order_id, 'renew', $amount, $billing_cycle, '续费: ' . $pkg['name']);
        
        Database::query("COMMIT");
        
        api_success([
            'order_id' => $renew_order_id,
            'order_no' => $renew_order_no,
            'amount' => $amount,
            'new_expire_at' => date('Y-m-d H:i:s', $new_expire),
            'host_id' => $host['id'],
            'status' => $new_status,
        ], '续费成功');
    } catch (Exception $e) {
        Database::query("ROLLBACK");
        api_error(50004, '续费失败: ' . $e->getMessage(), 500);
    }
}

function create_billing_record_api($uid, $host_id, $order_id, $type, $amount, $period, $description) {
    migrate_new_tables();
    $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
    Database::insert('billing_records', [
        'user_id' => $uid,
        'host_id' => $host_id,
        'order_id' => $order_id,
        'bill_type' => $type,
        'amount' => $amount,
        'balance_before' => floatval($user['balance'] ?? 0) + $amount,
        'balance_after' => floatval($user['balance'] ?? 0),
        'description' => $description,
        'billing_period' => $period,
        'status' => 'paid',
    ]);
}
