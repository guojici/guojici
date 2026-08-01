<?php
/**
 * 用户余额充值页面
 */
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/pay/epay.php';
require_auth();

$user = auth_user();
$uid = auth_id();

$page_title = '余额充值';

// 获取易支付配置（合并默认配置和数据库配置）
$epay_config = epay_get_config();
$epay_enabled = !empty($epay_config['enabled']) && !empty($epay_config['api_url']) && !empty($epay_config['pid']) && !empty($epay_config['key']);

// 处理充值请求
if (is_post()) {
    $amount = floatval(post('amount', 0));
    $payment_method = post('payment_method', '');
    
    if ($amount <= 0) {
        flash('error', '请输入有效的充值金额');
    } elseif ($amount < 1) {
        flash('error', '最低充值金额为1元');
    } elseif (!$payment_method) {
        flash('error', '请选择支付方式');
    } elseif (!$epay_enabled) {
        flash('error', '在线支付暂未开通，请联系管理员');
    } else {
        // 创建充值订单
        $order_no = 'RC' . date('YmdHis') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        Database::insert('user_recharges', [
            'user_id' => $uid,
            'order_no' => $order_no,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        
        // 生成支付链接
        $base_url = get_base_url();
        $notify_url = !empty($epay_config['notify_url']) ? $epay_config['notify_url'] : ($base_url . '/pay/notify.php');
        $return_url = !empty($epay_config['return_url']) ? $epay_config['return_url'] : ($base_url . '/pay/return.php');
        
        // 易支付API地址需要加上 /mapi.php
        $api_url = rtrim($epay_config['api_url'], '/');
        
        $epay_data = [
            'pid' => $epay_config['pid'],
            'type' => $payment_method,
            'out_trade_no' => $order_no,
            'notify_url' => $notify_url,
            'return_url' => $return_url,
            'name' => '余额充值',
            'money' => $amount,
        ];
        
        $sign_type = $epay_config['sign_type'] ?? 'md5';
        $sign = epay_generate_sign($epay_data, $epay_config['key'], $sign_type);
        $epay_data['sign'] = $sign;
        $epay_data['sign_type'] = $sign_type;
        
        // 易支付标准接口路径
        $pay_url = $api_url . '/mapi.php?' . http_build_query($epay_data);
        
        // 跳转支付
        header('Location: ' . $pay_url);
        exit;
    }
    
    header('Location: /user/recharge.php');
    exit;
}

// 获取充值记录
$recharges = Database::fetchAll(
    "SELECT * FROM user_recharges WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
    [$uid]
);

require_once __DIR__ . '/templates/navbar.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .recharge-card { background: #fff; border-radius: 12px; padding: 32px; }
        .balance-info { background: linear-gradient(135deg, #165dff, #00b42a); color: #fff; padding: 24px; border-radius: 12px; margin-bottom: 24px; }
        .balance-label { font-size: 14px; opacity: 0.8; }
        .balance-value { font-size: 40px; font-weight: 700; }
        .amount-options { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
        .amount-option { padding: 16px; border: 2px solid #e5e8eb; border-radius: 8px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .amount-option:hover { border-color: #165dff; }
        .amount-option.selected { border-color: #165dff; background: #e6f4ff; }
        .amount-option .value { font-size: 18px; font-weight: 600; color: #1d2129; }
        .amount-option .label { font-size: 12px; color: #86909c; margin-top: 4px; }
        .payment-methods { display: flex; gap: 16px; margin-bottom: 24px; }
        .payment-method { flex: 1; padding: 20px; border: 2px solid #e5e8eb; border-radius: 8px; text-align: center; cursor: pointer; transition: all 0.2s; }
        .payment-method:hover { border-color: #165dff; }
        .payment-method.selected { border-color: #165dff; background: #e6f4ff; }
        .payment-icon { font-size: 32px; margin-bottom: 8px; }
        .payment-name { font-size: 14px; font-weight: 600; }
        .custom-amount { margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 600px; padding: 20px;">
        <?php echo render_flash(); ?>
        
        <div class="recharge-card">
            <h1 style="font-size: 24px; margin: 0 0 8px;">余额充值</h1>
            <div style="color: #86909c; font-size: 14px;">为您的账户充值，方便快捷消费</div>
            
            <!-- 当前余额 -->
            <div class="balance-info">
                <div class="balance-label">当前账户余额</div>
                <div class="balance-value">¥<?php echo number_format($user['balance'], 2); ?></div>
            </div>
            
            <!-- 快捷金额 -->
            <h3 style="font-size: 16px; margin: 0 0 12px;">选择充值金额</h3>
            <div class="amount-options">
                <div class="amount-option" onclick="selectAmount(10)">
                    <div class="value">¥10</div>
                    <div class="label">体验充值</div>
                </div>
                <div class="amount-option" onclick="selectAmount(50)">
                    <div class="value">¥50</div>
                    <div class="label">小额充值</div>
                </div>
                <div class="amount-option" onclick="selectAmount(100)">
                    <div class="value">¥100</div>
                    <div class="label">标准充值</div>
                </div>
                <div class="amount-option" onclick="selectAmount(500)">
                    <div class="value">¥500</div>
                    <div class="label">大额充值</div>
                </div>
            </div>
            
            <!-- 自定义金额 -->
            <div class="custom-amount">
                <input type="number" id="custom_amount" class="form-control" placeholder="自定义金额（最低1元）" min="1" step="0.01">
            </div>
            
            <!-- 支付方式 -->
            <h3 style="font-size: 16px; margin: 0 0 12px;">选择支付方式</h3>
            <div class="payment-methods">
                <div class="payment-method" onclick="selectPayment('wxpay')">
                    <div class="payment-icon">💬</div>
                    <div class="payment-name">微信支付</div>
                </div>
                <div class="payment-method" onclick="selectPayment('qqpay')">
                    <div class="payment-icon">🐧</div>
                    <div class="payment-name">QQ钱包</div>
                </div>
            </div>
            
            <!-- 充值按钮 -->
            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="amount" id="form_amount">
                <input type="hidden" name="payment_method" id="form_payment">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px; font-size: 16px;">
                    立即充值
                </button>
            </form>
            
            <?php if (!$epay_enabled): ?>
            <div style="text-align: center; color: #ff7d00; font-size: 13px; margin-top: 16px;">
                ⚠️ 在线支付暂未开通，请联系管理员进行线下充值
            </div>
            <?php endif; ?>
        </div>
        
        <!-- 充值记录 -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-title">充值记录</div>
            <?php if (empty($recharges)): ?>
            <div style="padding: 40px; text-align: center; color: #86909c;">
                <div style="font-size: 32px; margin-bottom: 12px;">📋</div>
                <div>暂无充值记录</div>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>金额</th>
                        <th>支付方式</th>
                        <th>状态</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recharges as $r): ?>
                    <tr>
                        <td><?php echo e($r['order_no']); ?></td>
                        <td style="font-weight: 600;">¥<?php echo number_format($r['amount'], 2); ?></td>
                        <td>
                            <?php 
                                $methods = ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包'];
                                echo $methods[$r['payment_method']] ?? $r['payment_method'];
                            ?>
                        </td>
                        <td>
                            <?php 
                                $status_names = [
                                    'pending' => '<span style="color:#ff7d00;">待支付</span>',
                                    'paid' => '<span style="color:#00b42a;">已完成</span>',
                                    'failed' => '<span style="color:#f53f3f;">失败</span>',
                                    'refunded' => '<span style="color:#86909c;">已退款</span>'
                                ];
                                echo $status_names[$r['status']] ?? $r['status'];
                            ?>
                        </td>
                        <td><?php echo substr($r['created_at'], 0, 16); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        var selected_amount = 0;
        var selected_payment = '';
        
        function selectAmount(amount) {
            selected_amount = amount;
            document.getElementById('custom_amount').value = '';
            document.getElementById('form_amount').value = amount;
            
            document.querySelectorAll('.amount-option').forEach(function(el) {
                el.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }
        
        function selectPayment(method) {
            selected_payment = method;
            document.getElementById('form_payment').value = method;
            
            document.querySelectorAll('.payment-method').forEach(function(el) {
                el.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
        }
        
        document.getElementById('custom_amount').addEventListener('input', function() {
            var val = parseFloat(this.value);
            if (!isNaN(val) && val > 0) {
                selected_amount = val;
                document.getElementById('form_amount').value = val;
                document.querySelectorAll('.amount-option').forEach(function(el) {
                    el.classList.remove('selected');
                });
            }
        });
        
        function validateForm() {
            if (selected_amount <= 0) {
                alert('请选择或输入充值金额');
                return false;
            }
            if (!selected_payment) {
                alert('请选择支付方式');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>