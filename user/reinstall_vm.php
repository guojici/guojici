<?php
// AJAX请求不输出HTML错误，只返回JSON
@ini_set('display_errors', 0);
@error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

define('SKIP_SESSION_START', false);
require_once __DIR__ . '/../config/helper.php';

// 如果未登录，返回JSON错误而不是重定向
if (!auth_check()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登录或登录已过期']);
    exit;
}

header('Content-Type: application/json');

$uid = auth_id();
$host_id = intval(post('host_id', 0));
$image_id = intval(post('image_id', 0));
$confirm = post('confirm', '');

if ($host_id <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少主机ID']);
    exit;
}

$host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $uid]);
if (!$host) {
    echo json_encode(['success' => false, 'message' => '主机不存在或无权访问']);
    exit;
}

if ($confirm !== 'yes') {
    echo json_encode(['success' => false, 'message' => '请勾选确认重装选项']);
    exit;
}

if ($image_id <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择系统镜像']);
    exit;
}

// 更新主机状态为 reinstalling
Database::update('hosts', [
    'vm_power_status' => 'reinstalling',
], 'id = ?', [$host_id]);

// 创建重装任务记录
$task_id = Database::insert('vm_tasks', [
    'host_id' => $host_id,
    'user_id' => $uid,
    'task_type' => 'reinstall',
    'task_data' => json_encode(['image_id' => $image_id]),
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s'),
]);

// 异步执行重装任务
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
        $host_id
    );
    @exec($cmd);
}

echo json_encode([
    'success' => true,
    'message' => '重装任务已启动',
    'task_id' => $task_id,
    'status' => 'reinstalling',
]);