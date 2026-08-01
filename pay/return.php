<?php
/**
 * 易支付同步跳转回调
 */
require_once __DIR__ . '/../config/helper.php';
require_once __DIR__ . '/epay.php';

migrate_new_tables();

// 获取回调数据
$data = $_GET;

// 验证签名
if (!epay_verify_notify($data)) {
    flash('error', '支付验证失败，请联系客服');
    header('Location: /user/');
    exit;
}

// 获取订单信息
$out_trade_no = $data['out_trade_no'] ?? '';
$trade_status = $data['trade_status'] ?? '';

// 查找充值记录
$recharge = Database::fetch("SELECT * FROM user_recharges WHERE order_no = ?", [$out_trade_no]);

if (!$recharge) {
    flash('error', '订单不存在');
    header('Location: /user/');
    exit;
}

if ($trade_status === 'TRADE_SUCCESS') {
    // 如果订单还在pending状态，可能异步通知还未到达，尝试同步处理
    if ($recharge['status'] === 'pending') {
        // 查询订单状态确认
        $query_result = epay_query_order($out_trade_no);
        if ($query_result && ($query_result['status'] ?? 0) === 1) {
            // 确认支付成功，同步处理
            $money = floatval($data['money'] ?? $recharge['amount']);
            $trade_no = $data['trade_no'] ?? '';
            
            Database::beginTransaction();
            try {
                Database::update('user_recharges', [
                    'status' => 'paid',
                    'payment_no' => $trade_no,
                    'payment_type' => 'epay',
                    'paid_at' => date('Y-m-d H:i:s'),
                ], 'order_no = ?', [$out_trade_no]);
                
                $uid = $recharge['user_id'];
                $user = Database::fetch("SELECT balance FROM users WHERE id = ?", [$uid]);
                $new_balance = floatval($user['balance']) + $money;
                
                Database::update('users', ['balance' => $new_balance], 'id = ?', [$uid]);
                
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
                flash('success', '充值成功！已充值 ¥' . number_format($money, 2));
            } catch (Exception $e) {
                Database::rollBack();
                flash('warning', '支付成功但系统处理异常，请联系客服确认');
            }
        } else {
            flash('warning', '支付处理中，请稍后刷新查看余额');
        }
    } else {
        flash('success', '充值成功！余额已更新');
    }
} else {
    flash('error', '支付未成功，请重新尝试');
}

header('Location: /user/');
exit;