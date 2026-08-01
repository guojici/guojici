<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

migrate_new_tables();

$page = max(1, intval($_GET['page'] ?? 1));
$page_size = 20;
$offset = ($page - 1) * $page_size;

$status = $_GET['status'] ?? 'pending';
$keyword = trim($_GET['keyword'] ?? '');

$where = "WHERE 1=1";
$params = [];

if ($status) {
    $where .= " AND ak.status = ?";
    $params[] = $status;
}
if ($keyword) {
    $where .= " AND (ak.key_name LIKE ? OR ak.api_key LIKE ? OR u.username LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$total = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys ak 
    LEFT JOIN users u ON ak.user_id = u.id
    $where", $params);

$keys = Database::fetchAll("SELECT ak.*, u.username 
    FROM api_keys ak 
    LEFT JOIN users u ON ak.user_id = u.id
    $where
    ORDER BY ak.id DESC LIMIT ? OFFSET ?", 
    array_merge($params, [$page_size, $offset]));

$total_pages = ceil(intval($total['cnt']) / $page_size);

$pending_count = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys WHERE status = 'pending'");

$admin = admin_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $key_id = intval($_POST['id'] ?? 0);
    
    if ($key_id <= 0) {
        flash('error', '无效的密钥ID');
        header("Location: /admin/api_keys.php");
        exit;
    }
    
    $key = Database::fetch("SELECT ak.*, u.username FROM api_keys ak LEFT JOIN users u ON ak.user_id = u.id WHERE ak.id = ?", [$key_id]);
    if (!$key) {
        flash('error', '密钥不存在');
        header("Location: /admin/api_keys.php");
        exit;
    }
    
    if ($action === 'approve') {
        Database::update('api_keys', [
            'status' => 'active',
            'review_by' => $admin['id'],
            'review_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$key_id]);
        
        send_notification($key['user_id'], 'system', 
            'API密钥审核通过', 
            "您的API密钥「{$key['key_name']}」已通过审核，现在可以正常使用",
            'api_key', $key_id);
        
        flash('success', '审核通过');
    }
    
    if ($action === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');
        if (!$reason) {
            flash('error', '请填写拒绝原因');
            header("Location: /admin/api_keys.php?id={$key_id}&action=reject");
            exit;
        }
        
        Database::update('api_keys', [
            'status' => 'rejected',
            'review_by' => $admin['id'],
            'review_at' => date('Y-m-d H:i:s'),
            'reject_reason' => $reason,
        ], 'id = ?', [$key_id]);
        
        send_notification($key['user_id'], 'system', 
            'API密钥审核未通过', 
            "您的API密钥「{$key['key_name']}」审核未通过。原因：{$reason}",
            'api_key', $key_id);
        
        flash('success', '已拒绝');
    }
    
    if ($action === 'disable') {
        Database::update('api_keys', ['status' => 'disabled'], 'id = ?', [$key_id]);
        flash('success', '已禁用');
    }
    
    if ($action === 'enable') {
        Database::update('api_keys', ['status' => 'active'], 'id = ?', [$key_id]);
        flash('success', '已启用');
    }
    
    if ($action === 'delete') {
        Database::query("DELETE FROM api_keys WHERE id = ?", [$key_id]);
        flash('success', '已删除');
    }
    
    header("Location: /admin/api_keys.php?status=" . urlencode($status));
    exit;
}

$status_map = [
    'pending' => ['text' => '待审核', 'class' => 'warning'],
    'active' => ['text' => '已启用', 'class' => 'success'],
    'disabled' => ['text' => '已禁用', 'class' => 'secondary'],
    'rejected' => ['text' => '已拒绝', 'class' => 'danger'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API密钥审核 - <?php echo config('app.name'); ?>管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h2>🔑 API密钥审核</h2>
        <div class="breadcrumb">管理后台 / API密钥审核</div>
    </div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
    <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card" style="border-left: 4px solid #f59e0b;">
            <div class="stat-value" style="color: #f59e0b;"><?php echo intval($pending_count['cnt']); ?></div>
            <div class="stat-label">待审核</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php 
                $all_count = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys");
                echo intval($all_count['cnt']);
                ?>
            </div>
            <div class="stat-label">密钥总数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #22c55e;">
                <?php 
                $active_count = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys WHERE status = 'active'");
                echo intval($active_count['cnt']);
                ?>
            </div>
            <div class="stat-label">已启用</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #ef4444;">
                <?php 
                $rej_count = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys WHERE status = 'rejected'");
                echo intval($rej_count['cnt']);
                ?>
            </div>
            <div class="stat-label">已拒绝</div>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>状态</label>
                <select name="status" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($status_map as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $status == $k ? 'selected' : ''; ?>>
                        <?php echo $v['text']; ?>
                        <?php if ($k === 'pending'): ?>(<?php echo intval($pending_count['cnt']); ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>关键词</label>
                <input type="text" name="keyword" value="<?php echo e($keyword); ?>" class="form-control" placeholder="密钥名称/Key/用户名">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">
            <span>密钥列表</span>
            <span style="font-size: 13px; font-weight: normal; color: var(--text-secondary);">共 <?php echo intval($total['cnt']); ?> 条</span>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户</th>
                        <th>密钥名称</th>
                        <th>API Key</th>
                        <th>状态</th>
                        <th>限流/分钟</th>
                        <th>IP白名单</th>
                        <th>审核人</th>
                        <th>审核时间</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k): 
                    $st = $status_map[$k['status']] ?? ['text' => $k['status'], 'class' => 'secondary'];
                    ?>
                    <tr>
                        <td><?php echo $k['id']; ?></td>
                        <td><?php echo e($k['username'] ?? '-'); ?></td>
                        <td><strong><?php echo e($k['key_name']); ?></strong></td>
                        <td><code style="font-size: 11px;"><?php echo e($k['api_key']); ?></code></td>
                        <td>
                            <span class="badge badge-<?php echo $st['class']; ?>"><?php echo $st['text']; ?></span>
                            <?php if ($k['status'] === 'rejected' && !empty($k['reject_reason'])): ?>
                            <div style="font-size: 11px; color: var(--danger); margin-top: 3px; max-width: 150px;" title="<?php echo e($k['reject_reason']); ?>">
                                原因：<?php echo e(mb_substr($k['reject_reason'], 0, 15)); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($k['rate_limit']); ?></td>
                        <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;">
                            <?php echo $k['ip_whitelist'] ? e($k['ip_whitelist']) : '<span style="color: var(--text-secondary);">不限制</span>'; ?>
                        </td>
                        <td><?php echo e($k['review_by'] ? '#' . $k['review_by'] : '-'); ?></td>
                        <td><?php echo $k['review_at'] ?? '-'; ?></td>
                        <td><?php echo $k['created_at']; ?></td>
                        <td style="white-space: nowrap;">
                            <?php if ($k['status'] === 'pending'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success" style="padding: 2px 8px; font-size: 12px;" onclick="return confirm('确认通过审核？')">通过</button>
                            </form>
                            <button onclick="showRejectModal(<?php echo $k['id']; ?>)" class="btn btn-sm btn-danger" style="padding: 2px 8px; font-size: 12px;">拒绝</button>
                            <?php endif; ?>
                            
                            <?php if ($k['status'] === 'active'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-warning" style="padding: 2px 8px; font-size: 12px;">禁用</button>
                            </form>
                            <?php endif; ?>
                            
                            <?php if ($k['status'] === 'disabled'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-success" style="padding: 2px 8px; font-size: 12px;">启用</button>
                            </form>
                            <?php endif; ?>
                            
                            <form method="POST" style="display: inline;" onsubmit="return confirm('确认删除此密钥？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 8px; font-size: 12px;">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($keys)): ?>
                    <tr><td colspan="11" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="page-btn active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?status=<?php echo urlencode($status); ?>&keyword=<?php echo urlencode($keyword); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="rejectModal" style="display:none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-primary); border-radius: 8px; padding: 24px; width: 400px; max-width: 90%;">
        <h3 style="margin: 0 0 16px 0;">拒绝申请</h3>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="rejectId" value="">
            <div class="form-group">
                <label>拒绝原因 <span style="color: var(--danger);">*</span></label>
                <textarea name="reject_reason" class="form-control" rows="3" required placeholder="请填写拒绝原因" style="resize: vertical;"></textarea>
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">取消</button>
                <button type="submit" class="btn btn-danger">确认拒绝</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectModal').style.display = 'flex';
}
function hideRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
</script>
    </div>
</body>
</html>
