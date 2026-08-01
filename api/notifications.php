<?php
require_once __DIR__ . '/../config/helper.php';
header('Content-Type: application/json; charset=utf-8');

if (!auth_check()) {
    echo json_encode(['code' => 401, 'msg' => '请先登录']);
    exit;
}
session_write_close();

$user = auth_user();
$uid = intval($user['id']);
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $limit = intval($_GET['limit'] ?? 10);
    $only_unread = isset($_GET['unread']) && $_GET['unread'] == 1;
    $notifications = get_user_notifications($uid, $limit, $only_unread);
    $unread_count = get_unread_notification_count($uid);
    
    $list = [];
    foreach ($notifications as $n) {
        $list[] = [
            'id' => intval($n['id']),
            'type' => $n['type'],
            'icon' => notification_type_icon($n['type']),
            'title' => $n['title'],
            'content' => $n['content'] ?? '',
            'related_type' => $n['related_type'] ?? '',
            'related_id' => intval($n['related_id'] ?? 0),
            'is_read' => intval($n['is_read']),
            'created_at' => $n['created_at'],
            'time_text' => format_time_ago($n['created_at']),
        ];
    }
    
    echo json_encode([
        'code' => 0,
        'msg' => 'ok',
        'unread_count' => $unread_count,
        'list' => $list,
    ]);
} elseif ($action === 'read') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $result = mark_notification_read($uid, $id);
    echo json_encode([
        'code' => $result ? 0 : 1,
        'msg' => $result ? 'ok' : '失败',
        'unread_count' => get_unread_notification_count($uid),
    ]);
} elseif ($action === 'count') {
    echo json_encode([
        'code' => 0,
        'unread_count' => get_unread_notification_count($uid),
    ]);
} else {
    echo json_encode(['code' => 1, 'msg' => '未知操作']);
}
