<?php
require_once __DIR__ . '/app.php';

class Database {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo !== null) return self::$pdo;
        $cfg = config('db');
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}",
            ]);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
        return self::$pdo;
    }

    public static function query($sql, $params = []) {
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }

    public static function fetchColumn($sql, $params = [], $column = 0) {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetchColumn($column);
        return $result === false ? null : $result;
    }

    public static function fetchValue($sql, $params = []) {
        return self::fetchColumn($sql, $params, 0);
    }

    public static function insert($table, $data) {
        $pdo = self::connect();
        $fields = array_keys($data);
        $placeholders = array_map(function($f) { return ":$f"; }, $fields);
        $sql = "INSERT INTO $table (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return $pdo->lastInsertId();
    }

    public static function update($table, $data, $where, $whereParams = []) {
        $pdo = self::connect();
        $set = [];
        $params = [];
        $idx = 0;
        foreach ($data as $k => $v) {
            $tag = "set_" . $idx;
            $set[] = "$k = :$tag";
            $params[$tag] = $v;
            $idx++;
        }
        foreach ($whereParams as $v) {
            $tag = "wh_" . $idx;
            $where = preg_replace('/\?/', ":$tag", $where, 1);
            $params[$tag] = $v;
            $idx++;
        }
        $sql = "UPDATE $table SET " . implode(',', $set) . " WHERE $where";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function delete($table, $where, $params = []) {
        $pdo = self::connect();
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public static function beginTransaction() {
        $pdo = self::connect();
        return $pdo->beginTransaction();
    }

    public static function commit() {
        $pdo = self::connect();
        return $pdo->commit();
    }

    public static function rollBack() {
        $pdo = self::connect();
        return $pdo->rollBack();
    }

    public static function fetchAllCached($sql, $params = [], $ttl = 3600) {
        $cacheKey = 'db_' . md5($sql . json_encode($params));
        $cached = cache($cacheKey);
        if ($cached !== null) return $cached;
        $result = self::fetchAll($sql, $params);
        cache($cacheKey, $result, $ttl);
        return $result;
    }

    public static function fetchCached($sql, $params = [], $ttl = 3600) {
        $cacheKey = 'db_' . md5($sql . json_encode($params));
        $cached = cache($cacheKey);
        if ($cached !== null) return $cached;
        $result = self::fetch($sql, $params);
        cache($cacheKey, $result, $ttl);
        return $result;
    }
}

function db() {
    return Database::connect();
}

function db_init_settings() {
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function db_get_setting($key, $default = null) {
    try {
        $row = Database::fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        if ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') {
            return $row['setting_value'];
        }
    } catch (Exception $e) {
    }
    return $default;
}

function db_set_setting($key, $value) {
    try {
        db_init_settings();
        $exists = Database::fetch("SELECT 1 FROM settings WHERE setting_key = ?", [$key]);
        if ($exists) {
            $result = Database::update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
        } else {
            $result = Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
        _clear_settings_cache();
        return $result;
    } catch (Exception $e) {
        return false;
    }
}

function _get_settings_cache_file() {
    return __DIR__ . '/../data/settings_cache.php';
}

function _clear_settings_cache() {
    static $cached_settings = null;
    static $cache_loaded = false;
    $cached_settings = null;
    $cache_loaded = false;
    $cache_file = _get_settings_cache_file();
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

class DataCache {
    private static $cache = [];
    private static $cacheDir = null;

    private static function init() {
        if (self::$cacheDir === null) {
            self::$cacheDir = __DIR__ . '/../data/cache';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
    }

    public static function get($key, $default = null) {
        if (isset(self::$cache[$key])) {
            $item = self::$cache[$key];
            if ($item['expire'] == 0 || $item['expire'] > time()) {
                return $item['value'];
            }
            unset(self::$cache[$key]);
        }
        return $default;
    }

    public static function set($key, $value, $ttl = 300) {
        self::$cache[$key] = [
            'value' => $value,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    public static function delete($key) {
        unset(self::$cache[$key]);
        return true;
    }

    public static function getFile($key, $default = null) {
        self::init();
        $mem_value = self::get($key, '__NOCACHE__');
        if ($mem_value !== '__NOCACHE__') {
            return $mem_value;
        }
        $file = self::$cacheDir . '/' . md5($key) . '.php';
        if (!file_exists($file)) {
            return $default;
        }
        $data = include $file;
        if (!is_array($data) || !isset($data['expire']) || !isset($data['value'])) {
            return $default;
        }
        if ($data['expire'] > 0 && $data['expire'] < time()) {
            @unlink($file);
            return $default;
        }
        self::set($key, $data['value'], max(0, $data['expire'] - time()));
        return $data['value'];
    }

    public static function setFile($key, $value, $ttl = 300) {
        self::init();
        self::set($key, $value, $ttl);
        $file = self::$cacheDir . '/' . md5($key) . '.php';
        $data = [
            'value' => $value,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
        ];
        $content = '<?php return ' . var_export($data, true) . ';';
        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }

    public static function deleteFile($key) {
        self::init();
        self::delete($key);
        $file = self::$cacheDir . '/' . md5($key) . '.php';
        if (file_exists($file)) {
            @unlink($file);
        }
        return true;
    }

    public static function remember($key, $callback, $ttl = 300, $use_file = false) {
        if ($use_file) {
            $value = self::getFile($key, '__NOCACHE__');
        } else {
            $value = self::get($key, '__NOCACHE__');
        }
        if ($value !== '__NOCACHE__') {
            return $value;
        }
        $value = call_user_func($callback);
        if ($use_file) {
            self::setFile($key, $value, $ttl);
        } else {
            self::set($key, $value, $ttl);
        }
        return $value;
    }

    public static function flush() {
        self::$cache = [];
        self::init();
        $files = glob(self::$cacheDir . '/*.php');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        return true;
    }
}

function db_get_all_settings() {
    try {
        return Database::fetchAll("SELECT setting_key, setting_value FROM settings");
    } catch (Exception $e) {
        return [];
    }
}

function db_get_settings($prefix) {
    $result = [];
    try {
        $rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE ?", [$prefix . '_%']);
        foreach ($rows as $row) {
            $key = substr($row['setting_key'], strlen($prefix) + 1);
            $result[$key] = $row['setting_value'];
        }
    } catch (Exception $e) {
    }
    return $result;
}

function db_set_settings($prefix, $settings) {
    if (!is_array($settings) || empty($settings)) {
        return false;
    }
    db_init_settings();
    foreach ($settings as $key => $value) {
        $full_key = $prefix . '_' . $key;
        db_set_setting($full_key, $value);
    }
    return true;
}

function db_load_site_settings() {
    db_init_settings();
    static $cached_settings = null;
    static $cache_loaded = false;
    
    if ($cache_loaded && $cached_settings !== null) {
        _apply_settings($cached_settings);
        return;
    }
    
    $cache_file = _get_settings_cache_file();
    $settings_map = null;
    
    if (file_exists($cache_file)) {
        $cache_mtime = filemtime($cache_file);
        if (time() - $cache_mtime < 300) {
            $cached_data = include $cache_file;
            if (is_array($cached_data) && !empty($cached_data)) {
                $settings_map = $cached_data;
            }
        }
    }
    
    if ($settings_map === null) {
        try {
            $all_settings = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
            $settings_map = [];
            foreach ($all_settings as $row) {
                $settings_map[$row['setting_key']] = $row['setting_value'];
            }
            if (!empty($settings_map)) {
                $cache_content = '<?php return ' . var_export($settings_map, true) . ';';
                @file_put_contents($cache_file, $cache_content, LOCK_EX);
            }
        } catch (Exception $e) {
            $settings_map = [];
        }
    }
    
    $cached_settings = $settings_map;
    $cache_loaded = true;
    _apply_settings($settings_map);
}

function _apply_settings($settings_map) {
    $keys = ['title', 'subtitle', 'description', 'keywords', 'logo_text', 'logo_icon',
             'hero_title', 'hero_subtitle', 'footer_company', 'footer_copyright',
             'footer_icp', 'footer_contact'];
    foreach ($keys as $k) {
        $db_key = 'site_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            config_set('site.' . $k, $settings_map[$db_key]);
        }
    }
    $api_keys = ['base_url', 'mn_bh', 'mn_key', 'mn_keye', 'mn_vs'];
    foreach ($api_keys as $k) {
        $db_key = 'mnbt_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            config_set('mnbt.' . $k, $settings_map[$db_key]);
        }
    }
    $pay_keys = ['enabled', 'api_url', 'pid', 'key', 'type'];
    foreach ($pay_keys as $k) {
        $db_key = 'pay_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled') {
                config_set('payment.' . $k, (bool)$db_val);
            } else {
                config_set('payment.' . $k, $db_val);
            }
        }
    }
    $idv_keys = ['enabled', 'api_url', 'appkey', 'required'];
    foreach ($idv_keys as $k) {
        $db_key = 'idverify_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled' || $k === 'required') {
                config_set('idverify.' . $k, (bool)$db_val);
            } else {
                config_set('idverify.' . $k, $db_val);
            }
        }
    }
    $frp_keys = ['enabled', 'admin_api_url', 'admin_user', 'admin_password', 'server_addr', 'server_port', 'token', 'local_ip', 'local_port', 'port_range', 'public_domain'];
    foreach ($frp_keys as $k) {
        $db_key = 'frp_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled') {
                config_set('frp.' . $k, (bool)$db_val);
            } elseif ($k === 'server_port' || $k === 'local_port') {
                config_set('frp.' . $k, intval($db_val));
            } else {
                config_set('frp.' . $k, $db_val);
            }
        }
    }
    $bt_keys = ['enabled', 'api_url', 'api_key'];
    foreach ($bt_keys as $k) {
        $db_key = 'bt_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled') {
                config_set('bt_panel.' . $k, (bool)$db_val);
            } else {
                config_set('bt_panel.' . $k, $db_val);
            }
        }
    }
    $kvm_keys = ['enabled', 'host', 'port', 'user', 'password', 'public_domain', 'bridge', 'storage'];
    foreach ($kvm_keys as $k) {
        $db_key = 'kvm_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled') {
                config_set('kvm.' . $k, (bool)$db_val);
            } elseif ($k === 'port') {
                config_set('kvm.' . $k, intval($db_val));
            } else {
                config_set('kvm.' . $k, $db_val);
            }
        }
    }
    $ai_keys = ['enabled', 'api_url', 'model', 'system_prompt', 'temperature', 'num_ctx', 'max_tokens'];
    foreach ($ai_keys as $k) {
        $db_key = 'ai_' . $k;
        if (isset($settings_map[$db_key]) && $settings_map[$db_key] !== '' && $settings_map[$db_key] !== null) {
            $db_val = $settings_map[$db_key];
            if ($k === 'enabled') {
                config_set('ai.' . $k, (bool)$db_val);
            } elseif ($k === 'temperature') {
                config_set('ai.' . $k, floatval($db_val));
            } elseif ($k === 'num_ctx' || $k === 'max_tokens') {
                config_set('ai.' . $k, intval($db_val));
            } else {
                config_set('ai.' . $k, $db_val);
            }
        }
    }
}

