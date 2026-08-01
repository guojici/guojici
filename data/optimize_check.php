<?php
/**
 * 性能优化一键检测脚本
 * 检测：OPcache、PHP-FPM进程数、Session锁、数据库连接、Redis缓存等
 * 使用：php /www/wwwroot/192.168.3.2_4561/data/optimize_check.php
 */

require_once __DIR__ . '/../config/helper.php';

echo "=== guojici云性能优化检测 ===\n\n";

// 1. OPcache检测
echo "【1】OPcache 状态\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status && $status['opcache_enabled']) {
        echo "✓ OPcache 已启用\n";
        echo "  已缓存文件: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
        echo "  命中率: " . round(($status['opcache_statistics']['opcache_hit_rate'] ?? 0), 2) . "%\n";
        echo "  内存使用: " . round(($status['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    } else {
        echo "✗ OPcache 未启用 (性能损失 50%+)\n";
    }
} else {
    echo "✗ OPcache 扩展未安装\n";
}
echo "\n";

// 2. Session锁检测
echo "【2】Session 锁机制\n";
if (session_status() === PHP_SESSION_NONE) {
    echo "✓ 当前未启动Session\n";
} else {
    echo "⚠ Session已启动\n";
}

// 测试session_write_close是否可用
if (function_exists('session_write_close')) {
    echo "✓ session_write_close() 可用\n";
}
echo "\n";

// 3. 数据库连接
echo "【3】数据库连接\n";
try {
    $start = microtime(true);
    db();
    $time = round((microtime(true) - $start) * 1000, 2);
    echo "✓ 连接成功，耗时: {$time}ms\n";
    
    // 测试查询速度
    $start = microtime(true);
    Database::fetch("SELECT 1");
    $time = round((microtime(true) - $start) * 1000, 2);
    echo "  查询耗时: {$time}ms\n";
} catch (Exception $e) {
    echo "✗ 连接失败: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. 缓存检测
echo "【4】缓存系统\n";
$cacheStatus = CacheManager::getStatus();
if ($cacheStatus['redis_enabled']) {
    echo "✓ Redis 缓存已启用\n";
} else {
    echo "✗ Redis 未连接 (数据库压力大)\n";
}
if ($cacheStatus['apcu_enabled']) {
    echo "✓ APCu 本地缓存已启用\n";
} else {
    echo "✗ APCu 未安装 (可安装php-apcu提升小数据读取速度)\n";
}
echo "\n";

// 5. PHP配置
echo "【5】PHP 关键配置\n";
echo "  memory_limit: " . ini_get('memory_limit') . "\n";
echo "  max_execution_time: " . ini_get('max_execution_time') . "s\n";
echo "  post_max_size: " . ini_get('post_max_size') . "\n";
echo "  upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "  display_errors: " . ini_get('display_errors') . "\n";
echo "\n";

// 6. 扩展检测
echo "【6】关键扩展\n";
$exts = ['redis', 'curl', 'pdo_mysql', 'mbstring', 'json', 'gd', 'zip'];
foreach ($exts as $ext) {
    $has = extension_loaded($ext);
    echo "  " . ($has ? "✓" : "✗") . " $ext\n";
}
echo "\n";

// 7. FPM进程检测（CLI模式可能不准）
echo "【7】PHP-FPM 进程数建议\n";
$cpu_cores = function_exists('sysconf') ? sysconf(84) : 0; // _SC_NPROCESSORS_ONLN = 84
if (!$cpu_cores && is_file('/proc/cpuinfo')) {
    $cpuinfo = file_get_contents('/proc/cpuinfo');
    preg_match_all('/^processor/m', $cpuinfo, $matches);
    $cpu_cores = count($matches[0]);
}
if ($cpu_cores) {
    echo "  CPU核心数: $cpu_cores\n";
    echo "  建议FPM进程数: " . ($cpu_cores * 2) . " ~ " . ($cpu_cores * 4) . "\n";
    echo "  建议AI专用池: 20 ~ 50 (长连接多)\n";
} else {
    echo "  无法获取CPU核心数\n";
}
echo "\n";

echo "=== 优化建议 ===\n";
$issues = [];

if (!function_exists('opcache_get_status') || !(($status = opcache_get_status()) && $status['opcache_enabled'])) {
    $issues[] = "开启OPcache，性能提升50%+";
}
if (!$cacheStatus['redis_enabled']) {
    $issues[] = "安装Redis缓存，减少数据库压力";
}
if ($cpu_cores && $cpu_cores > 0) {
    $issues[] = "PHP-FPM进程数建议设置为 CPU核数*2 ~ CPU核数*4";
    $issues[] = "AI接口建议使用独立FPM进程池，避免长连接阻塞正常请求";
}
$issues[] = "Nginx开启gzip压缩和静态资源缓存";
$issues[] = "数据库添加合适索引，开启慢查询日志定位慢SQL";

foreach ($issues as $i => $issue) {
    echo ($i + 1) . ". $issue\n";
}
echo "\n";

echo "检测完成！\n";