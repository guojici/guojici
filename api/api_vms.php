<?php
function handle_vm_api($method, $resource_id, $action) {
    global $api_auth;
    $uid = $api_auth['user_id'];

    if ($method === 'GET' && !$resource_id) {
        $page = max(1, intval(api_param('page', 1)));
        $page_size = min(100, max(1, intval(api_param('page_size', 20))));
        $status = api_param('status', '');
        $type = api_param('type', '');
        
        $where = "WHERE user_id = ?";
        $params = [$uid];
        
        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        if ($type) {
            $where .= " AND vm_type = ?";
            $params[] = $type;
        }
        
        $total = Database::fetch("SELECT COUNT(*) as cnt FROM hosts $where", $params);
        $offset = ($page - 1) * $page_size;
        $hosts = Database::fetchAll("SELECT * FROM hosts $where ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, [$page_size, $offset]));
        
        $list = [];
        foreach ($hosts as $h) {
            $list[] = format_vm_data($h);
        }
        
        api_success([
            'list' => $list,
            'total' => intval($total['cnt']),
            'page' => $page,
            'page_size' => $page_size,
        ]);
    }
    
    if ($method === 'GET' && $resource_id && !$action) {
        $host = get_vm_by_identifier($resource_id, $uid);
        if (!$host) {
            api_error(40402, '主机不存在', 404);
        }
        api_success(format_vm_data($host));
    }
    
    if ($method === 'POST' && !$resource_id) {
        create_vm_via_api($uid);
    }
    
    if ($method === 'POST' && $resource_id && $action === 'start') {
        vm_action($resource_id, $uid, 'start');
    }
    
    if ($method === 'POST' && $resource_id && $action === 'stop') {
        vm_action($resource_id, $uid, 'stop');
    }
    
    if ($method === 'POST' && $resource_id && $action === 'restart') {
        vm_action($resource_id, $uid, 'restart');
    }
    
    if ($method === 'POST' && $resource_id && $action === 'reinstall') {
        vm_reinstall_via_api($resource_id, $uid);
    }
    
    if ($method === 'POST' && $resource_id && $action === 'reset_password') {
        vm_reset_password_via_api($resource_id, $uid);
    }
    
    if ($method === 'DELETE' && $resource_id) {
        vm_delete_via_api($resource_id, $uid);
    }
    
    if ($method === 'GET' && $resource_id && $action === 'status') {
        vm_status_detail($resource_id, $uid);
    }
    
    if ($method === 'GET' && $resource_id && $action === 'traffic') {
        vm_traffic_info($resource_id, $uid);
    }
    
    if ($method === 'POST' && $action === 'batch_create') {
        batch_create_vm($uid);
    }
    
    if ($method === 'POST' && $action === 'batch_start') {
        batch_vm_action($uid, 'start');
    }
    
    if ($method === 'POST' && $action === 'batch_stop') {
        batch_vm_action($uid, 'stop');
    }
    
    api_error(40402, '接口不存在', 404);
}

function get_vm_by_identifier($id, $uid) {
    if (is_numeric($id)) {
        return Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$id, $uid]);
    }
    return Database::fetch("SELECT * FROM hosts WHERE (uuid = ? OR vm_name = ?) AND user_id = ?", [$id, $id, $uid]);
}

function format_vm_data($host) {
    $is_kvm = !empty($host['vm_type']) && $host['vm_type'] === 'kvm';
    $data = [
        'id' => intval($host['id']),
        'uuid' => $host['uuid'] ?? '',
        'name' => $host['vm_name'] ?? $host['host_name'] ?? '',
        'type' => $host['vm_type'] ?? '',
        'status' => $host['status'] ?? '',
        'package_id' => intval($host['package_id'] ?? 0),
        'package_name' => $host['package_name'] ?? '',
        'ip_address' => $host['ip_address'] ?? '',
        'public_ip' => $host['public_ip'] ?? '',
        'port' => intval($host['port'] ?? 0),
        'cpu' => intval($host['vm_cpu'] ?? $host['kvm_vcpu'] ?? 0),
        'memory_mb' => intval($host['vm_memory'] ?? $host['kvm_memory_mb'] ?? 0),
        'disk_gb' => intval($host['vm_disk'] ?? $host['kvm_disk_gb'] ?? 0),
        'bandwidth_mbps' => intval($host['kvm_bandwidth_mbps'] ?? 0),
        'traffic_used_mb' => intval($host['traffic_used'] ?? 0),
        'traffic_limit_mb' => intval($host['traffic_limit'] ?? 0),
        'os' => $host['os_name'] ?? $host['image_name'] ?? '',
        'created_at' => $host['created_at'] ?? '',
        'expire_at' => $host['expire_at'] ?? null,
        'suspend_reason' => $host['suspend_reason'] ?? '',
        'node_id' => intval($host['node_id'] ?? 0),
    ];
    return $data;
}

