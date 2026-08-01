<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

$is_admin = ($user['role'] ?? 'user') === 'admin';

$id_param = get('id', '');
$host = null;

if (is_numeric($id_param)) {
    $id = intval($id_param);
    if ($is_admin) {
        $host = Database::fetch("SELECT h.*, u.username as owner_name FROM hosts h LEFT JOIN users u ON h.user_id = u.id WHERE h.id = ?", [$id]);
    } else {
        $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$id, $uid]);
    }
} else {
    $uuid = $id_param;
    if ($is_admin) {
        $host = Database::fetch("SELECT h.*, u.username as owner_name FROM hosts h LEFT JOIN users u ON h.user_id = u.id WHERE h.uuid = ?", [$uuid]);
    } else {
        $host = Database::fetch("SELECT * FROM hosts WHERE uuid = ? AND user_id = ?", [$uuid, $uid]);
    }
}

$id = $host['id'] ?? 0;
$host_uuid = $host['uuid'] ?? $id;

if (!$host) {
    flash('error', '主机不存在');
    if ($is_admin) {
        header('Location: /admin/hosts.php');
    } else {
        header('Location: /user/hosts.php');
    }
    exit;
}

$is_kvm = intval($host['vm_type'] === 'kvm' || (isset($host['vm_name']) && !empty($host['vm_name']) && $host['image_id'] > 0));
if (!$is_kvm) {
    // 普通虚拟主机，重定向回普通详情页
    header('Location: /user/host_detail.php?id=' . $host_uuid);
    exit;
}

// 获取VNC连接信息（支持多节点）
$vnc_info = kvm_get_vnc_info($host);

$vm_power = $host['vm_power_status'] ?? 'creating';
$vm_images = kvm_get_images(true);

// 获取外网SSH连接信息（从NAT规则表）
$ssh_nat_rule = Database::fetch(
    "SELECT * FROM nat_rules WHERE host_id = ? AND local_port = 22 AND status = 'active' ORDER BY id DESC LIMIT 1",
    [$id]
);

