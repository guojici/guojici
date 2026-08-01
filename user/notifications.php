<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$uid = auth_id();
$user = auth_user();
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$type = $_GET['type'] ?? 'all';

$where = 'WHERE user_id = ?';
$params = [$uid];
if ($type !== 'all') {
    $where .= ' AND type = ?';
    $params[] = $type;
}

$total = Database::fetch("SELECT COUNT(*) as cnt FROM user_notifications $where", $params)['cnt'];
$total_pages = ceil($total / $per_page);
$offset = ($page - 1) * $per_page;

$notifications = Database::fetchAll(
    "SELECT * FROM user_notifications $where ORDER BY created_at DESC LIMIT $offset, $per_page",
    $params
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'read_all') {
        mark_notification_read($uid);
        header('Location: /user/notifications.php?type=' . urlencode($type));
        exit;
    } elseif ($action === 'read' && !empty($_POST['id'])) {
        mark_notification_read($uid, intval($_POST['id']));
        echo json_encode(['code' => 0, 'msg' => 'ok']);
        exit;
    }
}

$unread_count = get_unread_notification_count($uid);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知中心 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">🔔 通知中心</h1>
                    <p class="page-subtitle">共 <?php echo $total; ?> 条通知，其中 <?php echo $unread_count; ?> 条未读</p>
                </div>
                <?php if ($unread_count > 0): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="read_all">
                    <button type="submit" class="btn btn-secondary">✓ 全部标为已读</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>

            <div class="card" style="margin-bottom: 16px;">
                <div style="display: flex; gap: 4px; border-bottom: 1px solid var(--border);">
                    <a href="/user/notifications.php?type=all" class="notif-tab <?php echo $type === 'all' ? 'active' : ''; ?>">全部</a>
                    <a href="/user/notifications.php?type=host" class="notif-tab <?php echo $type === 'host' ? 'active' : ''; ?>">🖥️ 主机</a>
                    <a href="/user/notifications.php?type=order" class="notif-tab <?php echo $type === 'order' ? 'active' : ''; ?>">📦 订单</a>
                    <a href="/user/notifications.php?type=system" class="notif-tab <?php echo $type === 'system' ? 'active' : ''; ?>">🔔 系统</a>
                    <a href="/user/notifications.php?type=promotion" class="notif-tab <?php echo $type === 'promotion' ? 'active' : ''; ?>">📢 促销</a>
                </div>

                <?php if (empty($notifications)): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
                    <div style="font-size: 15px; color: var(--text-secondary); margin-bottom: 8px;">暂无通知</div>
                    <div style="font-size: 13px; color: var(--text-tertiary);">您还没有收到任何通知</div>
                </div>
                <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $n): ?>
                    <div class="notif-item <?php echo $n['is_read'] ? 'is-read' : 'is-unread'; ?>"
                         onclick="openNotification(<?php echo $n['id']; ?>, '<?php echo e($n['related_type']); ?>', <?php echo intval($n['related_id']); ?>)">
                        <div class="notif-icon">
                            <?php echo notification_type_icon($n['type']); ?>
                        </div>
                        <div class="notif-content">
                            <div class="notif-title-row">
                                <?php if (!$n['is_read']): ?>
                                <span class="notif-dot"></span>
                                <?php endif; ?>
                                <span class="notif-title-text"><?php echo e($n['title']); ?></span>
                                <span class="notif-time"><?php echo format_time_ago($n['created_at']); ?></span>
                            </div>
                            <?php if (!empty($n['content'])): ?>
                            <div class="notif-desc"><?php echo e($n['content']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="/user/notifications.php?type=<?php echo urlencode($type); ?>&page=<?php echo $page - 1; ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    <span class="page-info">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页</span>
                    <?php if ($page < $total_pages): ?>
                    <a href="/user/notifications.php?type=<?php echo urlencode($type); ?>&page=<?php echo $page + 1; ?>" class="page-btn">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<style>
.notif-tab {
    padding: 10px 18px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    text-decoration: none;
    transition: all 0.2s;
    font-weight: 500;
}
.notif-tab:hover { color: var(--text-primary); }
.notif-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.notif-list { padding: 4px 0; }
.notif-item {
    display: flex;
    gap: 14px;
    padding: 16px 20px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s;
}
.notif-item:hover { background: var(--bg-light); }
.notif-item:last-child { border-bottom: none; }
.notif-item.is-read { opacity: 0.65; }
.notif-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--bg-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.notif-content { flex: 1; min-width: 0; }
.notif-title-row {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 500;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.notif-time {
    font-size: 12px;
    color: var(--text-tertiary);
    font-weight: normal;
    margin-left: auto;
}
.notif-desc {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.6;
}
.notif-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--primary);
    flex-shrink: 0;
}
</style>

<script>
function openNotification(id, relatedType, relatedId) {
    window.location.href = '/user/notification_detail.php?id=' + id;
}
</script>
</body>
</html>