function create_vm_via_api($uid) {
    $package_id = intval(api_param('package_id', 0, true));
    $billing_cycle = api_param('billing_cycle', 'monthly');
    $quantity = min(10, max(1, intval(api_param('quantity', 1))));
    $image_id = intval(api_param('image_id', 0));
    $hostname = api_param('hostname', '');
    
    $pkg = Database::fetch("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$package_id]);
    if (!$pkg) {
        api_error(40002, '套餐不存在或已下架');
    }
    
    if (!empty($pkg['is_kvm']) && $image_id <= 0) {
        $img = Database::fetch("SELECT id FROM vm_images WHERE status = 'active' ORDER BY sort_order ASC LIMIT 1");
        if ($img) {
            $image_id = intval($img['id']);
        }
    }
    
    $cycle_price_map = [
        'monthly' => 1,
        'quarterly' => 3,
        'yearly' => 12,
    ];
    $months = $cycle_price_map[$billing_cycle] ?? 1;
    
    $unit_price = floatval($pkg['price'] ?? 0);
    $total_price = $unit_price * $months * $quantity;
    
    $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
    if (floatval($user['balance'] ?? 0) < $total_price) {
        api_error(40003, '余额不足，请先充值。需要 ' . $total_price . ' 元');
    }
    
    $hosts = [];
    
    Database::query("START TRANSACTION");
    try {
        Database::query("UPDATE users SET balance = balance - ? WHERE id = ?", [$total_price, $uid]);
        
        for ($i = 0; $i < $quantity; $i++) {
            $order_no = 'API' . date('YmdHis') . str_pad($i, 3, '0', STR_PAD_LEFT) . rand(100, 999);
            
            $host_name = $hostname ?: ('vm_' . $order_no);
            
            $order_id = Database::insert('orders', [
                'order_no' => $order_no,
                'user_id' => $uid,
                'package_id' => $package_id,
                'package_name' => $pkg['name'],
                'billing_cycle' => $billing_cycle,
                'amount' => $total_price / $quantity,
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'payment_method' => 'balance',
                'quantity' => 1,
            ]);
            
            $expire_at = date('Y-m-d H:i:s', strtotime("+{$months} months"));
            
            $traffic_limit = !empty($pkg['kvm_traffic_gb']) ? intval($pkg['kvm_traffic_gb']) * 1024 : 0;
            
            $host_id = Database::insert('hosts', [
                'user_id' => $uid,
                'order_id' => $order_id,
                'package_id' => $package_id,
                'package_name' => $pkg['name'],
                'vm_type' => !empty($pkg['is_kvm']) ? 'kvm' : 'mnbt',
                'vm_name' => $host_name,
                'uuid' => '',
                'ip_address' => '',
                'port' => 22,
                'vm_cpu' => intval($pkg['kvm_vcpu'] ?? 0),
                'vm_memory' => intval($pkg['kvm_memory_mb'] ?? 0),
                'vm_disk' => intval($pkg['kvm_disk_gb'] ?? 0),
                'kvm_vcpu' => intval($pkg['kvm_vcpu'] ?? 0),
                'kvm_memory_mb' => intval($pkg['kvm_memory_mb'] ?? 0),
                'kvm_disk_gb' => intval($pkg['kvm_disk_gb'] ?? 0),
                'kvm_bandwidth_mbps' => intval($pkg['kvm_bandwidth_mbps'] ?? 0),
                'traffic_limit' => $traffic_limit,
                'traffic_reset_date' => date('Y-m-01'),
                'status' => 'creating',
                'expire_at' => $expire_at,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            create_billing_record($uid, $host_id, $order_id, 'package', $unit_price * $months, $billing_cycle, '购买套餐: ' . $pkg['name']);
            
            if (!empty($pkg['is_kvm']) && $image_id > 0) {
                async_create_kvm($host_id, $image_id);
            }
            
            $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$host_id]);
            $hosts[] = format_vm_data($host);
        }
        
        Database::query("COMMIT");
        
        api_success([
            'hosts' => $hosts,
            'total_amount' => $total_price,
            'quantity' => $quantity,
            'message' => $quantity > 1 ? '批量创建成功，正在后台初始化' : '创建成功，正在初始化',
        ], $quantity > 1 ? '批量创建任务已提交' : '创建任务已提交');
    } catch (Exception $e) {
        Database::query("ROLLBACK");
        api_error(50001, '创建失败: ' . $e->getMessage(), 500);
    }
}

function async_create_kvm($host_id, $image_id) {
    $php = PHP_BINARY ?: 'php';
    $worker = ROOT_PATH . '/api/create_vm_worker.php';
    $cmd = sprintf('nohup %s %s %d %d > /dev/null 2>&1 &', 
        escapeshellarg($php),
        escapeshellarg($worker),
        $host_id,
        $image_id
    );
    @exec($cmd);
}

function vm_action($id, $uid, $action) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    if (empty($host['vm_type']) || $host['vm_type'] !== 'kvm') {
        api_error(40004, '仅KVM主机支持此操作');
    }
    
    if (!in_array($host['status'], ['running', 'suspended', 'suspended_traffic'])) {
        api_error(40005, '当前状态不支持此操作: ' . $host['status']);
    }
    
    require_once ROOT_PATH . '/config/helper.php';
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];
    
    $result = false;
    $msg = '';
    
    switch ($action) {
        case 'start':
            $result = $kvm->startVm($vm_name);
            $msg = $result ? '启动成功' : '启动失败';
            if ($result) {
                Database::update('hosts', ['status' => 'running'], 'id = ?', [$host['id']]);
            }
            break;
        case 'stop':
            $result = $kvm->shutdownVm($vm_name);
            $msg = $result ? '关机成功' : '关机失败';
            if ($result) {
                Database::update('hosts', ['status' => 'suspended'], 'id = ?', [$host['id']]);
            }
            break;
        case 'restart':
            $result = $kvm->rebootVm($vm_name);
            $msg = $result ? '重启成功' : '重启失败';
            break;
    }
    
    add_host_operation_log($host['id'], $uid, $action, $msg, get_real_ip());
    
    if ($result) {
        api_success(['action' => $action, 'status' => $host['status']], $msg);
    } else {
        api_error(50002, $msg, 500);
    }
}

