<?php
/**
 * 易支付异步通知接口 - 生产环境
 * 文档要求：
 *   - 请求方式：GET
 *   - 必带参数：pid、out_trade_no、trade_no、type、name、money、trade_status、sign、sign_type
 *   - 可选参数：param
 *   - 成功响应：必须输出 "success" (纯文本，不包含任何多余字符)
 *   - 失败响应：输出 "fail"
 *
 * 流程：
 *   1. 收到易支付网关回调
 *   2. 校验签名 (MD5)
 *   3. 校验 trade_status = TRADE_SUCCESS
 *   4. 校验订单金额是否匹配
 *   5. 订单状态未处理时：标记 paid → 自动开通主机 → 输出 success
 */

require_once __DIR__ . '/lib/epay.config.php';
require_once __DIR__ . '/lib/EpayCore.class.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helper.php';

// 从数据库读取管理员后台配置的易支付参数，覆盖硬编码配置
$db_epay = db_get_settings('epay');
if (!empty($db_epay['api_url'])) {
    $epay_config['apiurl'] = rtrim($db_epay['api_url'], '/') . '/';
}
if (!empty($db_epay['pid'])) {
    $epay_config['pid'] = $db_epay['pid'];
}
if (!empty($db_epay['key'])) {
    $epay_config['key'] = $db_epay['key'];
}

// ================ 日志 (便于排查生产环境问题) ================
$log_line = date('Y-m-d H:i:s') . ' ' . json_encode($_GET, JSON_UNESCAPED_UNICODE) . PHP_EOL;
@file_put_contents(__DIR__ . '/notify.log', $log_line, FILE_APPEND | LOCK_EX);

// ================ 1. 签名校验 ================
$epay = new EpayCore($epay_config);
$verify_result = $epay->verifyNotify();
if (!$verify_result) {
    @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [ERROR] 签名校验失败' . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

// ================ 2. 解析参数 ================
$out_trade_no = trim($_GET['out_trade_no'] ?? '');
$trade_no     = trim($_GET['trade_no']     ?? '');
$trade_status = trim($_GET['trade_status'] ?? '');
$type         = trim($_GET['type']         ?? '');
$money        = trim($_GET['money']        ?? '0.00');
$param        = trim($_GET['param']        ?? '');

if ($out_trade_no === '' || $money === '') {
    echo 'fail';
    exit;
}

// ================ 3. 支付状态判断 ================
if ($trade_status !== 'TRADE_SUCCESS' && $trade_status !== 'TRADE_FINISHED') {
    // 未支付 / 处理中，直接回复 success 以避免回调抖动，不做任何业务处理
    echo 'success';
    exit;
}

// ================ 4. 查询本地订单 ================
$order = Database::fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$out_trade_no]);
if (!$order) {
    @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [ERROR] 本地订单不存在: ' . $out_trade_no . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo 'fail';
    exit;
}

// 已经处理过的订单（幂等处理），直接回复成功
if (in_array($order['status'], ['paid', 'completed'], true)) {
    echo 'success';
    exit;
}

// ================ 5. 金额校验 ================
if (abs(floatval($money) - floatval($order['amount'])) > 0.01) {
    @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [ERROR] 金额不匹配 本地=' . $order['amount'] . ' 回调=' . $money . PHP_EOL, FILE_APPEND | LOCK_EX);
    // 金额不匹配：订单作废，释放绑定的优惠券
    if (!empty($order['coupon_id'])) {
        release_coupon_for_order($order['id']);
    }
    echo 'fail';
    exit;
}

// ================ 6. 更新订单状态 ================
Database::update('orders', [
    'status' => 'paid',
    'paid_at' => date('Y-m-d H:i:s'),
    'payment_method' => $type,
    'payment_trade_no' => $trade_no,
], 'id = ?', [$order['id']]);

