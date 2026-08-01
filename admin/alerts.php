<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

migrate_new_tables();

$page_title = '告警中心';
$active_menu = 'alerts';

$page = max(1, intval($_GET['page'] ?? 1));
$page_size = 30;
$offset = ($page - 1) * $page_size;

$alert_type = $_GET['alert_type'] ?? '';
$status = $_GET['status'] ?? 'active';
$level = $_GET['level'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($alert_type) {
    $where .= " AND alert_type = ?";
    $params[] = $alert_type;
}
if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
}
if ($level) {
    $where .= " AND alert_level = ?";
    $params[] = $level;
}

$total = Database::fetch("SELECT COUNT(*) as cnt FROM node_alerts $where", $params);
$alerts = Database::fetchAll("SELECT * FROM node_alerts $where ORDER BY id DESC LIMIT ? OFFSET ?", 
    array_merge($params, [$page_size, $offset]));

$total_pages = ceil(intval($total['cnt']) / $page_size);

$active_count = Database::fetch("SELECT COUNT(*) as cnt FROM node_alerts WHERE status = 'active'");
$warning_count = Database::fetch("SELECT COUNT(*) as cnt FROM node_alerts WHERE status = 'active' AND alert_level = 'warning'");
$critical_count = Database::fetch("SELECT COUNT(*) as cnt FROM node_alerts WHERE status = 'active' AND alert_level = 'critical'");

$type_map = [
    'cpu_high' => 'CPU过高',
    'memory_high' => '内存过高',
    'disk_high' => '磁盘过高',
    'traffic_high' => '流量异常',
    'node_offline' => '节点离线',
    'vm_offline' => '虚拟机离线',
    'security' => '安全告警',
];

$level_map = [
    'info' => ['text' => '信息', 'class' => 'info'],
    'warning' => ['text' => '警告', 'class' => 'warning'],
    'critical' => ['text' => '严重', 'class' => 'danger'],
];

$status_map = [
    'active' => ['text' => '待处理', 'class' => 'danger'],
    'acknowledged' => ['text' => '已确认', 'class' => 'warning'],
    'resolved' => ['text' => '已解决', 'class' => 'success'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $alert_id = intval($_POST['alert_id'] ?? 0);
    $admin = admin_user();
    
    if ($action === 'acknowledge' && $alert_id > 0) {
        Database::update('node_alerts', [
            'status' => 'acknowledged',
            'acknowledged_by' => $admin['id'],
            'acknowledged_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$alert_id]);
        header("Location: /admin/alerts.php?status=$status");
        exit;
    }
    
    if ($action === 'resolve' && $alert_id > 0) {
        Database::update('node_alerts', [
            'status' => 'resolved',
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$alert_id]);
        header("Location: /admin/alerts.php?status=$status");
        exit;
    }
    
    if ($action === 'batch_resolve') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query("UPDATE node_alerts SET status = 'resolved', resolved_at = NOW() WHERE id IN ($placeholders)", array_map('intval', $ids));
        }
        header("Location: /admin/alerts.php?status=$status");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>告警中心 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
<div class="main-content">
    <div class="page-header">
        <h2>🚨 告警中心</h2>
        <div class="breadcrumb">首页 / 告警中心</div>
    </div>

    <div class="stats-grid">
        <div class="stat-card" style="border-left: 4px solid #ef4444;">
            <div class="stat-value" style="color: #ef4444;"><?php echo intval($critical_count['cnt'] ?? 0); ?></div>
            <div class="stat-label">严重告警</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #f59e0b;">
            <div class="stat-value" style="color: #f59e0b;"><?php echo intval($warning_count['cnt'] ?? 0); ?></div>
            <div class="stat-label">警告</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #22c55e;">
            <div class="stat-value" style="color: #22c55e;"><?php echo intval($active_count['cnt'] ?? 0); ?></div>
            <div class="stat-label">待处理总数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo intval($total['cnt']); ?></div>
            <div class="stat-label">全部告警</div>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>告警类型</label>
                <select name="alert_type" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($type_map as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $alert_type == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>级别</label>
                <select name="level" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($level_map as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $level == $k ? 'selected' : ''; ?>><?php echo $v['text']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>状态</label>
                <select name="status" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($status_map as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $status == $k ? 'selected' : ''; ?>><?php echo $v['text']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>
    </div>

    <form method="POST">
    <div class="card">
        <div class="card-title">
            <span>告警列表</span>
            <div style="display: flex; gap: 8px;">
                <button type="submit" name="action" value="batch_resolve" class="btn btn-sm btn-success" onclick="return confirm('确认批量标记为已解决？')">批量解决</button>
            </div>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                        <th>ID</th>
                        <th>级别</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>指标值</th>
                        <th>阈值</th>
                        <th>节点/主机</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $a): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo $a['id']; ?>" class="alert-check"></td>
                        <td><?php echo $a['id']; ?></td>
                        <td><span class="badge badge-<?php echo $level_map[$a['alert_level']]['class']; ?>"><?php echo $level_map[$a['alert_level']]['text']; ?></span></td>
                        <td><?php echo $type_map[$a['alert_type']] ?? $a['alert_type']; ?></td>
                        <td><strong><?php echo e($a['title']); ?></strong></td>
                        <td style="color: #ef4444;"><?php echo e($a['metric_value'] ?? '-'); ?></td>
                        <td><?php echo e($a['threshold'] ?? '-'); ?></td>
                        <td>
                            <?php if (!empty($a['node_id'])): ?>
                                <span class="badge badge-info">节点 #<?php echo $a['node_id']; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($a['host_id'])): ?>
                                <span class="badge badge-secondary">主机 #<?php echo $a['host_id']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?php echo $status_map[$a['status']]['class']; ?>"><?php echo $status_map[$a['status']]['text']; ?></span></td>
                        <td><?php echo $a['created_at']; ?></td>
                        <td>
                            <?php if ($a['status'] === 'active'): ?>
                                <button type="submit" name="action" value="acknowledge" class="btn btn-sm btn-warning" style="padding: 2px 8px; font-size: 12px;">确认</button>
                                <button type="submit" name="action" value="resolve" class="btn btn-sm btn-success" style="padding: 2px 8px; font-size: 12px;">解决</button>
                                <input type="hidden" name="alert_id" value="<?php echo $a['id']; ?>">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($alerts)): ?>
                    <tr><td colspan="11" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无告警</td></tr>
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
                    <a href="?alert_type=<?php echo urlencode($alert_type); ?>&status=<?php echo urlencode($status); ?>&level=<?php echo urlencode($level); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    </form>
</div>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.alert-check').forEach(c => c.checked = cb.checked);
}
</script>
    </div>
</div>
    </div>
</body>
</html>
