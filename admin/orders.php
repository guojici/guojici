<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$search = trim(get('search', ''));
$status = get('status', '');

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (order_no LIKE ? OR package_name LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
}
if ($status) {
    $where .= " AND o.status = ?";
    $params[] = $status;
}

$orders = Database::fetchAll("SELECT o.*, u.username as user_name FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE $where ORDER BY o.id DESC LIMIT 200", $params);

if (is_post()) {
    $action = post('action');
    $oid = intval(post('order_id'));
    $order = Database::fetch("SELECT * FROM orders WHERE id = ?", [$oid]);
    if (!$order) {
        flash('error', '订单不存在');
        header('Location: /admin/orders.php');
        exit;
    }
    if ($action === 'cancel') {
        Database::update('orders', ['status' => 'cancelled'], 'id = ?', [$oid]);
        flash('success', '订单已取消');
    } elseif ($action === 'refund') {
        $refund_reason = trim(post('refund_reason', '管理员手动退款'));
        $suspend_host = post('suspend_host') === '1';
        $refund_amount = floatval(post('refund_amount', $order['amount']));
        if ($refund_amount <= 0 || $refund_amount > $order['amount']) {
            $refund_amount = $order['amount'];
        }
        $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$order['user_id']]);
        $balance_before = floatval($user['balance']);
        $balance_after = $balance_before + $refund_amount;
        Database::beginTransaction();
        try {
            Database::update('users', ['balance' => $balance_after], 'id = ?', [$order['user_id']]);
            Database::update('orders', ['status' => 'refunded', 'remark' => '退款: ' . $refund_reason], 'id = ?', [$oid]);
            @Database::insert('billing_records', [
                'user_id' => $order['user_id'],
                'order_id' => $oid,
                'bill_type' => 'refund',
                'amount' => -$refund_amount,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'description' => '订单退款: ' . $order['order_no'] . ' - ' . $refund_reason,
                'status' => 'refunded',
            ]);
            @Database::insert('admin_logs', [
                'admin_id' => admin_user()['id'],
                'action' => 'order_refund',
                'target_type' => 'order',
                'target_id' => $oid,
                'detail' => '退款 ¥' . number_format($refund_amount, 2) . ' 原因: ' . $refund_reason,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            if ($suspend_host) {
                $hosts = Database::fetchAll("SELECT * FROM hosts WHERE order_id = ? AND status IN ('running','creating')", [$oid]);
                foreach ($hosts as $h) {
                    Database::update('hosts', ['status' => 'suspended'], 'id = ?', [$h['id']]);
                }
            }
            Database::commit();
            send_notification($order['user_id'], 'order', '退款成功',
                '订单 ' . $order['order_no'] . ' 已退款 ¥' . number_format($refund_amount, 2) . '，原因：' . $refund_reason . '。退款已退回您的账户余额。',
                'order', $oid);
            flash('success', '已退款 ¥' . number_format($refund_amount, 2) . '（余额已退回，已记录日志并通知用户）');
        } catch (Exception $e) {
            Database::rollBack();
            flash('error', '退款失败: ' . $e->getMessage());
        }
    }
    header('Location: /admin/orders.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>订单管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">订单管理</h1>
                    <p class="page-subtitle">管理所有订单 <a href="/admin/refunds.php" class="btn btn-sm btn-outline" style="margin-left:8px;">退款申请管理</a></p>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索订单号/套餐名..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/orders.php?search='+encodeURIComponent(this.value)+'&status=<?php echo $status; ?>';">
                    </div>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/orders.php?status='+this.value+'&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部状态</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>待支付</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>已支付</option>
                        <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>处理中</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>已完成</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                        <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>已退款</option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>订单号</th>
                                <th>用户</th>
                                <th>套餐</th>
                                <th>时长</th>
                                <th>金额</th>
                                <th>状态</th>
                                <th>支付时间</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?php echo e($o['order_no']); ?></td>
                                <td><?php echo e($o['user_name']); ?></td>
                                <td><?php echo e($o['package_name']); ?></td>
                                <td><?php echo $o['duration']; ?> 月</td>
                                <td style="color: var(--accent); font-weight: 600;">¥<?php echo number_format($o['amount'], 2); ?></td>
                                <td><?php echo get_status_label($o['status'], 'order'); ?></td>
                                <td><?php echo $o['paid_at'] ? format_date($o['paid_at']) : '-'; ?></td>
                                <td><?php echo format_date($o['created_at']); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if ($o['status'] == 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('确定取消此订单？')">取消</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($o['status'], ['paid','completed'])): ?>
                                        <button onclick="showRefundModal(<?php echo $o['id']; ?>, '<?php echo e($o['order_no']); ?>', <?php echo $o['amount']; ?>)" class="btn btn-sm btn-danger">退款</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 退款弹窗 -->
    <div class="modal-overlay" id="refundModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>订单退款</h3>
                <button class="modal-close" onclick="document.getElementById('refundModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" id="refundForm">
                <input type="hidden" name="action" value="refund">
                <input type="hidden" name="order_id" id="refund_order_id">
                <div style="padding: 0 0 16px;">
                    <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-bottom:16px;">
                        <div style="font-size:13px; color:#86909c;">订单号</div>
                        <div style="font-weight:600; color:#1d2129;" id="refund_order_no">--</div>
                    </div>
                    <div class="form-group">
                        <label>退款金额（¥）</label>
                        <input type="number" step="0.01" class="form-control" name="refund_amount" id="refund_amount" required>
                        <div style="font-size:11px; color:#86909c; margin-top:4px;">最大可退金额：<span id="refund_max">0</span> 元</div>
                    </div>
                    <div class="form-group">
                        <label>退款原因</label>
                        <select class="form-control" name="refund_reason" id="refund_reason_select" onchange="if(this.value==='自定义') document.getElementById('refund_reason_custom').style.display='block'; else document.getElementById('refund_reason_custom').style.display='none';">
                            <option value="服务质量不满意">服务质量不满意</option>
                            <option value="产品无法正常使用">产品无法正常使用</option>
                            <option value="用户误购">用户误购</option>
                            <option value="重复购买">重复购买</option>
                            <option value="用户主动申请退款">用户主动申请退款</option>
                            <option value="自定义">自定义原因</option>
                        </select>
                        <input type="text" class="form-control" name="refund_reason_custom" id="refund_reason_custom" placeholder="请输入退款原因" style="display:none; margin-top:8px;">
                    </div>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="suspend_host" value="1" checked>
                            <span style="font-size:13px;">同时暂停关联的主机</span>
                        </label>
                    </div>
                </div>
                <div style="padding:12px 0; background:#fff7e8; border-radius:8px; padding:12px; margin-bottom:16px; font-size:12px; color:#ff7d00;">
                    ⚠️ 退款后金额将退回用户余额，订单状态变为「已退款」，且记录到财务日志和管理员操作日志。
                </div>
                <button type="submit" class="btn btn-danger" style="width:100%;" onclick="return confirmRefund()">确认退款</button>
            </form>
        </div>
    </div>

    <script>
    function showRefundModal(id, orderNo, amount) {
        document.getElementById('refund_order_id').value = id;
        document.getElementById('refund_order_no').textContent = orderNo;
        document.getElementById('refund_amount').value = amount;
        document.getElementById('refund_amount').max = amount;
        document.getElementById('refund_max').textContent = amount.toFixed(2);
        document.getElementById('refundModal').classList.add('active');
    }
    function confirmRefund() {
        var reasonSel = document.getElementById('refund_reason_select');
        var reason = reasonSel.value;
        if (reason === '自定义') {
            var custom = document.getElementById('refund_reason_custom');
            if (!custom.value.trim()) {
                alert('请输入退款原因');
                return false;
            }
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'refund_reason';
            hidden.value = custom.value.trim();
            document.getElementById('refundForm').appendChild(hidden);
            reasonSel.removeAttribute('name');
        }
        return confirm('确定执行退款操作？此操作不可撤销。');
    }
    </script>
</body>
</html>