// ========== 积分系统：消费返积分 ==========
$order_amount = floatval($money);
$uid_for_points = intval($order['user_id']);
ensure_user_points($uid_for_points);
$points_rule = Database::fetch("SELECT * FROM point_rules WHERE rule_key = 'order_pay' AND enabled = 1");
if ($points_rule && intval($points_rule['points']) > 0) {
    $earned_points = intval($order_amount) * intval($points_rule['points']);
    if ($earned_points > 0) {
        change_points($uid_for_points, 'earn_order', $earned_points, '订单消费返积分: ' . $out_trade_no, $order['id']);
    }
}

// ========== 推广返现 ==========
process_referral_rebate($order['id'], $uid_for_points, $order_amount);

// ========== 自动开通主机 ==========
$pkg_info = json_decode($order['package_info'], true);
$image_id = intval($pkg_info['image_id'] ?? 0);
$uid = intval($order['user_id']);

// 检查是否已存在 hosts 记录（防止重复创建）
$existing_host = Database::fetch("SELECT id FROM hosts WHERE order_id = ?", [$order['id']]);

if (!$existing_host) {
    if ($image_id > 0 && kvm_is_enabled()) {
        // KVM 云服务器：必须传用户设置的 root 密码
        $user_root_password = $pkg_info['root_password'] ?? '';
        kvm_create_vm($order, $uid, $image_id, $user_root_password);
    } else {
        // 虚拟主机：使用与 checkout.php 中 mnbt_create_host 相同的逻辑
        // 注意：不能 require_once checkout.php，因为它会输出 HTML 破坏支付回调的纯文本响应
        $mnbt_username = 'mnbt' . $uid . substr(time(), -4) . rand(10, 99);
        $mnbt_password = substr(md5(time() . rand(1000, 9999) . mt_rand()), 0, 10);
        $webdx = $pkg_info['webdx'] ?? 1000;
        $sqldx = $pkg_info['sqldx'] ?? 500;
        $sizemax = $pkg_info['sizemax'] ?? 50;
        $mtype = $pkg_info['type'] ?? 2;
        $ymbds = $pkg_info['ymbds'] ?? 5;
        $dqtime = date('Y-m-d', strtotime("+" . intval($order['duration']) . " months"));

        try {
            require_once __DIR__ . '/../config/mnbt.php';
            $api = mnbt_api();
            $result = $api->create_host($mnbt_username, $mnbt_password, $webdx, $sqldx, $sizemax, $mtype, $ymbds, $dqtime);

            $host_status = 'creating';
            if (is_array($result) && isset($result['code']) && intval($result['code']) === 200) {
                $host_status = 'running';
                Database::update('orders', ['status' => 'completed'], 'id = ?', [$order['id']]);
            }

            Database::insert('hosts', [
                'user_id' => $uid,
                'order_id' => $order['id'],
                'package_id' => $order['package_id'],
                'package_name' => $order['package_name'],
                'vm_type' => 'mnbt',
                'mnbt_username' => $mnbt_username,
                'mnbt_password' => $mnbt_password,
                'control_panel_url' => config('mnbt.base_url') . '/user/',
                'expire_at' => $dqtime . ' 23:59:59',
                'api_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'status' => $host_status,
            ]);

            @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [OK] 虚拟主机开通成功 ' . $out_trade_no . ' user=' . $mnbt_username . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [WARN] 主机创建异常: ' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
            Database::insert('hosts', [
                'user_id' => $uid,
                'order_id' => $order['id'],
                'package_id' => $order['package_id'],
                'package_name' => $order['package_name'],
                'vm_type' => 'mnbt',
                'mnbt_username' => $mnbt_username,
                'mnbt_password' => $mnbt_password,
                'control_panel_url' => config('mnbt.base_url') . '/user/',
                'expire_at' => $dqtime . ' 23:59:59',
                'api_response' => 'Error: ' . $e->getMessage(),
                'status' => 'creating',
            ]);
        }
    }
} else {
    @file_put_contents(__DIR__ . '/notify.log', date('Y-m-d H:i:s') . ' [SKIP] 主机已存在 order=' . $order['id'] . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ================ 8. 最终必须返回 success ================
echo 'success';