function vm_reinstall_via_api($id, $uid) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    $image_id = intval(api_param('image_id', 0, true));
    $root_password = api_param('root_password', '');
    
    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ? AND status = 'active'", [$image_id]);
    if (!$image) {
        api_error(40006, '镜像不存在');
    }
    
    if (empty($host['vm_type']) || $host['vm_type'] !== 'kvm') {
        api_error(40004, '仅KVM主机支持重装');
    }
    
    Database::update('hosts', ['status' => 'reinstalling', 'os_name' => $image['name']], 'id = ?', [$host['id']]);
    
    $php = PHP_BINARY ?: 'php';
    $worker = ROOT_PATH . '/user/reinstall_worker.php';
    $cmd = sprintf('nohup %s %s %d %d %s > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($worker),
        $host['id'],
        $image_id,
        escapeshellarg($root_password)
    );
    @exec($cmd);
    
    add_host_operation_log($host['id'], $uid, 'reinstall', '系统重装: ' . $image['name'], get_real_ip());
    
    api_success(['status' => 'reinstalling', 'image' => $image['name']], '重装任务已提交，预计3-10分钟完成');
}

function vm_reset_password_via_api($id, $uid) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    $new_password = api_param('password', '', true);
    if (strlen($new_password) < 6) {
        api_error(40007, '密码长度不能少于6位');
    }
    
    if (empty($host['vm_type']) || $host['vm_type'] !== 'kvm') {
        api_error(40004, '仅KVM主机支持修改密码');
    }
    
    require_once ROOT_PATH . '/config/helper.php';
    $kvm = kvm_get_manager_for_host($host);
    $result = $kvm->setUserPassword($host['vm_name'], 'root', $new_password);
    
    if ($result) {
        Database::update('hosts', ['root_password' => $new_password], 'id = ?', [$host['id']]);
        add_host_operation_log($host['id'], $uid, 'reset_password', '修改root密码', get_real_ip());
        api_success(null, '密码修改成功');
    } else {
        api_error(50003, '密码修改失败，请确保虚拟机已安装qemu-guest-agent', 500);
    }
}

function vm_delete_via_api($id, $uid) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    if ($host['status'] === 'deleted' || $host['status'] === 'cancelled') {
        api_error(40008, '主机已删除');
    }
    
    if (empty($host['vm_type']) || $host['vm_type'] !== 'kvm') {
        api_error(40004, '仅KVM主机支持此操作');
    }
    
    require_once ROOT_PATH . '/config/helper.php';
    $kvm = kvm_get_manager_for_host($host);
    
    try {
        @$kvm->destroyVm($host['vm_name']);
        @$kvm->undefineVm($host['vm_name']);
        
        $disk_path = $host['disk_path'] ?? '';
        if ($disk_path && file_exists($disk_path)) {
            @unlink($disk_path);
        }
    } catch (Exception $e) {}
    
    Database::update('hosts', ['status' => 'cancelled'], 'id = ?', [$host['id']]);
    
    add_host_operation_log($host['id'], $uid, 'delete', '删除虚拟机', get_real_ip());
    
    api_success(null, '删除成功');
}

