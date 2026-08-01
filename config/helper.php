<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/LicenseManager.php';
require_once __DIR__ . '/CacheManager.php';

// 根据运行模式设置错误报告
$debug_mode = intval(db_get_setting('app_debug_mode') ?? 0);
if ($debug_mode || defined('FORCE_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

spl_autoload_register(function ($class) {
    $class_map = [
        'LicenseManager'  => __DIR__ . '/LicenseManager.php',
        'KvmManager'      => __DIR__ . '/KvmManager.php',
        'Mailer'          => __DIR__ . '/Mailer.php',
        'ImageCaptcha'    => __DIR__ . '/ImageCaptcha.php',
        'SliderCaptcha'   => __DIR__ . '/SliderCaptcha.php',
        'TianaiCaptcha'   => __DIR__ . '/TianaiCaptcha.php',
    ];
    if (isset($class_map[$class])) {
        require_once $class_map[$class];
    }
});

require_once __DIR__ . '/mnbt.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lang.php';

// ==== 会话必须在所有 session 之前启动
if (!defined('SKIP_SESSION_START') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==== 全局安全响应头
if (!headers_sent()) {
    sec_set_security_headers();
}

// ==== 全局WAF检查（生产环境建议开启，可通过 SKIP_WAF 常量跳过）
if (!defined('SKIP_WAF') && PHP_SAPI !== 'cli') {
    sec_waf_check();
}

// ==== 全局IP限流（默认120次/分钟，可通过 RATE_LIMIT 常量调整）
if (!defined('SKIP_RATE_LIMIT') && PHP_SAPI !== 'cli') {
    $limit = defined('GLOBAL_RATE_LIMIT') ? intval(GLOBAL_RATE_LIMIT) : 120;
    if ($limit > 0) {
        sec_rate_limit_ip($limit, 60, 300);
    }
}

// ==== 演示模式检查
if (!defined('SKIP_DEMO_CHECK') && PHP_SAPI !== 'cli') {
    if (file_exists(__DIR__ . '/DemoMode.php')) {
        require_once __DIR__ . '/DemoMode.php';
        if (DemoMode::isEnabled()) {
            $current_path = $_SERVER['REQUEST_URI'] ?? '';
            $is_allowed_path = strpos($current_path, '/demo/') !== false || 
                               strpos($current_path, '/pay/') !== false || 
                               strpos($current_path, '/admin/settings.php') !== false;
            if (!$is_allowed_path) {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => '演示模式下无法执行此操作',
                        'demo_mode' => true,
                    ]);
                    exit;
                }
            }
        }
    }
}

if (function_exists('db_load_site_settings')) {
    @db_load_site_settings();
}

// ==== 社区版：仅检查安装状态，无授权校验 ====
function license_global_check() {
    static $checked = false;
    if ($checked) return true;
    if (php_sapi_name() === 'cli') {
        $checked = true;
        return true;
    }
    $current_page = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($current_page, '/install.php') !== false || strpos($current_page, '/captcha.php') !== false) {
        $checked = true;
        return true;
    }
    if (!file_exists(__DIR__ . '/.installed')) {
        header('Location: /install.php');
        exit;
    }
    $checked = true;
    return true;
}

// 社区版无授权到期概念
function license_expire_info() { return null; }
function license_should_show_warning() { return false; }
function license_expire_warning_banner() { return ''; }
function license_check_and_notify_expire() { /* 无操作 */ }

license_global_check();

