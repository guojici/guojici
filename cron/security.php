<?php
/**
 * 安全定时任务
 * - 数据完整性校验（检测关键数据是否被篡改）
 * - 清理过期安全数据（nonce、限流记录、过期IP封禁、安全日志）
 */

require_once __DIR__ . '/../config/helper.php';

ignore_user_abort(true);
set_time_limit(0);

date_default_timezone_set('Asia/Shanghai');

function log_message($msg) {
    $log_file = ROOT_PATH . '/logs/security_cron.log';
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

$lock_file = __DIR__ . '/../logs/security_cron.lock';
$lock_max_age = 1800;

$lock_fp = @fopen($lock_file, 'w');
if (!$lock_fp) {
    log_message('SECURITY_CRON: Cannot create lock file');
    exit(1);
}

if (!@flock($lock_fp, LOCK_EX | LOCK_NB)) {
    $lock_age = time() - @filemtime($lock_file);
    if ($lock_age < $lock_max_age) {
        log_message('SECURITY_CRON: Another process is running, skip');
        exit(0);
    }
    @unlink($lock_file);
    $lock_fp = @fopen($lock_file, 'w');
    @flock($lock_fp, LOCK_EX);
}

register_shutdown_function(function() use ($lock_fp, $lock_file) {
    @flock($lock_fp, LOCK_UN);
    @fclose($lock_fp);
    @unlink($lock_file);
});

log_message('SECURITY_CRON: Start');

// 1. 数据完整性校验
try {
    $tampered = sec_batch_verify_critical_data();
    if ($tampered > 0) {
        log_message('SECURITY_CRON: Data integrity check found ' . $tampered . ' tampered records! [CRITICAL]');
    } else {
        log_message('SECURITY_CRON: Data integrity check passed');
    }
} catch (Exception $e) {
    log_message('SECURITY_CRON: Data integrity check error: ' . $e->getMessage());
}

// 2. 清理过期安全数据
try {
    sec_cleanup_expired_data();
    log_message('SECURITY_CRON: Expired security data cleaned');
} catch (Exception $e) {
    log_message('SECURITY_CRON: Cleanup error: ' . $e->getMessage());
}

// 3. 生成每日安全统计
try {
    $today_start = strtotime(date('Y-m-d 00:00:00'));
    $today_attacks = Database::fetch(
        "SELECT COUNT(*) as cnt, attack_type FROM security_logs WHERE UNIX_TIMESTAMP(created_at) >= ? GROUP BY attack_type",
        [$today_start]
    );
    $total = 0;
    foreach ((array)$today_attacks as $a) {
        $total += intval($a['cnt'] ?? 0);
    }
    log_message('SECURITY_CRON: Today attacks: ' . $total);
} catch (Exception $e) {}

log_message('SECURITY_CRON: Done');
exit(0);
