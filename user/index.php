<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$user = auth_user();
$uid = auth_id();

migrate_new_tables();

$user_points = get_user_points($uid);

$total_hosts = intval(Database::fetch("SELECT COUNT(*) as c FROM hosts WHERE user_id = ?", [$uid])['c']);
$active_hosts = intval(Database::fetch("SELECT COUNT(*) as c FROM hosts WHERE user_id = ? AND status = 'running'", [$uid])['c']);
$total_orders = intval(Database::fetch("SELECT COUNT(*) as c FROM orders WHERE user_id = ?", [$uid])['c']);
$completed_orders = intval(Database::fetch("SELECT COUNT(*) as c FROM orders WHERE user_id = ? AND status = 'completed'", [$uid])['c']);
$total_consumption = floatval(Database::fetch("SELECT COALESCE(SUM(amount), 0) as t FROM orders WHERE user_id = ? AND status IN ('paid','processing','completed')", [$uid])['t']);

$recent_hosts = Database::fetchAll("SELECT h.*, p.name as package_name FROM hosts h LEFT JOIN packages p ON h.package_id = p.id WHERE h.user_id = ? ORDER BY h.created_at DESC LIMIT 5", [$uid]);

$recent_orders = Database::fetchAll("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5", [$uid]);

$ads_top = get_ads_by_position('user_dashboard_top');
$ads_bottom = get_ads_by_position('user_dashboard_bottom');

if (is_post() && post('action') === 'ad_click' && post('ad_id')) {
    record_ad_click(intval(post('ad_id')));
    exit;
}

$vip_expire = $user['vip_expire_at'] ?? null;
$member_level = $user['member_level'] ?? '普通会员';
$site_theme = db_get_setting('site_theme', 'business');

