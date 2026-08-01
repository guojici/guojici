<?php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from CLI');
}

require_once __DIR__ . '/../config/helper.php';

$task_id = intval($argv[1] ?? 0);

if ($task_id <= 0) {
    die("Invalid task_id\n");
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
$iso_path = $task_data['iso_path'] ?? '';
$output_path = $task_data['output_path'] ?? '';
$disk_size_gb = intval($task_data['disk_size_gb'] ?? 40);
$memory_mb = intval($task_data['memory_mb'] ?? 2048);

if (empty($iso_path)) {
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => 'ISO路径为空',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    die("ISO path empty\n");
}

// 启动 QEMU 引导 ISO 安装（异步：启动后立即返回，不等待安装完成）
$result = kvm_convert_iso_to_qcow2($iso_path, $output_path, $disk_size_gb, $memory_mb);

if ($result && !empty($result['success'])) {
    // QEMU 启动成功，安装环境已就绪
    // 任务标记为 completed 表示"安装环境已启动"，用户需通过 VNC 完成系统安装
    Database::update('vm_tasks', [
        'status' => 'completed',
        'result_msg' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    echo "QEMU started successfully, ISO booting\n";
    echo "VNC port: " . ($result['vnc_port'] ?? 0) . "\n";
    echo "noVNC URL: " . ($result['ws_url'] ?? '') . "\n";
    echo "Output: " . ($result['output_path'] ?? '') . "\n";
} else {
    // 启动失败
    $error_msg = $result['message'] ?? '未知错误';
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => $error_msg,
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    echo "Convert failed: $error_msg\n";
}
