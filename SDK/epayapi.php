<?php
/**
 * 易支付统一跳转入口 - 生产环境
 * 功能：接收 checkout.php 的支付请求，调用 EpayCore 构造签名并自动跳转到 pay.vansdesign.cn
 * 支付方式：仅支持 wxpay (微信支付)
 * 请求方式：POST (推荐) 或 GET
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
if (!empty($db_epay['notify_url'])) {
    $epay_config['notify_url'] = $db_epay['notify_url'];
}
if (!empty($db_epay['return_url'])) {
    $epay_config['return_url'] = $db_epay['return_url'];
}
if (isset($db_epay['debug'])) {
    $epay_config['debug'] = !empty($db_epay['debug']);
}

// ===== 1. 获取参数 =====
$order_no = trim($_POST['WIDout_trade_no'] ?? $_GET['WIDout_trade_no'] ?? '');
$subject = trim($_POST['WIDsubject'] ?? $_GET['WIDsubject'] ?? '主机购买');
$total_fee = trim($_POST['WIDtotal_fee'] ?? $_GET['WIDtotal_fee'] ?? '');
$type = trim($_POST['type'] ?? $_GET['type'] ?? 'wxpay');

// ===== 2. 基础参数校验 =====
if ($order_no === '' || $total_fee === '' || !is_numeric($total_fee) || floatval($total_fee) <= 0) {
    http_response_code(400);
    echo '参数错误：订单号或金额无效';
    exit;
}

// 仅支持配置的支付方式 (默认：wxpay)
if (!in_array($type, $epay_config['enabled_types'], true)) {
    http_response_code(400);
    echo '不支持的支付方式';
    exit;
}

// ===== 3. 校验本地订单 =====
$order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND status = 'pending'", [$order_no]);
if (!$order) {
    http_response_code(404);
    echo '订单不存在或已支付';
    exit;
}

// 金额二次校验（以数据库为准）
$pay_money = number_format(floatval($order['amount']), 2, '.', '');

// ===== 4. 构造回调 URL =====
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$notify_url = $epay_config['notify_url'] ?? ($scheme . $host . '/SDK/notify_url.php');
$return_url = $epay_config['return_url'] ?? ($scheme . $host . '/SDK/return_url.php');

// ===== 5. 发起页面跳转支付 =====
$parameter = [
    'pid' => $epay_config['pid'],
    'type' => $type,
    'notify_url' => $notify_url,
    'return_url' => $return_url,
    'out_trade_no' => $order['order_no'],
    'name' => $subject,
    'money' => $pay_money,
];

$epay = new EpayCore($epay_config);
echo $epay->pagePay($parameter);
