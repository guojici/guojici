<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

$id_param = get('id', '');
$host = null;

if (is_numeric($id_param)) {
    $id = intval($id_param);
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$id, $uid]);
} else {
    $uuid = $id_param;
    $host = Database::fetch("SELECT * FROM hosts WHERE uuid = ? AND user_id = ?", [$uuid, $uid]);
}

if (!$host) {
    flash('error', '主机不存在');
    header('Location: /user/hosts.php');
    exit;
}

$id = $host['id'];
$host_uuid = $host['uuid'] ?? $id;
$vm_name = $host['vm_name'] ?? ('VM-' . $id);

// 获取存储配置
$storage_config = config('storage');
$max_snapshots = intval($storage_config['max_snapshots_per_vm'] ?? 10);

// 处理操作
if (is_post()) {
    $action = post('action', '');
    
    if ($action === 'create_snapshot') {
        $snapshot_name = trim(post('snapshot_name', ''));
        $snapshot_desc = trim(post('snapshot_desc', ''));
        $snapshot_type = post('snapshot_type', 'internal');
        
        // 检查快照数量限制
        $current_count = Database::fetchColumn("SELECT COUNT(*) FROM vm_snapshots WHERE host_id = ? AND status != 'deleting'", [$id]);
        if ($current_count >= $max_snapshots) {
            flash('error', '快照数量已达上限（' . $max_snapshots . ' 个），请删除旧快照后再创建');
        } elseif (empty($snapshot_name)) {
            flash('error', '快照名称不能为空');
        } else {
            $libvirt_name = 'snap_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
            
            try {
                Database::insert('vm_snapshots', [
                    'host_id' => $id,
                    'user_id' => $uid,
                    'snapshot_name' => $snapshot_name,
                    'snapshot_desc' => $snapshot_desc,
                    'libvirt_name' => $libvirt_name,
                    'snapshot_type' => $snapshot_type,
                    'status' => 'creating',
                ]);
                flash('success', '快照创建任务已提交，请在后台执行');
            } catch (Exception $e) {
                flash('error', '创建失败：' . $e->getMessage());
            }
        }
        header('Location: /user/host_snapshots.php?id=' . $host_uuid);
        exit;
    }
    
    if ($action === 'restore_snapshot') {
        $snapshot_id = intval(post('snapshot_id', 0));
        $snapshot = Database::fetch("SELECT * FROM vm_snapshots WHERE id = ? AND host_id = ?", [$snapshot_id, $id]);
        
        if (!$snapshot) {
            flash('error', '快照不存在');
        } elseif ($snapshot['status'] !== 'available') {
            flash('error', '快照状态不可用');
        } else {
            Database::update('vm_snapshots', ['status' => 'restoring'], 'id = ?', [$snapshot_id]);
            flash('success', '快照恢复任务已提交');
        }
        header('Location: /user/host_snapshots.php?id=' . $host_uuid);
        exit;
    }
    
    if ($action === 'delete_snapshot') {
        $snapshot_id = intval(post('snapshot_id', 0));
        $snapshot = Database::fetch("SELECT * FROM vm_snapshots WHERE id = ? AND host_id = ?", [$snapshot_id, $id]);
        
        if (!$snapshot) {
            flash('error', '快照不存在');
        } else {
            Database::update('vm_snapshots', ['status' => 'deleting'], 'id = ?', [$snapshot_id]);
            flash('success', '快照删除任务已提交');
        }
        header('Location: /user/host_snapshots.php?id=' . $host_uuid);
        exit;
    }
}

// 获取快照列表
$snapshots = Database::fetchAll(
    "SELECT * FROM vm_snapshots WHERE host_id = ? ORDER BY created_at DESC",
    [$id]
);

// 获取快照策略
$policies = Database::fetchAll(
    "SELECT * FROM snapshot_policies WHERE host_id = ? OR host_id = 0 ORDER BY id DESC",
    [$id]
);

// 计算已用快照数量
$snapshot_count = count($snapshots);
$available_count = $max_snapshots - $snapshot_count;

// 状态标签
$status_labels = [
    'creating' => ['text' => '创建中', 'class' => 'warning'],
    'available' => ['text' => '可用', 'class' => 'success'],
    'restoring' => ['text' => '恢复中', 'class' => 'warning'],
    'deleting' => ['text' => '删除中', 'class' => 'danger'],
    'error' => ['text' => '错误', 'class' => 'danger'],
];

