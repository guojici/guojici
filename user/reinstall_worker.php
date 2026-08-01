<?php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from CLI');
}

// CLI模式下不设超时
@set_time_limit(0);
@ini_set('max_execution_time', 0);

require_once __DIR__ . '/../config/helper.php';

$task_id = intval($argv[1] ?? 0);
$host_id = intval($argv[2] ?? 0);

if ($task_id <= 0 || $host_id <= 0) {
    die("Invalid parameters: task_id=$task_id, host_id=$host_id\n");
}

// 获取任务信息
$task = Database::fetch("SELECT * FROM vm_tasks WHERE id = ?", [$task_id]);
if (!$task) {
    die("Task not found: task_id=$task_id\n");
}

// 更新任务状态为 running
Database::update('vm_tasks', [
    'status' => 'running',
    'started_at' => date('Y-m-d H:i:s'),
], 'id = ?', [$task_id]);

// 解析任务数据
$task_data = json_decode($task['task_data'] ?? '', true);
$image_id = intval($task_data['image_id'] ?? 0);

if ($image_id <= 0) {
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => '镜像ID无效',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    Database::update('hosts', ['vm_power_status' => 'stopped'], 'id = ?', [$host_id]);
    die("Invalid image_id\n");
}

// 获取主机信息
$host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$host_id]);
if (!$host) {
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => '主机不存在',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    die("Host not found\n");
}

// 执行重装
$result = kvm_reinstall($host, $image_id);

if ($result && !empty($result['success'])) {
    // 重装成功，虚拟机已经被启动
    Database::update('vm_tasks', [
        'status' => 'completed',
        'result_msg' => '重装成功',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);

    // 更新主机状态为 running（reinstallVM 已经启动了虚拟机）
    Database::update('hosts', [
        'vm_power_status' => 'running',
        'vm_last_sync' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$host_id]);

    // 等待虚拟机启动后，通过 virsh set-user-password 设置密码（最后保障）
    $root_password = $host['root_password'] ?? '';
    $vm_name = $host['vm_name'] ?? '';
    if (!empty($root_password) && !empty($vm_name)) {
        // 等待15秒让虚拟机完全启动
        sleep(15);

        // 获取镜像默认用户名
        $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
        $username = !empty($image['default_username']) ? $image['default_username'] : 'root';

        $kvm = kvm_get_manager();
        $pwd_result = $kvm->exec("virsh set-user-password " . escapeshellarg($vm_name) . " " . escapeshellarg($username) . " " . escapeshellarg($root_password) . " 2>&1");

        if ($pwd_result !== false && strpos($pwd_result, 'error') === false && strpos($pwd_result, 'failed') === false) {
            echo "Password set via virsh successfully\n";
        } else {
            echo "Password set via virsh failed: " . $pwd_result . "\n";
            // 再等待15秒后重试一次
            sleep(15);
            $pwd_result2 = $kvm->exec("virsh set-user-password " . escapeshellarg($vm_name) . " " . escapeshellarg($username) . " " . escapeshellarg($root_password) . " 2>&1");
            if ($pwd_result2 !== false && strpos($pwd_result2, 'error') === false && strpos($pwd_result2, 'failed') === false) {
                echo "Password set via virsh (retry) successfully\n";
            } else {
                echo "Password set via virsh (retry) failed: " . $pwd_result2 . "\n";
            }
        }
    }

    echo "Reinstall completed successfully\n";
} else {
    // 重装失败
    $error_msg = $result['message'] ?? '未知错误';
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => $error_msg,
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);

    Database::update('hosts', [
        'vm_power_status' => 'stopped',
    ], 'id = ?', [$host_id]);

    echo "Reinstall failed: $error_msg\n";
}