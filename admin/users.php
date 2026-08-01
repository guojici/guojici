<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$search = trim(get('search', ''));
$status = get('status', '');

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (username LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s];
}
if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
}

$users = Database::fetchAll("SELECT * FROM users WHERE $where ORDER BY id DESC LIMIT 100", $params);

// 处理操作
if (is_post()) {
    $action = post('action');
    $uid = intval(post('user_id'));
    if ($action === 'suspend') {
        Database::update('users', ['status' => 'suspended'], 'id = ?', [$uid]);
        flash('success', '用户已暂停');
    } elseif ($action === 'activate') {
        Database::update('users', ['status' => 'active'], 'id = ?', [$uid]);
        flash('success', '用户已恢复');
    } elseif ($action === 'recharge') {
        $amount = floatval(post('amount'));
        $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$uid]);
        Database::update('users', ['balance' => $user['balance'] + $amount], 'id = ?', [$uid]);
        flash('success', '已为用户充值 ¥' . number_format($amount, 2));
    } elseif ($action === 'deduct_balance') {
        $amount = floatval(post('amount'));
        $reason = trim(post('reason', ''));
        $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$uid]);
        if ($user['balance'] < $amount) {
            flash('error', '用户余额不足');
        } else {
            Database::update('users', ['balance' => $user['balance'] - $amount], 'id = ?', [$uid]);
            // 记录余额变动日志
            Database::insert('balance_logs', [
                'user_id' => $uid,
                'type' => 'deduct',
                'amount' => $amount,
                'reason' => $reason ?: '管理员扣除',
                'operator_id' => admin_id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            flash('success', '已扣除用户余额 ¥' . number_format($amount, 2));
        }
    } elseif ($action === 'reset_pwd') {
        $new_pwd = post('new_password');
        Database::update('users', ['password' => password_hash($new_pwd, PASSWORD_DEFAULT)], 'id = ?', [$uid]);
        flash('success', '密码已重置');
    }
    header('Location: /admin/users.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">用户管理</h1>
                    <p class="page-subtitle">管理平台所有用户</p>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索用户名/邮箱/手机号..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/users.php?search='+encodeURIComponent(this.value)+'&status=<?php echo $status; ?>';">
                    </div>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/users.php?status='+this.value+'&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部状态</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>正常</option>
                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>已暂停</option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>用户名</th>
                                <th>邮箱</th>
                                <th>手机</th>
                                <th>实名认证</th>
                                <th>余额</th>
                                <th>状态</th>
                                <th>注册时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo e($u['username']); ?></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td><?php echo e($u['phone'] ?: '-'); ?></td>
                                <td><?php
                                    $vs = intval($u['id_verify_status'] ?? 0);
                                    if ($vs == 1) {
                                        echo '<span style="color: #22c55e; font-size: 12px;">✓ ' . e($u['real_name'] ?? '') . '</span>';
                                    } elseif ($vs == 2) {
                                        echo '<span style="color: #ef4444; font-size: 12px;">✗ 不匹配</span>';
                                    } else {
                                        echo '<span style="color: #eab308; font-size: 12px;">未认证</span>';
                                    }
                                ?></td>
                                <td style="color: var(--accent);">¥<?php echo number_format($u['balance'], 2); ?></td>
                                <td><?php echo get_status_label($u['status'], 'user'); ?></td>
                                <td><?php echo format_date($u['created_at']); ?></td>
                                <td style="white-space: nowrap;">
                                    <button onclick="showRecharge(<?php echo $u['id']; ?>)" class="btn btn-sm btn-primary">充值</button>
                                    <button onclick="showDeduct(<?php echo $u['id']; ?>)" class="btn btn-sm btn-danger">扣款</button>
                                    <?php if (intval($u['id_verify_status'] ?? 0) > 0): ?>
                                    <button onclick='showIdVerify(<?php echo json_encode($u, JSON_UNESCAPED_UNICODE); ?>)' class="btn btn-sm btn-secondary">查看实名</button>
                                    <?php endif; ?>
                                    <?php if ($u['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="suspend">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('确定要暂停此用户吗？')">暂停</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success">恢复</button>
                                        </form>
                                    <?php endif; ?>
                                    <button onclick="showReset(<?php echo $u['id']; ?>)" class="btn btn-sm btn-danger">重置密码</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 充值弹窗 -->
    <div class="modal-overlay" id="rechargeModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>充值余额</h3>
                <button class="modal-close" onclick="document.getElementById('rechargeModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="recharge">
                <input type="hidden" name="user_id" id="recharge_user_id">
                <div class="form-group">
                    <label>充值金额 (元)</label>
                    <input type="number" step="0.01" class="form-control" name="amount" required min="0.01" placeholder="100.00">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认充值</button>
            </form>
        </div>
    </div>

    <!-- 扣除余额弹窗 -->
    <div class="modal-overlay" id="deductModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>扣除余额</h3>
                <button class="modal-close" onclick="document.getElementById('deductModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="deduct_balance">
                <input type="hidden" name="user_id" id="deduct_user_id">
                <div class="form-group">
                    <label>扣除金额 (元)</label>
                    <input type="number" step="0.01" class="form-control" name="amount" required min="0.01" placeholder="100.00">
                </div>
                <div class="form-group">
                    <label>扣除原因</label>
                    <input type="text" class="form-control" name="reason" placeholder="请输入扣除原因">
                </div>
                <button type="submit" class="btn btn-danger" style="width: 100%;">确认扣除</button>
            </form>
        </div>
    </div>

    <!-- 重置密码弹窗 -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>重置密码</h3>
                <button class="modal-close" onclick="document.getElementById('resetModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_pwd">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="form-group">
                    <label>新密码</label>
                    <input type="text" class="form-control" name="new_password" required placeholder="新密码">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认重置</button>
            </form>
        </div>
    </div>

    <!-- 实名信息详情弹窗 -->
    <div class="modal-overlay" id="idVerifyModal">
        <div class="modal-box" style="max-width: 640px;">
            <div class="modal-header">
                <h3>实名认证信息</h3>
                <button class="modal-close" onclick="document.getElementById('idVerifyModal').classList.remove('active')">&times;</button>
            </div>
            <div id="idVerifyContent">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">真实姓名</div>
                        <div style="font-size: 15px; font-weight: 500;" id="iv_realname">-</div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">身份证号</div>
                        <div style="font-size: 15px; font-weight: 500;" id="iv_idcard">-</div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">认证状态</div>
                        <div id="iv_status">-</div>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">认证时间</div>
                        <div style="font-size: 14px;" id="iv_time">-</div>
                    </div>
                </div>
                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">身份证正面（人像面）</div>
                <div id="iv_front_img_wrap" style="margin-bottom: 16px;">
                    <img id="iv_front_img" src="" style="max-width: 100%; max-height: 240px; border-radius: 8px; border: 1px solid var(--border); display: none;">
                    <div id="iv_front_none" style="padding: 24px; background: var(--bg-secondary); border-radius: 8px; text-align: center; color: var(--text-secondary); font-size: 13px;">未上传</div>
                </div>
                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">身份证反面（国徽面）</div>
                <div id="iv_back_img_wrap">
                    <img id="iv_back_img" src="" style="max-width: 100%; max-height: 240px; border-radius: 8px; border: 1px solid var(--border); display: none;">
                    <div id="iv_back_none" style="padding: 24px; background: var(--bg-secondary); border-radius: 8px; text-align: center; color: var(--text-secondary); font-size: 13px;">未上传</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showRecharge(id) {
            document.getElementById('recharge_user_id').value = id;
            document.getElementById('rechargeModal').classList.add('active');
        }
        function showDeduct(id) {
            document.getElementById('deduct_user_id').value = id;
            document.getElementById('deductModal').classList.add('active');
        }
        function showReset(id) {
            document.getElementById('reset_user_id').value = id;
            document.getElementById('resetModal').classList.add('active');
        }
        function showIdVerify(user) {
            document.getElementById('iv_realname').textContent = user.real_name || '-';
            document.getElementById('iv_idcard').textContent = user.id_card || '-';
            var statusMap = {'0': '<span style="color:#eab308">未认证</span>', '1': '<span style="color:#22c55e">✓ 已认证</span>', '2': '<span style="color:#ef4444">✗ 不匹配</span>'};
            document.getElementById('iv_status').innerHTML = statusMap[String(user.id_verify_status || 0)] || '-';
            document.getElementById('iv_time').textContent = user.id_verify_time || '-';

            var frontImg = document.getElementById('iv_front_img');
            var frontNone = document.getElementById('iv_front_none');
            if (user.idcard_front_img) {
                frontImg.src = '/' + user.idcard_front_img;
                frontImg.style.display = 'block';
                frontNone.style.display = 'none';
            } else {
                frontImg.style.display = 'none';
                frontNone.style.display = 'block';
            }

            var backImg = document.getElementById('iv_back_img');
            var backNone = document.getElementById('iv_back_none');
            if (user.idcard_back_img) {
                backImg.src = '/' + user.idcard_back_img;
                backImg.style.display = 'block';
                backNone.style.display = 'none';
            } else {
                backImg.style.display = 'none';
                backNone.style.display = 'block';
            }

            document.getElementById('idVerifyModal').classList.add('active');
        }
    </script>
</body>
</html>
