<?php
require_once __DIR__ . '/../config/helper.php';

ignore_user_abort(true);
set_time_limit(0);

date_default_timezone_set('Asia/Shanghai');

$lock_file = ROOT_PATH . '/logs/traffic_monitor.lock';
$lock_max_age = 1800;

$lock_fp = @fopen($lock_file, 'w');
if (!$lock_fp) {
    log_message('ERROR: Cannot create lock file');
    exit(1);
}

if (!@flock($lock_fp, LOCK_EX | LOCK_NB)) {
    $lock_age = time() - @filemtime($lock_file);
    if ($lock_age < $lock_max_age) {
        log_message('Another instance is running (lock age: ' . $lock_age . 's), exiting');
        fclose($lock_fp);
        exit(0);
    }
    log_message('Lock is stale (' . $lock_age . 's), forcing acquisition');
    @flock($lock_fp, LOCK_EX | LOCK_NB);
}

register_shutdown_function(function() use ($lock_fp, $lock_file) {
    @flock($lock_fp, LOCK_UN);
    @fclose($lock_fp);
    @unlink($lock_file);
});

function log_message($msg) {
    $log_file = ROOT_PATH . '/logs/traffic_monitor.log';
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function collect_traffic($host) {
    global $kvm;
    
    try {
        $vm_name = $host['vm_name'];
        if (empty($vm_name)) return false;
        
        $stats = $kvm->getNetworkStats($vm_name);
        if (!$stats || !isset($stats['total_bytes'])) {
            return false;
        }
        
        $total_bytes = $stats['total_bytes'];
        $rx_bytes = $stats['rx_bytes'] ?? 0;
        $tx_bytes = $stats['tx_bytes'] ?? 0;
        
        Database::insert('host_traffic', [
            'host_id' => $host['id'],
            'rx_bytes' => $rx_bytes,
            'tx_bytes' => $tx_bytes,
            'total_bytes' => $total_bytes,
            'collected_at' => date('Y-m-d H:i:s')
        ]);
        
        return $total_bytes;
    } catch (Exception $e) {
        log_message('ERROR collecting traffic for host ' . $host['id'] . ': ' . $e->getMessage());
        return false;
    }
}

function calculate_monthly_usage($host_id) {
    $reset_date = Database::fetch("SELECT traffic_reset_date FROM hosts WHERE id = ?", [$host_id]);
    $reset_date = $reset_date['traffic_reset_date'] ?? date('Y-m-01');
    
    $start_time = strtotime($reset_date . ' 00:00:00');
    
    $rows = Database::fetchAll("SELECT total_bytes, collected_at FROM host_traffic WHERE host_id = ? AND collected_at >= FROM_UNIXTIME(?) ORDER BY collected_at ASC", [$host_id, $start_time]);
    
    if (empty($rows)) {
        return 0;
    }
    
    $total_usage = 0;
    $prev_total = null;
    $prev_time = null;
    $reset_count = 0;
    
    foreach ($rows as $row) {
        $current_total = intval($row['total_bytes']);
        $current_time = strtotime($row['collected_at']);
        
        if ($prev_total !== null) {
            if ($current_total >= $prev_total) {
                $total_usage += ($current_total - $prev_total);
            } else {
                $estimated_lost = 0;
                if ($prev_time !== null && $prev_total > 0) {
                    $interval = $current_time - $prev_time;
                    if ($interval > 0 && $interval < 3600) {
                        $rate_per_sec = $prev_total > 0 ? ($prev_total / max(1, $interval)) : 0;
                        $estimated_lost = intval($rate_per_sec * ($interval / 2));
                    }
                }
                $total_usage += $prev_total + $current_total + $estimated_lost;
                $reset_count++;
                log_message('Counter reset detected for host ' . $host_id . ': ' . $prev_total . ' -> ' . $current_total . ', estimated lost: ' . $estimated_lost . ' bytes');
            }
        }
        
        $prev_total = $current_total;
        $prev_time = $current_time;
    }
    
    if ($reset_count > 0) {
        log_message('Host ' . $host_id . ' had ' . $reset_count . ' counter resets this period');
    }
    
    return $total_usage;
}

function reset_monthly_traffic() {
    global $kvm;
    
    $today = date('Y-m-d');
    $first_day = date('Y-m-01');
    
    if ($today !== $first_day) {
        return;
    }
    
    $hosts = Database::fetchAll("SELECT id, user_id, package_name, vm_name FROM hosts WHERE status IN ('running', 'suspended_traffic') AND package_id IN (SELECT id FROM packages WHERE type = 3)");
    
    foreach ($hosts as $host) {
        Database::update('hosts', [
            'traffic_used' => 0,
            'traffic_reset_date' => $today
        ], 'id = ?', [$host['id']]);
        
        if ($host['status'] === 'suspended_traffic') {
            try {
                $kvm->startVM($host['vm_name']);
                
                Database::update('hosts', [
                    'status' => 'running'
                ], 'id = ?', [$host['id']]);
                
                send_notification($host['user_id'], 'host', '流量已重置，服务器已恢复', 
                    '您的云主机 ' . $host['package_name'] . ' 本月流量已重置，服务器已自动恢复运行。',
                    'host', $host['id']);
                
                log_message('Host ' . $host['id'] . ' resumed after traffic reset');
            } catch (Exception $e) {
                log_message('ERROR resuming host ' . $host['id'] . ': ' . $e->getMessage());
            }
        } else {
            send_notification($host['user_id'], 'host', '流量已重置', 
                '您的云主机 ' . $host['package_name'] . ' 本月流量已重置。',
                'host', $host['id']);
            
            log_message('Traffic reset for host ' . $host['id']);
        }
    }
    
    log_message('Monthly traffic reset completed');
}

function check_and_suspend() {
    global $kvm;
    
    $hosts = Database::fetchAll("SELECT h.*, p.kvm_traffic_gb FROM hosts h LEFT JOIN packages p ON h.package_id = p.id WHERE h.status = 'running' AND p.type = 3");
    
    foreach ($hosts as $host) {
        $traffic_limit_gb = intval($host['kvm_traffic_gb'] ?? 0);
        if ($traffic_limit_gb <= 0) continue;
        
        $traffic_limit_mb = $traffic_limit_gb * 1024;
        
        $total_bytes = calculate_monthly_usage($host['id']);
        $traffic_used_mb = round($total_bytes / 1024 / 1024);
        
        Database::update('hosts', [
            'traffic_used' => $traffic_used_mb,
            'traffic_limit' => $traffic_limit_mb
        ], 'id = ?', [$host['id']]);
        
        log_message('Host ' . $host['id'] . ' traffic used: ' . $traffic_used_mb . 'MB / ' . $traffic_limit_mb . 'MB');
        
        if ($traffic_used_mb >= $traffic_limit_mb) {
            try {
                $kvm->forceStopVM($host['vm_name']);
                
                Database::update('hosts', [
                    'status' => 'suspended_traffic'
                ], 'id = ?', [$host['id']]);
                
                send_notification($host['user_id'], 'host', '流量超限，服务器已暂停', 
                    '您的云主机 ' . ($host['package_name'] ?? $host['vm_name']) . ' 本月流量已用尽，服务器已自动暂停。请联系客服购买流量或升级套餐。',
                    'host', $host['id']);
                
                log_message('Host ' . $host['id'] . ' suspended due to traffic over limit');
            } catch (Exception $e) {
                log_message('ERROR suspending host ' . $host['id'] . ': ' . $e->getMessage());
            }
        } elseif ($traffic_used_mb >= $traffic_limit_mb * 0.8) {
            $remaining = $traffic_limit_mb - $traffic_used_mb;
            
            $last_warn = Database::fetch("SELECT traffic_warned_at FROM hosts WHERE id = ?", [$host['id']]);
            $last_warn_date = $last_warn['traffic_warned_at'] ?? '';
            
            if ($last_warn_date !== date('Y-m-d')) {
                send_notification($host['user_id'], 'host', '流量即将用尽', 
                    '您的云主机 ' . ($host['package_name'] ?? $host['vm_name']) . ' 本月流量已使用 ' . round($traffic_used_mb/1024, 2) . 'GB，剩余 ' . round($remaining/1024, 2) . 'GB。请及时留意。',
                    'host', $host['id']);
                
                Database::update('hosts', ['traffic_warned_at' => date('Y-m-d')], 'id = ?', [$host['id']]);
                log_message('Host ' . $host['id'] . ' traffic warning: 80% used');
            }
        }
    }
}

function cleanup_old_traffic_data() {
    try {
        $two_months_ago = date('Y-m-d', strtotime('-2 months'));
        
        $pdo = Database::connect();
        $stmt = $pdo->prepare("DELETE FROM host_traffic WHERE collected_at < ?");
        $stmt->execute([$two_months_ago]);
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            log_message('Cleaned up ' . $deleted . ' old traffic records');
        }
    } catch (Exception $e) {
        log_message('ERROR cleaning up traffic data: ' . $e->getMessage());
    }
}

log_message('Traffic monitor started');

$kvm = kvm_get_manager();

cleanup_old_traffic_data();

reset_monthly_traffic();

$hosts = Database::fetchAll("SELECT id, vm_name FROM hosts WHERE status = 'running' AND vm_name != ''");

foreach ($hosts as $host) {
    collect_traffic($host);
}

check_and_suspend();

log_message('Traffic monitor completed');

echo 'Traffic monitor completed successfully';