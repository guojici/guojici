<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$order_no = trim(get('order_no', ''));
$uid = auth_id();

if (empty($order_no)) {
    echo json_encode(['status' => 'error', 'message' => '订单号不能为空']);
    exit;
}

$order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [$order_no, $uid]);
if (!$order) {
    echo json_encode(['status' => 'error', 'message' => '订单不存在']);
    exit;
}

$host = Database::fetch("SELECT * FROM hosts WHERE order_id = ?", [$order['id']]);

if ($order['status'] === 'completed' && $host) {
    echo json_encode(['status' => 'completed', 'host_id' => $host['id']]);
} elseif ($order['status'] === 'processing') {
    echo json_encode(['status' => 'processing']);
} elseif (strpos($order['remark'] ?? '', 'create_error:') === 0) {
    $error_msg = substr($order['remark'], strlen('create_error:'));
    $pipe_pos = strpos($error_msg, ' |');
    if ($pipe_pos !== false) {
        $error_msg = trim(substr($error_msg, 0, $pipe_pos));
    }
    echo json_encode(['status' => 'error', 'message' => $error_msg]);
} else {
    echo json_encode(['status' => 'pending']);
}