// 处理操作
    if (is_post()) {
        $action = post('action', '');
        // 核验码验证（排除状态刷新等只读操作）
        if (in_array($action, ['start', 'stop', 'restart', 'forcestop', 'reinstall', 'change_password'])) {
            license_require_for_service('KVM主机管理');
        }
        if (in_array($action, ['start', 'stop', 'restart', 'forcestop', 'refresh'])) {
            if ($action === 'refresh') {
                $r = kvm_refresh_status($host);
                if ($r && !empty($r['success'])) {
                    flash('success', '状态已刷新: ' . ($r['state'] ?? '未知') . '，IP: ' . ($r['ip'] ?: '等待分配'));
                } else {
                    flash('error', '刷新失败: ' . ($r['message'] ?? '未知错误'));
                }
            } else {
                $r = kvm_vm_action($host, $action);
                if ($r && !empty($r['success'])) {
                    flash('success', '操作成功: ' . $action);
                } else {
                    flash('error', '操作失败: ' . ($r['message'] ?? '未知错误'));
                }
            }
            header('Location: /user/host_kvm.php?id=' . $host_uuid);
            exit;
        }

        // 重新创建虚拟机（当虚拟机在 libvirt 中不存在时）
        if ($action === 'recreate') {
            $confirm = post('confirm', '');
            if ($confirm !== 'yes') {
                flash('error', '请勾选确认重新创建选项');
            } else {
                $r = kvm_recreate_vm($host);
                if ($r && !empty($r['success'])) {
                    flash('success', '虚拟机已重新创建，IP: ' . ($r['ip'] ?? '等待分配'));
                } else {
                    flash('error', '重新创建失败: ' . ($r['message'] ?? '未知错误'));
                }
            }
            header('Location: /user/host_kvm.php?id=' . $host_uuid);
            exit;
        }

    // 重装系统 - AJAX 异步模式
    if ($action === 'reinstall' && is_ajax()) {
        @header('Content-Type: application/json; charset=utf-8');
        @ini_set('display_errors', 0);
        while (ob_get_level() > 0) ob_end_clean();

        $image_id = intval(post('image_id', 0));
        $confirm = post('confirm', '');

        if ($confirm !== 'yes') {
            echo json_encode(['success' => false, 'message' => '请勾选确认重装选项'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($image_id <= 0) {
            echo json_encode(['success' => false, 'message' => '请选择系统镜像'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 更新主机状态为 reinstalling
        Database::update('hosts', [
            'vm_power_status' => 'reinstalling',
        ], 'id = ?', [$id]);

        // 创建重装任务
        $task_id = Database::insert('vm_tasks', [
            'host_id' => $id,
            'user_id' => $uid,
            'task_type' => 'reinstall',
            'task_data' => json_encode(['image_id' => $image_id]),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 启动后台进程
        $php_bin = '';
        $php_paths = [
            '/www/server/php/83/bin/php',
            '/www/server/php/74/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        foreach ($php_paths as $p) {
            if (file_exists($p) && is_executable($p) && strpos($p, 'php-fpm') === false) {
                $php_bin = $p;
                break;
            }
        }

        if ($php_bin) {
            $worker_script = __DIR__ . '/reinstall_worker.php';
            $cmd = sprintf(
                '%s %s %d %d > /dev/null 2>&1 &',
                $php_bin,
                escapeshellarg($worker_script),
                $task_id,
                $id
            );
            @exec($cmd);
        }

        echo json_encode([
            'success' => true,
            'message' => '重装任务已启动',
            'task_id' => $task_id,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 重装系统 - 非 AJAX 表单提交（兼容旧逻辑）
    if ($action === 'reinstall') {
        $image_id = intval(post('image_id', 0));
        $confirm = post('confirm', '');
        if ($confirm !== 'yes') {
            flash('error', '请勾选确认重装选项');
        } elseif ($image_id <= 0) {
            flash('error', '请选择系统镜像');
        } else {
            flash('error', '请使用新版浏览器访问');
        }
        header('Location: /user/host_kvm.php?id=' . $host_uuid);
        exit;
    }
    
    // 修改 root 密码
    if ($action === 'change_password') {
        $new_password = trim(post('new_password', ''));
        $confirm_password = trim(post('confirm_password', ''));
        $old_password = trim(post('old_password', ''));
        
        if (empty($new_password)) {
            flash('error', '请输入新密码');
        } elseif (strlen($new_password) < 6) {
            flash('error', '密码长度不能少于6位');
        } elseif ($new_password !== $confirm_password) {
            flash('error', '两次输入的密码不一致');
        } else {
            $r = kvm_change_password($host, $new_password);
            if ($r && !empty($r['success'])) {
                // 根据修改方式显示不同的成功消息
                if (!empty($r['direct_change'])) {
                    flash('success', '密码修改成功！已直接写入虚拟机，立即生效。新密码：' . $new_password);
                } elseif (!empty($r['vm_state']) && $r['vm_state'] !== 'running') {
                    flash('success', '密码已保存到数据库。虚拟机开机后密码将自动生效。新密码：' . $new_password);
                } else {
                    flash('success', $r['message'] . ' 新密码：' . $new_password);
                }
            } else {
                flash('error', '密码修改失败：' . ($r['message'] ?? '未知错误'));
            }
        }
        header('Location: /user/host_kvm.php?id=' . $host_uuid);
        exit;
    }

    // 添加NAT规则
    if ($action === 'add_nat') {
        $rule_name = trim(post('rule_name', ''));
        $protocol = post('protocol', 'tcp');
        $local_ip = trim(post('local_ip', $host['ip_address'] ?? ''));
        $local_port = intval(post('local_port', 0));
        $remote_port = intval(post('remote_port', 0));

        $result = nat_add_rule($id, $uid, [
            'rule_name' => $rule_name,
            'protocol' => $protocol,
            'local_ip' => $local_ip,
            'local_port' => $local_port,
            'remote_port' => $remote_port,
        ]);

        if ($result['success']) {
            flash('success', 'NAT规则已添加，外网访问地址: ' . $result['remote_addr']);
        } else {
            flash('error', '添加失败: ' . ($result['message'] ?? '未知错误'));
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#nat');
        exit;
    }

    // 删除NAT规则
    if ($action === 'delete_nat') {
        $nat_id = intval(post('nat_id', 0));
        $result = nat_delete_rule($nat_id, $uid);
        if ($result['success']) {
            flash('success', 'NAT规则已删除');
        } else {
            flash('error', '删除失败: ' . ($result['message'] ?? '未知错误'));
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#nat');
        exit;
    }

    // 刷新NAT规则状态
    if ($action === 'refresh_nat') {
        $nat_id = intval(post('nat_id', 0));
        $result = nat_refresh_status($nat_id, $uid);
        if ($result['success']) {
            flash('success', '状态已刷新: ' . ($result['frp_status'] ?? 'unknown'));
        } else {
            flash('error', '刷新失败: ' . ($result['message'] ?? '未知错误'));
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#nat');
        exit;
    }

    // 创建快照
    if ($action === 'create_snapshot') {
        $snapshot_name = trim(post('snapshot_name', ''));
        $snapshot_desc = trim(post('snapshot_desc', ''));
        $result = kvm_create_snapshot($host, $snapshot_name, $snapshot_desc);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#snapshots');
        exit;
    }

    // 恢复快照
    if ($action === 'restore_snapshot') {
        $snapshot_id = intval(post('snapshot_id', 0));
        $result = kvm_restore_snapshot($snapshot_id, $uid);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#snapshots');
        exit;
    }

    // 删除快照
    if ($action === 'delete_snapshot') {
        $snapshot_id = intval(post('snapshot_id', 0));
        $result = kvm_delete_snapshot($snapshot_id, $uid);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#snapshots');
        exit;
    }

    // 更新网络配置
    if ($action === 'update_network') {
        $result = kvm_update_network_config($host, $_POST);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#network');
        exit;
    }

    // 添加防火墙规则
    if ($action === 'add_firewall') {
        $result = firewall_add_rule($id, $uid, $_POST);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#firewall');
        exit;
    }

    // 删除防火墙规则
    if ($action === 'delete_firewall') {
        $rule_id = intval(post('rule_id', 0));
        $result = firewall_delete_rule($rule_id, $uid);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#firewall');
        exit;
    }

    // 切换防火墙规则状态
    if ($action === 'toggle_firewall') {
        $rule_id = intval(post('rule_id', 0));
        $new_status = post('new_status', 'active');
        $result = firewall_update_rule($rule_id, $uid, ['status' => $new_status]);
        if ($result['success']) {
            flash('success', '规则状态已更新');
        } else {
            flash('error', $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#firewall');
        exit;
    }

    // 同步所有防火墙规则
    if ($action === 'sync_firewall') {
        $result = firewall_sync_all_rules($id, $uid);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', '同步失败: ' . $result['message']);
        }
        header('Location: /user/host_kvm.php?id=' . $id . '#firewall');
        exit;
    }
}

$nat_rules = nat_get_rules($id);
$frp_cfg = config('frp');
$frp_enabled = !empty($frp_cfg['enabled']);

$snapshots = kvm_get_snapshots($id, $uid);
$snapshot_count = kvm_snapshot_count($id, $uid);
$max_snapshots = kvm_max_snapshots_per_user();

$network_config = kvm_get_network_config($host);
$firewall_rules = firewall_get_rules($id, $uid);

// 进入页面自动刷新一次状态（重装中不刷新，避免覆盖状态）
if (!empty($host['vm_name']) && !in_array($vm_power, ['creating', 'installing', 'reinstalling'])) {
    $refresh_result = @kvm_refresh_status($host);
    if ($refresh_result && !empty($refresh_result['success'])) {
        $vm_power = $refresh_result['state'] ?? $vm_power;
        $host['vm_power_status'] = $vm_power;
        if (!empty($refresh_result['ip'])) {
            $host['ip_address'] = $refresh_result['ip'];
        }
        $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$id]);
    }
}

$try_ip = '';
$status_label = kvm_power_label($vm_power);
$status_color = kvm_power_color($vm_power);

// 区域信息
$region_name = config('kvm.region_name') ?: '上海';
$region_code = config('kvm.region_code') ?: 'AP-Shanghai';
$region_display = $region_name . ' (' . $region_code . ')';

// 获取真实监控数据
$cpu_usage = 0;
$mem_usage = 0;
$mem_used_mb = 0;
$mem_total_mb = intval($host['memory_mb'] ?? 2048);
$disk_usage = 0;
$disk_used_gb = 0;
$disk_total_gb = intval($host['disk_gb'] ?? 40);
$network_rx_mbps = 0;
$network_tx_mbps = 0;
$disk_read_mbps = 0;
$disk_write_mbps = 0;

if ($vm_power === 'running' && !empty($host['vm_name'])) {
    $kvm = kvm_get_manager();
    if ($kvm) {
        $usage = @$kvm->getVMUsage($host['vm_name']);
        if ($usage && !empty($usage['success'])) {
            $cpu_usage = floatval($usage['cpu_usage'] ?? 0);
            $mem_used_mb = floatval($usage['memory_used'] ?? 0);
            if (!empty($usage['memory_percent'])) {
                $mem_usage = floatval($usage['memory_percent']);
            } elseif ($mem_total_mb > 0) {
                $mem_usage = ($mem_used_mb / $mem_total_mb) * 100;
            }
            $disk_used_gb = floatval($usage['disk_used'] ?? 0);
            if ($disk_total_gb > 0) {
                $disk_usage = ($disk_used_gb / $disk_total_gb) * 100;
            }
            $rx_kb = floatval($usage['rx_speed'] ?? 0);
            $tx_kb = floatval($usage['tx_speed'] ?? 0);
            $network_rx_mbps = round($rx_kb * 8 / 1024, 2);
            $network_tx_mbps = round($tx_kb * 8 / 1024, 2);
            $disk_read_mbps = round(floatval($usage['disk_read_mb'] ?? 0), 2);
            $disk_write_mbps = round(floatval($usage['disk_write_mb'] ?? 0), 2);
        }
    }
}

// 操作日志/事件记录（从主机表的操作记录或数据库中获取）
$operation_logs = [];
try {
    @migrate_new_tables();
    $log_stmt = Database::fetchAll(
        "SELECT * FROM host_operation_logs WHERE host_id = ? ORDER BY created_at DESC LIMIT 10",
        [$id]
    );
    if ($log_stmt) {
        $operation_logs = $log_stmt;
    }
} catch (Exception $e) {
    $operation_logs = [];
}
// 如果没有日志表，用快照记录和状态变更模拟
if (empty($operation_logs)) {
    $fake_logs = [];
    if ($snapshots && count($snapshots) > 0) {
        foreach ($snapshots as $snap) {
            $fake_logs[] = [
                'type' => 'success',
                'type_label' => '成功',
                'content' => date('Y-m-d H:i', strtotime($snap['created_at'])) . ' · 快照创建：' . ($snap['snapshot_name'] ?? 'auto'),
                'time' => $snap['created_at']
            ];
        }
    }
    if (!empty($host['vm_created_at'])) {
        $fake_logs[] = [
            'type' => 'info',
            'type_label' => '信息',
            'content' => date('Y-m-d H:i', strtotime($host['vm_created_at'])) . ' · 虚拟机创建完成',
            'time' => $host['vm_created_at']
        ];
    }
    if (!empty($host['created_at'])) {
        $fake_logs[] = [
            'type' => 'info',
            'type_label' => '信息',
            'content' => date('Y-m-d H:i', strtotime($host['created_at'])) . ' · 订单支付成功，开始创建',
            'time' => $host['created_at']
        ];
    }
    usort($fake_logs, function($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });
    $operation_logs = array_slice($fake_logs, 0, 5);
}

// 如果是创建中、安装中或重装中状态，显示专门的加载页面
if (in_array($vm_power, ['creating', 'installing', 'reinstalling'])) {
    // 重装中不刷新状态，只检查任务是否完成
    if ($vm_power === 'reinstalling') {
        $task = Database::fetch("SELECT * FROM vm_tasks WHERE host_id = ? AND task_type = 'reinstall' ORDER BY id DESC LIMIT 1", [$id]);
        if ($task && $task['status'] === 'completed') {
            // 重装完成，更新状态为运行中
            Database::update('hosts', ['vm_power_status' => 'running', 'vm_last_sync' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            $vm_power = 'running';
        } elseif ($task && $task['status'] === 'error') {
            // 重装失败，更新状态为停止
            Database::update('hosts', ['vm_power_status' => 'stopped'], 'id = ?', [$id]);
            $vm_power = 'stopped';
        }
    } else {
        // 创建中/安装中：尝试从KVM宿主机刷新一次状态
        $refresh_result = @kvm_refresh_status($host);
        if ($refresh_result && !empty($refresh_result['success'])) {
            $new_state = $refresh_result['state'] ?? '';
            if (!in_array($new_state, ['creating', 'installing', 'unknown'])) {
                // 状态已更新，刷新页面显示正常控制台
                header('Location: /user/host_kvm.php?id=' . $host_uuid);
                exit;
            }
        }
    }

    // 如果状态仍然是创建/安装/重装中，显示加载页面
    if (in_array($vm_power, ['creating', 'installing', 'reinstalling'])) {
    $is_creating = ($vm_power === 'creating');
    $is_reinstalling = ($vm_power === 'reinstalling');
    $page_title = $is_reinstalling ? '正在重装系统' : ($is_creating ? '正在创建主机' : '正在安装系统');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        .creating-card {
            background: #fff;
            border-radius: 16px;
            padding: 48px;
            max-width: 520px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .loading-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1677ff 0%, #4096ff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .title {
            font-size: 24px;
            font-weight: 600;
            color: #1d2129;
            margin-bottom: 12px;
        }
        .subtitle {
            font-size: 14px;
            color: #86909c;
            margin-bottom: 32px;
            line-height: 1.6;
        }
        .progress-steps {
            background: #f5f7fa;
            border-radius: 12px;
            padding: 20px;
            text-align: left;
            margin-bottom: 32px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
        }
        .step-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e5e6eb;
            flex-shrink: 0;
        }
        .step-dot.done {
            background: #52c41a;
        }
        .step-dot.active {
            background: #1677ff;
            animation: pulse 1.5s infinite;
        }
        .step-text {
            font-size: 13px;
            color: #86909c;
        }
        .step-text.done {
            color: #4e5969;
        }
        .step-text.active {
            color: #1677ff;
            font-weight: 500;
        }
        .btn {
            display: inline-block;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .btn-outline {
            background: transparent;
            color: #1677ff;
            border: 1px solid #1677ff;
        }
        .btn-outline:hover {
            background: #e6f4ff;
        }
        .countdown {
            margin-top: 24px;
            font-size: 12px;
            color: #86909c;
        }
        .host-name {
            background: #f0f5ff;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-family: monospace;
            color: #1677ff;
            font-size: 14px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="creating-card">
        <div class="loading-icon">
            <div class="spinner"></div>
        </div>
        <h1 class="title"><?php echo $page_title; ?>...</h1>
        <p class="subtitle">
            <?php if ($is_reinstalling): ?>
            系统正在重装中，所有数据已被清空，请耐心等待。
            <br>重装完成后将自动恢复运行。
            <?php else: ?>
            您的云服务器正在<?php echo $is_creating ? '创建' : '安装系统'; ?>中，请耐心等待。
            <br>页面将自动刷新检查状态。
            <?php endif; ?>
        </p>
        
        <div class="host-name">
            主机名称：<?php echo e($host['vm_name'] ?: $host['mnbt_username']); ?>
        </div>
        
        <div class="progress-steps">
            <div class="step-item">
                <div class="step-dot done"></div>
                <span class="step-text done">订单验证完成</span>
            </div>
            <div class="step-item">
                <div class="step-dot done"></div>
                <span class="step-text done">支付确认完成</span>
            </div>
            <div class="step-item">
                <div class="step-dot active"></div>
                <span class="step-text active">
                    <?php echo $is_creating ? '正在创建云服务器...' : '正在安装操作系统...'; ?>
                </span>
            </div>
            <div class="step-item">
                <div class="step-dot"></div>
                <span class="step-text">
                    <?php echo $is_creating ? '系统初始化配置' : '完成初始化'; ?>
                </span>
            </div>
            <div class="step-item">
                <div class="step-dot"></div>
                <span class="step-text">准备就绪</span>
            </div>
        </div>
        
        <div>
            <a href="/user/hosts.php" class="btn btn-outline">返回主机列表</a>
        </div>
        
        <div class="countdown">
            预计需要 2-5 分钟，<span id="countdown">10</span> 秒后自动刷新...
        </div>
    </div>
    
    <script>
    var seconds = 10;
    var countdownEl = document.getElementById('countdown');
    
    function tick() {
        seconds--;
        if (seconds <= 0) {
            location.reload();
        } else {
            countdownEl.textContent = seconds;
            setTimeout(tick, 1000);
        }
    }
    
    setTimeout(tick, 1000);
    </script>
</body>
</html>
<?php
    exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KVM 主机控制台 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body style="margin: 0; padding: 0;">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="host-layout">
        <?php
        // 管理员显示当前查看用户的所有KVM主机，普通用户只显示自己的
        if ($is_admin) {
            $all_hosts = Database::fetchAll("SELECT * FROM hosts WHERE user_id = ? AND vm_type = 'kvm' ORDER BY created_at DESC", [$host['user_id']]);
        } else {
            $all_hosts = Database::fetchAll("SELECT * FROM hosts WHERE user_id = ? AND vm_type = 'kvm' ORDER BY created_at DESC", [$uid]);
        }
        ?>
        <!-- 左侧主机列表 -->
        <div class="host-list-panel">
            <div class="host-list-header">
                <div class="host-list-title">
                    <h2>主机列表</h2>
                    <span class="count">显示 1-<?php echo min(4, count($all_hosts)); ?> / <?php echo count($all_hosts); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                    <div class="host-list-filters">
                        <button class="filter-btn active">全部状态</button>
                        <button class="filter-btn"><?php echo e($region_name); ?></button>
                        <button class="filter-btn">到期时间 ↓</button>
                    </div>
                    <div class="host-list-view-toggle">
                        <button class="view-toggle-btn active">☰</button>
                        <button class="view-toggle-btn">▦</button>
                        <button class="view-toggle-btn">▤</button>
                    </div>
                </div>
            </div>
            <div class="host-list-content">
                <?php foreach ($all_hosts as $h): 
                    $h_power = $h['vm_power_status'] ?? 'stopped';
                    $h_status = $h_power;
                    $h_status_class = $h_power === 'running' ? 'running' : ($h_power === 'creating' ? 'creating' : 'stopped');
                    $h_status_label = kvm_power_label($h_power);
                    $is_active = intval($h['id']) === $id;
                ?>
                <div class="host-list-item <?php echo $is_active ? 'active' : ''; ?>" onclick="location.href='/user/host_kvm.php?id=<?php echo $h['uuid'] ?? $h['id']; ?>'">
                    <div class="host-item-main">
                        <div class="host-item-name">
                            <input type="checkbox" onclick="event.stopPropagation()" style="margin-right: 6px;">
                            <?php echo e($h['vm_name'] ?? $h['mnbt_username']); ?>
                            <span class="host-item-status <?php echo $h_status_class; ?>"><?php echo e($h_status_label); ?></span>
                        </div>
                        <div class="host-item-actions">
                            <button class="host-item-action" title="详情">ℹ</button>
                            <button class="host-item-action" title="控制台">🖥</button>
                            <button class="host-item-action" title="重启">⟳</button>
                            <button class="host-item-action" title="关机">⏻</button>
                            <button class="host-item-action" title="更多">⋯</button>
                        </div>
                    </div>
                    <div class="host-item-ip">
                        <span style="color: var(--text-secondary);">IP：</span>
                        <span style="color: var(--primary);"><?php echo e($h['ip_address'] ?: '等待分配'); ?></span>
                    </div>
                    <div class="host-item-spec">
                        <span><?php echo intval($h['vcpu'] ?? 2); ?> vCPU / <?php echo intval($h['memory_mb'] ?? 2048); ?>MB / <?php echo intval($h['disk_gb'] ?? 40); ?>GB / <?php echo intval($h['bandwidth_mbps'] ?? 100); ?>Mbps</span>
                        <span>到期：<?php echo substr($h['expire_at'] ?? '-', 0, 10); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($all_hosts)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary); font-size: 13px;">
                    暂无KVM主机
                </div>
                <?php endif; ?>
            </div>
            <div class="host-list-pagination">
                <span>每页 10 条</span>
                <div class="pagination-controls">
                    <button class="pagination-btn">← 上一页</button>
                    <input type="text" class="pagination-input" value="1">
                    <button class="pagination-btn">下一页 →</button>
                </div>
            </div>
        </div>

        <!-- 右侧详情面板 -->
        <div class="host-detail-panel">
            <!-- 详情头部 -->
            <div style="background: #fff; border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 20px 24px;">
                <div class="detail-header">
                    <div class="detail-title-area">
                        <div style="flex: 1;">
                            <?php if ($is_admin && isset($host['owner_name'])): ?>
                            <div style="background: #fff7e6; border: 1px solid #ffd59b; border-radius: 6px; padding: 8px 12px; margin-bottom: 10px; font-size: 12px; color: #d48806;">
                                🔐 管理员访问模式 · 主机归属用户: <strong><?php echo e($host['owner_name']); ?></strong> · <a href="/admin/hosts.php?type=kvm" style="color: #1677ff;">返回主机管理</a>
                            </div>
                            <?php endif; ?>
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                                <h2 style="font-size: 20px; margin: 0; color: var(--text-primary);">
                                    <?php echo e($host['vm_name'] ?: $host['mnbt_username']); ?>
                                </h2>
                                <span class="host-item-status <?php echo $vm_power === 'running' ? 'running' : ($vm_power === 'creating' ? 'creating' : 'stopped'); ?>" style="font-size: 12px; padding: 3px 10px;">
                                    <?php echo e($status_label); ?>
                                </span>
                            </div>
                            <div style="color: var(--text-secondary); font-size: 12px;">
                                📍 <?php echo e($region_display); ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                IP：<span style="color: var(--primary); font-family: monospace;"><?php echo e($host['ip_address'] ?: '等待分配'); ?></span>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                <?php echo e($host['package_name']); ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                创建于 <?php echo e($host['vm_created_at'] ?? $host['created_at']); ?>
                                &nbsp;&nbsp;|&nbsp;&nbsp;
                                到期：<?php echo e($host['expire_at'] ?? '-'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="detail-actions">
                        <button class="btn btn-primary" onclick="openVNC()" <?php echo $vm_power !== 'running' ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>🖥 控制台</button>
                        <form method="POST" style="display:inline-block;">
                            <input type="hidden" name="action" value="stop">
                            <button type="submit" class="btn btn-secondary" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?>>⏹ 关机</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 监控卡片行 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>CPU使用率</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">当前：<span style="color: var(--primary); font-weight: 600;" data-monitor="cpu"><?php echo round($cpu_usage, 1); ?>%</span></span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-bottom: 6px;">
                            <span>0%</span>
                            <span style="font-size: 24px; font-weight: 700; color: var(--primary); font-family: monospace;" data-monitor="cpu"><?php echo round($cpu_usage, 1); ?>%</span>
                            <span>100%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--bg-light); border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo min(100, round($cpu_usage, 1)); ?>%; height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.5s ease;" data-monitor="cpu-bar"></div>
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 6px;">
                            <?php echo intval($host['vcpu'] ?? 2); ?> vCPU
                        </div>
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>内存使用率</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">当前：<span style="color: var(--primary); font-weight: 600;" data-monitor="mem"><?php echo round($mem_usage, 1); ?>%</span></span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-bottom: 6px;">
                            <span>0%</span>
                            <span style="font-size: 24px; font-weight: 700; color: var(--primary); font-family: monospace;" data-monitor="mem"><?php echo round($mem_usage, 1); ?>%</span>
                            <span>100%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--bg-light); border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo min(100, round($mem_usage, 1)); ?>%; height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.5s ease;" data-monitor="mem-bar"></div>
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 6px;">
                            <span data-monitor="mem-used"><?php echo $mem_used_mb > 0 ? round($mem_used_mb, 0) : '0'; ?></span> / <?php echo $mem_total_mb; ?> MB
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>网络流量</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">入：<span style="font-weight: 500; color: var(--primary);" data-monitor="rx"><?php echo $network_rx_mbps; ?></span> Mbps</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 2px;">↓ 下行</div>
                                <div style="font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace;" data-monitor="rx"><?php echo $network_rx_mbps; ?></div>
                                <span style="font-size: 11px; font-weight: 400; color: var(--text-secondary);"> Mbps</span>
                            </div>
                            <div style="width: 1px; background: var(--border);"></div>
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 2px;">↑ 上行</div>
                                <div style="font-size: 18px; font-weight: 700; color: var(--success); font-family: monospace;" data-monitor="tx"><?php echo $network_tx_mbps; ?></div>
                                <span style="font-size: 11px; font-weight: 400; color: var(--text-secondary);"> Mbps</span>
                            </div>
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); text-align: center;">
                            峰值带宽：<?php echo intval($host['bandwidth_mbps'] ?? 100); ?> Mbps
                        </div>
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>月度流量</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">重置日期：<?php echo $host['traffic_reset_date'] ?? date('Y-m-01'); ?></span>
                    </div>
                    <div style="margin-top: 8px;">
                        <?php
                        $traffic_used_mb = intval($host['traffic_used'] ?? 0);
                        $traffic_limit_mb = intval($host['traffic_limit'] ?? 0);
                        $traffic_remaining_mb = $traffic_limit_mb - $traffic_used_mb;
                        $traffic_percent = $traffic_limit_mb > 0 ? ($traffic_used_mb / $traffic_limit_mb) * 100 : 0;
                        
                        $traffic_used_gb = $traffic_used_mb > 0 ? round($traffic_used_mb / 1024, 2) : 0;
                        $traffic_limit_gb = $traffic_limit_mb > 0 ? round($traffic_limit_mb / 1024, 0) : 0;
                        $traffic_remaining_gb = $traffic_remaining_mb > 0 ? round($traffic_remaining_mb / 1024, 2) : 0;
                        
                        $bar_color = '#22c55e';
                        if ($traffic_percent >= 90) $bar_color = '#ef4444';
                        elseif ($traffic_percent >= 70) $bar_color = '#f59e0b';
                        ?>
                        <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-bottom: 6px;">
                            <span>已使用：<span style="color: var(--primary); font-weight: 600; font-family: monospace;"><?php echo $traffic_used_gb; ?> GB</span></span>
                            <span>剩余：<span style="color: <?php echo $traffic_percent >= 90 ? '#ef4444' : '#22c55e'; ?>; font-weight: 600; font-family: monospace;"><?php echo $traffic_remaining_gb; ?> GB</span></span>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--bg-light); border-radius: 4px; overflow: hidden;">
                            <div style="width: <?php echo min(100, round($traffic_percent, 1)); ?>%; height: 100%; background: <?php echo $bar_color; ?>; border-radius: 4px; transition: width 0.5s ease;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-secondary); margin-top: 6px;">
                            <span>总流量：<?php echo $traffic_limit_gb; ?> GB/月</span>
                            <span>使用率：<span style="color: <?php echo $bar_color; ?>; font-weight: 600;"><?php echo round($traffic_percent, 1); ?>%</span></span>
                        </div>
                        <?php if ($traffic_percent >= 90): ?>
                        <div style="margin-top: 8px; padding: 8px 12px; background: #fff1f0; border-radius: 6px; font-size: 12px; color: #cf1322;">
                            ⚠️ 流量即将用尽，超过限制将自动暂停服务器
                        </div>
                        <?php elseif ($traffic_percent >= 80): ?>
                        <div style="margin-top: 8px; padding: 8px 12px; background: #fff7e6; border-radius: 6px; font-size: 12px; color: #d48806;">
                            ⚠️ 流量使用已超过80%，请留意剩余流量
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>磁盘 IO</h3>
                        <span style="font-size: 12px; color: var(--text-secondary);">读：<span style="font-weight: 500; color: var(--primary);" data-monitor="disk-read"><?php echo $disk_read_mbps; ?></span> MB/s</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 2px;">📖 读取</div>
                                <div style="font-size: 18px; font-weight: 700; color: var(--primary); font-family: monospace;" data-monitor="disk-read"><?php echo $disk_read_mbps; ?></div>
                                <span style="font-size: 11px; font-weight: 400; color: var(--text-secondary);"> MB/s</span>
                            </div>
                            <div style="width: 1px; background: var(--border);"></div>
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 2px;">✏️ 写入</div>
                                <div style="font-size: 18px; font-weight: 700; color: var(--warning); font-family: monospace;" data-monitor="disk-write"><?php echo $disk_write_mbps; ?></div>
                                <span style="font-size: 11px; font-weight: 400; color: var(--text-secondary);"> MB/s</span>
                            </div>
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); text-align: center;">
                            已用：<?php echo round($disk_used_gb, 1); ?> / <?php echo $disk_total_gb; ?> GB (<?php echo round($disk_usage, 1); ?>%)
                        </div>
                    </div>
                </div>
            </div>

            <!-- 主机配置 + 快照 -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>主机配置</h3>
                        <a href="/user/resize.php?host_id=<?php echo $host['id']; ?>">编辑</a>
                    </div>
                    <ul class="config-list">
                        <li>
                            <span class="label">操作系统</span>
                            <span class="value">
                                <?php
                                $image_id = intval($host['image_id'] ?? 0);
                                $img_name = 'Linux';
                                if ($image_id > 0) {
                                    $img = Database::fetch("SELECT name FROM vm_images WHERE id = ?", [$image_id]);
                                    if ($img) $img_name = $img['name'];
                                }
                                echo e($img_name);
                                ?>
                            </span>
                        </li>
                        <li>
                            <span class="label">规格</span>
                            <span class="value"><?php echo intval($host['vcpu'] ?? 2); ?> vCPU · <?php echo intval($host['memory_mb'] ?? 2048); ?>MB · 内存</span>
                        </li>
                        <li>
                            <span class="label">磁盘</span>
                            <span class="value">系统盘：SSD | <?php echo intval($host['disk_gb'] ?? 40); ?> GB</span>
                        </li>
                        <li>
                            <span class="label">峰值带宽</span>
                            <span class="value"><?php echo intval($host['bandwidth_mbps'] ?? 100); ?> Mbps</span>
                        </li>
                        <li>
                            <span class="label">快照</span>
                            <span class="value">
                                <?php echo $snapshot_count; ?> / <?php echo $max_snapshots; ?> 个
                            </span>
                        </li>
                    </ul>
                    <div style="margin-top: 12px; display: flex; gap: 8px;">
                        <button class="btn btn-sm btn-secondary" style="font-size: 12px;">恢复</button>
                        <button class="btn btn-sm btn-secondary" style="font-size: 12px;">删除</button>
                    </div>
                    <div style="margin-top: 8px;">
                        <?php 
                        $snap_list = array_slice($snapshots, 0, 3);
                        foreach ($snap_list as $snap): 
                        ?>
                        <div class="snapshot-item">
                            <div class="snapshot-item-info">
                                <span class="snapshot-item-name"><?php echo e($snap['snapshot_name'] ?? 'snapshot'); ?></span>
                                <span class="snapshot-item-time"><?php echo e($snap['created_at'] ?? ''); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 控制台访问 & 日志 -->
                <div class="detail-section">
                    <div class="detail-section-title">
                        <h3>控制台访问 & 日志</h3>
                        <a style="cursor: pointer;" onclick="openVNC()">打开VNC</a>
                    </div>
                    <div class="console-tabs">
                        <button class="console-tab active" onclick="openVNC()" style="cursor: pointer;">🖥 VNC</button>
                        <button class="console-tab" onclick="showSSHTab()" style="cursor: pointer;">💻 SSH</button>
                    </div>
                    <div class="terminal-box" style="margin-bottom: 16px; cursor: pointer;" onclick="openVNC()" title="点击打开VNC控制台">
                        <?php if ($vm_power === 'running'): ?>
                        <div class="terminal-line info">[ OK ] 虚拟机运行中</div>
                        <div class="terminal-line info">[ OK ] VNC端口: <?php echo intval($host['vnc_port'] ?: 5900); ?></div>
                        <div class="terminal-line info">[ OK ] IP地址: <?php echo e($host['ip_address'] ?: '等待分配'); ?></div>
                        <div class="terminal-line" style="margin-top: 8px; color: #4ade80;">点击此处打开VNC控制台 →</div>
                        <?php else: ?>
                        <div class="terminal-line" style="color: #f87171;">[警告] 当前虚拟机状态：<?php echo e($status_label); ?></div>
                        <div class="terminal-line info">[提示] 请先开机后再使用VNC/SSH连接</div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 13px; font-weight: 500; color: var(--text-primary);">最近日志 / 事件</span>
                            <a style="font-size: 12px; color: var(--text-secondary); cursor: pointer;">更多</a>
                        </div>
                        <?php if (!empty($operation_logs)): ?>
                            <?php foreach ($operation_logs as $log): 
                                $log_type = $log['type'] ?? 'info';
                                $log_label = $log['type_label'] ?? '信息';
                                $log_content = $log['content'] ?? '';
                                $log_class = '';
                                if ($log_type === 'success') $log_class = 'success';
                                elseif ($log_type === 'warning') $log_class = 'warning';
                                elseif ($log_type === 'error') $log_class = 'error';
                                else $log_class = 'info';
                            ?>
                            <div class="log-item">
                                <div class="log-item-info">
                                    <span class="log-item-type <?php echo $log_class; ?>"><?php echo e($log_label); ?></span>
                                    <span class="log-item-content"><?php echo e($log_content); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="font-size: 12px; color: var(--text-secondary); text-align: center; padding: 16px 0;">
                                暂无操作日志
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 12px; font-weight: 500; color: var(--text-primary);">连接信息</span>
                            <div style="display: flex; gap: 10px;">
                                <a style="font-size: 12px; color: var(--text-secondary); cursor: pointer;" onclick="openVNC()">VNC控制台</a>
                                <a style="font-size: 12px; color: var(--primary); cursor: pointer;" onclick="openWebSSH()" <?php echo $vm_power !== 'running' ? 'style="font-size: 12px; color: #ccc; cursor: not-allowed;"' : ''; ?>>WebSSH</a>
                            </div>
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); padding: 4px 0; line-height: 1.8;">
                            VNC：<?php echo e($vnc_info['host']); ?>:<?php echo intval($vnc_info['vnc_port']); ?><br>
                            SSH：<?php echo e($host['ip_address'] ?: 'IP_ADDRESS'); ?>:<?php echo intval($host['ssh_port'] ?: 22); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 控制操作按钮区域 -->
            <div class="detail-section">
                <div class="detail-section-title">
                    <h3>主机操作</h3>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="btn btn-secondary" <?php echo $vm_power === 'running' ? 'disabled' : ''; ?>>▶ 开机</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="restart">
                        <button type="submit" class="btn btn-secondary">⟳ 重启</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="stop">
                        <button type="submit" class="btn btn-secondary" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?>>⏹ 关机</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="forcestop">
                        <button type="submit" class="btn btn-danger" <?php echo $vm_power !== 'running' && $vm_power !== 'paused' ? 'disabled' : ''; ?> title="强制断电（可能损坏数据）">⚡ 强制关机</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="suspend">
                        <button type="submit" class="btn btn-warning" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?> title="暂停（状态保存在内存）">⏸️ 暂停</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="resume">
                        <button type="submit" class="btn btn-success" <?php echo $vm_power !== 'paused' ? 'disabled' : ''; ?>>▶ 恢复运行</button>
                    </form>
                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('确定休眠虚拟机？状态将保存到磁盘，虚拟机将停止运行');">
                        <input type="hidden" name="action" value="save">
                        <button type="submit" class="btn btn-warning" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?> title="休眠（状态保存到磁盘）">💾 休眠</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="restore">
                        <button type="submit" class="btn btn-success" <?php echo $vm_power !== 'saved' ? 'disabled' : ''; ?> title="从休眠恢复">🔄 唤醒</button>
                    </form>
                    <form method="POST" style="display:inline-block;">
                        <input type="hidden" name="action" value="refresh">
                        <button type="submit" class="btn btn-primary">↻ 刷新状态</button>
                    </form>
                    <button type="button" class="btn btn-secondary" onclick="openVNC()" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?>>🖥 VNC控制台</button>
                    <button type="button" class="btn btn-secondary" onclick="openWebSSH()" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?>>💻 WebSSH</button>
                    <a href="/user/renew.php?id=<?php echo $id; ?>" class="btn btn-secondary">🔄 续费</a>
                    <a href="/user/host_nat.php?id=<?php echo $id; ?>" class="btn btn-secondary">🌐 NAT端口</a>
                    <a href="/user/host_network.php?id=<?php echo $id; ?>" class="btn btn-secondary">🔧 网络配置</a>
                    <a href="/user/host_firewall.php?id=<?php echo $id; ?>" class="btn btn-secondary">🛡️ 防火墙</a>
                    <a href="/user/host_snapshots.php?id=<?php echo $id; ?>" class="btn btn-secondary">📸 快照</a>
                    <a href="/user/resize.php?host_id=<?php echo $host['id']; ?>" class="btn btn-secondary">⬆️ 规格调整</a>
                    <button type="button" class="btn btn-danger" onclick="openReinstallModal()">🔄 重装系统</button>
                </div>
            </div>

            <!-- 重装系统弹窗 -->
            <div id="reinstallModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:12px; width:500px; max-width:90%; max-height:80vh; overflow:hidden;">
                    <div style="padding:20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                        <h3 style="margin:0; font-size:16px;">重装系统</h3>
                        <button type="button" onclick="closeReinstallModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-secondary);">&times;</button>
                    </div>
                    <div id="reinstallForm" style="padding:20px;">
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:13px; font-weight:500; margin-bottom:8px;">选择系统镜像</label>
                            <select id="reinstallImageId" class="form-control" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:6px;">
                                <option value="">-- 请选择系统镜像 --</option>
                                <?php foreach ($vm_images as $img): ?>
                                <option value="<?php echo $img['id']; ?>"><?php echo e($img['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-bottom:20px;">
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="reinstallConfirm">
                                <span style="font-size:12px; color:var(--text-secondary);">我确认要重装系统，所有数据将会丢失</span>
                            </label>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="closeReinstallModal()" class="btn btn-secondary" style="flex:1;">取消</button>
                            <button type="button" onclick="submitReinstall()" class="btn btn-danger" style="flex:1;">确认重装</button>
                        </div>
                    </div>
                    <div id="reinstallStatus" style="display:none; padding:20px; text-align:center;">
                        <div style="font-size:48px; margin-bottom:16px;">🔄</div>
                        <div style="font-size:16px; font-weight:500; color:#f59e0b; margin-bottom:8px;">正在重装系统...</div>
                        <div style="font-size:13px; color:#86909c;">后台正在执行重装任务，请等待2-5分钟</div>
                        <div style="margin-top:20px;">
                            <button type="button" onclick="closeReinstallModal()" class="btn btn-secondary">关闭窗口</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 连接信息 -->
            <div class="detail-section">
                <div class="detail-section-title">
                    <h3>连接信息</h3>
                </div>
                <ul class="config-list">
                    <li>
                        <span class="label">IP地址</span>
                        <span class="value" style="font-family: monospace;"><?php echo e($host['ip_address'] ?: '等待分配'); ?></span>
                    </li>
                    <li>
                        <span class="label">SSH 端口</span>
                        <span class="value" style="font-family: monospace;"><?php echo intval($host['ssh_port'] ?: 22); ?>
                            <button type="button" onclick="openWebSSH()" class="btn btn-xs btn-primary" style="margin-left: 8px; padding: 2px 8px; font-size: 11px;" <?php echo $vm_power !== 'running' ? 'disabled' : ''; ?>>
                                💻 一键登录
                            </button>
                        </span>
                    </li>
                    <li>
                        <span class="label">VNC 端口</span>
                        <span class="value" style="font-family: monospace;"><?php echo intval($host['vnc_port'] ?: 5900); ?></span>
                    </li>
                    <li>
                        <span class="label">root 密码</span>
                        <span class="value" style="font-family: monospace;">
                            <?php if (!empty($host['root_password'])): ?>
                                <span id="pwdDisplay">••••••</span>
                                <button type="button" onclick="togglePassword(this)" style="background:none; border:none; color: var(--primary); cursor: pointer; font-size: 12px;">显示</button>
                            <?php else: ?>
                                未设置
                            <?php endif; ?>
                        </span>
                    </li>
                    <li>
                        <span class="label">CPU</span>
                        <span class="value"><?php echo intval($host['vcpu'] ?? 2); ?> 核</span>
                    </li>
                    <li>
                        <span class="label">内存</span>
                        <span class="value"><?php echo intval($host['memory_mb'] ?? 2048); ?> MB</span>
                    </li>
                    <li>
                        <span class="label">磁盘</span>
                        <span class="value"><?php echo intval($host['disk_gb'] ?? 40); ?> GB</span>
                    </li>
                </ul>
            </div>

            <!-- 底部 -->
            <div class="page-footer" style="margin: 0 -24px -20px; border-radius: 0 0 var(--radius-lg) var(--radius-lg);">
                <span>© 2026 虚拟主机控制台 · 保留所有权利</span>
                <span>版本 2.4.1 · 联系：</span>
            </div>
        </div>
    </div>

    <script>
    function togglePassword(btn) {
        var pwdDisplay = document.getElementById('pwdDisplay');
        var realPwd = '<?php echo e($host['root_password'] ?? ''); ?>';
        if (pwdDisplay.textContent === '••••••') {
            pwdDisplay.textContent = realPwd;
            btn.textContent = '隐藏';
        } else {
            pwdDisplay.textContent = '••••••';
            btn.textContent = '显示';
        }
    }
    function openVNC() {
        var vmRunning = <?php echo $vm_power === 'running' ? 'true' : 'false'; ?>;
        if (!vmRunning) {
            alert('虚拟机未运行，请先开机后再使用VNC控制台');
            return;
        }
        var wsHost = '<?php echo e($vnc_info['host']); ?>';
        var wsPort = <?php echo intval($vnc_info['port']); ?>;
        var vncPort = <?php echo intval($vnc_info['vnc_port']); ?>;
        var url = '/novnc/vnc_lite.html?host=' + wsHost + '&port=' + wsPort + '&autoconnect=true&resize=scale';
        window.open(url, '_blank');
    }
    function openWebSSH() {
        var vmRunning = <?php echo $vm_power === 'running' ? 'true' : 'false'; ?>;
        if (!vmRunning) {
            alert('虚拟机未运行，请先开机后再使用WebSSH');
            return;
        }
        var hostId = <?php echo $id; ?>;
        window.open('/webssh/index.php?id=' + hostId, '_blank');
    }
    function showSSHTab() {
        openWebSSH();
    }
    function openReinstallModal() {
        document.getElementById('reinstallModal').style.display = 'flex';
        document.getElementById('reinstallForm').style.display = 'block';
        document.getElementById('reinstallStatus').style.display = 'none';
    }
    function closeReinstallModal() {
        document.getElementById('reinstallModal').style.display = 'none';
    }

    function submitReinstall() {
        var imageId = document.getElementById('reinstallImageId').value;
        var confirmChecked = document.getElementById('reinstallConfirm').checked;

        if (!imageId) {
            alert('请选择系统镜像');
            return;
        }
        if (!confirmChecked) {
            alert('请勾选确认重装选项');
            return;
        }

        // 显示重装状态
        document.getElementById('reinstallForm').style.display = 'none';
        document.getElementById('reinstallStatus').style.display = 'block';

        // 发送异步请求到 host_kvm.php
        var fd = new FormData();
        fd.append('action', 'reinstall');
        fd.append('image_id', imageId);
        fd.append('confirm', 'yes');

        fetch('/user/host_kvm.php?id=' + <?php echo $id; ?>, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                // 重装任务已启动，刷新页面显示 reinstalling 状态
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                alert(res.message || '重装失败');
                document.getElementById('reinstallForm').style.display = 'block';
                document.getElementById('reinstallStatus').style.display = 'none';
            }
        })
        .catch(function(err) {
            alert('网络错误: ' + (err.message || '请刷新页面重试'));
            document.getElementById('reinstallForm').style.display = 'block';
            document.getElementById('reinstallStatus').style.display = 'none';
        });
    }

    function updateMonitorData() {
        var vmRunning = <?php echo $vm_power === 'running' ? 'true' : 'false'; ?>;
        if (!vmRunning) return;

        var hostId = <?php echo $id; ?>;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/user/host_monitor.php?id=' + hostId + '&action=api&t=' + Date.now(), true);
        xhr.timeout = 8000;
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (!resp.success || !resp.data) {
                        // 在控制台显示错误（不影响页面其他功能）
                        if (resp.error) {
                            console.warn('监控数据获取失败: ' + resp.error);
                        }
                        return;
                    }
                    var d = resp.data;

                    if (d.cpu_usage !== undefined) {
                        document.querySelectorAll('[data-monitor="cpu"]').forEach(function(el) {
                            el.textContent = d.cpu_usage.toFixed(1) + '%';
                        });
                        document.querySelectorAll('[data-monitor="cpu-bar"]').forEach(function(el) {
                            el.style.width = Math.min(100, d.cpu_usage.toFixed(1)) + '%';
                        });
                    }
                    if (d.mem_usage !== undefined) {
                        document.querySelectorAll('[data-monitor="mem"]').forEach(function(el) {
                            el.textContent = d.mem_usage.toFixed(1) + '%';
                        });
                        document.querySelectorAll('[data-monitor="mem-bar"]').forEach(function(el) {
                            el.style.width = Math.min(100, d.mem_usage.toFixed(1)) + '%';
                        });
                    }
                    if (d.mem_used !== undefined) {
                        var memUsedEl = document.querySelector('[data-monitor="mem-used"]');
                        if (memUsedEl) memUsedEl.textContent = Math.round(d.mem_used);
                    }
                    if (d.network_rx !== undefined) {
                        document.querySelectorAll('[data-monitor="rx"]').forEach(function(el) {
                            el.textContent = d.network_rx.toFixed(2);
                        });
                    }
                    if (d.network_tx !== undefined) {
                        document.querySelectorAll('[data-monitor="tx"]').forEach(function(el) {
                            el.textContent = d.network_tx.toFixed(2);
                        });
                    }
                    if (d.disk_read !== undefined) {
                        document.querySelectorAll('[data-monitor="disk-read"]').forEach(function(el) {
                            el.textContent = d.disk_read.toFixed(2);
                        });
                    }
                    if (d.disk_write !== undefined) {
                        document.querySelectorAll('[data-monitor="disk-write"]').forEach(function(el) {
                            el.textContent = d.disk_write.toFixed(2);
                        });
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    }

    setInterval(updateMonitorData, 5000);
    </script>
</body>
</html>
