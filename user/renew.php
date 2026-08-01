<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

$host_id = intval(get('id', 0));
$host = Database::fetch("SELECT h.*, p.name as package_name, p.price_monthly, p.webdx, p.sqldx, p.sizemax, p.ymbds, p.is_kvm, p.kvm_vcpu, p.kvm_memory_mb, p.kvm_disk_gb 
    FROM hosts h 
    LEFT JOIN packages p ON h.package_id = p.id 
    WHERE h.id = ? AND h.user_id = ?", [$host_id, $uid]);

if (!$host) {
    flash('error', '主机不存在');
    header('Location: /user/hosts.php');
    exit;
}

$is_kvm = !empty($host['vm_name']) || !empty($host['is_kvm']);

$packages = Database::fetchAll("SELECT * FROM packages WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
$current_package = null;
foreach ($packages as $pkg) {
    if ($pkg['id'] == $host['package_id']) {
        $current_package = $pkg;
        break;
    }
}

$package_id = intval(post('package_id', $host['package_id']));
$duration = intval(post('duration', get('duration', 1)));
if ($duration < 1) $duration = 1;
if ($duration > 36) $duration = 36;

$selected_package = null;
foreach ($packages as $pkg) {
    if ($pkg['id'] == $package_id) {
        $selected_package = $pkg;
        break;
    }
}
if (!$selected_package && $current_package) {
    $selected_package = $current_package;
    $package_id = $current_package['id'];
}

$discount = 1;
$discount_label = '';
if ($duration >= 12) {
    $discount = 0.85;
    $discount_label = '年付85折';
} elseif ($duration >= 6) {
    $discount = 0.92;
    $discount_label = '半年付92折';
} elseif ($duration >= 3) {
    $discount = 0.96;
    $discount_label = '季付96折';
}

$price_monthly = floatval($selected_package['price_monthly'] ?? 0);
$original_total = $price_monthly * $duration;
$total_amount = round($original_total * $discount, 2);

$order_no = trim(get('order_id', ''));
$existing_order = null;
if ($order_no) {
    $existing_order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ? AND status = 'pending'", [$order_no, $uid]);
    if ($existing_order) {
        $total_amount = floatval($existing_order['amount']);
        $duration = intval($existing_order['duration']);
        $package_id = intval($existing_order['package_id']);
    }
}

if (is_post() && post('action') === 'create_renew_order') {
    // 核验码验证
    license_require_for_service('主机续费');
    
    $dur = intval(post('duration'));
    if ($dur < 1) $dur = 1;
    if ($dur > 36) $dur = 36;
    
    // 计算折扣
    $disc = 1;
    if ($dur >= 36) $disc = 0.80;
    elseif ($dur >= 12) $disc = 0.85;
    elseif ($dur >= 6) $disc = 0.92;
    elseif ($dur >= 3) $disc = 0.96;
    
    if ($is_kvm) {
        // KVM主机：使用动态价格计算
        $kvm_monthly_price = floatval($current_package['price_monthly'] ?? 0);
        if ($kvm_monthly_price <= 0) {
            $kvm_monthly_price = floatval($host['vcpu'] ?? 2) * 50 + (floatval($host['memory_mb'] ?? 2048) / 1024) * 20 + floatval($host['disk_gb'] ?? 40) * 2;
        }
        $amount = round($kvm_monthly_price * $dur * $disc, 2);
        
        $new_order_no = generate_order_no();
        $pkg_info = [
            'renew_host_id' => $host_id,
            'is_renew' => true,
            'is_kvm' => true,
            'kvm_vcpu' => intval($host['vcpu'] ?? 2),
            'kvm_memory_mb' => intval($host['memory_mb'] ?? 2048),
            'kvm_disk_gb' => intval($host['disk_gb'] ?? 40),
            'kvm_monthly_price' => $kvm_monthly_price,
        ];

        Database::insert('orders', [
            'order_no' => $new_order_no,
            'user_id' => $uid,
            'package_id' => $host['package_id'] ?? 0,
            'package_name' => 'KVM云服务器续费',
            'package_info' => json_encode($pkg_info),
            'duration' => $dur,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => '',
            'remark' => '续费主机 ID:' . $host_id . ' | 配置: CPU ' . intval($host['vcpu'] ?? 2) . '核/内存' . intval($host['memory_mb'] ?? 2048) . 'MB/磁盘' . intval($host['disk_gb'] ?? 40) . 'GB',
        ]);
    } else {
        // 普通主机：使用套餐价格
        $pkg_id = intval(post('package_id'));
        $pkg = Database::fetch("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$pkg_id]);
        if (!$pkg) {
            flash('error', '套餐不存在');
            header("Location: /user/renew.php?id=$host_id");
            exit;
        }
        $amount = round($pkg['price_monthly'] * $dur * $disc, 2);

        $new_order_no = generate_order_no();
        $pkg_info = json_decode(json_encode($pkg), true);
        $pkg_info['renew_host_id'] = $host_id;
        $pkg_info['is_renew'] = true;

        Database::insert('orders', [
            'order_no' => $new_order_no,
            'user_id' => $uid,
            'package_id' => $pkg_id,
            'package_name' => $pkg['name'] . ' (续费)',
            'package_info' => json_encode($pkg_info),
            'duration' => $dur,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => '',
            'remark' => '续费主机 ID:' . $host_id,
        ]);
    }

    header("Location: /user/renew.php?id=$host_id&order_id=$new_order_no");
    exit;
}

if (is_post() && get('action') === 'submit_pay') {
    $pay_order_no = trim(post('order_no'));
    $pay_method = post('payment_method', 'balance');
    $order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ? AND status = 'pending'", [$pay_order_no, $uid]);
    if (!$order) {
        flash('error', '订单不存在或已支付');
        header("Location: /user/renew.php?id=$host_id");
        exit;
    }

    if ($pay_method === 'balance') {
        if ($user['balance'] < $order['amount']) {
            flash('error', '余额不足，请充值');
            header("Location: /user/renew.php?id=$host_id&order_id=$pay_order_no");
            exit;
        }
        Database::update('users', ['balance' => $user['balance'] - $order['amount']], 'id = ?', [$uid]);

        Database::update('orders', [
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => 'balance',
        ], 'order_no = ?', [$pay_order_no]);

        $order_record = Database::fetch("SELECT * FROM orders WHERE order_no = ?", [$pay_order_no]);
        $order_amount_for_points = floatval($order_record['amount']);
        $points_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'order_pay' AND enabled = 1");
        if ($points_rule && intval($points_rule['points']) > 0) {
            $earned_points = intval($order_amount_for_points) * intval($points_rule['points']);
            if ($earned_points > 0) {
                change_points($uid, 'earn_order', $earned_points, '订单消费返积分: ' . $pay_order_no, $order_record['id']);
            }
        }

        process_referral_rebate($order_record['id'], $uid, $order_amount_for_points);

        $pkg_info = json_decode($order['package_info'], true);
        $is_renew = !empty($pkg_info['is_renew']);
        $renew_host_id = intval($pkg_info['renew_host_id'] ?? 0);

        if ($is_renew && $renew_host_id > 0) {
            $renew_host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$renew_host_id, $uid]);
            if ($renew_host) {
                $old_expire = $renew_host['expire_at'] ? strtotime($renew_host['expire_at']) : time();
                if ($old_expire < time()) $old_expire = time();
                $new_expire = date('Y-m-d H:i:s', strtotime("+" . intval($order['duration']) . " months", $old_expire));

                $update_data = [
                    'expire_at' => $new_expire,
                    'status' => 'running',
                ];

                if ($renew_host['package_id'] != $order['package_id']) {
                    $update_data['package_id'] = $order['package_id'];
                    $update_data['package_name'] = $order['package_name'];
                    
                    $new_pkg = Database::fetch("SELECT kvm_traffic_gb FROM packages WHERE id = ?", [$order['package_id']]);
                    if ($new_pkg && !empty($new_pkg['kvm_traffic_gb'])) {
                        $update_data['traffic_limit'] = intval($new_pkg['kvm_traffic_gb']) * 1024;
                    }
                }

                Database::update('hosts', $update_data, 'id = ?', [$renew_host_id]);

                if (!$is_kvm) {
                    $api = mnbt_api();
                    $api->renew_host($renew_host['mnbt_username'], date('Y-m-d', strtotime($new_expire)));
                }

                Database::update('orders', ['status' => 'completed'], 'id = ?', [$order['id']]);

                send_notification($uid, 'host', '主机续费成功',
                    '您的主机 ' . ($renew_host['vm_name'] ?? $renew_host['mnbt_username']) . ' 续费成功，新到期时间: ' . $new_expire,
                    'host', $renew_host_id);

                flash('success', '续费成功！新到期时间: ' . $new_expire);
                header('Location: /user/hosts.php');
                exit;
            }
        }

        flash('success', '支付成功！续费已完成');
        header('Location: /user/hosts.php');
        exit;
    }

    if ($pay_method === 'wxpay') {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>正在跳转支付...</title>
            <link rel="stylesheet" href="/assets/css/style.css">
        </head>
        <body>
            <div style="padding:60px 20px; text-align:center; background:#f5f7fa; min-height:100vh;">
                <div style="max-width:520px; margin:0 auto; background:#fff; border:1px solid #e5e6eb; border-radius:12px; padding:48px 32px; box-shadow:0 4px 12px rgba(0,0,0,0.06);">
                    <h2 style="font-size:20px; color:#1d2129; margin-bottom:12px;">正在为您跳转到微信支付...</h2>
                    <p style="color:#86909c; margin-top:12px;">如果未自动跳转，请点击下方按钮。</p>
                    <form id="epayForm" method="POST" action="/SDK/epayapi.php" style="margin-top:32px;">
                        <input type="hidden" name="WIDout_trade_no" value="' . $pay_order_no . '">
                        <input type="hidden" name="WIDsubject" value="' . $order['package_name'] . ' x' . $order['duration'] . '个月">
                        <input type="hidden" name="WIDtotal_fee" value="' . $order['amount'] . '">
                        <input type="hidden" name="type" value="wxpay">
                        <button type="submit" class="btn btn-primary">立即支付 ¥' . number_format($order['amount'], 2) . '</button>
                    </form>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    document.getElementById("epayForm").submit();
                }, 800);
            </script>
        </body>
        </html>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主机续费 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .renew-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .host-info-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e6eb;
            padding: 24px;
            margin-bottom: 20px;
        }
        .host-info-title {
            font-size: 18px;
            font-weight: 600;
            color: #1d2129;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .host-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .host-info-item {
            background: #f7f8fa;
            padding: 12px 16px;
            border-radius: 8px;
        }
        .host-info-label {
            font-size: 12px;
            color: #86909c;
            margin-bottom: 4px;
        }
        .host-info-value {
            font-size: 15px;
            color: #1d2129;
            font-weight: 500;
        }
        .package-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e6eb;
            padding: 24px;
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1d2129;
            margin-bottom: 16px;
        }
        .package-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .package-item {
            border: 2px solid #e5e6eb;
            border-radius: 10px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .package-item:hover {
            border-color: #1677ff;
        }
        .package-item.selected {
            border-color: #1677ff;
            background: #f0f7ff;
        }
        .package-item.selected::after {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            width: 24px;
            height: 24px;
            background: #1677ff;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .package-name {
            font-size: 16px;
            font-weight: 600;
            color: #1d2129;
            margin-bottom: 8px;
        }
        .package-price {
            font-size: 24px;
            font-weight: 700;
            color: #1677ff;
            margin-bottom: 12px;
        }
        .package-price span {
            font-size: 14px;
            font-weight: 400;
            color: #86909c;
        }
        .package-specs {
            font-size: 13px;
            color: #4e5969;
            line-height: 1.8;
        }
        .duration-section {
            margin: 24px 0;
        }
        .duration-options {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .duration-btn {
            padding: 10px 20px;
            border: 2px solid #e5e6eb;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            background: #fff;
            transition: all 0.2s;
        }
        .duration-btn:hover {
            border-color: #1677ff;
            color: #1677ff;
        }
        .duration-btn.active {
            border-color: #1677ff;
            background: #e6f4ff;
            color: #1677ff;
            font-weight: 500;
        }
        .duration-btn .discount {
            font-size: 11px;
            color: #f59e0b;
            margin-left: 6px;
        }
        .payment-section {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e5e6eb;
        }
        .payment-options {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }
        .payment-item {
            flex: 1;
            border: 2px solid #e5e6eb;
            border-radius: 10px;
            padding: 16px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .payment-item:hover {
            border-color: #1677ff;
        }
        .payment-item.selected {
            border-color: #1677ff;
            background: #f0f7ff;
        }
        .payment-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .payment-name {
            font-size: 14px;
            color: #1d2129;
            font-weight: 500;
        }
        .order-summary {
            background: #f7f8fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
            color: #4e5969;
        }
        .summary-row.total {
            font-size: 18px;
            font-weight: 600;
            color: #1d2129;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e6eb;
        }
        .summary-row .price {
            color: #1677ff;
        }
        .summary-row.total .price {
            font-size: 24px;
        }
        .original-price {
            text-decoration: line-through;
            color: #86909c;
            font-size: 14px;
            margin-right: 8px;
        }
        .pay-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #1677ff, #0958d9);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            transition: opacity 0.2s;
        }
        .pay-btn:hover {
            opacity: 0.9;
        }
        .pay-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #1677ff;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .expire-warning {
            background: #fff7e6;
            border: 1px solid #ffd591;
            color: #d46b08;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">主机续费</h1>
                    <p class="page-subtitle">为您的主机延长使用时间</p>
                </div>
            </div>

            <a href="/user/hosts.php" class="back-link">← 返回主机列表</a>

            <?php if ($msg = flash('error')): ?><div class="alert alert-error"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>

            <div class="renew-container">
                <div class="host-info-card">
                    <div class="host-info-title">
                        <?php echo $is_kvm ? '🖥️' : '🌐'; ?>
                        <?php echo e($host['vm_name'] ?: $host['mnbt_username']); ?>
                    </div>
                    <?php if (strtotime($host['expire_at']) < time() + 86400 * 7): ?>
                    <div class="expire-warning">
                        ⚠️ 主机即将到期或已过期，请及时续费
                    </div>
                    <?php endif; ?>
                    <div class="host-info-grid">
                        <?php if ($is_kvm): ?>
                        <div class="host-info-item">
                            <div class="host-info-label">当前配置</div>
                            <div class="host-info-value">CPU <?php echo intval($host['vcpu'] ?? $host['kvm_vcpu'] ?? 2); ?>核 / 内存 <?php echo intval($host['memory_mb'] ?? $host['kvm_memory_mb'] ?? 2048); ?>MB / 磁盘 <?php echo intval($host['disk_gb'] ?? $host['kvm_disk_gb'] ?? 40); ?>GB</div>
                        </div>
                        <?php else: ?>
                        <div class="host-info-item">
                            <div class="host-info-label">当前套餐</div>
                            <div class="host-info-value"><?php echo e($host['package_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="host-info-item">
                            <div class="host-info-label">主机类型</div>
                            <div class="host-info-value"><?php echo $is_kvm ? 'KVM云服务器' : '虚拟主机'; ?></div>
                        </div>
                        <div class="host-info-item">
                            <div class="host-info-label">到期时间</div>
                            <div class="host-info-value"><?php echo e($host['expire_at'] ?: '-'); ?></div>
                        </div>
                        <div class="host-info-item">
                            <div class="host-info-label">当前状态</div>
                            <div class="host-info-value" style="color: <?php echo $host['status'] === 'running' ? '#22c55e' : '#f59e0b'; ?>;">
                                <?php echo $host['status'] === 'running' ? '运行中' : ($host['status'] === 'suspended' ? '已暂停' : '已过期'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$existing_order): ?>
                <form method="POST" id="packageForm">
                    <input type="hidden" name="action" value="create_renew_order">
                    <input type="hidden" name="package_id" id="packageIdInput" value="<?php echo $package_id; ?>">
                    <input type="hidden" name="duration" id="durationInput" value="<?php echo $duration; ?>">

                    <div class="package-card">
                        <?php if ($is_kvm): ?>
                        <div class="section-title">⏰ 选择续费时长</div>
                        <div class="duration-section" style="margin: 0 0 24px 0;">
                            <div class="duration-options">
                                <div class="duration-btn <?php echo $duration == 1 ? 'active' : ''; ?>" onclick="selectDuration(1, event)">1 个月</div>
                                <div class="duration-btn <?php echo $duration == 3 ? 'active' : ''; ?>" onclick="selectDuration(3, event)">3 个月<span class="discount">96折</span></div>
                                <div class="duration-btn <?php echo $duration == 6 ? 'active' : ''; ?>" onclick="selectDuration(6, event)">6 个月<span class="discount">92折</span></div>
                                <div class="duration-btn <?php echo $duration == 12 ? 'active' : ''; ?>" onclick="selectDuration(12, event)">12 个月<span class="discount">85折</span></div>
                                <div class="duration-btn <?php echo $duration == 24 ? 'active' : ''; ?>" onclick="selectDuration(24, event)">24 个月<span class="discount">85折</span></div>
                                <div class="duration-btn <?php echo $duration == 36 ? 'active' : ''; ?>" onclick="selectDuration(36, event)">36 个月<span class="discount">8折</span></div>
                            </div>
                        </div>
                        <?php 
                        // KVM主机使用月单价计算续费费用
                        $kvm_monthly_price = floatval($current_package['price_monthly'] ?? 0);
                        if ($kvm_monthly_price <= 0) {
                            // 如果没有套餐价格，使用默认价格
                            $kvm_monthly_price = floatval($host['vcpu'] ?? 2) * 50 + (floatval($host['memory_mb'] ?? 2048) / 1024) * 20 + floatval($host['disk_gb'] ?? 40) * 2;
                        }
                        ?>
                        <div class="order-summary">
                            <div class="summary-row">
                                <span>当前配置</span>
                                <span>CPU <?php echo intval($host['vcpu'] ?? 2); ?>核 / 内存 <?php echo intval($host['memory_mb'] ?? 2048); ?>MB / 磁盘 <?php echo intval($host['disk_gb'] ?? 40); ?>GB</span>
                            </div>
                            <div class="summary-row">
                                <span>月单价</span>
                                <span>¥<?php echo number_format($kvm_monthly_price, 2); ?> /月</span>
                            </div>
                            <div class="summary-row">
                                <span>购买时长</span>
                                <span id="durationLabel"><?php echo $duration; ?> 个月</span>
                            </div>
                            <?php if ($discount < 1): ?>
                            <div class="summary-row">
                                <span>优惠折扣</span>
                                <span style="color: #f59e0b;"><?php echo $discount_label; ?></span>
                            </div>
                            <div class="summary-row">
                                <span>原价</span>
                                <span class="original-price">¥<?php echo number_format($kvm_monthly_price * $duration, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="summary-row total">
                                <span>应付金额</span>
                                <span class="price" id="totalPriceLabel">¥<?php echo number_format($kvm_monthly_price * $duration * $discount, 2); ?></span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="section-title">📦 选择套餐</div>
                        <div class="package-list">
                            <?php foreach ($packages as $pkg): ?>
                            <div class="package-item <?php echo $pkg['id'] == $package_id ? 'selected' : ''; ?>"
                                 onclick="selectPackage(<?php echo $pkg['id']; ?>, event)">
                                <div class="package-name"><?php echo e($pkg['name']); ?></div>
                                <div class="package-price">
                                    ¥<?php echo number_format($pkg['price_monthly'], 2); ?>
                                    <span>/月</span>
                                </div>
                                <div class="package-specs">
                                    <?php if (!empty($pkg['is_kvm'])): ?>
                                        <div>CPU: <?php echo intval($pkg['kvm_vcpu'] ?: 2); ?> 核</div>
                                        <div>内存: <?php echo intval($pkg['kvm_memory_mb'] ?: 2048); ?> MB</div>
                                        <div>磁盘: <?php echo intval($pkg['kvm_disk_gb'] ?: 40); ?> GB</div>
                                    <?php else: ?>
                                        <div>空间: <?php echo intval($pkg['sizemax'] ?: 50); ?> GB</div>
                                        <div>流量: <?php echo intval($pkg['webdx'] ?: 1000); ?> GB/月</div>
                                        <div>数据库: <?php echo intval($pkg['sqldx'] ?: 50); ?> GB</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="duration-section">
                            <div class="section-title" style="margin-bottom: 12px;">⏰ 续费时长</div>
                            <div class="duration-options">
                                <div class="duration-btn <?php echo $duration == 1 ? 'active' : ''; ?>" onclick="selectDuration(1, event)">1 个月</div>
                                <div class="duration-btn <?php echo $duration == 3 ? 'active' : ''; ?>" onclick="selectDuration(3, event)">3 个月<span class="discount">96折</span></div>
                                <div class="duration-btn <?php echo $duration == 6 ? 'active' : ''; ?>" onclick="selectDuration(6, event)">6 个月<span class="discount">92折</span></div>
                                <div class="duration-btn <?php echo $duration == 12 ? 'active' : ''; ?>" onclick="selectDuration(12, event)">12 个月<span class="discount">85折</span></div>
                                <div class="duration-btn <?php echo $duration == 24 ? 'active' : ''; ?>" onclick="selectDuration(24, event)">24 个月<span class="discount">85折</span></div>
                            </div>
                        </div>

                        <div class="order-summary">
                            <div class="summary-row">
                                <span>套餐单价</span>
                                <span>¥<?php echo number_format($price_monthly, 2); ?> /月</span>
                            </div>
                            <div class="summary-row">
                                <span>购买时长</span>
                                <span><?php echo $duration; ?> 个月</span>
                            </div>
                            <?php if ($discount < 1): ?>
                            <div class="summary-row">
                                <span>优惠折扣</span>
                                <span style="color: #f59e0b;"><?php echo $discount_label; ?></span>
                            </div>
                            <div class="summary-row">
                                <span>原价</span>
                                <span class="original-price">¥<?php echo number_format($original_total, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="summary-row total">
                                <span>应付金额</span>
                                <span class="price">¥<?php echo number_format($total_amount, 2); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="pay-btn">
                            生成续费订单
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="package-card">
                    <div class="section-title">💰 选择支付方式</div>
                    <div style="margin-bottom: 16px; padding: 12px 16px; background: #f0f7ff; border-radius: 8px; font-size: 14px; color: #1677ff;">
                        订单号: <?php echo e($existing_order['order_no']); ?> | 金额: ¥<?php echo number_format($existing_order['amount'], 2); ?>
                    </div>

                    <form method="POST" action="/user/renew.php?id=<?php echo $host_id; ?>&action=submit_pay">
                        <input type="hidden" name="order_no" value="<?php echo e($existing_order['order_no']); ?>">
                        <input type="hidden" name="payment_method" id="paymentMethodInput" value="balance">

                        <div class="payment-options">
                            <div class="payment-item selected" id="payBalance" onclick="selectPayment('balance')">
                                <div class="payment-icon">💳</div>
                                <div class="payment-name">余额支付</div>
                                <div style="font-size: 12px; color: #86909c; margin-top: 4px;">
                                    余额: ¥<?php echo number_format($user['balance'], 2); ?>
                                </div>
                            </div>
                            <div class="payment-item" id="payWxpay" onclick="selectPayment('wxpay')">
                                <div class="payment-icon">💚</div>
                                <div class="payment-name">微信支付</div>
                                <div style="font-size: 12px; color: #86909c; margin-top: 4px;">扫码支付</div>
                            </div>
                        </div>

                        <div class="order-summary">
                            <div class="summary-row">
                                <span>订单金额</span>
                                <span>¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>续费时长</span>
                                <span><?php echo intval($existing_order['duration']); ?> 个月</span>
                            </div>
                            <div class="summary-row total">
                                <span>实付金额</span>
                                <span class="price">¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                            </div>
                        </div>

                        <button type="submit" class="pay-btn" id="payBtn"
                            <?php echo ($user['balance'] < $existing_order['amount']) ? 'disabled' : ''; ?>>
                            立即支付 ¥<?php echo number_format($existing_order['amount'], 2); ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    var isKvm = <?php echo $is_kvm ? 'true' : 'false'; ?>;
    var kvmMonthlyPrice = <?php echo isset($kvm_monthly_price) ? floatval($kvm_monthly_price) : 0; ?>;
    
    function selectPackage(id, evt) {
        var pkgInput = document.getElementById('packageIdInput');
        if (!pkgInput) return;
        pkgInput.value = id;
        document.querySelectorAll('.package-item').forEach(function(el) {
            el.classList.remove('selected');
        });
        if (evt && evt.currentTarget) {
            evt.currentTarget.classList.add('selected');
        }
        updateSummary();
    }

    function selectDuration(months, evt) {
        var durInput = document.getElementById('durationInput');
        if (!durInput) return;
        durInput.value = months;
        document.querySelectorAll('.duration-btn').forEach(function(el) {
            el.classList.remove('active');
        });
        if (evt && evt.currentTarget) {
            evt.currentTarget.classList.add('active');
        }
        updateSummary();
    }

    function selectPayment(method) {
        var methodInput = document.getElementById('paymentMethodInput');
        if (methodInput) methodInput.value = method;
        document.querySelectorAll('.payment-item').forEach(function(el) {
            el.classList.remove('selected');
        });
        var target = document.getElementById('pay' + method.charAt(0).toUpperCase() + method.slice(1));
        if (target) target.classList.add('selected');

        var btn = document.getElementById('payBtn');
        if (!btn) return;
        <?php if ($existing_order): ?>
        var balance = <?php echo floatval($user['balance']); ?>;
        var amount = <?php echo floatval($existing_order['amount']); ?>;
        if (method === 'balance') {
            btn.disabled = balance < amount;
            btn.textContent = '立即支付 ¥<?php echo number_format($existing_order['amount'], 2); ?>';
        } else {
            btn.disabled = false;
            btn.textContent = '前往微信支付 ¥<?php echo number_format($existing_order['amount'], 2); ?>';
        }
        <?php endif; ?>
    }

    function updateSummary() {
        var durInput = document.getElementById('durationInput');
        if (!durInput) return;

        var dur = parseInt(durInput.value) || 1;
        
        // 更新时长显示
        var durationLabel = document.getElementById('durationLabel');
        if (durationLabel) durationLabel.textContent = dur + ' 个月';
        
        if (isKvm) {
            // KVM主机：动态计算价格
            var discount = 1;
            if (dur >= 36) { discount = 0.80; }
            else if (dur >= 12) { discount = 0.85; }
            else if (dur >= 6) { discount = 0.92; }
            else if (dur >= 3) { discount = 0.96; }
            
            var finalPrice = (kvmMonthlyPrice * dur * discount).toFixed(2);
            var totalPriceLabel = document.getElementById('totalPriceLabel');
            if (totalPriceLabel) totalPriceLabel.textContent = '¥' + finalPrice;
        } else {
            // 普通主机：使用套餐价格
            var pkgInput = document.getElementById('packageIdInput');
            if (!pkgInput) return;

            var pkgId = pkgInput.value;
            var packages = <?php echo json_encode($packages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var pkg = null;
            for (var i = 0; i < packages.length; i++) {
                if (packages[i].id == pkgId) {
                    pkg = packages[i];
                    break;
                }
            }
            if (!pkg) return;

            var price = parseFloat(pkg.price_monthly);
            var total = price * dur;
            var discount = 1;
            if (dur >= 12) { discount = 0.85; }
            else if (dur >= 6) { discount = 0.92; }
            else if (dur >= 3) { discount = 0.96; }

            var finalPrice = (total * discount).toFixed(2);
            var totalPriceEl = document.querySelector('.summary-row.total .price');
            if (totalPriceEl) totalPriceEl.textContent = '¥' + finalPrice;
        }
    }
    </script>
</body>
</html>
