<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$order_no = trim(post('order_no', ''));
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

if (!in_array($order['status'], ['paid', 'processing'])) {
    echo json_encode(['status' => 'error', 'message' => '订单状态异常']);
    exit;
}

$existing_host = Database::fetch("SELECT * FROM hosts WHERE order_id = ?", [$order['id']]);
if ($existing_host) {
    echo json_encode(['status' => 'completed', 'host_id' => $existing_host['id']]);
    exit;
}

if ($order['status'] === 'paid') {
    Database::update('orders', ['status' => 'processing'], 'order_no = ?', [$order_no]);
}

$pkg_info = json_decode($order['package_info'] ?? '{}', true);
$image_id = intval($pkg_info['image_id'] ?? 0);
$is_kvm_order = ($image_id > 0);

if ($is_kvm_order) {
    $root_password = $pkg_info['root_password'] ?? '';
    $create_result = kvm_create_vm($order, $uid, $image_id, $root_password);
    
    if ($create_result && !empty($create_result['success'])) {
        Database::update('orders', ['status' => 'completed'], 'order_no = ?', [$order_no]);
        $host_id = $create_result['host_id'] ?? 0;
        $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$host_id]);
        $ip_text = $host['ip_address'] ? 'IP: ' . $host['ip_address'] : '';
        send_notification($uid, 'host', '云主机开通成功', 
            '您的KVM云主机 ' . ($host['vm_name'] ?? $host['mnbt_username']) . ' 已开通成功。' . $ip_text,
            'host', $host_id);
        echo json_encode(['status' => 'completed', 'host_id' => $host_id]);
    } else {
        $error_msg = $create_result['message'] ?? '创建失败';
        $remark = 'create_error:' . $error_msg . ' | ' . ($order['remark'] ?? '');
        Database::update('orders', [
            'status' => 'paid',
            'remark' => substr($remark, 0, 255)
        ], 'order_no = ?', [$order_no]);
        send_notification($uid, 'host', '云主机开通失败', 
            '您的KVM云主机开通失败，原因：' . $error_msg,
            'order', $order['id']);
        echo json_encode(['status' => 'error', 'message' => $error_msg]);
    }
} else {
    $result = mnbt_create_host($order, $uid);
    if ($result) {
        Database::update('orders', ['status' => 'completed'], 'order_no = ?', [$order_no]);
        $new_host = Database::fetch("SELECT * FROM hosts WHERE order_id = ?", [$order['id']]);
        $host_id = $new_host ? $new_host['id'] : 0;
        send_notification($uid, 'host', '虚拟主机开通成功', 
            '您的虚拟主机 ' . ($new_host['mnbt_username'] ?? '') . ' 已开通成功。',
            'host', $host_id);
        echo json_encode(['status' => 'completed', 'host_id' => $host_id]);
    } else {
        send_notification($uid, 'host', '虚拟主机开通失败', 
            '您的虚拟主机开通失败，请联系客服。',
            'order', $order['id']);
        echo json_encode(['status' => 'error', 'message' => '创建失败']);
    }
}