// ==== 社区版：功能包装直接执行，无授权检查 ====
function license_wrap($func) {
    return function() use ($func) {
        return call_user_func_array($func, func_get_args());
    };
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function js_escape($str) {
    return addslashes($str ?? '');
}

function flash($key = null, $value = null) {
    auth_start();
    if ($key !== null && $value !== null) {
        $_SESSION['flash'][$key] = $value;
        session_write_close();
        return;
    }
    if ($key !== null) {
        if (isset($_SESSION['flash'][$key])) {
            $value = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $value;
        }
        return null;
    }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function render_flash() {
    $html = '';
    if ($msg = flash('success')) {
        $html .= '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
    }
    if ($msg = flash('error')) {
        $html .= '<div class="alert alert-error">' . htmlspecialchars($msg) . '</div>';
    }
    return $html;
}

/**
 * 获取代理对某套餐的拿货价
 * 优先查 agent_pricing 表，没有则按代理折扣率计算
 * @param int $package_id 套餐ID
 * @param array $agent 代理信息（含 level_id, discount_rate）
 * @return array ['agent_price' => 拿货价, 'min_sell_price' => 最低售价, 'source' => 来源]
 */
function get_agent_package_price($package_id, $agent) {
    $package = Database::fetch("SELECT * FROM packages WHERE id = ?", [$package_id]);
    if (!$package) {
        return ['agent_price' => 0, 'min_sell_price' => 0, 'source' => 'none', 'original_price' => 0];
    }
    $original_price = floatval($package['price_monthly']);

    // 优先查代理定价表
    if (!empty($agent['level_id'])) {
        $pricing = Database::fetch(
            "SELECT * FROM agent_pricing WHERE package_id = ? AND level_id = ? AND status = 'active'",
            [$package_id, $agent['level_id']]
        );
        if ($pricing && floatval($pricing['agent_price']) > 0) {
            return [
                'agent_price' => floatval($pricing['agent_price']),
                'min_sell_price' => floatval($pricing['min_sell_price']),
                'source' => 'pricing_table',
                'original_price' => $original_price,
            ];
        }
    }

    // 回退：按代理个人折扣率计算
    $discount_rate = floatval($agent['discount_rate'] ?? 90);
    $agent_price = round($original_price * $discount_rate / 100, 2);
    return [
        'agent_price' => $agent_price,
        'min_sell_price' => 0,
        'source' => 'discount_rate',
        'original_price' => $original_price,
    ];
}

function old($key, $default = '') {
    auth_start();
    if (isset($_SESSION['old'][$key])) {
        $val = $_SESSION['old'][$key];
        return $val;
    }
    return $default;
}

function flash_set_old($data) {
    auth_start();
    $_SESSION['old'] = $data;
}

function flash_clear_old() {
    auth_start();
    unset($_SESSION['old']);
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function back() {
    $url = $_SERVER['HTTP_REFERER'] ?? '/';
    redirect($url);
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success($msg = '', $data = []) {
    json_response(['code' => 200, 'msg' => $msg, 'data' => $data]);
}

function json_error($msg = '', $code = 400, $data = []) {
    json_response(['code' => $code, 'msg' => $msg, 'data' => $data]);
}

function generate_order_no() {
    return 'V' . date('YmdHis') . rand(1000, 9999);
}

function format_date($datetime) {
    if (!$datetime || $datetime == '0000-00-00 00:00:00') return '-';
    return date('Y-m-d H:i:s', strtotime($datetime));
}

function format_money($num) {
    return '¥' . number_format($num, 2, '.', '');
}

function get_status_label($status, $type = '') {
    $map = [
        'user' => [
            'active' => ['text' => '正常', 'class' => 'success'],
            'suspended' => ['text' => '已停用', 'class' => 'warning'],
            'deleted' => ['text' => '已删除', 'class' => 'danger'],
        ],
        'order' => [
            'pending' => ['text' => '待支付', 'class' => 'warning'],
            'paid' => ['text' => '已支付', 'class' => 'primary'],
            'processing' => ['text' => '处理中', 'class' => 'info'],
            'completed' => ['text' => '已完成', 'class' => 'success'],
            'cancelled' => ['text' => '已取消', 'class' => 'secondary'],
            'refunded' => ['text' => '已退款', 'class' => 'danger'],
        ],
        'host' => [
            'creating' => ['text' => '创建中', 'class' => 'warning'],
            'running' => ['text' => '运行中', 'class' => 'success'],
            'suspended' => ['text' => '已暂停', 'class' => 'danger'],
            'cancelled' => ['text' => '已取消', 'class' => 'secondary'],
        ],
        'refund' => [
            'pending' => ['text' => '待处理', 'class' => 'warning'],
            'approved' => ['text' => '已批准', 'class' => 'primary'],
            'rejected' => ['text' => '已拒绝', 'class' => 'secondary'],
            'completed' => ['text' => '已完成', 'class' => 'success'],
        ],
        'transfer' => [
            'pending' => ['text' => '待处理', 'class' => 'warning'],
            'approved' => ['text' => '已批准', 'class' => 'primary'],
            'rejected' => ['text' => '已拒绝', 'class' => 'secondary'],
            'completed' => ['text' => '已完成', 'class' => 'success'],
        ],
    ];
    if (isset($map[$type][$status])) {
        $item = $map[$type][$status];
        return "<span class=\"badge badge-{$item['class']}\">{$item['text']}</span>";
    }
    return $status;
}

function is_post() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

function post($key = null, $default = null) {
    if ($key === null) return $_POST;
    return $_POST[$key] ?? $default;
}

function get($key = null, $default = null) {
    if ($key === null) return $_GET;
    return $_GET[$key] ?? $default;
}

function dd($var) {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    die;
}

// ===================== 新系统迁移与辅助函数 =====================

// 初始化新系统所需的数据表（确保表存在）
function migrate_new_tables() {
    $pdo = db();
    $existing_tables = [];
    try {
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $existing_tables[] = $row[0];
        }
    } catch (Exception $e) {}

    $dbname = config('db.name');

    // 积分表
    if (!in_array('user_points', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE user_points (
                id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL,
                points INT DEFAULT 0, total_earned INT DEFAULT 0, total_spent INT DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 积分记录表
    if (!in_array('point_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE point_logs (
                id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL,
                change_type ENUM('earn_order','earn_register','earn_referral','earn_daily','spend_exchange','admin_add','admin_deduct') NOT NULL,
                points INT NOT NULL, balance_after INT NOT NULL, description VARCHAR(255) DEFAULT '',
                related_id INT DEFAULT NULL, operator_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id), INDEX idx_change_type (change_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 积分规则表
    if (!in_array('point_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE point_rules (
                id INT PRIMARY KEY AUTO_INCREMENT, rule_key VARCHAR(50) NOT NULL UNIQUE,
                rule_name VARCHAR(100) NOT NULL, points INT NOT NULL, enabled TINYINT(1) DEFAULT 1,
                description VARCHAR(255) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $default_rules = [
                ['register', '注册赠送', 50, '新用户注册时赠送'],
                ['order_pay', '消费返积分', 10, '每消费1元返1积分'],
                ['daily_login', '每日登录', 5, '每日首次登录赠送'],
                ['referral_signup', '推广注册', 20, '推广用户注册成功'],
            ];
            foreach ($default_rules as $r) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO point_rules (rule_key, rule_name, points, description) VALUES (?,?,?,?)")->execute($r);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }
    // 工单表
    if (!in_array('tickets', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE tickets (
                id INT PRIMARY KEY AUTO_INCREMENT, ticket_no VARCHAR(32) NOT NULL UNIQUE,
                user_id INT NOT NULL, title VARCHAR(200) NOT NULL,
                category ENUM('tech','finance','account','complaint','other') DEFAULT 'other',
                priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
                status ENUM('open','replied','closed') DEFAULT 'open',
                last_reply_at TIMESTAMP NULL, last_reply_by VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id), INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 工单回复表
    if (!in_array('ticket_replies', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ticket_replies (
                id INT PRIMARY KEY AUTO_INCREMENT, ticket_id INT NOT NULL,
                user_id INT DEFAULT NULL, admin_id INT DEFAULT NULL, content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                INDEX idx_ticket_id (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 工单表字段扩展（阶段三：工单流程自定义引擎）
    if (in_array('tickets', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map('strtolower', $cols);
            if (!in_array('assigned_staff_id', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN assigned_staff_id INT DEFAULT NULL COMMENT '分配的客服ID' AFTER status");
            }
            if (!in_array('assigned_admin_id', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN assigned_admin_id INT DEFAULT NULL COMMENT '分配的管理员ID' AFTER assigned_staff_id");
            }
            if (!in_array('source', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN source VARCHAR(50) DEFAULT 'web' COMMENT '来源渠道' AFTER assigned_admin_id");
            }
            if (!in_array('session_id', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN session_id BIGINT DEFAULT NULL COMMENT '关联会话ID' AFTER source");
            }
            if (!in_array('resolved_at', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN resolved_at TIMESTAMP NULL COMMENT '解决时间' AFTER last_reply_by");
            }
            if (!in_array('closed_at', $cols)) {
                $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_at TIMESTAMP NULL COMMENT '关闭时间' AFTER resolved_at");
            }
        } catch (Exception $e) {}
    }
    // 工单流转日志表
    if (!in_array('ticket_flow_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_flow_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ticket_id INT NOT NULL COMMENT '工单ID',
                from_status VARCHAR(50) DEFAULT NULL COMMENT '原状态',
                to_status VARCHAR(50) NOT NULL COMMENT '新状态',
                operator_id INT DEFAULT NULL COMMENT '操作人ID',
                operator_type ENUM('admin','staff','user','system') DEFAULT 'system' COMMENT '操作人类型',
                operator_name VARCHAR(50) DEFAULT NULL COMMENT '操作人名称',
                remark TEXT COMMENT '备注',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket_id (ticket_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工单状态流转日志'");
        } catch (Exception $e) {}
    }
    // 推广关系表
    if (!in_array('referrals', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE referrals (
                id INT PRIMARY KEY AUTO_INCREMENT, referrer_id INT NOT NULL, referred_id INT NOT NULL,
                referral_code VARCHAR(32) NOT NULL, rebate_amount DECIMAL(10,2) DEFAULT 0.00, rebate_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_referrer (referrer_id), INDEX idx_referred (referred_id), INDEX idx_code (referral_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 返现记录表
    if (!in_array('rebate_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE rebate_logs (
                id INT PRIMARY KEY AUTO_INCREMENT, referrer_id INT NOT NULL, referred_id INT NOT NULL,
                order_id INT NOT NULL, rebate_amount DECIMAL(10,2) NOT NULL, order_amount DECIMAL(10,2) NOT NULL,
                status ENUM('pending','settled','cancelled') DEFAULT 'settled',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                INDEX idx_referrer (referrer_id), INDEX idx_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // 返现规则表
    if (!in_array('rebate_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE rebate_rules (
                id INT PRIMARY KEY AUTO_INCREMENT, rule_key VARCHAR(50) NOT NULL UNIQUE,
                rule_name VARCHAR(100) NOT NULL, rebate_type ENUM('percent','fixed') DEFAULT 'percent',
                rebate_value DECIMAL(10,2) NOT NULL, min_order_amount DECIMAL(10,2) DEFAULT 0.00,
                enabled TINYINT(1) DEFAULT 1, description VARCHAR(255) DEFAULT '',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try {
                $pdo->prepare("INSERT IGNORE INTO rebate_rules (rule_key, rule_name, rebate_type, rebate_value, min_order_amount, enabled, description) VALUES (?,?,?,?,?,?,?)")
                    ->execute(['first_order', '首单返现', 'percent', 5.00, 0.00, 1, '被推广用户首单，返订单金额的5%给推广人']);
                $pdo->prepare("INSERT IGNORE INTO rebate_rules (rule_key, rule_name, rebate_type, rebate_value, min_order_amount, enabled, description) VALUES (?,?,?,?,?,?,?)")
                    ->execute(['every_order', '每单返现', 'percent', 2.00, 10.00, 1, '推广用户每笔订单返2%']);
            } catch (Exception $e) {}
        } catch (Exception $e) {}
    }
    // 广告位表
    if (!in_array('ad_positions', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ad_positions (
                id INT PRIMARY KEY AUTO_INCREMENT, pos_key VARCHAR(50) NOT NULL UNIQUE,
                pos_name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT '',
                width INT DEFAULT 0, height INT DEFAULT 0, status ENUM('active','disabled') DEFAULT 'active',
                sort_order INT DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status), INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $default_positions = [
                ['user_dashboard_top', '用户后台顶部', '用户后台首页顶部横幅广告', 728, 90, 1],
                ['user_dashboard_bottom', '用户后台底部', '用户后台首页底部横幅广告', 728, 90, 2],
                ['checkout_top', '购买页顶部', '购买流程页面顶部广告', 728, 90, 3],
            ];
            foreach ($default_positions as $p) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO ad_positions (pos_key, pos_name, description, width, height, sort_order) VALUES (?,?,?,?,?,?)")->execute($p);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }
    // 广告表
    if (!in_array('ads', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ads (
                id INT PRIMARY KEY AUTO_INCREMENT, title VARCHAR(200) NOT NULL, pos_id INT NOT NULL,
                image_url VARCHAR(500) NOT NULL, link_url VARCHAR(500) DEFAULT '', link_target ENUM('_self','_blank') DEFAULT '_blank',
                start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, click_count INT DEFAULT 0,
                status ENUM('active','paused','expired') DEFAULT 'active', sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (pos_id) REFERENCES ad_positions(id) ON DELETE CASCADE,
                INDEX idx_pos_id (pos_id), INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    // ====== ads 表迁移：旧简单版 -> 广告联盟版 ======
    if (in_array('ads', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM ads")->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map('strtolower', $cols);
            // 有 pos_id 字段就移除外键约束（广告联盟版不再依赖 ad_positions）
            if (in_array('pos_id', $cols)) {
                try {
                    $fk_rows = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ads' AND CONSTRAINT_NAME != 'PRIMARY' AND REFERENCED_TABLE_NAME IS NOT NULL AND COLUMN_NAME = 'pos_id'")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($fk_rows as $fk_name) {
                        try { $pdo->exec("ALTER TABLE ads DROP FOREIGN KEY `$fk_name`"); } catch (Exception $e) {}
                    }
                } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE ads DROP INDEX idx_pos_id"); } catch (Exception $e) {}
                // pos_id 设默认值
                try { $pdo->exec("ALTER TABLE ads MODIFY COLUMN pos_id INT DEFAULT 0"); } catch (Exception $e) {}
            }
            // 检测是否为旧表（有 pos_id 但没有 ad_name）
            if (in_array('pos_id', $cols) && !in_array('ad_name', $cols)) {
                // 添加新字段
                $add_cols = [
                    "ADD COLUMN ad_name VARCHAR(200) NOT NULL DEFAULT '' COMMENT '广告名称' AFTER id",
                    "ADD COLUMN ad_type ENUM('banner','popup','native','text') DEFAULT 'banner' COMMENT '广告类型' AFTER ad_name",
                    "ADD COLUMN ad_title VARCHAR(255) DEFAULT '' COMMENT '广告标题' AFTER ad_type",
                    "ADD COLUMN ad_desc TEXT COMMENT '广告描述' AFTER ad_title",
                    "ADD COLUMN target_url VARCHAR(500) NOT NULL DEFAULT '' COMMENT '跳转链接' AFTER image_url",
                    "ADD COLUMN width INT DEFAULT 0 COMMENT '宽度(px)' AFTER target_url",
                    "ADD COLUMN height INT DEFAULT 0 COMMENT '高度(px)' AFTER width",
                    "ADD COLUMN cpc_rate DECIMAL(10,4) DEFAULT 0.0000 COMMENT '单次点击收益(元)' AFTER height",
                    "ADD COLUMN cpm_rate DECIMAL(10,4) DEFAULT 0.0000 COMMENT '千次展示收益(元)' AFTER cpc_rate",
                    "ADD COLUMN total_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '总预算' AFTER cpm_rate",
                    "ADD COLUMN used_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '已用预算' AFTER total_budget",
                    "ADD COLUMN daily_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '每日预算' AFTER used_budget",
                    "ADD COLUMN target_category VARCHAR(100) DEFAULT '' COMMENT '目标分类' AFTER end_date",
                ];
                foreach ($add_cols as $sql) {
                    try { $pdo->exec("ALTER TABLE ads $sql"); } catch (Exception $e) {}
                }
                // 数据迁移：title -> ad_name / ad_title, link_url -> target_url
                try {
                    $pdo->exec("UPDATE ads SET ad_name = title, ad_title = title, target_url = link_url WHERE ad_name = ''");
                } catch (Exception $e) {}
                // 旧字段设默认值，避免新代码插入时报错
                $old_cols = [
                    "MODIFY COLUMN title VARCHAR(200) DEFAULT ''",
                    "MODIFY COLUMN link_url VARCHAR(500) DEFAULT ''",
                    "MODIFY COLUMN link_target ENUM('_self','_blank') DEFAULT '_blank'",
                    "MODIFY COLUMN click_count INT DEFAULT 0",
                ];
                foreach ($old_cols as $sql) {
                    try { $pdo->exec("ALTER TABLE ads $sql"); } catch (Exception $e) {}
                }
                // 扩展 status 枚举
                try { $pdo->exec("ALTER TABLE ads MODIFY COLUMN status ENUM('active','paused','expired','completed') DEFAULT 'active' COMMENT '状态'"); } catch (Exception $e) {}
                // 添加新索引
                try { $pdo->exec("ALTER TABLE ads ADD INDEX idx_type (ad_type), ADD INDEX idx_sort (sort_order)"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }
    // ====== KVM 系统镜像表 ======
    if (!in_array('vm_images', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_images (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL COMMENT '系统名称',
                os_type ENUM('linux','windows','other') DEFAULT 'linux',
                iso_path VARCHAR(500) NOT NULL COMMENT '宿主机上的ISO路径（用于安装系统）',
                disk_type ENUM('qcow2','raw','img','vmdk','vdi') DEFAULT 'qcow2' COMMENT '磁盘镜像格式',
                preinstalled_image VARCHAR(500) DEFAULT '' COMMENT '预装好的系统镜像路径（qcow2/img/raw格式）',
                version VARCHAR(50) DEFAULT '',
                arch VARCHAR(20) DEFAULT 'x86_64',
                min_cpu INT DEFAULT 1,
                min_memory_mb INT DEFAULT 1024,
                min_disk_gb INT DEFAULT 20,
                recommended VARCHAR(255) DEFAULT '',
                default_username VARCHAR(50) DEFAULT 'root',
                default_password VARCHAR(100) DEFAULT '',
                description VARCHAR(500) DEFAULT '',
                status ENUM('active','maintain','disabled') DEFAULT 'active',
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_os_type (os_type),
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 初始化默认镜像
            try {
                $stm = $pdo->prepare("INSERT IGNORE INTO vm_images (name, os_type, iso_path, version, arch, min_cpu, min_memory_mb, min_disk_gb, recommended, default_username, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stm->execute(['Ubuntu 22.04 LTS', 'linux', '/var/lib/libvirt/images/iso/ubuntu-22.04.iso', '22.04', 'x86_64', 1, 1024, 20, '推荐使用', 'root', 1]);
                $stm->execute(['Ubuntu 24.04 LTS', 'linux', '/var/lib/libvirt/images/iso/ubuntu-24.04.iso', '24.04', 'x86_64', 1, 2048, 25, '最新版', 'root', 2]);
                $stm->execute(['CentOS 7', 'linux', '/var/lib/libvirt/images/iso/centos7.iso', '7', 'x86_64', 1, 1024, 20, '稳定成熟', 'root', 3]);
                $stm->execute(['Debian 12', 'linux', '/var/lib/libvirt/images/iso/debian-12.iso', '12', 'x86_64', 1, 1024, 20, '轻量级', 'root', 4]);
                $stm->execute(['Windows Server 2022', 'windows', '/var/lib/libvirt/images/iso/win2022.iso', '2022', 'x86_64', 2, 4096, 60, '企业级', 'Administrator', 5]);
                $stm->execute(['Windows 11', 'windows', '/var/lib/libvirt/images/iso/win11.iso', '11', 'x86_64', 2, 4096, 50, '桌面系统', 'Administrator', 6]);
            } catch (Exception $e) {}
        } catch (Exception $e) {}
    }

    // ====== vm_images 表补全字段（旧表迁移） ======
    try {
        $img_cols = $pdo->query("DESCRIBE vm_images")->fetchAll(PDO::FETCH_COLUMN);
        $img_need = [
            'iso_path VARCHAR(500) DEFAULT ""' => 'iso_path',
            'description VARCHAR(500) DEFAULT ""' => 'description',
            'default_password VARCHAR(100) DEFAULT ""' => 'default_password',
            'status ENUM("active","maintain","disabled") DEFAULT "active"' => 'status',
            'sort_order INT DEFAULT 0' => 'sort_order',
            'image_url VARCHAR(500) DEFAULT ""' => 'image_url',
            'disk_type ENUM("qcow2","raw","img","vmdk","vdi") DEFAULT "qcow2"' => 'disk_type',
            'preinstalled_image VARCHAR(500) DEFAULT ""' => 'preinstalled_image',
        ];
        foreach ($img_need as $def => $cname) {
            if (!in_array($cname, $img_cols)) {
                try { $pdo->exec("ALTER TABLE vm_images ADD COLUMN $def"); } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {}

    // ====== packages 表扩展 KVM 字段 ======
    try {
        $pkg_cols = $pdo->query("DESCRIBE packages")->fetchAll(PDO::FETCH_COLUMN);
        $pkg_need = [
            'is_kvm TINYINT(1) DEFAULT 0' => 'is_kvm',
            'kvm_vcpu INT DEFAULT 2' => 'kvm_vcpu',
            'kvm_memory_mb INT DEFAULT 2048' => 'kvm_memory_mb',
            'kvm_disk_gb INT DEFAULT 40' => 'kvm_disk_gb',
            'kvm_bandwidth_mbps INT DEFAULT 100' => 'kvm_bandwidth_mbps',
        ];
        foreach ($pkg_need as $def => $cname) {
            if (!in_array($cname, $pkg_cols)) {
                try { $pdo->exec("ALTER TABLE packages ADD COLUMN $def"); } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {}

    // ====== hosts 表扩展 KVM 字段（逐个安全添加） ======
    try {
        $cols = $pdo->query("DESCRIBE hosts")->fetchAll(PDO::FETCH_COLUMN);
        $need_columns = [
            'vm_type VARCHAR(20) DEFAULT "web"' => 'vm_type',
            'vcpu INT DEFAULT 1' => 'vcpu',
            'memory_mb INT DEFAULT 1024' => 'memory_mb',
            'disk_gb INT DEFAULT 40' => 'disk_gb',
            'vm_uuid VARCHAR(60) DEFAULT ""' => 'vm_uuid',
            'uuid VARCHAR(128) DEFAULT NULL' => 'uuid',
            'vm_name VARCHAR(100) DEFAULT ""' => 'vm_name',
            'image_id INT DEFAULT 0' => 'image_id',
            'ip_address VARCHAR(60) DEFAULT ""' => 'ip_address',
            'root_password VARCHAR(100) DEFAULT ""' => 'root_password',
            'vnc_port INT DEFAULT 0' => 'vnc_port',
            'ssh_port INT DEFAULT 22' => 'ssh_port',
            'vm_power_status VARCHAR(30) DEFAULT "creating"' => 'vm_power_status',
            'vm_created_at TIMESTAMP NULL' => 'vm_created_at',
            'vm_last_sync TIMESTAMP NULL' => 'vm_last_sync',
            'bandwidth_mbps INT DEFAULT 100' => 'bandwidth_mbps',
            'kvm_node_id INT DEFAULT 0' => 'kvm_node_id',
        ];
        foreach ($need_columns as $def => $colname) {
            if (!in_array($colname, $cols)) {
                try {
                    $pdo->exec("ALTER TABLE hosts ADD COLUMN $def");
                } catch (Exception $e) {}
            }
        }
        // 索引
        if (!in_array('idx_vm_type', array_map(function($k) use ($pdo) { return $k; }, []))) {
            try {
                $pdo->exec("ALTER TABLE hosts ADD INDEX idx_vm_type (vm_type)");
                $pdo->exec("ALTER TABLE hosts ADD INDEX idx_vm_power (vm_power_status)");
                $pdo->exec("ALTER TABLE hosts ADD INDEX idx_image_id (image_id)");
            } catch (Exception $e) {}
        }
        // 为已有主机生成uuid
        try {
            $stmt = $pdo->query("SELECT id, uuid FROM hosts WHERE uuid IS NULL OR uuid = '' LIMIT 100");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $new_uuid = kvm_generate_host_uuid();
                $pdo->exec("UPDATE hosts SET uuid = '" . $pdo->quote($new_uuid) . "' WHERE id = " . intval($row['id']));
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {}

    // ====== IP池表 ======
    if (!in_array('ip_pools', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ip_pools (
                id INT PRIMARY KEY AUTO_INCREMENT,
                pool_name VARCHAR(100) NOT NULL COMMENT 'IP池名称',
                pool_type ENUM('dedicated','nat') DEFAULT 'dedicated' COMMENT '池类型：dedicated=独立IP，nat=NAT共享',
                public_ip VARCHAR(45) DEFAULT '' COMMENT '公网IP（NAT池使用）',
                ip_start VARCHAR(45) DEFAULT '' COMMENT '起始IP（独立池使用）',
                ip_end VARCHAR(45) DEFAULT '' COMMENT '结束IP（独立池使用）',
                nat_port_start INT DEFAULT 20000 COMMENT '起始端口（NAT池使用）',
                nat_port_end INT DEFAULT 59999 COMMENT '结束端口（NAT池使用）',
                gateway VARCHAR(45) DEFAULT '' COMMENT '网关',
                netmask VARCHAR(45) DEFAULT '255.255.255.0' COMMENT '子网掩码',
                total_count INT DEFAULT 0 COMMENT '总IP数',
                used_count INT DEFAULT 0 COMMENT '已用IP数',
                status ENUM('active','disabled') DEFAULT 'active',
                description VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_pool_type (pool_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    } else {
        // 检查并添加新字段（如果不存在）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM ip_pools")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('pool_type', $cols)) {
                $pdo->exec("ALTER TABLE ip_pools ADD COLUMN pool_type ENUM('dedicated','nat') DEFAULT 'dedicated' COMMENT '池类型' AFTER pool_name");
            }
            if (!in_array('public_ip', $cols)) {
                $pdo->exec("ALTER TABLE ip_pools ADD COLUMN public_ip VARCHAR(45) DEFAULT '' COMMENT '公网IP（NAT池使用）' AFTER pool_type");
            }
            if (!in_array('nat_port_start', $cols)) {
                $pdo->exec("ALTER TABLE ip_pools ADD COLUMN nat_port_start INT DEFAULT 20000 COMMENT '起始端口（NAT池使用）' AFTER ip_end");
            }
            if (!in_array('nat_port_end', $cols)) {
                $pdo->exec("ALTER TABLE ip_pools ADD COLUMN nat_port_end INT DEFAULT 59999 COMMENT '结束端口（NAT池使用）' AFTER nat_port_start");
            }
        } catch (Exception $e) {}
    }

    // ====== IP地址分配记录表 ======
    if (!in_array('ip_assignments', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ip_assignments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                pool_id INT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                host_id INT DEFAULT 0,
                user_id INT DEFAULT 0,
                status ENUM('available','assigned','reserved','disabled') DEFAULT 'available',
                assigned_at TIMESTAMP NULL,
                released_at TIMESTAMP NULL,
                remark VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (pool_id) REFERENCES ip_pools(id) ON DELETE CASCADE,
                INDEX idx_pool_id (pool_id),
                INDEX idx_ip (ip_address),
                INDEX idx_status (status),
                INDEX idx_host_id (host_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== NAT规则表 ======
    if (!in_array('nat_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE nat_rules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL,
                user_id INT NOT NULL,
                rule_name VARCHAR(100) NOT NULL COMMENT '规则名称',
                protocol ENUM('tcp','udp') DEFAULT 'tcp',
                local_ip VARCHAR(45) NOT NULL COMMENT '内网IP',
                local_port INT NOT NULL COMMENT '内网端口',
                remote_ip VARCHAR(45) DEFAULT '' COMMENT '外网IP',
                remote_port INT NOT NULL COMMENT '外网端口',
                frp_rule_name VARCHAR(100) DEFAULT '' COMMENT 'FRP规则名',
                status ENUM('active','disabled','error') DEFAULT 'active',
                frp_status VARCHAR(20) DEFAULT '',
                error_msg VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_remote_port (remote_port)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== KVM快照表 ======
    if (!in_array('vm_snapshots', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_snapshots (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL,
                user_id INT NOT NULL,
                snapshot_name VARCHAR(100) NOT NULL COMMENT '快照名称',
                snapshot_desc VARCHAR(255) DEFAULT '' COMMENT '快照描述',
                libvirt_name VARCHAR(100) DEFAULT '' COMMENT 'libvirt中的快照名',
                snapshot_type ENUM('internal','disk-only') DEFAULT 'internal' COMMENT '快照类型',
                snapshot_size BIGINT DEFAULT 0 COMMENT '快照大小(字节)',
                status ENUM('creating','available','restoring','deleting','error') DEFAULT 'creating',
                error_msg VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    } else {
        try {
            $pdo->exec("ALTER TABLE vm_snapshots ADD COLUMN IF NOT EXISTS snapshot_type ENUM('internal','disk-only') DEFAULT 'internal' COMMENT '快照类型' AFTER libvirt_name");
        } catch (Exception $e) {}
    }

    // ====== 防火墙规则表 ======
    if (!in_array('firewall_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE firewall_rules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL,
                user_id INT NOT NULL,
                rule_name VARCHAR(100) NOT NULL COMMENT '规则名称',
                protocol ENUM('tcp','udp','icmp','all') DEFAULT 'tcp',
                port INT DEFAULT 0 COMMENT '端口(0表示所有)',
                port_range VARCHAR(50) DEFAULT '' COMMENT '端口范围(如: 1000-2000)',
                source_ip VARCHAR(45) DEFAULT '' COMMENT '源IP(空表示所有)',
                action ENUM('accept','drop','reject') DEFAULT 'accept' COMMENT '动作',
                direction ENUM('inbound','outbound') DEFAULT 'inbound' COMMENT '方向',
                status ENUM('active','disabled') DEFAULT 'active',
                sort_order INT DEFAULT 0,
                applied TINYINT DEFAULT 0 COMMENT '是否已应用到防火墙',
                apply_error TEXT COMMENT '应用错误信息',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== KVM任务表 ======
    if (!in_array('vm_tasks', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_tasks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL,
                user_id INT NOT NULL,
                task_type ENUM('reinstall','resize','backup','migrate','convert_iso','restore','delete_snapshot') DEFAULT 'reinstall',
                task_data TEXT COMMENT '任务参数JSON',
                status ENUM('pending','running','completed','error') DEFAULT 'pending',
                result_msg TEXT COMMENT '执行结果',
                error_msg TEXT COMMENT '错误信息',
                started_at TIMESTAMP NULL COMMENT '开始时间',
                finished_at TIMESTAMP NULL COMMENT '完成时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_status (status),
                INDEX idx_task_type (task_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    } else {
        try {
            $cols = [];
            $q = $pdo->query("SHOW COLUMNS FROM firewall_rules");
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
            if (!in_array('applied', $cols)) {
                $pdo->exec("ALTER TABLE firewall_rules ADD COLUMN applied TINYINT DEFAULT 0 COMMENT '是否已应用到防火墙' AFTER sort_order");
            }
            if (!in_array('apply_error', $cols)) {
                $pdo->exec("ALTER TABLE firewall_rules ADD COLUMN apply_error TEXT COMMENT '应用错误信息' AFTER applied");
            } else {
                // 已存在但可能是 VARCHAR(255)，扩展为 TEXT
                $pdo->exec("ALTER TABLE firewall_rules MODIFY COLUMN apply_error TEXT COMMENT '应用错误信息'");
            }
        } catch (Exception $e) {}
    }

    // 迁移 vm_tasks 表的 task_type 字段，添加新类型
    if (in_array('vm_tasks', $existing_tables)) {
        try {
            $pdo->exec("ALTER TABLE vm_tasks MODIFY COLUMN task_type ENUM('reinstall','resize','backup','migrate','convert_iso','restore','delete_snapshot') DEFAULT 'reinstall'");
        } catch (Exception $e) {}
    }

    // 为已有用户初始化积分记录
    try {
        $pdo->exec("INSERT IGNORE INTO user_points (user_id, points, total_earned, total_spent)
            SELECT id, 0, 0, 0 FROM users WHERE id NOT IN (SELECT COALESCE(user_id,0) FROM user_points)");
    } catch (Exception $e) {}

    // 为IP池表添加NAT类型字段
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM ip_pools");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        if (!in_array('pool_type', $cols)) {
            $pdo->exec("ALTER TABLE ip_pools ADD COLUMN pool_type ENUM('dedicated','nat') DEFAULT 'dedicated' COMMENT 'IP池类型: dedicated=独立IP, nat=NAT共享IP' AFTER pool_name");
        }
        if (!in_array('public_ip', $cols)) {
            $pdo->exec("ALTER TABLE ip_pools ADD COLUMN public_ip VARCHAR(45) DEFAULT '' COMMENT 'NAT池公网IP' AFTER pool_type");
        }
    } catch (Exception $e) {}

    // 为套餐表添加NAT KVM类型字段
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM packages");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        if (!in_array('is_nat_kvm', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN is_nat_kvm TINYINT DEFAULT 0 COMMENT '是否NAT共享IP KVM' AFTER is_kvm");
        }
    } catch (Exception $e) {}

    // 为主机表添加NAT KVM和公网IP字段
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM hosts");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        if (!in_array('is_nat_kvm', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN is_nat_kvm TINYINT DEFAULT 0 COMMENT '是否NAT共享IP KVM' AFTER vm_type");
        }
        if (!in_array('public_ip', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN public_ip VARCHAR(45) DEFAULT '' COMMENT '公网IP(NAT主机用)' AFTER ip_address");
        }
        if (!in_array('root_password', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN root_password VARCHAR(100) DEFAULT '' COMMENT 'root密码' AFTER public_ip");
        }
        if (!in_array('traffic_used', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN traffic_used INT DEFAULT 0 COMMENT '已用流量MB' AFTER api_response");
        }
        if (!in_array('traffic_limit', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN traffic_limit INT DEFAULT 0 COMMENT '流量限制MB' AFTER traffic_used");
        }
        if (!in_array('traffic_reset_date', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN traffic_reset_date DATE DEFAULT NULL COMMENT '流量重置日期' AFTER traffic_limit");
        }
        if (!in_array('traffic_warned_at', $cols)) {
            $pdo->exec("ALTER TABLE hosts ADD COLUMN traffic_warned_at DATE DEFAULT NULL COMMENT '流量警告发送日期' AFTER traffic_reset_date");
        }
    } catch (Exception $e) {}

    // 更新hosts表status枚举，添加suspended_traffic
    try {
        $pdo->exec("ALTER TABLE hosts MODIFY COLUMN status ENUM('creating', 'running', 'suspended', 'cancelled', 'suspended_traffic') DEFAULT 'creating'");
    } catch (Exception $e) {}

    // 为套餐表添加KVM规格字段
    try {
        $cols = [];
        $q = $pdo->query("SHOW COLUMNS FROM packages");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        if (!in_array('kvm_vcpu', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN kvm_vcpu INT DEFAULT 2 COMMENT 'KVM核心数' AFTER status");
        }
        if (!in_array('kvm_memory_mb', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN kvm_memory_mb INT DEFAULT 2048 COMMENT 'KVM内存MB' AFTER kvm_vcpu");
        }
        if (!in_array('kvm_disk_gb', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN kvm_disk_gb INT DEFAULT 40 COMMENT 'KVM磁盘GB' AFTER kvm_memory_mb");
        }
        if (!in_array('kvm_bandwidth_mbps', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN kvm_bandwidth_mbps INT DEFAULT 100 COMMENT 'KVM带宽Mbps' AFTER kvm_disk_gb");
        }
        if (!in_array('kvm_traffic_gb', $cols)) {
            $pdo->exec("ALTER TABLE packages ADD COLUMN kvm_traffic_gb INT DEFAULT 100 COMMENT 'KVM流量GB/月' AFTER kvm_bandwidth_mbps");
        }
    } catch (Exception $e) {}

    // 更新packages表type枚举，添加KVM类型
    try {
        $pdo->exec("ALTER TABLE packages MODIFY COLUMN type INT DEFAULT 2 COMMENT '1=CDN,2=主机,3=KVM'");
    } catch (Exception $e) {}

    // ====== 主机流量监控表 ======
    if (!in_array('host_traffic', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE host_traffic (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL COMMENT '主机ID',
                rx_bytes BIGINT DEFAULT 0 COMMENT '接收字节数',
                tx_bytes BIGINT DEFAULT 0 COMMENT '发送字节数',
                total_bytes BIGINT DEFAULT 0 COMMENT '总流量字节数',
                collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '采集时间',
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_collected (collected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // 为已有主机初始化流量重置日期
    try {
        $pdo->exec("UPDATE hosts SET traffic_reset_date = '" . date('Y-m-01') . "' WHERE traffic_reset_date IS NULL AND package_id IN (SELECT id FROM packages WHERE type = 3)");
    } catch (Exception $e) {}

    // ====== 规格升级价格表 ======
    if (!in_array('resize_prices', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE resize_prices (
                id INT PRIMARY KEY AUTO_INCREMENT,
                resource_type ENUM('cpu','memory','disk') NOT NULL COMMENT '资源类型: cpu=CPU核心, memory=内存, disk=磁盘',
                unit VARCHAR(20) NOT NULL COMMENT '单位: core=核, MB=MB, GB=GB',
                unit_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '每单位价格(元)',
                min_value INT DEFAULT 0 COMMENT '最小调整值',
                max_value INT DEFAULT 0 COMMENT '最大调整值',
                step INT DEFAULT 1 COMMENT '调整步长',
                status ENUM('active','disabled') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_resource_type (resource_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("INSERT IGNORE INTO resize_prices (resource_type, unit, unit_price, min_value, max_value, step, status) VALUES
                ('cpu', 'core', 50.00, 1, 64, 1, 'active'),
                ('memory', 'GB', 20.00, 1, 256, 1, 'active'),
                ('disk', 'GB', 5.00, 10, 2000, 10, 'active')");
        } catch (Exception $e) {}
    }

    // ====== 主机升级订单表 ======
    if (!in_array('host_upgrades', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE host_upgrades (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_no VARCHAR(50) NOT NULL COMMENT '订单号',
                host_id INT NOT NULL COMMENT '主机ID',
                user_id INT NOT NULL COMMENT '用户ID',
                old_vcpu INT DEFAULT 0 COMMENT '原CPU核心数',
                new_vcpu INT DEFAULT 0 COMMENT '新CPU核心数',
                old_memory_mb INT DEFAULT 0 COMMENT '原内存(MB)',
                new_memory_mb INT DEFAULT 0 COMMENT '新内存(MB)',
                old_disk_gb INT DEFAULT 0 COMMENT '原磁盘(GB)',
                new_disk_gb INT DEFAULT 0 COMMENT '新磁盘(GB)',
                total_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '总价格',
                status ENUM('pending','paid','completed','failed','cancelled') DEFAULT 'pending' COMMENT '状态',
                pay_time DATETIME NULL COMMENT '支付时间',
                complete_time DATETIME NULL COMMENT '完成时间',
                fail_reason VARCHAR(255) DEFAULT '' COMMENT '失败原因',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_order_no (order_no),
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 站内通知表 ======
    if (!in_array('user_notifications', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE user_notifications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                type ENUM('system','host','order','security','promotion') DEFAULT 'system' COMMENT '通知类型',
                title VARCHAR(200) NOT NULL COMMENT '标题',
                content TEXT COMMENT '内容',
                related_type VARCHAR(50) DEFAULT '' COMMENT '关联类型: host/order等',
                related_id INT DEFAULT 0 COMMENT '关联ID',
                is_read TINYINT(1) DEFAULT 0 COMMENT '是否已读: 0=未读, 1=已读',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_is_read (is_read),
                INDEX idx_type (type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== WebSSH Token表 ======
    if (!in_array('ssh_tokens', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ssh_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                token VARCHAR(64) NOT NULL UNIQUE COMMENT '一次性token',
                user_id INT NOT NULL COMMENT '用户ID',
                host_id INT NOT NULL COMMENT '主机ID',
                ip VARCHAR(50) NOT NULL COMMENT 'SSH IP地址',
                port INT DEFAULT 22 COMMENT 'SSH端口',
                username VARCHAR(50) DEFAULT 'root' COMMENT 'SSH用户名',
                password VARCHAR(255) DEFAULT '' COMMENT 'SSH密码',
                expire_at INT NOT NULL COMMENT '过期时间戳',
                used TINYINT(1) DEFAULT 0 COMMENT '是否已使用: 0=未用, 1=已用',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_user_id (user_id),
                INDEX idx_expire (expire_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 主机操作日志表 ======
    if (!in_array('host_operation_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE host_operation_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL COMMENT '主机ID',
                user_id INT NOT NULL COMMENT '用户ID',
                type ENUM('info','success','warning','error') DEFAULT 'info' COMMENT '日志类型',
                type_label VARCHAR(20) DEFAULT '' COMMENT '类型标签',
                action VARCHAR(100) DEFAULT '' COMMENT '操作动作',
                content VARCHAR(500) DEFAULT '' COMMENT '日志内容',
                ip VARCHAR(45) DEFAULT '' COMMENT '操作IP',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_type (type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 积分兑换商品表 ======
    if (!in_array('point_products', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE point_products (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(200) NOT NULL COMMENT '商品名称',
                category ENUM('host','server','voucher','other') DEFAULT 'host' COMMENT '商品分类: host=虚拟主机, server=云服务器, voucher=优惠券, other=其他',
                description TEXT COMMENT '商品描述',
                image_url VARCHAR(500) DEFAULT '' COMMENT '商品图片',
                points INT NOT NULL DEFAULT 0 COMMENT '所需积分',
                original_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '原价（元）',
                stock INT DEFAULT -1 COMMENT '库存数量, -1为无限',
                sold_count INT DEFAULT 0 COMMENT '已兑换数量',
                duration INT DEFAULT 0 COMMENT '有效期/时长(天)',
                package_id INT DEFAULT 0 COMMENT '关联套餐ID',
                discount_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '折扣率%',
                status ENUM('active','disabled') DEFAULT 'active' COMMENT '状态',
                sort_order INT DEFAULT 0 COMMENT '排序',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $default_products = [
                ['基础版虚拟主机月卡', 'host', '基础版虚拟主机1个月使用权限，适合个人网站', '', 500, 19.90, -1, 0, 30, 1, 100.00, 'active', 1],
                ['标准版虚拟主机月卡', 'host', '标准版虚拟主机1个月使用权限，中小型企业首选', '', 1000, 39.90, -1, 0, 30, 2, 100.00, 'active', 2],
                ['专业版虚拟主机月卡', 'host', '专业版虚拟主机1个月使用权限，大流量站点优选', '', 1800, 69.90, -1, 0, 30, 3, 100.00, 'active', 3],
                ['云服务器2核4G月卡', 'server', '云服务器2核4G配置1个月，适合中小型应用', '', 3000, 99.00, -1, 0, 30, 0, 100.00, 'active', 4],
                ['云服务器4核8G月卡', 'server', '云服务器4核8G配置1个月，企业级应用首选', '', 6000, 199.00, -1, 0, 30, 0, 100.00, 'active', 5],
                ['10元无门槛优惠券', 'voucher', '全场通用10元优惠券，无最低消费限制', '', 200, 10.00, -1, 0, 30, 0, 100.00, 'active', 6],
                ['50元满减优惠券', 'voucher', '满200元减50元优惠券，适用于所有套餐', '', 800, 50.00, -1, 0, 30, 0, 100.00, 'active', 7],
            ];
            foreach ($default_products as $p) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO point_products (name, category, description, image_url, points, original_price, stock, sold_count, duration, package_id, discount_rate, status, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($p);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }

    // ====== 积分兑换记录表 ======
    if (!in_array('point_exchange_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE point_exchange_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                product_id INT NOT NULL COMMENT '商品ID',
                product_name VARCHAR(200) NOT NULL COMMENT '商品名称快照',
                category VARCHAR(50) DEFAULT '' COMMENT '商品分类快照',
                points INT NOT NULL COMMENT '消耗积分',
                status ENUM('pending','completed','failed','cancelled') DEFAULT 'completed' COMMENT '状态',
                related_id INT DEFAULT 0 COMMENT '关联ID(如订单ID/主机ID)',
                remark VARCHAR(500) DEFAULT '' COMMENT '备注',
                expire_at TIMESTAMP NULL COMMENT '过期时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_product_id (product_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== API密钥表 ======
    if (!in_array('api_keys', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE api_keys (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                key_name VARCHAR(100) NOT NULL COMMENT '密钥名称',
                api_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'API Key',
                api_secret VARCHAR(128) NOT NULL COMMENT 'API Secret',
                status ENUM('pending','active','disabled','rejected') DEFAULT 'pending' COMMENT '状态:pending待审核,active启用,disabled禁用,rejected拒绝',
                ip_whitelist TEXT COMMENT 'IP白名单，逗号分隔，空为不限制',
                rate_limit INT DEFAULT 100 COMMENT '每分钟请求限制',
                last_used_at TIMESTAMP NULL COMMENT '最后使用时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL COMMENT '过期时间，NULL为永久',
                review_by INT DEFAULT NULL COMMENT '审核人ID',
                review_at TIMESTAMP NULL COMMENT '审核时间',
                reject_reason VARCHAR(500) DEFAULT '' COMMENT '拒绝原因',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_api_key (api_key),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    
    if (in_array('api_keys', $existing_tables)) {
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM api_keys LIKE 'status'")->fetchAll();
            if (!empty($columns) && strpos($columns[0]['Type'], 'pending') === false) {
                $pdo->exec("ALTER TABLE api_keys MODIFY COLUMN status ENUM('pending','active','disabled','rejected') DEFAULT 'pending'");
            }
        } catch (Exception $e) {}
        try {
            $has_review = $pdo->query("SHOW COLUMNS FROM api_keys LIKE 'review_by'")->fetchAll();
            if (empty($has_review)) {
                $pdo->exec("ALTER TABLE api_keys 
                    ADD COLUMN review_by INT DEFAULT NULL COMMENT '审核人ID' AFTER expires_at,
                    ADD COLUMN review_at TIMESTAMP NULL COMMENT '审核时间' AFTER review_by,
                    ADD COLUMN reject_reason VARCHAR(500) DEFAULT '' COMMENT '拒绝原因' AFTER review_at");
            }
        } catch (Exception $e) {}
    }

    // ====== API请求日志表 ======
    if (!in_array('api_request_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE api_request_logs (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                api_key_id INT DEFAULT NULL COMMENT 'API密钥ID',
                user_id INT DEFAULT NULL COMMENT '用户ID',
                method VARCHAR(10) NOT NULL COMMENT '请求方法',
                endpoint VARCHAR(200) NOT NULL COMMENT '请求端点',
                params TEXT COMMENT '请求参数(JSON)',
                ip VARCHAR(45) DEFAULT '' COMMENT '请求IP',
                status_code INT DEFAULT 0 COMMENT '响应状态码',
                response_time INT DEFAULT 0 COMMENT '响应时间(ms)',
                error_msg VARCHAR(255) DEFAULT '' COMMENT '错误信息',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_key_id (api_key_id),
                INDEX idx_user_id (user_id),
                INDEX idx_endpoint (endpoint),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 财务账单表 ======
    if (!in_array('billing_records', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE billing_records (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                host_id INT DEFAULT NULL COMMENT '主机ID',
                order_id INT DEFAULT NULL COMMENT '订单ID',
                bill_type ENUM('package','renew','upgrade','overage','refund','adjust','hourly') NOT NULL COMMENT '账单类型',
                amount DECIMAL(10,2) NOT NULL COMMENT '金额，正为扣费，负为退款',
                balance_before DECIMAL(10,2) DEFAULT 0 COMMENT '变动前余额',
                balance_after DECIMAL(10,2) DEFAULT 0 COMMENT '变动后余额',
                description VARCHAR(500) DEFAULT '' COMMENT '描述',
                billing_period VARCHAR(50) DEFAULT '' COMMENT '计费周期 2024-01',
                status ENUM('pending','paid','cancelled','refunded') DEFAULT 'paid' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_host_id (host_id),
                INDEX idx_bill_type (bill_type),
                INDEX idx_billing_period (billing_period),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 节点告警记录表 ======
    if (!in_array('node_alerts', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE node_alerts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                node_id INT DEFAULT NULL COMMENT '节点ID',
                host_id INT DEFAULT NULL COMMENT '主机ID',
                alert_type ENUM('cpu_high','memory_high','disk_high','traffic_high','node_offline','vm_offline','security') NOT NULL COMMENT '告警类型',
                alert_level ENUM('info','warning','critical') DEFAULT 'warning' COMMENT '告警级别',
                title VARCHAR(200) NOT NULL COMMENT '告警标题',
                content TEXT COMMENT '告警内容',
                metric_value VARCHAR(100) DEFAULT '' COMMENT '指标值',
                threshold VARCHAR(100) DEFAULT '' COMMENT '阈值',
                status ENUM('active','acknowledged','resolved') DEFAULT 'active' COMMENT '状态',
                acknowledged_by INT DEFAULT NULL COMMENT '确认人ID',
                acknowledged_at TIMESTAMP NULL COMMENT '确认时间',
                resolved_at TIMESTAMP NULL COMMENT '解决时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_node_id (node_id),
                INDEX idx_host_id (host_id),
                INDEX idx_alert_type (alert_type),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 广告表 ======
    if (!in_array('ads', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ads (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ad_name VARCHAR(200) NOT NULL COMMENT '广告名称',
                ad_type ENUM('banner','popup','native','text') DEFAULT 'banner' COMMENT '广告类型',
                ad_title VARCHAR(255) DEFAULT '' COMMENT '广告标题',
                ad_desc TEXT COMMENT '广告描述',
                image_url VARCHAR(500) DEFAULT '' COMMENT '广告图片',
                target_url VARCHAR(500) NOT NULL COMMENT '跳转链接',
                width INT DEFAULT 0 COMMENT '宽度(px)',
                height INT DEFAULT 0 COMMENT '高度(px)',
                cpc_rate DECIMAL(10,4) DEFAULT 0.0000 COMMENT '单次点击收益(元)',
                cpm_rate DECIMAL(10,4) DEFAULT 0.0000 COMMENT '千次展示收益(元)',
                total_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '总预算',
                used_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '已用预算',
                daily_budget DECIMAL(10,2) DEFAULT 0.00 COMMENT '每日预算',
                start_date DATE DEFAULT NULL COMMENT '开始日期',
                end_date DATE DEFAULT NULL COMMENT '结束日期',
                target_category VARCHAR(100) DEFAULT '' COMMENT '目标分类',
                status ENUM('active','paused','expired','completed') DEFAULT 'active' COMMENT '状态',
                sort_order INT DEFAULT 0 COMMENT '排序',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_type (ad_type),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 广告位表 ======
    if (!in_array('ad_spots', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ad_spots (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                spot_name VARCHAR(200) NOT NULL COMMENT '广告位名称',
                spot_type ENUM('banner','popup','native','text') DEFAULT 'banner' COMMENT '广告位类型',
                website_url VARCHAR(500) NOT NULL COMMENT '网站地址',
                website_name VARCHAR(200) DEFAULT '' COMMENT '网站名称',
                website_category VARCHAR(100) DEFAULT '' COMMENT '网站分类',
                traffic_daily INT DEFAULT 0 COMMENT '日预估流量',
                ad_code TEXT COMMENT '广告代码',
                status ENUM('pending','active','rejected','suspended') DEFAULT 'pending' COMMENT '状态',
                reject_reason VARCHAR(500) DEFAULT '' COMMENT '拒绝原因',
                total_impressions BIGINT DEFAULT 0 COMMENT '总展示数',
                total_clicks INT DEFAULT 0 COMMENT '总点击数',
                total_earnings DECIMAL(10,2) DEFAULT 0.00 COMMENT '总收益',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    
    // ====== ad_spots 表迁移：添加风控字段 ======
    if (in_array('ad_spots', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM ad_spots")->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map('strtolower', $cols);
            if (!in_array('risk_status', $cols)) {
                @$pdo->exec("ALTER TABLE ad_spots ADD COLUMN risk_status ENUM('normal','frozen') DEFAULT 'normal' COMMENT '风控状态' AFTER reject_reason");
            }
            if (!in_array('frozen_reason', $cols)) {
                @$pdo->exec("ALTER TABLE ad_spots ADD COLUMN frozen_reason VARCHAR(500) DEFAULT '' COMMENT '冻结原因' AFTER risk_status");
            }
        } catch (Exception $e) {}
    }

    // ====== 广告统计表 ======
    if (!in_array('ad_stats', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ad_stats (
                id INT PRIMARY KEY AUTO_INCREMENT,
                stat_date DATE NOT NULL COMMENT '统计日期',
                ad_id INT NOT NULL COMMENT '广告ID',
                spot_id INT NOT NULL COMMENT '广告位ID',
                user_id INT NOT NULL COMMENT '用户ID',
                impressions INT DEFAULT 0 COMMENT '展示数',
                clicks INT DEFAULT 0 COMMENT '点击数',
                ctr DECIMAL(8,4) DEFAULT 0.0000 COMMENT '点击率%',
                earnings DECIMAL(10,4) DEFAULT 0.0000 COMMENT '收益',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_date (stat_date),
                INDEX idx_ad_id (ad_id),
                INDEX idx_spot_id (spot_id),
                INDEX idx_user_id (user_id),
                UNIQUE KEY idx_unique (stat_date, ad_id, spot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 广告点击记录表 ======
    if (!in_array('ad_clicks', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ad_clicks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ad_id INT NOT NULL COMMENT '广告ID',
                spot_id INT NOT NULL COMMENT '广告位ID',
                user_id INT NOT NULL COMMENT '发布者用户ID',
                ip_address VARCHAR(50) DEFAULT '' COMMENT 'IP地址',
                user_agent VARCHAR(500) DEFAULT '' COMMENT 'User-Agent',
                referrer VARCHAR(500) DEFAULT '' COMMENT '来源页',
                is_valid TINYINT(1) DEFAULT 1 COMMENT '是否有效点击',
                click_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ad_id (ad_id),
                INDEX idx_spot_id (spot_id),
                INDEX idx_user_id (user_id),
                INDEX idx_ip (ip_address),
                INDEX idx_time (click_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 广告收益记录表 ======
    if (!in_array('ad_earnings', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE ad_earnings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                amount DECIMAL(10,2) NOT NULL COMMENT '金额',
                type ENUM('cpc','cpm','settle','withdraw') DEFAULT 'cpc' COMMENT '类型',
                description VARCHAR(500) DEFAULT '' COMMENT '描述',
                related_id INT DEFAULT NULL COMMENT '关联ID',
                status ENUM('pending','available','withdrawn') DEFAULT 'pending' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== KVM节点表 ======
    if (!in_array('kvm_nodes', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE kvm_nodes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                node_name VARCHAR(100) NOT NULL UNIQUE COMMENT '节点名称',
                node_ip VARCHAR(45) NOT NULL COMMENT '节点IP地址',
                ssh_port INT DEFAULT 22 COMMENT 'SSH端口',
                username VARCHAR(50) DEFAULT 'root' COMMENT 'SSH用户名',
                password VARCHAR(255) DEFAULT '' COMMENT 'SSH密码/密钥',
                status ENUM('online','offline','maintain') DEFAULT 'offline' COMMENT '节点状态',
                cpu_total INT DEFAULT 0 COMMENT '总CPU核心数',
                cpu_usage INT DEFAULT 0 COMMENT 'CPU使用率%',
                memory_total_mb INT DEFAULT 0 COMMENT '总内存MB',
                memory_usage INT DEFAULT 0 COMMENT '内存使用率%',
                disk_total_gb INT DEFAULT 0 COMMENT '总磁盘GB',
                disk_usage INT DEFAULT 0 COMMENT '磁盘使用率%',
                current_vms INT DEFAULT 0 COMMENT '当前运行虚拟机数',
                max_vms INT DEFAULT 100 COMMENT '最大支持虚拟机数',
                storage_path VARCHAR(500) DEFAULT '/var/lib/libvirt/images' COMMENT '存储路径',
                description VARCHAR(500) DEFAULT '' COMMENT '节点描述',
                sort_order INT DEFAULT 0 COMMENT '排序',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== VM迁移记录表 ======
    if (!in_array('vm_migrations', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_migrations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                vm_name VARCHAR(100) NOT NULL COMMENT '虚拟机名称',
                host_id INT NOT NULL COMMENT '主机ID',
                source_node_id INT NOT NULL COMMENT '源节点ID',
                target_node_id INT NOT NULL COMMENT '目标节点ID',
                status ENUM('running','completed','failed','cancelled') DEFAULT 'running' COMMENT '迁移状态',
                ip_before VARCHAR(45) DEFAULT '' COMMENT '迁移前IP',
                ip_after VARCHAR(45) DEFAULT '' COMMENT '迁移后IP',
                error_msg VARCHAR(500) DEFAULT '' COMMENT '错误信息',
                started_at TIMESTAMP NULL COMMENT '开始时间',
                finished_at TIMESTAMP NULL COMMENT '完成时间',
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_status (status),
                INDEX idx_started (started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 存储池表 ======
    if (!in_array('storage_pools', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE storage_pools (
                id INT PRIMARY KEY AUTO_INCREMENT,
                pool_name VARCHAR(100) NOT NULL UNIQUE COMMENT '存储池名称',
                pool_type ENUM('local','nfs','iscsi','ceph','glusterfs') DEFAULT 'local' COMMENT '存储类型',
                node_id INT DEFAULT 0 COMMENT '所属节点ID(本地存储)',
                mount_path VARCHAR(500) NOT NULL COMMENT '挂载路径',
                total_size_gb INT DEFAULT 0 COMMENT '总容量GB',
                used_size_gb INT DEFAULT 0 COMMENT '已用容量GB',
                available_size_gb INT DEFAULT 0 COMMENT '可用容量GB',
                status ENUM('active','inactive','error','maintain') DEFAULT 'active' COMMENT '状态',
                is_shared TINYINT(1) DEFAULT 0 COMMENT '是否共享存储',
                nfs_server VARCHAR(100) DEFAULT '' COMMENT 'NFS服务器地址',
                nfs_path VARCHAR(500) DEFAULT '' COMMENT 'NFS导出路径',
                description VARCHAR(500) DEFAULT '' COMMENT '描述',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (pool_type),
                INDEX idx_status (status),
                INDEX idx_node (node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 虚拟机磁盘表 ======
    if (!in_array('vm_disks', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_disks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL COMMENT '主机ID',
                user_id INT NOT NULL COMMENT '用户ID',
                disk_name VARCHAR(100) NOT NULL COMMENT '磁盘名称',
                disk_type ENUM('system','data') DEFAULT 'system' COMMENT '磁盘类型: system=系统盘, data=数据盘',
                disk_format ENUM('qcow2','raw','vmdk','vdi','iso') DEFAULT 'qcow2' COMMENT '磁盘格式',
                disk_path VARCHAR(500) NOT NULL COMMENT '磁盘文件路径',
                disk_size_gb INT NOT NULL COMMENT '磁盘大小GB',
                allocated_size_gb INT DEFAULT 0 COMMENT '实际分配大小GB(稀疏格式)',
                pool_id INT DEFAULT 0 COMMENT '存储池ID',
                is_attached TINYINT(1) DEFAULT 1 COMMENT '是否已挂载',
                device_name VARCHAR(20) DEFAULT '' COMMENT '设备名(vda/vdb等)',
                read_only TINYINT(1) DEFAULT 0 COMMENT '是否只读',
                status ENUM('active','detached','error','resizing') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_disk_type (disk_type),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 快照策略表 ======
    if (!in_array('snapshot_policies', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE snapshot_policies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                policy_name VARCHAR(100) NOT NULL COMMENT '策略名称',
                host_id INT DEFAULT 0 COMMENT '主机ID(0表示全局策略)',
                user_id INT DEFAULT 0 COMMENT '用户ID',
                schedule_type ENUM('hourly','daily','weekly','monthly','manual') DEFAULT 'daily' COMMENT '调度类型',
                schedule_hour INT DEFAULT 2 COMMENT '执行时间(小时)',
                schedule_minute INT DEFAULT 0 COMMENT '执行时间(分钟)',
                schedule_day INT DEFAULT 0 COMMENT '执行日期(周/月)',
                retention_count INT DEFAULT 7 COMMENT '保留数量',
                retention_days INT DEFAULT 30 COMMENT '保留天数',
                include_memory TINYINT(1) DEFAULT 0 COMMENT '是否包含内存状态',
                snapshot_type ENUM('internal','disk-only') DEFAULT 'internal' COMMENT '快照类型',
                is_enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
                last_run_at TIMESTAMP NULL COMMENT '上次执行时间',
                next_run_at TIMESTAMP NULL COMMENT '下次执行时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_enabled (is_enabled),
                INDEX idx_next_run (next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 备份记录表 ======
    if (!in_array('vm_backups', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE vm_backups (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL COMMENT '主机ID',
                user_id INT NOT NULL COMMENT '用户ID',
                backup_name VARCHAR(100) NOT NULL COMMENT '备份名称',
                backup_type ENUM('full','incremental','differential') DEFAULT 'full' COMMENT '备份类型',
                backup_mode ENUM('manual','scheduled','policy') DEFAULT 'manual' COMMENT '备份模式',
                backup_path VARCHAR(500) NOT NULL COMMENT '备份文件路径',
                backup_size_gb DECIMAL(10,2) DEFAULT 0 COMMENT '备份大小GB',
                source_pool_id INT DEFAULT 0 COMMENT '源存储池ID',
                target_pool_id INT DEFAULT 0 COMMENT '目标存储池ID',
                target_node_id INT DEFAULT 0 COMMENT '目标节点ID(跨节点备份)',
                status ENUM('creating','available','restoring','deleting','error') DEFAULT 'creating' COMMENT '状态',
                is_encrypted TINYINT(1) DEFAULT 0 COMMENT '是否加密',
                encryption_key VARCHAR(100) DEFAULT '' COMMENT '加密密钥(哈希)',
                compression ENUM('none','gzip','lz4','zstd') DEFAULT 'none' COMMENT '压缩方式',
                restore_count INT DEFAULT 0 COMMENT '恢复次数',
                error_msg VARCHAR(500) DEFAULT '' COMMENT '错误信息',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_type (backup_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 用户充值记录表 ======
    if (!in_array('user_recharges', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE user_recharges (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                order_no VARCHAR(50) NOT NULL UNIQUE COMMENT '订单号',
                amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '充值金额',
                payment_method VARCHAR(20) DEFAULT '' COMMENT '支付方式(alipay/wxpay/qqpay)',
                payment_no VARCHAR(100) DEFAULT '' COMMENT '第三方交易号',
                payment_type VARCHAR(20) DEFAULT '' COMMENT '支付类型(epay)',
                status ENUM('pending','paid','failed','refunded') DEFAULT 'pending' COMMENT '订单状态',
                paid_at DATETIME DEFAULT NULL COMMENT '支付时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_order (order_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 余额变动日志表 ======
    if (!in_array('balance_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE balance_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                type ENUM('recharge','deduct','order','refund','bonus') DEFAULT 'recharge' COMMENT '变动类型',
                amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '变动金额',
                balance_before DECIMAL(12,2) DEFAULT 0.00 COMMENT '变动前余额',
                balance_after DECIMAL(12,2) DEFAULT 0.00 COMMENT '变动后余额',
                reason VARCHAR(500) DEFAULT '' COMMENT '变动原因',
                order_no VARCHAR(50) DEFAULT '' COMMENT '关联订单号',
                operator_id INT DEFAULT 0 COMMENT '操作人ID(管理员)',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_type (type),
                INDEX idx_time (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 代理等级表 ======
    if (!in_array('agent_levels', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE agent_levels (
                id INT PRIMARY KEY AUTO_INCREMENT,
                level_name VARCHAR(50) NOT NULL COMMENT '等级名称',
                level_key VARCHAR(30) NOT NULL UNIQUE COMMENT '等级标识(super/top/normal)',
                level INT DEFAULT 0 COMMENT '等级数字(越大越高)',
                discount_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '拿货折扣率(%)',
                min_commission_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '最低佣金比例(%)',
                max_commission_rate DECIMAL(5,2) DEFAULT 30.00 COMMENT '最高佣金比例(%)',
                can_create_agent TINYINT(1) DEFAULT 0 COMMENT '是否可创建下级代理',
                can_set_price TINYINT(1) DEFAULT 0 COMMENT '是否可设置下级售价',
                can_view_sub_data TINYINT(1) DEFAULT 0 COMMENT '是否可查看下级数据',
                description VARCHAR(500) DEFAULT '' COMMENT '等级描述',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_level (level),
                INDEX idx_key (level_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 插入默认等级
            $pdo->exec("INSERT IGNORE INTO agent_levels (level_name, level_key, level, discount_rate, min_commission_rate, max_commission_rate, can_create_agent, can_set_price, can_view_sub_data) VALUES
                ('超级总代', 'super', 100, 60.00, 0.00, 50.00, 1, 1, 1),
                ('高级代理', 'top', 50, 75.00, 5.00, 30.00, 1, 1, 1),
                ('普通代理', 'normal', 10, 90.00, 0.00, 15.00, 0, 0, 0)");
        } catch (Exception $e) {}
    }

    // ====== agent_levels 表新增字段（兼容老库）======
    if (in_array('agent_levels', $existing_tables)) {
    try {
        $level_status_col = $pdo->query("SHOW COLUMNS FROM agent_levels LIKE 'status'")->fetchAll();
        if (empty($level_status_col)) {
            $pdo->exec("ALTER TABLE agent_levels
                ADD COLUMN status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态' AFTER description,
                ADD COLUMN sort_order INT DEFAULT 0 COMMENT '排序权重(数字越大越靠前)' AFTER status,
                ADD INDEX idx_status (status)");
            // 已有等级默认都是 active，sort_order 用 level 字段填充
            $pdo->exec("UPDATE agent_levels SET sort_order = level WHERE sort_order = 0");
        }
    } catch (Exception $e) {}
    }

    // ====== 代理账号表 ======
    if (!in_array('agents', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE agents (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '关联用户ID',
                agent_no VARCHAR(50) NOT NULL UNIQUE COMMENT '代理编号',
                level_id INT DEFAULT 3 COMMENT '代理等级ID',
                parent_id INT DEFAULT 0 COMMENT '上级代理ID',
                parent_path VARCHAR(500) DEFAULT '' COMMENT '代理路径(如:1,5,12)',
                invite_code VARCHAR(20) NOT NULL UNIQUE COMMENT '专属邀请码',
                discount_rate DECIMAL(5,2) DEFAULT 90.00 COMMENT '个人拿货折扣(%)',
                commission_rate DECIMAL(5,2) DEFAULT 10.00 COMMENT '佣金比例(%)',
                total_sales DECIMAL(12,2) DEFAULT 0.00 COMMENT '累计销售额',
                total_commission DECIMAL(12,2) DEFAULT 0.00 COMMENT '累计佣金',
                available_commission DECIMAL(12,2) DEFAULT 0.00 COMMENT '可用佣金',
                frozen_commission DECIMAL(12,2) DEFAULT 0.00 COMMENT '冻结佣金',
                total_customers INT DEFAULT 0 COMMENT '累计客户数',
                total_orders INT DEFAULT 0 COMMENT '累计订单数',
                status ENUM('active','frozen','cancelled') DEFAULT 'active' COMMENT '状态',
                approved_at TIMESTAMP NULL COMMENT '审核通过时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_level (level_id),
                INDEX idx_parent (parent_id),
                INDEX idx_invite (invite_code),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 代理客户关系表 ======
    if (!in_array('agent_customers', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE agent_customers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                agent_id INT NOT NULL COMMENT '代理ID',
                user_id INT NOT NULL COMMENT '客户用户ID',
                first_order_time TIMESTAMP NULL COMMENT '首次下单时间',
                last_order_time TIMESTAMP NULL COMMENT '最近下单时间',
                total_orders INT DEFAULT 0 COMMENT '总订单数',
                total_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '累计消费金额',
                status ENUM('active','lost') DEFAULT 'active' COMMENT '客户状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY uk_agent_user (agent_id, user_id),
                INDEX idx_agent (agent_id),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 佣金记录表 ======
    if (!in_array('commission_records', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE commission_records (
                id INT PRIMARY KEY AUTO_INCREMENT,
                agent_id INT NOT NULL COMMENT '代理ID',
                order_id INT DEFAULT 0 COMMENT '关联订单ID',
                order_no VARCHAR(50) DEFAULT '' COMMENT '订单编号',
                customer_id INT DEFAULT 0 COMMENT '客户ID',
                order_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '订单金额',
                commission_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '佣金比例(%)',
                commission_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '佣金金额',
                commission_type ENUM('sale','renew','rebate','bonus') DEFAULT 'sale' COMMENT '佣金类型',
                status ENUM('pending','available','withdrawn','frozen','cancelled') DEFAULT 'pending' COMMENT '状态',
                available_at TIMESTAMP NULL COMMENT '可提现时间',
                withdrawn_at TIMESTAMP NULL COMMENT '提现时间',
                description VARCHAR(255) DEFAULT '' COMMENT '描述',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                INDEX idx_agent (agent_id),
                INDEX idx_order (order_id),
                INDEX idx_status (status),
                INDEX idx_type (commission_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 提现记录表 ======
    if (!in_array('withdraw_records', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE withdraw_records (
                id INT PRIMARY KEY AUTO_INCREMENT,
                agent_id INT NOT NULL COMMENT '代理ID',
                amount DECIMAL(12,2) NOT NULL COMMENT '提现金额',
                bank_name VARCHAR(100) DEFAULT '' COMMENT '银行名称',
                bank_account VARCHAR(50) DEFAULT '' COMMENT '银行账号',
                bank_holder VARCHAR(50) DEFAULT '' COMMENT '持卡人姓名',
                alipay_account VARCHAR(100) DEFAULT '' COMMENT '支付宝账号',
                wechat_account VARCHAR(100) DEFAULT '' COMMENT '微信账号',
                status ENUM('pending','processing','success','failed','cancelled') DEFAULT 'pending' COMMENT '状态',
                admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
                processed_at TIMESTAMP NULL COMMENT '处理时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                INDEX idx_agent (agent_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== KVM阶梯定价表 ======
    if (!in_array('kvm_pricing_tiers', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE kvm_pricing_tiers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                package_id INT DEFAULT 0 COMMENT '套餐ID(0表示自定义配置)',
                level_id INT DEFAULT 0 COMMENT '代理等级ID(0表示平台标准价)',
                cpu_cores INT DEFAULT 1 COMMENT 'CPU核心数',
                memory_mb INT DEFAULT 1024 COMMENT '内存MB',
                disk_gb INT DEFAULT 40 COMMENT '磁盘GB',
                bandwidth_mbps INT DEFAULT 10 COMMENT '带宽Mbps',
                platform_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '平台标准价(元/月)',
                base_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '代理拿货底价(元/月)',
                min_sell_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '最低售价限制(元/月)',
                suggested_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '建议售价(元/月)',
                status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_package (package_id),
                INDEX idx_level (level_id),
                INDEX idx_config (cpu_cores, memory_mb, disk_gb)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 代理申请表 ======
    if (!in_array('agent_applications', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE agent_applications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '申请用户ID',
                real_name VARCHAR(50) DEFAULT '' COMMENT '真实姓名',
                phone VARCHAR(20) DEFAULT '' COMMENT '联系电话',
                wechat VARCHAR(50) DEFAULT '' COMMENT '微信号',
                company VARCHAR(100) DEFAULT '' COMMENT '公司名称',
                experience VARCHAR(500) DEFAULT '' COMMENT '代理经验/渠道描述',
                expected_level_id INT DEFAULT 0 COMMENT '期望代理等级ID',
                expected_discount_rate DECIMAL(5,2) DEFAULT 0 COMMENT '期望拿货折扣(%)',
                expected_commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT '期望佣金比例(%)',
                status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending' COMMENT '状态',
                admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
                approved_level_id INT DEFAULT 0 COMMENT '审核通过的等级ID',
                approved_discount_rate DECIMAL(5,2) DEFAULT 0 COMMENT '审核通过的折扣',
                approved_commission_rate DECIMAL(5,2) DEFAULT 0 COMMENT '审核通过的佣金比例',
                reviewed_by INT DEFAULT 0 COMMENT '审核人ID',
                reviewed_at TIMESTAMP NULL COMMENT '审核时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 代理等级定价表（每个套餐对各代理等级的拿货价）======
    if (!in_array('agent_pricing', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE agent_pricing (
                id INT PRIMARY KEY AUTO_INCREMENT,
                package_id INT NOT NULL COMMENT '套餐ID',
                level_id INT NOT NULL COMMENT '代理等级ID',
                agent_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '代理拿货价(元/月)',
                min_sell_price DECIMAL(10,2) DEFAULT 0.00 COMMENT '最低售价限制(元/月)',
                status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_pkg_level (package_id, level_id),
                INDEX idx_package (package_id),
                INDEX idx_level (level_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 优惠券表 ======
    if (!in_array('coupons', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE coupons (
                id INT PRIMARY KEY AUTO_INCREMENT,
                coupon_code VARCHAR(50) NOT NULL UNIQUE COMMENT '优惠券码',
                coupon_name VARCHAR(100) NOT NULL COMMENT '优惠券名称',
                coupon_type ENUM('discount','cash','rebate') DEFAULT 'discount' COMMENT '类型(折扣/立减/返现)',
                discount_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '折扣率(%)',
                discount_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '立减金额',
                min_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '最低消费金额',
                max_discount DECIMAL(10,2) DEFAULT 0.00 COMMENT '最大优惠金额',
                total_count INT DEFAULT 0 COMMENT '发行总量',
                used_count INT DEFAULT 0 COMMENT '已使用数量',
                per_user_limit INT DEFAULT 1 COMMENT '每人限领',
                level_limit VARCHAR(100) DEFAULT '' COMMENT '代理等级限制(逗号分隔)',
                product_limit VARCHAR(100) DEFAULT 'kvm' COMMENT '产品类型限制',
                valid_from TIMESTAMP NULL COMMENT '有效期开始',
                valid_to TIMESTAMP NULL COMMENT '有效期结束',
                status ENUM('active','inactive','expired') DEFAULT 'active' COMMENT '状态',
                created_by INT DEFAULT 0 COMMENT '创建人ID',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_code (coupon_code),
                INDEX idx_status (status),
                INDEX idx_valid (valid_from, valid_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 用户优惠券表 ======
    if (!in_array('user_coupons', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE user_coupons (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                coupon_id INT NOT NULL COMMENT '优惠券ID',
                order_id INT DEFAULT 0 COMMENT '使用订单ID',
                status ENUM('unused','used','expired') DEFAULT 'unused' COMMENT '状态',
                used_at TIMESTAMP NULL COMMENT '使用时间',
                expired_at TIMESTAMP NULL COMMENT '过期时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== orders 表新增优惠券字段（兼容老库）======
    if (in_array('orders', $existing_tables)) {
    try {
        $order_coupon_col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'coupon_id'")->fetchAll();
        if (empty($order_coupon_col)) {
            $pdo->exec("ALTER TABLE orders
                ADD COLUMN coupon_id INT DEFAULT 0 COMMENT '使用的优惠券ID' AFTER amount,
                ADD COLUMN coupon_code VARCHAR(50) DEFAULT '' COMMENT '优惠券码快照' AFTER coupon_id,
                ADD COLUMN original_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '优惠前原价' AFTER coupon_code,
                ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT '优惠金额' AFTER original_amount,
                ADD INDEX idx_coupon (coupon_id)");
        }
    } catch (Exception $e) {}
    }

    // ====== 推广链接表 ======
    if (!in_array('promo_links', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE promo_links (
                id INT PRIMARY KEY AUTO_INCREMENT,
                agent_id INT NOT NULL COMMENT '代理ID',
                link_code VARCHAR(20) NOT NULL UNIQUE COMMENT '链接码',
                link_name VARCHAR(100) DEFAULT '' COMMENT '链接名称',
                target_url VARCHAR(500) DEFAULT '' COMMENT '目标URL',
                click_count INT DEFAULT 0 COMMENT '点击次数',
                register_count INT DEFAULT 0 COMMENT '注册次数',
                order_count INT DEFAULT 0 COMMENT '下单次数',
                order_amount DECIMAL(12,2) DEFAULT 0.00 COMMENT '下单金额',
                status ENUM('active','inactive') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                INDEX idx_agent (agent_id),
                INDEX idx_code (link_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 套餐分类表 ======
    if (!in_array('package_categories', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE package_categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL COMMENT '分类名称',
                description VARCHAR(500) DEFAULT '' COMMENT '分类描述',
                sort_order INT DEFAULT 0 COMMENT '排序',
                status ENUM('active', 'disabled') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sort (sort_order),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 地区表 ======
    if (!in_array('regions', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE regions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL COMMENT '地区名称(中文)',
                code VARCHAR(50) NOT NULL COMMENT '地区代码(如AP-Shanghai)',
                description VARCHAR(500) DEFAULT '' COMMENT '地区描述',
                sort_order INT DEFAULT 0 COMMENT '排序(越小越靠前)',
                is_default TINYINT(1) DEFAULT 0 COMMENT '是否默认地区',
                status ENUM('active', 'disabled') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sort (sort_order),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 插入默认地区数据
            $pdo->exec("INSERT IGNORE INTO regions (name, code, description, sort_order, is_default, status) VALUES
                ('上海', 'AP-Shanghai', '上海数据中心', 0, 1, 'active'),
                ('北京', 'AP-Beijing', '北京数据中心', 1, 0, 'active'),
                ('广州', 'AP-Guangzhou', '广州数据中心', 2, 0, 'active')");
        } catch (Exception $e) {}
    }

    // ====== 知识库表 ======
    if (!in_array('knowledge_base', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE knowledge_base (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category VARCHAR(50) NOT NULL DEFAULT '其他' COMMENT '分类',
                question VARCHAR(500) NOT NULL COMMENT '问题/关键词',
                answer TEXT NOT NULL COMMENT '回答内容',
                keywords VARCHAR(1000) DEFAULT '' COMMENT '匹配关键词(逗号分隔)',
                intent VARCHAR(100) DEFAULT '' COMMENT '意图标识',
                priority INT DEFAULT 50 COMMENT '优先级(越高越优先)',
                is_ai_answer TINYINT(1) DEFAULT 0 COMMENT '是否使用AI生成回答',
                hit_count INT DEFAULT 0 COMMENT '命中次数',
                status ENUM('active', 'disabled') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_category (category),
                INDEX idx_intent (intent),
                INDEX idx_status (status),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 插入默认知识库数据（从SmartQA迁移）
            $pdo->exec("INSERT IGNORE INTO knowledge_base (category, question, answer, keywords, intent, priority, status) VALUES
                ('购买', '如何购买云服务器', '购买云服务器步骤：1.进入套餐页选择配置 2.点击立即购买 3.选择计费周期和操作系统 4.确认支付。', '购买,买,开通,下单,订购,KVM,云服务器,云主机', 'buy_kvm', 95, 'active'),
                ('购买', '如何续费', '续费步骤：进入云服务器列表→找到实例→点击更多→续费→选择周期→支付。', '续费,到期,过期', 'renew', 92, 'active'),
                ('操作', '如何重装系统', '重装系统：进入云服务器列表→更多→重装系统→选择操作系统→设置密码→确认。注意会清空系统盘数据！', '重装,换系统,装系统,更换系统', 'reinstall', 92, 'active'),
                ('操作', '如何重置密码', '重置密码：进入云服务器列表→更多→重置密码→输入新密码→确认。Linux重置root密码，Windows重置Administrator密码。', '重置密码,忘记密码,改密码,root密码', 'reset_password', 90, 'active'),
                ('账户', '如何注册', '注册步骤：点击右上角注册→填写用户名、邮箱、密码→同意协议→立即注册→邮箱验证。新用户赠送10元体验金！', '注册,创建账户,新用户,开户', 'register', 85, 'active'),
                ('账户', '如何充值', '充值步骤：登录后点击用户名→财务中心→充值→输入金额→选择支付方式（微信/支付宝）→完成支付。', '充值,余额,钱包,打钱,充钱', 'recharge', 85, 'active'),
                ('故障', '服务器连不上', '排查步骤：1.检查服务器状态是否为运行中 2.检查安全组是否放行对应端口 3.使用VNC控制台查看系统 4.检查SSH服务是否运行。', '连不上,连不到,连接失败,无法连接,访问不了', 'cant_connect', 95, 'active'),
                ('故障', '服务器变慢', '排查步骤：1.查看CPU/内存/磁盘监控 2.登录服务器用top命令查看进程 3.检查磁盘空间是否满了 4.检查带宽是否跑满 5.检查是否被攻击。', '慢,卡,延迟高,卡顿,响应慢', 'slow', 90, 'active'),
                ('支持', '如何提交工单', '提交工单：进入工单系统→点击提交工单→选择类型→填写标题和内容→设置优先级→提交。紧急问题15分钟内响应。', '工单,技术支持,问题反馈', 'ticket', 80, 'active'),
                ('其他', '转人工客服', '即将为您转接人工客服，请稍候。系统会自动分配空闲客服，保留对话记录。', '转人工,人工客服,真人,转接人工,找人工', 'transfer_human', 90, 'active')");
        } catch (Exception $e) {}
    }

    // ====== 机器人上下文表（多轮对话） ======
    if (!in_array('chat_bot_context', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_bot_context (
                id INT PRIMARY KEY AUTO_INCREMENT,
                session_id INT NOT NULL COMMENT '关联会话ID',
                current_intent VARCHAR(100) DEFAULT '' COMMENT '当前意图',
                context_data TEXT COMMENT '上下文JSON数据',
                unanswered_count INT DEFAULT 0 COMMENT '连续未匹配次数',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_session (session_id),
                INDEX idx_intent (current_intent)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 为packages表添加category_id字段 ======
    if (in_array('packages', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM packages LIKE 'category_id'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE packages ADD COLUMN category_id INT DEFAULT 0 COMMENT '分类ID' AFTER name, ADD INDEX idx_category (category_id)");
            }
        } catch (Exception $e) {}
    }

    // ====== 阶段三：客户档案与标签系统 ======
    if (!in_array('chat_user_profiles', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_user_profiles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                phone VARCHAR(20) DEFAULT '' COMMENT '电话',
                company VARCHAR(200) DEFAULT '' COMMENT '公司',
                position VARCHAR(100) DEFAULT '' COMMENT '职位',
                notes TEXT COMMENT '备注',
                first_visit_at TIMESTAMP NULL COMMENT '首次访问时间',
                last_visit_at TIMESTAMP NULL COMMENT '最后访问时间',
                total_sessions INT DEFAULT 0 COMMENT '总会话数',
                total_messages INT DEFAULT 0 COMMENT '总消息数',
                source_channel VARCHAR(50) DEFAULT '' COMMENT '来源渠道',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user (user_id),
                INDEX idx_last_visit (last_visit_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户档案'");
        } catch (Exception $e) {}
    }
    if (!in_array('chat_tags', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_tags (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(50) NOT NULL COMMENT '标签名称',
                color VARCHAR(20) DEFAULT '#1677ff' COMMENT '标签颜色',
                category VARCHAR(50) DEFAULT 'general' COMMENT '分类',
                sort_order INT DEFAULT 0 COMMENT '排序',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_name (name),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户标签'");
            // 默认标签
            $pdo->exec("INSERT IGNORE INTO chat_tags (name, color, category, sort_order) VALUES
                ('VIP客户', '#faad14', 'level', 1),
                ('新用户', '#52c41a', 'level', 2),
                ('高价值', '#f5222d', 'level', 3),
                ('技术问题', '#1677ff', 'type', 4),
                ('投诉', '#ff4d4f', 'type', 5),
                ('待跟进', '#8c8c8c', 'status', 6)");
        } catch (Exception $e) {}
    }
    if (!in_array('chat_user_tag_relations', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_user_tag_relations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL COMMENT '用户ID',
                tag_id INT NOT NULL COMMENT '标签ID',
                created_by INT DEFAULT 0 COMMENT '创建人ID(客服/管理员)',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user_tag (user_id, tag_id),
                INDEX idx_user (user_id),
                INDEX idx_tag (tag_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户标签关联'");
        } catch (Exception $e) {}
    }
    if (!in_array('chat_visitor_tracks', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_visitor_tracks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                session_id BIGINT NOT NULL COMMENT '会话ID',
                user_id INT DEFAULT NULL COMMENT '用户ID',
                page_url VARCHAR(500) DEFAULT '' COMMENT '页面URL',
                referrer VARCHAR(500) DEFAULT '' COMMENT '来源页',
                ip VARCHAR(45) DEFAULT '' COMMENT 'IP地址',
                duration_seconds INT DEFAULT 0 COMMENT '停留秒数',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访客轨迹'");
        } catch (Exception $e) {}
    }

    // ====== 阶段三：坐席技能分组与智能分配 ======
    if (!in_array('chat_staff_groups', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_staff_groups (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL COMMENT '技能组名称',
                description VARCHAR(500) DEFAULT '' COMMENT '描述',
                auto_assign TINYINT(1) DEFAULT 1 COMMENT '是否自动分配',
                sort_order INT DEFAULT 0 COMMENT '排序',
                status ENUM('active','disabled') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客服技能组'");
            // 默认技能组
            $pdo->exec("INSERT IGNORE INTO chat_staff_groups (name, description, sort_order, status) VALUES
                ('售前咨询', '负责产品咨询和购买引导', 0, 'active'),
                ('技术支持', '负责技术问题和故障排查', 1, 'active'),
                ('售后服务', '负责售后问题和投诉处理', 2, 'active')");
        } catch (Exception $e) {}
    }
    if (!in_array('chat_staff_group_members', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_staff_group_members (
                id INT PRIMARY KEY AUTO_INCREMENT,
                staff_id INT NOT NULL COMMENT '客服ID',
                group_id INT NOT NULL COMMENT '技能组ID',
                is_primary TINYINT(1) DEFAULT 0 COMMENT '是否主技能组',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_staff_group (staff_id, group_id),
                INDEX idx_staff (staff_id),
                INDEX idx_group (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客服技能组成员'");
        } catch (Exception $e) {}
    }
    // chat_staff 表添加 group_id 字段
    if (in_array('chat_staff', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM chat_staff")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('group_id', $cols)) {
                $pdo->exec("ALTER TABLE chat_staff ADD COLUMN group_id INT DEFAULT 0 COMMENT '所属技能组ID' AFTER status");
            }
            if (!in_array('skills', $cols)) {
                $pdo->exec("ALTER TABLE chat_staff ADD COLUMN skills VARCHAR(500) DEFAULT '' COMMENT '技能标签(逗号分隔)' AFTER group_id");
            }
        } catch (Exception $e) {}
    }

    // ====== 阶段三：话术库管理（企业级知识库） ======
    if (!in_array('chat_scripts', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_scripts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                group_id INT DEFAULT 0 COMMENT '技能组ID(0表示全局)',
                title VARCHAR(200) NOT NULL COMMENT '话术标题',
                content TEXT NOT NULL COMMENT '话术内容',
                category VARCHAR(50) DEFAULT 'general' COMMENT '分类',
                shortcut VARCHAR(50) DEFAULT '' COMMENT '快捷指令(如/kf)',
                sort_order INT DEFAULT 0 COMMENT '排序',
                status ENUM('active','disabled') DEFAULT 'active' COMMENT '状态',
                use_count INT DEFAULT 0 COMMENT '使用次数',
                created_by INT DEFAULT 0 COMMENT '创建人ID',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_group (group_id),
                INDEX idx_category (category),
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业话术库'");
            // 默认话术
            $pdo->exec("INSERT IGNORE INTO chat_scripts (title, content, category, shortcut, sort_order, status) VALUES
                ('欢迎语', '您好，欢迎咨询guojici云服务！我是您的专属客服，请问有什么可以帮您？', 'greeting', '/hy', 1, 'active'),
                ('结束语', '感谢您的咨询！如有其他问题欢迎随时联系，祝您使用愉快！', 'greeting', '/zj', 2, 'active'),
                ('等待回复', '请您稍等，我为您查询一下。', 'common', '/dd', 3, 'active'),
                ('转技术', '您的问题涉及技术层面，我为您转接技术支持同事处理。', 'transfer', '/js', 4, 'active'),
                ('转售后', '您的问题需要售后部门处理，我为您转接售后同事。', 'transfer', '/sh', 5, 'active')");
        } catch (Exception $e) {}
    }

    // ====== 阶段四：智能质检规则引擎 ======
    if (!in_array('chat_quality_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_quality_rules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(200) NOT NULL COMMENT '规则名称',
                rule_type ENUM('sensitive_word','response_time','msg_count','greeting_check','closing_check','emotion_score') DEFAULT 'sensitive_word' COMMENT '规则类型',
                config TEXT COMMENT '规则配置JSON',
                score_deduction INT DEFAULT 5 COMMENT '扣分值',
                is_auto TINYINT(1) DEFAULT 1 COMMENT '是否自动质检',
                status ENUM('active','disabled') DEFAULT 'active' COMMENT '状态',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (rule_type),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能质检规则'");
            // 默认规则
            $pdo->exec("INSERT IGNORE INTO chat_quality_rules (name, rule_type, config, score_deduction, is_auto, status) VALUES
                ('敏感词检测', 'sensitive_word', '{\"words\":\"傻逼,垃圾,滚,操\"}', 10, 1, 'active'),
                ('首次响应超时', 'response_time', '{\"first_response_sec\":60}', 5, 1, 'active'),
                ('平均响应超时', 'response_time', '{\"avg_response_sec\":120}', 5, 1, 'active'),
                ('未发送欢迎语', 'greeting_check', '{}', 3, 1, 'active'),
                ('未发送结束语', 'closing_check', '{}', 3, 1, 'active')");
        } catch (Exception $e) {}
    }
    if (!in_array('chat_quality_checks', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_quality_checks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                session_id BIGINT NOT NULL COMMENT '会话ID',
                staff_id INT NOT NULL COMMENT '客服ID',
                rule_id INT NOT NULL COMMENT '规则ID',
                score INT DEFAULT 100 COMMENT '得分',
                deductions INT DEFAULT 0 COMMENT '扣分',
                details TEXT COMMENT '质检详情JSON',
                checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_staff (staff_id),
                INDEX idx_checked (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='质检记录'");
        } catch (Exception $e) {}
    }

    // ====== 阶段四：数据统计表 ======
    if (!in_array('chat_daily_stats', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE chat_daily_stats (
                id INT PRIMARY KEY AUTO_INCREMENT,
                stat_date DATE NOT NULL COMMENT '统计日期',
                staff_id INT DEFAULT 0 COMMENT '客服ID(0表示全局)',
                total_sessions INT DEFAULT 0 COMMENT '总会话数',
                active_sessions INT DEFAULT 0 COMMENT '有效会话数',
                total_messages INT DEFAULT 0 COMMENT '总消息数',
                staff_messages INT DEFAULT 0 COMMENT '客服消息数',
                user_messages INT DEFAULT 0 COMMENT '用户消息数',
                avg_response_time INT DEFAULT 0 COMMENT '平均响应时间秒',
                avg_session_duration INT DEFAULT 0 COMMENT '平均会话时长秒',
                satisfaction_avg DECIMAL(3,2) DEFAULT 0.00 COMMENT '平均满意度',
                transfer_count INT DEFAULT 0 COMMENT '转接次数',
                ticket_count INT DEFAULT 0 COMMENT '创建工单数',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_date_staff (stat_date, staff_id),
                INDEX idx_date (stat_date),
                INDEX idx_staff (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客服每日统计'");
        } catch (Exception $e) {}
    }

    // ====== 性能优化：自动添加缺失的索引 ======
    _db_add_missing_indexes($pdo, $existing_tables);

    return true;
}

function _db_add_missing_indexes($pdo, $existing_tables) {
    $indexes = [
        'users' => [
            'idx_username' => 'username',
            'idx_created' => 'created_at',
        ],
        'orders' => [
            'idx_user_status' => 'user_id, status',
            'idx_paid' => 'paid_at',
        ],
        'hosts' => [
            'idx_user_order' => 'user_id, order_id',
            'idx_package' => 'package_id',
            'idx_created' => 'created_at',
            'idx_ip_address' => 'ip_address',
        ],
        'host_traffic' => [
            'idx_host_collected' => 'host_id, collected_at',
        ],
        'api_keys' => [
            'idx_user_id' => 'user_id',
            'idx_status' => 'status',
        ],
        'api_request_logs' => [
            'idx_api_key' => 'api_key_id',
            'idx_user_id' => 'user_id',
            'idx_created' => 'created_at',
            'idx_ip' => 'ip',
        ],
        'tickets' => [
            'idx_user_status' => 'user_id, status',
        ],
        'billing_records' => [
            'idx_user_id' => 'user_id',
            'idx_host_id' => 'host_id',
            'idx_type' => 'bill_type',
            'idx_created' => 'created_at',
        ],
        'notifications' => [
            'idx_user_read' => 'user_id, is_read',
            'idx_created' => 'created_at',
        ],
        'security_login_attempts' => [
            'idx_ip' => 'ip_address',
            'idx_username' => 'username',
            'idx_created' => 'created_at',
        ],
        'security_nonces' => [
            'idx_nonce' => 'nonce',
            'idx_created' => 'created_at',
        ],
        'ad_stats' => [
            'idx_ad_id' => 'ad_id',
            'idx_stat_date' => 'stat_date',
            'idx_user_id' => 'user_id',
        ],
        'ad_device_fingerprints' => [
            'idx_fingerprint' => 'device_fingerprint',
            'idx_ip' => 'ip_address',
            'idx_last_seen' => 'last_seen_at',
        ],
        'ad_click_logs' => [
            'idx_ad_id' => 'ad_id',
            'idx_click_time' => 'click_time',
            'idx_ip' => 'ip_address',
        ],
        'ad_impression_logs' => [
            'idx_ad_id' => 'ad_id',
            'idx_imp_time' => 'impression_time',
            'idx_ip' => 'ip_address',
        ],
        'ad_fraud_violations' => [
            'idx_user_id' => 'user_id',
            'idx_created' => 'created_at',
        ],
    ];

    foreach ($indexes as $table => $table_indexes) {
        if (!in_array($table, $existing_tables)) continue;

        try {
            $existing_indexes = [];
            $stmt = $pdo->query("SHOW INDEX FROM `$table`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_indexes[] = $row['Key_name'];
            }
        } catch (Exception $e) {
            continue;
        }

        foreach ($table_indexes as $idx_name => $idx_cols) {
            if (in_array($idx_name, $existing_indexes)) continue;
            try {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idx_name` ($idx_cols)");
            } catch (Exception $e) {}
        }
    }

    // ====== 退款申请表 ======
    if (!in_array('refund_requests', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS refund_requests (
                id INT PRIMARY KEY AUTO_INCREMENT,
                order_id INT NOT NULL COMMENT '订单ID',
                user_id INT NOT NULL COMMENT '用户ID',
                host_id INT DEFAULT NULL COMMENT '关联主机ID',
                amount DECIMAL(10,2) NOT NULL COMMENT '退款金额',
                reason VARCHAR(500) NOT NULL COMMENT '退款原因',
                status ENUM('pending','approved','rejected','completed') DEFAULT 'pending' COMMENT '状态',
                admin_id INT DEFAULT NULL COMMENT '处理管理员ID',
                admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
                processed_at TIMESTAMP NULL COMMENT '处理时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_order_id (order_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 主机转移表 ======
    if (!in_array('host_transfers', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS host_transfers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                host_id INT NOT NULL COMMENT '主机ID',
                from_user_id INT NOT NULL COMMENT '原用户ID',
                to_user_id INT NOT NULL COMMENT '目标用户ID',
                status ENUM('pending','approved','rejected','completed') DEFAULT 'pending' COMMENT '状态',
                admin_id INT DEFAULT NULL COMMENT '处理管理员ID',
                admin_remark VARCHAR(500) DEFAULT '' COMMENT '管理员备注',
                processed_at TIMESTAMP NULL COMMENT '处理时间',
                completed_at TIMESTAMP NULL COMMENT '完成时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE,
                FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_host_id (host_id),
                INDEX idx_from_user (from_user_id),
                INDEX idx_to_user (to_user_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 管理员角色表 ======
    if (!in_array('admin_roles', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_roles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                role_name VARCHAR(50) NOT NULL UNIQUE COMMENT '角色名称',
                role_key VARCHAR(50) NOT NULL UNIQUE COMMENT '角色标识',
                description VARCHAR(255) DEFAULT '' COMMENT '角色描述',
                is_system TINYINT(1) DEFAULT 0 COMMENT '是否系统内置(不可删除)',
                sort_order INT DEFAULT 0 COMMENT '排序',
                status ENUM('active','disabled') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 初始化默认角色
            $default_roles = [
                ['超级管理员', 'super_admin', '拥有系统全部权限，不可删除', 1, 1],
                ['运维管理员', 'ops_admin', '负责虚拟机、节点、网络运维管理', 1, 2],
                ['财务管理员', 'finance_admin', '负责订单、账单、财务统计管理', 1, 3],
                ['客服管理员', 'support_admin', '负责工单、用户咨询、退款审核处理', 1, 4],
                ['只读管理员', 'readonly_admin', '仅可查看数据，不可操作修改', 1, 5],
            ];
            foreach ($default_roles as $r) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO admin_roles (role_name, role_key, description, is_system, sort_order) VALUES (?,?,?,?,?)")
                        ->execute($r);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }

    // ====== 角色权限关联表 ======
    if (!in_array('admin_role_permissions', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_role_permissions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                role_id INT NOT NULL COMMENT '角色ID',
                permission_key VARCHAR(100) NOT NULL COMMENT '权限标识',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_perm (role_id, permission_key),
                FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
                INDEX idx_role_id (role_id),
                INDEX idx_permission (permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 为超级管理员默认添加全部权限（标记为通配符）
            try {
                $super_role = $pdo->query("SELECT id FROM admin_roles WHERE role_key = 'super_admin' LIMIT 1")->fetch();
                if ($super_role) {
                    $pdo->prepare("INSERT IGNORE INTO admin_role_permissions (role_id, permission_key) VALUES (?, '*')")
                        ->execute([$super_role['id']]);
                }
            } catch (Exception $e) {}
        } catch (Exception $e) {}
    }

    // ====== 管理员用户表增强（添加role_id）======
    try {
        $col_check = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'role_id'")->fetch();
        if (!$col_check) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN role_id INT DEFAULT NULL COMMENT '角色ID' AFTER role");
            // 将现有super_admin管理员关联到超级管理员角色
            $super_role = $pdo->query("SELECT id FROM admin_roles WHERE role_key = 'super_admin' LIMIT 1")->fetch();
            if ($super_role) {
                $pdo->exec("UPDATE admin_users SET role_id = {$super_role['id']} WHERE role = 'super_admin' AND role_id IS NULL");
            }
        }
    } catch (Exception $e) {}

    // ====== 用户配额表 ======
    if (!in_array('user_quotas', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_quotas (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL UNIQUE COMMENT '用户ID',
                max_vms INT DEFAULT -1 COMMENT '最大虚拟机数量(-1为不限)',
                max_cpu INT DEFAULT -1 COMMENT '最大CPU核心数(-1为不限)',
                max_memory_mb INT DEFAULT -1 COMMENT '最大内存MB(-1为不限)',
                max_disk_gb INT DEFAULT -1 COMMENT '最大磁盘GB(-1为不限)',
                max_bandwidth_mbps INT DEFAULT -1 COMMENT '最大带宽Mbps(-1为不限)',
                max_ip_count INT DEFAULT -1 COMMENT '最大公网IP数(-1为不限)',
                max_snapshots INT DEFAULT 10 COMMENT '最大快照数(-1为不限)',
                used_vms INT DEFAULT 0 COMMENT '已用虚拟机数',
                used_cpu INT DEFAULT 0 COMMENT '已用CPU核数',
                used_memory_mb INT DEFAULT 0 COMMENT '已用内存MB',
                used_disk_gb INT DEFAULT 0 COMMENT '已用磁盘GB',
                used_ip_count INT DEFAULT 0 COMMENT '已用IP数',
                used_snapshots INT DEFAULT 0 COMMENT '已用快照数',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 用户登录日志表 ======
    if (!in_array('user_login_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_login_logs (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                user_id INT DEFAULT NULL COMMENT '用户ID',
                username VARCHAR(50) DEFAULT '' COMMENT '登录用户名',
                ip VARCHAR(45) DEFAULT '' COMMENT '登录IP',
                ip_location VARCHAR(100) DEFAULT '' COMMENT 'IP归属地',
                user_agent VARCHAR(500) DEFAULT '' COMMENT '浏览器UA',
                device_type VARCHAR(20) DEFAULT '' COMMENT '设备类型',
                status ENUM('success','failed') DEFAULT 'success' COMMENT '登录状态',
                fail_reason VARCHAR(100) DEFAULT '' COMMENT '失败原因',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_ip (ip),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 管理员登录日志表 ======
    if (!in_array('admin_login_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_login_logs (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                admin_id INT DEFAULT NULL COMMENT '管理员ID',
                username VARCHAR(50) DEFAULT '' COMMENT '登录用户名',
                ip VARCHAR(45) DEFAULT '' COMMENT '登录IP',
                ip_location VARCHAR(100) DEFAULT '' COMMENT 'IP归属地',
                user_agent VARCHAR(500) DEFAULT '' COMMENT '浏览器UA',
                status ENUM('success','failed') DEFAULT 'success' COMMENT '登录状态',
                fail_reason VARCHAR(100) DEFAULT '' COMMENT '失败原因',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_id (admin_id),
                INDEX idx_ip (ip),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 告警规则表 ======
    if (!in_array('alert_rules', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS alert_rules (
                id INT PRIMARY KEY AUTO_INCREMENT,
                rule_name VARCHAR(100) NOT NULL COMMENT '规则名称',
                rule_type ENUM('vm_cpu','vm_memory','vm_disk','vm_network','node_cpu','node_memory','node_disk','vm_offline','node_offline','traffic_exceed') NOT NULL COMMENT '告警类型',
                threshold DECIMAL(10,2) DEFAULT NULL COMMENT '阈值',
                threshold_unit VARCHAR(20) DEFAULT '' COMMENT '阈值单位',
                duration INT DEFAULT 5 COMMENT '持续时间(分钟)',
                notify_channels VARCHAR(255) DEFAULT 'admin' COMMENT '通知渠道(admin,email,sms)',
                notify_emails VARCHAR(500) DEFAULT '' COMMENT '通知邮箱(逗号分隔)',
                notify_phones VARCHAR(500) DEFAULT '' COMMENT '通知手机(逗号分隔)',
                enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (rule_type),
                INDEX idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 告警记录表 ======
    if (!in_array('alert_logs', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS alert_logs (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                rule_id INT DEFAULT NULL COMMENT '关联规则ID',
                alert_type VARCHAR(50) NOT NULL COMMENT '告警类型',
                target_type VARCHAR(20) DEFAULT '' COMMENT '目标类型(vm/node/user)',
                target_id INT DEFAULT NULL COMMENT '目标ID',
                target_name VARCHAR(100) DEFAULT '' COMMENT '目标名称',
                severity ENUM('info','warning','critical') DEFAULT 'warning' COMMENT '严重程度',
                message TEXT COMMENT '告警消息',
                metric_value DECIMAL(10,2) DEFAULT NULL COMMENT '触发时指标值',
                threshold_value DECIMAL(10,2) DEFAULT NULL COMMENT '阈值',
                status ENUM('active','resolved') DEFAULT 'active' COMMENT '状态',
                resolved_at TIMESTAMP NULL COMMENT '恢复时间',
                notified TINYINT(1) DEFAULT 0 COMMENT '是否已通知',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rule_id (rule_id),
                INDEX idx_type (alert_type),
                INDEX idx_target (target_type, target_id),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }

    // ====== 在线客服 - 客服账号表 ======
    if (!in_array('chat_staff', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_staff (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL UNIQUE COMMENT '客服账号',
                password VARCHAR(255) NOT NULL COMMENT '密码(bcrypt)',
                nickname VARCHAR(50) NOT NULL DEFAULT '' COMMENT '昵称/显示名',
                avatar VARCHAR(255) DEFAULT '' COMMENT '头像URL',
                status ENUM('online','offline','busy') DEFAULT 'offline' COMMENT '在线状态',
                max_sessions INT DEFAULT 10 COMMENT '最大同时接待会话数',
                current_sessions INT DEFAULT 0 COMMENT '当前接待会话数',
                total_served INT DEFAULT 0 COMMENT '累计服务会话数',
                last_active_at TIMESTAMP NULL COMMENT '最后活跃时间',
                is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线客服账号表'");
        } catch (Exception $e) {}
    }

    // ====== 在线客服 - 会话表 ======
    if (!in_array('chat_sessions', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                session_key VARCHAR(64) NOT NULL UNIQUE COMMENT '会话唯一标识',
                user_id INT DEFAULT NULL COMMENT '关联用户ID(登录用户)',
                visitor_name VARCHAR(50) DEFAULT '' COMMENT '访客昵称',
                visitor_ip VARCHAR(45) DEFAULT '' COMMENT '访客IP',
                visitor_ua VARCHAR(500) DEFAULT '' COMMENT '访客UA',
                staff_id INT DEFAULT NULL COMMENT '接待客服ID',
                status ENUM('waiting','active','closed') DEFAULT 'waiting' COMMENT '会话状态',
                priority TINYINT DEFAULT 0 COMMENT '优先级(0普通 1高)',
                source VARCHAR(50) DEFAULT 'web' COMMENT '来源渠道',
                first_message TEXT COMMENT '首条消息/问题摘要',
                rating TINYINT DEFAULT NULL COMMENT '评分(1-5星)',
                rating_comment VARCHAR(500) DEFAULT '' COMMENT '评价内容',
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '开始时间',
                accepted_at TIMESTAMP NULL COMMENT '客服接入时间',
                closed_at TIMESTAMP NULL COMMENT '结束时间',
                close_reason VARCHAR(100) DEFAULT '' COMMENT '关闭原因',
                unread_user INT DEFAULT 0 COMMENT '用户未读消息数',
                unread_staff INT DEFAULT 0 COMMENT '客服未读消息数',
                INDEX idx_staff_id (staff_id),
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_started (started_at),
                INDEX idx_session_key (session_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线客服会话表'");
        } catch (Exception $e) {}
    }
    // 会话表新增字段（后续版本补充）
    if (in_array('chat_sessions', $existing_tables)) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM chat_sessions")->fetchAll(PDO::FETCH_COLUMN);
            $cols = array_map('strtolower', $cols);
            if (!in_array('last_msg_time', $cols)) {
                try {
                    $pdo->exec("ALTER TABLE chat_sessions ADD COLUMN last_msg_time TIMESTAMP NULL COMMENT '最后消息时间' AFTER closed_at");
                    $pdo->exec("ALTER TABLE chat_sessions ADD INDEX idx_last_msg (last_msg_time)");
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }

    // ====== 在线客服 - 消息表 ======
    if (!in_array('chat_messages', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                session_id BIGINT NOT NULL COMMENT '会话ID',
                sender_type ENUM('user','staff','system') NOT NULL COMMENT '发送方类型',
                sender_id INT DEFAULT NULL COMMENT '发送方ID(用户或客服)',
                sender_name VARCHAR(50) DEFAULT '' COMMENT '发送方显示名',
                content TEXT NOT NULL COMMENT '消息内容',
                msg_type ENUM('text','image','file','system') DEFAULT 'text' COMMENT '消息类型',
                is_read TINYINT(1) DEFAULT 0 COMMENT '是否已读',
                read_at TIMESTAMP NULL COMMENT '阅读时间',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
                INDEX idx_session_id (session_id),
                INDEX idx_sender (sender_type, sender_id),
                INDEX idx_created (created_at),
                INDEX idx_unread (session_id, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线客服消息表'");
        } catch (Exception $e) {}
    }

    // ====== 在线客服 - 快捷回复表 ======
    if (!in_array('chat_quick_replies', $existing_tables)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS chat_quick_replies (
                id INT PRIMARY KEY AUTO_INCREMENT,
                staff_id INT DEFAULT NULL COMMENT '所属客服ID(全局为NULL)',
                title VARCHAR(100) NOT NULL COMMENT '标题/关键词',
                content TEXT NOT NULL COMMENT '回复内容',
                sort_order INT DEFAULT 0 COMMENT '排序',
                is_global TINYINT(1) DEFAULT 1 COMMENT '是否全局公共',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_staff_id (staff_id),
                INDEX idx_global (is_global)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线客服快捷回复表'");

            $default_replies = [
                [null, '问候语', '您好！欢迎咨询 guojici云 在线客服，请问有什么可以帮您？', 1, 1],
                [null, '稍后回复', '非常抱歉，当前咨询量较大，请您稍等片刻，我会尽快回复您。', 2, 1],
                [null, '感谢咨询', '感谢您的咨询，如果后续还有问题，欢迎随时联系我们，祝您生活愉快！', 3, 1],
                [null, '工单指引', '关于您的问题，建议您提交工单详细说明，我们的技术人员会尽快处理。请进入用户中心 → 工单系统 → 提交工单。', 4, 1],
                [null, '退款说明', '关于退款问题，符合退款政策的可在用户中心申请退款，审核通过后1-3个工作日原路返回。具体退款政策请查看服务条款。', 5, 1],
            ];
            foreach ($default_replies as $r) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO chat_quick_replies (staff_id, title, content, sort_order, is_global) VALUES (?,?,?,?,?)")->execute($r);
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}
    }
}
function ensure_user_points($uid) {
    try {
        $row = Database::fetch("SELECT id FROM user_points WHERE user_id = ?", [$uid]);
        if (!$row) {
            Database::insert('user_points', ['user_id' => $uid, 'points' => 0, 'total_earned' => 0, 'total_spent' => 0]);
        }
    } catch (Exception $e) {}
}

// 积分变动
function change_points($uid, $change_type, $points, $description = '', $related_id = null, $operator_id = null) {
    ensure_user_points($uid);
    $up = Database::fetch("SELECT points FROM user_points WHERE user_id = ?", [$uid]);
    $old = intval($up['points'] ?? 0);
    $new = $old + $points;
    Database::update('user_points', ['points' => $new], 'user_id = ?', [$uid]);
    if ($points > 0) {
        Database::query("UPDATE user_points SET total_earned = total_earned + ? WHERE user_id = ?", [$points, $uid]);
    } else {
        Database::query("UPDATE user_points SET total_spent = total_spent + ? WHERE user_id = ?", [abs($points), $uid]);
    }
    Database::insert('point_logs', [
        'user_id' => $uid, 'change_type' => $change_type,
        'points' => $points, 'balance_after' => $new,
        'description' => $description, 'related_id' => $related_id, 'operator_id' => $operator_id,
    ]);
    return $new;
}

// 获取用户积分
function get_user_points($uid) {
    ensure_user_points($uid);
    $row = Database::fetch("SELECT points FROM user_points WHERE user_id = ?", [$uid]);
    return intval($row['points'] ?? 0);
}

// 获取用户积分记录
function get_point_logs($uid, $limit = 20) {
    return Database::fetchAll("SELECT * FROM point_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$uid, $limit]);
}

// ========== 积分兑换函数 ==========

// 获取积分兑换商品列表
function get_point_products($category = '', $status = 'active') {
    migrate_new_tables();
    $where = '1=1';
    $params = [];
    if ($category) {
        $where .= " AND category = ?";
        $params[] = $category;
    }
    if ($status) {
        $where .= " AND status = ?";
        $params[] = $status;
    }
    return Database::fetchAll("SELECT * FROM point_products WHERE $where ORDER BY sort_order ASC, id ASC", $params);
}

// 获取单个积分商品
function get_point_product($id) {
    migrate_new_tables();
    return Database::fetch("SELECT * FROM point_products WHERE id = ?", [intval($id)]);
}

// 积分兑换商品
function exchange_point_product($uid, $product_id, $user_root_password = '') {
    migrate_new_tables();
    $product = get_point_product($product_id);
    if (!$product || $product['status'] !== 'active') {
        return ['success' => false, 'message' => '商品不存在或已下架'];
    }

    // 校验用户输入的 root 密码（仅 KVM 类型套餐需要）
    $package = null;
    if (intval($product['package_id']) > 0) {
        $package = Database::fetch("SELECT * FROM packages WHERE id = ?", [intval($product['package_id'])]);
    }
    $is_kvm_product = $package && intval($package['type']) === 3;
    if ($is_kvm_product && !empty($user_root_password)) {
        // 密码强度校验
        if (strlen($user_root_password) < 8 || strlen($user_root_password) > 64) {
            return ['success' => false, 'message' => 'Root 密码长度需在 8-64 个字符之间'];
        }
        if (!preg_match('/^[A-Za-z0-9!@#$%^&*()_+\-=\[\]{}|;:,.<>?\/\\\\]+$/', $user_root_password)) {
            return ['success' => false, 'message' => 'Root 密码包含非法字符'];
        }
    }

    $user_points = get_user_points($uid);
    if ($user_points < intval($product['points'])) {
        return ['success' => false, 'message' => '积分不足'];
    }

    if (intval($product['stock']) >= 0 && intval($product['sold_count']) >= intval($product['stock'])) {
        return ['success' => false, 'message' => '商品已兑换完'];
    }

    try {
        Database::query("START TRANSACTION");

        // 扣除积分
        change_points($uid, 'spend_exchange', -intval($product['points']), '兑换：' . $product['name'], $product_id);

        // 更新已兑换数量
        Database::query("UPDATE point_products SET sold_count = sold_count + 1 WHERE id = ?", [$product_id]);

        // 计算过期时间
        $expire_at = null;
        if (intval($product['duration']) > 0) {
            $expire_at = date('Y-m-d H:i:s', time() + intval($product['duration']) * 86400);
        }

        // 创建兑换记录
        $exchange_id = Database::insert('point_exchange_logs', [
            'user_id' => intval($uid),
            'product_id' => intval($product_id),
            'product_name' => $product['name'],
            'category' => $product['category'],
            'points' => intval($product['points']),
            'status' => 'completed',
            'expire_at' => $expire_at,
        ]);

        // 如果关联了套餐，自动创建主机
        $host_created = false;
        $host_message = '';
        if (intval($product['package_id']) > 0) {
            $host_result = create_host_from_exchange($uid, $product, $exchange_id, $user_root_password);
            if ($host_result['success']) {
                $host_created = true;
                $host_message = '，主机已自动开通';
            } else {
                $host_message = '，主机开通失败: ' . $host_result['message'];
            }
        }

        // ====== voucher 分类：发放优惠券到用户账户 ======
        // 积分兑换的优惠券直接绑定到当前用户，仅本用户可用
        $coupon_message = '';
        if ($product['category'] === 'voucher') {
            $voucher_value = floatval($product['original_price']);  // 立减金额
            $min_amount = 0.00;
            // 满减券：根据商品名解析（如 "满200减50"）— 默认无门槛
            $coupon_code = 'EX' . substr(strtoupper(md5($uid . $exchange_id . time())), 0, 10);
            $coupon_name = $product['name'];
            $coupon_type = 'cash';
            $discount_rate = 0.00;
            // 兑换券有效期：按商品 duration 字段（天），默认 30 天
            $valid_days = intval($product['duration']) > 0 ? intval($product['duration']) : 30;
            $valid_from = date('Y-m-d H:i:s');
            $valid_to = date('Y-m-d H:i:s', time() + $valid_days * 86400);

            // 创建独立的优惠券记录（绑定为兑换发放，避免与全局券池混用）
            $new_coupon_id = Database::insert('coupons', [
                'coupon_code' => $coupon_code,
                'coupon_name' => $coupon_name,
                'coupon_type' => $coupon_type,
                'discount_rate' => $discount_rate,
                'discount_amount' => $voucher_value,
                'min_amount' => $min_amount,
                'max_discount' => 0.00,
                'total_count' => 1,
                'used_count' => 0,
                'per_user_limit' => 1,
                'level_limit' => '',
                'product_limit' => '',  // 空=通用券，所有套餐类型均可使用
                'valid_from' => $valid_from,
                'valid_to' => $valid_to,
                'status' => 'active',
                'created_by' => intval($uid),
            ]);

            // 校验优惠券记录是否创建成功（失败则抛异常触发事务回滚）
            if (!$new_coupon_id || intval($new_coupon_id) <= 0) {
                throw new Exception('优惠券记录创建失败');
            }

            // 写入用户优惠券表（绑定 user_id）
            $user_coupon_id = Database::insert('user_coupons', [
                'user_id' => intval($uid),
                'coupon_id' => intval($new_coupon_id),
                'order_id' => 0,
                'status' => 'unused',
                'expired_at' => $valid_to,
            ]);

            // 更新兑换日志 related_id 指向 user_coupon_id
            Database::update('point_exchange_logs', ['related_id' => intval($user_coupon_id)], 'id = ?', [$exchange_id]);

            $coupon_message = '，优惠券已发放到您的账户（有效期 ' . $valid_days . ' 天）';
        }

        // 发送通知
        send_notification($uid, 'system', '积分兑换成功', '您已成功兑换 ' . $product['name'] . '，消耗积分 ' . $product['points'] . '。' . $host_message . $coupon_message, 'exchange', $exchange_id);

        Database::query("COMMIT");
        return ['success' => true, 'message' => '兑换成功' . $host_message . $coupon_message, 'exchange_id' => $exchange_id, 'host_created' => $host_created];
    } catch (Exception $e) {
        Database::query("ROLLBACK");
        return ['success' => false, 'message' => '兑换失败：' . $e->getMessage()];
    }
}

// 获取用户兑换记录
function get_user_exchange_logs($uid, $limit = 20) {
    migrate_new_tables();
    return Database::fetchAll("SELECT * FROM point_exchange_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$uid, $limit]);
}

// ====== 优惠券相关辅助函数 ======

/**
 * 获取用户当前可用的优惠券列表（未使用且未过期）
 * 绑定 user_id，仅本用户可使用
 *
 * @param int $uid 用户ID
 * @param float $order_amount 订单金额（用于过滤最低消费限制）
 * @return array 可用优惠券数组
 */
function get_user_available_coupons($uid, $order_amount = 0.00) {
    migrate_new_tables();
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT uc.id AS user_coupon_id, uc.status AS user_coupon_status, uc.expired_at,
                   c.id AS coupon_id, c.coupon_code, c.coupon_name, c.coupon_type,
                   c.discount_rate, c.discount_amount, c.min_amount, c.max_discount,
                   c.valid_from, c.valid_to, c.product_limit
            FROM user_coupons uc
            INNER JOIN coupons c ON uc.coupon_id = c.id
            WHERE uc.user_id = ?
              AND uc.status = 'unused'
              AND c.status = 'active'
              AND (uc.expired_at IS NULL OR uc.expired_at > ?)
              AND (c.valid_to IS NULL OR c.valid_to > ?)
              AND (c.valid_from IS NULL OR c.valid_from <= ?)
              AND c.min_amount <= ?
            ORDER BY c.discount_amount DESC, c.discount_rate DESC";
    return Database::fetchAll($sql, [$uid, $now, $now, $now, $order_amount]);
}

/**
 * 计算优惠券折扣后的实际金额
 *
 * @param array $coupon 优惠券信息（来自 get_user_available_coupons）
 * @param float $original_amount 原价
 * @return array ['discount' => 优惠金额, 'final' => 应付金额]
 */
function calculate_coupon_discount($coupon, $original_amount) {
    if (empty($coupon) || $original_amount <= 0) {
        return ['discount' => 0.00, 'final' => $original_amount];
    }
    $discount = 0.00;
    if ($coupon['coupon_type'] === 'cash') {
        // 立减券：直接抵扣金额
        $discount = floatval($coupon['discount_amount']);
    } elseif ($coupon['coupon_type'] === 'discount') {
        // 折扣券：discount_rate 表示折扣率（如 90 = 9折）
        $rate = floatval($coupon['discount_rate']);
        if ($rate > 0 && $rate < 100) {
            $discount = round($original_amount * (100 - $rate) / 100, 2);
        }
        // 最大优惠限制
        if (!empty($coupon['max_discount']) && $coupon['max_discount'] > 0 && $discount > floatval($coupon['max_discount'])) {
            $discount = floatval($coupon['max_discount']);
        }
    } elseif ($coupon['coupon_type'] === 'rebate') {
        // 返现券：在支付时不抵扣，仅记录；这里按立减处理以兼容
        $discount = floatval($coupon['discount_amount']);
    }
    // 优惠金额不能超过原价
    if ($discount > $original_amount) $discount = $original_amount;
    $final = round($original_amount - $discount, 2);
    return ['discount' => round($discount, 2), 'final' => $final];
}

/**
 * 标记用户优惠券为已使用
 *
 * @param int $user_coupon_id user_coupons.id
 * @param int $order_id 订单ID
 * @return bool 是否成功
 */
function mark_coupon_used($user_coupon_id, $order_id) {
    migrate_new_tables();
    try {
        // 先查当前状态，确保只对 unused 的券进行标记，避免重复递增 used_count
        $coupon = Database::fetch("SELECT coupon_id, status FROM user_coupons WHERE id = ?", [intval($user_coupon_id)]);
        if (!$coupon || $coupon['status'] !== 'unused') {
            return false; // 已被使用或不存在，不重复标记
        }
        // 标记为已使用
        $affected = Database::query(
            "UPDATE user_coupons SET status = 'used', order_id = ?, used_at = NOW() WHERE id = ? AND status = 'unused'",
            [intval($order_id), intval($user_coupon_id)]
        );
        if ($affected > 0) {
            // 只有实际更新了行才递减库存
            Database::query("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?", [intval($coupon['coupon_id'])]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 释放未支付的优惠券占用（订单取消/超时/退款时调用）
 *
 * @param int $order_id 订单ID
 * @return bool
 */
function release_coupon_for_order($order_id) {
    migrate_new_tables();
    try {
        // 先查出受影响的 coupon_id（必须在 UPDATE user_coupons 之前查，否则 order_id 已被清零）
        $coupons = Database::fetchAll(
            "SELECT coupon_id FROM user_coupons WHERE order_id = ? AND status = 'used'",
            [intval($order_id)]
        );
        if (empty($coupons)) {
            return true; // 没有关联的优惠券，直接返回成功
        }
        // 先递减 coupons.used_count（基于查到的 coupon_id 列表）
        foreach ($coupons as $c) {
            Database::query("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?", [intval($c['coupon_id'])]);
        }
        // 再更新 user_coupons 状态为 unused，清空 order_id
        Database::update('user_coupons', [
            'status' => 'unused',
            'order_id' => 0,
            'used_at' => null,
        ], 'order_id = ? AND status = ?', [intval($order_id), 'used']);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 积分兑换创建主机
function create_host_from_exchange($uid, $product, $exchange_id, $user_root_password = '') {
    $package_id = intval($product['package_id']);
    if ($package_id <= 0) {
        return ['success' => false, 'message' => '未关联套餐'];
    }

    $package = Database::fetch("SELECT * FROM packages WHERE id = ?", [$package_id]);
    if (!$package || $package['status'] !== 'active') {
        return ['success' => false, 'message' => '套餐不存在或已下架'];
    }

    $now = date('Y-m-d H:i:s');
    $duration_days = intval($product['duration']);
    $expire_at = date('Y-m-d H:i:s', strtotime("+" . $duration_days . " days"));

    $order_no = generate_order_no();

    // 将用户输入的 root 密码写入 package_info，确保与 VM 实际使用的密码一致
    $package_info = $package;
    if (!empty($user_root_password)) {
        $package_info['root_password'] = $user_root_password;
    }

    $order_id = Database::insert('orders', [
        'order_no' => $order_no,
        'user_id' => $uid,
        'package_id' => $package_id,
        'package_name' => $package['name'],
        'package_info' => json_encode($package_info, JSON_UNESCAPED_UNICODE),
        'duration' => ceil($duration_days / 30),
        'amount' => 0.00,
        'status' => 'completed',
        'paid_at' => $now,
        'payment_method' => 'points',
        'remark' => '积分兑换',
        'created_at' => $now,
    ]);

    $type = intval($package['type']);

    if ($type === 3) {
        return create_kvm_host_from_order($uid, $order_id, $package_info, $duration_days, $user_root_password);
    } elseif ($type === 2) {
        return create_virtual_host_from_order($uid, $order_id, $package_info, $duration_days);
    } else {
        return create_generic_host($uid, $order_id, $package_info, $duration_days);
    }
}

function create_kvm_host_from_order($uid, $order_id, $package, $duration_days, $user_password = '') {
    if (!kvm_is_enabled()) {
        return ['success' => false, 'message' => 'KVM功能未启用'];
    }

    $images = kvm_get_images();
    if (empty($images)) {
        return ['success' => false, 'message' => '未配置系统镜像'];
    }
    $default_image = $images[0];

    // 社区版：仅使用全局KVM配置（单节点模式）
    $selected_node_id = 0;
    $kvm = kvm_get_manager();
    $vm_name = 'vm_' . $uid . '_' . substr(time(), -5) . '_' . rand(100, 999);
    // 优先使用用户传入的密码，否则自动生成
    $root_pwd = !empty($user_password) ? $user_password : $kvm->generateRootPassword();

    $iso_filename = basename($default_image['iso_path']);
    $full_iso_path = rtrim($kvm->getStoragePool(), '/') . '/iso/' . $iso_filename;

    $disk_type = $default_image['disk_type'] ?? 'qcow2';
    $preinstalled_image = !empty($default_image['preinstalled_image']) ? $default_image['preinstalled_image'] : '';

    $create_options = [
        'disk_type' => $disk_type,
        'clone_image' => true,
        'root_password' => $root_pwd,
    ];
    if (!empty($preinstalled_image)) {
        $create_options['preinstalled_image'] = $preinstalled_image;
    }

    $specs = kvm_get_specs_from_package($package);

    $result = $kvm->createVM(
        $vm_name,
        $specs['vcpu'],
        $specs['memory_mb'],
        $specs['disk_gb'],
        $full_iso_path,
        $default_image['os_type'],
        $create_options
    );

    if (!$result['success']) {
        return ['success' => false, 'message' => 'KVM创建失败: ' . $kvm->getError()];
    }

    $expire = date('Y-m-d H:i:s', strtotime("+" . $duration_days . " days"));
    $vm_ip = $result['ip_address'];
    $vnc_port = intval($result['vnc_port'] ?? 5900);

    $traffic_limit_gb = intval($package['kvm_traffic_gb'] ?? 0);
    $traffic_limit_mb = $traffic_limit_gb * 1024;

    $host_id = Database::insert('hosts', [
        'user_id' => $uid,
        'kvm_node_id' => $selected_node_id,
        'order_id' => $order_id,
        'package_id' => $package['id'],
        'package_name' => $package['name'],
        'vm_type' => 'kvm',
        'vcpu' => $specs['vcpu'],
        'memory_mb' => $specs['memory_mb'],
        'disk_gb' => $specs['disk_gb'],
        'bandwidth_mbps' => $specs['bandwidth_mbps'],
        'image_id' => $default_image['id'],
        'vm_name' => $vm_name,
        'vm_uuid' => $result['uuid'],
        'uuid' => $result['uuid'],
        'ip_address' => $vm_ip,
        'root_password' => $root_pwd,
        'vnc_port' => $vnc_port,
        'ssh_port' => 22,
        'mnbt_username' => '',   // KVM 主机不使用 MNBT 字段
        'mnbt_password' => '',
        'control_panel_url' => '',
        'expire_at' => $expire,
        'vm_power_status' => 'running',
        'vm_created_at' => date('Y-m-d H:i:s'),
        'vm_last_sync' => date('Y-m-d H:i:s'),
        'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'status' => 'running',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'traffic_used' => 0,
        'traffic_limit' => $traffic_limit_mb,
        'traffic_reset_date' => date('Y-m-01'),
    ]);

    // 更新节点VM计数
    if ($selected_node_id > 0) {
        try {
            Database::query("UPDATE kvm_nodes SET current_vms = current_vms + 1 WHERE id = ?", [$selected_node_id]);
        } catch (Exception $e) {}
    }

    return ['success' => true, 'message' => 'KVM主机创建成功', 'host_id' => $host_id];
}

function create_virtual_host_from_order($uid, $order_id, $package, $duration_days) {
    $expire = date('Y-m-d H:i:s', strtotime("+" . $duration_days . " days"));

    // 生成与 checkout.php mnbt_create_host() 一致格式的账号密码
    $mnbt_username = 'mnbt' . $uid . substr(time(), -4) . rand(10, 99);
    $mnbt_password = substr(md5(time() . rand(1000, 9999) . mt_rand()), 0, 10);
    $webdx = $package['webdx'] ?? 1000;
    $sqldx = $package['sqldx'] ?? 500;
    $sizemax = $package['sizemax'] ?? 50;
    $mtype = $package['type'] ?? 2;
    $ymbds = $package['ymbds'] ?? 5;
    $dqtime = date('Y-m-d', strtotime("+" . ceil($duration_days / 30) . " months"));

    // 必须调用 MNBT API 创建主机账户，否则用户无法登录
    try {
        require_once __DIR__ . '/mnbt.php';
        $api = mnbt_api();
        $result = $api->create_host($mnbt_username, $mnbt_password, $webdx, $sqldx, $sizemax, $mtype, $ymbds, $dqtime);

        $host_status = 'creating';
        if (is_array($result) && isset($result['code']) && intval($result['code']) === 200) {
            $host_status = 'running';
        }

        $host_id = Database::insert('hosts', [
            'user_id' => $uid,
            'order_id' => $order_id,
            'package_id' => $package['id'],
            'package_name' => $package['name'],
            'vm_type' => 'mnbt',
            'mnbt_username' => $mnbt_username,
            'mnbt_password' => $mnbt_password,
            'control_panel_url' => config('mnbt.base_url') . '/user/',
            'expire_at' => $expire,
            'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'status' => $host_status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => '虚拟主机已创建', 'host_id' => $host_id];
    } catch (Exception $e) {
        // MNBT API 调用失败，仍写入记录但标记为 creating
        $host_id = Database::insert('hosts', [
            'user_id' => $uid,
            'order_id' => $order_id,
            'package_id' => $package['id'],
            'package_name' => $package['name'],
            'vm_type' => 'mnbt',
            'mnbt_username' => $mnbt_username,
            'mnbt_password' => $mnbt_password,
            'control_panel_url' => config('mnbt.base_url') . '/user/',
            'expire_at' => $expire,
            'api_response' => 'Error: ' . $e->getMessage(),
            'status' => 'creating',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => false, 'message' => '虚拟主机API调用失败: ' . $e->getMessage(), 'host_id' => $host_id];
    }
}

function create_generic_host($uid, $order_id, $package, $duration_days) {
    $expire = date('Y-m-d H:i:s', strtotime("+" . $duration_days . " days"));

    $host_id = Database::insert('hosts', [
        'user_id' => $uid,
        'order_id' => $order_id,
        'package_id' => $package['id'],
        'package_name' => $package['name'],
        'vm_type' => 'generic',
        'expire_at' => $expire,
        'status' => 'running',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return ['success' => true, 'message' => '服务已开通', 'host_id' => $host_id];
}

// 获取商品分类名称
function point_product_category_name($category) {
    $map = [
        'host' => '虚拟主机',
        'server' => '云服务器',
        'voucher' => '优惠券',
        'other' => '其他',
    ];
    return $map[$category] ?? $category;
}

// 获取商品分类图标
function point_product_category_icon($category) {
    $map = [
        'host' => '🖥️',
        'server' => '☁️',
        'voucher' => '🎫',
        'other' => '🎁',
    ];
    return $map[$category] ?? '🎁';
}

// ========== 站内通知函数 ==========

// 发送站内通知
function send_notification($user_id, $type, $title, $content = '', $related_type = '', $related_id = 0) {
    try {
        migrate_new_tables();
        Database::insert('user_notifications', [
            'user_id' => intval($user_id),
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'related_type' => $related_type,
            'related_id' => intval($related_id),
            'is_read' => 0,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 获取用户未读通知数量
function get_unread_notification_count($user_id) {
    try {
        migrate_new_tables();
        $row = Database::fetch("SELECT COUNT(*) as cnt FROM user_notifications WHERE user_id = ? AND is_read = 0", [$user_id]);
        return intval($row['cnt'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// 获取用户通知列表
function get_user_notifications($user_id, $limit = 10, $only_unread = false) {
    try {
        migrate_new_tables();
        if ($only_unread) {
            return Database::fetchAll("SELECT * FROM user_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT ?", [$user_id, $limit]);
        }
        return Database::fetchAll("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$user_id, $limit]);
    } catch (Exception $e) {
        return [];
    }
}

// 标记通知为已读
function mark_notification_read($user_id, $notification_id = null) {
    try {
        migrate_new_tables();
        if ($notification_id) {
            Database::query("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND id = ?", [$user_id, $notification_id]);
        } else {
            Database::query("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0", [$user_id]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 通知类型图标映射
function notification_type_icon($type) {
    $icons = [
        'system' => '🔔',
        'host' => '🖥️',
        'order' => '📦',
        'security' => '🔒',
        'promotion' => '🎉',
    ];
    return $icons[$type] ?? '📢';
}

// 格式化时间为"几分钟前"
function format_time_ago($datetime) {
    $timestamp = is_numeric($datetime) ? $datetime : strtotime($datetime);
    if (!$timestamp) return '';
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return '刚刚';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . '分钟前';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . '小时前';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . '天前';
    } else {
        return date('Y-m-d', $timestamp);
    }
}

// ========== WebSSH Token函数 ==========

// 创建SSH连接token
function create_ssh_token($user_id, $host_id, $ip, $port = 22, $username = 'root', $password = '', $expire_seconds = 300) {
    migrate_new_tables();
    $token = md5($user_id . '_' . $host_id . '_' . time() . '_' . rand(100000, 999999));
    $expire_at = time() + $expire_seconds;

    try {
        Database::insert('ssh_tokens', [
            'token' => $token,
            'user_id' => intval($user_id),
            'host_id' => intval($host_id),
            'ip' => $ip,
            'port' => intval($port),
            'username' => $username,
            'password' => $password,
            'expire_at' => $expire_at,
            'used' => 0,
        ]);
        return $token;
    } catch (Exception $e) {
        return false;
    }
}

// 验证SSH token并返回连接信息
function validate_ssh_token_db($token) {
    migrate_new_tables();
    try {
        $row = Database::fetch("SELECT * FROM ssh_tokens WHERE token = ? AND used = 0", [$token]);
        if (!$row) return false;

        if (time() > intval($row['expire_at'])) {
            return false;
        }

        Database::query("UPDATE ssh_tokens SET used = 1 WHERE id = ?", [$row['id']]);

        return [
            'ip' => $row['ip'],
            'port' => intval($row['port']),
            'user' => $row['username'],
            'password' => $row['password'],
            'user_id' => intval($row['user_id']),
            'host_id' => intval($row['host_id']),
        ];
    } catch (Exception $e) {
        return false;
    }
}

// 清理过期的SSH token
function cleanup_expired_ssh_tokens() {
    migrate_new_tables();
    try {
        Database::query("DELETE FROM ssh_tokens WHERE expire_at < ? OR used = 1", [time() - 3600]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 生成工单编号
function generate_ticket_no() {
    return 'TK' . date('YmdHis') . rand(1000, 9999);
}

// 获取广告（按位置）
function get_ads_by_position($pos_key) {
    $today = date('Y-m-d');
    return Database::fetchAll(
        "SELECT * FROM ads a
         JOIN ad_positions p ON a.pos_id = p.id
         WHERE p.pos_key = ? AND p.status = 'active' AND a.status = 'active'
         AND (a.start_date IS NULL OR a.start_date <= ?)
         AND (a.end_date IS NULL OR a.end_date >= ?)
         ORDER BY a.sort_order ASC, a.created_at DESC LIMIT 1",
        [$pos_key, $today, $today]
    );
}

// 记录广告点击
function record_ad_click($ad_id) {
    try {
        Database::query("UPDATE ads SET click_count = click_count + 1 WHERE id = ?", [$ad_id]);
    } catch (Exception $e) {}
}

// 生成分享码
function generate_referral_code($uid) {
    return strtoupper(substr(md5($uid . time() . rand(1000, 9999)), 0, 8));
}

// 获取用户的推广码
function get_user_referral_code($uid) {
    $row = Database::fetch("SELECT referral_code FROM referrals WHERE referrer_id = ?", [$uid]);
    if ($row && !empty($row['referral_code'])) {
        return $row['referral_code'];
    }
    // 没有则创建
    $code = generate_referral_code($uid);
    try {
        Database::insert('referrals', ['referrer_id' => $uid, 'referred_id' => $uid, 'referral_code' => $code, 'rebate_amount' => 0, 'rebate_count' => 0]);
    } catch (Exception $e) {}
    return $code;
}

// 处理推广返现（订单支付成功后调用）
function process_referral_rebate($order_id, $user_id, $order_amount) {
    // 检查该用户是否被推广注册
    $ref = Database::fetch("SELECT referrer_id FROM referrals WHERE referred_id = ? AND referrer_id != ?", [$user_id, $user_id]);
    if (!$ref) return;

    $referrer_id = $ref['referrer_id'];
    $rebate_amount = 0;

    // 首单返现规则
    $first_rule = Database::fetch("SELECT * FROM rebate_rules WHERE rule_key = 'first_order' AND enabled = 1");
    if ($first_rule) {
        $ref_count = Database::fetch("SELECT COUNT(*) as c FROM rebate_logs WHERE referred_id = ?", [$user_id]);
        if (intval($ref_count['c']) === 0) {
            if ($first_rule['rebate_type'] === 'percent') {
                $amount = $order_amount * floatval($first_rule['rebate_value']) / 100;
            } else {
                $amount = floatval($first_rule['rebate_value']);
            }
            $amount = round($amount, 2);
            if ($amount > 0) {
                Database::insert('rebate_logs', [
                    'referrer_id' => $referrer_id, 'referred_id' => $user_id,
                    'order_id' => $order_id, 'rebate_amount' => $amount,
                    'order_amount' => $order_amount, 'status' => 'settled',
                ]);
                Database::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $referrer_id]);
                Database::query("UPDATE referrals SET rebate_amount = rebate_amount + ?, rebate_count = rebate_count + 1 WHERE referrer_id = ? AND referred_id = ?",
                    [$amount, $referrer_id, $user_id]);
                $rebate_amount += $amount;
            }
        }
    }

    // 每单返现规则
    $every_rule = Database::fetch("SELECT * FROM rebate_rules WHERE rule_key = 'every_order' AND enabled = 1");
    if ($every_rule && $order_amount >= floatval($every_rule['min_order_amount'])) {
        if ($every_rule['rebate_type'] === 'percent') {
            $amount = $order_amount * floatval($every_rule['rebate_value']) / 100;
        } else {
            $amount = floatval($every_rule['rebate_value']);
        }
        $amount = round($amount, 2);
        if ($amount > 0) {
            Database::insert('rebate_logs', [
                'referrer_id' => $referrer_id, 'referred_id' => $user_id,
                'order_id' => $order_id, 'rebate_amount' => $amount,
                'order_amount' => $order_amount, 'status' => 'settled',
            ]);
            Database::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $referrer_id]);
            Database::query("UPDATE referrals SET rebate_amount = rebate_amount + ?, rebate_count = rebate_count + 1 WHERE referrer_id = ? AND referred_id = ?",
                [$amount, $referrer_id, $user_id]);
        }
    }

    return $rebate_amount;
}

// 工单分类映射
function ticket_category_name($cat) {
    $map = ['tech' => '技术支持', 'finance' => '财务问题', 'account' => '账号问题', 'complaint' => '投诉建议', 'other' => '其他'];
    return $map[$cat] ?? '其他';
}

function ticket_priority_name($p) {
    $map = ['low' => '低', 'medium' => '中', 'high' => '高', 'urgent' => '紧急'];
    return $map[$p] ?? '中';
}

function ticket_priority_color($p) {
    $map = ['low' => '#94a3b8', 'medium' => '#3b82f6', 'high' => '#f59e0b', 'urgent' => '#ef4444'];
    return $map[$p] ?? '#3b82f6';
}

function ticket_status_name($s) {
    $map = ['open' => '待处理', 'replied' => '处理中', 'closed' => '已关闭'];
    return $map[$s] ?? $s;
}

function ticket_status_color($s) {
    $map = ['open' => '#ef4444', 'replied' => '#f59e0b', 'closed' => '#22c55e'];
    return $map[$s] ?? '#94a3b8';
}

// ======================== KVM 辅助函数 ========================

// 生成6位随机验证码
function codestr() {
    $arr = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));
    shuffle($arr);
    $arr = array_flip($arr);
    $arr = array_rand($arr, 6);
    $res = '';
    foreach ($arr as $v) {
        $res .= $v;
    }
    return $res;
}

// 保存验证码到SESSION
function save_verify_code($email, $code, $type = 'register') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['verify_code'] = $code;
    $_SESSION['verify_email'] = $email;
    $_SESSION['verify_type'] = $type;
    $_SESSION['verify_time'] = time();
}

// 验证验证码是否正确
function verify_code($input_code) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['verify_code']) || empty($_SESSION['verify_email']) || empty($_SESSION['verify_time'])) {
        return ['success' => false, 'message' => '请先获取验证码'];
    }

    // 验证码5分钟有效期
    if (time() - $_SESSION['verify_time'] > 300) {
        clear_verify_code();
        return ['success' => false, 'message' => '验证码已过期，请重新获取'];
    }

    if (strtolower($input_code) !== strtolower($_SESSION['verify_code'])) {
        return ['success' => false, 'message' => '验证码错误'];
    }

    return ['success' => true, 'email' => $_SESSION['verify_email']];
}

// 清除验证码
function clear_verify_code() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['verify_code']);
    unset($_SESSION['verify_email']);
    unset($_SESSION['verify_type']);
    unset($_SESSION['verify_time']);
}

// 获取KVM配置（从配置文件读取 host/port/user/password）
function kvm_get_manager() {
    static $instance = null;
    if ($instance !== null) return $instance;
    $cfg = config('kvm');
    if (empty($cfg['host'])) {
        $cfg = [
            'host' => '127.0.0.1',
            'port' => 22,
            'user' => 'root',
            'password' => '',
            'public_domain' => '127.0.0.1',
            'bridge' => 'virbr0',
            'storage' => '/mnt/50D008FDD008EAD4',
        ];
    }
    require_once __DIR__ . '/KvmManager.php';
    $instance = new KvmManager($cfg);
    return $instance;
}

// KVM管理器别名函数
function kvm_manager() {
    return kvm_get_manager();
}

// 社区版：单节点模式，节点ID参数保留兼容但忽略，统一使用全局配置
function kvm_get_manager_for_node($node_id) {
    return kvm_get_manager();
}

// 根据主机记录获取KvmManager实例（自动判断节点）
function kvm_get_manager_for_host($host) {
    $node_id = intval($host['kvm_node_id'] ?? 0);
    if ($node_id > 0) {
        return kvm_get_manager_for_node($node_id);
    }
    // 没有关联节点，使用全局配置（兼容旧数据）
    return kvm_get_manager();
}

// 检查KVM是否启用
function kvm_is_enabled() {
    $cfg = config('kvm');
    return !empty($cfg['enabled']);
}

// 社区版：获取主机对应的VNC连接信息（单节点，使用全局配置）
function kvm_get_vnc_info($host) {
    return [
        'host' => config('kvm.public_domain') ?: config('kvm.vnc_proxy_host') ?: '127.0.0.1',
        'port' => intval(config('kvm.novnc_port') ?: 6080),
        'vnc_port' => intval($host['vnc_port'] ?: 5900),
    ];
}

// 从套餐/订单解析CPU/内存/磁盘/带宽规格
function kvm_get_specs_from_package($pkg) {
    $vcpu = intval($pkg['kvm_vcpu'] ?? $pkg['vcpu'] ?? $pkg['cpu'] ?? 2);
    $memory_mb = intval($pkg['kvm_memory_mb'] ?? $pkg['memory_mb'] ?? $pkg['memory'] ?? 2048);
    $disk_gb = intval($pkg['kvm_disk_gb'] ?? $pkg['disk_gb'] ?? $pkg['disk'] ?? 40);
    $bandwidth_mbps = intval($pkg['kvm_bandwidth_mbps'] ?? $pkg['bandwidth_mbps'] ?? $pkg['bandwidth'] ?? 100);
    return ['vcpu' => $vcpu, 'memory_mb' => $memory_mb, 'disk_gb' => $disk_gb, 'bandwidth_mbps' => $bandwidth_mbps];
}

// KVM电源状态显示
function kvm_power_label($s) {
    $map = ['running' => '运行中', 'stopped' => '已停止', 'paused' => '已暂停', 'saved' => '已休眠', 'creating' => '创建中', 'unknown' => '未知', 'installing' => '安装中', 'reinstalling' => '重装中', 'suspended_traffic' => '流量超限暂停', 'destroyed' => '已销毁', 'crashed' => '已崩溃', 'dying' => '正在关闭'];
    return $map[$s] ?? $s;
}

function kvm_power_color($s) {
    $map = ['running' => '#22c55e', 'stopped' => '#ef4444', 'paused' => '#f59e0b', 'saved' => '#8b5cf6', 'creating' => '#3b82f6', 'installing' => '#8b5cf6', 'unknown' => '#64748b', 'reinstalling' => '#f59e0b', 'suspended_traffic' => '#dc2626', 'destroyed' => '#64748b', 'crashed' => '#dc2626', 'dying' => '#f97316'];
    return $map[$s] ?? '#64748b';
}

// 获取镜像列表
function kvm_get_images($active_only = true) {
    $cache_key = 'vm_images_' . ($active_only ? 'active' : 'all');
    if (class_exists('DataCache')) {
        $cached = DataCache::getFile($cache_key, '__NOCACHE__');
        if ($cached !== '__NOCACHE__') {
            return $cached;
        }
    }
    $sql = "SELECT * FROM vm_images";
    if ($active_only) {
        $result = Database::fetchAll($sql . " WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
    } else {
        $result = Database::fetchAll($sql . " ORDER BY sort_order ASC, id ASC");
    }
    if (class_exists('DataCache')) {
        DataCache::setFile($cache_key, $result, 300);
    }
    return $result;
}

// 通过QEMU引导ISO安装，创建可启动的qcow2预装镜像
function kvm_convert_iso_to_qcow2($iso_path, $output_path = '', $disk_size_gb = 40, $memory_mb = 2048) {
    if (!kvm_is_enabled()) {
        return ['success' => false, 'message' => 'KVM功能未启用'];
    }
    $kvm = kvm_get_manager();
    return $kvm->convertIsoToQcow2($iso_path, $output_path, $disk_size_gb, $memory_mb);
}

// 检查ISO转qcow2安装进程状态
function kvm_check_iso_convert_status($output_path) {
    if (!kvm_is_enabled()) {
        return ['running' => false, 'message' => 'KVM功能未启用'];
    }
    $kvm = kvm_get_manager();
    return $kvm->checkIsoConvertStatus($output_path);
}

// 停止ISO转qcow2安装进程
function kvm_stop_iso_convert($output_path) {
    if (!kvm_is_enabled()) {
        return false;
    }
    $kvm = kvm_get_manager();
    return $kvm->stopIsoConvert($output_path);
}

// 获取宿主机上的ISO文件列表
function kvm_list_iso_files($directory = '') {
    if (!kvm_is_enabled()) {
        return [];
    }
    $kvm = kvm_get_manager();
    return $kvm->listIsoFiles($directory);
}

// 创建KVM主机（创建后写入hosts表）
function kvm_create_vm($order, $uid, $image_id, $user_password = '') {
    $pkg_info = json_decode($order['package_info'], true);
    $specs = kvm_get_specs_from_package($pkg_info);
    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
    if (!$image) {
        return ['success' => false, 'message' => '指定的系统镜像不存在'];
    }

    $is_nat_kvm = !empty($pkg_info['is_nat_kvm']) || !empty($order['is_nat_kvm']);
    $pool_type = $is_nat_kvm ? 'nat' : 'dedicated';

    // 社区版：仅使用全局KVM配置（单节点模式）
    $selected_node_id = 0;
    $kvm = kvm_get_manager();
    $vm_name = 'vm_' . $uid . '_' . substr(time(), -5) . '_' . rand(100, 999);
    // 优先使用用户设置的密码，否则自动生成
    $root_pwd = !empty($user_password) ? $user_password : $kvm->generateRootPassword();

    $iso_filename = basename($image['iso_path']);
    $full_iso_path = rtrim($kvm->getStoragePool(), '/') . '/iso/' . $iso_filename;

    // 获取磁盘格式和预装镜像
    $disk_type = $image['disk_type'] ?? 'qcow2';
    $preinstalled_image = !empty($image['preinstalled_image']) ? $image['preinstalled_image'] : '';

    // 构建创建选项
    $create_options = [
        'disk_type' => $disk_type,
        'clone_image' => true,
        'root_password' => $root_pwd,
    ];
    
    // 如果有预装镜像路径，加入选项
    if (!empty($preinstalled_image)) {
        $create_options['preinstalled_image'] = $preinstalled_image;
    }

    $result = $kvm->createVM(
        $vm_name,
        $specs['vcpu'],
        $specs['memory_mb'],
        $specs['disk_gb'],
        $full_iso_path,
        $image['os_type'],
        $create_options
    );

    if (!$result['success']) {
        return ['success' => false, 'message' => 'KVM创建失败: ' . $kvm->getError()];
    }

    $now = date('Y-m-d H:i:s');
    $expire = date('Y-m-d H:i:s', strtotime("+" . intval($order['duration']) . " months"));

    $vm_ip = $result['ip_address'];
    $ssh_port = intval($result['ssh_port'] ?? 22);
    $vnc_port = intval($result['vnc_port'] ?? 5900);

    $frp_remote_ssh_port = 0;
    $frp_remote_vnc_port = 0;
    $frp_public_ip = '';
    $frp_ssh_rule = '';
    $frp_vnc_rule = '';

    $frp_cfg = config('frp');
    $frp_enabled = !empty($frp_cfg['enabled']);

    $package = Database::fetch("SELECT kvm_traffic_gb FROM packages WHERE id = ?", [$order['package_id']]);
    $traffic_limit_gb = intval($package['kvm_traffic_gb'] ?? 0);
    $traffic_limit_mb = $traffic_limit_gb * 1024;
    
    $host_uuid = kvm_generate_host_uuid();
    $host_id = Database::insert('hosts', [
        'user_id' => $uid,
        'kvm_node_id' => $selected_node_id,
        'order_id' => $order['id'],
        'package_id' => $order['package_id'],
        'package_name' => $order['package_name'],
        'vm_type' => 'kvm',
        'is_nat_kvm' => $is_nat_kvm ? 1 : 0,
        'vcpu' => $specs['vcpu'],
        'memory_mb' => $specs['memory_mb'],
        'disk_gb' => $specs['disk_gb'],
        'bandwidth_mbps' => $specs['bandwidth_mbps'],
        'image_id' => $image_id,
        'vm_name' => $vm_name,
        'vm_uuid' => $result['uuid'],
        'uuid' => $host_uuid,
        'ip_address' => $vm_ip,
        'root_password' => $root_pwd,
        'vnc_port' => $vnc_port,
        'ssh_port' => $ssh_port,
        'mnbt_username' => '',   // KVM 主机不使用 MNBT 字段
        'mnbt_password' => '',
        'control_panel_url' => '',
        'expire_at' => $expire,
        'vm_power_status' => 'running',
        'vm_created_at' => $now,
        'vm_last_sync' => $now,
        'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'status' => 'running',
        'created_at' => $now,
        'updated_at' => $now,
        'traffic_used' => 0,
        'traffic_limit' => $traffic_limit_mb,
        'traffic_reset_date' => date('Y-m-01'),
    ]);

    $pool_id = intval($pkg_info['pool_id'] ?? 0);
    $ip_pool_result = ip_pool_allocate($host_id, $uid, $pool_id, $pool_type);
    if ($ip_pool_result['success']) {
        $vm_ip = $ip_pool_result['ip'];
        $update_data = ['ip_address' => $vm_ip];
        if ($is_nat_kvm && !empty($ip_pool_result['public_ip'])) {
            $update_data['public_ip'] = $ip_pool_result['public_ip'];
        }
        Database::update('hosts', $update_data, 'id = ?', [$host_id]);
    }

    // 更新节点VM计数
    if ($selected_node_id > 0) {
        try {
            Database::query("UPDATE kvm_nodes SET current_vms = current_vms + 1 WHERE id = ?", [$selected_node_id]);
        } catch (Exception $e) {}
    }

    if ($frp_enabled && !empty($vm_ip)) {
        $frp_public_ip = $is_nat_kvm && !empty($ip_pool_result['public_ip']) ? $ip_pool_result['public_ip'] : ($frp_cfg['public_domain'] ?? $frp_cfg['server_addr'] ?? '');

        $frp_remote_ssh_port = frp_find_available_port(30000 + intval(substr($host_id, -4)));
        $frp_ssh_rule = 'kvm_ssh_' . $host_id;
        $ssh_frp_result = frp_add_proxy($frp_ssh_rule, 'tcp', $vm_ip, 22, $frp_remote_ssh_port);

        $frp_remote_vnc_port = frp_find_available_port($frp_remote_ssh_port + 1);
        $frp_vnc_rule = 'kvm_vnc_' . $host_id;
        $vnc_frp_result = frp_add_proxy($frp_vnc_rule, 'tcp', '127.0.0.1', $vnc_port, $frp_remote_vnc_port);

        Database::insert('nat_rules', [
            'host_id' => $host_id,
            'user_id' => $uid,
            'rule_name' => 'SSH远程登录',
            'protocol' => 'tcp',
            'local_ip' => $vm_ip,
            'local_port' => 22,
            'remote_ip' => $frp_public_ip,
            'remote_port' => $frp_remote_ssh_port,
            'frp_rule_name' => $frp_ssh_rule,
            'status' => !empty($ssh_frp_result['success']) ? 'active' : 'error',
            'frp_status' => !empty($ssh_frp_result['success']) ? 'online' : 'error',
            'error_msg' => empty($ssh_frp_result['success']) ? ($ssh_frp_result['message'] ?? '') : '',
        ]);

        Database::insert('nat_rules', [
            'host_id' => $host_id,
            'user_id' => $uid,
            'rule_name' => 'VNC控制台',
            'protocol' => 'tcp',
            'local_ip' => '127.0.0.1',
            'local_port' => $vnc_port,
            'remote_ip' => $frp_public_ip,
            'remote_port' => $frp_remote_vnc_port,
            'frp_rule_name' => $frp_vnc_rule,
            'status' => !empty($vnc_frp_result['success']) ? 'active' : 'error',
            'frp_status' => !empty($vnc_frp_result['success']) ? 'online' : 'error',
            'error_msg' => empty($vnc_frp_result['success']) ? ($vnc_frp_result['message'] ?? '') : '',
        ]);
    }

    return [
        'success' => true,
        'host_id' => $host_id,
        'vm' => $result,
        'ip' => $vm_ip,
        'is_nat_kvm' => $is_nat_kvm,
        'frp_ssh_port' => $frp_remote_ssh_port,
        'frp_vnc_port' => $frp_remote_vnc_port,
        'frp_public_ip' => $frp_public_ip,
        'ip_pool' => $ip_pool_result,
    ];
}

// KVM控制操作
function kvm_vm_action($host, $action) {
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];
    if (empty($vm_name)) return ['success' => false, 'message' => '虚拟机名称为空'];

    // 检查是否因流量超限暂停
    if ($action === 'start' && $host['status'] === 'suspended_traffic') {
        return ['success' => false, 'message' => '该服务器因流量超限已暂停，请等待下月流量重置或联系客服购买流量'];
    }

    // 先检查虚拟机是否存在（休眠恢复不需要vm存在）
    if (!in_array($action, ['restore', 'start']) && !$kvm->vmExists($vm_name)) {
        return ['success' => false, 'message' => '虚拟机在 libvirt 中不存在，请重新创建'];
    }

    $ok = false;
    $msg = '';
    $new_state = '';
    $error = $kvm->getError(); // 清除之前的错误
    switch ($action) {
        case 'start': $ok = $kvm->startVM($vm_name); $new_state = 'running'; break;
        case 'stop': $ok = $kvm->stopVM($vm_name); $new_state = 'stopped'; break;
        case 'restart': $ok = $kvm->restartVM($vm_name); $new_state = 'running'; break;
        case 'forcestop': $ok = $kvm->forceStopVM($vm_name); $new_state = 'stopped'; break;
        case 'destroy': $ok = $kvm->destroyVM($vm_name); $new_state = 'destroyed'; break;
        case 'suspend': $ok = $kvm->suspendVM($vm_name); $new_state = 'paused'; break;
        case 'resume': $ok = $kvm->resumeVM($vm_name); $new_state = 'running'; break;
        case 'save':
            $result = $kvm->saveVM($vm_name);
            if ($result && !empty($result['success'])) {
                $ok = true;
                $new_state = 'saved';
            }
            break;
        case 'restore':
            $ok = $kvm->restoreVM($vm_name);
            $new_state = 'running';
            break;
        default: return ['success' => false, 'message' => '未知操作'];
    }
    // 更新状态
    if ($ok) {
        try {
            if ($new_state) {
                Database::update('hosts', ['vm_power_status' => $new_state], 'id = ?', [$host['id']]);
            }
            // 记录操作日志
            @Database::insert('resource_operations', [
                'resource_type' => 'vm',
                'resource_id' => $host['id'],
                'user_id' => $host['user_id'],
                'operation' => $action,
                'status' => 'success',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
        return ['success' => true, 'message' => '操作成功'];
    }
    $err = $kvm->getError();
    return ['success' => false, 'message' => '操作失败: ' . ($err ?: '未知错误')];
}

// 调整虚拟机规格（CPU、内存、磁盘）
function kvm_resize_vm($host, $vcpu = 0, $memory_mb = 0, $disk_gb = 0) {
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];
    if (empty($vm_name)) return ['success' => false, 'message' => '虚拟机名称为空'];

    if (!$kvm->vmExists($vm_name)) {
        return ['success' => false, 'message' => '虚拟机在 libvirt 中不存在'];
    }

    $vcpu = max(0, intval($vcpu));
    $memory_mb = max(0, intval($memory_mb));
    $disk_gb = max(0, intval($disk_gb));

    if ($vcpu <= 0 && $memory_mb <= 0 && $disk_gb <= 0) {
        return ['success' => false, 'message' => '请至少调整一项规格'];
    }

    if ($vcpu > 0 && $vcpu > 64) {
        return ['success' => false, 'message' => 'CPU核心数不能超过64核'];
    }
    if ($memory_mb > 0 && $memory_mb > 262144) {
        return ['success' => false, 'message' => '内存不能超过256GB'];
    }
    if ($disk_gb > 0 && $disk_gb > 2000) {
        return ['success' => false, 'message' => '磁盘不能超过2000GB'];
    }

    $result = $kvm->resizeVM($vm_name, $vcpu, $memory_mb, $disk_gb);
    if ($result['success']) {
        $update_data = [];
        if ($vcpu > 0) $update_data['vcpu'] = $vcpu;
        if ($memory_mb > 0) $update_data['memory_mb'] = $memory_mb;
        if ($disk_gb > 0) $update_data['disk_gb'] = $disk_gb;
        if (!empty($update_data)) {
            try {
                Database::update('hosts', $update_data, 'id = ?', [$host['id']]);
            } catch (Exception $e) {}
        }
    }
    return $result;
}

/**
 * 批量执行KVM操作
 * @param array $hosts 主机数组
 * @param string $action 操作类型
 * @return array ['success' => N, 'failed' => N, 'results' => [...]]
 */
function kvm_batch_action($hosts, $action) {
    $results = ['success' => 0, 'failed' => 0, 'details' => []];
    $valid_actions = ['start', 'stop', 'restart', 'forcestop', 'suspend', 'resume', 'save', 'restore'];
    if (!in_array($action, $valid_actions)) {
        $results['error'] = '不支持的操作';
        return $results;
    }
    foreach ($hosts as $host) {
        try {
            $r = kvm_vm_action($host, $action);
            if (!empty($r['success'])) {
                $results['success']++;
                $results['details'][] = ['id' => $host['id'], 'name' => $host['vm_name'] ?: ('host_'.$host['id']), 'status' => 'success'];
            } else {
                $results['failed']++;
                $results['details'][] = ['id' => $host['id'], 'name' => $host['vm_name'] ?: ('host_'.$host['id']), 'status' => 'failed', 'message' => $r['message'] ?? '失败'];
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['details'][] = ['id' => $host['id'], 'name' => $host['vm_name'] ?: ('host_'.$host['id']), 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }
    return $results;
}

/**
 * 迁移虚拟机到目标节点
 */
function kvm_migrate_vm($host, $target_node_id, $options = []) {
    $target_node = Database::fetch("SELECT * FROM kvm_nodes WHERE id = ?", [$target_node_id]);
    if (!$target_node) {
        return ['success' => false, 'message' => '目标节点不存在'];
    }
    if ($target_node['status'] !== 'online') {
        return ['success' => false, 'message' => '目标节点不在线'];
    }
    $source_node_id = intval($host['kvm_node_id'] ?? 0);
    if ($source_node_id == $target_node_id) {
        return ['success' => false, 'message' => '虚拟机已在目标节点上'];
    }
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];
    if (empty($vm_name)) {
        return ['success' => false, 'message' => '虚拟机名称为空'];
    }
    if (!$kvm->vmExists($vm_name)) {
        return ['success' => false, 'message' => '虚拟机不存在'];
    }

    // 记录迁移开始
    try {
        Database::insert('vm_migrations', [
            'vm_name' => $vm_name,
            'host_id' => $host['id'],
            'source_node_id' => $source_node_id,
            'target_node_id' => $target_node_id,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'ip_before' => $host['ip_address'] ?? '',
        ]);
        $migration_id = Database::lastInsertId();
    } catch (Exception $e) {
        $migration_id = 0;
    }

    $live = !empty($options['live']) ? true : true; // 默认在线热迁移
    $result = $kvm->migrateVM($vm_name, $target_node['node_ip'], $target_node['ssh_user'], [
        'live' => $live,
        'unsafe' => !empty($options['unsafe']),
        'timeout' => intval($options['timeout'] ?? 300),
    ]);

    if (!empty($result['success'])) {
        // 更新主机节点信息
        try {
            Database::update('hosts', [
                'kvm_node_id' => $target_node_id,
            ], 'id = ?', [$host['id']]);

            // 更新节点计数
            if ($source_node_id > 0) {
                Database::query("UPDATE kvm_nodes SET current_vms = GREATEST(0, current_vms - 1) WHERE id = ?", [$source_node_id]);
            }
            Database::query("UPDATE kvm_nodes SET current_vms = current_vms + 1 WHERE id = ?", [$target_node_id]);

            if ($migration_id > 0) {
                Database::update('vm_migrations', [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$migration_id]);
            }
        } catch (Exception $e) {}
        return ['success' => true, 'message' => '迁移成功'];
    } else {
        if ($migration_id > 0) {
            try {
                Database::update('vm_migrations', [
                    'status' => 'failed',
                    'error_message' => $result['message'] ?? '迁移失败',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$migration_id]);
            } catch (Exception $e) {}
        }
        return ['success' => false, 'message' => $result['message'] ?? '迁移失败'];
    }
}

// 获取规格升级价格配置
function resize_get_prices() {
    try {
        $rows = Database::fetchAll("SELECT * FROM resize_prices WHERE status = 'active' ORDER BY resource_type");
        $prices = [];
        foreach ($rows as $row) {
            $prices[$row['resource_type']] = $row;
        }
        return $prices;
    } catch (Exception $e) {
        return [];
    }
}

// 获取单个资源类型的价格配置
function resize_get_price($resource_type) {
    try {
        return Database::fetch("SELECT * FROM resize_prices WHERE resource_type = ? AND status = 'active'", [$resource_type]);
    } catch (Exception $e) {
        return null;
    }
}

// 计算升级费用
function resize_calculate_price($host, $new_vcpu, $new_memory_mb, $new_disk_gb) {
    $prices = resize_get_prices();
    if (empty($prices)) {
        return ['success' => false, 'message' => '未配置升级价格'];
    }

    $old_vcpu = intval($host['vcpu'] ?? 2);
    $old_memory_mb = intval($host['memory_mb'] ?? 2048);
    $old_disk_gb = intval($host['disk_gb'] ?? 40);

    $total_price = 0;
    $details = [];

    if ($new_vcpu > $old_vcpu && isset($prices['cpu'])) {
        $diff = $new_vcpu - $old_vcpu;
        $price = $diff * floatval($prices['cpu']['unit_price']);
        $total_price += $price;
        $details[] = "CPU: {$old_vcpu}核 → {$new_vcpu}核 (+{$diff}核) ¥{$price}";
    } elseif ($new_vcpu < $old_vcpu) {
        return ['success' => false, 'message' => 'CPU不能减少'];
    }

    if ($new_memory_mb > $old_memory_mb && isset($prices['memory'])) {
        $diff_gb = ($new_memory_mb - $old_memory_mb) / 1024;
        $price = $diff_gb * floatval($prices['memory']['unit_price']);
        $total_price += $price;
        $details[] = "内存: {$old_memory_mb}MB → {$new_memory_mb}MB (+{$diff_gb}GB) ¥{$price}";
    } elseif ($new_memory_mb < $old_memory_mb) {
        return ['success' => false, 'message' => '内存不能减少'];
    }

    if ($new_disk_gb > $old_disk_gb && isset($prices['disk'])) {
        $diff = $new_disk_gb - $old_disk_gb;
        $price = $diff * floatval($prices['disk']['unit_price']);
        $total_price += $price;
        $details[] = "磁盘: {$old_disk_gb}GB → {$new_disk_gb}GB (+{$diff}GB) ¥{$price}";
    } elseif ($new_disk_gb < $old_disk_gb) {
        return ['success' => false, 'message' => '磁盘不能减少'];
    }

    if ($total_price <= 0) {
        return ['success' => false, 'message' => '没有需要升级的资源'];
    }

    return [
        'success' => true,
        'total_price' => $total_price,
        'details' => $details,
        'old_vcpu' => $old_vcpu,
        'old_memory_mb' => $old_memory_mb,
        'old_disk_gb' => $old_disk_gb
    ];
}

// 创建升级订单
function resize_create_order($host, $user, $new_vcpu, $new_memory_mb, $new_disk_gb) {
    $calc = resize_calculate_price($host, $new_vcpu, $new_memory_mb, $new_disk_gb);
    if (!$calc['success']) {
        return $calc;
    }

    $order_no = generate_order_no();

    try {
        $id = Database::insert('host_upgrades', [
            'order_no' => $order_no,
            'host_id' => $host['id'],
            'user_id' => $user['id'],
            'old_vcpu' => $calc['old_vcpu'],
            'new_vcpu' => $new_vcpu,
            'old_memory_mb' => $calc['old_memory_mb'],
            'new_memory_mb' => $new_memory_mb,
            'old_disk_gb' => $calc['old_disk_gb'],
            'new_disk_gb' => $new_disk_gb,
            'total_price' => $calc['total_price'],
            'status' => 'pending'
        ]);

        return [
            'success' => true,
            'order_id' => $id,
            'order_no' => $order_no,
            'total_price' => $calc['total_price'],
            'details' => $calc['details']
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '创建订单失败: ' . $e->getMessage()];
    }
}

// 支付升级订单
function resize_pay_order($order_id, $user) {
    try {
        $order = Database::fetch("SELECT * FROM host_upgrades WHERE id = ? AND user_id = ? AND status = 'pending'", [$order_id, $user['id']]);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在或已处理'];
        }

        $balance = floatval($user['balance'] ?? 0);
        if ($balance < floatval($order['total_price'])) {
            return ['success' => false, 'message' => '余额不足，请先充值'];
        }

        Database::beginTransaction();

        $new_balance = $balance - floatval($order['total_price']);
        Database::update('users', ['balance' => $new_balance], 'id = ?', [$user['id']]);

        Database::update('host_upgrades', [
            'status' => 'paid',
            'pay_time' => date('Y-m-d H:i:s')
        ], 'id = ?', [$order_id]);

        Database::commit();

        return ['success' => true, 'message' => '支付成功'];
    } catch (Exception $e) {
        Database::rollBack();
        return ['success' => false, 'message' => '支付失败: ' . $e->getMessage()];
    }
}

// 执行升级操作
function resize_execute_order($order_id) {
    try {
        $order = Database::fetch("SELECT * FROM host_upgrades WHERE id = ? AND status = 'paid'", [$order_id]);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在或状态不正确'];
        }

        $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$order['host_id']]);
        if (!$host) {
            return ['success' => false, 'message' => '主机不存在'];
        }

        $vcpu = $order['new_vcpu'] > $order['old_vcpu'] ? $order['new_vcpu'] : 0;
        $memory_mb = $order['new_memory_mb'] > $order['old_memory_mb'] ? $order['new_memory_mb'] : 0;
        $disk_gb = $order['new_disk_gb'] > $order['old_disk_gb'] ? $order['new_disk_gb'] : 0;

        $result = kvm_resize_vm($host, $vcpu, $memory_mb, $disk_gb);

        if ($result['success']) {
            Database::update('host_upgrades', [
                'status' => 'completed',
                'complete_time' => date('Y-m-d H:i:s')
            ], 'id = ?', [$order_id]);
        } else {
            Database::update('host_upgrades', [
                'status' => 'failed',
                'fail_reason' => $result['message']
            ], 'id = ?', [$order_id]);
        }

        return $result;
    } catch (Exception $e) {
        Database::update('host_upgrades', [
            'status' => 'failed',
            'fail_reason' => '执行失败: ' . $e->getMessage()
        ], 'id = ?', [$order_id]);
        return ['success' => false, 'message' => '执行失败: ' . $e->getMessage()];
    }
}

// 获取主机升级历史
function resize_get_history($host_id) {
    try {
        return Database::fetchAll("SELECT * FROM host_upgrades WHERE host_id = ? ORDER BY created_at DESC", [$host_id]);
    } catch (Exception $e) {
        return [];
    }
}

// 检查虚拟机是否存在（支持多节点）
function kvm_vm_exists($vm_name, $node_id = 0) {
    $kvm = kvm_get_manager_for_node($node_id);
    return $kvm->vmExists($vm_name);
}

// 重新创建虚拟机（用于虚拟机丢失的情况）
function kvm_recreate_vm($host) {
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];
    if (empty($vm_name)) return ['success' => false, 'message' => '虚拟机名称为空'];

    $image_id = intval($host['image_id'] ?? 0);
    if ($image_id <= 0) return ['success' => false, 'message' => '缺少镜像ID，无法重新创建'];

    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
    if (!$image) return ['success' => false, 'message' => '镜像不存在'];

    $vcpu = intval($host['vcpu'] ?? 2);
    $memory_mb = intval($host['memory_mb'] ?? 2048);
    $disk_gb = intval($host['disk_gb'] ?? 40);

    $iso_filename = basename($image['iso_path']);
    $full_iso_path = rtrim($kvm->getStoragePool(), '/') . '/iso/' . $iso_filename;

    $result = $kvm->createVM(
        $vm_name,
        $vcpu,
        $memory_mb,
        $disk_gb,
        $full_iso_path,
        $image['os_type']
    );

    if (!$result['success']) {
        return ['success' => false, 'message' => '创建失败: ' . ($result['message'] ?? $kvm->getError())];
    }

    // 更新数据库
    Database::update('hosts', [
        'vm_uuid' => $result['uuid'],
        'ip_address' => $result['ip_address'],
        'vnc_port' => $result['vnc_port'],
        'ssh_port' => $result['ssh_port'],
        'root_password' => $result['root_password'],
        'vm_power_status' => 'running',
        'vm_created_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$host['id']]);

    return ['success' => true, 'message' => '虚拟机已重新创建', 'ip' => $result['ip_address']];
}

// 重装系统
function kvm_reinstall($host, $image_id) {
    $kvm = kvm_get_manager_for_host($host);
    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
    if (!$image) return ['success' => false, 'message' => '镜像不存在'];
    if (empty($host['vm_name'])) return ['success' => false, 'message' => '无法找到VM名'];
    
    // 获取磁盘格式和预装镜像
    $disk_type = $image['disk_type'] ?? 'qcow2';
    $preinstalled_image = $image['preinstalled_image'] ?? '';
    
    // 构建完整ISO路径（与kvm_create_vm一致）
    $iso_filename = basename($image['iso_path']);
    $full_iso_path = rtrim($kvm->getStoragePool(), '/') . '/iso/' . $iso_filename;
    
    // 验证ISO文件是否存在
    if (!empty($image['iso_path']) && !file_exists($full_iso_path)) {
        // 如果完整路径不存在，尝试用原始路径
        if (file_exists($image['iso_path'])) {
            $full_iso_path = $image['iso_path'];
        }
    }
    
    $ok = $kvm->reinstallVM($host['vm_name'], $full_iso_path, $host['disk_gb'], $disk_type, $preinstalled_image, $host['root_password']);
    if ($ok) {
        Database::update('hosts', [
            'image_id' => $image_id,
            'vm_power_status' => 'running',
            'vm_last_sync' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$host['id']]);
        return ['success' => true, 'message' => '系统重装已启动，将从新镜像引导'];
    }
    return ['success' => false, 'message' => '重装失败: ' . $kvm->getError()];
}

// 修改 root 密码（优先通过 virsh 命令，失败则保存数据库）
function kvm_change_password($host, $new_password) {
    if (empty($host['vm_name'])) {
        return ['success' => false, 'message' => '无法找到VM名'];
    }
    
    $kvm = kvm_get_manager_for_host($host);
    $vm_name = $host['vm_name'];

    // 1. 检查虚拟机是否在运行
    $state = $kvm->getVMPowerState($vm_name);
    if ($state !== 'running') {
        // 虚拟机未运行，只保存到数据库
        Database::update('hosts', ['root_password' => $new_password], 'id = ?', [$host['id']]);
        return [
            'success' => true, 
            'message' => '密码已保存到数据库，虚拟机开机后密码将自动生效',
            'vm_state' => $state
        ];
    }
    
    // 2. 获取镜像信息确定用户名
    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$host['image_id']]);
    $username = !empty($image['default_username']) ? $image['default_username'] : 'root';
    
    // 3. 尝试通过 virsh set-user-password 直接修改密码
    $result = $kvm->exec("virsh set-user-password " . escapeshellarg($vm_name) . " " . escapeshellarg($username) . " " . escapeshellarg($new_password) . " 2>&1");
    
    if (strpos($result, 'error') === false && strpos($result, 'failed') === false) {
        // 密码修改成功
        Database::update('hosts', ['root_password' => $new_password], 'id = ?', [$host['id']]);
        return [
            'success' => true, 
            'message' => '密码修改成功！已直接写入虚拟机，立即生效',
            'direct_change' => true
        ];
    }
    
    // 4. virsh 方法失败，尝试通过 cloud-init 并重启使密码生效
    $cloud_init_iso = $kvm->createCloudInitISO($vm_name, $new_password);
    $restart_vm = false;
    if (!empty($cloud_init_iso)) {
        // 先尝试分离旧的 cloud-init 磁盘（如果存在）
        $kvm->exec("virsh detach-disk " . escapeshellarg($vm_name) . " hdb --persistent 2>&1");
        // 附加新的 cloud-init CD-ROM
        $attach_result = $kvm->exec("virsh attach-disk " . escapeshellarg($vm_name) . " " . escapeshellarg($cloud_init_iso) . " hdb --type cdrom --mode readonly --persistent 2>&1");
        if (strpos($attach_result, 'error') === false && strpos($attach_result, 'failed') === false) {
            $restart_vm = true;
        }
    }
    
    // 5. 保存到数据库
    Database::update('hosts', ['root_password' => $new_password], 'id = ?', [$host['id']]);
    
    if ($restart_vm) {
        // 自动重启虚拟机让 cloud-init 密码生效
        $kvm->exec("virsh reboot " . escapeshellarg($vm_name) . " 2>&1");
        return [
            'success' => true, 
            'message' => '密码已保存，正在重启虚拟机使密码生效。请等待约30秒后使用新密码连接。',
            'direct_change' => false,
            'reboot' => true
        ];
    }
    
    return [
        'success' => true, 
        'message' => '密码已保存到数据库，请手动重启虚拟机使密码生效',
        'direct_change' => false
    ];
}

// 刷新VM状态和IP
function kvm_refresh_status($host) {
    $kvm = kvm_get_manager_for_host($host);
    if (empty($host['vm_name'])) return ['success' => false, 'message' => '无VM名称'];
    $state = $kvm->getVMPowerState($host['vm_name']);
    $ip = $kvm->getVMIP($host['vm_name']);
    Database::update('hosts', [
        'vm_power_status' => $state,
        'ip_address' => $ip,
        'vm_last_sync' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$host['id']]);
    return ['success' => true, 'state' => $state, 'ip' => $ip];
}

// ======================== VM同步检查函数 ========================

/**
 * 检查数据库中的KVM虚拟机与libvirt实际虚拟机的同步状态
 * @return array ['success' => bool, 'data' => [...], 'missing' => [...], 'orphan' => [...], 'db_count' => int, 'libvirt_count' => int]
 */
function kvm_sync_check() {
    $kvm = kvm_get_manager();
    
    // 获取数据库中所有KVM虚拟机
    $db_vms = Database::fetchAll(
        "SELECT id, user_id, vm_name, vnc_port, status, vm_power_status, ip_address 
         FROM hosts 
         WHERE vm_name IS NOT NULL AND vm_name != '' AND status != 'cancelled'
         ORDER BY id ASC"
    );
    
    // 获取libvirt中所有虚拟机
    $libvirt_vms_raw = $kvm->listVMs();
    $libvirt_vms = [];
    if ($libvirt_vms_raw) {
        $lines = explode("\n", $libvirt_vms_raw);
        foreach ($lines as $line) {
            // 解析 virsh list --all 输出格式: " Id    Name                           State"
            if (preg_match('/^\s*(\d+|-)?\s+(\S+)\s+(\S+)/', trim($line), $m)) {
                $vm_name = $m[2];
                $vm_state = $m[3];
                if ($vm_name && $vm_name !== 'Name' && $vm_name !== 'Id') {
                    $libvirt_vms[$vm_name] = [
                        'name' => $vm_name,
                        'state' => $vm_state,
                        'libvirt_id' => $m[1] ?? '-'
                    ];
                }
            }
        }
    }
    
    $sync_data = [];
    $missing_vms = [];  // 数据库有但libvirt没有
    $orphan_vms = [];   // libvirt有但数据库没有
    
    foreach ($db_vms as $db_vm) {
        $vm_name = $db_vm['vm_name'];
        $exists_in_libvirt = isset($libvirt_vms[$vm_name]);
        
        $sync_item = [
            'id' => $db_vm['id'],
            'user_id' => $db_vm['user_id'],
            'vm_name' => $vm_name,
            'db_status' => $db_vm['status'],
            'db_power' => $db_vm['vm_power_status'],
            'db_vnc_port' => $db_vm['vnc_port'],
            'db_ip' => $db_vm['ip_address'],
            'libvirt_exists' => $exists_in_libvirt,
            'libvirt_state' => $exists_in_libvirt ? $libvirt_vms[$vm_name]['state'] : 'missing',
            'sync_status' => $exists_in_libvirt ? 'ok' : 'missing',
        ];
        $sync_data[] = $sync_item;
        
        if (!$exists_in_libvirt) {
            $missing_vms[] = $sync_item;
        }
        
        // 从libvirt列表中移除已匹配的
        unset($libvirt_vms[$vm_name]);
    }
    
    // 剩余的libvirt虚拟机是孤儿虚拟机
    foreach ($libvirt_vms as $vm) {
        if ($vm['name'] && strpos($vm['name'], 'vm_') === 0) {
            $orphan_vms[] = $vm;
        }
    }
    
    return [
        'success' => true,
        'data' => $sync_data,
        'missing' => $missing_vms,
        'orphan' => $orphan_vms,
        'db_count' => count($db_vms),
        'libvirt_count' => count($sync_data) - count($missing_vms),
    ];
}

/**
 * 批量清理数据库中不存在于libvirt的虚拟机记录
 * @param array $host_ids 要清理的主机ID列表
 * @return array ['success' => bool, 'cleaned' => int, 'message' => string]
 */
function kvm_sync_cleanup($host_ids) {
    $cleaned = 0;
    $errors = [];
    
    foreach ($host_ids as $hid) {
        $hid = intval($hid);
        if ($hid <= 0) continue;
        
        $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$hid]);
        if (!$host) {
            $errors[] = "主机ID $hid 不存在";
            continue;
        }
        
        // 清理相关数据
        try {
            // 删除快照记录
            Database::query("DELETE FROM vm_snapshots WHERE host_id = ?", [$hid]);
            
            // 删除防火墙规则
            Database::query("DELETE FROM firewall_rules WHERE host_id = ?", [$hid]);
            
            // 删除NAT规则
            Database::query("DELETE FROM nat_rules WHERE host_id = ?", [$hid]);
            
            // 更新主机状态为已取消
            Database::update('hosts', [
                'status' => 'cancelled',
                'vm_power_status' => 'missing',
                'ip_address' => '',
            ], 'id = ?', [$hid]);
            
            $cleaned++;
        } catch (Exception $e) {
            $errors[] = "清理主机ID $hid 失败: " . $e->getMessage();
        }
    }
    
    return [
        'success' => $cleaned > 0,
        'cleaned' => $cleaned,
        'errors' => $errors,
        'message' => "已清理 $cleaned 条记录" . (count($errors) > 0 ? "，错误: " . implode('; ', $errors) : ''),
    ];
}

/**
 * 清理VNC token文件中过期的token
 * @return array ['success' => bool, 'cleaned' => int, 'message' => string]
 */
function kvm_cleanup_tokens() {
    $token_file = dirname(__DIR__) . '/novnc/tokens/tokens.conf';
    
    if (!file_exists($token_file)) {
        return ['success' => true, 'cleaned' => 0, 'message' => 'Token文件不存在'];
    }
    
    $content = file_get_contents($token_file);
    $lines = array_filter(explode("\n", $content));
    
    // 获取当前存在的虚拟机VNC端口
    $kvm = kvm_get_manager();
    $valid_ports = [];
    
    $db_vms = Database::fetchAll(
        "SELECT vnc_port FROM hosts WHERE vm_name IS NOT NULL AND vm_name != '' AND status = 'running'"
    );
    foreach ($db_vms as $vm) {
        if (!empty($vm['vnc_port'])) {
            $valid_ports[] = intval($vm['vnc_port']);
        }
    }
    
    // 从libvirt获取实际运行的虚拟机VNC端口
    $libvirt_vms_raw = $kvm->listVMs();
    if ($libvirt_vms_raw) {
        $lines_vm = explode("\n", $libvirt_vms_raw);
        foreach ($lines_vm as $line) {
            if (preg_match('/running/', $line)) {
                // 尝试获取VNC端口
                $vm_name = preg_replace('/^\s*(\d+)?\s+(\S+)\s+/', '', trim($line));
                if ($vm_name) {
                    $vnc_display = $kvm->exec("virsh vncdisplay " . escapeshellarg($vm_name) . " 2>/dev/null");
                    if ($vnc_display && preg_match('/: (\d+)/', $vnc_display, $m)) {
                        $valid_ports[] = 5900 + intval($m[1]);
                    }
                }
            }
        }
    }
    
    $valid_ports = array_unique($valid_ports);
    
    // 过滤token，只保留有效端口
    $new_lines = [];
    $cleaned = 0;
    foreach ($lines as $line) {
        if (preg_match('/^[a-f0-9]+:\s+127\.0\.0\.1:(\d+)/', trim($line), $m)) {
            $port = intval($m[1]);
            if (in_array($port, $valid_ports)) {
                $new_lines[] = trim($line);
            } else {
                $cleaned++;
            }
        }
    }
    
    // 写入清理后的内容
    file_put_contents($token_file, implode("\n", $new_lines) . "\n");
    
    return [
        'success' => true,
        'cleaned' => $cleaned,
        'message' => "已清理 $cleaned 个过期token",
        'valid_ports' => $valid_ports,
    ];
}

// ======================== IP池管理函数 ========================

function ip2long_safe($ip) {
    $long = ip2long($ip);
    if ($long === false) return 0;
    return sprintf('%u', $long);
}

function long2ip_safe($long) {
    return long2ip($long);
}

function ip_pool_get_list($active_only = false) {
    $sql = "SELECT * FROM ip_pools";
    if ($active_only) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY id DESC";
    return Database::fetchAll($sql);
}

function ip_pool_get($id) {
    return Database::fetch("SELECT * FROM ip_pools WHERE id = ?", [$id]);
}

function ip_pool_create($data) {
    $pool_name = trim($data['pool_name'] ?? '');
    $pool_type = $data['pool_type'] ?? 'dedicated';
    $public_ip = trim($data['public_ip'] ?? '');
    $ip_start = trim($data['ip_start'] ?? '');
    $ip_end = trim($data['ip_end'] ?? '');
    $gateway = trim($data['gateway'] ?? '');
    $netmask = trim($data['netmask'] ?? '255.255.255.0');
    $description = trim($data['description'] ?? '');

    if (empty($pool_name)) {
        return ['success' => false, 'message' => '请填写IP池名称'];
    }

    if (!in_array($pool_type, ['dedicated', 'nat'])) {
        $pool_type = 'dedicated';
    }

    if ($pool_type === 'dedicated') {
        if (empty($ip_start) || empty($ip_end)) {
            return ['success' => false, 'message' => '请填写IP范围'];
        }

        $start_long = ip2long_safe($ip_start);
        $end_long = ip2long_safe($ip_end);
        if ($start_long == 0 || $end_long == 0 || $start_long > $end_long) {
            return ['success' => false, 'message' => 'IP地址范围无效'];
        }

        $total_count = $end_long - $start_long + 1;
        if ($total_count > 65536) {
            return ['success' => false, 'message' => 'IP数量不能超过65536个'];
        }
    } else {
        if (empty($public_ip)) {
            return ['success' => false, 'message' => '请填写公网IP地址'];
        }
        $total_count = 0;
        $ip_start = '';
        $ip_end = '';
    }

    $pool_id = Database::insert('ip_pools', [
        'pool_name' => $pool_name,
        'pool_type' => $pool_type,
        'public_ip' => $public_ip,
        'ip_start' => $ip_start,
        'ip_end' => $ip_end,
        'gateway' => $gateway,
        'netmask' => $netmask,
        'total_count' => $total_count,
        'used_count' => 0,
        'status' => 'active',
        'description' => $description,
    ]);

    return ['success' => true, 'id' => $pool_id];
}

function ip_pool_update($id, $data) {
    $pool = ip_pool_get($id);
    if (!$pool) return ['success' => false, 'message' => 'IP池不存在'];

    $update = [];
    if (isset($data['pool_name'])) $update['pool_name'] = trim($data['pool_name']);
    if (isset($data['pool_type'])) $update['pool_type'] = $data['pool_type'];
    if (isset($data['public_ip'])) $update['public_ip'] = trim($data['public_ip']);
    if (isset($data['gateway'])) $update['gateway'] = trim($data['gateway']);
    if (isset($data['netmask'])) $update['netmask'] = trim($data['netmask']);
    if (isset($data['description'])) $update['description'] = trim($data['description']);
    if (isset($data['status'])) $update['status'] = $data['status'];

    if (empty($update)) return ['success' => true];

    Database::update('ip_pools', $update, 'id = ?', [$id]);
    return ['success' => true];
}

function ip_pool_delete($id) {
    $pool = ip_pool_get($id);
    if (!$pool) return ['success' => false, 'message' => 'IP池不存在'];

    $used = Database::fetch("SELECT COUNT(*) as c FROM ip_assignments WHERE pool_id = ? AND status = 'assigned'", [$id]);
    if ($used && intval($used['c']) > 0) {
        return ['success' => false, 'message' => 'IP池中有已分配的IP，无法删除'];
    }

    Database::query("DELETE FROM ip_pools WHERE id = ?", [$id]);
    Database::query("DELETE FROM ip_assignments WHERE pool_id = ?", [$id]);
    return ['success' => true];
}

function ip_pool_allocate($host_id, $user_id, $pool_id = 0, $pool_type = 'dedicated') {
    $sql = "SELECT * FROM ip_pools WHERE status = 'active'";
    if ($pool_id > 0) {
        $sql .= " AND id = $pool_id";
    } else {
        $sql .= " AND pool_type = '" . ($pool_type === 'nat' ? 'nat' : 'dedicated') . "'";
    }
    $sql .= " ORDER BY id ASC LIMIT 1";
    $pool = Database::fetch($sql);
    if (!$pool) {
        return ['success' => false, 'message' => '没有可用的' . ($pool_type === 'nat' ? 'NAT共享' : '') . 'IP池'];
    }

    $pool_id = intval($pool['id']);
    $start_long = ip2long_safe($pool['ip_start']);
    $end_long = ip2long_safe($pool['ip_end']);

    $assigned_ips = Database::fetchAll("SELECT ip_address FROM ip_assignments WHERE pool_id = ?", [$pool_id]);
    $assigned_map = [];
    foreach ($assigned_ips as $a) {
        $assigned_map[ip2long_safe($a['ip_address'])] = true;
    }

    $allocated_ip = '';
    for ($long = $start_long; $long <= $end_long; $long++) {
        if (!isset($assigned_map[$long])) {
            $allocated_ip = long2ip_safe($long);
            break;
        }
    }

    if (empty($allocated_ip)) {
        return ['success' => false, 'message' => 'IP池已用尽'];
    }

    Database::insert('ip_assignments', [
        'pool_id' => $pool_id,
        'ip_address' => $allocated_ip,
        'host_id' => $host_id,
        'user_id' => $user_id,
        'status' => 'assigned',
        'assigned_at' => date('Y-m-d H:i:s'),
    ]);

    Database::query("UPDATE ip_pools SET used_count = used_count + 1 WHERE id = ?", [$pool_id]);

    return [
        'success' => true,
        'ip' => $allocated_ip,
        'pool_id' => $pool_id,
        'pool' => $pool,
        'pool_type' => $pool['pool_type'] ?? 'dedicated',
        'public_ip' => $pool['public_ip'] ?? '',
    ];
}

function ip_pool_release($host_id) {
    $assignments = Database::fetchAll("SELECT * FROM ip_assignments WHERE host_id = ? AND status = 'assigned'", [$host_id]);
    if (empty($assignments)) return ['success' => true, 'released' => 0];

    $count = 0;
    foreach ($assignments as $a) {
        Database::update('ip_assignments', [
            'status' => 'available',
            'host_id' => 0,
            'user_id' => 0,
            'released_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$a['id']]);
        Database::query("UPDATE ip_pools SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?", [$a['pool_id']]);
        $count++;
    }

    return ['success' => true, 'released' => $count];
}

function ip_get_assigned($host_id) {
    return Database::fetchAll("SELECT a.*, p.pool_name FROM ip_assignments a LEFT JOIN ip_pools p ON a.pool_id = p.id WHERE a.host_id = ? ORDER BY a.id DESC", [$host_id]);
}

// ======================== NAT规则管理函数 ========================

function nat_get_rules($host_id) {
    return Database::fetchAll("SELECT * FROM nat_rules WHERE host_id = ? ORDER BY id DESC", [$host_id]);
}

function nat_get_rule($id, $user_id = 0) {
    $sql = "SELECT * FROM nat_rules WHERE id = ?";
    $params = [$id];
    if ($user_id > 0) {
        $sql .= " AND user_id = ?";
        $params[] = $user_id;
    }
    return Database::fetch($sql, $params);
}

function nat_add_rule($host_id, $user_id, $data) {
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $user_id]);
    if (!$host) {
        return ['success' => false, 'message' => '主机不存在'];
    }

    $rule_name = trim($data['rule_name'] ?? '');
    $protocol = $data['protocol'] ?? 'tcp';
    $local_ip = trim($data['local_ip'] ?? '');
    $local_port = intval($data['local_port'] ?? 0);
    $remote_port = intval($data['remote_port'] ?? 0);

    if (empty($rule_name)) {
        $rule_name = 'nat_' . $host_id . '_' . time() . '_' . rand(100, 999);
    }

    if (empty($local_ip)) {
        $local_ip = $host['ip_address'] ?? '';
    }

    if (empty($local_ip) || $local_port <= 0) {
        return ['success' => false, 'message' => '请填写内网IP和端口'];
    }

    if (!in_array($protocol, ['tcp', 'udp'])) {
        $protocol = 'tcp';
    }

    $frp_cfg = config('frp');
    if (empty($frp_cfg['enabled'])) {
        return ['success' => false, 'message' => 'FRP未启用'];
    }

    if ($remote_port <= 0) {
        $remote_port = frp_find_available_port(rand(10000, 50000));
    }

    $frp_rule_name = 'nat_' . $host_id . '_' . $local_port . '_' . $remote_port;

    $frp_result = frp_add_proxy($frp_rule_name, $protocol, $local_ip, $local_port, $remote_port);
    if (!$frp_result['success']) {
        Database::insert('nat_rules', [
            'host_id' => $host_id,
            'user_id' => $user_id,
            'rule_name' => $rule_name,
            'protocol' => $protocol,
            'local_ip' => $local_ip,
            'local_port' => $local_port,
            'remote_ip' => $frp_cfg['public_domain'] ?? $frp_cfg['server_addr'] ?? '',
            'remote_port' => $remote_port,
            'frp_rule_name' => $frp_rule_name,
            'status' => 'error',
            'error_msg' => $frp_result['message'] ?? 'FRP添加失败',
        ]);
        return ['success' => false, 'message' => '添加FRP规则失败: ' . ($frp_result['message'] ?? '未知错误')];
    }

    $remote_ip = $frp_cfg['public_domain'] ?? $frp_cfg['server_addr'] ?? '';

    $nat_id = Database::insert('nat_rules', [
        'host_id' => $host_id,
        'user_id' => $user_id,
        'rule_name' => $rule_name,
        'protocol' => $protocol,
        'local_ip' => $local_ip,
        'local_port' => $local_port,
        'remote_ip' => $remote_ip,
        'remote_port' => $remote_port,
        'frp_rule_name' => $frp_rule_name,
        'status' => 'active',
        'frp_status' => 'online',
    ]);

    return [
        'success' => true,
        'id' => $nat_id,
        'remote_port' => $remote_port,
        'remote_addr' => $remote_ip . ':' . $remote_port,
        'frp' => $frp_result,
    ];
}

function nat_delete_rule($id, $user_id = 0) {
    $rule = nat_get_rule($id, $user_id);
    if (!$rule) {
        return ['success' => false, 'message' => '规则不存在'];
    }

    if (!empty($rule['frp_rule_name'])) {
        $frp_result = frp_delete_proxy($rule['frp_rule_name']);
    }

    Database::query("DELETE FROM nat_rules WHERE id = ?", [$id]);

    return ['success' => true, 'frp' => $frp_result ?? null];
}

function nat_refresh_status($id, $user_id = 0) {
    $rule = nat_get_rule($id, $user_id);
    if (!$rule) {
        return ['success' => false, 'message' => '规则不存在'];
    }

    if (empty($rule['frp_rule_name'])) {
        return ['success' => true, 'status' => 'unknown'];
    }

    $proxy = frp_get_proxy_status_by_name($rule['frp_rule_name']);
    $frp_status = $proxy['status'] ?? 'offline';

    Database::update('nat_rules', [
        'frp_status' => $frp_status,
    ], 'id = ?', [$id]);

    return ['success' => true, 'frp_status' => $frp_status, 'proxy' => $proxy];
}

// ======================== KVM主机清理函数 ========================

function kvm_cleanup_host($host_id) {
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$host_id]);
    if (!$host) {
        return ['success' => false, 'message' => '主机不存在'];
    }

    $results = [
        'ip_released' => false,
        'nat_rules_deleted' => 0,
        'frp_proxies_deleted' => 0,
    ];

    // 1. 释放IP池分配的IP
    $ip_result = ip_pool_release($host_id);
    if ($ip_result['success']) {
        $results['ip_released'] = true;
        $results['ip_count'] = $ip_result['released'] ?? 0;
    }

    // 2. 清理NAT规则和对应的FRP代理
    $nat_rules = nat_get_rules($host_id);
    foreach ($nat_rules as $rule) {
        if (!empty($rule['frp_rule_name'])) {
            $frp_result = frp_delete_proxy($rule['frp_rule_name']);
            if ($frp_result['success']) {
                $results['frp_proxies_deleted']++;
            }
        }
        $results['nat_rules_deleted']++;
    }

    // 3. 额外检查并清理可能存在的SSH和VNC默认FRP规则
    $default_rules = [
        'kvm_ssh_' . $host_id,
        'kvm_vnc_' . $host_id,
    ];
    foreach ($default_rules as $rule_name) {
        $frp_result = frp_delete_proxy($rule_name);
        if ($frp_result['success'] && empty($frp_result['skipped'])) {
            $results['frp_proxies_deleted']++;
        }
    }

    // 4. 删除NAT规则记录（数据库外键会自动处理，但这里显式清理）
    try {
        Database::query("DELETE FROM nat_rules WHERE host_id = ?", [$host_id]);
    } catch (Exception $e) {}

    return ['success' => true, 'results' => $results];
}

// ======================== 服务器资源监控函数 ========================

function server_get_cpu_usage() {
    if (!function_exists('shell_exec')) {
        return ['success' => false, 'usage' => 0, 'message' => 'shell_exec 不可用'];
    }

    $os = strtoupper(substr(PHP_OS, 0, 3));
    if ($os === 'WIN') {
        $cmd = 'wmic cpu get loadpercentage /value 2>NUL';
        $output = @shell_exec($cmd);
        preg_match('/LoadPercentage\s*=\s*(\d+)/i', $output, $m);
        $usage = isset($m[1]) ? floatval($m[1]) : 0;
        return ['success' => true, 'usage' => $usage, 'cores' => 1];
    }

    $stat1 = @file_get_contents('/proc/stat');
    if (!$stat1) {
        $output = @shell_exec('cat /proc/stat 2>/dev/null');
        $stat1 = $output ?: '';
    }
    usleep(200000);
    $stat2 = @file_get_contents('/proc/stat');
    if (!$stat2) {
        $output = @shell_exec('cat /proc/stat 2>/dev/null');
        $stat2 = $output ?: '';
    }

    if (!$stat1 || !$stat2) {
        return ['success' => false, 'usage' => 0, 'message' => '无法读取CPU信息'];
    }

    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat1, $m1);
    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat2, $m2);

    if (!$m1 || !$m2) {
        return ['success' => false, 'usage' => 0, 'message' => 'CPU信息解析失败'];
    }

    $total1 = array_sum(array_slice($m1, 1));
    $total2 = array_sum(array_slice($m2, 1));
    $idle1 = $m1[4] + $m1[5];
    $idle2 = $m2[4] + $m2[5];

    $total_diff = $total2 - $total1;
    $idle_diff = $idle2 - $idle1;

    $usage = $total_diff > 0 ? round(100 - ($idle_diff / $total_diff * 100), 1) : 0;

    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    $cores = $cpuinfo ? substr_count($cpuinfo, 'processor') : 1;

    return ['success' => true, 'usage' => floatval($usage), 'cores' => $cores];
}

function server_get_memory_usage() {
    if (!function_exists('shell_exec')) {
        return ['success' => false, 'total' => 0, 'used' => 0, 'free' => 0, 'usage' => 0, 'message' => 'shell_exec 不可用'];
    }

    $os = strtoupper(substr(PHP_OS, 0, 3));
    if ($os === 'WIN') {
        $output = @shell_exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>NUL');
        preg_match('/TotalVisibleMemorySize\s*=\s*(\d+)/i', $output, $total_m);
        preg_match('/FreePhysicalMemory\s*=\s*(\d+)/i', $output, $free_m);
        $total_kb = isset($total_m[1]) ? floatval($total_m[1]) : 0;
        $free_kb = isset($free_m[1]) ? floatval($free_m[1]) : 0;
        $used_kb = $total_kb - $free_kb;
        $usage = $total_kb > 0 ? round($used_kb / $total_kb * 100, 1) : 0;
        return [
            'success' => true,
            'total' => round($total_kb / 1024 / 1024, 2),
            'used' => round($used_kb / 1024 / 1024, 2),
            'free' => round($free_kb / 1024 / 1024, 2),
            'usage' => floatval($usage),
            'unit' => 'GB'
        ];
    }

    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) {
        $output = @shell_exec('cat /proc/meminfo 2>/dev/null');
        $meminfo = $output ?: '';
    }
    if (!$meminfo) {
        return ['success' => false, 'total' => 0, 'used' => 0, 'free' => 0, 'usage' => 0, 'message' => '无法读取内存信息'];
    }

    preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $total_m);
    preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $avail_m);
    preg_match('/MemFree:\s+(\d+)/i', $meminfo, $free_m);
    preg_match('/Buffers:\s+(\d+)/i', $meminfo, $buf_m);
    preg_match('/Cached:\s+(\d+)/i', $meminfo, $cache_m);

    $total_kb = isset($total_m[1]) ? floatval($total_m[1]) : 0;
    $available_kb = isset($avail_m[1]) ? floatval($avail_m[1]) : 0;

    if ($available_kb == 0) {
        $free_kb = isset($free_m[1]) ? floatval($free_m[1]) : 0;
        $buffers_kb = isset($buf_m[1]) ? floatval($buf_m[1]) : 0;
        $cached_kb = isset($cache_m[1]) ? floatval($cache_m[1]) : 0;
        $available_kb = $free_kb + $buffers_kb + $cached_kb;
    }

    $used_kb = $total_kb - $available_kb;
    $usage = $total_kb > 0 ? round($used_kb / $total_kb * 100, 1) : 0;

    return [
        'success' => true,
        'total' => round($total_kb / 1024 / 1024, 2),
        'used' => round($used_kb / 1024 / 1024, 2),
        'free' => round($available_kb / 1024 / 1024, 2),
        'usage' => floatval($usage),
        'unit' => 'GB'
    ];
}

function server_get_disk_usage($path = '/') {
    if (!function_exists('disk_total_space')) {
        return ['success' => false, 'total' => 0, 'used' => 0, 'free' => 0, 'usage' => 0, 'message' => 'disk_total_space 不可用'];
    }

    $total = @disk_total_space($path);
    $free = @disk_free_space($path);

    if (!$total || !$free) {
        return ['success' => false, 'total' => 0, 'used' => 0, 'free' => 0, 'usage' => 0, 'message' => '无法读取磁盘信息'];
    }

    $used = $total - $free;
    $usage = round($used / $total * 100, 1);

    return [
        'success' => true,
        'total' => round($total / 1024 / 1024 / 1024, 2),
        'used' => round($used / 1024 / 1024 / 1024, 2),
        'free' => round($free / 1024 / 1024 / 1024, 2),
        'usage' => floatval($usage),
        'unit' => 'GB',
        'path' => $path
    ];
}

function server_get_network_usage() {
    if (!function_exists('shell_exec')) {
        return ['success' => false, 'rx' => 0, 'tx' => 0, 'rx_speed' => 0, 'tx_speed' => 0, 'message' => 'shell_exec 不可用'];
    }

    $os = strtoupper(substr(PHP_OS, 0, 3));
    if ($os === 'WIN') {
        return ['success' => false, 'rx' => 0, 'tx' => 0, 'rx_speed' => 0, 'tx_speed' => 0, 'message' => 'Windows暂不支持'];
    }

    $netdev = @file_get_contents('/proc/net/dev');
    if (!$netdev) {
        $output = @shell_exec('cat /proc/net/dev 2>/dev/null');
        $netdev = $output ?: '';
    }
    if (!$netdev) {
        return ['success' => false, 'rx' => 0, 'tx' => 0, 'rx_speed' => 0, 'tx_speed' => 0, 'message' => '无法读取网络信息'];
    }

    $lines = explode("\n", $netdev);
    $total_rx = 0;
    $total_tx = 0;
    $interface = '';

    foreach ($lines as $line) {
        if (strpos($line, ':') === false) continue;
        if (preg_match('/(lo|docker|veth|br-|virbr)/', $line)) continue;

        $parts = preg_split('/[\s:]+/', trim($line));
        if (count($parts) < 10) continue;

        $iface = $parts[0];
        $rx = floatval($parts[1]);
        $tx = floatval($parts[9]);

        if ($rx > 0 || $tx > 0) {
            $total_rx += $rx;
            $total_tx += $tx;
            if (empty($interface)) $interface = $iface;
        }
    }

    $tx_speed = 0;
    $rx_speed = 0;

    $cache_file = sys_get_temp_dir() . '/server_net_stats.json';
    $now = time();
    $old_data = null;

    if (file_exists($cache_file)) {
        $old_content = @file_get_contents($cache_file);
        if ($old_content) {
            $old_data = json_decode($old_content, true);
        }
    }

    if ($old_data && isset($old_data['time']) && ($now - $old_data['time']) > 0) {
        $time_diff = $now - $old_data['time'];
        if ($time_diff > 0 && $time_diff < 300) {
            $rx_diff = max(0, $total_rx - ($old_data['rx'] ?? 0));
            $tx_diff = max(0, $total_tx - ($old_data['tx'] ?? 0));
            $rx_speed = round($rx_diff / $time_diff / 1024, 2);
            $tx_speed = round($tx_diff / $time_diff / 1024, 2);
        }
    }

    @file_put_contents($cache_file, json_encode([
        'time' => $now,
        'rx' => $total_rx,
        'tx' => $total_tx,
    ]));

    return [
        'success' => true,
        'rx' => round($total_rx / 1024 / 1024 / 1024, 2),
        'tx' => round($total_tx / 1024 / 1024 / 1024, 2),
        'rx_speed' => $rx_speed,
        'tx_speed' => $tx_speed,
        'unit' => 'GB',
        'speed_unit' => 'KB/s',
        'interface' => $interface
    ];
}

function server_get_all_stats() {
    $cpu = server_get_cpu_usage();
    $memory = server_get_memory_usage();
    $disk = server_get_disk_usage();
    $network = server_get_network_usage();
    $nodes = server_get_node_stats();

    return [
        'success' => true,
        'cpu' => $cpu,
        'memory' => $memory,
        'disk' => $disk,
        'network' => $network,
        'nodes' => $nodes,
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

function server_get_node_stats() {
    try {
        $nodes = Database::fetchAll("SELECT * FROM kvm_nodes ORDER BY id");
        $result = [];
        
        foreach ($nodes as $node) {
            $result[] = [
                'id' => $node['id'],
                'name' => $node['node_name'],
                'ip' => $node['node_ip'],
                'status' => $node['status'],
                'cpu_usage' => floatval($node['cpu_usage']),
                'memory_usage' => floatval($node['memory_usage']),
                'disk_usage' => floatval($node['disk_usage']),
                'current_vms' => intval($node['current_vms']),
                'max_vms' => intval($node['max_vms']),
                'last_check' => $node['last_check_at'] ?? '',
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

// ======================== KVM快照管理函数 ========================

function kvm_generate_host_uuid() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $uuid = '';
    for ($i = 0; $i < 128; $i++) {
        $uuid .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $uuid;
}

function kvm_max_snapshots_per_user() {
    return 2;
}

function kvm_get_snapshots($host_id, $user_id) {
    $snapshots = Database::fetchAll(
        "SELECT * FROM vm_snapshots WHERE host_id = ? AND user_id = ? ORDER BY created_at DESC",
        [$host_id, $user_id]
    );

    $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $user_id]);
    if ($host && !empty($host['vm_name'])) {
        $kvm = kvm_manager();
        $list_output = $kvm->exec("virsh snapshot-list --domain " . escapeshellarg($host['vm_name']) . " --name 2>&1");
        $libvirt_snapshots = [];
        if ($list_output !== false) {
            $lines = explode("\n", trim($list_output));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && $line !== 'Name' && strpos($line, '---') === false) {
                    $libvirt_snapshots[] = $line;
                }
            }
        }

        $storage_pool = $kvm->getStoragePool();
        $disk_path = rtrim($storage_pool, '/') . '/' . $host['vm_name'] . '.qcow2';
        $snapshot_list = $kvm->exec('qemu-img snapshot -l ' . escapeshellarg($disk_path) . ' 2>&1');
        $snapshot_sizes = [];
        if ($snapshot_list !== false) {
            $lines = explode("\n", $snapshot_list);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, 'ID') !== false && strpos($line, 'TAG') !== false) continue;
                if (strpos($line, '---') !== false) continue;
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    $tag = $parts[1] ?? '';
                    $size_str = $parts[2] ?? '';
                    // 支持 1.8G, 500M, 100K 等格式（不带B）
                    if (preg_match('/^([\d.]+)([KMGT])$/i', $size_str, $sm)) {
                        $s = floatval($sm[1]);
                        $u = strtoupper($sm[2]);
                        if ($u === 'K') $s *= 1024;
                        elseif ($u === 'M') $s *= 1024 * 1024;
                        elseif ($u === 'G') $s *= 1024 * 1024 * 1024;
                        elseif ($u === 'T') $s *= 1024 * 1024 * 1024 * 1024;
                        $snapshot_sizes[$tag] = intval($s);
                    } elseif (preg_match('/^([\d.]+)([KMGT]?B)$/i', $size_str, $sm)) {
                        // 也支持带B的格式：1.8GB, 500MB
                        $s = floatval($sm[1]);
                        $u = strtoupper($sm[2]);
                        if ($u === 'K' || $u === 'KB') $s *= 1024;
                        elseif ($u === 'M' || $u === 'MB') $s *= 1024 * 1024;
                        elseif ($u === 'G' || $u === 'GB') $s *= 1024 * 1024 * 1024;
                        elseif ($u === 'T' || $u === 'TB') $s *= 1024 * 1024 * 1024 * 1024;
                        $snapshot_sizes[$tag] = intval($s);
                    }
                }
            }
        }

        foreach ($snapshots as &$snap) {
            if (empty($snap['libvirt_name'])) continue;
            $exists = in_array($snap['libvirt_name'], $libvirt_snapshots);
            if (!$exists && $snap['status'] === 'available') {
                Database::update('vm_snapshots', ['status' => 'error', 'error_msg' => '快照在libvirt中不存在'], 'id = ?', [$snap['id']]);
                $snap['status'] = 'error';
                $snap['error_msg'] = '快照在libvirt中不存在';
            }
            if ($exists && isset($snapshot_sizes[$snap['libvirt_name']])) {
                $new_size = $snapshot_sizes[$snap['libvirt_name']];
                if (intval($snap['snapshot_size']) !== $new_size) {
                    Database::update('vm_snapshots', ['snapshot_size' => $new_size], 'id = ?', [$snap['id']]);
                    $snap['snapshot_size'] = $new_size;
                }
            }
        }
        unset($snap);
    }

    return $snapshots;
}

function kvm_snapshot_count($host_id, $user_id) {
    return intval(Database::fetch(
        "SELECT COUNT(*) as c FROM vm_snapshots WHERE host_id = ? AND user_id = ?",
        [$host_id, $user_id]
    )['c'] ?? 0);
}

function kvm_create_snapshot($host, $snapshot_name, $snapshot_desc = '') {
    $host_id = intval($host['id']);
    $user_id = intval($host['user_id']);
    $vm_name = $host['vm_name'];

    if (empty($vm_name)) {
        return ['success' => false, 'message' => '虚拟机不存在'];
    }

    $kvm = kvm_get_manager_for_host($host);
    if (!$kvm) {
        return ['success' => false, 'message' => 'KVM管理器初始化失败'];
    }
    $vm_state = $kvm->getVMPowerState($vm_name);
    // getVMPowerState 返回 'running'/'stopped'/'paused'/'unknown'
    // 'stopped' 对应 virsh 的 'shut off'
    $is_shutdown = ($vm_state === 'stopped' || $vm_state === 'shut off');
    if ($vm_state !== 'running' && !$is_shutdown) {
        return ['success' => false, 'message' => '虚拟机当前状态（' . $vm_state . '）不支持创建快照，请先开机或关机后再试'];
    }

    $current_count = kvm_snapshot_count($host_id, $user_id);
    $max_snapshots = kvm_max_snapshots_per_user();
    if ($current_count >= $max_snapshots) {
        return ['success' => false, 'message' => "快照数量已达上限（最多{$max_snapshots}个），请先删除旧快照"];
    }

    if (empty($snapshot_name)) {
        $snapshot_name = 'snapshot_' . date('Ymd_His');
    }

    $libvirt_name = 'snap_' . $host_id . '_' . time();

    $snapshot_id = Database::insert('vm_snapshots', [
        'host_id' => $host_id,
        'user_id' => $user_id,
        'snapshot_name' => $snapshot_name,
        'snapshot_desc' => $snapshot_desc,
        'libvirt_name' => $libvirt_name,
        'status' => 'creating',
    ]);

    // 不使用 --quiesce（需要 qemu-guest-agent），不使用 --atomic（某些 QEMU 版本不支持）
    // 关机状态优先尝试 --disk-only（更快），运行状态不使用 --disk-only（某些 QEMU 不支持即时磁盘快照）
    $base_cmd = "virsh snapshot-create-as --domain " . escapeshellarg($vm_name) . " --name " . escapeshellarg($libvirt_name) . " --description " . escapeshellarg($snapshot_desc ?: $snapshot_name);

    // 尝试顺序：
    // 1. 关机: --disk-only --atomic  /  运行: --atomic
    // 2. 关机: --disk-only           /  运行: 无参数
    // 3. 无参数（最兼容）
    $attempts = [];
    $attempt_types = [];
    if ($is_shutdown) {
        $attempts[] = $base_cmd . " --disk-only --atomic 2>&1";
        $attempt_types[] = 'disk-only';
        $attempts[] = $base_cmd . " --disk-only 2>&1";
        $attempt_types[] = 'disk-only';
    } else {
        $attempts[] = $base_cmd . " --atomic 2>&1";
        $attempt_types[] = 'internal';
    }
    $attempts[] = $base_cmd . " 2>&1";
    $attempt_types[] = 'internal';

    $output = false;
    $error_msg = '';
    $snapshot_type = 'internal';
    foreach ($attempts as $idx => $cmd) {
        $output = $kvm->exec($cmd);
        if ($output !== false) {
            $snapshot_type = $attempt_types[$idx] ?? 'internal';
            break;
        }
        $error_msg = $kvm->getError() ?: '未知错误';
    }

    $success = false;
    if ($output !== false) {
        if (strpos($output, 'Domain snapshot') !== false || strpos($output, 'created') !== false || empty(trim($output))) {
            $list_check = $kvm->exec("virsh snapshot-info --domain " . escapeshellarg($vm_name) . " --snapshotname " . escapeshellarg($libvirt_name) . " 2>&1");
            if ($list_check !== false && strpos($list_check, 'error') === false) {
                $success = true;
            }
        }
    }

    if ($success) {
        $size = 0;
        $storage_pool = $kvm->getStoragePool();
        $disk_path = rtrim($storage_pool, '/') . '/' . $vm_name . '.qcow2';
        $snapshot_list = $kvm->exec('qemu-img snapshot -l ' . escapeshellarg($disk_path) . ' 2>&1');
        if ($snapshot_list !== false) {
            $lines = explode("\n", $snapshot_list);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, 'ID') !== false && strpos($line, 'TAG') !== false) continue;
                if (strpos($line, '---') !== false) continue;
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 3) {
                    $tag = $parts[1] ?? '';
                    if ($tag === $libvirt_name) {
                        $size_str = $parts[2] ?? '';
                        // 支持 1.8G, 500M, 100K 等格式（不带B）
                        if (preg_match('/^([\d.]+)([KMGT])$/i', $size_str, $sm)) {
                            $s = floatval($sm[1]);
                            $u = strtoupper($sm[2]);
                            if ($u === 'K') $s *= 1024;
                            elseif ($u === 'M') $s *= 1024 * 1024;
                            elseif ($u === 'G') $s *= 1024 * 1024 * 1024;
                            elseif ($u === 'T') $s *= 1024 * 1024 * 1024 * 1024;
                            $size = intval($s);
                        } elseif (preg_match('/^([\d.]+)([KMGT]?B)$/i', $size_str, $sm)) {
                            $s = floatval($sm[1]);
                            $u = strtoupper($sm[2]);
                            if ($u === 'K' || $u === 'KB') $s *= 1024;
                            elseif ($u === 'M' || $u === 'MB') $s *= 1024 * 1024;
                            elseif ($u === 'G' || $u === 'GB') $s *= 1024 * 1024 * 1024;
                            elseif ($u === 'T' || $u === 'TB') $s *= 1024 * 1024 * 1024 * 1024;
                            $size = intval($s);
                        }
                        break;
                    }
                }
            }
        }

        Database::update('vm_snapshots', [
            'status' => 'available',
            'snapshot_type' => $snapshot_type,
            'snapshot_size' => $size,
        ], 'id = ?', [$snapshot_id]);

        return ['success' => true, 'message' => '快照创建成功', 'snapshot_id' => $snapshot_id];
    } else {
        $err_msg = $output !== false ? trim($output) : ($kvm->getError() ?: '未知错误');
        Database::update('vm_snapshots', [
            'status' => 'error',
            'error_msg' => $err_msg,
        ], 'id = ?', [$snapshot_id]);

        return ['success' => false, 'message' => '快照创建失败: ' . $err_msg];
    }
}

function kvm_restore_snapshot($snapshot_id, $user_id) {
    $snapshot = Database::fetch(
        "SELECT s.*, h.vm_name, h.vm_power_status, h.kvm_node_id FROM vm_snapshots s
         JOIN hosts h ON s.host_id = h.id
         WHERE s.id = ? AND s.user_id = ?",
        [$snapshot_id, $user_id]
    );

    if (!$snapshot) {
        return ['success' => false, 'message' => '快照不存在'];
    }

    if ($snapshot['status'] !== 'available' && $snapshot['status'] !== 'restoring') {
        return ['success' => false, 'message' => '快照状态不可用'];
    }

    if (empty($snapshot['vm_name'])) {
        return ['success' => false, 'message' => '虚拟机不存在'];
    }

    Database::update('vm_snapshots', ['status' => 'restoring'], 'id = ?', [$snapshot_id]);

    $kvm = kvm_get_manager_for_node($snapshot['kvm_node_id'] ?? 0);
    if (!$kvm) {
        Database::update('vm_snapshots', ['status' => 'error', 'error_msg' => 'KVM管理器初始化失败'], 'id = ?', [$snapshot_id]);
        return ['success' => false, 'message' => 'KVM管理器初始化失败'];
    }

    $vm_name = $snapshot['vm_name'];
    $libvirt_name = $snapshot['libvirt_name'];
    $snapshot_type = $snapshot['snapshot_type'] ?? 'internal';

    $current_state = $kvm->getVMPowerState($vm_name);
    if ($current_state === 'running' || $current_state === 'paused') {
        $shutdown_output = $kvm->exec("virsh destroy " . escapeshellarg($vm_name) . " 2>&1");
        if ($shutdown_output === false) {
            $err = $kvm->getError() ?: '关闭虚拟机失败';
            Database::update('vm_snapshots', [
                'status' => 'error',
                'error_msg' => $err,
            ], 'id = ?', [$snapshot_id]);
            return ['success' => false, 'message' => '关闭虚拟机失败: ' . $err];
        }
        usleep(2000000);
    }

    $revert_cmd = "virsh snapshot-revert --domain " . escapeshellarg($vm_name) . " --snapshotname " . escapeshellarg($libvirt_name) . " --running 2>&1";
    $output = $kvm->exec($revert_cmd);

    if ($output === false) {
        $err = $kvm->getError() ?: '未知错误';
        if (strpos($err, 'not found') !== false || strpos($err, '未找到') !== false) {
            $revert_cmd_no_running = "virsh snapshot-revert --domain " . escapeshellarg($vm_name) . " --snapshotname " . escapeshellarg($libvirt_name) . " 2>&1";
            $output2 = $kvm->exec($revert_cmd_no_running);
            if ($output2 !== false) {
                $start_output = $kvm->exec("virsh start " . escapeshellarg($vm_name) . " 2>&1");
                usleep(3000000);
                $actual_state = $kvm->getVMPowerState($vm_name);
                Database::update('vm_snapshots', ['status' => 'available'], 'id = ?', [$snapshot_id]);
                Database::update('hosts', ['vm_power_status' => $actual_state], 'id = ?', [$snapshot['host_id']]);
                return ['success' => true, 'message' => '快照恢复成功，虚拟机状态: ' . $actual_state];
            }
        }
        Database::update('vm_snapshots', [
            'status' => 'error',
            'error_msg' => $err,
        ], 'id = ?', [$snapshot_id]);
        return ['success' => false, 'message' => '快照恢复失败: ' . $err];
    }

    usleep(3000000);
    
    $actual_state = $kvm->getVMPowerState($vm_name);
    
    Database::update('vm_snapshots', ['status' => 'available'], 'id = ?', [$snapshot_id]);
    Database::update('hosts', ['vm_power_status' => $actual_state], 'id = ?', [$snapshot['host_id']]);
    
    return ['success' => true, 'message' => '快照恢复成功，虚拟机状态: ' . $actual_state];
}

function kvm_delete_snapshot($snapshot_id, $user_id) {
    $snapshot = Database::fetch(
        "SELECT s.*, h.vm_name, h.kvm_node_id FROM vm_snapshots s
         JOIN hosts h ON s.host_id = h.id
         WHERE s.id = ? AND s.user_id = ?",
        [$snapshot_id, $user_id]
    );

    if (!$snapshot) {
        return ['success' => false, 'message' => '快照不存在'];
    }

    Database::update('vm_snapshots', ['status' => 'deleting'], 'id = ?', [$snapshot_id]);

    if (!empty($snapshot['vm_name']) && !empty($snapshot['libvirt_name'])) {
        $kvm = kvm_get_manager_for_node($snapshot['kvm_node_id'] ?? 0);
        if (!$kvm) {
            Database::update('vm_snapshots', ['status' => 'error', 'error_msg' => 'KVM管理器初始化失败'], 'id = ?', [$snapshot_id]);
            return ['success' => false, 'message' => 'KVM管理器初始化失败'];
        }
        $cmd = "virsh snapshot-delete --domain " . escapeshellarg($snapshot['vm_name']) . " --snapshotname " . escapeshellarg($snapshot['libvirt_name']) . " 2>&1";
        $output = $kvm->exec($cmd);

        // exec 失败时返回 false
        if ($output === false) {
            $err = $kvm->getError() ?: '未知错误';
            // 快照在 libvirt 中不存在（已删除），不算错误
            if (strpos($err, 'not found') === false && strpos($err, '未找到') === false) {
                Database::update('vm_snapshots', [
                    'status' => 'error',
                    'error_msg' => $err,
                ], 'id = ?', [$snapshot_id]);
                return ['success' => false, 'message' => '快照删除失败: ' . $err];
            }
        }
    }

    Database::query("DELETE FROM vm_snapshots WHERE id = ?", [$snapshot_id]);
    return ['success' => true, 'message' => '快照已删除'];
}

function kvm_format_size($bytes) {
    $bytes = intval($bytes);
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

// ======================== 网络配置函数 ========================

function kvm_get_network_config($host) {
    $host_id = intval($host['id']);
    $ip_assignment = Database::fetch(
        "SELECT a.*, p.gateway, p.netmask FROM ip_assignments a
         LEFT JOIN ip_pools p ON a.pool_id = p.id
         WHERE a.host_id = ? AND a.status = 'assigned'
         LIMIT 1",
        [$host_id]
    );

    $ip = $host['ip_address'] ?? '';
    $netmask = '255.255.255.0';
    $gateway = '';
    $dns1 = '8.8.8.8';
    $dns2 = '114.114.114.114';

    if ($ip_assignment) {
        $ip = $ip_assignment['ip_address'];
        if (!empty($ip_assignment['netmask'])) $netmask = $ip_assignment['netmask'];
        if (!empty($ip_assignment['gateway'])) $gateway = $ip_assignment['gateway'];
    }

    $nat_rules = Database::fetchAll(
        "SELECT * FROM nat_rules WHERE host_id = ? ORDER BY created_at DESC",
        [$host_id]
    );

    return [
        'success' => true,
        'ip_address' => $ip,
        'netmask' => $netmask,
        'gateway' => $gateway,
        'dns1' => $dns1,
        'dns2' => $dns2,
        'nat_rules' => $nat_rules,
        'firewall_enabled' => true,
    ];
}

function kvm_update_network_config($host, $data) {
    $new_ip = trim($data['ip_address'] ?? '');
    $netmask = trim($data['netmask'] ?? '255.255.255.0');
    $gateway = trim($data['gateway'] ?? '');

    if (empty($new_ip) || !filter_var($new_ip, FILTER_VALIDATE_IP)) {
        return ['success' => false, 'message' => '请输入有效的IP地址'];
    }

    Database::update('hosts', ['ip_address' => $new_ip], 'id = ?', [$host['id']]);

    $assignment = Database::fetch(
        "SELECT * FROM ip_assignments WHERE host_id = ? AND status = 'assigned' LIMIT 1",
        [$host['id']]
    );

    if ($assignment) {
        Database::update('ip_assignments', [
            'ip_address' => $new_ip,
        ], 'id = ?', [$assignment['id']]);
    }

    return ['success' => true, 'message' => '网络配置已更新，请在虚拟机内手动配置网络'];
}

// ======================== 防火墙规则管理函数 ========================

function firewall_get_host_ip($host_id) {
    $host = Database::fetch("SELECT ip_address, vm_name FROM hosts WHERE id = ?", [$host_id]);
    if (!$host || empty($host['ip_address'])) {
        return '';
    }
    return $host['ip_address'];
}

function firewall_iptables_exec($cmd) {
    static $sudo = null;
    if ($sudo === null) {
        $sudo = '';
        if (function_exists('exec')) {
            $out = [];
            @exec('whoami 2>/dev/null', $out, $ret);
            if ($ret === 0 && !empty($out) && trim($out[0]) !== 'root') {
                $sudo = 'sudo ';
            }
        }
    }
    $full_cmd = $sudo . 'iptables ' . $cmd . ' 2>&1';
    $output = [];
    $return_var = 0;
    @exec($full_cmd, $output, $return_var);
    return [
        'success' => $return_var === 0,
        'output' => implode("\n", $output),
        'cmd' => $full_cmd,
    ];
}

function firewall_build_iptables_args($rule, $vm_ip) {
    $args = '';
    
    if ($rule['direction'] === 'inbound') {
        $args .= '-d ' . escapeshellarg($vm_ip) . ' ';
    } else {
        $args .= '-s ' . escapeshellarg($vm_ip) . ' ';
    }
    
    if (!empty($rule['source_ip']) && $rule['direction'] === 'inbound') {
        $args .= '-s ' . escapeshellarg($rule['source_ip']) . ' ';
    } elseif (!empty($rule['source_ip']) && $rule['direction'] === 'outbound') {
        $args .= '-d ' . escapeshellarg($rule['source_ip']) . ' ';
    }
    
    $proto = $rule['protocol'];
    if ($proto !== 'all') {
        $args .= '-p ' . escapeshellarg($proto) . ' ';
    }
    
    $port = intval($rule['port'] ?? 0);
    $port_range = trim($rule['port_range'] ?? '');
    if ($proto === 'tcp' || $proto === 'udp') {
        if (!empty($port_range) && strpos($port_range, '-') !== false) {
            $args .= '--dport ' . escapeshellarg($port_range) . ' ';
        } elseif ($port > 0) {
            $args .= '--dport ' . $port . ' ';
        }
    }
    
    $action_map = [
        'accept' => 'ACCEPT',
        'drop' => 'DROP',
        'reject' => 'REJECT',
    ];
    $target = $action_map[$rule['action']] ?? 'ACCEPT';
    $args .= '-j ' . $target;
    
    return trim($args);
}

function firewall_get_chain_name($host_id) {
    return 'GJC_FW_' . intval($host_id);
}

function firewall_ensure_chain($host_id, $vm_ip) {
    $chain = firewall_get_chain_name($host_id);
    
    $result = firewall_iptables_exec('-L ' . escapeshellarg($chain) . ' -n');
    if (!$result['success']) {
        firewall_iptables_exec('-N ' . escapeshellarg($chain));
    }
    
    $check_in = firewall_iptables_exec('-C FORWARD -d ' . escapeshellarg($vm_ip) . ' -j ' . escapeshellarg($chain));
    if (!$check_in['success']) {
        firewall_iptables_exec('-I FORWARD -d ' . escapeshellarg($vm_ip) . ' -j ' . escapeshellarg($chain));
    }
    
    $check_out = firewall_iptables_exec('-C FORWARD -s ' . escapeshellarg($vm_ip) . ' -j ' . escapeshellarg($chain));
    if (!$check_out['success']) {
        firewall_iptables_exec('-I FORWARD -s ' . escapeshellarg($vm_ip) . ' -j ' . escapeshellarg($chain));
    }
    
    return true;
}

function firewall_apply_rule_to_iptables($rule) {
    $vm_ip = firewall_get_host_ip($rule['host_id']);
    if (empty($vm_ip)) {
        return ['success' => false, 'message' => '虚拟机IP未分配，无法应用规则'];
    }
    
    $chain = firewall_get_chain_name($rule['host_id']);
    firewall_ensure_chain($rule['host_id'], $vm_ip);
    
    $args = firewall_build_iptables_args($rule, $vm_ip);
    $comment = '-m comment --comment "gw_rule_' . intval($rule['id']) . '"';
    
    $check = firewall_iptables_exec('-C ' . escapeshellarg($chain) . ' ' . $args . ' ' . $comment);
    if ($check['success']) {
        return ['success' => true, 'message' => '规则已存在'];
    }
    
    $result = firewall_iptables_exec('-I ' . escapeshellarg($chain) . ' 1 ' . $args . ' ' . $comment);
    
    Database::update('firewall_rules', [
        'applied' => 1,
        'apply_error' => '',
    ], 'id = ?', [$rule['id']]);
    
    return $result['success'] 
        ? ['success' => true, 'message' => '规则已应用到防火墙'] 
        : ['success' => false, 'message' => '应用规则失败: ' . $result['output']];
}

function firewall_remove_rule_from_iptables($rule) {
    $vm_ip = firewall_get_host_ip($rule['host_id']);
    if (empty($vm_ip)) {
        return ['success' => true, 'message' => '虚拟机IP未分配，无需移除'];
    }
    
    $chain = firewall_get_chain_name($rule['host_id']);
    $args = firewall_build_iptables_args($rule, $vm_ip);
    $comment = '-m comment --comment "gw_rule_' . intval($rule['id']) . '"';
    
    $check = firewall_iptables_exec('-C ' . escapeshellarg($chain) . ' ' . $args . ' ' . $comment);
    if (!$check['success']) {
        return ['success' => true, 'message' => '规则不存在于防火墙中'];
    }
    
    $result = firewall_iptables_exec('-D ' . escapeshellarg($chain) . ' ' . $args . ' ' . $comment);
    
    Database::update('firewall_rules', [
        'applied' => 0,
    ], 'id = ?', [$rule['id']]);
    
    return $result['success'] 
        ? ['success' => true, 'message' => '规则已从防火墙移除'] 
        : ['success' => false, 'message' => '移除规则失败: ' . $result['output']];
}

function firewall_sync_all_rules($host_id, $user_id) {
    $vm_ip = firewall_get_host_ip($host_id);
    if (empty($vm_ip)) {
        return ['success' => false, 'message' => '虚拟机IP未分配'];
    }
    
    $chain = firewall_get_chain_name($host_id);
    firewall_ensure_chain($host_id, $vm_ip);
    
    firewall_iptables_exec('-F ' . escapeshellarg($chain));
    
    $rules = Database::fetchAll(
        "SELECT * FROM firewall_rules WHERE host_id = ? AND user_id = ? AND status = 'active' ORDER BY sort_order ASC, id ASC",
        [$host_id, $user_id]
    );
    
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($rules as $rule) {
        $args = firewall_build_iptables_args($rule, $vm_ip);
        $comment = '-m comment --comment "gw_rule_' . intval($rule['id']) . '"';
        $result = firewall_iptables_exec('-A ' . escapeshellarg($chain) . ' ' . $args . ' ' . $comment);
        if ($result['success']) {
            $success_count++;
            Database::update('firewall_rules', ['applied' => 1, 'apply_error' => ''], 'id = ?', [$rule['id']]);
        } else {
            $fail_count++;
            Database::update('firewall_rules', ['applied' => 0, 'apply_error' => $result['output']], 'id = ?', [$rule['id']]);
        }
    }
    
    return [
        'success' => true,
        'message' => "同步完成：成功 {$success_count} 条，失败 {$fail_count} 条",
        'success_count' => $success_count,
        'fail_count' => $fail_count,
    ];
}

function firewall_get_rules($host_id, $user_id) {
    return Database::fetchAll(
        "SELECT * FROM firewall_rules WHERE host_id = ? AND user_id = ? ORDER BY sort_order ASC, id ASC",
        [$host_id, $user_id]
    );
}

function firewall_add_rule($host_id, $user_id, $data) {
    $rule_name = trim($data['rule_name'] ?? '');
    $protocol = $data['protocol'] ?? 'tcp';
    $port = intval($data['port'] ?? 0);
    $port_range = trim($data['port_range'] ?? '');
    $source_ip = trim($data['source_ip'] ?? '');
    $action = $data['action'] ?? 'accept';
    $direction = $data['direction'] ?? 'inbound';

    if (empty($rule_name)) {
        $rule_name = 'rule_' . date('Ymd_His');
    }

    if (!in_array($protocol, ['tcp', 'udp', 'icmp', 'all'])) {
        return ['success' => false, 'message' => '协议类型不正确'];
    }

    if (!in_array($action, ['accept', 'drop', 'reject'])) {
        return ['success' => false, 'message' => '动作类型不正确'];
    }

    if (!in_array($direction, ['inbound', 'outbound'])) {
        return ['success' => false, 'message' => '方向不正确'];
    }

    if ($port < 0 || $port > 65535) {
        return ['success' => false, 'message' => '端口号范围不正确'];
    }

    if (!empty($source_ip) && !filter_var($source_ip, FILTER_VALIDATE_IP) && strpos($source_ip, '/') === false) {
        return ['success' => false, 'message' => '源IP地址格式不正确'];
    }

    $rule_id = Database::insert('firewall_rules', [
        'host_id' => $host_id,
        'user_id' => $user_id,
        'rule_name' => $rule_name,
        'protocol' => $protocol,
        'port' => $port,
        'port_range' => $port_range,
        'source_ip' => $source_ip,
        'action' => $action,
        'direction' => $direction,
        'status' => 'active',
        'applied' => 0,
        'apply_error' => '',
    ]);

    $new_rule = Database::fetch("SELECT * FROM firewall_rules WHERE id = ?", [$rule_id]);
    $apply_result = firewall_apply_rule_to_iptables($new_rule);

    if (!$apply_result['success']) {
        return ['success' => true, 'message' => '规则已添加（应用到防火墙失败: ' . $apply_result['message'] . '）', 'rule_id' => $rule_id];
    }

    return ['success' => true, 'message' => '防火墙规则已添加并生效', 'rule_id' => $rule_id];
}

function firewall_update_rule($rule_id, $user_id, $data) {
    $rule = Database::fetch(
        "SELECT * FROM firewall_rules WHERE id = ? AND user_id = ?",
        [$rule_id, $user_id]
    );

    if (!$rule) {
        return ['success' => false, 'message' => '规则不存在'];
    }

    $was_active = $rule['status'] === 'active';

    $update_data = [];
    if (isset($data['rule_name'])) $update_data['rule_name'] = trim($data['rule_name']);
    if (isset($data['protocol'])) $update_data['protocol'] = $data['protocol'];
    if (isset($data['port'])) $update_data['port'] = intval($data['port']);
    if (isset($data['port_range'])) $update_data['port_range'] = trim($data['port_range']);
    if (isset($data['source_ip'])) $update_data['source_ip'] = trim($data['source_ip']);
    if (isset($data['action'])) $update_data['action'] = $data['action'];
    if (isset($data['direction'])) $update_data['direction'] = $data['direction'];
    if (isset($data['status'])) $update_data['status'] = $data['status'];

    if (empty($update_data)) {
        return ['success' => false, 'message' => '没有需要更新的数据'];
    }

    if ($was_active) {
        firewall_remove_rule_from_iptables($rule);
    }

    Database::update('firewall_rules', $update_data, 'id = ?', [$rule_id]);

    $updated_rule = Database::fetch("SELECT * FROM firewall_rules WHERE id = ?", [$rule_id]);
    $msg = '规则已更新';

    if ($updated_rule['status'] === 'active') {
        $apply_result = firewall_apply_rule_to_iptables($updated_rule);
        if (!$apply_result['success']) {
            $msg .= '（应用到防火墙失败: ' . $apply_result['message'] . '）';
        } else {
            $msg .= '并生效';
        }
    }

    return ['success' => true, 'message' => $msg];
}

function firewall_delete_rule($rule_id, $user_id) {
    $rule = Database::fetch(
        "SELECT * FROM firewall_rules WHERE id = ? AND user_id = ?",
        [$rule_id, $user_id]
    );

    if (!$rule) {
        return ['success' => false, 'message' => '规则不存在'];
    }

    firewall_remove_rule_from_iptables($rule);

    Database::query("DELETE FROM firewall_rules WHERE id = ?", [$rule_id]);
    return ['success' => true, 'message' => '规则已删除'];
}

function firewall_status_text($status) {
    $map = [
        'active' => '已启用',
        'disabled' => '已禁用',
    ];
    return $map[$status] ?? $status;
}

function firewall_action_text($action) {
    $map = [
        'accept' => '允许',
        'drop' => '丢弃',
        'reject' => '拒绝',
    ];
    return $map[$action] ?? $action;
}

function firewall_direction_text($direction) {
    $map = [
        'inbound' => '入站',
        'outbound' => '出站',
    ];
    return $map[$direction] ?? $direction;
}

function firewall_protocol_text($protocol) {
    $map = [
        'tcp' => 'TCP',
        'udp' => 'UDP',
        'icmp' => 'ICMP',
        'all' => '全部',
    ];
    return $map[$protocol] ?? $protocol;
}

// ======================== 广告联盟函数 ========================

function get_ad_available_earnings($uid) {
    $row = Database::fetch("SELECT COALESCE(SUM(amount),0) as total FROM ad_earnings WHERE user_id = ? AND status IN ('pending','available') AND type != 'withdraw'", [$uid]);
    return floatval($row['total'] ?? 0);
}

function ad_settle_daily_earnings() {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $users = Database::fetchAll("SELECT DISTINCT user_id FROM ad_stats WHERE stat_date = ?", [$yesterday]);
    
    foreach ($users as $u) {
        $uid = intval($u['user_id']);
        $stat = Database::fetch("SELECT COALESCE(SUM(earnings),0) as total FROM ad_stats WHERE stat_date = ? AND user_id = ?", [$yesterday, $uid]);
        $total = floatval($stat['total'] ?? 0);
        
        if ($total > 0) {
            Database::query("UPDATE ad_earnings SET status = 'available' WHERE user_id = ? AND status = 'pending' AND DATE(created_at) = ?", [$uid, $yesterday]);
        }
    }
    return true;
}
