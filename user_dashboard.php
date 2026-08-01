<?php
define('PUBLIC_URL', '');

$_xc_config = __DIR__ . '/宣传/config.php';
if (file_exists($_xc_config)) {
    require_once $_xc_config;
} else {
    require_once __DIR__ . '/hym_license/config.php';
}

require_user_auth();

$user = current_user();
if (!is_array($user) || empty($user['id'])) {
    header('Location: /hym_license/user_login.php');
    exit;
}
$orders = get_user_orders($user['id']);

$status_map = [
    'pending' => '待支付',
    'paid' => '已支付',
    'failed' => '支付失败',
];

$type_map = [
    'trial' => '试用版',
    'standard' => '标准版',
    'enterprise' => '企业版',
];

$has_paid_upgrade = false;
foreach ($orders as $order) {
    if ($order['status'] === 'paid' && in_array($order['type'], ['standard', 'enterprise'])) {
        $has_paid_upgrade = true;
        break;
    }
}

$success_order = null;
if (!empty($_GET['success'])) {
    $success_order = get_buy_order($_GET['success']);
} elseif (!empty($_GET['order'])) {
    $ord = get_buy_order($_GET['order']);
    if ($ord && $ord['status'] === 'paid') {
        $success_order = $ord;
    }
}

$pub = defined('PUBLIC_URL') ? PUBLIC_URL : '';
if ($pub !== '' && substr($pub, -1) !== '/') $pub .= '/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户中心 - <?php echo e(SITE_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .navbar .brand { font-size: 18px; font-weight: 600; }
        .navbar .user-info { font-size: 14px; }
        .navbar .user-info a { color: #fff; text-decoration: none; margin-left: 16px; opacity: 0.9; }
        .navbar .user-info a:hover { opacity: 1; text-decoration: underline; }
        .container { max-width: 960px; margin: 0 auto; padding: 30px 20px; }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 24px;
            margin-bottom: 24px;
        }
        .card h2 { font-size: 18px; color: #333; margin-bottom: 16px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #fafafa; font-weight: 600; color: #666; font-size: 13px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-pending { background: #fff7e6; color: #fa8c16; }
        .badge-paid { background: #f6ffed; color: #52c41a; }
        .badge-failed { background: #fff1f0; color: #ff4d4f; }
        .badge-trial { background: #e6f7ff; color: #1890ff; }
        .badge-standard { background: #f6ffed; color: #52c41a; }
        .badge-enterprise { background: #fff7e6; color: #fa8c16; }
        .success-banner {
            background: linear-gradient(135deg, #52c41a 0%, #73d13d 100%);
            color: #fff;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
        }
        .success-banner h2 { margin-bottom: 8px; font-size: 22px; }
        .success-banner p { opacity: 0.9; font-size: 14px; }
        .upgrade-section {
            background: linear-gradient(135deg, #f0f5ff 0%, #f9f0ff 100%);
            border: 1px solid #d6e4ff;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            margin-bottom: 24px;
        }
        .upgrade-section p { color: #666; margin-bottom: 16px; font-size: 14px; }
        .upgrade-btn {
            display: inline-block;
            padding: 12px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .upgrade-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3); }
        .license-code {
            font-family: monospace;
            background: #f5f5f5;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        .activation-box {
            margin-top: 12px;
            padding: 12px;
            background: #fafafa;
            border-radius: 6px;
            font-size: 13px;
            color: #666;
        }
        .activation-box h4 { margin-bottom: 8px; color: #333; font-size: 13px; }
        .activation-item { padding: 4px 0; font-size: 12px; }
        .activation-empty { color: #999; font-size: 12px; }
        .upgrade-code-btn {
            display: inline-block;
            padding: 6px 16px;
            background: #1677ff;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
        }
        .upgrade-code-btn:hover { background: #0958d9; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="brand"><?php echo e(SITE_NAME); ?> - 用户中心</div>
        <div style="display:flex;gap:20px;">
            <a href="/user_dashboard.php" style="color:rgba(255,255,255,0.9);text-decoration:none;font-size:14px;">我的订单</a>
            <a href="/hym_license/downloads.php" style="color:rgba(255,255,255,0.9);text-decoration:none;font-size:14px;">资源下载</a>
        </div>
        <div class="user-info">
            <?php echo e($user['email']); ?>
            <a href="/hym_license/user_logout.php">退出</a>
        </div>
    </div>

    <div class="container">
        <?php if ($success_order): ?>
        <div class="success-banner">
            <h2>支付成功！</h2>
            <p>订单号: <?php echo e($success_order['order_no']); ?> | 套餐: <?php echo e($type_map[$success_order['type']] ?? $success_order['type']); ?></p>
            <?php if (!empty($success_order['license_code'])): ?>
            <p style="margin-top:8px;">核验码: <span style="font-family:monospace;background:rgba(255,255,255,0.2);padding:2px 8px;border-radius:4px;"><?php echo e($success_order['license_code']); ?></span></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$has_paid_upgrade): ?>
            <div class="upgrade-section">
                <p>当前为试用版或暂无付费套餐，升级后可获得更多权益</p>
                <a href="/user_upgrade.php" class="upgrade-btn">升级套餐</a>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>我的订单</h2>
            <table>
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>类型</th>
                        <th>金额</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>支付时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?php echo e($order['order_no']); ?></td>
                        <td><span class="badge badge-<?php echo e($order['type']); ?>"><?php echo e($type_map[$order['type']] ?? $order['type']); ?></span></td>
                        <td>¥<?php echo e($order['amount']); ?></td>
                        <td><span class="badge badge-<?php echo e($order['status']); ?>"><?php echo e($status_map[$order['status']] ?? $order['status']); ?></span></td>
                        <td><?php echo e($order['created_at']); ?></td>
                        <td><?php echo e($order['pay_time'] ?? '-'); ?></td>
                    </tr>
                    <?php if ($order['status'] === 'paid' && !empty($order['license_code'])): ?>
                    <tr>
                        <td colspan="6">
                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                <div>
                                    <strong>核验码:</strong>
                                    <span class="license-code"><?php echo e($order['license_code']); ?></span>
                                </div>
                                <?php if (in_array($order['type'], ['trial', 'standard'])): ?>
                                <a href="/user_upgrade.php?code=<?php echo urlencode($order['license_code']); ?>&from=<?php echo e($order['type']); ?>" class="upgrade-code-btn">
                                    <?php echo $order['type'] === 'trial' ? '升级到标准版/企业版' : '升级到企业版'; ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="activation-box">
                                <h4>激活状态</h4>
                                <?php
                                $activations = get_activation_records($order['license_code']);
                                if (empty($activations)):
                                ?>
                                    <div class="activation-empty">该核验码尚未激活任何设备</div>
                                <?php else: ?>
                                    <?php foreach ($activations as $act): ?>
                                        <div class="activation-item">
                                            设备ID: <?php echo e($act['machine_id'] ?: '未知'); ?> |
                                            域名: <?php echo e($act['domain'] ?: '-'); ?> |
                                            IP: <?php echo e($act['ip_address'] ?: '-'); ?> |
                                            状态: <?php echo e($act['status'] === 'active' ? '激活中' : '已撤销'); ?> |
                                            激活时间: <?php echo e($act['activated_at']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#999;padding:40px;">暂无订单</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
