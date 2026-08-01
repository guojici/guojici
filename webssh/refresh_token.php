<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

$host_id = intval($_GET['id'] ?? 0);
$uid = auth_id();

if ($host_id <= 0) {
    echo json_encode(['code' => 1, 'msg' => '参数错误']);
    exit;
}

$host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $uid]);
if (!$host) {
    echo json_encode(['code' => 1, 'msg' => '主机不存在']);
    exit;
}

$is_kvm = ($host['vm_type'] ?? 'web') === 'kvm';
if (!$is_kvm) {
    echo json_encode(['code' => 1, 'msg' => '仅KVM主机支持WebSSH']);
    exit;
}

$ip_address = $host['ip_address'] ?: '';

if (empty($ip_address) || $ip_address === '0.0.0.0' || $ip_address === '127.0.0.1') {
    $refresh_result = kvm_refresh_status($host);
    if ($refresh_result && !empty($refresh_result['ip'])) {
        $ip_address = $refresh_result['ip'];
    } else {
        $ip_address = '';
    }
}

if (empty($ip_address)) {
    echo json_encode(['code' => 1, 'msg' => '无法获取虚拟机IP地址，请等待DHCP分配后重试']);
    exit;
}

$is_local_kvm = preg_match('/^(192\.168\.122\.|10\.|172\.16\.|172\.17\.|172\.18\.|172\.19\.|172\.20\.|172\.21\.|172\.22\.|172\.23\.|172\.24\.|172\.25\.|172\.26\.|172\.27\.|172\.28\.|172\.29\.|172\.30\.|172\.31\.)/', $ip_address);
if ($is_local_kvm) {
    $ssh_port = 22;
} else {
    $ssh_port = intval($host['ssh_port'] ?: 22);
}

$ssh_user = 'root';
$ssh_password = $host['root_password'] ?: '';

if (empty($ssh_password)) {
    echo json_encode(['code' => 1, 'msg' => '密码为空，请先设置root密码']);
    exit;
}

$token = create_ssh_token($uid, $host_id, $ip_address, $ssh_port, $ssh_user, $ssh_password, 300);
if (!$token) {
    echo json_encode(['code' => 1, 'msg' => '创建token失败']);
    exit;
}

echo json_encode(['code' => 0, 'token' => $token, 'ip' => $ip_address]);
