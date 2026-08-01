<?php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from CLI');
}

@set_time_limit(0);
@ini_set('max_execution_time', 0);

require_once __DIR__ . '/../config/helper.php';

$task_id = intval($argv[1] ?? 0);

if ($task_id <= 0) {
    die("Invalid task_id\n");
}

$task = Database::fetch("SELECT * FROM vm_tasks WHERE id = ?", [$task_id]);
if (!$task) {
    die("Task not found\n");
}

Database::update('vm_tasks', [
    'status' => 'running',
    'started_at' => date('Y-m-d H:i:s'),
], 'id = ?', [$task_id]);

$task_data = json_decode($task['task_data'] ?? '', true);
$snapshot_id = intval($task_data['snapshot_id'] ?? 0);
$uid = intval($task['user_id'] ?? 0);
$action_type = $task['task_type'] ?? ''; // 'restore' or 'delete_snapshot'

if ($snapshot_id <= 0) {
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => '快照ID无效',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    die("Invalid snapshot_id\n");
}

// 执行快照操作
if ($action_type === 'restore') {
    $result = kvm_restore_snapshot($snapshot_id, $uid);
} elseif ($action_type === 'delete_snapshot') {
    $result = kvm_delete_snapshot($snapshot_id, $uid);
} else {
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => '未知任务类型',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    die("Unknown task type: $action_type\n");
}

if ($result && !empty($result['success'])) {
    Database::update('vm_tasks', [
        'status' => 'completed',
        'result_msg' => $result['message'] ?? '操作成功',
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    echo "Task completed: " . ($result['message'] ?? '') . "\n";
} else {
    $error_msg = $result['message'] ?? '未知错误';
    
    Database::update('vm_tasks', [
        'status' => 'error',
        'error_msg' => $error_msg,
        'finished_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$task_id]);
    
    if ($action_type === 'restore' && $snapshot_id > 0) {
        $snapshot = Database::fetch("SELECT status FROM vm_snapshots WHERE id = ?", [$snapshot_id]);
        if ($snapshot && $snapshot['status'] === 'restoring') {
            Database::update('vm_snapshots', [
                'status' => 'error',
                'error_msg' => $error_msg,
            ], 'id = ?', [$snapshot_id]);
        }
    } elseif ($action_type === 'delete_snapshot' && $snapshot_id > 0) {
        $snapshot = Database::fetch("SELECT status FROM vm_snapshots WHERE id = ?", [$snapshot_id]);
        if ($snapshot && $snapshot['status'] === 'deleting') {
            Database::update('vm_snapshots', [
                'status' => 'error',
                'error_msg' => $error_msg,
            ], 'id = ?', [$snapshot_id]);
        }
    }
    
    echo "Task failed: " . $error_msg . "\n";
}