<?php
/**
 * 演示模式管理模块
 */

class DemoMode {
    private static $enabled = null;
    private static $password = null;
    
    /**
     * 检查演示模式是否启用
     */
    public static function isEnabled() {
        if (self::$enabled === null) {
            self::$enabled = !empty(db_get_setting('demo_enabled'));
        }
        return self::$enabled;
    }
    
    /**
     * 获取演示模式密码
     */
    public static function getPassword() {
        if (self::$password === null) {
            self::$password = db_get_setting('demo_password') ?: 'demo123';
        }
        return self::$password;
    }
    
    /**
     * 验证演示模式密码
     */
    public static function verifyPassword($password) {
        return $password === self::getPassword();
    }
    
    /**
     * 启用演示模式
     */
    public static function enable($password) {
        if (!self::verifyPassword($password)) {
            return ['success' => false, 'message' => '密码错误'];
        }
        db_set_setting('demo_enabled', 1);
        self::$enabled = true;
        return ['success' => true, 'message' => '演示模式已启用'];
    }
    
    /**
     * 禁用演示模式
     */
    public static function disable($password) {
        if (!self::verifyPassword($password)) {
            return ['success' => false, 'message' => '密码错误'];
        }
        db_set_setting('demo_enabled', 0);
        self::$enabled = false;
        return ['success' => true, 'message' => '演示模式已禁用'];
    }
    
    /**
     * 设置演示模式密码
     */
    public static function setPassword($old_password, $new_password) {
        if (!self::verifyPassword($old_password)) {
            return ['success' => false, 'message' => '原密码错误'];
        }
        if (strlen($new_password) < 6) {
            return ['success' => false, 'message' => '密码长度至少6位'];
        }
        db_set_setting('demo_password', $new_password);
        self::$password = $new_password;
        return ['success' => true, 'message' => '密码已修改'];
    }
    
