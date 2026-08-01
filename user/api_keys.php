<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

migrate_new_tables();

$uid = auth_id();
$user = auth_user();
$page_title = 'API密钥管理';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $key_name = trim($_POST['key_name'] ?? '');
        $apply_reason = trim($_POST['apply_reason'] ?? '');
        if (!$key_name) {
            flash('error', '请输入密钥名称');
        } else {
            $api_key = 'sk_' . substr(md5($uid . time() . rand()), 0, 32);
            $api_secret = bin2hex(random_bytes(32));
            
            $rate_limit = min(1000, max(10, intval($_POST['rate_limit'] ?? 100)));
            $ip_whitelist = trim($_POST['ip_whitelist'] ?? '');
            
            $id = Database::insert('api_keys', [
                'user_id' => $uid,
                'key_name' => $key_name,
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'status' => 'pending',
                'ip_whitelist' => $ip_whitelist,
                'rate_limit' => $rate_limit,
                'reject_reason' => '',
            ]);
            
            flash('success', 'API密钥申请已提交，请等待管理员审核，审核通过后即可使用');
        }
        header("Location: /user/api_keys.php");
        exit;
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $key = Database::fetch("SELECT status FROM api_keys WHERE id = ? AND user_id = ?", [$id, $uid]);
        if (!$key) {
            flash('error', '密钥不存在');
        } elseif (!in_array($key['status'], ['pending', 'rejected'])) {
            flash('error', '已审核的密钥请联系管理员删除');
        } else {
            Database::query("DELETE FROM api_keys WHERE id = ? AND user_id = ?", [$id, $uid]);
            flash('success', '密钥已删除');
        }
        header("Location: /user/api_keys.php");
        exit;
    }
    
    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $key = Database::fetch("SELECT status FROM api_keys WHERE id = ? AND user_id = ?", [$id, $uid]);
        if ($key && in_array($key['status'], ['active', 'disabled'])) {
            $new_status = $key['status'] === 'active' ? 'disabled' : 'active';
            Database::update('api_keys', ['status' => $new_status], 'id = ? AND user_id = ?', [$id, $uid]);
            flash('success', '状态已更新');
        } else {
            flash('error', '密钥状态不支持此操作');
        }
        header("Location: /user/api_keys.php");
        exit;
    }
}

$keys = Database::fetchAll("SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC", [$uid]);

$call_stats = Database::fetch("SELECT 
    COUNT(*) as total_calls,
    SUM(CASE WHEN status_code = 0 THEN 1 ELSE 0 END) as success_calls
    FROM api_request_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$uid]);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API密钥管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
