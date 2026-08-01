<?php
require_once __DIR__ . '/../config/helper.php';

ignore_user_abort(true);
set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');

migrate_new_tables();

$lock_file = ROOT_PATH . '/logs/node_monitor.lock';
$lock_fp = @fopen($lock_file, 'w');
if (!$lock_fp) {
    echo "Cannot create lock file\n";
    exit(1);
}
if (!@flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "Another instance running\n";
    exit(0);
}
register_shutdown_function(function() use ($lock_fp, $lock_file) {
    @flock($lock_fp, LOCK_UN);
    @fclose($lock_fp);
    @unlink($lock_file);
});

$settings = Database::fetch("SELECT * FROM settings WHERE setting_key LIKE 'alert_%'");
$config = [];
foreach ($settings as $s) {
    $config[$s['setting_key']] = $s['setting_value'];
}

$cpu_threshold = intval($config['alert_cpu_threshold'] ?? 90);
$memory_threshold = intval($config['alert_memory_threshold'] ?? 90);
$disk_threshold = intval($config['alert_disk_threshold'] ?? 90);
$traffic_threshold_mbps = floatval($config['alert_traffic_threshold'] ?? 1000);

function create_alert($alert_type, $alert_level, $title, $content, $metric = '', $threshold = '', $node_id = null, $host_id = null) {
    $existing = Database::fetch("SELECT id, status FROM node_alerts 
        WHERE alert_type = ? AND " . ($node_id ? "node_id = " . intval($node_id) : "node_id IS NULL") . " 
        AND " . ($host_id ? "host_id = " . intval($host_id) : "host_id IS NULL") . "
        AND status = 'active'
        ORDER BY id DESC LIMIT 1", [$alert_type]);
    
    if ($existing && $existing['status'] === 'active') {
        return $existing['id'];
    }
    
    return Database::insert('node_alerts', [
        'node_id' => $node_id,
        'host_id' => $host_id,
        'alert_type' => $alert_type,
        'alert_level' => $alert_level,
        'title' => $title,
        'content' => $content,
        'metric_value' => $metric,
        'threshold' => $threshold,
        'status' => 'active',
    ]);
}

function resolve_auto_recover_alerts($alert_type, $node_id = null, $host_id = null) {
    $where = "alert_type = ? AND status = 'active'";
    $params = [$alert_type];
    if ($node_id) {
        $where .= " AND node_id = ?";
        $params[] = $node_id;
    }
    if ($host_id) {
        $where .= " AND host_id = ?";
        $params[] = $host_id;
    }
    Database::query("UPDATE node_alerts SET status = 'resolved', resolved_at = NOW() WHERE $where", $params);
}

try {
    $sys_load = sys_getloadavg();
    if ($sys_load) {
        $cpu_cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cpu_cores = count($matches[0]) ?: 1;
        }
        $load_1m = floatval($sys_load[0] ?? 0);
        $load_percent = round(($load_1m / $cpu_cores) * 100, 1);
        
        if ($load_percent >= $cpu_threshold) {
            create_alert('cpu_high', $load_percent >= 95 ? 'critical' : 'warning', 
                'CPU负载过高', 
                "系统1分钟负载: {$load_percent}% ({$load_1m}/{$cpu_cores}核)",
                "{$load_percent}%", "{$cpu_threshold}%", null, null);
        } else {
            resolve_auto_recover_alerts('cpu_high');
        }
    }
} catch (Exception $e) {}

try {
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);
        if ($total && $avail) {
            $total_mb = intval($total[1]) / 1024;
            $avail_mb = intval($avail[1]) / 1024;
            $used_mb = $total_mb - $avail_mb;
            $mem_percent = round(($used_mb / $total_mb) * 100, 1);
            
            if ($mem_percent >= $memory_threshold) {
                create_alert('memory_high', $mem_percent >= 95 ? 'critical' : 'warning',
                    '内存使用率过高',
                    "已用: {$used_mb}MB / 总计: {$total_mb}MB ({$mem_percent}%)",
                    "{$mem_percent}%", "{$memory_threshold}%", null, null);
            } else {
                resolve_auto_recover_alerts('memory_high');
            }
        }
    }
} catch (Exception $e) {}

