<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();
migrate_new_tables();

$user = auth_user();
$uid = auth_id();

$kvm_only = intval(get('kvm_only', 0));
$status_filter = trim(get('status', ''));

// 处理批量操作
if (is_post()) {
    $action = post('action');

    if ($action === 'transfer_request') {
        $host_id = intval(post('host_id'));
        $target_username = trim(post('target_username', ''));
        $reason = trim(post('reason', ''));

        $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $uid]);
        if (!$host) {
            flash('error', '主机不存在或您无权操作');
            header('Location: /user/hosts.php');
            exit;
        }
        if ($target_username === '') {
            flash('error', '请输入目标用户名');
            header('Location: /user/hosts.php');
            exit;
        }
        $target = Database::fetch("SELECT * FROM users WHERE username = ? AND status = 'active'", [$target_username]);
        if (!$target) {
            flash('error', '目标用户不存在或已被禁用');
            header('Location: /user/hosts.php');
            exit;
        }
        if (intval($target['id']) === intval($uid)) {
            flash('error', '目标用户不能是自己');
            header('Location: /user/hosts.php');
            exit;
        }
        $existing = Database::fetch("SELECT id FROM host_transfers WHERE host_id = ? AND from_user_id = ? AND status = 'pending'", [$host_id, $uid]);
        if ($existing) {
            flash('error', '该主机已有待处理的转移申请，请勿重复提交');
            header('Location: /user/hosts.php');
            exit;
        }

        Database::insert('host_transfers', [
            'host_id' => $host_id,
            'from_user_id' => $uid,
            'to_user_id' => $target['id'],
            'status' => 'pending',
        ]);

        @Database::insert('admin_logs', [
            'admin_id' => 0,
            'action' => 'host_transfer_request',
            'target_type' => 'host',
            'target_id' => $host_id,
            'detail' => '用户申请转移主机: ' . ($host['vm_name'] ?: $host['mnbt_username']) . ' 给用户 ' . $target['username'] . ($reason !== '' ? ' 原因: ' . $reason : ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        flash('success', '转移申请已提交，请等待管理员审核。目标用户：' . $target['username']);
        header('Location: /user/hosts.php');
        exit;
    }

    // KVM批量操作
    if (in_array($action, ['batch_start', 'batch_stop', 'batch_restart', 'batch_forcestop', 'batch_suspend', 'batch_resume'])) {
        $vm_action = str_replace('batch_', '', $action);
        $host_ids = post('host_ids', '');
        $id_array = array_filter(array_map('intval', explode(',', $host_ids)));
        if (empty($id_array)) {
            flash('error', '请先选择要操作的主机');
            header('Location: /user/hosts.php');
            exit;
        }
        if (count($id_array) > 50) {
            flash('error', '一次最多操作50台主机');
            header('Location: /user/hosts.php');
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($id_array), '?'));
        $hosts = Database::fetchAll("SELECT * FROM hosts WHERE id IN ($placeholders) AND user_id = ? AND vm_type = 'kvm'", array_merge($id_array, [$uid]));

        if (empty($hosts)) {
            flash('error', '未找到可操作的KVM主机');
            header('Location: /user/hosts.php');
            exit;
        }

        $result = kvm_batch_action($hosts, $vm_action);
        flash('success', '批量操作完成：成功 ' . $result['success'] . ' 台，失败 ' . $result['failed'] . ' 台');
        header('Location: /user/hosts.php');
        exit;
    }
}

// 查询主机
$where = "h.user_id = ?";
$params = [$uid];
if ($kvm_only) {
    $where .= " AND h.vm_type = 'kvm'";
}
if ($status_filter) {
    if ($status_filter === 'running') {
        $where .= " AND h.vm_power_status = 'running'";
    } elseif ($status_filter === 'stopped') {
        $where .= " AND h.vm_power_status = 'stopped'";
    } elseif ($status_filter === 'paused') {
        $where .= " AND h.vm_power_status = 'paused'";
    } elseif ($status_filter === 'expired') {
        $where .= " AND h.expire_at < NOW()";
    } elseif ($status_filter === 'active') {
        $where .= " AND h.status = 'running'";
    }
}

$hosts = Database::fetchAll("SELECT h.*, p.name as package_name, p.webdx, p.sqldx, p.sizemax, p.ymbds FROM hosts h LEFT JOIN packages p ON h.package_id = p.id WHERE $where ORDER BY h.created_at DESC", $params);

// 获取各主机最新的转移申请
$transfer_map = [];
if (!empty($hosts)) {
    $host_ids = array_column($hosts, 'id');
    $placeholders = implode(',', array_fill(0, count($host_ids), '?'));
    $transfers = Database::fetchAll("SELECT t.*, tu.username AS to_user_name FROM host_transfers t LEFT JOIN users tu ON t.to_user_id = tu.id WHERE t.host_id IN ($placeholders) ORDER BY t.id DESC", $host_ids);
    foreach ($transfers as $t) {
        if (!isset($transfer_map[$t['host_id']])) {
            $transfer_map[$t['host_id']] = $t;
        }
    }
}

