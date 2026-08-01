<?php
/**
 * 平台安全防护体系
 * 覆盖：WAF防护、API签名校验、防重放、QPS限流、数据防篡改、文件上传安全、操作审计、密码策略
 */

// ===================== 数据库表迁移 =====================

function migrate_security_tables() {
    static $migrated = false;
    if ($migrated) return;
    $migrated = true;

    $pdo = Database::connect();
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    // 安全攻击日志表
    if (!in_array('security_logs', $existing)) {
        $pdo->exec("CREATE TABLE security_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            attack_type VARCHAR(50) DEFAULT '' COMMENT '攻击类型: sql_inject/xss/csrf/rate_limit/replay/sign_invalid/file_upload/illegal_access',
            severity ENUM('low','medium','high','critical') DEFAULT 'low' COMMENT '严重程度',
            ip_address VARCHAR(50) DEFAULT '' COMMENT '攻击IP',
            user_id INT DEFAULT 0 COMMENT '用户ID',
            request_uri VARCHAR(500) DEFAULT '' COMMENT '请求URI',
            request_method VARCHAR(10) DEFAULT '' COMMENT '请求方法',
            payload TEXT COMMENT '攻击载荷',
            user_agent VARCHAR(500) DEFAULT '' COMMENT 'UA',
            blocked TINYINT(1) DEFAULT 1 COMMENT '是否已拦截',
            detail VARCHAR(500) DEFAULT '' COMMENT '详情',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attack_type (attack_type),
            INDEX idx_ip (ip_address),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // API密钥表（接口签名用）
    if (!in_array('api_keys', $existing)) {
        $pdo->exec("CREATE TABLE api_keys (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL COMMENT '用户ID',
            api_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'API Key',
            api_secret VARCHAR(128) NOT NULL COMMENT 'API Secret',
            status ENUM('pending','active','suspended','revoked') DEFAULT 'pending' COMMENT '状态',
            name VARCHAR(100) DEFAULT '' COMMENT '密钥名称',
            rate_limit INT DEFAULT 100 COMMENT '每分钟请求上限',
            last_used_at TIMESTAMP NULL DEFAULT NULL COMMENT '最后使用时间',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL DEFAULT NULL COMMENT '过期时间',
            INDEX idx_user_id (user_id),
            INDEX idx_api_key (api_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 防重放 nonce 表
    if (!in_array('security_nonces', $existing)) {
        $pdo->exec("CREATE TABLE security_nonces (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nonce VARCHAR(64) NOT NULL UNIQUE COMMENT '随机数',
            api_key VARCHAR(64) DEFAULT '' COMMENT '对应API Key',
            ip_address VARCHAR(50) DEFAULT '' COMMENT 'IP',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_nonce (nonce),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 限流记录表
    if (!in_array('security_rate_limit', $existing)) {
        $pdo->exec("CREATE TABLE security_rate_limit (
            id INT PRIMARY KEY AUTO_INCREMENT,
            limit_key VARCHAR(128) NOT NULL COMMENT '限流键(ip:xxx or user:xxx or api:xxx)',
            limit_type VARCHAR(20) DEFAULT 'ip' COMMENT '类型: ip/user/device/api',
            request_count INT DEFAULT 0 COMMENT '请求数',
            window_start INT DEFAULT 0 COMMENT '时间窗口起始(秒级时间戳)',
            blocked_until INT DEFAULT 0 COMMENT '封禁截止时间戳',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_limit_key_window (limit_key, window_start),
            INDEX idx_limit_key (limit_key),
            INDEX idx_blocked (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 数据完整性指纹表
    if (!in_array('data_integrity_fingerprints', $existing)) {
        $pdo->exec("CREATE TABLE data_integrity_fingerprints (
            id INT PRIMARY KEY AUTO_INCREMENT,
            table_name VARCHAR(64) NOT NULL COMMENT '表名',
            record_id INT NOT NULL COMMENT '记录ID',
            fingerprint VARCHAR(64) NOT NULL COMMENT '数据指纹SHA256',
            fields_hash TEXT COMMENT '参与计算的字段JSON',
            version INT DEFAULT 1 COMMENT '版本号',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            status ENUM('valid','tampered','unknown') DEFAULT 'valid' COMMENT '状态',
            UNIQUE KEY uk_table_record (table_name, record_id, version),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // IP封禁表
    if (!in_array('security_ip_blocks', $existing)) {
        $pdo->exec("CREATE TABLE security_ip_blocks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ip_address VARCHAR(50) NOT NULL COMMENT 'IP或IP段',
            block_type ENUM('permanent','temporary') DEFAULT 'temporary' COMMENT '封禁类型',
            reason VARCHAR(200) DEFAULT '' COMMENT '封禁原因',
            blocked_by VARCHAR(50) DEFAULT 'system' COMMENT '封禁者system/admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL DEFAULT NULL COMMENT '过期时间(临时封禁)',
            INDEX idx_ip (ip_address),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 管理员操作审计日志表
    if (!in_array('admin_audit_logs', $existing)) {
        $pdo->exec("CREATE TABLE admin_audit_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT DEFAULT 0 COMMENT '管理员ID',
            admin_name VARCHAR(100) DEFAULT '' COMMENT '管理员名称',
            action VARCHAR(100) NOT NULL COMMENT '操作动作',
            target_type VARCHAR(50) DEFAULT '' COMMENT '目标类型',
            target_id INT DEFAULT 0 COMMENT '目标ID',
            ip_address VARCHAR(50) DEFAULT '' COMMENT 'IP',
            detail TEXT COMMENT '操作详情JSON',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 登录失败记录表
    if (!in_array('security_login_attempts', $existing)) {
        $pdo->exec("CREATE TABLE security_login_attempts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(100) DEFAULT '' COMMENT '尝试的用户名',
            ip_address VARCHAR(50) DEFAULT '' COMMENT 'IP',
            user_agent VARCHAR(500) DEFAULT '' COMMENT 'UA',
            success TINYINT(1) DEFAULT 0 COMMENT '是否成功',
            fail_reason VARCHAR(100) DEFAULT '' COMMENT '失败原因',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip_address),
            INDEX idx_username (username),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 设备指纹表（人机验证体系 v2.2.0+）
    if (!in_array('device_fingerprints', $existing)) {
        $pdo->exec("CREATE TABLE device_fingerprints (
            id INT PRIMARY KEY AUTO_INCREMENT,
            fingerprint_hash VARCHAR(64) NOT NULL COMMENT '设备指纹哈希',
            user_id INT DEFAULT 0 COMMENT '关联用户ID',
            ip_address VARCHAR(50) DEFAULT '' COMMENT '首次IP',
            user_agent VARCHAR(500) DEFAULT '' COMMENT 'UA',
            trust_score INT DEFAULT 50 COMMENT '信任分 0-100',
            visit_count INT DEFAULT 0 COMMENT '访问次数',
            verify_pass_count INT DEFAULT 0 COMMENT '验证通过次数',
            verify_fail_count INT DEFAULT 0 COMMENT '验证失败次数',
            is_suspicious TINYINT(1) DEFAULT 0 COMMENT '是否可疑(模拟器/虚拟机)',
            first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '首次发现',
            last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后活跃',
            UNIQUE KEY uk_fingerprint (fingerprint_hash),
            INDEX idx_user_id (user_id),
            INDEX idx_trust (trust_score),
            INDEX idx_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 验证记录表（人机验证体系 v2.2.0+）
    if (!in_array('captcha_verify_log', $existing)) {
        $pdo->exec("CREATE TABLE captcha_verify_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT DEFAULT 0 COMMENT '用户ID',
            ip_address VARCHAR(50) DEFAULT '' COMMENT 'IP',
            device_fingerprint VARCHAR(64) DEFAULT '' COMMENT '设备指纹',
            captcha_type VARCHAR(30) DEFAULT '' COMMENT '验证类型: none/slider/image_select/email_code/sms_code',
            risk_level VARCHAR(10) DEFAULT '' COMMENT '风险等级: low/medium/high',
            risk_score INT DEFAULT 0 COMMENT '风险分数',
            result VARCHAR(10) DEFAULT '' COMMENT '结果: pass/fail/skip',
            action_type VARCHAR(50) DEFAULT '' COMMENT '操作类型: login/register/forgot/sensitive',
            detail TEXT COMMENT '详情JSON',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_ip (ip_address),
            INDEX idx_fingerprint (device_fingerprint),
            INDEX idx_result (result),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 验证码表（邮件/短信验证码 v2.2.0+）
    if (!in_array('verification_codes', $existing)) {
        $pdo->exec("CREATE TABLE verification_codes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            target VARCHAR(100) NOT NULL COMMENT '目标(邮箱/手机号)',
            target_type VARCHAR(10) DEFAULT 'email' COMMENT '类型: email/sms',
            code VARCHAR(10) NOT NULL COMMENT '验证码',
            purpose VARCHAR(30) DEFAULT 'login' COMMENT '用途: login/register/forgot/sensitive',
            user_id INT DEFAULT 0 COMMENT '关联用户ID',
            ip_address VARCHAR(50) DEFAULT '' COMMENT '请求IP',
            expires_at TIMESTAMP NOT NULL COMMENT '过期时间',
            verified TINYINT(1) DEFAULT 0 COMMENT '是否已验证',
            verified_at TIMESTAMP NULL DEFAULT NULL COMMENT '验证时间',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_target (target),
            INDEX idx_code (target, code),
            INDEX idx_expires (expires_at),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

migrate_security_tables();

// ===================== 工具函数=====================

function sec_get_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return $ip;
}

function sec_get_user_agent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

// ===================== WAF防护模块=====================

/**
 * 记录安全事件
 */
function sec_log_attack($attack_type, $severity = 'medium', $payload = '', $detail = '', $blocked = true) {
    try {
        Database::insert('security_logs', [
            'attack_type' => $attack_type,
            'severity' => $severity,
            'ip_address' => sec_get_ip(),
            'user_id' => function_exists('auth_id') ? intval(auth_id()) : 0,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'payload' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : substr($payload, 0, 5000),
            'user_agent' => sec_get_user_agent(),
            'blocked' => $blocked ? 1 : 0,
            'detail' => $detail,
        ]);
    } catch (Exception $e) {}
}

/**
 * SQL注入检测
 */
function sec_detect_sql_injection($input) {
    if (!is_string($input)) return false;

    $patterns = [
        '/\bunion\s+select\b/i',
        '/\bselect\s+.*\s+from\b/i',
        '/\binsert\s+into\b/i',
        '/\bdelete\s+from\b/i',
        '/\bdrop\s+(table|database)\b/i',
        '/\bupdate\s+.*\s+set\b/i',
        '/\b(or|and)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+/i',
        "/['\"]\s*or\s*['\"]1['\"]?\s*=\s*['\"]?1/i",
        '/\bsleep\s*\(\s*\d+\s*\)/i',
        '/\bbenchmark\s*\(/i',
        '/\bload_file\s*\(/i',
        '/\boutfile\b/i',
        '/--\s*$/',
        '/;\s*--/',
        '/\bxp_/i',
        '/information_schema/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    return false;
}

/**
 * XSS攻击检测
 */
function sec_detect_xss($input) {
    if (!is_string($input)) return false;

    $patterns = [
        '/<script\b[^>]*>.*<\/script>/is',
        '/<script\b[^>]*\/?>/i',
        '/javascript\s*:/i',
        '/on\w+\s*=/i',
        '/<iframe\b/i',
        '/<img\b[^>]*on\w+\s*=/i',
        '/<body\b[^>]*on\w+\s*=/i',
        '/eval\s*\(/i',
        '/alert\s*\(/i',
        '/document\.cookie/i',
        '/window\.location/i',
        '/\bvbscript\s*:/i',
        '/data:text\/html/i',
        '/<svg\b[^>]*on\w+\s*=/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    return false;
}

/**
 * 目录遍历检测
 */
function sec_detect_path_traversal($input) {
    if (!is_string($input)) return false;
    $patterns = ['../', '..\\', '%2e%2e%2f', '%2e%2e/', '..%2f', '..\\'];
    foreach ($patterns as $p) {
        if (stripos($input, $p) !== false) return true;
    }
    return false;
}

/**
 * 全局WAF检查（在入口文件调用）
 */
function sec_waf_check() {
    $ip = sec_get_ip();

    // 检查IP是否被封禁
    if (sec_is_ip_blocked($ip)) {
        sec_log_attack('ip_blocked', 'high', '', 'IP已被封禁，拒绝访问', true);
        http_response_code(403);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>访问被拒绝</title></head>
        <body style="background:#f5f7fa;"><div style="max-width:500px;margin:100px auto;background:#fff;padding:40px;border-radius:12px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.1);">
        <h2 style="color:#cf1322;">🚫 访问被拒绝</h2><p style="color:#64748b;">您的IP已被系统封禁</p><p style="color:#94a3b8;font-size:14px;">IP: ' . htmlspecialchars($ip) . '</p></div></body></html>');
    }

    $inputs = array_merge($_GET, $_POST);

    foreach ($inputs as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_string($v) && _sec_check_input($key, $v, $ip)) return;
            }
        } elseif (is_string($value)) {
            if (_sec_check_input($key, $value, $ip)) return;
        }
    }
}

function _sec_check_input($key, $value, $ip) {
    // 跳过明显安全的字段
    if (strlen($value) < 4) return false;

    // SQL注入检测
    if (sec_detect_sql_injection($value)) {
        sec_log_attack('sql_inject', 'high', $value, "参数{$key}包含SQL注入特征", true);
        sec_block_ip_temporary($ip, 'sql_injection_attack', 3600);
        http_response_code(403);
        die('请求包含非法参数');
    }

    // XSS检测（对输出时使用e()函数转义，这里仅记录日志，不直接拦截，避免误杀）
    if (sec_detect_xss($value)) {
        sec_log_attack('xss', 'medium', $value, "参数{$key}包含XSS特征", false);
    }

    // 目录遍历检测
    if (sec_detect_path_traversal($value)) {
        sec_log_attack('path_traversal', 'high', $value, "参数{$key}包含目录遍历特征", true);
        http_response_code(403);
        die('请求包含非法参数');
    }

    return false;
}

// ===================== CSRF防护=====================

function sec_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('auth_start')) auth_start();
        else session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sec_csrf_field() {
    $token = sec_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

function sec_verify_csrf($token = null) {
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('auth_start')) auth_start();
        else session_start();
    }
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function sec_require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sec_verify_csrf()) {
        sec_log_attack('csrf', 'high', '', 'CSRF token验证失败', true);
        if (function_exists('is_ajax') && is_ajax()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'CSRF验证失败']);
            exit;
        }
        http_response_code(403);
        die('CSRF验证失败，请刷新页面后重试');
    }
}

// ===================== IP封禁管理=====================

function sec_is_ip_blocked($ip) {
    try {
        $block = Database::fetch(
            "SELECT * FROM security_ip_blocks WHERE ip_address = ? AND (block_type = 'permanent' OR expires_at > NOW()) ORDER BY id DESC LIMIT 1",
            [$ip]
        );
        if ($block) return true;
    } catch (Exception $e) {}
    return false;
}

function sec_block_ip_permanent($ip, $reason = '', $blocked_by = 'system') {
    try {
        Database::insert('security_ip_blocks', [
            'ip_address' => $ip,
            'block_type' => 'permanent',
            'reason' => $reason,
            'blocked_by' => $blocked_by,
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sec_block_ip_temporary($ip, $reason = '', $duration_seconds = 3600) {
    try {
        Database::insert('security_ip_blocks', [
            'ip_address' => $ip,
            'block_type' => 'temporary',
            'reason' => $reason,
            'expires_at' => date('Y-m-d H:i:s', time() + $duration_seconds),
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sec_unblock_ip($ip) {
    try {
        Database::query("DELETE FROM security_ip_blocks WHERE ip_address = ?", [$ip]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ===================== QPS限流=====================

/**
 * 限流检查
 * @param string $key 限流键（如 "ip:127.0.0.1"或"user:123"）
 * @param int $max_requests 窗口内最大请求数
 * @param int $window_seconds 时间窗口秒数
 * @param int $block_duration 超限后封禁秒数
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
 */
function sec_rate_limit_check($key, $max_requests = 60, $window_seconds = 60, $block_duration = 300) {
    $now = time();
    $window_start = floor($now / $window_seconds) * $window_seconds;

    try {
        // 检查是否已被封禁
        $blocked = Database::fetch(
            "SELECT blocked_until FROM security_rate_limit WHERE limit_key = ? AND blocked_until > ? LIMIT 1",
            [$key, $now]
        );
        if ($blocked && intval($blocked['blocked_until']) > $now) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => intval($blocked['blocked_until']) - $now,
            ];
        }

        // 递增计数器
        $existing = Database::fetch(
            "SELECT * FROM security_rate_limit WHERE limit_key = ? AND window_start = ?",
            [$key, $window_start]
        );

        if ($existing) {
            $new_count = intval($existing['request_count']) + 1;
            Database::query(
                "UPDATE security_rate_limit SET request_count = ? WHERE id = ?",
                [$new_count, $existing['id']]
            );
        } else {
            $new_count = 1;
            Database::insert('security_rate_limit', [
                'limit_key' => $key,
                'limit_type' => explode(':', $key)[0] ?? 'ip',
                'request_count' => 1,
                'window_start' => $window_start,
            ]);
        }

        $remaining = max(0, $max_requests - $new_count);
        $allowed = $new_count <= $max_requests;

        if (!$allowed && $block_duration > 0) {
            // 超限，设置封禁
            Database::query(
                "UPDATE security_rate_limit SET blocked_until = ? WHERE limit_key = ? AND window_start = ?",
                [$now + $block_duration, $key, $window_start]
            );
            sec_log_attack('rate_limit', 'medium', '', "限流触发: {$key} 超过{$max_requests}次/{$window_seconds}秒", true);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retry_after' => $allowed ? 0 : $block_duration,
        ];
    } catch (Exception $e) {
        return ['allowed' => true, 'remaining' => $max_requests, 'retry_after' => 0];
    }
}

/**
 * 全局IP限流检查（建议在入口文件调用）
 */
function sec_rate_limit_ip($max_requests = 120, $window_seconds = 60, $block_duration = 300) {
    $ip = sec_get_ip();
    $key = "ip:{$ip}";
    $result = sec_rate_limit_check($key, $max_requests, $window_seconds, $block_duration);

    header('X-RateLimit-Limit: ' . $max_requests);
    header('X-RateLimit-Remaining: ' . $result['remaining']);

    if (!$result['allowed']) {
        header('Retry-After: ' . $result['retry_after']);
        http_response_code(429);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>请求过于频繁</title></head>
        <body style="background:#f5f7fa;"><div style="max-width:500px;margin:100px auto;background:#fff;padding:40px;border-radius:12px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.1);">
        <h2 style="color:#fa8c16;">⏳ 请求过于频繁</h2><p style="color:#64748b;">请在 ' . $result['retry_after'] . ' 秒后重试</p></div></body></html>');
    }
    return $result;
}

// ===================== API签名校验=====================

/**
 * 生成API密钥对
 */
function sec_generate_api_key_pair() {
    $api_key = 'ak_' . substr(bin2hex(random_bytes(16)), 0, 32);
    $api_secret = 'sk_' . bin2hex(random_bytes(32));
    return ['api_key' => $api_key, 'api_secret' => $api_secret];
}

/**
 * 生成请求签名
 * 签名算法：HMAC-SHA256( api_secret + timestamp + nonce + method + uri + body_hash
 */
function sec_generate_signature($api_secret, $timestamp, $nonce, $method, $uri, $body = '') {
    $body_hash = hash('sha256', $body);
    $sign_str = "{$timestamp}\n{$nonce}\n{$method}\n{$uri}\n{$body_hash}";
    return base64_encode(hash_hmac('sha256', $sign_str, $api_secret, true));
}

/**
 * 验证API请求签名
 * @return array ['valid' => bool, 'message' => string, 'api_key' => string]
 */
function sec_verify_api_request() {
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? $_GET['timestamp'] ?? 0;
    $nonce = $_SERVER['HTTP_X_NONCE'] ?? $_GET['nonce'] ?? '';
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? $_GET['signature'] ?? '';

    if (empty($api_key) || empty($timestamp) || empty($nonce) || empty($signature)) {
        sec_log_attack('sign_invalid', 'high', '', '缺少签名参数', true);
        return ['valid' => false, 'message' => '缺少必要的签名参数'];
    }

    // 时间戳校验（±5分钟
    $diff = abs(time() - intval($timestamp));
    if ($diff > 300) {
        sec_log_attack('sign_invalid', 'medium', '', "时间戳偏差过大: {$diff}秒", true);
        return ['valid' => false, 'message' => '请求已过期'];
    }

    // nonce防重放
    try {
        $existing_nonce = Database::fetch("SELECT id FROM security_nonces WHERE nonce = ?", [$nonce]);
        if ($existing_nonce) {
            sec_log_attack('replay', 'high', '', "重放攻击检测: nonce={$nonce}", true);
            return ['valid' => false, 'message' => '请求不能重复使用'];
        }
        Database::insert('security_nonces', [
            'nonce' => $nonce,
            'api_key' => $api_key,
            'ip_address' => sec_get_ip(),
        ]);
    } catch (Exception $e) {}

    // 获取API密钥信息
    try {
        $key_info = Database::fetch("SELECT * FROM api_keys WHERE api_key = ? AND status = 'active'", [$api_key]);
        if (!$key_info) {
            sec_log_attack('sign_invalid', 'high', '', "无效的API Key: {$api_key}", true);
            return ['valid' => false, 'message' => 'API密钥无效'];
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $body = file_get_contents('php://input') ?: '';

        $expected_sign = sec_generate_signature($key_info['api_secret'], $timestamp, $nonce, $method, $uri, $body);

        if (!hash_equals($expected_sign, $signature)) {
            sec_log_attack('sign_invalid', 'high', '', "签名不匹配", true);
            return ['valid' => false, 'message' => '签名验证失败'];
        }

        // 更新最后使用时间
        Database::query("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?", [$key_info['id']]);

        return ['valid' => true, 'message' => '验证通过', 'api_key' => $api_key, 'user_id' => $key_info['user_id']];
    } catch (Exception $e) {
        return ['valid' => false, 'message' => '验证异常: ' . $e->getMessage()];
    }
}

/**
 * 要求API签名验证（失败终止）
 */
function sec_require_api_signature() {
    $result = sec_verify_api_request();
    if (!$result['valid']) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => $result['message'], 'code' => 'SIGNATURE_INVALID']);
        exit;
    }
    return $result;
}

// ===================== 数据防篡改=====================

/**
 * 计算数据指纹
 */
function sec_compute_fingerprint($table, $record_id, $data) {
    ksort($data);
    $serialized = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
    $salt = $table . '|' . $record_id . '|';
    return hash('sha256', $salt . $serialized);
}

/**
 * 保存数据指纹（写入/更新时调用）
 */
function sec_save_fingerprint($table, $record_id, $data, $fields = []) {
    try {
        $fingerprint = sec_compute_fingerprint($table, $record_id, $data);

        // 获取当前最大版本号
        $latest = Database::fetch(
            "SELECT MAX(version) as max_ver FROM data_integrity_fingerprints WHERE table_name = ? AND record_id = ?",
            [$table, $record_id]
        );
        $new_version = intval($latest['max_ver'] ?? 0) + 1;

        Database::insert('data_integrity_fingerprints', [
            'table_name' => $table,
            'record_id' => $record_id,
            'fingerprint' => $fingerprint,
            'fields_hash' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'version' => $new_version,
            'status' => 'valid',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
        return $fingerprint;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 验证数据完整性
 */
function sec_verify_fingerprint($table, $record_id, $current_data) {
    try {
        $latest = Database::fetch(
            "SELECT * FROM data_integrity_fingerprints WHERE table_name = ? AND record_id = ? ORDER BY version DESC LIMIT 1",
            [$table, $record_id]
        );
        if (!$latest) {
            return ['valid' => true, 'message' => '无历史指纹记录'];
        }

        $current_fp = sec_compute_fingerprint($table, $record_id, $current_data);

        if (hash_equals($latest['fingerprint'], $current_fp)) {
            Database::query(
                "UPDATE data_integrity_fingerprints SET verified_at = NOW(), status = 'valid' WHERE id = ?",
                [$latest['id']]
            );
            return ['valid' => true, 'message' => '数据完整'];
        } else {
            Database::query(
                "UPDATE data_integrity_fingerprints SET status = 'tampered' WHERE id = ?",
                [$latest['id']]
            );
            sec_log_attack('data_tamper', 'critical', json_encode(['table' => $table, 'id' => $record_id]), '数据指纹不匹配，疑似篡改', true);
            return ['valid' => false, 'message' => '数据完整性校验失败，疑似被篡改'];
        }
    } catch (Exception $e) {
        return ['valid' => true, 'message' => '校验异常: ' . $e->getMessage()];
    }
}

/**
 * 批量校验关键表数据完整性（定时任务调用）
 * @return array 篡改记录数
 */
function sec_batch_verify_critical_data() {
    $tampered_count = 0;
    $tables = [
        'ad_stats' => ['id', 'clicks', 'impressions', 'earnings', 'stat_date'],
        'ad_earnings' => ['id', 'user_id', 'amount', 'status', 'created_at'],
        'orders' => ['id', 'order_no', 'user_id', 'amount', 'status'],
        'users' => ['id', 'username', 'balance', 'role'],
    ];

    foreach ($tables as $table => $fields) {
        try {
            $records = Database::fetchAll("SELECT " . implode(',', $fields) . " FROM {$table} ORDER BY id DESC LIMIT 100");
            foreach ($records as $rec) {
                $rid = $rec['id'];
                $result = sec_verify_fingerprint($table, $rid, $rec);
                if (!$result['valid']) $tampered_count++;
            }
        } catch (Exception $e) {}
    }
    return $tampered_count;
}

// ===================== 文件上传安全=====================

/**
 * 安全文件上传校验
 * @param array $file $_FILES 中的文件项
 * @param array $allowed_types 允许的MIME类型
 * @param int $max_size 最大大小(字节)
 * @param string $upload_dir 上传目录
 * @return array ['success' => bool, 'path' => string, 'message' => string]
 */
function sec_safe_upload($file, $allowed_types = ['image/jpeg', 'image/png', 'image/gif'], $max_size = 5242880, $upload_dir = '') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => '无效的上传文件'];
    }

    // 大小检查
    if ($file['size'] > $max_size) {
        sec_log_attack('file_upload', 'medium', '', "文件过大: {$file['size']}字节", true);
        return ['success' => false, 'message' => '文件大小超过限制'];
    }

    // MIME类型检查
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($file['tmp_name']);
    if (!in_array($real_mime, $allowed_types, true)) {
        sec_log_attack('file_upload', 'high', '', "非法文件类型: {$real_mime}", true);
        return ['success' => false, 'message' => '不支持的文件类型'];
    }

    // 文件名安全处理：根据真实MIME类型确定扩展名
    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
    $ext = $ext_map[$real_mime] ?? 'bin';

    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;

    // 检查文件内容中的恶意代码（图片二次渲染检测
    if (strpos($real_mime, 'image/') === 0) {
        $content = file_get_contents($file['tmp_name']);
        if (preg_match('/<\?php|<script/i', $content)) {
            sec_log_attack('file_upload', 'critical', '', "图片中包含PHP/脚本代码", true);
            return ['success' => false, 'message' => '文件包含恶意内容'];
        }
        // 验证是否为真实图片
        $image_info = @getimagesize($file['tmp_name']);
        if (!$image_info) {
            sec_log_attack('file_upload', 'high', '', "伪造图片文件", true);
            return ['success' => false, 'message' => '不是有效的图片文件'];
        }
    }

    if ($upload_dir) {
        $target_path = rtrim($upload_dir, '/') . '/' . $safe_name;
        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            return ['success' => false, 'message' => '文件保存失败'];
        }
        // 重置权限
        @chmod($target_path, 0644);
        return ['success' => true, 'path' => $target_path, 'filename' => $safe_name, 'mime' => $real_mime];
    }

    return ['success' => true, 'filename' => $safe_name, 'mime' => $real_mime, 'tmp_path' => $file['tmp_name']];
}

// ===================== 密码策略=====================

/**
 * 密码强度校验
 * @return array ['valid' => bool, 'message' => string]
 */
function sec_validate_password_strength($password) {
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = '密码长度不能少于8位';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = '必须包含小写字母';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = '必须包含大写字母';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = '必须包含数字';
    }
    if (!preg_match('/[!@#$%^&*()\-_=+{}[\]:;\"\'<>,.?\/\\\~`]/', $password)) {
        $errors[] = '必须包含特殊符号';
    }
    if (preg_match('/(.)\\1{2,}/', $password)) {
        $errors[] = '不能包含连续3个以上相同字符';
    }

    if (count($errors) > 0) {
        return ['valid' => false, 'message' => implode('；', $errors)];
    }
    return ['valid' => true, 'message' => '密码强度合格'];
}

/**
 * 记录登录尝试
 */
function sec_log_login_attempt($username, $success, $reason = '') {
    try {
        Database::insert('security_login_attempts', [
            'username' => $username,
            'ip_address' => sec_get_ip(),
            'user_agent' => sec_get_user_agent(),
            'success' => $success ? 1 : 0,
            'fail_reason' => $reason,
        ]);
    } catch (Exception $e) {}
}

/**
 * 检查登录失败次数（防暴力破解
 */
function sec_check_login_brute_force($username) {
    $ip = sec_get_ip();
    $window = time() - 900; // 15分钟窗口
    try {
        // IP维度
        $ip_fail = Database::fetch(
            "SELECT COUNT(*) as cnt FROM security_login_attempts WHERE ip_address = ? AND success = 0 AND UNIX_TIMESTAMP(created_at) > ?",
            [$ip, $window]
        );
        if (intval($ip_fail['cnt'] ?? 0) >= 10) {
            sec_block_ip_temporary($ip, 'login_brute_force', 1800);
            return ['blocked' => true, 'reason' => '登录失败次数过多，请30分钟后再试'];
        }
        // 用户名维度
        $user_fail = Database::fetch(
            "SELECT COUNT(*) as cnt FROM security_login_attempts WHERE username = ? AND success = 0 AND UNIX_TIMESTAMP(created_at) > ?",
            [$username, $window]
        );
        if (intval($user_fail['cnt'] ?? 0) >= 10) {
            return ['blocked' => true, 'reason' => '该账号登录失败次数过多，请30分钟后再试'];
        }
    } catch (Exception $e) {}
    return ['blocked' => false];
}

// ===================== 管理员操作审计=====================

/**
 * 记录管理员操作审计日志
 */
function sec_admin_audit($action, $target_type = '', $target_id = 0, $detail = []) {
    try {
        $admin = function_exists('admin_user') ? admin_user() : null;
        Database::insert('admin_audit_logs', [
            'admin_id' => $admin ? intval($admin['id']) : 0,
            'admin_name' => $admin ? ($admin['username'] ?? '') : '',
            'action' => $action,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'ip_address' => sec_get_ip(),
            'detail' => is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $detail,
        ]);
    } catch (Exception $e) {}
}

// ===================== 安全头设置=====================

/**
 * 设置安全响应头
 */
function sec_set_security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-Permitted-Cross-Domain-Policies: none');
}

// ===================== 输入过滤函数（用于严格过滤用户输入）=====================

/**
 * 严格过滤字符串（去除HTML标签、特殊字符）
 */
function sec_clean_str($str, $max_len = 255) {
    $str = trim($str ?? '');
    $str = strip_tags($str);
    $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    if (strlen($str) > $max_len) {
        $str = substr($str, 0, $max_len);
    }
    return $str;
}

/**
 * 安全的整数获取
 */
function sec_int($val, $min = null, $max = null) {
    $val = intval($val);
    if ($min !== null && $val < $min) $val = $min;
    if ($max !== null && $val > $max) $val = $max;
    return $val;
}

/**
 * 清理过期数据（定时任务调用）
 */
function sec_cleanup_expired_data() {
    try {
        // 清理7天前的nonce
        Database::query("DELETE FROM security_nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        // 清理30天前的限流记录
        Database::query("DELETE FROM security_rate_limit WHERE window_start < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))");
        // 清理过期的临时IP封禁
        Database::query("DELETE FROM security_ip_blocks WHERE block_type = 'temporary' AND expires_at < NOW()");
        // 清理90天前的安全日志
        Database::query("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        // 清理90天前的登录尝试
        Database::query("DELETE FROM security_login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    } catch (Exception $e) {}
}