<div class="main-content">
    <div class="page-header">
        <h2>🔑 API密钥管理</h2>
        <div class="breadcrumb">用户中心 / API密钥</div>
    </div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
    <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-title">
            <span>申请新密钥</span>
            <span style="font-size: 12px; color: var(--text-secondary); font-weight: normal;">申请后需管理员审核通过方可使用</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>密钥名称 <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="key_name" class="form-control" placeholder="如：生产环境、测试环境" required>
                </div>
                <div class="form-group" style="flex: 0 0 150px;">
                    <label>请求限制/分钟</label>
                    <input type="number" name="rate_limit" class="form-control" value="100" min="10" max="1000">
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>IP白名单（选填，逗号分隔）</label>
                    <input type="text" name="ip_whitelist" class="form-control" placeholder="留空表示不限制">
                </div>
            </div>
            <div class="form-group" style="margin-top: 12px;">
                <label>申请理由 <span style="color: var(--danger);">*</span></label>
                <textarea name="apply_reason" class="form-control" rows="2" placeholder="请简要说明使用场景" required style="resize: vertical;"></textarea>
            </div>
            <div style="margin-top: 12px;">
                <button type="submit" class="btn btn-primary">提交申请</button>
            </div>
        </form>
    </div>

    <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($keys); ?></div>
            <div class="stat-label">API密钥数量</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo intval($call_stats['total_calls'] ?? 0); ?></div>
            <div class="stat-label">近30天调用次数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php 
                $total = intval($call_stats['total_calls'] ?? 0);
                $success = intval($call_stats['success_calls'] ?? 0);
                echo $total > 0 ? round($success / $total * 100, 1) : 100; ?>%
            </div>
            <div class="stat-label">成功率</div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>API文档</span>
            <a href="/docs/api-docs.html" target="_blank" style="font-size: 12px; font-weight: normal; color: var(--primary); text-decoration: none; margin-left: 8px;">查看完整HTML文档 →</a>
        </div>
        <div style="padding: 16px; background: rgba(59,130,246,0.05); border-radius: 8px; margin-bottom: 16px;">
            <p style="margin: 0 0 8px 0;"><strong>📡 API入口：</strong><code><?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/api/index.php</code> 或 <code>/api/</code></p>
            <p style="margin: 0 0 8px 0;"><strong>🔐 认证方式：</strong>在请求头中携带 <code>X-API-Key</code>（必填），可选签名认证 <code>X-API-Sign</code></p>
            <p style="margin: 0 0 8px 0;"><strong>📚 主要接口：</strong></p>
            <ul style="margin: 0; padding-left: 20px;">
                <li><code>GET /api/vms</code> - 主机列表</li>
                <li><code>GET /api/vms/{id}</code> - 主机详情</li>
                <li><code>POST /api/vms</code> - 创建主机</li>
                <li><code>POST /api/vms/{id}/start</code> - 启动</li>
                <li><code>POST /api/vms/{id}/stop</code> - 停止</li>
                <li><code>POST /api/vms/{id}/restart</code> - 重启</li>
                <li><code>POST /api/vms/{id}/reinstall</code> - 重装系统</li>
                <li><code>DELETE /api/vms/{id}</code> - 删除主机</li>
                <li><code>POST /api/vms/batch_create</code> - 批量创建</li>
                <li><code>POST /api/vms/batch_start</code> - 批量启动</li>
                <li><code>GET /api/orders</code> - 订单列表</li>
                <li><code>GET /api/billing/records</code> - 账单记录</li>
                <li><code>GET /api/packages</code> - 套餐列表</li>
                <li><code>GET /api/users/info</code> - 用户信息</li>
            </ul>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>密钥列表</span>
            <span style="font-size: 13px; font-weight: normal; color: var(--text-secondary);">共 <?php echo count($keys); ?> 个</span>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>名称</th>
                        <th>API Key</th>
                        <th>状态</th>
                        <th>限流/分钟</th>
                        <th>IP白名单</th>
                        <th>最后使用</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $status_map = [
                        'pending' => ['text' => '待审核', 'class' => 'warning'],
                        'active' => ['text' => '已启用', 'class' => 'success'],
                        'disabled' => ['text' => '已禁用', 'class' => 'secondary'],
                        'rejected' => ['text' => '已拒绝', 'class' => 'danger'],
                    ];
                    foreach ($keys as $k): 
                    $st = $status_map[$k['status']] ?? ['text' => $k['status'], 'class' => 'secondary'];
                    ?>
                    <tr>
                        <td><?php echo $k['id']; ?></td>
                        <td><?php echo e($k['key_name']); ?></td>
                        <td><code style="font-size: 12px;"><?php echo e($k['api_key']); ?></code></td>
                        <td>
                            <span class="badge badge-<?php echo $st['class']; ?>"><?php echo $st['text']; ?></span>
                            <?php if ($k['status'] === 'rejected' && !empty($k['reject_reason'])): ?>
                            <div style="font-size: 11px; color: var(--danger); margin-top: 3px; max-width: 150px;" title="<?php echo e($k['reject_reason']); ?>">
                                原因：<?php echo e(mb_substr($k['reject_reason'], 0, 20)); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($k['rate_limit']); ?></td>
                        <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo $k['ip_whitelist'] ? e($k['ip_whitelist']) : '<span style="color: var(--text-secondary);">不限制</span>'; ?>
                        </td>
                        <td><?php echo $k['last_used_at'] ?? '-'; ?></td>
                        <td><?php echo $k['created_at']; ?></td>
                        <td>
                            <?php if (in_array($k['status'], ['active', 'disabled'])): ?>
                            <form method="POST" style="display: inline; margin-right: 4px;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $k['status'] === 'active' ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $k['status'] === 'active' ? '禁用' : '启用'; ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if (in_array($k['status'], ['pending', 'rejected'])): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('确认删除此申请？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($keys)): ?>
                    <tr><td colspan="9" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无API密钥，请申请</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
    </div>
</body>
</html>