$kvm_count = 0;
foreach ($hosts as $h) {
    if (!empty($h['vm_type']) && $h['vm_type'] === 'kvm') $kvm_count++;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的主机 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">我的主机</h1>
                    <p class="page-subtitle">管理您的所有虚拟主机（共 <?php echo count($hosts); ?> 台，KVM: <?php echo $kvm_count; ?> 台）</p>
                </div>
                <a href="/checkout.php" class="btn btn-primary">购买新主机</a>
            </div>

            <?php
            $error = flash('error');
            $success = flash('success');
            if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

            <!-- 筛选和批量操作栏 -->
            <div class="card" style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <span style="font-size: 13px; color: #86909c;">筛选：</span>
                        <a href="/user/hosts.php" class="filter-tag <?php echo !$kvm_only && !$status_filter ? 'active' : ''; ?>">全部</a>
                        <a href="/user/hosts.php?kvm_only=1" class="filter-tag <?php echo $kvm_only ? 'active' : ''; ?>">仅KVM</a>
                        <a href="/user/hosts.php?status=running&kvm_only=1" class="filter-tag <?php echo $status_filter === 'running' ? 'active' : ''; ?>">运行中</a>
                        <a href="/user/hosts.php?status=stopped&kvm_only=1" class="filter-tag <?php echo $status_filter === 'stopped' ? 'active' : ''; ?>">已停止</a>
                        <a href="/user/hosts.php?status=paused&kvm_only=1" class="filter-tag <?php echo $status_filter === 'paused' ? 'active' : ''; ?>">已暂停</a>
                        <a href="/user/hosts.php?status=expired" class="filter-tag <?php echo $status_filter === 'expired' ? 'active' : ''; ?>">已过期</a>
                    </div>
                    <?php if ($kvm_count > 0): ?>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4e5969; cursor: pointer;">
                            <input type="checkbox" id="selectAllToggle" onclick="toggleSelectAll()">
                            全选KVM
                        </label>
                        <div class="batch-actions" id="batchActions" style="display: none; gap: 6px;">
                            <button onclick="batchAction('batch_start')" class="btn btn-sm btn-success">批量开机</button>
                            <button onclick="batchAction('batch_stop')" class="btn btn-sm btn-secondary">批量关机</button>
                            <button onclick="batchAction('batch_restart')" class="btn btn-sm btn-outline">批量重启</button>
                            <button onclick="batchAction('batch_suspend')" class="btn btn-sm btn-outline">批量暂停</button>
                            <button onclick="batchAction('batch_resume')" class="btn btn-sm btn-outline">批量恢复</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($hosts)): ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon">🖥️</div>
                        <h3 style="margin-bottom: 16px;">暂无主机</h3>
                        <p>您还没有购买任何主机，立即选购适合您的套餐！</p>
                        <a href="/checkout.php" class="btn btn-primary" style="margin-top: 20px;">立即购买</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($hosts as $host): ?>
                <?php
                $is_kvm = intval($host['vm_type'] === 'kvm' || (isset($host['vm_name']) && !empty($host['vm_name']) && $host['image_id'] > 0));
                $host_uuid = $host['uuid'] ?? $host['id'];
                $card_url = $is_kvm ? "/user/host_kvm.php?id=" . $host_uuid : "/user/host_detail.php?id=" . $host_uuid;
                ?>
                <div class="host-card" style="cursor: pointer; position: relative;" onclick="location.href='<?php echo $card_url; ?>'">
                    <?php if ($is_kvm): ?>
                    <div style="position: absolute; top: 12px; left: 12px; z-index: 2;" onclick="event.stopPropagation();">
                        <label style="cursor: pointer;">
                            <input type="checkbox" class="host-checkbox" data-host-id="<?php echo $host['id']; ?>" onchange="updateBatchActions()">
                        </label>
                    </div>
                    <?php endif; ?>
                    <div class="host-card-header" style="<?php echo $is_kvm ? 'padding-left: 40px;' : ''; ?>">
                        <div>
                            <h4>
                                <?php if ($is_kvm): ?>
                                    <span style="font-size:18px; margin-right:4px;"><?php
                                        $image_id = intval($host['image_id'] ?? 0);
                                        $icon = '🐧';
                                        if ($image_id > 0) {
                                            $image = Database::fetch("SELECT os_type FROM vm_images WHERE id = ?", [$image_id]);
                                            if ($image && $image['os_type'] === 'windows') $icon = '🪟';
                                        }
                                        echo $icon;
                                    ?></span>
                                    <span><?php echo e($host['vm_name'] ?: 'KVM主机'); ?></span>
                                    <span class="package" style="background:linear-gradient(135deg,#1677ff,#69b1ff); color:#fff;"><?php echo e($host['package_name']); ?> · KVM</span>
                                <?php else: ?>
                                    <?php echo e($host['mnbt_username'] ?? '主机#' . $host['id']); ?>
                                    <span class="package"><?php echo e($host['package_name']); ?></span>
                                <?php endif; ?>
                            </h4>
                        </div>
                        <?php if ($is_kvm): ?>
                            <?php
                            $power = $host['vm_power_status'] ?: 'stopped';
                            $pl = kvm_power_label($power);
                            $pc = kvm_power_color($power);
                            ?>
                            <span style="background:<?php echo $pc; ?>; color:#fff; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:600;"><?php echo e($pl); ?></span>
                        <?php else: ?>
                            <?php echo get_status_label($host['status'], 'host'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="host-info">
                        <?php if ($is_kvm): ?>
                            <div class="host-info-item">
                                <div class="label">IP地址</div>
                                <div class="value" style="font-size:13px; font-family:monospace;"><?php echo e($host['ip_address'] ?: '等待分配'); ?></div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">CPU</div>
                                <div class="value"><?php echo intval($host['vcpu'] ?? 2); ?> 核</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">内存</div>
                                <div class="value"><?php echo intval($host['memory_mb'] ?? 2048); ?> MB</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">磁盘</div>
                                <div class="value"><?php echo intval($host['disk_gb'] ?? 40); ?> GB</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">SSH端口</div>
                                <div class="value"><?php echo intval($host['ssh_port'] ?: 22); ?></div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">到期时间</div>
                                <div class="value"><?php echo format_date($host['expire_at']); ?></div>
                            </div>
                            <?php
                            $traffic_used_mb = intval($host['traffic_used'] ?? 0);
                            $traffic_limit_mb = intval($host['traffic_limit'] ?? 0);
                            if ($traffic_limit_mb > 0):
                                $traffic_used_gb = $traffic_used_mb > 0 ? round($traffic_used_mb / 1024, 2) : 0;
                                $traffic_limit_gb = round($traffic_limit_mb / 1024, 0);
                                $traffic_percent = ($traffic_used_mb / $traffic_limit_mb) * 100;
                                $bar_color = '#22c55e';
                                if ($traffic_percent >= 90) $bar_color = '#ef4444';
                                elseif ($traffic_percent >= 70) $bar_color = '#f59e0b';
                            ?>
                            <div class="host-info-item" style="flex-basis: 100%;">
                                <div class="label">流量使用</div>
                                <div class="value" style="width: 100%;">
                                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                                        <span><?php echo $traffic_used_gb; ?> GB / <?php echo $traffic_limit_gb; ?> GB</span>
                                        <span style="color: <?php echo $bar_color; ?>; font-weight: 600;"><?php echo round($traffic_percent, 0); ?>%</span>
                                    </div>
                                    <div style="width: 100%; height: 6px; background: #e5e6eb; border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?php echo min(100, $traffic_percent); ?>%; height: 100%; background: <?php echo $bar_color; ?>; border-radius: 3px;"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="host-info-item">
                                <div class="label">网页空间</div>
                                <div class="value"><?php echo $host['webdx']; ?> MB</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">数据库</div>
                                <div class="value"><?php echo $host['sqldx']; ?> MB</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">月流量</div>
                                <div class="value"><?php echo $host['sizemax']; ?> GB</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">域名绑定</div>
                                <div class="value"><?php echo $host['ymbds']; ?> 个</div>
                            </div>
                            <div class="host-info-item">
                                <div class="label">到期时间</div>
                                <div class="value"><?php echo format_date($host['expire_at']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!$is_kvm && !empty($host['frp_public_url'])): ?>
                        <div class="host-info-item" style="flex-basis: 100%; border-top: 1px dashed var(--border); padding-top: 8px;">
                            <div class="label">🌐 远程访问地址</div>
                            <div class="value"><a href="<?php echo e($host['frp_public_url']); ?>" target="_blank" style="color: var(--accent); font-weight: 600; font-size: 13px;" onclick="event.stopPropagation()"><?php echo e($host['frp_public_url']); ?></a></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="host-actions">
                        <a href="/user/renew.php?id=<?php echo $host['id']; ?>" class="btn btn-sm btn-secondary" onclick="event.stopPropagation()">续费</a>
                        <?php $transfer = $transfer_map[$host['id']] ?? null; ?>
                        <?php if ($transfer && $transfer['status'] === 'pending'): ?>
                            <span class="btn btn-sm btn-outline" style="cursor: default; opacity: 0.6;" onclick="event.stopPropagation()">转移审核中</span>
                        <?php elseif ($transfer && $transfer['status'] === 'completed'): ?>
                            <span class="btn btn-sm btn-outline" style="cursor: default; opacity: 0.6;" onclick="event.stopPropagation()">已转移给 <?php echo e($transfer['to_user_name'] ?? '其他用户'); ?></span>
                        <?php else: ?>
                            <button onclick="event.stopPropagation(); showTransferModal(<?php echo $host['id']; ?>, '<?php echo e($host['vm_name'] ?: ($host['mnbt_username'] ?: ('#' . $host['id']))); ?>')" class="btn btn-sm btn-outline">转移到其他账号</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 主机转移申请弹窗 -->
    <div class="modal-overlay" id="transferModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>转移到其他账号</h3>
                <button class="modal-close" onclick="document.getElementById('transferModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" id="transferForm">
                <input type="hidden" name="action" value="transfer_request">
                <input type="hidden" name="host_id" id="transfer_host_id">
                <div style="background:#f5f7fa; border-radius:8px; padding:12px; margin-bottom:16px;">
                    <div style="font-size:13px; color:#86909c;">主机</div>
                    <div style="font-weight:600; color:#1d2129; font-family:monospace;" id="transfer_host_name">--</div>
                </div>
                <div class="form-group">
                    <label>目标用户名 <span style="color:#ef4444;">*</span></label>
                    <input type="text" class="form-control" name="target_username" id="transfer_target_username" placeholder="请输入目标用户名" required>
                    <div style="font-size:11px; color:#1677ff; margin-top:4px;">💡 请输入对方的用户名，对方账号需为正常状态</div>
                </div>
                <div class="form-group">
                    <label>转移原因（可选）</label>
                    <textarea class="form-control" name="reason" rows="3" placeholder="请填写转移原因"></textarea>
                </div>
                <div style="padding:12px; background:#fff7e8; border-radius:8px; margin-bottom:16px; font-size:12px; color:#ff7d00;">
                    ⚠️ 提交后请等待管理员审核。审核通过后，主机将归属目标用户，您将无法再访问此主机。
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;" onclick="return confirm('确定提交转移申请？')">提交转移申请</button>
            </form>
        </div>
    </div>

    <script>
    function showTransferModal(hostId, hostName) {
        document.getElementById('transfer_host_id').value = hostId;
        document.getElementById('transfer_host_name').textContent = hostName || ('#' + hostId);
        document.getElementById('transfer_target_username').value = '';
        document.getElementById('transferModal').classList.add('active');
    }

    function toggleSelectAll() {
        var checked = document.getElementById('selectAllToggle').checked;
        var boxes = document.querySelectorAll('.host-checkbox');
        boxes.forEach(function(b) { b.checked = checked; });
        updateBatchActions();
    }

    function updateBatchActions() {
        var boxes = document.querySelectorAll('.host-checkbox:checked');
        var batchDiv = document.getElementById('batchActions');
        if (!batchDiv) return;
        if (boxes.length > 0) {
            batchDiv.style.display = 'flex';
        } else {
            batchDiv.style.display = 'none';
        }
    }

    function getSelectedHostIds() {
        var boxes = document.querySelectorAll('.host-checkbox:checked');
        var ids = [];
        boxes.forEach(function(b) { ids.push(b.dataset.hostId); });
        return ids.join(',');
    }

    function batchAction(action) {
        var ids = getSelectedHostIds();
        if (!ids) {
            alert('请先选择要操作的主机');
            return;
        }
        var count = ids.split(',').length;
        var actionNames = {
            'batch_start': '开机',
            'batch_stop': '关机',
            'batch_restart': '重启',
            'batch_forcestop': '强制关机',
            'batch_suspend': '暂停',
            'batch_resume': '恢复'
        };
        var name = actionNames[action] || action;
        if (!confirm('确定对 ' + count + ' 台主机执行' + name + '操作？')) {
            return;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="' + action + '"><input type="hidden" name="host_ids" value="' + ids + '">';
        document.body.appendChild(form);
        form.submit();
    }
    </script>

    <style>
    .filter-tag {
        display: inline-block;
        padding: 6px 14px;
        font-size: 13px;
        color: #4e5969;
        background: #f2f3f5;
        border-radius: 16px;
        text-decoration: none;
        transition: all 0.2s;
        border: 1px solid transparent;
    }
    .filter-tag:hover {
        background: #e8f3ff;
        color: #1677ff;
    }
    .filter-tag.active {
        background: linear-gradient(135deg, #1677ff, #4096ff);
        color: #fff;
        border-color: #1677ff;
    }
    .host-card {
        position: relative;
    }
    .host-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #1677ff;
    }
    </style>
</body>
</html>
