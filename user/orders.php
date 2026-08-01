<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

// 处理退款申请提交
if (is_post()) {
    $action = post('action');
    if ($action === 'refund_request') {
        $oid = intval(post('order_id'));
        $order = Database::fetch("SELECT * FROM orders WHERE id = ? AND user_id = ?", [$oid, $uid]);
        if (!$order) {
            flash('error', '订单不存在');
            header('Location: /user/orders.php');
            exit;
        }
        if (!in_array($order['status'], ['paid', 'completed'])) {
            flash('error', '当前订单状态不支持申请退款');
            header('Location: /user/orders.php');
            exit;
        }

        // 检查是否已有待处理的退款申请
        $existing = Database::fetch("SELECT id FROM refund_requests WHERE order_id = ? AND user_id = ? AND status = 'pending'", [$oid, $uid]);
        if ($existing) {
            flash('error', '该订单已有待处理的退款申请，请勿重复提交');
            header('Location: /user/orders.php');
            exit;
        }

        $refund_amount = floatval(post('refund_amount', $order['amount']));
        if ($refund_amount <= 0 || $refund_amount > floatval($order['amount'])) {
            $refund_amount = floatval($order['amount']);
        }
        $reason = trim(post('reason', ''));
        if ($reason === '') {
            flash('error', '请填写退款原因');
            header('Location: /user/orders.php');
            exit;
        }

        Database::insert('refund_requests', [
            'order_id' => $oid,
            'user_id' => $uid,
            'host_id' => null,
            'amount' => $refund_amount,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        // 通知所有管理员（写入一条系统通知给首个管理员，或仅记录日志）
        @Database::insert('admin_logs', [
            'admin_id' => 0,
            'action' => 'refund_request_submit',
            'target_type' => 'order',
            'target_id' => $oid,
            'detail' => '用户提交退款申请: 订单 ' . $order['order_no'] . ' 金额 ¥' . number_format($refund_amount, 2) . ' 原因: ' . $reason,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        flash('success', '退款申请已提交，请等待管理员审核');
        header('Location: /user/orders.php');
        exit;
    }
}

// 获取订单及关联的退款申请
$orders = Database::fetchAll("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC", [$uid]);
$order_ids = array_column($orders, 'id');
$refund_map = [];
if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $refunds = Database::fetchAll("SELECT * FROM refund_requests WHERE order_id IN ($placeholders) ORDER BY id DESC", $order_ids);
    foreach ($refunds as $r) {
        if (!isset($refund_map[$r['order_id']])) {
            $refund_map[$r['order_id']] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的订单 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">我的订单</h1>
                    <p class="page-subtitle">查看和管理您的所有订单</p>
                </div>
                <a href="/checkout.php" class="btn btn-primary">购买新主机</a>
            </div>

            <?php
            $error = flash('error');
            $success = flash('success');
            if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <div class="card">
                <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>您还没有任何订单</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>订单号</th>
                                    <th>套餐</th>
                                    <th>时长</th>
                                    <th>金额</th>
                                    <th>优惠券</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>退款状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <?php $refund = $refund_map[$order['id']] ?? null; ?>
                                <tr>
                                    <td><?php echo e($order['order_no']); ?></td>
                                    <td><?php echo e($order['package_name']); ?></td>
                                    <td><?php echo $order['duration']; ?> 个月</td>
                                    <td style="color: var(--accent); font-weight: 600;">
                                        ¥<?php echo number_format($order['amount'], 2); ?>
                                        <?php
                                        $order_discount = floatval($order['discount_amount'] ?? 0);
                                        $order_coupon_code = $order['coupon_code'] ?? '';
                                        if (!empty($order['coupon_id']) && $order_discount > 0):
                                        ?>
                                        <div style="font-size:11px; color:#d46b08; margin-top:2px;" title="券码：<?php echo e($order_coupon_code); ?>">
                                            🎫 -¥<?php echo number_format($order_discount, 2); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($order['coupon_id']) && $order_discount > 0):
                                            echo '<span style="color:#d46b08; font-size:12px;" title="券码：' . e($order_coupon_code) . '">' . e(mb_substr($order_coupon_code, 0, 8)) . '…</span>';
                                        else:
                                            echo '<span style="color:#86909c; font-size:12px;">未使用</span>';
                                        endif;
                                        ?>
                                    </td>
                                    <td><?php echo get_status_label($order['status'], 'order'); ?></td>
                                    <td><?php echo format_date($order['created_at']); ?></td>
                                    <td>
                                        <?php if ($refund): ?>
                                            <?php echo get_status_label($refund['status'], 'refund'); ?>
                                            <?php if ($refund['status'] === 'rejected' && !empty($refund['admin_remark'])): ?>
                                                <div style="font-size:11px; color:#ef4444; margin-top:2px;"><?php echo e($refund['admin_remark']); ?></div>
                                            <?php elseif ($refund['status'] === 'completed'): ?>
                                                <div style="font-size:11px; color:#22c55e; margin-top:2px;">¥<?php echo number_format($refund['amount'], 2); ?> 已退回</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#86909c; font-size:12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($order['status'] == 'pending'): ?>
                                            <a href="/checkout.php?order_id=<?php echo $order['order_no']; ?>" class="btn btn-sm btn-primary">支付</a>
                                        <?php elseif ($order['status'] == 'completed'): ?>
                                            <a href="/user/hosts.php" class="btn btn-sm btn-secondary">查看主机</a>
                                        <?php endif; ?>
                                        <?php if (in_array($order['status'], ['paid', 'completed']) && (!$refund || $refund['status'] === 'rejected')): ?>
                                            <button onclick="showRefundModal(<?php echo $order['id']; ?>, '<?php echo e($order['order_no']); ?>', <?php echo $order['amount']; ?>)" class="btn btn-sm btn-outline">申请退款</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 退款申请弹窗 -->
    <div class="modal-overlay" id="refundModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>申请退款</h3>
                <button class="modal-close" onclick="document.getElementById('refundModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" id="refundForm">
                <input type="hidden" name="action" value="refund_request">
                <input type="hidden" name="order_id" id="refund_order_id">
                <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-bottom:16px;">
                    <div style="font-size:13px; color:#86909c;">订单号</div>
                    <div style="font-weight:600; color:#1d2129;" id="refund_order_no">--</div>
                    <div style="font-size:13px; color:#86909c; margin-top:8px;">订单金额 / 可退金额</div>
                    <div style="font-weight:600; color:#1d2129;">¥<span id="refund_order_amount">0</span> / ¥<span id="refund_max_amount">0</span></div>
                </div>
                <div class="form-group">
                    <label>退款金额（¥）</label>
                    <input type="number" class="form-control" name="refund_amount" id="refund_amount_input" step="0.01" min="0.01" required>
                    <div style="font-size:11px; color:#1677ff; margin-top:4px;">💡 退款金额最高为订单全额，退款将退回您的账户余额</div>
                </div>
                <div class="form-group">
                    <label>退款原因 <span style="color:#ef4444;">*</span></label>
                    <textarea class="form-control" name="reason" rows="4" placeholder="请详细说明退款原因，以便管理员审核" required></textarea>
                </div>
                <div style="padding:12px; background:#fff7e8; border-radius:8px; margin-bottom:16px; font-size:12px; color:#ff7d00;">
                    ⚠️ 提交后请等待管理员审核。审核通过后退款金额将退回您的账户余额。
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;" onclick="return confirm('确定提交退款申请？')">提交退款申请</button>
            </form>
        </div>
    </div>

    <script>
    function showRefundModal(orderId, orderNo, orderAmount) {
        document.getElementById('refund_order_id').value = orderId;
        document.getElementById('refund_order_no').textContent = orderNo;
        document.getElementById('refund_order_amount').textContent = Number(orderAmount).toFixed(2);
        document.getElementById('refund_max_amount').textContent = Number(orderAmount).toFixed(2);
        document.getElementById('refund_amount_input').value = Number(orderAmount).toFixed(2);
        document.getElementById('refund_amount_input').max = orderAmount;
        document.getElementById('refundModal').classList.add('active');
    }
    </script>
</body>
</html>