function vm_status_detail($id, $uid) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    $data = format_vm_data($host);
    
    if (!empty($host['vm_type']) && $host['vm_type'] === 'kvm') {
        require_once ROOT_PATH . '/config/helper.php';
        $kvm = kvm_get_manager_for_host($host);
        $vm_name = $host['vm_name'];
        
        try {
            $dom = $kvm->getVmInfo($vm_name);
            if ($dom && isset($dom['state'])) {
                $data['libvirt_state'] = $dom['state'];
                $data['libvirt_state_desc'] = $dom['state_desc'] ?? '';
                $data['cpu_usage'] = $dom['cpu_usage'] ?? 0;
                $data['memory_used_kb'] = $dom['memory_used'] ?? 0;
            }
            
            $net = $kvm->getNetworkStats($vm_name);
            if ($net) {
                $data['network'] = $net;
            }
        } catch (Exception $e) {
            $data['libvirt_error'] = $e->getMessage();
        }
    }
    
    api_success($data);
}

function vm_traffic_info($id, $uid) {
    $host = get_vm_by_identifier($id, $uid);
    if (!$host) {
        api_error(40402, '主机不存在', 404);
    }
    
    $days = min(30, max(1, intval(api_param('days', 7))));
    $start_time = strtotime("-$days days");
    
    $rows = Database::fetchAll("SELECT total_bytes, collected_at FROM host_traffic 
        WHERE host_id = ? AND collected_at >= FROM_UNIXTIME(?) ORDER BY collected_at ASC",
        [$host['id'], $start_time]);
    
    $daily = [];
    $prev_day = null;
    $prev_total = 0;
    $day_start_total = 0;
    
    foreach ($rows as $row) {
        $day = substr($row['collected_at'], 0, 10);
        $total = intval($row['total_bytes']);
        
        if ($prev_day !== $day) {
            if ($prev_day !== null && $prev_total > 0) {
                $daily[$prev_day] = max(0, $prev_total - $day_start_total);
            }
            $day_start_total = $total;
            $prev_day = $day;
        }
        $prev_total = $total;
    }
    
    if ($prev_day !== null) {
        $daily[$prev_day] = max(0, $prev_total - $day_start_total);
    }
    
    api_success([
        'traffic_used_mb' => intval($host['traffic_used'] ?? 0),
        'traffic_limit_mb' => intval($host['traffic_limit'] ?? 0),
        'traffic_reset_date' => $host['traffic_reset_date'] ?? '',
        'daily_traffic_bytes' => $daily,
        'days' => $days,
    ]);
}

