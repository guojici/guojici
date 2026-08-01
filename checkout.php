<?php
require_once __DIR__ . '/config/helper.php';
require_auth();

// 确保新系统表存在
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

$package_id = intval(get('package_id', 0));
$order_no = trim(get('order_id', ''));

// 如果是续付订单
$existing_order = null;
if ($order_no) {
    $existing_order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [$order_no, $uid]);
    if ($existing_order) {
        $package_id = $existing_order['package_id'];
    }
}

// 获取分类列表
$categories = Database::fetchAll("SELECT * FROM package_categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");

// 分类筛选
$category_id = intval(get('category_id', 0));
$cat_sql = $category_id > 0 ? " AND category_id = ?" : "";
$cat_params = $category_id > 0 ? [$category_id] : [];

$packages = Database::fetchAll("SELECT * FROM packages WHERE status = 'active'" . $cat_sql . " ORDER BY sort_order ASC, id ASC", $cat_params);
$current_package = null;
foreach ($packages as $pkg) {
    if ($pkg['id'] == $package_id) {
        $current_package = $pkg;
        break;
    }
}
if (!$current_package && !empty($packages)) {
    $current_package = $packages[0];
    $package_id = $current_package['id'];
}

// 获取系统镜像列表
$kvm_enabled = kvm_is_enabled();
$vm_images = [];
$ip_pools = [];
$is_kvm_pkg = false;
$is_nat_kvm_pkg = false;
if ($kvm_enabled && $current_package) {
    $is_kvm_pkg = !empty($current_package['is_kvm']);
    $is_nat_kvm_pkg = !empty($current_package['is_nat_kvm']);
    // 标准版和试用版禁止购买NAT机型
    if ($is_nat_kvm_pkg && !license_feature('nat_kvm')) {
        flash('error', '当前授权版本不支持NAT机型，请升级到企业版');
        redirect('/checkout.php');
    }
    if ($is_kvm_pkg) {
        $vm_images = kvm_get_images(true);
        $pool_type = $is_nat_kvm_pkg ? 'nat' : 'dedicated';
        $ip_pools = Database::fetchAll("SELECT * FROM ip_pools WHERE status = 'active' AND pool_type = ? ORDER BY id ASC", [$pool_type]);
    }
}
$default_image_id = 0;
$default_pool_id = 0;
if (!empty($vm_images)) $default_image_id = intval($vm_images[0]['id']);
if (!empty($ip_pools)) $default_pool_id = intval($ip_pools[0]['id']);

// 获取KVM套餐规格
$pkg_kvm_specs = [];
if ($current_package && $is_kvm_pkg) {
    $pkg_kvm_specs = [
        'vcpu' => intval($current_package['kvm_vcpu'] ?: 2),
        'memory_mb' => intval($current_package['kvm_memory_mb'] ?: 2048),
        'disk_gb' => intval($current_package['kvm_disk_gb'] ?: 40),
        'bandwidth_mbps' => intval($current_package['kvm_bandwidth_mbps'] ?: 100),
    ];
}

$duration = intval(post('duration', get('duration', 1)));
if ($duration < 1) $duration = 1;
if ($duration > 36) $duration = 36;

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

$original_total = $current_package ? $current_package['price_monthly'] * $duration : 0;
$total_amount = $original_total * $discount;
$total_amount = round($total_amount, 2);

// ====== 加载当前用户可用的优惠券（用于下单页选择）======
// 优惠券绑定到用户账户，仅本用户的未使用、未过期、且满足当前订单最低消费门槛的券才显示
$user_coupons = $current_package ? get_user_available_coupons($uid, $total_amount) : [];

