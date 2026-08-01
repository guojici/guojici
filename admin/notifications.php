<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

migrate_new_tables();

$page = max(1, intval($_GET['page'] ?? 1));
$page_size = 20;
$offset = ($page - 1) * $page_size;

$filter_user = trim($_GET['user'] ?? '');
$filter_type = trim($_GET['type'] ?? '');
$filter_read = $_GET['read'] ?? '';

$where = '1=1';
$params = [];

if ($filter_user) {
    $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.id = ?)";
    $params[] = "%$filter_user%";
    $params[] = "%$filter_user%";
    $params[] = is_numeric($filter_user) ? intval($filter_user) : -1;
}

if ($filter_type) {
    $where .= " AND n.type = ?";
    $params[] = $filter_type;
}

if ($filter_read !== '') {
    $where .= " AND n.is_read = ?";
    $params[] = intval($filter_read);
}

if (is_post()) {
    $action = post('action');
    
    if ($action === 'send') {
        $user_id = intval(post('user_id', 0));
        $target = post('target', 'single');
        $type = post('type', 'system');
        $title = trim(post('title', ''));
        $content = trim(post('content', ''));
        
        if (empty($title)) {
            flash('error', '通知标题不能为空');
        } else {
            $sent_count = 0;
            try {
                if ($target === 'all') {
                    $users = Database::fetchAll("SELECT id FROM users WHERE status = 'active'");
                    foreach ($users as $u) {
                        send_notification($u['id'], $type, $title, $content);
                        $sent_count++;
                    }
                    flash('success', "已向 {$sent_count} 位用户发送通知");
                } elseif ($target === 'all_kvm') {
                    $users = Database::fetchAll("SELECT DISTINCT h.user_id FROM hosts h WHERE h.vm_type = 'kvm' AND h.user_id > 0");
                    foreach ($users as $u) {
                        send_notification($u['user_id'], $type, $title, $content);
                        $sent_count++;
                    }
                    flash('success', "已向 {$sent_count} 位KVM用户发送通知");
                } elseif ($user_id > 0) {
                    send_notification($user_id, $type, $title, $content);
                    flash('success', '通知已发送');
                } else {
                    flash('error', '请选择发送对象');
                }
            } catch (Exception $e) {
                flash('error', '发送失败：' . $e->getMessage());
            }
        }
        header('Location: /admin/notifications.php');
        exit;
    }
    
    if ($action === 'mark_read') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            Database::update('user_notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            flash('success', '已标记为已读');
        }
        header('Location: /admin/notifications.php');
        exit;
    }
    
    if ($action === 'mark_all_read') {
        Database::query("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
        flash('success', '已全部标记为已读');
        header('Location: /admin/notifications.php');
        exit;
    }
    
    if ($action === 'delete') {
        $id = intval(post('id', 0));
        if ($id > 0) {
            Database::delete('user_notifications', 'id = ?', [$id]);
            flash('success', '通知已删除');
        }
        header('Location: /admin/notifications.php');
        exit;
    }
    
    if ($action === 'delete_all') {
        Database::query("DELETE FROM user_notifications WHERE is_read = 1");
        flash('success', '已删除所有已读通知');
        header('Location: /admin/notifications.php');
        exit;
    }
}

$total = Database::fetch("SELECT COUNT(*) as total FROM user_notifications n LEFT JOIN users u ON n.user_id = u.id WHERE $where", $params);
$total = $total['total'] ?? 0;

$notifications = Database::fetchAll(
    "SELECT n.*, u.username, u.email FROM user_notifications n LEFT JOIN users u ON n.user_id = u.id WHERE $where ORDER BY n.id DESC LIMIT $offset, $page_size",
    $params
);

$total_pages = ceil($total / $page_size);

$stats = [
    'total' => Database::fetch("SELECT COUNT(*) as c FROM user_notifications")['c'] ?? 0,
    'unread' => Database::fetch("SELECT COUNT(*) as c FROM user_notifications WHERE is_read = 0")['c'] ?? 0,
    'today' => Database::fetch("SELECT COUNT(*) as c FROM user_notifications WHERE DATE(created_at) = CURDATE()")['c'] ?? 0,
];

$type_map = [
    'system' => '系统通知',
    'host' => '主机通知',
    'order' => '订单通知',
    'security' => '安全通知',
    'promotion' => '促销通知',
];

