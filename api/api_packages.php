<?php
function handle_package_api($method, $resource_id, $action) {
    global $api_auth;
    $uid = $api_auth['user_id'];
    
    if ($method === 'GET' && !$resource_id) {
        $type = api_param('type', '');
        $is_kvm = api_param('is_kvm', '');
        
        $cache_key = 'api_packages_' . md5($type . '|' . $is_kvm);
        $packages = null;
        if (class_exists('DataCache')) {
            $cached = DataCache::getFile($cache_key, '__NOCACHE__');
            if ($cached !== '__NOCACHE__') {
                $packages = $cached;
            }
        }
        
        if ($packages === null) {
            $where = "WHERE status = 'active'";
            $params = [];
            
            if ($type) {
                $where .= " AND type = ?";
                $params[] = $type;
            }
            if ($is_kvm !== '') {
                $where .= " AND is_kvm = ?";
                $params[] = intval($is_kvm);
            }
            
            $packages = Database::fetchAll("SELECT * FROM packages $where ORDER BY sort_order ASC, id ASC", $params);
            
            if (class_exists('DataCache')) {
                DataCache::setFile($cache_key, $packages, 300);
            }
        }
        
        $list = [];
        foreach ($packages as $p) {
            $list[] = format_package_data($p);
        }
        
        api_success(['list' => $list, 'total' => count($list)]);
    }
    
    if ($method === 'GET' && $resource_id) {
        $cache_key = 'api_package_' . intval($resource_id);
        $pkg = null;
        if (class_exists('DataCache')) {
            $cached = DataCache::getFile($cache_key, '__NOCACHE__');
            if ($cached !== '__NOCACHE__') {
                $pkg = $cached;
            }
        }
        
        if ($pkg === null) {
            $pkg = Database::fetch("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$resource_id]);
            if ($pkg && class_exists('DataCache')) {
                DataCache::setFile($cache_key, $pkg, 300);
            }
        }
        
        if (!$pkg) {
            api_error(40405, '套餐不存在', 404);
        }
        api_success(format_package_data($pkg));
    }
    
    api_error(40405, '接口不存在', 404);
}

function format_package_data($pkg) {
    $data = [
        'id' => intval($pkg['id']),
        'name' => $pkg['name'] ?? '',
        'type' => intval($pkg['type'] ?? 0),
        'type_name' => package_type_name($pkg['type'] ?? 0),
        'is_kvm' => boolval($pkg['is_kvm'] ?? 0),
        'is_nat_kvm' => boolval($pkg['is_nat_kvm'] ?? 0),
        'price' => floatval($pkg['price'] ?? 0),
        'description' => $pkg['description'] ?? '',
        'sort_order' => intval($pkg['sort_order'] ?? 0),
        'status' => $pkg['status'] ?? '',
    ];
    
    if (!empty($pkg['is_kvm'])) {
        $data['specs'] = [
            'vcpu' => intval($pkg['kvm_vcpu'] ?? 0),
            'memory_mb' => intval($pkg['kvm_memory_mb'] ?? 0),
            'disk_gb' => intval($pkg['kvm_disk_gb'] ?? 0),
            'bandwidth_mbps' => intval($pkg['kvm_bandwidth_mbps'] ?? 0),
            'traffic_gb' => intval($pkg['kvm_traffic_gb'] ?? 0),
        ];
    } else {
        $data['specs'] = [
            'web_quota' => intval($pkg['web_quota'] ?? 0),
            'db_quota' => intval($pkg['db_quota'] ?? 0),
            'bandwidth' => intval($pkg['bandwidth'] ?? 0),
            'flow' => intval($pkg['flow'] ?? 0),
            'db_count' => intval($pkg['db_count'] ?? 0),
            'ftp_count' => intval($pkg['ftp_count'] ?? 0),
        ];
    }
    
    return $data;
}

function package_type_name($type) {
    $map = [1 => 'CDN加速', 2 => '虚拟主机', 3 => 'KVM云服务器'];
    return $map[intval($type)] ?? '未知';
}
