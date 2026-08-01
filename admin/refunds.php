<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();
migrate_new_tables();

$search = trim(get('search', ''));
$status = get('status', '');

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (o.order_no LIKE ?)";
    $params[] = "%$search%";
}
if ($status) {
    $where .= " AND r.status = ?";
    $params[] = $status;
}

$refunds = Database::fetchAll(
    "SELECT r.*, o.order_no, o.amount AS order_amount, o.status AS order_status,
            u.username AS user_name, a.username AS admin_name
     FROM refund_requests r
     LEFT JOIN orders o ON r.order_id = o.id
     LEFT JOIN users u ON r.user_id = u.id
     LEFT JOIN admin_users a ON r.admin_id = a.id
     WHERE $where
     ORDER BY r.id DESC LIMIT 200",
    $params
);

if (is_post()) {
    $action = post('action');
    $rid = intval(post('refund_id'));
    $admin_remark = trim(post('admin_remark', ''));
    $refund = Database::fetch("SELECT * FROM refund_requests WHERE id = ?", [$rid]);

    if (!$refund) {
        flash('error', '退款申请不存在');
        header('Location: /admin/refunds.php');
        exit;
    }

    if ($action === 'approve') {
        $order = Database::fetch("SELECT * FROM orders WHERE id = ?", [$refund['order_id']]);
        if (!$order) {
            flash('error', '关联订单不存在');
            header('Location: /admin/refunds.php');
            exit;
        }
        if (!in_array($order['status'], ['paid', 'completed'])) {
            flash('error', '订单状态不支持退款（仅已支付/已完成订单可退款）');
            header('Location: /admin/refunds.php');
            exit;
        }

        $refund_amount = floatval($refund['amount']);
        if ($refund_amount <= 0) {
            flash('error', '退款金额无效');
            header('Location: /admin/refunds.php');
            exit;
        }

        $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$refund['user_id']]);
        if (!$user) {
            flash('error', '用户不存在');
            header('Location: /admin/refunds.php');
            exit;
        }

        $balance_before = floatval($user['balance']);
        $balance_after = $balance_before + $refund_amount;

        Database::beginTransaction();
        try {
            Database::update('users', ['balance' => $balance_after], 'id = ?', [$refund['user_id']]);
            Database::update('orders', ['status' => 'refunded', 'remark' => '退款申请通过: ' . $admin_remark], 'id = ?', [$order['id']]);
            Database::update('refund_requests', [
                'status' => 'completed',
                'admin_id' => admin_user()['id'],
                'admin_remark' => $admin_remark !== '' ? $admin_remark : '同意退款',
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$rid]);

            @Database::insert('billing_records', [
                'user_id' => $refund['user_id'],
                'order_id' => $order['id'],
                'bill_type' => 'refund',
                'amount' => -$refund_amount,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'description' => '退款申请通过: 订单 ' . $order['order_no'] . ' - ' . ($admin_remark !== '' ? $admin_remark : '同意退款'),
                'status' => 'refunded',
            ]);

            @Database::insert('admin_logs', [
                'admin_id' => admin_user()['id'],
                'action' => 'refund_approve',
                'target_type' => 'refund_request',
                'target_id' => $rid,
                'detail' => '同意退款 ¥' . number_format($refund_amount, 2) . ' 订单: ' . $order['order_no'] . ' 备注: ' . $admin_remark,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // ====== 释放订单使用的优惠券（全额退款场景下让用户可以再次使用）======
            // 注意：仅当退款金额 ≥ 用户实付金额（订单整体作废）时才释放优惠券。
            // 部分退款不释放——保留 used 状态避免被重复使用。
            if ($refund_amount >= floatval($order['amount']) && !empty($order['coupon_id'])) {
                release_coupon_for_order($order['id']);
            }

            Database::commit();

            send_notification(
                $refund['user_id'],
                'order',
                '退款申请已通过',
                '您的退款申请已通过审核。订单 ' . $order['order_no'] . ' 已退款 ¥' . number_format($refund_amount, 2) . '，金额已退回您的账户余额。' . ($admin_remark !== '' ? '管理员备注：' . $admin_remark : ''),
                'order',
                $order['id']
            );

            flash('success', '已同意退款 ¥' . number_format($refund_amount, 2) . '（余额已退回，已记录日志并通知用户）');
        } catch (Exception $e) {
            Database::rollBack();
            flash('error', '退款处理失败: ' . $e->getMessage());
        }
    } elseif ($action === 'reject') {
        if ($admin_remark === '') {
            $admin_remark = '管理员拒绝';
        }
        Database::beginTransaction();
        try {
            Database::update('refund_requests', [
                'status' => 'rejected',
                'admin_id' => admin_user()['id'],
                'admin_remark' => $admin_remark,
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$rid]);

            @Database::insert('admin_logs', [
                'admin_id' => admin_user()['id'],
                'action' => 'refund_reject',
                'target_type' => 'refund_request',
                'target_id' => $rid,
                'detail' => '拒绝退款申请 ID: ' . $rid . ' 原因: ' . $admin_remark,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            Database::commit();

            $order = Database::fetch("SELECT order_no FROM orders WHERE id = ?", [$refund['order_id']]);
            $order_no = $order['order_no'] ?? ('#' . $refund['order_id']);
            send_notification(
                $refund['user_id'],
                'order',
                '退款申请已拒绝',
                '您的退款申请已被拒绝。订单 ' . $order_no . '。原因：' . $admin_remark,
                'order',
                $refund['order_id']
            );

            flash('success', '已拒绝退款申请并通知用户');
        } catch (Exception $e) {
            Database::rollBack();
            flash('error', '拒绝失败: ' . $e->getMessage());
        }
    }
    header('Location: /admin/refunds.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>退款申请管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include '_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">退款申请管理</h1>
                    <p class="page-subtitle">审核并处理用户提交的退款申请</p>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索订单号..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/refunds.php?search='+encodeURIComponent(this.value)+'&status=<?php echo urlencode($status); ?>';">
                    </div>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/refunds.php?status='+this.value+'&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部状态</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>待处理</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>已批准</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>已拒绝</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>已完成</option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>订单号</th>
                                <th>用户</th>
                                <th>退款金额</th>
                                <th>退款原因</th>
                                <th>状态</th>
                                <th>申请时间</th>
                                <th>处理时间</th>
                                <th>管理员备注</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($refunds)): ?>
                            <tr><td colspan="10" style="text-align:center; color:#86909c; padding:24px;">暂无退款申请记录</td></tr>
                            <?php else: ?>
                            <?php foreach ($refunds as $r): ?>
                            <tr>
                                <td><?php echo intval($r['id']); ?></td>
                                <td><?php echo e($r['order_no'] ?? '-'); ?></td>
                                <td><?php echo e($r['user_name'] ?? '-'); ?></td>
                                <td style="color: var(--accent); font-weight: 600;">¥<?php echo number_format($r['amount'], 2); ?></td>
                                <td style="max-width:240px; white-space:normal; word-break:break-all;"><?php echo e($r['reason']); ?></td>
                                <td><?php echo get_status_label($r['status'], 'refund'); ?></td>
                                <td><?php echo format_date($r['created_at']); ?></td>
                                <td><?php echo format_date($r['processed_at']); ?></td>
                                <td style="max-width:200px; white-space:normal; word-break:break-all;"><?php echo e($r['admin_remark']); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <button onclick="showApproveModal(<?php echo intval($r['id']); ?>, '<?php echo e($r['order_no'] ?? ''); ?>', '<?php echo e($r['user_name'] ?? ''); ?>', <?php echo floatval($r['amount']); ?>)" class="btn btn-sm btn-danger">同意退款</button>
                                        <button onclick="showRejectModal(<?php echo intval($r['id']); ?>, '<?php echo e($r['order_no'] ?? ''); ?>', '<?php echo e($r['user_name'] ?? ''); ?>')" class="btn btn-sm btn-secondary">拒绝</button>
                                    <?php else: ?>
                                        <span style="color:#86909c; font-size:12px;">已处理</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 同意退款弹窗 -->
    <div class="modal-overlay" id="approveModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>同意退款</h3>
                <button class="modal-close" onclick="document.getElementById('approveModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" id="approveForm">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="refund_id" id="approve_refund_id">
                <div style="padding: 0 0 16px;">
                    <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-bottom:16px;">
                        <div style="font-size:13px; color:#86909c;">订单号</div>
                        <div style="font-weight:600; color:#1d2129;" id="approve_order_no">--</div>
                        <div style="font-size:13px; color:#86909c; margin-top:8px;">用户 / 退款金额</div>
                        <div style="font-weight:600; color:#1d2129;"><span id="approve_user_name">--</span> / ¥<span id="approve_amount">0</span></div>
                    </div>
                    <div class="form-group">
                        <label>管理员备注（可选）</label>
                        <textarea class="form-control" name="admin_remark" rows="3" placeholder="同意退款备注，如不填默认为「同意退款」"></textarea>
                    </div>
                </div>
                <div style="padding:12px; background:#fff7e8; border-radius:8px; margin-bottom:16px; font-size:12px; color:#ff7d00;">
                    ⚠️ 确认后退款金额将退回用户余额，订单状态变为「已退款」，并记录到财务日志与管理员操作日志。
                </div>
                <button type="submit" class="btn btn-danger" style="width:100%;" onclick="return confirm('确定同意此退款申请？此操作不可撤销。')">确认同意退款</button>
            </form>
        </div>
    </div>

    <!-- 拒绝退款弹窗 -->
    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>拒绝退款申请</h3>
                <button class="modal-close" onclick="document.getElementById('rejectModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="refund_id" id="reject_refund_id">
                <div style="padding: 0 0 16px;">
                    <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-bottom:16px;">
                        <div style="font-size:13px; color:#86909c;">订单号 / 用户</div>
                        <div style="font-weight:600; color:#1d2129;"><span id="reject_order_no">--</span> / <span id="reject_user_name">--</span></div>
                    </div>
                    <div class="form-group">
                        <label>拒绝原因</label>
                        <textarea class="form-control" name="admin_remark" rows="3" placeholder="请输入拒绝原因（必填）" required></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary" style="width:100%;" onclick="return confirm('确定拒绝此退款申请？')">确认拒绝</button>
            </form>
        </div>
    </div>

    <script>
    function showApproveModal(id, orderNo, userName, amount) {
        document.getElementById('approve_refund_id').value = id;
        document.getElementById('approve_order_no').textContent = orderNo || ('#' + id);
        document.getElementById('approve_user_name').textContent = userName || '-';
        document.getElementById('approve_amount').textContent = Number(amount).toFixed(2);
        document.getElementById('approveModal').classList.add('active');
    }
    function showRejectModal(id, orderNo, userName) {
        document.getElementById('reject_refund_id').value = id;
        document.getElementById('reject_order_no').textContent = orderNo || ('#' + id);
        document.getElementById('reject_user_name').textContent = userName || '-';
        document.getElementById('rejectModal').classList.add('active');
    }
    </script>
</body>
</html>