$type_icon_map = [
    'system' => '🔔',
    'host' => '🖥️',
    'order' => '📦',
    'security' => '🔒',
    'promotion' => '🎁',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知管理 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .send-form {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
        }
        .send-form-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .send-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .send-form .form-row-full {
            grid-column: 1 / -1;
        }
        .send-form textarea {
            width: 100%;
            min-height: 80px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            resize: vertical;
            font-family: inherit;
        }
        .send-form textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            font-family: 'SF Mono', Monaco, monospace;
        }
        .stat-value.unread {
            color: var(--danger);
        }
        .stat-value.today {
            color: var(--primary);
        }
        .filter-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
            background: #fff;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }
        .filter-bar input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .notification-admin-item {
            display: flex;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-light);
            transition: background 0.15s;
            cursor: pointer;
        }
        .notification-admin-item:hover {
            background: var(--bg-light);
        }
        .notification-admin-icon {
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
        .notification-admin-content {
            flex: 1;
            min-width: 0;
        }
        .notification-admin-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .notification-admin-meta {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .notification-admin-actions {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            flex-shrink: 0;
        }
        .notification-admin-item.unread {
            background: #f0f7ff;
        }
        .notification-admin-item.unread .notification-admin-title {
            font-weight: 700;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-system { background: #e6f7ff; color: #1890ff; }
        .badge-host { background: #f6ffed; color: #52c41a; }
        .badge-order { background: #fff7e6; color: #faad14; }
        .badge-security { background: #fff1f0; color: #ff4d4f; }
        .badge-promotion { background: #f9f0ff; color: #722ed1; }
        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
        }
        .user-select-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">通知管理</h1>
                    <p class="page-subtitle">管理系统通知，向用户发送站内消息</p>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo e(flash('success')); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo e(flash('error')); ?></div><?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">全部通知</div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">未读通知</div>
                    <div class="stat-value unread"><?php echo number_format($stats['unread']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">今日发送</div>
                    <div class="stat-value today"><?php echo number_format($stats['today']); ?></div>
                </div>
            </div>

            <div class="send-form">
                <div class="send-form-title">📤 发送通知</div>
                <form method="POST">
                    <input type="hidden" name="action" value="send">
                    <div class="form-row">
                        <div class="form-group">
                            <label style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; display: block;">发送对象</label>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer;">
                                    <input type="radio" name="target" value="single" checked style="margin: 0;"> 指定用户
                                </label>
                                <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer;">
                                    <input type="radio" name="target" value="all" style="margin: 0;"> 全部用户
                                </label>
                                <label style="display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer;">
                                    <input type="radio" name="target" value="all_kvm" style="margin: 0;"> 全部KVM用户
                                </label>
                            </div>
                            <div id="userSelectArea" style="margin-top: 8px;">
                                <select name="user_id" class="form-control" style="font-size: 13px;">
                                    <option value="0">-- 选择用户 --</option>
                                    <?php
                                    $all_users = Database::fetchAll("SELECT id, username, email FROM users WHERE status = 'active' ORDER BY id ASC LIMIT 200");
                                    foreach ($all_users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo e($u['username']); ?> (<?php echo e($u['email']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; display: block;">通知类型</label>
                            <select name="type" class="form-control" style="font-size: 13px;">
                                <option value="system">🔔 系统通知</option>
                                <option value="host">🖥️ 主机通知</option>
                                <option value="order">📦 订单通知</option>
                                <option value="security">🔒 安全通知</option>
                                <option value="promotion">🎁 促销通知</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-row-full">
                            <label style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; display: block;">通知标题 *</label>
                            <input type="text" name="title" class="form-control" placeholder="请输入通知标题" required style="font-size: 14px;">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group form-row-full">
                            <label style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; display: block;">通知内容</label>
                            <textarea name="content" placeholder="请输入通知内容（可选）" class="form-control"></textarea>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary">📤 发送通知</button>
                    </div>
                </form>
            </div>

            <div class="filter-bar">
                <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                    <input type="text" name="user" placeholder="搜索用户ID/用户名/邮箱" value="<?php echo e($filter_user); ?>" style="width: 200px;">
                    <select name="type" onchange="this.form.submit()" style="min-width: 120px;">
                        <option value="">全部类型</option>
                        <?php foreach ($type_map as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $filter_type === $k ? 'selected' : ''; ?>><?php echo $type_icon_map[$k]; ?> <?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="read" onchange="this.form.submit()" style="min-width: 100px;">
                        <option value="">全部状态</option>
                        <option value="0" <?php echo $filter_read === '0' ? 'selected' : ''; ?>>未读</option>
                        <option value="1" <?php echo $filter_read === '1' ? 'selected' : ''; ?>>已读</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-secondary">🔍 筛选</button>
                    <a href="/admin/notifications.php" class="btn btn-sm btn-secondary">重置</a>
                </form>
                <div style="margin-left: auto; display: flex; gap: 8px;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-sm btn-secondary">✓ 全部已读</button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('确定删除所有已读通知？');">
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="btn btn-sm btn-secondary">🗑️ 清空已读</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div style="padding: 8px 16px; background: var(--bg-light); border-bottom: 1px solid var(--border); font-size: 13px; color: var(--text-secondary);">
                    共 <?php echo number_format($total); ?> 条通知
                </div>
                <?php if (empty($notifications)): ?>
                <div style="padding: 60px 20px; text-align: center; color: var(--text-secondary);">
                    <div style="font-size: 36px; margin-bottom: 8px;">📭</div>
                    <div>暂无通知</div>
                </div>
                <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                <div class="notification-admin-item <?php echo $n['is_read'] ? '' : 'unread'; ?>"
                     onclick="openNotification(<?php echo $n['id']; ?>, '<?php echo e($n['related_type']); ?>', <?php echo intval($n['related_id']); ?>)">
                    <div class="notification-admin-icon">
                        <?php echo $type_icon_map[$n['type']] ?? '🔔'; ?>
                    </div>
                    <div class="notification-admin-content">
                        <div class="notification-admin-title">
                            <?php if (!$n['is_read']): ?><span style="display: inline-block; width: 8px; height: 8px; background: var(--primary); border-radius: 50%; margin-right: 6px;"></span><?php endif; ?>
                            <?php echo e($n['title']); ?>
                        </div>
                        <?php if (!empty($n['content'])): ?>
                        <div style="font-size: 13px; color: var(--text-secondary); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo e($n['content']); ?>
                        </div>
                        <?php endif; ?>
                        <div class="notification-admin-meta">
                            <span class="badge badge-<?php echo $n['type']; ?>"><?php echo $type_map[$n['type']] ?? $n['type']; ?></span>
                            <span>用户: <?php echo e($n['username'] ?? '未知') . ' (' . e($n['email'] ?? '-') . ')'; ?></span>
                            <span><?php echo e(format_date($n['created_at'])); ?></span>
                            <?php if ($n['is_read']): ?>
                            <span style="color: #52c41a;">✓ 已读</span>
                            <?php else: ?>
                            <span style="color: var(--danger);">未读</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="notification-admin-actions" onclick="event.stopPropagation()">
                        <?php if (!$n['is_read']): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-secondary" title="标记已读">✓</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('确定删除此通知？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="删除">🗑️</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($total_pages > 1): ?>
                <div class="pagination" style="padding: 16px; display: flex; justify-content: center; gap: 8px;">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&user=<?php echo urlencode($filter_user); ?>&type=<?php echo urlencode($filter_type); ?>&read=<?php echo urlencode($filter_read); ?>" class="btn btn-sm btn-secondary">上一页</a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a href="?page=<?php echo $p; ?>&user=<?php echo urlencode($filter_user); ?>&type=<?php echo urlencode($filter_type); ?>&read=<?php echo urlencode($filter_read); ?>" class="btn btn-sm <?php echo $p === $page ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&user=<?php echo urlencode($filter_user); ?>&type=<?php echo urlencode($filter_type); ?>&read=<?php echo urlencode($filter_read); ?>" class="btn btn-sm btn-secondary">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('input[name="target"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var select = document.querySelector('select[name="user_id"]');
            if (this.value === 'single') {
                select.disabled = false;
                select.parentElement.style.display = '';
            } else {
                select.disabled = true;
                select.parentElement.style.display = 'none';
            }
        });
    });

    function openNotification(id, relatedType, relatedId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/notifications.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('action=mark_read&id=' + id);

        if (relatedType === 'host' && relatedId > 0) {
            window.location.href = '/admin/hosts.php';
        } else if (relatedType === 'order' && relatedId > 0) {
            window.location.href = '/admin/orders.php';
        } else if (relatedType === 'ad_spot' && relatedId > 0) {
            window.location.href = '/admin/ad_network.php';
        } else if (relatedType === 'ticket' && relatedId > 0) {
            window.location.href = '/admin/tickets.php';
        } else if (relatedType === 'exchange' && relatedId > 0) {
            window.location.href = '/admin/point_exchange.php';
        }
    }
    </script>
</body>
</html>
