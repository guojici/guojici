<?php
/**
 * 易支付同步通知页面 - 生产环境
 * 文档要求：
 *   - 请求方式：GET (用户支付后，由易支付跳转到此处)
 *   - 参数：pid、out_trade_no、trade_no、type、name、money、trade_status、param、sign、sign_type
 *   - 功能：仅向用户展示支付结果，并提供快捷入口
 *   - 订单状态建议以异步回调 (notify_url.php) 为准
 */

require_once __DIR__ . '/lib/epay.config.php';
require_once __DIR__ . '/lib/EpayCore.class.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helper.php';

$out_trade_no = trim($_GET['out_trade_no'] ?? '');
$trade_no = trim($_GET['trade_no'] ?? '');
$trade_status = trim($_GET['trade_status'] ?? '');

// 本地查询订单（展示给用户用，不做业务判断）
$order = null;
if ($out_trade_no !== '') {
    $order = Database::fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$out_trade_no]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支付结果 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div style="min-height: 100vh; background: #f5f7fa; padding: 60px 20px;">
        <div style="max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #e5e6eb; border-radius: 12px; padding: 48px 32px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
            <?php if ($order && in_array($order['status'], ['paid', 'completed'], true)): ?>
                <div style="width: 72px; height: 72px; margin: 0 auto 20px; background: #e8ffea; border-radius: 50%; display:flex; align-items:center; justify-content:center;">
                    <span style="font-size: 40px; color: #00b42a;">✓</span>
                </div>
                <h1 style="font-size: 22px; color: #1d2129; margin-bottom: 12px;">支付成功</h1>
                <p style="color: #4e5969; margin-bottom: 8px;">订单号：<?php echo htmlspecialchars($out_trade_no); ?></p>
                <p style="color: #4e5969; margin-bottom: 20px;">金额：¥<?php echo number_format($order['amount'], 2); ?></p>
                <p style="color: #86909c; font-size: 13px; margin-bottom: 28px;">主机正在为您开通中，请稍后前往"我的主机"查看。</p>

                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="/user/hosts.php" class="btn btn-primary" style="padding: 8px 24px;">查看主机</a>
                    <a href="/user/orders.php" class="btn btn-secondary" style="padding: 8px 24px;">我的订单</a>
                    <a href="/" class="btn btn-secondary" style="padding: 8px 24px;">返回首页</a>
                </div>

            <?php elseif ($order && $order['status'] === 'pending'): ?>
                <div style="width: 72px; height: 72px; margin: 0 auto 20px; background: #fff7e8; border-radius: 50%; display:flex; align-items:center; justify-content:center;">
                    <span style="font-size: 40px; color: #ff7d00;">⏳</span>
                </div>
                <h1 style="font-size: 22px; color: #1d2129; margin-bottom: 12px;">支付处理中</h1>
                <p style="color: #4e5969; margin-bottom: 8px;">订单号：<?php echo htmlspecialchars($out_trade_no); ?></p>
                <p style="color: #86909c; font-size: 13px; margin-bottom: 28px;">如果您已支付，请稍等 1-2 分钟，系统正在处理支付结果。</p>

                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="/user/orders.php" class="btn btn-primary" style="padding: 8px 24px;">查看订单</a>
                    <a href="/" class="btn btn-secondary" style="padding: 8px 24px;">返回首页</a>
                </div>

            <?php else: ?>
                <div style="width: 72px; height: 72px; margin: 0 auto 20px; background: #ffece8; border-radius: 50%; display:flex; align-items:center; justify-content:center;">
                    <span style="font-size: 40px; color: #f53f3f;">!</span>
                </div>
                <h1 style="font-size: 22px; color: #1d2129; margin-bottom: 12px;">无法识别的支付结果</h1>
                <p style="color: #86909c; font-size: 13px; margin-bottom: 28px;">请前往"我的订单"查看订单状态。如已扣款但订单未处理，请联系客服。</p>

                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="/user/orders.php" class="btn btn-primary" style="padding: 8px 24px;">查看订单</a>
                    <a href="/" class="btn btn-secondary" style="padding: 8px 24px;">返回首页</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