// 用户配额信息
ensure_user_quota($uid);
$quota = Database::fetch("SELECT * FROM user_quotas WHERE user_id = ?", [$uid]);
$has_any_quota = false;
if ($quota) {
    foreach (['max_vms', 'max_cpu', 'max_memory_mb', 'max_disk_gb', 'max_ip_count', 'max_snapshots'] as $k) {
        if (intval($quota[$k] ?? -1) >= 0) {
            $has_any_quota = true;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body data-theme="<?php echo $site_theme; ?>">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <?php
            $error = flash('error');
            $success = flash('success');
            if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="dashboard-welcome">
                <div class="welcome-content">
                    <div class="welcome-text">
                        <h1>欢迎回来，<?php echo e($user['username']); ?></h1>
                        <p>今天也要元气满满地管理您的云资源！</p>
                    </div>
                    <div class="welcome-meta">
                        <span class="meta-tag"><?php echo e($member_level); ?></span>
                        <span>注册时间：<?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span>
                        <?php if ($vip_expire && $vip_expire !== '0000-00-00 00:00:00'): ?>
                        <span>VIP到期：<?php echo date('Y-m-d', strtotime($vip_expire)); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="welcome-actions">
                    <a href="/checkout.php" class="btn btn-primary">快速购买</a>
                    <a href="/user/hosts.php" class="btn btn-secondary">管理主机</a>
                </div>
            </div>

            <?php if (!empty($ads_top)): ?>
            <?php foreach ($ads_top as $ad): ?>
            <div class="ad-banner">
                <a href="javascript:void(0);" onclick="recordAdClick(<?php echo $ad['id']; ?>, '<?php echo e($ad['link_url']); ?>')">
                    <img src="<?php echo e($ad['image_url']); ?>" alt="<?php echo e($ad['title']); ?>">
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="stat-cards-row">
                <div class="stat-card-dashboard">
                    <div class="stat-card-icon" style="background: rgba(22,119,255,0.1); color: #1677ff;">💰</div>
                    <div class="stat-card-body">
                        <div class="stat-card-label">账户余额</div>
                        <div class="stat-card-value">¥<?php echo number_format($user['balance'], 2); ?></div>
                        <div class="stat-card-hint">可用的账户余额</div>
                    </div>
                </div>
                <div class="stat-card-dashboard">
                    <div class="stat-card-icon" style="background: rgba(255,125,0,0.1); color: #ff7d00;">🎯</div>
                    <div class="stat-card-body">
                        <div class="stat-card-label">我的积分</div>
                        <div class="stat-card-value"><?php echo number_format($user_points); ?></div>
                        <div class="stat-card-hint">可兑换各种福利</div>
                    </div>
                </div>
                <div class="stat-card-dashboard">
                    <div class="stat-card-icon" style="background: rgba(0,180,42,0.1); color: #00b42a;">🖥️</div>
                    <div class="stat-card-body">
                        <div class="stat-card-label">我的主机</div>
                        <div class="stat-card-value"><?php echo $active_hosts; ?><span class="stat-card-divider">/</span><?php echo $total_hosts; ?></div>
                        <div class="stat-card-hint">运行中 / 总计</div>
                    </div>
                </div>
                <div class="stat-card-dashboard">
                    <div class="stat-card-icon" style="background: rgba(114,46,209,0.1); color: #722ed1;">📊</div>
                    <div class="stat-card-body">
                        <div class="stat-card-label">累计消费</div>
                        <div class="stat-card-value">¥<?php echo number_format($total_consumption, 2); ?></div>
                        <div class="stat-card-hint">已完成订单总额</div>
                    </div>
                </div>
            </div>

            <div class="quick-actions-grid">
                <a href="/checkout.php" class="quick-action-item">
                    <div class="quick-action-icon">🛒</div>
                    <div class="quick-action-title">购买主机</div>
                    <div class="quick-action-desc">选择心仪套餐</div>
                </a>
                <a href="/user/hosts.php" class="quick-action-item">
                    <div class="quick-action-icon">🖥️</div>
                    <div class="quick-action-title">我的主机</div>
                    <div class="quick-action-desc">管理已购主机</div>
                </a>
                <a href="/user/orders.php" class="quick-action-item">
                    <div class="quick-action-icon">📋</div>
                    <div class="quick-action-title">我的订单</div>
                    <div class="quick-action-desc">查看订单记录</div>
                </a>
                <a href="/user/recharge.php" class="quick-action-item">
                    <div class="quick-action-icon">💳</div>
                    <div class="quick-action-title">余额充值</div>
                    <div class="quick-action-desc">账户余额管理</div>
                </a>
                <a href="/user/profile.php" class="quick-action-item">
                    <div class="quick-action-icon">⚙️</div>
                    <div class="quick-action-title">账户设置</div>
                    <div class="quick-action-desc">个人信息管理</div>
                </a>
            </div>

            <?php if ($has_any_quota && $quota):
                $quota_items = [
                    ['key' => 'vms', 'label' => '虚拟机', 'icon' => '🖥️', 'unit' => '台'],
                    ['key' => 'cpu', 'label' => 'CPU', 'icon' => '⚙️', 'unit' => '核'],
                    ['key' => 'memory_mb', 'label' => '内存', 'icon' => '💾', 'unit' => 'MB'],
                    ['key' => 'disk_gb', 'label' => '磁盘', 'icon' => '💿', 'unit' => 'GB'],
                    ['key' => 'ip_count', 'label' => '公网IP', 'icon' => '🌐', 'unit' => '个'],
                    ['key' => 'snapshots', 'label' => '快照', 'icon' => '📸', 'unit' => '个'],
                ];
                $visible_items = [];
                foreach ($quota_items as $item) {
                    $max_key = 'max_' . $item['key'];
                    $used_key = 'used_' . $item['key'];
                    $max_val = intval($quota[$max_key] ?? -1);
                    if ($max_val >= 0) {
                        $used_val = intval($quota[$used_key] ?? 0);
                        $pct = $max_val > 0 ? min(100, ($used_val / $max_val) * 100) : 0;
                        $status = $pct >= 90 ? 'high' : ($pct >= 70 ? 'medium' : 'low');
                        $visible_items[] = [
                            'label' => $item['label'],
                            'icon' => $item['icon'],
                            'unit' => $item['unit'],
                            'used' => $used_val,
                            'max' => $max_val,
                            'pct' => $pct,
                            'status' => $status,
                        ];
                    }
                }
                if (!empty($visible_items)):
            ?>
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h3>资源配额</h3>
                    <span style="font-size: 12px; color: #86909c;">合理使用资源，超出配额将无法创建新资源</span>
                </div>
                <div class="quota-grid">
                    <?php foreach ($visible_items as $qi): ?>
                    <div class="quota-item-card">
                        <div class="quota-item-header">
                            <span class="quota-item-icon"><?php echo $qi['icon']; ?></span>
                            <span class="quota-item-label"><?php echo $qi['label']; ?></span>
                        </div>
                        <div class="quota-item-values">
                            <span class="quota-item-used"><?php echo $qi['used']; ?></span>
                            <span class="quota-item-divider">/</span>
                            <span class="quota-item-max"><?php echo $qi['max']; ?> <?php echo $qi['unit']; ?></span>
                        </div>
                        <div class="quota-item-bar">
                            <div class="quota-item-fill quota-status-<?php echo $qi['status']; ?>" style="width: <?php echo $qi['pct']; ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; endif; ?>

            <div class="dashboard-row">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <h3>最近的主机</h3>
                        <a href="/user/hosts.php">查看全部 →</a>
                    </div>
                    <?php if (empty($recent_hosts)): ?>
                        <div class="dashboard-card-empty">
                            <div class="empty-icon">📦</div>
                            <p>您还没有购买任何主机</p>
                            <a href="/checkout.php" class="btn btn-primary">立即购买</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_hosts as $host): ?>
                        <div class="host-mini-card">
                            <div class="host-mini-header">
                                <div>
                                    <h4><?php echo e($host['mnbt_username'] ?? '主机#' . $host['id']); ?></h4>
                                    <span class="host-mini-package"><?php echo e($host['package_name']); ?></span>
                                </div>
                                <?php echo get_status_label($host['status'], 'host'); ?>
                            </div>
                            <div class="host-mini-footer">
                                <span>到期：<?php echo format_date($host['expire_at']); ?></span>
                                <a href="/user/host_detail.php?id=<?php echo $host['uuid'] ?? $host['id']; ?>" class="btn btn-sm btn-secondary">管理</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <h3>最近的订单</h3>
                        <a href="/user/orders.php">查看全部 →</a>
                    </div>
                    <?php if (empty($recent_orders)): ?>
                        <div class="dashboard-card-empty">
                            <div class="empty-icon">📋</div>
                            <p>暂无订单记录</p>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>订单号</th>
                                        <th>金额</th>
                                        <th>状态</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><?php echo e($order['order_no']); ?></td>
                                        <td class="order-amount">¥<?php echo number_format($order['amount'], 2); ?></td>
                                        <td><?php echo get_status_label($order['status'], 'order'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($ads_bottom)): ?>
            <?php foreach ($ads_bottom as $ad): ?>
            <div class="ad-banner">
                <a href="javascript:void(0);" onclick="recordAdClick(<?php echo $ad['id']; ?>, '<?php echo e($ad['link_url']); ?>')">
                    <img src="<?php echo e($ad['image_url']); ?>" alt="<?php echo e($ad['title']); ?>">
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="dashboard-footer">
                <div class="footer-left">
                    <span>技术支持：请联系管理员</span>
                </div>
                <div class="footer-right">
                    © <?php echo date('Y'); ?> <?php echo e(config('app.name')); ?> 版权所有
                </div>
            </div>
        </div>
    </div>

    <style>
    .dashboard-welcome {
        background: linear-gradient(135deg, #1677ff 0%, #4096ff 100%);
        border-radius: 16px;
        padding: 28px 32px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        box-shadow: 0 8px 32px rgba(22,119,255,0.25);
    }
    .welcome-content {
        flex: 1;
    }
    .welcome-text h1 {
        font-size: 24px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 8px;
    }
    .welcome-text p {
        font-size: 14px;
        color: rgba(255,255,255,0.85);
        margin: 0;
    }
    .welcome-meta {
        display: flex;
        gap: 16px;
        margin-top: 12px;
        flex-wrap: wrap;
    }
    .meta-tag {
        background: rgba(255,255,255,0.25);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        color: #fff;
        font-weight: 500;
    }
    .welcome-meta span:not(.meta-tag) {
        font-size: 13px;
        color: rgba(255,255,255,0.75);
    }
    .welcome-actions {
        display: flex;
        gap: 12px;
    }
    .welcome-actions .btn-primary {
        background: rgba(255,255,255,0.2);
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff;
        padding: 10px 20px;
    }
    .welcome-actions .btn-primary:hover {
        background: rgba(255,255,255,0.3);
        color: #fff;
    }
    .welcome-actions .btn-secondary {
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.3);
        color: #fff;
        padding: 10px 20px;
        backdrop-filter: blur(8px);
    }
    .welcome-actions .btn-secondary:hover {
        background: rgba(255,255,255,0.2);
        color: #fff;
    }

    .ad-banner {
        margin-bottom: 20px;
        border-radius: 12px;
        overflow: hidden;
        line-height: 0;
    }
    .ad-banner img {
        width: 100%;
        height: auto;
        max-height: 90px;
        object-fit: cover;
    }

    .stat-cards-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card-dashboard {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .stat-card-dashboard::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-gradient);
        transition: height 0.3s ease;
        height: 0;
    }
    .stat-card-dashboard:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
        border-color: var(--primary-light);
    }
    .stat-card-dashboard:hover::before {
        height: 100%;
    }
    .stat-card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }
    .stat-card-body {
        flex: 1;
    }
    .stat-card-label {
        font-size: 13px;
        color: var(--text-secondary);
        font-weight: 500;
        margin-bottom: 8px;
    }
    .stat-card-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--text-primary);
        font-family: "SF Mono", Monaco, Menlo, monospace;
        margin-bottom: 4px;
    }
    .stat-card-divider {
        font-size: 16px;
        color: var(--text-secondary);
        font-weight: 400;
        margin: 0 4px;
    }
    .stat-card-hint {
        font-size: 12px;
        color: var(--text-secondary);
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 14px;
        margin-bottom: 24px;
    }
    .quick-action-item {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 20px 14px;
        text-align: center;
        text-decoration: none;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        display: block;
    }
    .quick-action-item:hover {
        border-color: var(--primary);
        box-shadow: 0 8px 24px rgba(22,119,255,0.12);
        transform: translateY(-3px);
    }
    .quick-action-icon {
        font-size: 28px;
        margin-bottom: 8px;
    }
    .quick-action-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    .quick-action-desc {
        font-size: 12px;
        color: var(--text-secondary);
    }

    .dashboard-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }
    .dashboard-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 24px;
    }
    .dashboard-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-light);
    }
    .dashboard-card-header h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }
    .dashboard-card-header a {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-secondary);
        text-decoration: none;
    }
    .dashboard-card-header a:hover {
        color: var(--primary);
    }
    .dashboard-card-empty {
        text-align: center;
        padding: 40px 20px;
        color: var(--text-secondary);
    }
    .empty-icon {
        font-size: 40px;
        margin-bottom: 12px;
        opacity: 0.6;
    }
    .dashboard-card-empty p {
        font-size: 14px;
        margin: 0 0 16px;
    }

    .host-mini-card {
        background: var(--bg-page);
        border: 1px solid var(--border-light);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 12px;
    }
    .host-mini-card:last-child {
        margin-bottom: 0;
    }
    .host-mini-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
    }
    .host-mini-header h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0 0 4px;
    }
    .host-mini-package {
        font-size: 12px;
        color: var(--primary);
        font-weight: 500;
    }
    .host-mini-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .host-mini-footer span {
        font-size: 12px;
        color: var(--text-secondary);
    }

    .dashboard-table {
        overflow-x: auto;
    }
    .dashboard-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .dashboard-table th {
        padding: 10px 12px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border-light);
    }
    .dashboard-table td {
        padding: 12px;
        font-size: 13px;
        color: var(--text-primary);
        border-bottom: 1px solid var(--border-light);
    }
    .dashboard-table tbody tr:last-child td {
        border-bottom: none;
    }
    .dashboard-table tbody tr:hover {
        background: var(--primary-lighter);
    }
    .order-amount {
        color: var(--primary);
        font-weight: 600;
        font-family: "SF Mono", Monaco, Menlo, monospace;
    }

    .dashboard-footer {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 16px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    .footer-left {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }
    .footer-left span {
        font-size: 14px;
        color: var(--text-secondary);
    }
    .footer-left a {
        font-size: 14px;
        color: var(--primary);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .footer-right {
        font-size: 12px;
        color: var(--text-placeholder);
    }

    .quota-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }
    .quota-item-card {
        background: var(--bg-light);
        border-radius: 10px;
        padding: 16px;
        border: 1px solid var(--border-light);
    }
    .quota-item-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .quota-item-icon {
        font-size: 18px;
    }
    .quota-item-label {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-secondary);
    }
    .quota-item-values {
        display: flex;
        align-items: baseline;
        gap: 4px;
        margin-bottom: 10px;
    }
    .quota-item-used {
        font-size: 22px;
        font-weight: 700;
        color: var(--text-primary);
        font-family: "SF Mono", Monaco, Menlo, monospace;
    }
    .quota-item-divider {
        color: var(--text-placeholder);
        font-size: 14px;
    }
    .quota-item-max {
        font-size: 13px;
        color: var(--text-secondary);
    }
    .quota-item-bar {
        height: 6px;
        background: var(--border-light);
        border-radius: 3px;
        overflow: hidden;
    }
    .quota-item-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    .quota-status-low { background: #00b42a; }
    .quota-status-medium { background: #ff7d00; }
    .quota-status-high { background: #f53f3f; }

    @media (max-width: 1024px) {
        .stat-cards-row {
            grid-template-columns: repeat(2, 1fr);
        }
        .quick-actions-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .dashboard-row {
            grid-template-columns: 1fr;
        }
        .quota-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 768px) {
        .stat-cards-row {
            grid-template-columns: 1fr;
        }
        .quick-actions-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .quota-grid {
            grid-template-columns: 1fr;
        }
        .dashboard-welcome {
            flex-direction: column;
            align-items: flex-start;
        }
        .welcome-actions {
            width: 100%;
            justify-content: stretch;
        }
        .welcome-actions .btn {
            flex: 1;
            text-align: center;
        }
    }
    </style>

    <script>
    function recordAdClick(adId, linkUrl) {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ad_click&ad_id=' + adId
        }).then(function() {
            if (linkUrl) {
                window.open(linkUrl, '_blank');
            }
        });
    }
    </script>
</body>
</html>