    /**
     * 获取模拟数据
     */
    public static function getMockData($type) {
        $mock = [];
        
        switch ($type) {
            case 'hosts':
                $mock = [
                    ['id' => 1, 'vm_name' => 'web-server-01', 'status' => 'running', 'ip_address' => '192.168.1.101', 'vcpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 100],
                    ['id' => 2, 'vm_name' => 'db-server-01', 'status' => 'running', 'ip_address' => '192.168.1.102', 'vcpu' => 8, 'memory_mb' => 16384, 'disk_gb' => 500],
                    ['id' => 3, 'vm_name' => 'cache-server-01', 'status' => 'suspended', 'ip_address' => '192.168.1.103', 'vcpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 50],
                    ['id' => 4, 'vm_name' => 'api-server-01', 'status' => 'running', 'ip_address' => '192.168.1.104', 'vcpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 200],
                    ['id' => 5, 'vm_name' => 'cdn-server-01', 'status' => 'creating', 'ip_address' => '-', 'vcpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 100],
                ];
                break;
                
            case 'stats':
                $mock = [
                    'total_vms' => 156,
                    'running_vms' => 142,
                    'suspended_vms' => 8,
                    'creating_vms' => 6,
                    'total_cpu' => 896,
                    'used_cpu' => 456,
                    'total_memory' => 3584,
                    'used_memory' => 2156,
                    'total_disk' => 120000,
                    'used_disk' => 68000,
                    'total_users' => 89,
                    'active_users' => 72,
                ];
                break;
                
            case 'storage':
                $mock = [
                    ['id' => 1, 'pool_name' => 'local-pool-1', 'pool_type' => 'local', 'total_size_gb' => 2000, 'used_size_gb' => 856, 'status' => 'active'],
                    ['id' => 2, 'pool_name' => 'nfs-shared', 'pool_type' => 'nfs', 'total_size_gb' => 5000, 'used_size_gb' => 3200, 'status' => 'active'],
                    ['id' => 3, 'pool_name' => 'ceph-cluster', 'pool_type' => 'ceph', 'total_size_gb' => 10000, 'used_size_gb' => 6500, 'status' => 'active'],
                ];
                break;
                
            case 'nodes':
                $mock = [
                    ['id' => 1, 'node_name' => 'kvm-node-01', 'node_ip' => '192.168.1.201', 'status' => 'online', 'cpu_usage' => 45, 'memory_usage' => 62, 'current_vms' => 32, 'max_vms' => 50],
                    ['id' => 2, 'node_name' => 'kvm-node-02', 'node_ip' => '192.168.1.202', 'status' => 'online', 'cpu_usage' => 58, 'memory_usage' => 71, 'current_vms' => 45, 'max_vms' => 50],
                    ['id' => 3, 'node_name' => 'kvm-node-03', 'node_ip' => '192.168.1.203', 'status' => 'maintain', 'cpu_usage' => 0, 'memory_usage' => 0, 'current_vms' => 0, 'max_vms' => 50],
                ];
                break;
                
            case 'users':
                $mock = [
                    ['id' => 1, 'username' => 'admin', 'email' => 'admin@demo.com', 'balance' => 0.00, 'status' => 'active', 'created_at' => '2024-01-01 00:00:00'],
                    ['id' => 2, 'username' => 'user001', 'email' => 'user001@demo.com', 'balance' => 500.00, 'status' => 'active', 'created_at' => '2024-03-15 10:30:00'],
                    ['id' => 3, 'username' => 'user002', 'email' => 'user002@demo.com', 'balance' => 1200.00, 'status' => 'active', 'created_at' => '2024-05-20 14:20:00'],
                    ['id' => 4, 'username' => 'user003', 'email' => 'user003@demo.com', 'balance' => 0.00, 'status' => 'disabled', 'created_at' => '2024-02-10 09:00:00'],
                ];
                break;
                
            case 'bills':
                $mock = [
                    ['id' => 1, 'order_no' => 'BILL20240701001', 'user_id' => 2, 'amount' => 199.00, 'status' => 'paid', 'created_at' => '2024-07-01 00:00:00'],
                    ['id' => 2, 'order_no' => 'BILL20240701002', 'user_id' => 3, 'amount' => 399.00, 'status' => 'paid', 'created_at' => '2024-07-01 00:00:00'],
                    ['id' => 3, 'order_no' => 'BILL20240702001', 'user_id' => 2, 'amount' => 99.00, 'status' => 'pending', 'created_at' => '2024-07-02 00:00:00'],
                ];
                break;
        }
        
        return $mock;
    }
    
    /**
     * 获取演示模式提示信息
     */
    public static function getDemoNotice() {
        return '<div style="padding: 12px; background: linear-gradient(90deg, #1a1a2e, #16213e); color: #fff; text-align: center; font-size: 14px; font-weight: 500; position: fixed; top: 0; left: 0; right: 0; z-index: 9999;">
            <span style="margin-right: 8px;">🎭</span>
            当前处于演示模式，所有操作仅为模拟展示，实际数据不会被修改
            <button onclick="document.getElementById(\'demoOverlay\').style.display=\'block\'" style="margin-left: 16px; padding: 4px 12px; background: #e94560; border: none; border-radius: 4px; color: #fff; font-size: 12px; cursor: pointer;">退出演示</button>
        </div>';
    }
}

/**
 * 检查并拦截演示模式操作
 */
function check_demo_mode() {
    if (!DemoMode::isEnabled()) {
        return;
    }
    
    if (is_post()) {
        $action = post('action', '');
        $allowed_actions = ['demo_login', 'demo_logout', 'demo_switch'];
        
        if (!in_array($action, $allowed_actions)) {
            // 返回演示模式提示，阻止实际操作
            echo json_encode([
                'success' => false,
                'message' => '演示模式下无法执行此操作',
                'demo_mode' => true,
            ]);
            exit;
        }
    }
}

/**
 * 获取模拟数据的辅助函数
 */
function get_demo_data($type) {
    return DemoMode::getMockData($type);
}