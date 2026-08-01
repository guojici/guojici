<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$uid = auth_id();
$notification_id = intval($_GET['id'] ?? 0);

if ($notification_id <= 0) {
    flash('error', '无效的通知ID');
    header('Location: /user/notifications.php');
    exit;
}

$notification = Database::fetch("SELECT * FROM user_notifications WHERE id = ? AND user_id = ?", [$notification_id, $uid]);

if (!$notification) {
    flash('error', '通知不存在或无权查看');
    header('Location: /user/notifications.php');
    exit;
}

if (!$notification['is_read']) {
    mark_notification_read($uid, $notification_id);
    $notification['is_read'] = 1;
}

$type_map = [
    'system' => '系统通知',
    'host' => '主机通知',
    'order' => '订单通知',
    'security' => '安全通知',
    'promotion' => '促销通知',
    'ad_network' => '广告联盟通知',
];

$type_icon_map = [
    'system' => '🔔',
    'host' => '🖥️',
    'order' => '📦',
    'security' => '🔒',
    'promotion' => '🎁',
    'ad_network' => '📢',
];

function get_related_link($related_type, $related_id) {
    if ($related_type === 'host' && $related_id > 0) {
        return '/user/host_detail.php?id=' . $related_id;
    } elseif ($related_type === 'order' && $related_id > 0) {
        return '/user/orders.php';
    } elseif ($related_type === 'host_kvm' && $related_id > 0) {
        return '/user/host_kvm.php?id=' . $related_id;
    }
    return null;
}

function get_related_name($related_type, $related_id) {
    if ($related_type === 'host' && $related_id > 0) {
        return '查看主机详情';
    } elseif ($related_type === 'order' && $related_id > 0) {
        return '查看订单列表';
    } elseif ($related_type === 'host_kvm' && $related_id > 0) {
        return '查看KVM主机';
    }
    return null;
}

$related_link = get_related_link($notification['related_type'], $notification['related_id']);
$related_name = get_related_name($notification['related_type'], $notification['related_id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知详情 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">🔔 通知详情</h1>
                    <p class="page-subtitle">查看通知的完整内容</p>
                </div>
                <a href="/user/notifications.php" class="btn btn-secondary">← 返回通知列表</a>
            </div>

            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <div style="padding: 24px;">
                    <div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 20px;">
                        <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--bg-light); display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;">
                            <?php echo $type_icon_map[$notification['type']] ?? '🔔'; ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <span class="badge badge-<?php echo $notification['type']; ?>">
                                    <?php echo $type_map[$notification['type']] ?? $notification['type']; ?>
                                </span>
                                <?php if (!$notification['is_read']): ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background: var(--primary); border-radius: 50%;"></span>
                                <span style="font-size: 12px; color: var(--primary);">未读</span>
                                <?php else: ?>
                                <span style="font-size: 12px; color: #52c41a;">✓ 已读</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 14px; color: var(--text-tertiary);">
                                <?php echo format_date($notification['created_at']); ?>
                            </div>
                        </div>
                    </div>

                    <h2 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 16px; line-height: 1.5;">
                        <?php echo e($notification['title']); ?>
                    </h2>

                    <div style="background: var(--bg-page); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                        <div style="font-size: 15px; color: var(--text-primary); line-height: 1.8; white-space: pre-wrap; word-break: break-word;">
                            <?php echo e($notification['content'] ?: '暂无详细内容'); ?>
                        </div>
                    </div>

                    <?php if ($related_link): ?>
                    <div style="background: #f0f7ff; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">📎 相关链接</div>
                        <a href="<?php echo $related_link; ?>" class="btn btn-primary" style="margin-right: 8px;">
                            <?php echo $related_name; ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 12px;">
                        <a href="/user/notifications.php" class="btn btn-secondary">← 返回通知列表</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

<style>
.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}
.badge-system { background: #e6f7ff; color: #1890ff; }
.badge-host { background: #f6ffed; color: #52c41a; }
.badge-order { background: #fff7e6; color: #faad14; }
.badge-security { background: #fff1f0; color: #ff4d4f; }
.badge-promotion { background: #f9f0ff; color: #722ed1; }
.badge-ad_network { background: #fff0f6; color: #eb2f96; }
</style>
</body>
</html>