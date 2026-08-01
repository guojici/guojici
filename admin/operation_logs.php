<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$page_title = '操作日志';
$active_menu = 'logs';

$page = max(1, intval($_GET['page'] ?? 1));
$page_size = 30;
$offset = ($page - 1) * $page_size;

$log_type = $_GET['type'] ?? '';
$username = $_GET['username'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($log_type) {
    $where .= " AND al.target_type = ?";
    $params[] = $log_type;
}

if ($username) {
    $where .= " AND u.username LIKE ?";
    $params[] = "%{$username}%";
}

$total = Database::fetch("SELECT COUNT(*) as cnt FROM admin_logs al 
    LEFT JOIN admin_users u ON al.admin_id = u.id
    $where", $params);

$logs = Database::fetchAll("SELECT al.*, u.username as admin_name 
    FROM admin_logs al 
    LEFT JOIN admin_users u ON al.admin_id = u.id
    $where
    ORDER BY al.id DESC LIMIT ? OFFSET ?", 
    array_merge($params, [$page_size, $offset]));

$total_pages = ceil(intval($total['cnt']) / $page_size);

$type_map = [
    'user' => '用户管理',
    'order' => '订单管理',
    'host' => '主机管理',
    'package' => '套餐管理',
    'system' => '系统设置',
    'node' => '节点管理',
    'other' => '其他',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作日志 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
<div class="main-content">
    <div class="page-header">
        <h2>📝 操作日志</h2>
        <div class="breadcrumb">首页 / 操作日志</div>
    </div>

    <div class="card">
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>操作类型</label>
                <select name="type" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($type_map as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $log_type == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>管理员</label>
                <input type="text" name="username" value="<?php echo e($username); ?>" class="form-control" placeholder="管理员用户名">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">
            <span>日志列表</span>
            <span style="font-size: 13px; font-weight: normal; color: var(--text-secondary);">共 <?php echo intval($total['cnt']); ?> 条</span>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>管理员</th>
                        <th>类型</th>
                        <th>操作</th>
                        <th>详情</th>
                        <th>IP</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo $log['id']; ?></td>
                        <td><?php echo e($log['admin_name'] ?? '-'); ?></td>
                        <td><span class="badge badge-info"><?php echo $type_map[$log['target_type']] ?? $log['target_type'] ?? '-'; ?></span></td>
                        <td><?php echo e($log['action'] ?? ''); ?></td>
                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo e($log['detail'] ?? ''); ?>
                        </td>
                        <td><?php echo e($log['ip'] ?? ''); ?></td>
                        <td><?php echo $log['created_at']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="7" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无日志</td></tr>
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
                    <a href="?type=<?php echo urlencode($log_type); ?>&username=<?php echo urlencode($username); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
    </div>
</body>
</html>