try {
    $disk_total = @disk_total_space('/');
    $disk_free = @disk_free_space('/');
    if ($disk_total && $disk_free > 0) {
        $disk_used = $disk_total - $disk_free;
        $disk_percent = round(($disk_used / $disk_total) * 100, 1);
        
        if ($disk_percent >= $disk_threshold) {
            $used_gb = round($disk_used / 1024 / 1024 / 1024, 1);
            $total_gb = round($disk_total / 1024 / 1024 / 1024, 1);
            create_alert('disk_high', $disk_percent >= 95 ? 'critical' : 'warning',
                '磁盘使用率过高',
                "已用: {$used_gb}GB / 总计: {$total_gb}GB ({$disk_percent}%)",
                "{$disk_percent}%", "{$disk_threshold}%", null, null);
        } else {
            resolve_auto_recover_alerts('disk_high');
        }
    }
} catch (Exception $e) {}

try {
    $kvm_hosts = Database::fetchAll("SELECT id, vm_name, status FROM hosts 
        WHERE vm_type = 'kvm' AND status IN ('running', 'creating')");
    
    require_once ROOT_PATH . '/config/KvmManager.php';
    $kvm = new KvmManager();
    
    $current_traffic = [];
    
    foreach ($kvm_hosts as $h) {
        try {
            $vm_info = $kvm->getVmInfo($h['vm_name']);
            $libvirt_state = $vm_info['state'] ?? 'unknown';
            
            if ($h['status'] === 'running' && !in_array($libvirt_state, ['running', 'paused'])) {
                create_alert('vm_offline', 'warning',
                    "虚拟机离线: {$h['vm_name']}",
                    "数据库状态: running, libvirt状态: {$libvirt_state}",
                    $libvirt_state, 'running', null, $h['id']);
            } elseif ($h['status'] === 'running' && in_array($libvirt_state, ['running', 'paused'])) {
                resolve_auto_recover_alerts('vm_offline', null, $h['id']);
            }
            
            $net = $kvm->getNetworkStats($h['vm_name']);
            if ($net && isset($net['total_bytes'])) {
                $current_traffic[$h['id']] = [
                    'vm_name' => $h['vm_name'],
                    'total_bytes' => intval($net['total_bytes']),
                    'time' => time(),
                ];
            }
        } catch (Exception $e) {}
    }
    
    $cache_file = ROOT_PATH . '/data/traffic_checkpoint.json';
    $prev_traffic = @json_decode(@file_get_contents($cache_file), true) ?: [];
    
    if (!empty($prev_traffic)) {
        foreach ($current_traffic as $host_id => $curr) {
            if (isset($prev_traffic[$host_id])) {
                $prev = $prev_traffic[$host_id];
                $time_diff = $curr['time'] - $prev['time'];
                if ($time_diff > 0) {
                    $bytes_diff = $curr['total_bytes'] - $prev['total_bytes'];
                    if ($bytes_diff < 0) $bytes_diff = $curr['total_bytes'];
                    $bps = $bytes_diff / $time_diff;
                    $mbps = round($bps * 8 / 1024 / 1024, 2);
                    
                    if ($mbps >= $traffic_threshold_mbps) {
                        create_alert('traffic_high', $mbps >= $traffic_threshold_mbps * 1.5 ? 'critical' : 'warning',
                            "流量异常: {$curr['vm_name']}",
                            "当前带宽: {$mbps}Mbps",
                            "{$mbps}Mbps", "{$traffic_threshold_mbps}Mbps", null, $host_id);
                    }
                }
            }
        }
    }
    
    @file_put_contents($cache_file, json_encode($current_traffic));
    
} catch (Exception $e) {}

// 社区版：单节点模式，无需多节点监控

echo "Node monitor completed at " . date('Y-m-d H:i:s') . "\n";