// ================ 处理下单 ================
if (is_post() && post('action') === 'create_order') {
    // 核验码验证
    license_require_for_service('购买主机');
    
    $pkg_id = intval(post('package_id'));
    $dur = intval(post('duration'));
    $image_id = intval(post('image_id', $default_image_id));
    $pool_id = intval(post('pool_id', $default_pool_id));
    $root_password = trim(post('root_password', ''));
    
    $pkg = Database::fetch("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$pkg_id]);
    if (!$pkg) {
        flash('error', '套餐不存在');
        header('Location: /checkout.php');
        exit;
    }
    
    // KVM套餐验证root密码
    if (!empty($pkg['is_kvm'])) {
        if (empty($root_password)) {
            flash('error', '请设置root密码');
            header('Location: /checkout.php?package_id=' . $pkg_id . '&duration=' . $dur);
            exit;
        }
        if (strlen($root_password) < 6) {
            flash('error', 'root密码长度不能少于6位');
            header('Location: /checkout.php?package_id=' . $pkg_id . '&duration=' . $dur);
            exit;
        }
        // 验证密码复杂度
        if (!preg_match('/^[a-zA-Z0-9_!@#$%^&*()+-=]+$/', $root_password)) {
            flash('error', 'root密码只能包含字母、数字和常见符号');
            header('Location: /checkout.php?package_id=' . $pkg_id . '&duration=' . $dur);
            exit;
        }
    }
    
    // 协议确认验证
    if (!post('agree_terms')) {
        flash('error', '请阅读并同意服务协议和隐私政策');
        header('Location: /checkout.php?package_id=' . $pkg_id . '&duration=' . $dur);
        exit;
    }
    
    // 如果没有镜像，则image_id保持0（虚拟主机套餐）
    $disc = 1;
    if ($dur >= 12) $disc = 0.85;
    elseif ($dur >= 6) $disc = 0.92;
    elseif ($dur >= 3) $disc = 0.96;
    $original_amount = round($pkg['price_monthly'] * $dur * $disc, 2);
    $new_order_no = generate_order_no();

    $pkg_info = json_decode(json_encode($pkg), true);
    $pkg_info['image_id'] = $image_id;
    $pkg_info['pool_id'] = $pool_id;
    $pkg_info['root_password'] = $root_password; // 保存用户设置的密码
    // KVM套餐时写入规格
    if (!empty($pkg['is_kvm'])) {
        $pkg_info['kvm_vcpu'] = intval($pkg['kvm_vcpu'] ?: 2);
        $pkg_info['kvm_memory_mb'] = intval($pkg['kvm_memory_mb'] ?: 2048);
        $pkg_info['kvm_disk_gb'] = intval($pkg['kvm_disk_gb'] ?: 40);
        $pkg_info['kvm_bandwidth_mbps'] = intval($pkg['kvm_bandwidth_mbps'] ?: 100);
    }

    // ====== 优惠券处理（用户可选）======
    $user_coupon_id = intval(post('user_coupon_id', 0));
    $coupon_code = '';
    $coupon_id = 0;
    $discount_amount = 0.00;
    $final_amount = $original_amount;

    if ($user_coupon_id > 0) {
        // 验证该优惠券确实属于当前用户且可用
        $available_coupons = get_user_available_coupons($uid, $original_amount);
        $selected_coupon = null;
        foreach ($available_coupons as $ac) {
            if (intval($ac['user_coupon_id']) === $user_coupon_id) {
                $selected_coupon = $ac;
                break;
            }
        }
        if ($selected_coupon) {
            $discount_calc = calculate_coupon_discount($selected_coupon, $original_amount);
            $discount_amount = $discount_calc['discount'];
            $final_amount = $discount_calc['final'];
            $coupon_code = $selected_coupon['coupon_code'];
            $coupon_id = intval($selected_coupon['coupon_id']);
        } else {
            flash('error', '优惠券不可用或不符合使用条件');
            header('Location: /checkout.php?package_id=' . $pkg_id . '&duration=' . $dur);
            exit;
        }
    }
    $amount = $final_amount;

    $oid = Database::insert('orders', [
        'order_no' => $new_order_no,
        'user_id' => $uid,
        'package_id' => $pkg_id,
        'package_name' => $pkg['name'],
        'package_info' => json_encode($pkg_info),
        'duration' => $dur,
        'amount' => $amount,
        'coupon_id' => $coupon_id,
        'coupon_code' => $coupon_code,
        'original_amount' => $original_amount,
        'discount_amount' => $discount_amount,
        'status' => 'pending',
        'payment_method' => '',
        'remark' => 'image_id:' . $image_id . ',pool_id:' . $pool_id,
    ]);

    // 若选用了优惠券，立即标记为已使用并绑定到该订单（订单后续若取消会通过 release_coupon_for_order 释放）
    if ($user_coupon_id > 0 && $coupon_id > 0 && $oid) {
        mark_coupon_used($user_coupon_id, $oid);
    }

    // 余额充足时直接用余额支付并跳转到创建页面
    if ($user['balance'] >= $amount) {
        Database::update('users', ['balance' => $user['balance'] - $amount], 'id = ?', [$uid]);
        Database::update('orders', [
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => 'balance',
        ], 'order_no = ?', [$new_order_no]);
        
        // 积分返现
        $points_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'order_pay' AND enabled = 1");
        if ($points_rule && intval($points_rule['points']) > 0) {
            $earned_points = intval($amount) * intval($points_rule['points']);
            if ($earned_points > 0) {
                change_points($uid, 'earn_order', $earned_points, '订单消费返积分: ' . $new_order_no, $oid);
            }
        }
        
        // 推广返现
        $order_record = Database::fetch("SELECT * FROM orders WHERE order_no = ?", [$new_order_no]);
        if ($order_record) {
            process_referral_rebate($order_record['id'], $uid, $amount);
        }
        
        header('Location: /creating.php?order_no=' . urlencode($new_order_no));
        exit;
    }
    
    header("Location: /checkout.php?order_id=$new_order_no");
    exit;
}

// ================ 处理支付 ================
if (is_post() && get('action') === 'submit_pay') {
    // 核验码验证
    license_require_for_service('支付订单');
    
    $pay_order_no = trim(post('order_no'));
    $pay_method = post('payment_method', 'balance');
    $order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ? AND status = 'pending'", [$pay_order_no, $uid]);
    if (!$order) {
        flash('error', '订单不存在或已支付');
        header('Location: /user/orders.php');
        exit;
    }

    // 余额支付
    if ($pay_method === 'balance') {
        if ($user['balance'] < $order['amount']) {
            // 余额不足，自动跳转到微信支付
            $pay_method = 'wxpay';
        } else {
            Database::update('users', ['balance' => $user['balance'] - $order['amount']], 'id = ?', [$uid]);

            // 标记订单已支付
            Database::update('orders', [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'payment_method' => 'balance',
            ], 'order_no = ?', [$pay_order_no]);

            // 发送支付成功通知
            send_notification($uid, 'order', '订单支付成功',
                '订单 ' . $pay_order_no . ' 支付成功，金额：¥' . number_format($order['amount'], 2) . '，正在为您开通主机。',
                'order', $order['id']);

            // ========== 积分系统：消费返积分 ==========
            $order_record = Database::fetch("SELECT * FROM orders WHERE order_no = ?", [$pay_order_no]);
            $order_amount_for_points = floatval($order_record['amount']);
            $points_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'order_pay' AND enabled = 1");
            if ($points_rule && intval($points_rule['points']) > 0) {
                $earned_points = intval($order_amount_for_points) * intval($points_rule['points']); // 每消费1元返N积分
                if ($earned_points > 0) {
                    change_points($uid, 'earn_order', $earned_points, '订单消费返积分: ' . $pay_order_no, $order_record['id']);
                }
            }

            // ========== 推广返现 ==========
            if ($order_record) {
                process_referral_rebate($order_record['id'], $uid, $order_amount_for_points);
            }

            // 跳转到创建等待页面
            header('Location: /creating.php?order_no=' . urlencode($pay_order_no));
            exit;
        }
    }

    // 微信支付（仅保留 wxpay）
    if ($pay_method === 'wxpay') {
        // 提交到易支付 SDK
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

// 公共函数：开通主机
function mnbt_create_host($order, $uid) {
    $pkg_info = json_decode($order['package_info'], true);
    $mnbt_username = 'mnbt' . $uid . substr(time(), -4) . rand(10, 99);
    $mnbt_password = substr(md5(time() . rand(1000, 9999) . mt_rand()), 0, 10);
    $webdx = $pkg_info['webdx'] ?? 1000;
    $sqldx = $pkg_info['sqldx'] ?? 500;
    $sizemax = $pkg_info['sizemax'] ?? 50;
    $mtype = $pkg_info['type'] ?? 2;
    $ymbds = $pkg_info['ymbds'] ?? 5;
    $dqtime = date('Y-m-d', strtotime("+" . intval($order['duration']) . " months"));

    try {
        $api = mnbt_api();
        $result = $api->create_host($mnbt_username, $mnbt_password, $webdx, $sqldx, $sizemax, $mtype, $ymbds, $dqtime);

        $host_status = 'creating';
        if (is_array($result) && isset($result['code']) && intval($result['code']) === 200) {
            $host_status = 'running';
            Database::update('orders', ['status' => 'completed'], 'id = ?', [$order['id']]);
        }

        $host_data = [
            'user_id' => $uid,
            'order_id' => $order['id'],
            'package_id' => $order['package_id'],
            'package_name' => $order['package_name'],
            'mnbt_username' => $mnbt_username,
            'mnbt_password' => $mnbt_password,
            'control_panel_url' => config('mnbt.base_url') . '/user/',
            'expire_at' => $dqtime . ' 23:59:59',
            'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'status' => $host_status,
        ];
        $host_id = Database::insert('hosts', $host_data);

        // 自动添加FRP内网穿透（如果启用了FRP）
        $frp_cfg = config('frp');
        if (!empty($frp_cfg['enabled']) && $host_id) {
            @db_ensure_host_frp_columns();
            $rule_name = 'host_' . $mnbt_username;
            $local_ip = frp_clean_local_ip($frp_cfg['local_ip'] ?? '127.0.0.1');
            $local_port = intval($frp_cfg['local_port'] ?? 7894);
            if ($local_port <= 0) $local_port = 7894;
            $remote_port = frp_find_available_port(20000 + intval(substr($uid, -4)) + intval($host_id));
            $frp_result = frp_add_proxy($rule_name, 'tcp', $local_ip, $local_port, $remote_port);
            if ($frp_result['success']) {
                $server_addr = $frp_cfg['public_domain'] ?? $frp_cfg['server_addr'] ?? '';
                $remote_addr = $server_addr . ':' . $remote_port;
                $public_url = 'http://' . $remote_addr;
                Database::update('hosts', [
                    'frp_rule_name' => $rule_name,
                    'frp_local_port' => $local_port,
                    'frp_remote_port' => $remote_port,
                    'frp_remote_addr' => $remote_addr,
                    'frp_public_url' => $public_url,
                    'frp_status' => 'online',
                    'frp_api_response' => json_encode($frp_result, JSON_UNESCAPED_UNICODE),
                ], 'id = ?', [$host_id]);

                // 尝试自动绑定宝塔面板域名
                $bt_cfg = config('bt_panel');
                if (!empty($bt_cfg['enabled'])) {
                    @bt_add_domain($mnbt_username, $remote_addr);
                }
            }
        }

        return true;
    } catch (Exception $e) {
        $host_data = [
            'user_id' => $uid,
            'order_id' => $order['id'],
            'package_id' => $order['package_id'],
            'package_name' => $order['package_name'],
            'mnbt_username' => $mnbt_username,
            'mnbt_password' => $mnbt_password,
            'control_panel_url' => config('mnbt.base_url') . '/user/',
            'expire_at' => $dqtime . ' 23:59:59',
            'api_response' => 'Error: ' . $e->getMessage(),
            'status' => 'creating',
        ];
        Database::insert('hosts', $host_data);
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>购买主机 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body data-theme="<?php echo db_get_setting('site_theme', 'business'); ?>">
    <?php include __DIR__ . '/templates/navbar.php'; ?>

    <?php if ($existing_order): ?>
    <!-- 订单支付页：独立页面，无侧边栏 -->
    <div class="checkout-standalone-page">
    <?php else: ?>
    <div class="dashboard">
        <?php if (auth_check()) include __DIR__ . '/user/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">购买主机</h1>
                    <p class="page-subtitle">选择适合您的套餐和时长</p>
                </div>
            </div>
    <?php endif; ?>

            <?php
            $error = flash('error');
            $success = flash('success');
            if ($error): ?><div class="alert alert-error" style="margin-bottom:20px;"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:20px;"><?php echo e($success); ?></div><?php endif; ?>

            <?php if (!$existing_order): ?>
                <!-- 选择套餐 -->
                <div class="card">
                    <div class="card-title">选择套餐</div>

                    <!-- 分类筛选 -->
                    <?php if (!empty($categories)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                        <a href="/checkout.php?duration=<?php echo $duration; ?>" style="text-decoration:none;">
                            <span style="padding:6px 16px; border-radius:20px; font-size:13px; border:1px solid <?php echo $category_id == 0 ? '#1677ff' : '#e5e6eb'; ?>; background:<?php echo $category_id == 0 ? '#1677ff' : '#fff'; ?>; color:<?php echo $category_id == 0 ? '#fff' : '#4e5969'; ?>; cursor:pointer; transition:all 0.2s;">全部</span>
                        </a>
                        <?php foreach ($categories as $cat): ?>
                        <a href="/checkout.php?category_id=<?php echo $cat['id']; ?>&duration=<?php echo $duration; ?>" style="text-decoration:none;">
                            <span style="padding:6px 16px; border-radius:20px; font-size:13px; border:1px solid <?php echo $category_id == $cat['id'] ? '#1677ff' : '#e5e6eb'; ?>; background:<?php echo $category_id == $cat['id'] ? '#1677ff' : '#fff'; ?>; color:<?php echo $category_id == $cat['id'] ? '#fff' : '#4e5969'; ?>; cursor:pointer; transition:all 0.2s;"><?php echo e($cat['name']); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="pricing-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                        <?php foreach ($packages as $pkg):
                            $pkg_name = !empty($pkg['name']) ? $pkg['name'] : '套餐 #' . $pkg['id'];
                            $pkg_price = floatval($pkg['price_monthly'] ?? 0);
                            $pkg_price_display = $pkg_price > 0 ? number_format($pkg_price, 0) : '免费';
                        ?>
                            <a href="/checkout.php?package_id=<?php echo $pkg['id']; ?>&duration=<?php echo $duration; ?>" style="text-decoration: none;">
                                <div class="price-card <?php echo $pkg['id'] == $package_id ? 'recommended' : ''; ?>" style="margin-bottom: 0; padding:20px; transition: all 0.2s;">
                                    <div style="font-size:12px; color:#86909c; margin-bottom:4px;"><?php echo e($pkg_name); ?></div>
                                    <div class="price" style="margin:8px 0;">
                                        <span class="currency" style="font-size:14px; color:#1677ff;">¥</span>
                                        <span class="amount" style="font-size:32px; font-weight:700; color:#1677ff;"><?php echo $pkg_price_display; ?></span>
                                        <span class="period" style="font-size:12px; color:#86909c;">/月</span>
                                    </div>
                                    <ul style="list-style:none; font-size:13px; color:#4e5969; line-height:1.9; padding:0; margin:0;">
                                        <li>• <?php echo intval($pkg['webdx'] ?? 0); ?> MB 网页空间</li>
                                        <li>• <?php echo intval($pkg['sqldx'] ?? 0); ?> MB 数据库</li>
                                        <li>• <?php echo intval($pkg['sizemax'] ?? 0); ?> GB 月流量</li>
                                        <li>• <?php echo intval($pkg['ymbds'] ?? 0); ?> 个域名绑定</li>
                                    </ul>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 选择时长 -->
                <div class="card">
                    <div class="card-title">选择时长</div>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:12px;">
                        <?php foreach ([1, 3, 6, 12, 24, 36] as $d):
                            $d_discount = 1;
                            $d_label = '';
                            if ($d >= 12) { $d_discount = 0.85; $d_label = '85折'; }
                            elseif ($d >= 6) { $d_discount = 0.92; $d_label = '92折'; }
                            elseif ($d >= 3) { $d_discount = 0.96; $d_label = '96折'; }
                            $d_total = round($current_package['price_monthly'] * $d * $d_discount, 2);
                        ?>
                            <a href="/checkout.php?package_id=<?php echo $package_id; ?>&duration=<?php echo $d; ?>" style="text-decoration:none;">
                                <div style="padding:16px 12px; text-align:center; border-radius:8px; border:1px solid <?php echo $duration == $d ? '#1677ff' : '#e5e6eb'; ?>; background:<?php echo $duration == $d ? '#e6f4ff' : '#ffffff'; ?>; transition:all 0.2s;">
                                    <div style="font-size:15px; font-weight:600; color:<?php echo $duration == $d ? '#1677ff' : '#1d2129'; ?>;"><?php echo $d; ?> 个月</div>
                                    <div style="font-size:13px; color:#4e5969; margin-top:6px;">¥<?php echo $d_total; ?> <?php if ($d_label): ?><span style="color:#00b42a; font-weight:500; margin-left:4px;"><?php echo $d_label; ?></span><?php endif; ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 系统镜像选择（KVM套餐才显示） -->
                <?php if (!empty($vm_images)): ?>
                <div class="card">
                    <div class="card-title">选择系统镜像
                        <span style="font-size:12px; color:#86909c; font-weight:normal;">（选择您想要的操作系统）</span>
                    </div>
                    <div id="imageSelector" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                        <?php foreach ($vm_images as $img): ?>
                            <div class="image-card" onclick="selectImage(<?php echo $img['id']; ?>, this)" data-id="<?php echo $img['id']; ?>">
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                    <span style="font-size:20px;"><?php echo $img['os_type'] === 'windows' ? '🪟' : ($img['os_type'] === 'linux' ? '🐧' : '💾'); ?></span>
                                    <span style="font-weight:600; color:#1d2129; font-size:14px;"><?php echo e($img['name']); ?></span>
                                </div>
                                <div style="font-size:12px; color:#4e5969; line-height:1.6;">
                                    最小：<?php echo intval($img['min_cpu']); ?>核 / <?php echo intval($img['min_memory_mb']); ?>MB / <?php echo intval($img['min_disk_gb']); ?>GB
                                    <br/>
                                    默认账号：<?php echo e($img['default_username']); ?>
                                    <?php if (!empty($img['recommended'])): ?>
                                        <div style="color:#00b42a; margin-top:6px; font-size:12px;">✓ <?php echo e($img['recommended']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="image-check">✓ 已选择</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- IP池选择（KVM套餐才显示） -->
                <?php if (!empty($vm_images) && !empty($ip_pools)): ?>
                <div class="card">
                    <div class="card-title">选择IP节点
                        <span style="font-size:12px; color:#86909c; font-weight:normal;">（选择您的IP地址所在区域）</span>
                    </div>
                    <div id="poolSelector" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
                        <?php foreach ($ip_pools as $pool): ?>
                            <div class="image-card" onclick="selectPool(<?php echo $pool['id']; ?>, this)" data-id="<?php echo $pool['id']; ?>">
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                    <span style="font-size:20px;">
                                        <?php
                                        if (($pool['pool_type'] ?? 'dedicated') === 'nat') {
                                            echo '🌐';
                                        } else {
                                            echo '💻';
                                        }
                                        ?>
                                    </span>
                                    <span style="font-weight:600; color:#1d2129; font-size:14px;"><?php echo e($pool['pool_name']); ?></span>
                                </div>
                                <div style="font-size:12px; color:#4e5969; line-height:1.8;">
                                    <div>IP段：<span style="font-family:monospace;"><?php echo e($pool['ip_start']); ?> - <?php echo e($pool['ip_end']); ?></span></div>
                                    <?php if (($pool['pool_type'] ?? 'dedicated') === 'nat' && !empty($pool['public_ip'])): ?>
                                    <div>公网IP：<span style="color:#d97706; font-weight:600; font-family:monospace;"><?php echo e($pool['public_ip']); ?></span></div>
                                    <?php endif; ?>
                                    <div>可用IP：<span style="color:#22c55e; font-weight:500;"><?php echo intval($pool['total_count']) - intval($pool['used_count']); ?> 个</span></div>
                                    <?php if (!empty($pool['description'])): ?>
                                    <div style="margin-top:4px; color:#86909c;"><?php echo e($pool['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="image-check">✓ 已选择</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 订单汇总 + 提交 -->
                <div class="card">
                    <div class="card-title">订单详情</div>
                    <div style="padding:4px 0;">
                        <div style="display:flex; justify-content:space-between; padding:8px 0; color:#4e5969; font-size:14px;">
                            <span><?php echo e($current_package['name']); ?> × <?php echo $duration; ?> 个月</span>
                            <span>¥<?php echo number_format($original_total, 2); ?></span>
                        </div>
                        <?php if ($discount < 1): ?>
                        <div style="display:flex; justify-content:space-between; padding:8px 0; color:#00b42a; font-size:14px;">
                            <span>优惠折扣 (<?php echo $discount_label; ?>)</span>
                            <span>-¥<?php echo number_format($original_total - $total_amount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($is_kvm_pkg && !empty($pkg_kvm_specs)): ?>
                        <!-- KVM规格详情 -->
                        <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-top:12px;">
                            <div style="font-size:13px; font-weight:600; color:#1d2129; margin-bottom:8px;">配置规格</div>
                            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px; font-size:12px; color:#4e5969;">
                                <div><span style="color:#86909c;">CPU：</span><?php echo $pkg_kvm_specs['vcpu']; ?> 核</div>
                                <div><span style="color:#86909c;">内存：</span><?php echo intval($pkg_kvm_specs['memory_mb'] / 1024); ?> GB</div>
                                <div><span style="color:#86909c;">磁盘：</span><?php echo $pkg_kvm_specs['disk_gb']; ?> GB</div>
                                <div><span style="color:#86909c;">峰值带宽：</span><?php echo $pkg_kvm_specs['bandwidth_mbps']; ?> Mbps</div>
                            </div>
                            <?php if (!empty($ip_pools)): ?>
                            <div style="font-size:12px; color:#4e5969; margin-top:8px; padding-top:8px; border-top:1px dashed #e5e6eb;">
                                <span style="color:#86909c;">节点：</span><span id="poolNameDisplay"><?php echo e($ip_pools[0]['pool_name'] ?? '请选择'); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #e5e6eb; margin-top:12px; padding-top:16px;">
                            <span style="color:#1d2129; font-weight:600;">应付金额</span>
                            <span id="payableAmount" style="font-size:28px; color:#1677ff; font-weight:700; font-family: SF Mono, Monaco, Menlo, monospace;">¥<?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    </div>
                    
                    <form method="POST" style="margin-top: 24px;" id="orderForm">
                        <input type="hidden" name="action" value="create_order">
                        <input type="hidden" name="package_id" value="<?php echo $package_id; ?>">
                        <input type="hidden" name="duration" value="<?php echo $duration; ?>">
                        <input type="hidden" name="image_id" id="imageIdInput" value="0">
                        <input type="hidden" name="pool_id" id="poolIdInput" value="<?php echo $default_pool_id; ?>">
                        <input type="hidden" name="user_coupon_id" id="userCouponId" value="0">
                        
                        <?php if ($is_kvm_pkg): ?>
                        <!-- KVM套餐需要设置root密码 -->
                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-size:14px; font-weight:600; color:#1d2129; margin-bottom:8px;">
                                设置 Root 密码 <span style="color:#ff4d4f; font-size:12px;">（请牢记此密码，用于SSH登录）</span>
                            </label>
                            <input type="password" name="root_password" id="rootPassword" placeholder="请输入root密码（至少6位）" 
                                   style="width:100%; padding:10px 12px; border:1px solid #e5e6eb; border-radius:6px; font-size:14px;" required>
                            <input type="password" name="root_password_confirm" id="rootPasswordConfirm" placeholder="确认root密码" 
                                   style="width:100%; padding:10px 12px; border:1px solid #e5e6eb; border-radius:6px; font-size:14px; margin-top:8px;" required>
                            <div id="passwordTips" style="font-size:12px; color:#86909c; margin-top:6px;">密码长度至少6位</div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 优惠券选择（绑定到当前用户账户，可选择不使用）-->
                        <div class="coupon-block">
                            <div style="font-size:14px; font-weight:600; color:#1d2129; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                                <span>🎫 优惠券</span>
                                <label style="font-size:12px; font-weight:400; color:#86909c; cursor:pointer;">
                                    <input type="radio" name="coupon_radio" value="0" checked onclick="selectCoupon(0)"> 不使用优惠券
                                </label>
                            </div>
                            <?php if (empty($user_coupons)): ?>
                                <div style="font-size:12px; color:#86909c; padding:12px; background:#f5f7fa; border-radius:8px; text-align:center;">
                                    暂无可用优惠券
                                </div>
                            <?php else: ?>
                                <div class="coupon-list">
                                    <?php foreach ($user_coupons as $uc): 
                                        $discount_preview = calculate_coupon_discount($uc, $total_amount);
                                        $preview_text = '';
                                        if ($uc['coupon_type'] === 'cash') {
                                            $preview_text = '立减 ¥' . number_format($uc['discount_amount'], 2);
                                        } elseif ($uc['coupon_type'] === 'discount') {
                                            // discount_rate=90 表示 9折（10% off），需转换为中文习惯
                                            $rate_display = rtrim(rtrim(number_format($uc['discount_rate'] / 10, 1), '0'), '.');
                                            $preview_text = $rate_display . ' 折';
                                        } else {
                                            $preview_text = '立减 ¥' . number_format($uc['discount_amount'], 2);
                                        }
                                    ?>
                                    <label class="coupon-item" onclick="selectCoupon(<?php echo intval($uc['user_coupon_id']); ?>, this, <?php echo number_format($discount_preview['discount'], 2, '.', ''); ?>)">
                                        <input type="radio" name="coupon_radio" value="<?php echo intval($uc['user_coupon_id']); ?>" style="display:none;">
                                        <div class="coupon-info">
                                            <div class="coupon-name"><?php echo e($uc['coupon_name']); ?></div>
                                            <div class="coupon-meta">
                                                <?php echo $preview_text; ?> ·
                                                有效期至 <?php echo date('Y-m-d', strtotime($uc['valid_to'])); ?>
                                            </div>
                                            <div class="coupon-code">码：<?php echo e($uc['coupon_code']); ?></div>
                                        </div>
                                        <div class="coupon-discount">
                                            -¥<?php echo number_format($discount_preview['discount'], 2); ?>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div style="font-size:11px; color:#86909c; margin-top:6px;">提示：优惠券与账户绑定，仅本账户可用。订单取消将自动释放优惠券。</div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- 协议确认 -->
                        <div style="margin-bottom:20px; padding:16px; background:#f5f7fa; border-radius:8px;">
                            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                                <input type="checkbox" name="agree_terms" id="agreeTerms" value="1" style="margin-top:3px; width:16px; height:16px;">
                                <span style="font-size:13px; color:#4e5969; line-height:1.6;">
                                    我已阅读并同意 <a href="/terms.php" target="_blank" style="color:#1677ff;">《服务协议》</a>、<a href="/privacy.php" target="_blank" style="color:#1677ff;">《隐私政策》</a>，了解并同意相关条款
                                </span>
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; padding:12px; font-size:16px;" id="submitBtn">提交订单</button>
                    </form>
                </div>

                <style>
                .coupon-block {
                    margin-bottom: 20px;
                    padding: 16px;
                    background: #fff;
                    border: 1px solid #e5e6eb;
                    border-radius: 8px;
                }
                .coupon-list {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }
                .coupon-item {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 10px 12px;
                    border: 2px solid #e5e6eb;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s;
                    background: #fff;
                }
                .coupon-item:hover {
                    border-color: #91caff;
                    background: #f5f9ff;
                }
                .coupon-item.selected {
                    border-color: #1677ff;
                    background: #e6f4ff;
                }
                .coupon-info { flex: 1; }
                .coupon-name { font-size: 13px; font-weight: 600; color: #1d2129; }
                .coupon-meta { font-size: 11px; color: #86909c; margin-top: 2px; }
                .coupon-code { font-size: 10px; color: #c9cdd4; margin-top: 2px; font-family: monospace; }
                .coupon-discount {
                    font-size: 16px;
                    font-weight: 700;
                    color: #ff4d4f;
                    font-family: SF Mono, Monaco, Menlo, monospace;
                }
                </style>
                <script>
                var baseAmount = <?php echo number_format($total_amount, 2, '.', ''); ?>; // 应付金额（不含优惠券）
                var payableSpan = document.getElementById('payableAmount');

                function selectCoupon(userCouponId, itemEl, discountVal) {
                    document.getElementById('userCouponId').value = userCouponId;
                    // 同步单选状态
                    var allItems = document.querySelectorAll('.coupon-item');
                    allItems.forEach(function(el) { el.classList.remove('selected'); });
                    if (userCouponId === 0) {
                        payableSpan.textContent = '¥' + baseAmount.toFixed(2);
                        return;
                    }
                    if (itemEl) itemEl.classList.add('selected');
                    var final = Math.max(baseAmount - parseFloat(discountVal), 0);
                    payableSpan.textContent = '¥' + final.toFixed(2);
                }
                </script>

                <style>
                .image-card {
                    position: relative;
                    padding: 14px 12px;
                    border: 2px solid #e5e6eb;
                    border-radius: 8px;
                    background: #fff;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .image-card:hover {
                    border-color: #91caff;
                    background: #f5f9ff;
                }
                .image-card.selected {
                    border-color: #1677ff;
                    background: #e6f4ff;
                }
                .image-card .image-check {
                    display: none;
                    position: absolute;
                    top: 8px;
                    right: 8px;
                    background: #1677ff;
                    color: #fff;
                    font-size: 11px;
                    padding: 2px 8px;
                    border-radius: 4px;
                    font-weight: 600;
                }
                .image-card.selected .image-check {
                    display: block;
                }
                </style>
                <script>
                var selectedImage = <?php echo $default_image_id; ?>;
                var selectedPool = <?php echo $default_pool_id; ?>;
                var poolNames = {};
                <?php foreach ($ip_pools as $pool): ?>
                poolNames[<?php echo $pool['id']; ?>] = <?php echo json_encode($pool['pool_name']); ?>;
                <?php endforeach; ?>

                function selectImage(id, el) {
                    selectedImage = id;
                    document.getElementById('imageIdInput').value = id;
                    document.querySelectorAll('#imageSelector .image-card').forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    el.classList.add('selected');
                }

                function selectPool(id, el) {
                    selectedPool = id;
                    document.getElementById('poolIdInput').value = id;
                    document.getElementById('poolNameDisplay').textContent = poolNames[id] || '请选择';
                    document.querySelectorAll('#poolSelector .image-card').forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    el.classList.add('selected');
                }

                // 密码验证
                function validatePassword() {
                    var pwd = document.getElementById('rootPassword').value;
                    var pwd2 = document.getElementById('rootPasswordConfirm').value;
                    var tips = document.getElementById('passwordTips');
                    var submitBtn = document.getElementById('submitBtn');
                    
                    if (!pwd || pwd.length < 6) {
                        tips.textContent = '密码长度至少6位';
                        tips.style.color = '#ff4d4f';
                        return false;
                    }
                    if (pwd !== pwd2) {
                        tips.textContent = '两次密码输入不一致';
                        tips.style.color = '#ff4d4f';
                        return false;
                    }
                    tips.textContent = '密码验证通过';
                    tips.style.color = '#00b42a';
                    return true;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    var firstImg = document.querySelector('#imageSelector .image-card[data-id="' + selectedImage + '"]');
                    if (firstImg) firstImg.classList.add('selected');
                    document.getElementById('imageIdInput').value = selectedImage;

                    var firstPool = document.querySelector('#poolSelector .image-card[data-id="' + selectedPool + '"]');
                    if (firstPool) firstPool.classList.add('selected');
                    document.getElementById('poolIdInput').value = selectedPool;
                    
                    // 密码实时验证
                    var pwdInput = document.getElementById('rootPassword');
                    var pwdConfirmInput = document.getElementById('rootPasswordConfirm');
                    if (pwdInput) {
                        pwdInput.addEventListener('input', validatePassword);
                        pwdConfirmInput.addEventListener('input', validatePassword);
                    }
                    
                    // 表单提交验证
                    var form = document.getElementById('orderForm');
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            <?php if ($is_kvm_pkg): ?>
                            if (!validatePassword()) {
                                e.preventDefault();
                                alert('请正确填写密码');
                                return false;
                            }
                            <?php endif; ?>
                            var agreeTerms = document.getElementById('agreeTerms');
                            if (agreeTerms && !agreeTerms.checked) {
                                e.preventDefault();
                                alert('请阅读并同意服务协议和隐私政策');
                                return false;
                            }
                        });
                    }
                });
                </script>
            <?php else: ?>
                <?php 
                // 获取订单详情
                $pkg_info = json_decode($existing_order['package_info'] ?? '{}', true);
                $order_is_kvm = !empty($pkg_info['image_id']);
                $order_image = null;
                if ($order_is_kvm && !empty($pkg_info['image_id'])) {
                    $order_image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$pkg_info['image_id']]);
                }
                ?>
                <div class="checkout-main">
                    <div class="checkout-left">
                        <div class="card">
                            <div class="card-title">
                                <span>订单详情</span>
                                <span class="badge badge-primary" style="background:#e6f4ff; color:#1677ff; border:1px solid #91caff;"><?php echo e($existing_order['order_no']); ?></span>
                            </div>
                            <div style="padding:4px 0;">
                                <div style="display:flex; justify-content:space-between; padding:8px 0; color:#4e5969; font-size:14px;">
                                    <span>商品：<?php echo e($existing_order['package_name']); ?></span>
                                    <span>时长：<?php echo $existing_order['duration']; ?> 个月</span>
                                </div>
                                <div style="display:flex; justify-content:space-between; padding:8px 0; color:#4e5969; font-size:14px;">
                                    <span>下单时间</span>
                                    <span><?php echo format_date($existing_order['created_at']); ?></span>
                                </div>
                                
                                <?php if ($order_is_kvm): ?>
                                <!-- KVM订单详情 -->
                                <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-top:12px;">
                                    <div style="font-size:13px; font-weight:600; color:#1d2129; margin-bottom:8px;">配置规格</div>
                                    <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px; font-size:12px; color:#4e5969;">
                                        <div><span style="color:#86909c;">CPU：</span><?php echo intval($pkg_info['kvm_vcpu'] ?? 2); ?> 核</div>
                                        <div><span style="color:#86909c;">内存：</span><?php echo intval(($pkg_info['kvm_memory_mb'] ?? 2048) / 1024); ?> GB</div>
                                        <div><span style="color:#86909c;">磁盘：</span><?php echo intval($pkg_info['kvm_disk_gb'] ?? 40); ?> GB</div>
                                        <div><span style="color:#86909c;">峰值带宽：</span><?php echo intval($pkg_info['kvm_bandwidth_mbps'] ?? 100); ?> Mbps</div>
                                    </div>
                                    <?php if ($order_image): ?>
                                    <div style="display:flex; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:1px dashed #e5e6eb; font-size:12px; color:#4e5969;">
                                        <span><span style="color:#86909c;">系统：</span><?php echo e($order_image['name']); ?></span>
                                        <span><span style="color:#86909c;">账号：</span><?php echo e($order_image['default_username'] ?? 'root'); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($pkg_info['root_password'])): ?>
                                    <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #e5e6eb;">
                                        <div style="font-size:12px; color:#4e5969;">
                                            <span style="color:#86909c;">Root密码：</span>
                                            <span style="font-family:monospace; background:#fff; padding:2px 8px; border-radius:4px; margin-left:4px;"><?php echo e($pkg_info['root_password']); ?></span>
                                            <span style="color:#ff7c00; font-size:11px; margin-left:8px;">（请牢记密码）</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                // 显示优惠券使用情况
                                $order_discount = floatval($existing_order['discount_amount'] ?? 0);
                                $order_original = floatval($existing_order['original_amount'] ?? 0);
                                $order_coupon_code = $existing_order['coupon_code'] ?? '';
                                $order_coupon_id = intval($existing_order['coupon_id'] ?? 0);
                                if ($order_coupon_id > 0 && $order_discount > 0):
                                ?>
                                <div style="background:#fff7e6; border:1px solid #ffe58f; border-radius:8px; padding:12px; margin-top:12px;">
                                    <div style="font-size:13px; font-weight:600; color:#d46b08; margin-bottom:6px;">🎫 优惠券使用情况</div>
                                    <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px; font-size:12px; color:#4e5969;">
                                        <div><span style="color:#86909c;">券码：</span><span style="font-family:monospace;"><?php echo e($order_coupon_code); ?></span></div>
                                        <div><span style="color:#86909c;">优惠金额：</span><span style="color:#d46b08; font-weight:600;">-¥<?php echo number_format($order_discount, 2); ?></span></div>
                                        <div><span style="color:#86909c;">原价：</span>¥<?php echo number_format($order_original, 2); ?></div>
                                        <div><span style="color:#86909c;">实付：</span>¥<?php echo number_format($existing_order['amount'], 2); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #e5e6eb; margin-top:12px; padding-top:16px;">
                                    <span style="color:#1d2129; font-weight:600;">应付金额</span>
                                    <span style="font-size:28px; color:#1677ff; font-weight:700; font-family:SF Mono, Monaco, Menlo, monospace;">¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button onclick="openPaymentPanel()" class="checkout-pay-btn">
                        <span>立即支付</span>
                        <span class="btn-amount">¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                    </button>
                </div>

                <div class="payment-overlay" id="paymentOverlay" onclick="closePaymentPanel()"></div>
                <div class="payment-panel" id="paymentPanel">
                    <div class="payment-panel-header">
                        <div class="payment-panel-title">确认支付</div>
                        <button class="payment-panel-close" onclick="closePaymentPanel()">×</button>
                    </div>
                    <div class="payment-panel-body">
                        <div class="payment-currency">
                            <span class="currency-label">选择货币：</span>
                            <div class="currency-options">
                                <div class="currency-option selected" onclick="selectCurrency(this, 'CNY')">
                                    <span class="currency-flag">🇨🇳</span>
                                    <span>¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                                </div>
                                <div class="currency-option" onclick="selectCurrency(this, 'USD')">
                                    <span class="currency-flag">🇺🇸</span>
                                    <span>US$<?php echo number_format($existing_order['amount'] / 7.2, 2); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="payment-divider"></div>

                        <div class="payment-method-section">
                            <div class="payment-method-title">选择支付方式</div>
                            <div class="payment-method-list">
                                <div class="payment-method-item selected" onclick="selectPaymentMethod(this, 'balance')">
                                    <div class="payment-method-icon">💰</div>
                                    <div class="payment-method-info">
                                        <div class="payment-method-name">余额支付</div>
                                        <div class="payment-method-desc">可用：¥<?php echo number_format($user['balance'], 2); ?></div>
                                    </div>
                                    <div class="payment-method-check">✓</div>
                                </div>
                                <div class="payment-method-item" onclick="selectPaymentMethod(this, 'wxpay')">
                                    <div class="payment-method-icon">💚</div>
                                    <div class="payment-method-info">
                                        <div class="payment-method-name">微信支付</div>
                                        <div class="payment-method-desc">推荐有微信账户的用户</div>
                                    </div>
                                    <div class="payment-method-check">✓</div>
                                </div>
                            </div>
                        </div>

                        <div class="payment-divider"></div>

                        <div class="payment-agree">
                            <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="payAgreeTerms" style="margin-top:3px; width:16px; height:16px;">
                                <span style="font-size:13px; color:#86909c; line-height:1.6;">
                                    我已阅读并同意 <a href="/terms.php" target="_blank" style="color:#1677ff;">《服务协议》</a>、<a href="/privacy.php" target="_blank" style="color:#1677ff;">《隐私政策》</a>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="payment-panel-footer">
                        <div class="payment-total">
                            <span>应付金额</span>
                            <span class="payment-total-amount">¥<?php echo number_format($existing_order['amount'], 2); ?></span>
                        </div>
                        <form method="POST" id="payForm" action="/checkout.php?action=submit_pay" style="display:none;">
                            <input type="hidden" name="order_no" value="<?php echo e($existing_order['order_no']); ?>">
                            <input type="hidden" name="payment_method" id="paymentMethod" value="balance">
                        </form>
                        <button onclick="submitPayment()" id="payBtn" class="payment-submit-btn">支付</button>
                    </div>
                </div>

                <style>
                .checkout-main {
                    position: relative;
                }
                .checkout-left {
                    flex: 1;
                }
                .checkout-pay-btn {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: var(--primary-gradient);
                    color: #fff;
                    padding: 16px 24px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    font-size: 16px;
                    font-weight: 600;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 -4px 20px rgba(22, 119, 255, 0.3);
                    z-index: 100;
                }
                .checkout-pay-btn .btn-amount {
                    font-size: 20px;
                    font-weight: 700;
                }

                .payment-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 1000;
                    animation: fadeIn 0.3s ease;
                }
                .payment-overlay.show {
                    display: block;
                }

                .payment-panel {
                    display: none;
                    position: fixed;
                    top: 0;
                    right: 0;
                    width: 420px;
                    max-width: 100%;
                    height: 100vh;
                    background: #ffffff;
                    z-index: 1001;
                    flex-direction: column;
                    animation: slideInRight 0.3s ease;
                    box-shadow: -8px 0 32px rgba(0, 0, 0, 0.12);
                }
                .payment-panel.show {
                    display: flex;
                }

                @keyframes slideInRight {
                    from {
                        transform: translateX(100%);
                    }
                    to {
                        transform: translateX(0);
                    }
                }

                .payment-panel-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 20px 24px;
                    border-bottom: 1px solid var(--border-light);
                }
                .payment-panel-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: var(--text-primary);
                }
                .payment-panel-close {
                    width: 32px;
                    height: 32px;
                    border: none;
                    background: var(--bg-secondary);
                    color: var(--text-secondary);
                    border-radius: 50%;
                    font-size: 20px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s;
                }
                .payment-panel-close:hover {
                    background: var(--bg-hover);
                    color: var(--text-primary);
                }

                .payment-panel-body {
                    flex: 1;
                    padding: 24px;
                    overflow-y: auto;
                }

                .payment-currency {
                    margin-bottom: 20px;
                }
                .currency-label {
                    font-size: 13px;
                    color: var(--text-secondary);
                    margin-bottom: 12px;
                    display: block;
                }
                .currency-options {
                    display: flex;
                    gap: 12px;
                }
                .currency-option {
                    flex: 1;
                    padding: 14px;
                    border: 2px solid var(--border);
                    border-radius: var(--radius-lg);
                    cursor: pointer;
                    transition: all 0.2s;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    font-size: 15px;
                    font-weight: 600;
                    color: var(--text-primary);
                }
                .currency-option:hover {
                    border-color: var(--primary-light);
                }
                .currency-option.selected {
                    border-color: var(--primary);
                    background: var(--primary-lighter);
                    color: var(--primary);
                }
                .currency-flag {
                    font-size: 20px;
                }

                .payment-divider {
                    height: 8px;
                    background: var(--bg-page);
                    margin: 20px -24px;
                }

                .payment-method-section {
                    margin-bottom: 20px;
                }
                .payment-method-title {
                    font-size: 14px;
                    font-weight: 600;
                    color: var(--text-primary);
                    margin-bottom: 16px;
                }
                .payment-method-list {
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                }
                .payment-method-item {
                    padding: 14px 16px;
                    border: 2px solid var(--border);
                    border-radius: var(--radius-lg);
                    cursor: pointer;
                    transition: all 0.2s;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .payment-method-item:hover {
                    border-color: var(--primary-light);
                    background: var(--bg-secondary);
                }
                .payment-method-item.selected {
                    border-color: var(--primary);
                    background: var(--primary-lighter);
                }
                .payment-method-icon {
                    font-size: 24px;
                }
                .payment-method-info {
                    flex: 1;
                }
                .payment-method-name {
                    font-size: 14px;
                    font-weight: 600;
                    color: var(--text-primary);
                }
                .payment-method-desc {
                    font-size: 12px;
                    color: var(--text-secondary);
                    margin-top: 2px;
                }
                .payment-method-check {
                    width: 20px;
                    height: 20px;
                    border-radius: 50%;
                    background: var(--primary);
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    font-weight: 600;
                }
                .payment-method-item:not(.selected) .payment-method-check {
                    display: none;
                }

                .payment-agree {
                    padding: 16px;
                    background: var(--bg-page);
                    border-radius: var(--radius-lg);
                }

                .payment-panel-footer {
                    padding: 20px 24px;
                    padding-bottom: calc(20px + env(safe-area-inset-bottom));
                    border-top: 1px solid var(--border-light);
                    background: #fff;
                }
                .payment-total {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 16px;
                }
                .payment-total span:first-child {
                    font-size: 14px;
                    color: var(--text-regular);
                }
                .payment-total-amount {
                    font-size: 24px;
                    font-weight: 700;
                    color: var(--primary);
                    font-family: SF Mono, Monaco, Menlo, monospace;
                }
                .payment-submit-btn {
                    width: 100%;
                    padding: 14px;
                    background: var(--primary-gradient);
                    color: #fff;
                    border: none;
                    border-radius: var(--radius-lg);
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s;
                    box-shadow: var(--primary-shadow);
                }
                .payment-submit-btn:hover {
                    opacity: 0.95;
                    transform: translateY(-1px);
                    box-shadow: 0 6px 20px rgba(22, 119, 255, 0.35);
                }

                @media (max-width: 768px) {
                    .payment-panel {
                        width: 100%;
                    }
                }

                /* 独立订单支付页面样式 */
                .checkout-standalone-page {
                    max-width: 720px;
                    margin: 0 auto;
                    padding: 24px 20px 80px;
                    min-height: 100vh;
                    background: #f5f7fa;
                }
                .checkout-standalone-page .card {
                    background: #fff;
                    border-radius: 12px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
                    margin-bottom: 16px;
                }
                .checkout-standalone-page .checkout-main {
                    max-width: 720px;
                    margin: 0 auto;
                }
                </style>

                <script>
                function openPaymentPanel() {
                    document.getElementById('paymentOverlay').classList.add('show');
                    document.getElementById('paymentPanel').classList.add('show');
                    document.body.style.overflow = 'hidden';
                }

                function closePaymentPanel() {
                    document.getElementById('paymentOverlay').classList.remove('show');
                    document.getElementById('paymentPanel').classList.remove('show');
                    document.body.style.overflow = '';
                }

                function selectCurrency(el, currency) {
                    document.querySelectorAll('.currency-option').forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    el.classList.add('selected');
                }

                function selectPaymentMethod(el, method) {
                    document.querySelectorAll('.payment-method-item').forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    el.classList.add('selected');
                    document.getElementById('paymentMethod').value = method;
                }

                function submitPayment() {
                    var agreeTerms = document.getElementById('payAgreeTerms');
                    if (!agreeTerms.checked) {
                        alert('请阅读并同意服务协议和隐私政策');
                        return false;
                    }
                    document.getElementById('payForm').submit();
                }
                </script>
            <?php endif; ?>
        <?php if (!$existing_order): ?></div><?php endif; ?>
    </div>
</body>
</html>