function idverify_api_query($realname, $idcard) {
    $cfg = config('idverify');
    if (!$cfg || empty($cfg['api_url']) || empty($cfg['appkey'])) {
        return ['success' => false, 'message' => '系统未配置实名认证接口'];
    }
    $params = [
        'key' => $cfg['appkey'],
        'idcard' => $idcard,
        'realname' => $realname,
        'orderid' => 1,
    ];
    $url = $cfg['api_url'] . '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => '请求失败: ' . $err];
    }
    $data = json_decode($resp, true);
    if (!$data) {
        return ['success' => false, 'message' => '接口返回数据异常'];
    }
    if (isset($data['error_code']) && $data['error_code'] == 0 && isset($data['result']['res']) && $data['result']['res'] == 1) {
        return ['success' => true, 'match' => true, 'message' => $data['reason'] ?? '验证通过', 'orderid' => $data['result']['orderid'] ?? ''];
    }
    if (isset($data['result']['res']) && $data['result']['res'] == 2) {
        return ['success' => true, 'match' => false, 'message' => '姓名与身份证号码不匹配'];
    }
    return ['success' => false, 'message' => $data['reason'] ?? '验证失败', 'raw' => $data];
}

function db_ensure_idverify_columns() {
    $pdo = db();
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $row['Field'];
    }
    if (!in_array('real_name', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN real_name VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
    }
    if (!in_array('id_card', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN id_card VARCHAR(32) DEFAULT NULL"); } catch (Exception $e) {}
    }
    if (!in_array('id_verify_status', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN id_verify_status TINYINT DEFAULT 0"); } catch (Exception $e) {}
    }
    if (!in_array('id_verify_time', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN id_verify_time TIMESTAMP NULL"); } catch (Exception $e) {}
    }
    if (!in_array('id_verify_orderid', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN id_verify_orderid VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    }
    if (!in_array('idcard_front_img', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN idcard_front_img VARCHAR(255) DEFAULT NULL COMMENT '身份证正面图片路径'"); } catch (Exception $e) {}
    }
    if (!in_array('idcard_back_img', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN idcard_back_img VARCHAR(255) DEFAULT NULL COMMENT '身份证反面图片路径'"); } catch (Exception $e) {}
    }
    if (!in_array('role', $cols)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user','admin') DEFAULT 'user'"); } catch (Exception $e) {}
    }
    return true;
}

function db_ensure_host_frp_columns() {
    $pdo = db();
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM hosts");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    } catch (Exception $e) {
        return false;
    }
    $need_cols = [
        'frp_rule_name' => 'VARCHAR(100) DEFAULT NULL',
        'frp_local_port' => 'INT DEFAULT NULL',
        'frp_remote_port' => 'INT DEFAULT NULL',
        'frp_remote_addr' => 'VARCHAR(200) DEFAULT NULL',
        'frp_public_url' => 'VARCHAR(255) DEFAULT NULL',
        'frp_status' => 'VARCHAR(20) DEFAULT NULL',
        'frp_api_response' => 'TEXT DEFAULT NULL',
        'uuid' => 'VARCHAR(128) DEFAULT NULL UNIQUE',
    ];
    foreach ($need_cols as $col => $def) {
        if (!in_array($col, $cols)) {
            try { $pdo->exec("ALTER TABLE hosts ADD COLUMN $col $def"); } catch (Exception $e) {}
        }
    }
    return true;
}

function frp_api_request($method, $path, $payload = null, $payload_format = null) {
    $cfg = config('frp');
    if (!$cfg || empty($cfg['admin_api_url'])) {
        return ['success' => false, 'message' => 'FRP 接口未配置'];
    }
    $url = rtrim($cfg['admin_api_url'], '/') . '/' . ltrim($path, '/');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    $headers = [];
    if ($payload !== null) {
        if (is_string($payload)) {
            $body = $payload;
        } elseif ($payload_format === 'toml') {
            $body = frp_toml_build($payload);
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($payload_format === 'toml') {
            $headers[] = 'Content-Type: text/toml';
        } else {
            $headers[] = 'Content-Type: application/json';
        }
    }
    if (!empty($cfg['admin_user']) && !empty($cfg['admin_password'])) {
        $headers[] = 'Authorization: Basic ' . base64_encode($cfg['admin_user'] . ':' . $cfg['admin_password']);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['success' => false, 'message' => '请求失败: ' . $err];
    }
    if ($httpcode >= 400) {
        return ['success' => false, 'message' => 'HTTP ' . $httpcode . ': ' . (is_string($resp) ? substr($resp, 0, 200) : '无响应内容')];
    }
    // 自动识别响应格式：JSON 或 TOML
    $format = 'json';
    $data = null;
    if (is_string($resp) && trim($resp) !== '') {
        $trimmed = trim($resp);
        $first = substr($trimmed, 0, 1);
        if ($first === '{' || $first === '[') {
            $data = json_decode($trimmed, true);
            if ($data === null) {
                // 虽然以 { 开头但解析失败，可能是 TOML 中的 [[proxies]]
                $data = frp_toml_parse($trimmed);
                $format = 'toml';
            }
        } else {
            // 非 JSON 起始符，按 TOML 解析
            $data = frp_toml_parse($trimmed);
            $format = 'toml';
            if ($data === null || (is_array($data) && empty($data) && empty($data['_common']) && empty($data['proxies']))) {
                // TOML 解析不出任何有意义的内容，再试一次 JSON
                $json_data = json_decode($trimmed, true);
                if ($json_data !== null) {
                    $data = $json_data;
                    $format = 'json';
                }
            }
        }
    }
    return ['success' => true, 'httpcode' => $httpcode, 'data' => $data, 'raw' => $resp, 'format' => $format];
}

function frp_toml_parse($content) {
    if (!is_string($content) || trim($content) === '') {
        return ['_common' => [], 'proxies' => []];
    }
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $result = [
        '_common' => [],
        'proxies' => [],
    ];
    $current_section = '_common';
    $current_proxy = null;

    foreach ($lines as $line) {
        $line = trim($line);
        // 跳过空行和注释
        if ($line === '') continue;
        if ($line[0] === '#' || strpos($line, '//') === 0) continue;

        // [[proxies]] - 数组表
        if (preg_match('/^\[\[(.+?)\]\]/', $line, $m)) {
            $name = trim($m[1]);
            if ($name === 'proxies') {
                if ($current_proxy !== null) {
                    $result['proxies'][] = $current_proxy;
                }
                $current_proxy = [];
                $current_section = '_proxy';
            }
            continue;
        }

        // [section] - 普通段
        if (preg_match('/^\[(.+?)\]/', $line, $m)) {
            $section = trim($m[1]);
            if ($current_proxy !== null && $current_section === '_proxy') {
                $result['proxies'][] = $current_proxy;
                $current_proxy = null;
            }
            $current_section = $section;
            continue;
        }

        // key = value
        if (strpos($line, '=') !== false) {
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // 解析值类型
            if (preg_match('/^"(.*)"$/', $value, $vm)) {
                $value = $vm[1];
            } elseif (preg_match('/^true$/i', $value)) {
                $value = true;
            } elseif (preg_match('/^false$/i', $value)) {
                $value = false;
            } elseif (is_numeric($value)) {
                if (strpos($value, '.') !== false) {
                    $value = floatval($value);
                } else {
                    $value = intval($value);
                }
            }

            if ($current_section === '_proxy' && $current_proxy !== null) {
                $current_proxy[$key] = $value;
            } elseif ($current_section === '_common') {
                $result['_common'][$key] = $value;
            } else {
                if (!isset($result[$current_section]) || !is_array($result[$current_section])) {
                    $result[$current_section] = [];
                }
                $result[$current_section][$key] = $value;
            }
        }
    }

    // 别忘了最后一个 proxy
    if ($current_proxy !== null && $current_section === '_proxy') {
        $result['proxies'][] = $current_proxy;
    }

    return $result;
}

function frp_toml_build($data) {
    if (!is_array($data)) return '';
    $toml = '';

    // 顶层 common 键
    if (is_array($data['_common'] ?? null)) {
        foreach ($data['_common'] as $k => $v) {
            $toml .= _frp_toml_kv($k, $v) . "\n";
        }
    }

    // 各命名段 [auth] [transport] 等
    foreach ($data as $section => $values) {
        if ($section === '_common' || $section === 'proxies') continue;
        if (!is_array($values) || empty($values)) continue;
        $toml .= "\n[$section]\n";
        foreach ($values as $k => $v) {
            $toml .= _frp_toml_kv($k, $v) . "\n";
        }
    }

    // [[proxies]]
    if (is_array($data['proxies'] ?? null)) {
        foreach ($data['proxies'] as $proxy) {
            if (!is_array($proxy)) continue;
            $toml .= "\n[[proxies]]\n";
            foreach ($proxy as $k => $v) {
                $toml .= _frp_toml_kv($k, $v) . "\n";
            }
        }
    }

    return $toml;
}

function _frp_toml_kv($key, $value) {
    if (is_bool($value)) {
        return "$key = " . ($value ? 'true' : 'false');
    } elseif (is_numeric($value) && !is_string($value)) {
        return "$key = $value";
    } elseif (is_string($value) && ctype_digit($value) && strlen($value) < 10) {
        // 纯数字字符串按数字处理
        return "$key = $value";
    } else {
        return "$key = \"" . addslashes(strval($value)) . "\"";
    }
}

function frp_get_config() {
    return frp_api_request('GET', '/config');
}

function frp_get_status() {
    return frp_api_request('GET', '/status');
}

function frp_clean_local_ip($input) {
    $input = trim(strval($input));
    if (strpos($input, ':') !== false) {
        $parts = explode(':', $input);
        $input = trim($parts[0]);
    }
    if (strpos($input, '/') !== false) {
        $parts = explode('/', $input);
        $input = trim($parts[0]);
    }
    if (empty($input)) {
        $input = '127.0.0.1';
    }
    return $input;
}

function frp_extract_proxies($response_data) {
    if (!is_array($response_data)) return [];
    // JSON 格式 {..., proxies: [...]}
    if (is_array($response_data['proxies'] ?? null)) {
        return $response_data['proxies'];
    }
    return [];
}

function frp_find_available_port($preferred = 0) {
    $current = frp_get_config();
    if (!$current['success']) {
        return intval($preferred) > 0 ? intval($preferred) : 20000;
    }
    $existing_ports = [];
    $proxies = frp_extract_proxies($current['data'] ?? null);
    foreach ($proxies as $p) {
        if (!is_array($p)) continue;
        if (!empty($p['remotePort'])) {
            $existing_ports[] = intval($p['remotePort']);
        }
        if (!empty($p['remote_port'])) {
            $existing_ports[] = intval($p['remote_port']);
        }
    }
    $cfg = config('frp');
    $range = $cfg['port_range'] ?? '2000-59999';
    $parts = explode('-', $range);
    $start = intval($parts[0] ?? 2000);
    $end = intval($parts[1] ?? 59999);
    if ($preferred > 0 && $preferred >= $start && $preferred <= $end && !in_array($preferred, $existing_ports)) {
        return intval($preferred);
    }
    for ($port = $start; $port <= $end; $port++) {
        if (!in_array($port, $existing_ports)) {
            return $port;
        }
    }
    return $start;
}

function frp_reload() {
    // 让 frpc 重新加载配置 (上传/应用/重启)
    // 依次尝试多种常见的 frpc reload 接口
    $endpoints = [
        ['method' => 'POST', 'path' => '/reload', 'payload' => null],
        ['method' => 'POST', 'path' => '/config/reload', 'payload' => null],
        ['method' => 'POST', 'path' => '/api/reload', 'payload' => null],
        ['method' => 'GET',  'path' => '/reload', 'payload' => null],
        ['method' => 'POST', 'path' => '/restart', 'payload' => null],
        ['method' => 'PUT',  'path' => '/config/reload', 'payload' => ['action' => 'reload']],
    ];

    $last_result = null;
    foreach ($endpoints as $ep) {
        $result = frp_api_request($ep['method'], $ep['path'], $ep['payload']);
        if ($result['success']) {
            return [
                'success' => true,
                'message' => '配置已上传并重新加载 (接口: ' . $ep['method'] . ' ' . $ep['path'] . ')',
                'endpoint' => $ep['path'],
                'raw' => $result['raw'] ?? '',
            ];
        }
        $last_result = $result;
    }

    // 所有 reload 接口都失败了，返回最后一次的信息，但不视为致命错误
    return [
        'success' => false,
        'warning' => true,
        'message' => '配置已写入，但 frpc reload 接口未响应 (' . ($last_result['message'] ?? '未知') . ')。如未生效请手动重启 frpc',
        'raw' => $last_result['raw'] ?? '',
    ];
}

function frp_add_proxy($rule_name, $type, $local_ip, $local_port, $remote_port) {
    $current = frp_get_config();
    if (!$current['success']) {
        return ['success' => false, 'message' => '获取FRP配置失败: ' . ($current['message'] ?? '未知错误')];
    }
    if (!is_array($current['data'])) {
        return ['success' => false, 'message' => 'FRP返回的配置格式异常: ' . substr($current['raw'] ?? '', 0, 200)];
    }

    $format = $current['format'] ?? 'toml';
    $clean_local_ip = frp_clean_local_ip($local_ip);
    $new_proxy = [
        'name' => $rule_name,
        'type' => $type,
        'localIP' => $clean_local_ip,
        'localPort' => intval($local_port),
        'remotePort' => intval($remote_port),
    ];

    // 提取现有 proxies 列表
    $existing_proxies = frp_extract_proxies($current['data']);

    // 替换或新增
    $new_proxies = [];
    $found = false;
    foreach ($existing_proxies as $p) {
        if (is_array($p) && isset($p['name']) && $p['name'] === $rule_name) {
            $new_proxies[] = $new_proxy;
            $found = true;
        } else {
            $new_proxies[] = $p;
        }
    }
    if (!$found) {
        $new_proxies[] = $new_proxy;
    }

    // 构建完整配置 payload 并发送 PUT
    if ($format === 'toml') {
        $payload = $current['data'];
        if (!is_array($payload)) $payload = [];
        $payload['proxies'] = $new_proxies;
        $put_result = frp_api_request('PUT', '/config', $payload, 'toml');
    } else {
        $payload = $current['data'];
        if (!is_array($payload)) $payload = [];
        $payload['proxies'] = $new_proxies;
        $put_result = frp_api_request('PUT', '/config', $payload, 'json');
    }

    if (!$put_result['success']) {
        return ['success' => false, 'message' => '写入FRP配置失败: ' . ($put_result['message'] ?? '未知错误')];
    }

    // ========== 关键：上传配置后必须让 frpc 重新加载才能生效 ==========
    $reload_result = frp_reload();

    $msg = '代理已添加并上传生效 (规则: ' . $rule_name . ', 远程端口: ' . $remote_port . ', 本地: ' . $clean_local_ip . ':' . $local_port . ')';
    if (!$reload_result['success']) {
        // reload 未响应，但配置已经写入，不视为整体失败，返回带警告
        return [
            'success' => true,
            'warning' => true,
            'message' => $msg . '；注意: frpc reload ' . ($reload_result['message'] ?? '未响应'),
            'rule_name' => $rule_name,
            'remote_port' => $remote_port,
            'local_ip' => $clean_local_ip,
            'local_port' => intval($local_port),
            'proxies_count' => count($new_proxies),
            'reload' => $reload_result,
        ];
    }

    return [
        'success' => true,
        'message' => $msg,
        'rule_name' => $rule_name,
        'remote_port' => $remote_port,
        'local_ip' => $clean_local_ip,
        'local_port' => intval($local_port),
        'proxies_count' => count($new_proxies),
        'reload' => $reload_result,
    ];
}

function frp_get_proxy_status_by_name($rule_name) {
    $status = frp_get_status();
    if (!$status['success']) return null;
    $proxies = frp_extract_proxies($status['data'] ?? null);
    foreach ($proxies as $p) {
        if (is_array($p) && isset($p['name']) && $p['name'] === $rule_name) {
            return $p;
        }
    }
    // status 接口格式可能不同，再试 config 接口
    $config = frp_get_config();
    if ($config['success']) {
        $proxies2 = frp_extract_proxies($config['data'] ?? null);
        foreach ($proxies2 as $p) {
            if (is_array($p) && isset($p['name']) && $p['name'] === $rule_name) {
                return array_merge($p, ['status' => 'unknown']);
            }
        }
    }
    return null;
}

function frp_delete_proxy($rule_name) {
    $current = frp_get_config();
    if (!$current['success']) {
        return ['success' => false, 'message' => '获取FRP配置失败: ' . ($current['message'] ?? '未知错误')];
    }
    if (!is_array($current['data'])) {
        return ['success' => false, 'message' => 'FRP返回的配置格式异常'];
    }

    $format = $current['format'] ?? 'toml';
    $existing_proxies = frp_extract_proxies($current['data']);

    $new_proxies = [];
    $found = false;
    foreach ($existing_proxies as $p) {
        if (is_array($p) && isset($p['name']) && $p['name'] === $rule_name) {
            $found = true;
            continue;
        }
        $new_proxies[] = $p;
    }

    if (!$found) {
        return ['success' => true, 'message' => '规则不存在，无需删除', 'skipped' => true];
    }

    if ($format === 'toml') {
        $payload = $current['data'];
        if (!is_array($payload)) $payload = [];
        $payload['proxies'] = $new_proxies;
        $put_result = frp_api_request('PUT', '/config', $payload, 'toml');
    } else {
        $payload = $current['data'];
        if (!is_array($payload)) $payload = [];
        $payload['proxies'] = $new_proxies;
        $put_result = frp_api_request('PUT', '/config', $payload, 'json');
    }

    if (!$put_result['success']) {
        return ['success' => false, 'message' => '写入FRP配置失败: ' . ($put_result['message'] ?? '未知错误')];
    }

    $reload_result = frp_reload();

    $msg = '代理规则已删除 (规则: ' . $rule_name . ')';
    return [
        'success' => true,
        'message' => $msg,
        'rule_name' => $rule_name,
        'proxies_count' => count($new_proxies),
        'reload' => $reload_result,
    ];
}

function bt_api_request($path, $params = []) {
    $cfg = config('bt_panel');
    if (!$cfg || empty($cfg['api_url'])) {
        return ['success' => false, 'message' => '宝塔面板API未配置'];
    }
    $api_key = $cfg['api_key'] ?? '';
    $url = rtrim($cfg['api_url'], '/') . '/' . ltrim($path, '/');

    // 添加API密钥到请求参数
    if (!empty($api_key)) {
        $params['api_key'] = $api_key;
        $params['request_token'] = md5(time() . '|' . $api_key);
        $params['request_time'] = time();
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $resp = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'message' => '请求失败: ' . $err];
    }
    if ($httpcode >= 400) {
        return ['success' => false, 'message' => 'HTTP ' . $httpcode . ': ' . (is_string($resp) ? substr($resp, 0, 200) : '无响应内容')];
    }

    $data = json_decode($resp, true);
    return ['success' => true, 'httpcode' => $httpcode, 'data' => $data, 'raw' => $resp];
}

function bt_test_connection() {
    // 测试宝塔面板API连接
    $result = bt_api_request('/api/api.php?gn=cfif', ['username' => 'test']);
    if ($result['success']) {
        // 尝试面板的测试接口
        if (is_array($result['data']) && isset($result['data']['code'])) {
            return ['success' => true, 'message' => 'API连接成功: code=' . $result['data']['code']];
        }
        // 否则尝试获取网站列表
        $sites_result = bt_api_request('/api/site', ['action' => 'list']);
        if ($sites_result['success']) {
            return ['success' => true, 'message' => '宝塔面板API连接成功'];
        }
        return ['success' => true, 'message' => 'API已连接，但返回格式不标准 (需要在宝塔面板配置API密钥)'];
    }
    return $result;
}

function bt_add_domain($username, $domain) {
    // 尝试通过面板API或宝塔面板API将公网IP:端口绑定到网站域名列表
    if (empty($username) || empty($domain)) {
        return ['success' => false, 'message' => '用户名或域名不能为空'];
    }

    $all_tried = [];

    // 方案1：MNBT面板自定义API接口（gn=bind / gn=bddomain / gn=AddDomain）
    $mnbt_endpoints = [
        '/api/api.php?gn=bind',
        '/api/api.php?gn=bddomain',
        '/api/api.php?gn=AddDomain',
    ];
    foreach ($mnbt_endpoints as $ep) {
        $result = bt_api_request($ep, [
            'username' => $username,
            'domain' => $domain,
        ]);
        if ($result['success'] && is_array($result['data'])) {
            $code = intval($result['data']['code'] ?? -1);
            if ($code === 200) {
                return ['success' => true, 'auto' => true, 'message' => '宝塔面板域名自动绑定成功: ' . $domain];
            }
            if (!empty($result['data']['msg'])) {
                $all_tried[] = $ep . ' -> ' . $result['data']['msg'];
            } else {
                $all_tried[] = $ep . ' -> code=' . $code;
            }
        } elseif (!empty($result['message'])) {
            $all_tried[] = $ep . ' -> ' . $result['message'];
        }
    }

    // 方案2：标准宝塔面板 API (/site?action=AddDomain 等)
    $bt_actions = [
        ['path' => '/site', 'params' => ['action' => 'AddDomain', 'domain' => $domain, 'username' => $username]],
        ['path' => '/site?action=AddDomain', 'params' => ['domain' => $domain, 'username' => $username]],
    ];
    foreach ($bt_actions as $item) {
        $result = bt_api_request($item['path'], $item['params']);
        if ($result['success'] && is_array($result['data'])) {
            $code = intval($result['data']['code'] ?? -1);
            if ($code === 200) {
                return ['success' => true, 'auto' => true, 'message' => '宝塔面板域名自动绑定成功: ' . $domain];
            }
            if (!empty($result['data']['msg'])) {
                $all_tried[] = $item['path'] . ' -> ' . $result['data']['msg'];
            } else {
                $all_tried[] = $item['path'] . ' -> code=' . $code;
            }
        } elseif (!empty($result['message'])) {
            $all_tried[] = $item['path'] . ' -> ' . $result['message'];
        }
    }

    // 所有API都没有明确成功，返回提示让用户手动设置
    $hint = '请登录宝塔面板 → 网站 → ' . htmlspecialchars($username) . ' → 域名管理 → 添加域名 → 填入: ' . $domain;
    return [
        'success' => true,
        'warning' => true,
        'auto' => false,
        'message' => '穿透已添加成功！域名需在宝塔面板中绑定: ' . $domain,
        'hint' => $hint,
        'raw' => implode('; ', array_slice($all_tried, 0, 3)),
    ];
}
