<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();
migrate_new_tables();

require_permission('user:quota');

function get_bar_class($used, $max) {
    if ($max <= 0) return 'quota-bar-low';
    $pct = ($used / $max) * 100;
    if ($pct >= 90) return 'quota-bar-high';
    if ($pct >= 70) return 'quota-bar-medium';
    return 'quota-bar-low';
}
function get_pct($used, $max) {
    if ($max <= 0) return 0;
    return min(100, ($used / $max) * 100);
}

$search = trim(get('search', ''));
$page = max(1, intval(get('page', 1)));
$page_size = 20;

// 处理POST请求
if (is_post()) {
    $action = post('action');

    if ($action === 'update_quota') {
        $user_id = intval(post('user_id'));
        $user = Database::fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
        if (!$user) {
            flash('error', '用户不存在');
        } else {
            ensure_user_quota($user_id);

            $max_vms = intval(post('max_vms', -1));
            $max_cpu = intval(post('max_cpu', -1));
            $max_memory_mb = intval(post('max_memory_mb', -1));
            $max_disk_gb = intval(post('max_disk_gb', -1));
            $max_bandwidth_mbps = intval(post('max_bandwidth_mbps', -1));
            $max_ip_count = intval(post('max_ip_count', -1));
            $max_snapshots = intval(post('max_snapshots', -1));

            Database::update('user_quotas', [
                'max_vms' => $max_vms,
                'max_cpu' => $max_cpu,
                'max_memory_mb' => $max_memory_mb,
                'max_disk_gb' => $max_disk_gb,
                'max_bandwidth_mbps' => $max_bandwidth_mbps,
                'max_ip_count' => $max_ip_count,
                'max_snapshots' => $max_snapshots,
            ], 'user_id = ?', [$user_id]);

            // 重新计算已用配额
            recalc_user_quota_usage($user_id);

            @Database::insert('admin_logs', [
                'admin_id' => admin_user()['id'],
                'action' => 'user_quota_update',
                'target_type' => 'user',
                'target_id' => $user_id,
                'detail' => '更新用户配额: ' . $user['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            flash('success', '用户配额已更新');
        }
        header('Location: /admin/user_quotas.php?search=' . urlencode($search) . '&page=' . $page);
        exit;
    }

    if ($action === 'recalc_all') {
        $users = Database::fetchAll("SELECT id FROM users");
        $count = 0;
        foreach ($users as $u) {
            if (recalc_user_quota_usage($u['id'])) {
                $count++;
            }
        }
        flash('success', '已重新计算 ' . $count . ' 个用户的资源使用量');
        header('Location: /admin/user_quotas.php');
        exit;
    }

    if ($action === 'batch_set_quota') {
        $batch_vms = intval(post('batch_vms', -1));
        $batch_cpu = intval(post('batch_cpu', -1));
        $batch_memory = intval(post('batch_memory', -1));
        $batch_disk = intval(post('batch_disk', -1));
        $batch_ip = intval(post('batch_ip', -1));
        $batch_snap = intval(post('batch_snap', -1));

        // 为没有配额记录的用户创建默认配额
        $users = Database::fetchAll("SELECT id FROM users");
        foreach ($users as $u) {
            ensure_user_quota($u['id']);
            Database::update('user_quotas', [
                'max_vms' => $batch_vms,
                'max_cpu' => $batch_cpu,
                'max_memory_mb' => $batch_memory,
                'max_disk_gb' => $batch_disk,
                'max_ip_count' => $batch_ip,
                'max_snapshots' => $batch_snap,
            ], 'user_id = ?', [$u['id']]);
        }
        flash('success', '已批量设置 ' . count($users) . ' 个用户的配额');
        header('Location: /admin/user_quotas.php');
        exit;
    }
}

// 构建查询
$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s];
}

// 总数
$total = Database::fetch("SELECT COUNT(*) as cnt FROM users u WHERE $where", $params);
$total_count = intval($total['cnt'] ?? 0);
$total_pages = ceil($total_count / $page_size);
$offset = ($page - 1) * $page_size;

// 获取用户配额列表
$users = Database::fetchAll("SELECT 
    u.id, u.username, u.email, u.status, u.created_at,
    q.max_vms, q.max_cpu, q.max_memory_mb, q.max_disk_gb, q.max_bandwidth_mbps, q.max_ip_count, q.max_snapshots,
    q.used_vms, q.used_cpu, q.used_memory_mb, q.used_disk_gb, q.used_ip_count, q.used_snapshots
    FROM users u
    LEFT JOIN user_quotas q ON u.id = q.user_id
    WHERE $where
    ORDER BY u.id DESC
    LIMIT $offset, $page_size", $params);

// 统计概览
$stats = Database::fetch("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN q.max_vms > 0 THEN 1 ELSE 0 END) as limited_users
    FROM users u
    LEFT JOIN user_quotas q ON u.id = q.user_id");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>租户配额管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .quota-bar {
            height: 6px;
            background: #e5e6eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }
        .quota-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .quota-bar-low { background: #22c55e; }
        .quota-bar-medium { background: #f59e0b; }
        .quota-bar-high { background: #ef4444; }
        .quota-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
        }
        .stat-card-label {
            font-size: 13px;
            color: #86909c;
            margin-bottom: 4px;
        }
        .stat-card-value {
            font-size: 24px;
            font-weight: 600;
            color: #1d2129;
        }
        .quota-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .quota-item {
            display: flex;
            flex-direction: column;
        }
        .quota-item-label {
            font-size: 12px;
            color: #86909c;
            margin-bottom: 2px;
        }
        .quota-item-value {
            font-size: 13px;
            font-weight: 500;
        }
        .batch-quota-form {
            background: #f9fafb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            display: none;
        }
        .batch-quota-form.show { display: block; }
        .batch-quota-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include '_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">租户配额管理</h1>
                    <p class="page-subtitle">管理租户资源配额限制</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button onclick="toggleBatchQuota()" class="btn btn-outline">批量设置配额</button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="recalc_all">
                        <button type="submit" class="btn btn-outline" onclick="return confirm('确定重新计算所有用户的资源使用量？')">重新计算用量</button>
                    </form>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <!-- 统计概览 -->
            <div class="quota-stats-grid">
                <div class="stat-card">
                    <div class="stat-card-label">总用户数</div>
                    <div class="stat-card-value"><?php echo intval($stats['total_users'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-label">有限额用户</div>
                    <div class="stat-card-value"><?php echo intval($stats['limited_users'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-label">无限额用户</div>
                    <div class="stat-card-value" style="color: #22c55e;"><?php echo intval(($stats['total_users'] ?? 0) - ($stats['limited_users'] ?? 0)); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-label">当前页</div>
                    <div class="stat-card-value"><?php echo count($users); ?> 条</div>
                </div>
            </div>

            <!-- 批量设置表单 -->
            <div class="card batch-quota-form" id="batchQuotaForm">
                <div style="font-weight: 600; margin-bottom: 12px;">批量设置所有用户配额（-1 表示不限）</div>
                <form method="POST">
                    <input type="hidden" name="action" value="batch_set_quota">
                    <div class="batch-quota-grid">
                        <div class="form-group">
                            <label>最大虚拟机数</label>
                            <input type="number" class="form-control" name="batch_vms" value="-1" min="-1">
                        </div>
                        <div class="form-group">
                            <label>最大CPU核数</label>
                            <input type="number" class="form-control" name="batch_cpu" value="-1" min="-1">
                        </div>
                        <div class="form-group">
                            <label>最大内存(MB)</label>
                            <input type="number" class="form-control" name="batch_memory" value="-1" min="-1">
                        </div>
                        <div class="form-group">
                            <label>最大磁盘(GB)</label>
                            <input type="number" class="form-control" name="batch_disk" value="-1" min="-1">
                        </div>
                        <div class="form-group">
                            <label>最大公网IP数</label>
                            <input type="number" class="form-control" name="batch_ip" value="-1" min="-1">
                        </div>
                        <div class="form-group">
                            <label>最大快照数</label>
                            <input type="number" class="form-control" name="batch_snap" value="-1" min="-1">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('确定批量设置所有用户的配额？此操作不可撤销。')">应用到所有用户</button>
                </form>
            </div>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索用户名/邮箱/手机号..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/user_quotas.php?search='+encodeURIComponent(this.value);">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>用户</th>
                                <th>虚拟机</th>
                                <th>CPU</th>
                                <th>内存</th>
                                <th>磁盘</th>
                                <th>IP数</th>
                                <th>快照</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr><td colspan="8" style="text-align:center; color:#86909c; padding:24px;">暂无用户</td></tr>
                            <?php else: ?>
                            <?php foreach ($users as $u):
                                $max_vms = intval($u['max_vms'] ?? -1);
                                $used_vms = intval($u['used_vms'] ?? 0);
                                $max_cpu = intval($u['max_cpu'] ?? -1);
                                $used_cpu = intval($u['used_cpu'] ?? 0);
                                $max_mem = intval($u['max_memory_mb'] ?? -1);
                                $used_mem = intval($u['used_memory_mb'] ?? 0);
                                $max_disk = intval($u['max_disk_gb'] ?? -1);
                                $used_disk = intval($u['used_disk_gb'] ?? 0);
                                $max_ip = intval($u['max_ip_count'] ?? -1);
                                $used_ip = intval($u['used_ip_count'] ?? 0);
                                $max_snap = intval($u['max_snapshots'] ?? -1);
                                $used_snap = intval($u['used_snapshots'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($u['username']); ?></strong>
                                    <div style="font-size:11px; color:#86909c;"><?php echo e($u['email'] ?? '--'); ?></div>
                                </td>
                                <td style="min-width: 120px;">
                                    <div style="font-size:12px;"><?php echo $used_vms; ?> / <?php echo $max_vms < 0 ? '不限' : $max_vms; ?></div>
                                    <?php if ($max_vms > 0): ?>
                                    <div class="quota-bar"><div class="quota-bar-fill <?php echo get_bar_class($used_vms, $max_vms); ?>" style="width: <?php echo get_pct($used_vms, $max_vms); ?>%;"></div></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 120px;">
                                    <div style="font-size:12px;"><?php echo $used_cpu; ?> / <?php echo $max_cpu < 0 ? '不限' : $max_cpu; ?> 核</div>
                                    <?php if ($max_cpu > 0): ?>
                                    <div class="quota-bar"><div class="quota-bar-fill <?php echo get_bar_class($used_cpu, $max_cpu); ?>" style="width: <?php echo get_pct($used_cpu, $max_cpu); ?>%;"></div></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 120px;">
                                    <div style="font-size:12px;"><?php echo $used_mem; ?> / <?php echo $max_mem < 0 ? '不限' : $max_mem; ?> MB</div>
                                    <?php if ($max_mem > 0): ?>
                                    <div class="quota-bar"><div class="quota-bar-fill <?php echo get_bar_class($used_mem, $max_mem); ?>" style="width: <?php echo get_pct($used_mem, $max_mem); ?>%;"></div></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 120px;">
                                    <div style="font-size:12px;"><?php echo $used_disk; ?> / <?php echo $max_disk < 0 ? '不限' : $max_disk; ?> GB</div>
                                    <?php if ($max_disk > 0): ?>
                                    <div class="quota-bar"><div class="quota-bar-fill <?php echo get_bar_class($used_disk, $max_disk); ?>" style="width: <?php echo get_pct($used_disk, $max_disk); ?>%;"></div></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 100px;">
                                    <div style="font-size:12px;"><?php echo $used_ip; ?> / <?php echo $max_ip < 0 ? '不限' : $max_ip; ?></div>
                                </td>
                                <td style="min-width: 100px;">
                                    <div style="font-size:12px;"><?php echo $used_snap; ?> / <?php echo $max_snap < 0 ? '不限' : $max_snap; ?></div>
                                </td>
                                <td style="white-space: nowrap;">
                                    <button onclick="showEditQuota(<?php echo $u['id']; ?>, '<?php echo e($u['username']); ?>', <?php echo $max_vms; ?>, <?php echo $max_cpu; ?>, <?php echo $max_mem; ?>, <?php echo $max_disk; ?>, <?php echo $max_ip; ?>, <?php echo $max_snap; ?>)" class="btn btn-sm btn-primary">设置配额</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="/admin/user_quotas.php?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn">上一页</a>
                    <?php endif; ?>
                    <span class="page-info">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，共 <?php echo $total_count; ?> 条</span>
                    <?php if ($page < $total_pages): ?>
                        <a href="/admin/user_quotas.php?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 编辑配额弹窗 -->
    <div class="modal-overlay" id="editQuotaModal">
        <div class="modal-box" style="max-width: 500px;">
            <div class="modal-header">
                <h3>设置用户配额</h3>
                <button class="modal-close" onclick="document.getElementById('editQuotaModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_quota">
                <input type="hidden" name="user_id" id="eq_user_id">
                <div style="background: #f5f7fa; border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; font-size: 13px;">
                    用户：<strong id="eq_username">--</strong>
                    <span style="color: #86909c; margin-left: 8px;">（设置为 -1 表示不限制）</span>
                </div>
                <div class="quota-detail-grid">
                    <div class="form-group">
                        <label>最大虚拟机数</label>
                        <input type="number" class="form-control" name="max_vms" id="eq_vms" value="-1" min="-1">
                    </div>
                    <div class="form-group">
                        <label>最大CPU核数</label>
                        <input type="number" class="form-control" name="max_cpu" id="eq_cpu" value="-1" min="-1">
                    </div>
                    <div class="form-group">
                        <label>最大内存(MB)</label>
                        <input type="number" class="form-control" name="max_memory_mb" id="eq_mem" value="-1" min="-1">
                    </div>
                    <div class="form-group">
                        <label>最大磁盘(GB)</label>
                        <input type="number" class="form-control" name="max_disk_gb" id="eq_disk" value="-1" min="-1">
                    </div>
                    <div class="form-group">
                        <label>最大带宽(Mbps)</label>
                        <input type="number" class="form-control" name="max_bandwidth_mbps" id="eq_bw" value="-1" min="-1">
                    </div>
                    <div class="form-group">
                        <label>最大公网IP数</label>
                        <input type="number" class="form-control" name="max_ip_count" id="eq_ip" value="-1" min="-1">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>最大快照数</label>
                        <input type="number" class="form-control" name="max_snapshots" id="eq_snap" value="-1" min="-1">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">保存配额</button>
            </form>
        </div>
    </div>

    <script>
    function showEditQuota(id, username, vms, cpu, mem, disk, ip, snap) {
        document.getElementById('eq_user_id').value = id;
        document.getElementById('eq_username').textContent = username;
        document.getElementById('eq_vms').value = vms;
        document.getElementById('eq_cpu').value = cpu;
        document.getElementById('eq_mem').value = mem;
        document.getElementById('eq_disk').value = disk;
        document.getElementById('eq_ip').value = ip;
        document.getElementById('eq_snap').value = snap;
        document.getElementById('editQuotaModal').classList.add('active');
    }
    function toggleBatchQuota() {
        var form = document.getElementById('batchQuotaForm');
        form.classList.toggle('show');
    }
    </script>
</body>
</html>
