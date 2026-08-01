<?php
/**
 * 易支付异步通知回调
 */
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/epay.php';

migrate_new_tables();

// 获取回调数据
$data = $_GET;

if (empty($data)) {
    $data = $_POST;
}

// 记录日志
$log_file = __DIR__ . '/../logs/epay_notify.log';
$timestamp = date('Y-m-d H:i:s');
file_put_contents($log_file, $timestamp . ' [notify] ' . json_encode($data) . "\n", FILE_APPEND);

// 验证签名
if (!epay_verify_notify($data)) {
    file_put_contents($log_file, $timestamp . ' [notify] SIGN_INVALID\n', FILE_APPEND);
    exit('fail');
}

// 获取订单信息
$trade_no = $data['trade_no'] ?? '';
$out_trade_no = $data['out_trade_no'] ?? '';
$money = floatval($data['money'] ?? 0);
$trade_status = $data['trade_status'] ?? '';

file_put_contents($log_file, $timestamp . ' [notify] trade_no=' . $trade_no . ' out_trade_no=' . $out_trade_no . ' money=' . $money . ' status=' . $trade_status . "\n", FILE_APPEND);

if ($trade_status !== 'TRADE_SUCCESS') {
    file_put_contents($log_file, $timestamp . ' [notify] STATUS_NOT_SUCCESS\n', FILE_APPEND);
    exit('fail');
}

// 查找充值记录
$recharge = Database::fetch("SELECT * FROM user_recharges WHERE order_no = ?", [$out_trade_no]);

if (!$recharge) {
    file_put_contents($log_file, $timestamp . ' [notify] ORDER_NOT_FOUND: ' . $out_trade_no . "\n", FILE_APPEND);
    exit('fail');
}

// 检查订单状态
if ($recharge['status'] !== 'pending') {
    file_put_contents($log_file, $timestamp . ' [notify] ORDER_ALREADY_PROCESSED: status=' . $recharge['status'] . "\n", FILE_APPEND);
    exit('success'); // 已处理过，返回成功避免重复通知
}

// 检查金额是否匹配
if (abs($recharge['amount'] - $money) > 0.01) {
    file_put_contents($log_file, $timestamp . ' [notify] AMOUNT_MISMATCH: expected=' . $recharge['amount'] . ' received=' . $money . "\n", FILE_APPEND);
    exit('fail');
}

// 开始处理订单
Database::beginTransaction();

try {
    // 更新充值记录状态
    Database::update('user_recharges', [
        'status' => 'paid',
        'payment_no' => $trade_no,
        'payment_type' => 'epay',
        'paid_at' => date('Y-m-d H:i:s'),
    ], 'order_no = ?', [$out_trade_no]);
    
    // 更新用户余额
    $uid = $recharge['user_id'];
    $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
    $new_balance = floatval($user['balance']) + $money;
    
    Database::update('users', ['balance' => $new_balance], 'id = ?', [$uid]);
    
    // 记录余额变动
    Database::insert('balance_logs', [
        'user_id' => $uid,
        'type' => 'recharge',
        'amount' => $money,
        'balance_before' => $user['balance'],
        'balance_after' => $new_balance,
        'reason' => '易支付充值 - 订单号:' . $out_trade_no,
        'order_no' => $out_trade_no,
    ]);
    
    Database::commit();
    
    file_put_contents($log_file, $timestamp . ' [notify] SUCCESS: user_id=' . $uid . ' new_balance=' . $new_balance . "\n", FILE_APPEND);
    
    echo 'success';
} catch (Exception $e) {
    Database::rollBack();
    file_put_contents($log_file, $timestamp . ' [notify] ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    echo 'fail';
}