function batch_create_vm($uid) {
    $package_id = intval(api_param('package_id', 0, true));
    $quantity = min(50, max(1, intval(api_param('quantity', 1, true))));
    $billing_cycle = api_param('billing_cycle', 'monthly');
    $image_id = intval(api_param('image_id', 0));
    $naming_prefix = api_param('naming_prefix', 'batch_');
    
    $pkg = Database::fetch("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$package_id]);
    if (!$pkg) {
        api_error(40002, '套餐不存在或已下架');
    }
    
    $cycle_price_map = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
    $months = $cycle_price_map[$billing_cycle] ?? 1;
    $unit_price = floatval($pkg['price'] ?? 0);
    $total_price = $unit_price * $months * $quantity;
    
    $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
    if (floatval($user['balance'] ?? 0) < $total_price) {
        api_error(40003, '余额不足，需要 ' . $total_price . ' 元');
    }
    
    $host_ids = [];
    Database::query("START TRANSACTION");
    try {
        Database::query("UPDATE users SET balance = balance - ? WHERE id = ?", [$total_price, $uid]);
        
        for ($i = 1; $i <= $quantity; $i++) {
            $order_no = 'BATCH' . date('YmdHis') . str_pad($i, 4, '0', STR_PAD_LEFT) . rand(100, 999);
            $host_name = $naming_prefix . $order_no;
            $expire_at = date('Y-m-d H:i:s', strtotime("+{$months} months"));
            $traffic_limit = !empty($pkg['kvm_traffic_gb']) ? intval($pkg['kvm_traffic_gb']) * 1024 : 0;
            
            $order_id = Database::insert('orders', [
                'order_no' => $order_no, 'user_id' => $uid, 'package_id' => $package_id,
                'package_name' => $pkg['name'], 'billing_cycle' => $billing_cycle,
                'amount' => $unit_price * $months, 'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'), 'payment_method' => 'balance', 'quantity' => 1,
            ]);
            
            $host_id = Database::insert('hosts', [
                'user_id' => $uid, 'order_id' => $order_id, 'package_id' => $package_id,
                'package_name' => $pkg['name'], 'vm_type' => !empty($pkg['is_kvm']) ? 'kvm' : 'mnbt',
                'vm_name' => $host_name, 'uuid' => '', 'ip_address' => '',
                'kvm_vcpu' => intval($pkg['kvm_vcpu'] ?? 0),
                'kvm_memory_mb' => intval($pkg['kvm_memory_mb'] ?? 0),
                'kvm_disk_gb' => intval($pkg['kvm_disk_gb'] ?? 0),
                'kvm_bandwidth_mbps' => intval($pkg['kvm_bandwidth_mbps'] ?? 0),
                'traffic_limit' => $traffic_limit, 'traffic_reset_date' => date('Y-m-01'),
                'status' => 'creating', 'expire_at' => $expire_at,
            ]);
            
            $host_ids[] = $host_id;
            create_billing_record($uid, $host_id, $order_id, 'package', $unit_price * $months, $billing_cycle, '批量购买: ' . $pkg['name']);
            
            if (!empty($pkg['is_kvm']) && $image_id > 0) {
                async_create_kvm($host_id, $image_id);
            }
        }
        
        Database::query("COMMIT");
        api_success(['host_ids' => $host_ids, 'total_amount' => $total_price, 'quantity' => $quantity], '批量创建任务已提交');
    } catch (Exception $e) {
        Database::query("ROLLBACK");
        api_error(50001, '批量创建失败: ' . $e->getMessage(), 500);
    }
}

function batch_vm_action($uid, $action) {
    $ids = api_param('ids', '', true);
    $id_array = is_array($ids) ? $ids : explode(',', $ids);
    $id_array = array_filter(array_map('intval', $id_array));
    
    if (empty($id_array)) {
        api_error(40009, '请指定要操作的主机ID');
    }
    
    if (count($id_array) > 100) {
        api_error(40010, '单次操作不能超过100台');
    }
    
    $placeholders = implode(',', array_fill(0, count($id_array), '?'));
    $hosts = Database::fetchAll("SELECT * FROM hosts WHERE id IN ($placeholders) AND user_id = ?", array_merge($id_array, [$uid]));
    
    $success = 0;
    $failed = 0;
    $results = [];
    
    require_once ROOT_PATH . '/config/helper.php';
    $kvm = kvm_get_manager_for_host($host);
    
    foreach ($hosts as $host) {
        if (empty($host['vm_type']) || $host['vm_type'] !== 'kvm') {
            $results[] = ['id' => $host['id'], 'success' => false, 'message' => '非KVM主机'];
            $failed++;
            continue;
        }
        
        try {
            $vm_name = $host['vm_name'];
            $ok = false;
            switch ($action) {
                case 'start':
                    $ok = $kvm->startVm($vm_name);
                    if ($ok) Database::update('hosts', ['status' => 'running'], 'id = ?', [$host['id']]);
                    break;
                case 'stop':
                    $ok = $kvm->shutdownVm($vm_name);
                    if ($ok) Database::update('hosts', ['status' => 'suspended'], 'id = ?', [$host['id']]);
                    break;
            }
            
            if ($ok) {
                $success++;
                $results[] = ['id' => $host['id'], 'success' => true];
            } else {
                $failed++;
                $results[] = ['id' => $host['id'], 'success' => false, 'message' => '操作失败'];
            }
        } catch (Exception $e) {
            $failed++;
            $results[] = ['id' => $host['id'], 'success' => false, 'message' => $e->getMessage()];
        }
    }
    
    api_success([
        'action' => $action,
        'total' => count($hosts),
        'success' => $success,
        'failed' => $failed,
        'results' => $results,
    ], '批量操作完成');
}

function create_billing_record($uid, $host_id, $order_id, $type, $amount, $period, $description) {
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

function add_host_operation_log($host_id, $user_id, $action, $content, $ip) {
    @Database::insert('host_operation_logs', [
        'host_id' => $host_id,
        'user_id' => $user_id,
        'type' => 'info',
        'action' => $action,
        'content' => substr($content, 0, 500),
        'ip' => $ip,
    ]);
}