$page_title = '快照管理 - ' . $vm_name;
require_once __DIR__ . '/../templates/navbar.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .snapshot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .snapshot-card { background: #fff; border-radius: 8px; border: 1px solid #e5e8eb; padding: 16px; transition: all 0.2s; }
        .snapshot-card:hover { border-color: #165dff; box-shadow: 0 2px 8px rgba(22,93,255,0.1); }
        .snapshot-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .snapshot-name { font-size: 15px; font-weight: 600; color: #1d2129; }
        .snapshot-status { font-size: 11px; padding: 2px 8px; border-radius: 4px; }
        .snapshot-status.success { background: #e8ffea; color: #00b42a; }
        .snapshot-status.warning { background: #fff7e8; color: #ff7d00; }
        .snapshot-status.danger { background: #ffece8; color: #f53f3f; }
        .snapshot-meta { font-size: 12px; color: #86909c; margin-bottom: 8px; }
        .snapshot-desc { font-size: 13px; color: #4e5969; margin-bottom: 12px; line-height: 1.5; }
        .snapshot-actions { display: flex; gap: 8px; }
        .snapshot-summary { display: flex; gap: 24px; padding: 16px; background: #f7f8fa; border-radius: 8px; margin-bottom: 20px; }
        .summary-item { text-align: center; }
        .summary-value { font-size: 24px; font-weight: 700; color: #1d2129; }
        .summary-label { font-size: 12px; color: #86909c; margin-top: 4px; }
        .policy-item { display: flex; align-items: center; padding: 12px; background: #f7f8fa; border-radius: 6px; margin-bottom: 8px; }
        .policy-icon { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 14px; margin-right: 12px; }
        .policy-icon.enabled { background: #e8ffea; color: #00b42a; }
        .policy-icon.disabled { background: #f2f3f5; color: #4e5969; }
        .policy-info { flex: 1; }
        .policy-name { font-size: 13px; font-weight: 600; color: #1d2129; }
        .policy-schedule { font-size: 12px; color: #86909c; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1200px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h1 style="font-size: 24px; margin: 0;">📸 快照管理</h1>
                <div style="font-size: 14px; color: #86909c; margin-top: 4px;">
                    <?php echo e($vm_name); ?> · 
                    <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" style="color: #165dff;">返回主机详情</a>
                </div>
            </div>
            <button class="btn btn-primary" onclick="showCreateSnapshot()">+ 创建快照</button>
        </div>

        <?php echo render_flash(); ?>

        <!-- 快照统计 -->
        <div class="snapshot-summary">
            <div class="summary-item">
                <div class="summary-value"><?php echo $snapshot_count; ?></div>
                <div class="summary-label">已有快照</div>
            </div>
            <div class="summary-item">
                <div class="summary-value"><?php echo $max_snapshots; ?></div>
                <div class="summary-label">最大数量</div>
            </div>
            <div class="summary-item">
                <div class="summary-value" style="color: <?php echo $available_count > 0 ? '#00b42a' : '#f53f3f'; ?>;"><?php echo $available_count; ?></div>
                <div class="summary-label">可创建</div>
            </div>
        </div>

        <!-- 自动快照策略 -->
        <?php if (!empty($policies)): ?>
        <div style="margin-bottom: 20px;">
            <h3 style="font-size: 16px; margin: 0 0 12px;">自动快照策略</h3>
            <?php foreach ($policies as $p): ?>
            <?php 
                $schedule_names = ['hourly' => '每小时', 'daily' => '每天', 'weekly' => '每周', 'monthly' => '每月', 'manual' => '手动'];
                $weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
                $schedule_desc = '';
                switch ($p['schedule_type']) {
                    case 'hourly': $schedule_desc = '每小时执行'; break;
                    case 'daily': $schedule_desc = '每天 ' . $p['schedule_hour'] . ':' . str_pad($p['schedule_minute'], 2, '0', STR_PAD_LEFT); break;
                    case 'weekly': $schedule_desc = $weekdays[$p['schedule_day'] % 7] . ' 执行'; break;
                    case 'monthly': $schedule_desc = '每月' . $p['schedule_day'] . '日执行'; break;
                    case 'manual': $schedule_desc = '手动执行'; break;
                }
            ?>
            <div class="policy-item">
                <div class="policy-icon <?php echo $p['is_enabled'] ? 'enabled' : 'disabled'; ?>">
                    <?php echo $p['is_enabled'] ? '✅' : '⏸️'; ?>
                </div>
                <div class="policy-info">
                    <div class="policy-name"><?php echo e($p['policy_name']); ?></div>
                    <div class="policy-schedule"><?php echo $schedule_desc; ?> · 保留 <?php echo $p['retention_count']; ?> 个 / <?php echo $p['retention_days']; ?> 天</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 快照列表 -->
        <h3 style="font-size: 16px; margin: 0 0 12px;">快照列表</h3>
        
        <?php if (empty($snapshots)): ?>
        <div style="text-align: center; padding: 60px 20px; background: #f7f8fa; border-radius: 8px;">
            <div style="font-size: 48px; margin-bottom: 16px;">📸</div>
            <div style="color: #86909c; margin-bottom: 16px;">暂无快照</div>
            <button class="btn btn-primary" onclick="showCreateSnapshot()">创建第一个快照</button>
        </div>
        <?php else: ?>
        <div class="snapshot-grid">
            <?php foreach ($snapshots as $snap): ?>
            <?php 
                $status = $status_labels[$snap['status']] ?? ['text' => $snap['status'], 'class' => 'warning'];
                $size_mb = round(intval($snap['snapshot_size'] ?? 0) / 1024 / 1024, 2);
            ?>
            <div class="snapshot-card">
                <div class="snapshot-header">
                    <div class="snapshot-name"><?php echo e($snap['snapshot_name']); ?></div>
                    <span class="snapshot-status <?php echo $status['class']; ?>"><?php echo $status['text']; ?></span>
                </div>
                <div class="snapshot-meta">
                    创建时间: <?php echo substr($snap['created_at'], 0, 16); ?>
                    <?php if ($size_mb > 0): ?>
                    · 大小: <?php echo $size_mb; ?> MB
                    <?php endif; ?>
                </div>
                <?php if ($snap['snapshot_desc']): ?>
                <div class="snapshot-desc"><?php echo e($snap['snapshot_desc']); ?></div>
                <?php endif; ?>
                <?php if ($snap['status'] === 'available'): ?>
                <div class="snapshot-actions">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('确定恢复到此快照？当前数据将被覆盖！')">
                        <input type="hidden" name="action" value="restore_snapshot">
                        <input type="hidden" name="snapshot_id" value="<?php echo $snap['id']; ?>">
                        <button class="btn btn-sm btn-primary">🔄 恢复</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('确定删除此快照？')">
                        <input type="hidden" name="action" value="delete_snapshot">
                        <input type="hidden" name="snapshot_id" value="<?php echo $snap['id']; ?>">
                        <button class="btn btn-sm btn-danger">删除</button>
                    </form>
                </div>
                <?php elseif ($snap['status'] === 'creating'): ?>
                <div style="font-size: 12px; color: #ff7d00;">⏳ 快照创建中，请稍候...</div>
                <?php elseif ($snap['status'] === 'restoring'): ?>
                <div style="font-size: 12px; color: #ff7d00;">⏳ 快照恢复中...</div>
                <?php elseif ($snap['status'] === 'error'): ?>
                <div style="font-size: 12px; color: #f53f3f;">❌ <?php echo e($snap['error_msg'] ?: '操作失败'); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 创建快照弹窗 -->
    <div class="modal-overlay" id="createSnapshotModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>创建快照</h3>
                <button class="modal-close" onclick="document.getElementById('createSnapshotModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" onsubmit="return confirm('确定创建快照？创建期间虚拟机可能会短暂暂停')">
                <input type="hidden" name="action" value="create_snapshot">
                <div class="form-group">
                    <label>快照名称 <span style="color:#f53f3f;">*</span></label>
                    <input type="text" class="form-control" name="snapshot_name" required placeholder="例如：系统部署完成">
                </div>
                <div class="form-group">
                    <label>描述</label>
                    <textarea class="form-control" name="snapshot_desc" rows="3" placeholder="快照用途说明（可选）"></textarea>
                </div>
                <div class="form-group">
                    <label>快照类型</label>
                    <select class="form-control" name="snapshot_type">
                        <option value="internal">内存快照（包含内存状态）</option>
                        <option value="disk-only">磁盘快照（仅磁盘状态，速度更快）</option>
                    </select>
                    <div style="font-size:11px; color:#86909c; margin-top:4px;">内存快照恢复后虚拟机保持原状态；磁盘快照仅保存磁盘数据</div>
                </div>
                <div style="padding:10px; background:rgba(22,93,255,0.08); border-radius:6px; font-size:12px; color:#165dff; margin-bottom:12px;">
                    💡 提示：您已创建 <?php echo $snapshot_count; ?> 个快照，还可以创建 <?php echo $available_count; ?> 个
                </div>
                <?php if ($available_count <= 0): ?>
                <div style="padding:10px; background:rgba(245,158,11,0.08); border-radius:6px; font-size:12px; color:#b45309; margin-bottom:12px;">
                    ⚠️ 快照数量已达上限，请先删除旧快照
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="width:100%;" <?php echo $available_count <= 0 ? 'disabled' : ''; ?>>创建快照</button>
            </form>
        </div>
    </div>

    <script>
        function showCreateSnapshot() {
            document.getElementById('createSnapshotModal').classList.add('active');
        }
    </script>
</body>
</html>