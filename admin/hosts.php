<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$status = get('status', '');
$search = trim(get('search', ''));
$type = get('type', ''); // 新增：筛选主机类型

$where = '1=1';
$params = [];
if ($search) {
    $where .= " AND (mnbt_username LIKE ? OR vm_name LIKE ? OR package_name LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s];
}
if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
}
if ($type === 'kvm') {
    $where .= " AND vm_name IS NOT NULL AND vm_name != ''";
} elseif ($type === 'mnbt') {
    $where .= " AND (vm_name IS NULL OR vm_name = '')";
}

$hosts = Database::fetchAll("SELECT h.*, u.username as user_name FROM hosts h LEFT JOIN users u ON h.user_id = u.id WHERE $where ORDER BY h.id DESC LIMIT 200", $params);

if (is_post()) {
    $action = post('action');
    $hid = intval(post('host_id'));
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$hid]);
    if (!$host) { flash('error','主机不存在'); header('Location: /admin/hosts.php'); exit; }

    // 判断主机类型
    $is_kvm = !empty($host['vm_name']);

    if ($is_kvm) {
        // KVM 主机操作
        $kvm = kvm_get_manager();
        $vm_name = $host['vm_name'];

        if ($action === 'vm_start') {
            if (!$kvm->vmExists($vm_name)) {
                flash('error', '虚拟机在 libvirt 中不存在，请点击"重新创建"');
            } else {
                $result = $kvm->startVM($vm_name);
                if ($result) {
                    Database::update('hosts', ['vm_power_status' => 'running', 'status' => 'running'], 'id = ?', [$hid]);
                    flash('success', '虚拟机已启动');
                } else {
                    flash('error', '启动失败: ' . $kvm->getError());
                }
            }
        } elseif ($action === 'vm_stop') {
            if (!$kvm->vmExists($vm_name)) {
                flash('error', '虚拟机在 libvirt 中不存在');
            } else {
                $result = $kvm->stopVM($vm_name);
                if ($result) {
                    Database::update('hosts', ['vm_power_status' => 'stopped'], 'id = ?', [$hid]);
                    flash('success', '虚拟机已关机');
                } else {
                    flash('error', '关机失败: ' . $kvm->getError());
                }
            }
        } elseif ($action === 'vm_restart') {
            if (!$kvm->vmExists($vm_name)) {
                flash('error', '虚拟机在 libvirt 中不存在');
            } else {
                $result = $kvm->restartVM($vm_name);
                if ($result) {
                    Database::update('hosts', ['vm_power_status' => 'running'], 'id = ?', [$hid]);
                    flash('success', '虚拟机已重启');
                } else {
                    flash('error', '重启失败: ' . $kvm->getError());
                }
            }
        } elseif ($action === 'vm_forcestop') {
            if (!$kvm->vmExists($vm_name)) {
                flash('error', '虚拟机在 libvirt 中不存在');
            } else {
                $result = $kvm->forceStopVM($vm_name);
                if ($result) {
                    Database::update('hosts', ['vm_power_status' => 'stopped'], 'id = ?', [$hid]);
                    flash('success', '虚拟机已强制关机');
                } else {
                    flash('error', '强制关机失败: ' . $kvm->getError());
                }
            }
        } elseif ($action === 'vm_destroy') {
            if ($kvm->vmExists($vm_name)) {
                $destroy_result = $kvm->destroyVM($vm_name);
                if (!$destroy_result) {
                    flash('error', '删除虚拟机失败: ' . $kvm->getError());
                    header('Location: /admin/hosts.php');
                    exit;
                }
            }

            $cleanup_result = kvm_cleanup_host($hid);
            if (!$cleanup_result['success']) {
                flash('warning', '虚拟机已删除，但清理过程出现问题: ' . ($cleanup_result['message'] ?? ''));
            }

            // 删除快照记录
            Database::query("DELETE FROM vm_snapshots WHERE host_id = ?", [$hid]);

            // 删除防火墙规则记录
            Database::query("DELETE FROM firewall_rules WHERE host_id = ?", [$hid]);

            // 删除主机记录
            Database::query("DELETE FROM hosts WHERE id = ?", [$hid]);

            flash('success', '虚拟机已彻底删除，IP、快照、FRP规则和防火墙规则已全部清理');
        } elseif ($action === 'vm_recreate') {
            $result = kvm_recreate_vm($host);
            if ($result['success']) {
                flash('success', '虚拟机已重新创建，IP: ' . ($result['ip'] ?? '等待分配'));
            } else {
                flash('error', '重新创建失败: ' . $result['message']);
            }
        } elseif ($action === 'vm_refresh') {
            $result = kvm_refresh_status($host);
            if ($result && $result['success']) {
                flash('success', '状态已刷新: ' . ($result['state'] ?? '未知') . ', IP: ' . ($result['ip'] ?? '等待分配'));
            } else {
                flash('error', '刷新失败: ' . ($result['message'] ?? '未知错误'));
            }
        } elseif ($action === 'vm_reinstall') {
            $image_id = intval(post('image_id', 0));
            if ($image_id <= 0) {
                flash('error', '请选择系统镜像');
            } else {
                $result = kvm_reinstall($host, $image_id);
                if ($result && $result['success']) {
                    flash('success', '系统重装已启动，请等待2-5分钟');
                } else {
                    flash('error', '重装失败: ' . ($result['message'] ?? '未知错误'));
                }
            }
        } elseif ($action === 'vm_update_info') {
            $ip = trim(post('ip_address', ''));
            $ssh_port = intval(post('ssh_port', 22));
            $vnc_port = intval(post('vnc_port', 5900));
            $root_pwd = trim(post('root_password', ''));
            
            // 如果密码有变更，尝试通过 virsh 设置密码
            $pwd_changed = false;
            if (!empty($root_pwd) && $root_pwd !== ($host['root_password'] ?? '')) {
                // 获取镜像信息确定用户名
                $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$host['image_id']]);
                $username = !empty($image['default_username']) ? $image['default_username'] : 'root';
                
                // 检查虚拟机是否在运行
                $vm_state = $kvm->getVMPowerState($vm_name);
                if ($vm_state === 'running') {
                    // 通过 virsh set-user-password 设置密码
                    $cmd_result = $kvm->exec("virsh set-user-password " . escapeshellarg($vm_name) . " " . escapeshellarg($username) . " " . escapeshellarg($root_pwd) . " 2>&1");
                    if (strpos($cmd_result, 'error') === false && strpos($cmd_result, 'failed') === false) {
                        $pwd_changed = true;
                    } else {
                        // 如果 virsh 命令失败，记录警告但继续更新数据库
                        flash('warning', '密码已保存到数据库，但 virsh 设置密码失败：' . $cmd_result . '。虚拟机需要重启才能应用新密码。');
                    }
                } else {
                    flash('warning', '密码已保存到数据库，但虚拟机未运行。开机后密码将自动应用。');
                }
            }
            
            Database::update('hosts', [
                'ip_address' => $ip,
                'ssh_port' => $ssh_port,
                'vnc_port' => $vnc_port,
                'root_password' => $root_pwd,
            ], 'id = ?', [$hid]);
            
            if ($pwd_changed) {
                flash('success', '连接信息和 root 密码已更新，密码已直接写入虚拟机');
            } elseif (empty($flash_error) && empty($flash_warning)) {
                flash('success', '连接信息已更新');
            }
        }

    } else {
        // MNBT 虚拟主机操作
        $api = mnbt_api();

        if ($action === 'suspend') {
            $r = $api->suspend_host($host['mnbt_username']);
            if ($r['code'] == 200 || $r['code'] == 100) {
                Database::update('hosts', ['status' => 'suspended'], 'id = ?', [$hid]);
                flash('success', '操作完成: ' . ($r['msg'] ?? ''));
            } else {
                flash('error', 'API操作失败: ' . ($r['msg'] ?? ''));
            }
        } elseif ($action === 'unsuspend') {
            $r = $api->unsuspend_host($host['mnbt_username']);
            if ($r['code'] == 200 || $r['code'] == 100) {
                Database::update('hosts', ['status' => 'running'], 'id = ?', [$hid]);
                flash('success', '操作完成: ' . ($r['msg'] ?? ''));
            } else {
                flash('error', 'API操作失败: ' . ($r['msg'] ?? ''));
            }
        } elseif ($action === 'delete') {
            $r = $api->delete_host($host['mnbt_username']);

            // 删除NAT规则记录
            Database::query("DELETE FROM nat_rules WHERE host_id = ?", [$hid]);

            // 删除防火墙规则记录
            Database::query("DELETE FROM firewall_rules WHERE host_id = ?", [$hid]);

            // 删除主机记录
            Database::query("DELETE FROM hosts WHERE id = ?", [$hid]);

            flash('success', '虚拟主机已彻底删除');
        } elseif ($action === 'renew') {
            $months = intval(post('months', 1));
            $new_expire = date('Y-m-d', strtotime("+$months months", strtotime($host['expire_at'])));
            $r = $api->renew_host($host['mnbt_username'], $new_expire);
            if ($r['code'] == 200) {
                Database::update('hosts', ['expire_at' => $new_expire . ' 23:59:59'], 'id = ?', [$hid]);
                flash('success', '续费成功，新到期时间: ' . $new_expire);
            } else {
                flash('error', 'API操作失败: ' . ($r['msg'] ?? ''));
            }
        } elseif ($action === 'reset_pwd') {
            $new_pwd = post('new_password');
            $r = $api->reset_password($host['mnbt_username'], $new_pwd);
            if ($r['code'] == 200) {
                Database::update('hosts', ['mnbt_password' => $new_pwd], 'id = ?', [$hid]);
                flash('success', '密码重置成功');
            } else {
                flash('error', 'API操作失败: ' . ($r['msg'] ?? ''));
            }
        }
    }

    // ====== 主机转移操作（KVM和MNBT通用） ======
    if ($action === 'transfer_host') {
        $target_user_id = intval(post('target_user_id', 0));
        $transfer_reason = trim(post('transfer_reason', '管理员手动转移'));

        if ($target_user_id <= 0) {
            flash('error', '请选择目标用户');
        } elseif ($target_user_id == $host['user_id']) {
            flash('error', '目标用户与原用户相同，无需转移');
        } else {
            $target_user = Database::fetch("SELECT * FROM users WHERE id = ? AND status = 'active'", [$target_user_id]);
            if (!$target_user) {
                flash('error', '目标用户不存在或已被禁用');
            } else {
                $from_user = Database::fetch("SELECT * FROM users WHERE id = ?", [$host['user_id']]);
                Database::beginTransaction();
                try {
                    Database::update('hosts', ['user_id' => $target_user_id], 'id = ?', [$hid]);
                    @Database::insert('host_transfers', [
                        'host_id' => $hid,
                        'from_user_id' => $host['user_id'],
                        'to_user_id' => $target_user_id,
                        'status' => 'completed',
                        'admin_id' => admin_user()['id'],
                        'admin_remark' => $transfer_reason,
                        'processed_at' => date('Y-m-d H:i:s'),
                        'completed_at' => date('Y-m-d H:i:s'),
                    ]);
                    @Database::insert('admin_logs', [
                        'admin_id' => admin_user()['id'],
                        'action' => 'host_transfer',
                        'target_type' => 'host',
                        'target_id' => $hid,
                        'detail' => '主机转移: ' . ($from_user['username'] ?? '未知') . ' -> ' . $target_user['username'] . ' 原因: ' . $transfer_reason,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ]);
                    Database::commit();
                    send_notification($host['user_id'], 'system', '主机转移通知',
                        '您的主机「' . ($host['vm_name'] ?: $host['mnbt_username']) . '」已被管理员转移给用户「' . $target_user['username'] . '」。原因：' . $transfer_reason,
                        'host', $hid);
                    send_notification($target_user_id, 'system', '主机转移通知',
                        '用户「' . ($from_user['username'] ?? '未知') . '」的主机「' . ($host['vm_name'] ?: $host['mnbt_username']) . '」已转移给您。请及时查看。',
                        'host', $hid);
                    flash('success', '主机已成功转移给用户「' . $target_user['username'] . '」');
                } catch (Exception $e) {
                    Database::rollBack();
                    flash('error', '转移失败: ' . $e->getMessage());
                }
            }
        }
        header('Location: /admin/hosts.php?type=' . $type . '&status=' . $status . '&search=' . urlencode($search));
        exit;
    }

    header('Location: /admin/hosts.php?type=' . $type . '&status=' . $status . '&search=' . urlencode($search));
    exit;
}

// 获取可用的镜像列表（用于重装系统）
$images = Database::fetchAll("SELECT id, name, os_type FROM vm_images WHERE status = 'active' ORDER BY id");

// 获取活跃用户列表（用于主机转移）
$users_list = Database::fetchAll("SELECT id, username, email FROM users WHERE status = 'active' ORDER BY username");

// 获取KVM节点列表（用于迁移）
$kvm_nodes = [];
try {
    $kvm_nodes = Database::fetchAll("SELECT id, node_name, node_ip, status, cpu_usage, memory_usage, current_vms, max_vms FROM kvm_nodes ORDER BY node_name");
} catch (Exception $e) {}

// 同步检查结果
$sync_result = null;
$show_sync = get('sync', '') === '1';

// 处理同步相关操作
if (is_post()) {
    $action = post('action');
    
    if ($action === 'sync_check') {
        $sync_result = kvm_sync_check();
        $show_sync = true;
    } elseif ($action === 'sync_cleanup') {
        $host_ids = post('host_ids');
        if (is_string($host_ids)) {
            $host_ids = array_map('intval', explode(',', $host_ids));
        }
        $result = kvm_sync_cleanup($host_ids);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        header('Location: /admin/hosts.php?type=kvm&sync=1');
        exit;
    } elseif ($action === 'cleanup_tokens') {
        $result = kvm_cleanup_tokens();
        flash('success', $result['message']);
        header('Location: /admin/hosts.php?type=kvm&sync=1');
        exit;
    } elseif ($action === 'batch_kvm_action') {
        $vm_action = post('vm_action', '');
        $host_ids = post('host_ids', '');
        $id_array = array_filter(array_map('intval', explode(',', $host_ids)));
        if (empty($id_array)) {
            flash('error', '请先选择要操作的主机');
        } elseif (count($id_array) > 50) {
            flash('error', '一次最多操作50台主机');
        } else {
            $placeholders = implode(',', array_fill(0, count($id_array), '?'));
            $hosts_batch = Database::fetchAll("SELECT * FROM hosts WHERE id IN ($placeholders) AND vm_name IS NOT NULL AND vm_name != ''", $id_array);
            if (empty($hosts_batch)) {
                flash('error', '未找到可操作的KVM主机');
            } else {
                $result = kvm_batch_action($hosts_batch, $vm_action);
                flash('success', '批量操作完成：成功 ' . $result['success'] . ' 台，失败 ' . $result['failed'] . ' 台');
            }
        }
        header('Location: /admin/hosts.php?type=' . $type . '&status=' . $status . '&search=' . urlencode($search));
        exit;
    } elseif ($action === 'vm_migrate') {
        $host_id = intval(post('host_id', 0));
        $target_node_id = intval(post('target_node_id', 0));
        $host = Database::fetch("SELECT * FROM hosts WHERE id = ?", [$host_id]);
        if (!$host) {
            flash('error', '主机不存在');
        } elseif (empty($host['vm_name'])) {
            flash('error', '仅KVM虚拟机支持迁移');
        } elseif ($target_node_id <= 0) {
            flash('error', '请选择目标节点');
        } else {
            $result = kvm_migrate_vm($host, $target_node_id, ['live' => true]);
            if ($result['success']) {
                flash('success', $result['message']);
            } else {
                flash('error', $result['message']);
            }
        }
        header('Location: /admin/hosts.php?type=' . $type . '&status=' . $status . '&search=' . urlencode($search));
        exit;
    }
}

// 如果请求显示同步结果
if ($show_sync && !$sync_result) {
    $sync_result = kvm_sync_check();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主机管理 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .host-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .type-kvm { background: #e6f4ff; color: #1677ff; }
        .type-mnbt { background: #f6ffed; color: #52c41a; }
        .vm-info {
            font-size: 11px;
            color: #86909c;
            margin-top: 4px;
        }
        .action-btns { white-space: nowrap; }
        .action-btns form { display: inline-block; margin-right: 4px; }
        .kvm-panel {
            background: #f9fafb;
            border: 1px solid #e5e6eb;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
        }
        .kvm-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        .kvm-item label { font-size: 12px; color: #86909c; }
        .kvm-item input { width: 100%; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">主机管理</h1>
                    <p class="page-subtitle">管理所有虚拟主机和 KVM 虚拟机</p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="sync_check">
                        <button type="submit" class="btn btn-outline">🔍 同步检查</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="cleanup_tokens">
                        <button type="submit" class="btn btn-outline" onclick="return confirm('确定清理过期的VNC Token？')">🧹 清理Token</button>
                    </form>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo flash('success'); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo flash('error'); ?></div><?php endif; ?>

            <?php if ($sync_result): ?>
            <!-- 同步检查结果 -->
            <div class="card" style="margin-bottom: 16px;">
                <div class="card-title">
                    <span>🔍 VM 同步检查结果</span>
                    <span style="font-size: 12px; color: #86909c;">
                        数据库: <?php echo $sync_result['db_count']; ?> 台 | 
                        Libvirt: <?php echo $sync_result['libvirt_count']; ?> 台 | 
                        缺失: <?php echo count($sync_result['missing']); ?> 台 | 
                        孤儿: <?php echo count($sync_result['orphan']); ?> 台
                    </span>
                </div>
                
                <?php if (count($sync_result['missing']) > 0): ?>
                <div style="padding: 16px; background: rgba(239,68,68,0.05); border-radius: 8px; margin-bottom: 12px;">
                    <div style="font-weight: 600; color: #ef4444; margin-bottom: 8px;">⚠️ 数据库中存在但 Libvirt 中不存在的虚拟机</div>
                    <table class="data-table" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>虚拟机名</th>
                                <th>VNC端口</th>
                                <th>数据库状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sync_result['missing'] as $vm): ?>
                            <tr>
                                <td><?php echo $vm['id']; ?></td>
                                <td><?php echo e($vm['vm_name']); ?></td>
                                <td><?php echo $vm['db_vnc_port'] ?: '-'; ?></td>
                                <td><?php echo e($vm['db_status']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="sync_cleanup">
                                        <input type="hidden" name="host_ids" value="<?php echo $vm['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定清理此虚拟机记录？')">清理记录</button>
                                    </form>
                                    <a href="/admin/hosts.php?type=kvm&search=<?php echo urlencode($vm['vm_name']); ?>" class="btn btn-sm btn-outline">查看详情</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($sync_result['missing']) > 1): ?>
                    <form method="POST" style="margin-top: 12px;">
                        <input type="hidden" name="action" value="sync_cleanup">
                        <input type="hidden" name="host_ids" value="<?php echo implode(',', array_column($sync_result['missing'], 'id')); ?>">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('确定批量清理 <?php echo count($sync_result['missing']); ?> 条缺失的虚拟机记录？')">批量清理所有缺失记录</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (count($sync_result['orphan']) > 0): ?>
                <div style="padding: 16px; background: rgba(245,158,11,0.05); border-radius: 8px; margin-bottom: 12px;">
                    <div style="font-weight: 600; color: #f59e0b; margin-bottom: 8px;">⚠️ Libvirt 中存在但数据库中没有记录的孤儿虚拟机</div>
                    <table class="data-table" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th>虚拟机名</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sync_result['orphan'] as $vm): ?>
                            <tr>
                                <td><?php echo e($vm['name']); ?></td>
                                <td><?php echo e($vm['state']); ?></td>
                                <td>
                                    <span style="font-size: 12px; color: #86909c;">建议手动在服务器执行: virsh destroy <?php echo e($vm['name']); ?> && virsh undefine <?php echo e($vm['name']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if (count($sync_result['missing']) === 0 && count($sync_result['orphan']) === 0): ?>
                <div style="padding: 16px; background: rgba(34,197,94,0.05); border-radius: 8px; text-align: center;">
                    <div style="font-weight: 600; color: #22c55e;">✓ 所有虚拟机同步状态正常</div>
                    <div style="font-size: 12px; color: #86909c; margin-top: 4px;">数据库记录与 Libvirt 虚拟机完全匹配</div>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 12px; font-size: 12px; color: #86909c;">
                    💡 说明："缺失"的虚拟机记录需要清理或重新创建；"孤儿"虚拟机建议在服务器上手动删除以释放资源。
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="filters">
                    <div style="flex: 2;">
                        <input type="text" class="form-control" placeholder="搜索主机账号/虚拟机名/套餐..." value="<?php echo e($search); ?>" onkeypress="if(event.key==='Enter') window.location.href='/admin/hosts.php?search='+encodeURIComponent(this.value)+'&status=<?php echo $status; ?>&type=<?php echo $type; ?>';">
                    </div>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/hosts.php?type='+this.value+'&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部类型</option>
                        <option value="mnbt" <?php echo $type === 'mnbt' ? 'selected' : ''; ?>>虚拟主机</option>
                        <option value="kvm" <?php echo $type === 'kvm' ? 'selected' : ''; ?>>KVM 虚拟机</option>
                    </select>
                    <select class="form-control" style="flex: 1;" onchange="window.location.href='/admin/hosts.php?status='+this.value+'&type=<?php echo $type; ?>&search=<?php echo urlencode($search); ?>';">
                        <option value="">全部状态</option>
                        <option value="creating" <?php echo $status === 'creating' ? 'selected' : ''; ?>>创建中</option>
                        <option value="running" <?php echo $status === 'running' ? 'selected' : ''; ?>>运行中</option>
                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>已暂停</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                    </select>
                </div>
            </div>

            <!-- 批量操作栏 -->
            <?php if ($type === 'kvm' || !$type): ?>
            <div class="card" style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #4e5969; cursor: pointer;">
                            <input type="checkbox" id="adminSelectAll" onclick="toggleAdminSelectAll()">
                            全选KVM主机
                        </label>
                        <span id="selectedCount" style="font-size: 12px; color: #86909c;">已选 0 台</span>
                    </div>
                    <div class="admin-batch-actions" id="adminBatchActions" style="display: none; gap: 8px;">
                        <button onclick="adminBatchAction('start')" class="btn btn-sm btn-success">批量开机</button>
                        <button onclick="adminBatchAction('stop')" class="btn btn-sm btn-secondary">批量关机</button>
                        <button onclick="adminBatchAction('restart')" class="btn btn-sm btn-outline">批量重启</button>
                        <button onclick="adminBatchAction('suspend')" class="btn btn-sm btn-warning">批量暂停</button>
                        <button onclick="adminBatchAction('resume')" class="btn btn-sm btn-success">批量恢复</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>类型</th>
                                <th>用户</th>
                                <th>主机名/账号</th>
                                <th>连接信息</th>
                                <th>套餐</th>
                                <th>到期时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hosts as $h): ?>
                            <?php $is_kvm = !empty($h['vm_name']); ?>
                            <tr>
                                <td>
                                    <?php if ($is_kvm): ?>
                                    <input type="checkbox" class="admin-host-checkbox" data-host-id="<?php echo $h['id']; ?>" onchange="updateAdminSelectedCount()">
                                    <?php endif; ?>
                                    <?php echo $h['id']; ?>
                                </td>
                                <td>
                                    <span class="host-type-badge <?php echo $is_kvm ? 'type-kvm' : 'type-mnbt'; ?>">
                                        <?php echo $is_kvm ? 'KVM' : '虚拟主机'; ?>
                                    </span>
                                </td>
                                <td><?php echo e($h['user_name']); ?></td>
                                <td>
                                    <strong><?php echo e($h['mnbt_username'] ?? ($h['vm_name'] ?: '-')); ?></strong>
                                    <?php if ($is_kvm): ?>
                                    <div class="vm-info">
                                        IP: <?php echo e($h['ip_address'] ?: '等待分配'); ?> | SSH: <?php echo intval($h['ssh_port'] ?? 22); ?> | VNC: <?php echo intval($h['vnc_port'] ?? 5900); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family: monospace; font-size: 12px;">
                                    <?php if ($is_kvm): ?>
                                        <div>Root: <?php echo !empty($h['root_password']) ? '<span style="cursor:pointer;color:#1677ff;" onclick="this.textContent=\'' . e($h['root_password']) . '\'">点击查看</span>' : '未设置'; ?></div>
                                    <?php else: ?>
                                        <?php echo e($h['mnbt_password'] ?? '-'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($h['package_name']); ?></td>
                                <td><?php echo format_date($h['expire_at']); ?></td>
                                <td>
                                    <?php echo get_status_label($h['status'], 'host'); ?>
                                    <?php if ($is_kvm && !empty($h['vm_power_status'])): ?>
                                        <span style="font-size:11px;color:#86909c;">(<?php echo e($h['vm_power_status']); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-btns">
                                    <?php if ($is_kvm): ?>
                                        <!-- KVM 操作按钮 -->
                                        <a href="/user/host_kvm.php?id=<?php echo $h['uuid'] ?? $h['id']; ?>" class="btn btn-sm btn-primary" title="进入控制台">🖥</a>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_start">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success" title="开机">▶</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_stop">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" title="关机">⏹</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_restart">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" title="重启">⟳</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_refresh">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" title="刷新状态">↻</button>
                                        </form>
                                        <button onclick="showKvmInfo(<?php echo $h['id']; ?>, '<?php echo e($h['ip_address'] ?? ''); ?>', '<?php echo intval($h['ssh_port'] ?? 22); ?>', '<?php echo intval($h['vnc_port'] ?? 5900); ?>', '<?php echo e($h['root_password'] ?? ''); ?>', '<?php echo e($h['vm_power_status'] ?? ''); ?>')" class="btn btn-sm btn-outline" title="编辑连接信息和密码">🔐</button>
                                        <button onclick="showReinstall(<?php echo $h['id']; ?>)" class="btn btn-sm btn-outline" title="重装系统">🔄</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_recreate">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('虚拟机将重新创建，原有数据丢失，确认？')" title="重新创建">🔧</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="vm_destroy">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除此虚拟机？')" title="删除">✕</button>
                                        </form>
                                        <?php if (!empty($kvm_nodes)): ?>
                                        <button onclick="showMigrate(<?php echo $h['id']; ?>, '<?php echo e($h['vm_name'] ?: '#'.$h['id']); ?>')" class="btn btn-sm btn-outline" title="迁移到其他节点">🔄 迁移</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- MNBT 虚拟主机操作按钮 -->
                                        <?php if ($h['status'] == 'running'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="suspend">
                                                <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('确定暂停此主机？')">暂停</button>
                                            </form>
                                        <?php elseif ($h['status'] == 'suspended'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="unsuspend">
                                                <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('确定解除暂停？')">恢复</button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="showRenew(<?php echo $h['id']; ?>)" class="btn btn-sm btn-primary">续费</button>
                                        <button onclick="showReset(<?php echo $h['id']; ?>)" class="btn btn-sm btn-outline">改密</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="host_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除此主机？')">删除</button>
                                        </form>
                                    <?php endif; ?>
                                    <button onclick="showTransfer(<?php echo $h['id']; ?>, '<?php echo e($h['user_name']); ?>', '<?php echo e($h['mnbt_username'] ?? ($h['vm_name'] ?: '#'.$h['id'])); ?>')" class="btn btn-sm btn-outline" title="转移到其他账号">🔀</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 续费弹窗（MNBT） -->
    <div class="modal-overlay" id="renewModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>主机续费</h3>
                <button class="modal-close" onclick="document.getElementById('renewModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="renew">
                <input type="hidden" name="host_id" id="renew_host_id">
                <div class="form-group">
                    <label>续费月数</label>
                    <select class="form-control" name="months">
                        <option value="1">1 个月</option>
                        <option value="3">3 个月</option>
                        <option value="6">6 个月</option>
                        <option value="12">12 个月</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认续费</button>
            </form>
        </div>
    </div>

    <!-- 重置密码弹窗（MNBT） -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>重置主机密码</h3>
                <button class="modal-close" onclick="document.getElementById('resetModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_pwd">
                <input type="hidden" name="host_id" id="reset_host_id">
                <div class="form-group">
                    <label>新密码</label>
                    <input type="text" class="form-control" name="new_password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认重置</button>
            </form>
        </div>
    </div>

    <!-- KVM 连接信息编辑弹窗 -->
    <div class="modal-overlay" id="kvmInfoModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>编辑 KVM 连接信息</h3>
                <button class="modal-close" onclick="document.getElementById('kvmInfoModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" onsubmit="return confirm('确认修改连接信息？\n\n注意：如果修改了 root 密码且虚拟机正在运行，密码将直接写入虚拟机。');">
                <input type="hidden" name="action" value="vm_update_info">
                <input type="hidden" name="host_id" id="kvm_info_host_id">
                <div class="form-group">
                    <label>IP 地址</label>
                    <input type="text" class="form-control" name="ip_address" id="kvm_ip" placeholder="如 192.168.1.100">
                </div>
                <div class="form-group">
                    <label>SSH 端口</label>
                    <input type="number" class="form-control" name="ssh_port" id="kvm_ssh_port" value="22">
                </div>
                <div class="form-group">
                    <label>VNC 端口</label>
                    <input type="number" class="form-control" name="vnc_port" id="kvm_vnc_port" value="5900">
                </div>
                <div class="form-group">
                    <label>Root 密码</label>
                    <input type="text" class="form-control" name="root_password" id="kvm_root_pwd" placeholder="留空则不修改密码">
                    <div style="font-size:11px; color:#1677ff; margin-top:4px;">💡 修改后密码将直接写入虚拟机（需要虚拟机正在运行）</div>
                </div>
                <div class="form-group">
                    <label>虚拟机状态</label>
                    <div id="kvm_vm_state" style="padding:8px 12px; background:#f5f7fa; border-radius:6px; font-size:13px;">--</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">💾 保存并更新虚拟机密码</button>
            </form>
        </div>
    </div>

    <!-- 重装系统弹窗（KVM） -->
    <div class="modal-overlay" id="reinstallModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>重装系统</h3>
                <button class="modal-close" onclick="document.getElementById('reinstallModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="vm_reinstall">
                <input type="hidden" name="host_id" id="reinstall_host_id">
                <div class="form-group">
                    <label>选择系统镜像</label>
                    <select class="form-control" name="image_id" required>
                        <?php foreach ($images as $img): ?>
                            <option value="<?php echo $img['id']; ?>"><?php echo e($img['name']); ?> (<?php echo e($img['os_type']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="color:#ef4444;">⚠️ 警告：重装系统会清空磁盘数据</label>
                </div>
                <button type="submit" class="btn btn-danger" style="width: 100%;" onclick="return confirm('确定重装系统？原有数据将丢失！')">确认重装</button>
            </form>
        </div>
    </div>

    <!-- 主机转移弹窗 -->
    <div class="modal-overlay" id="transferModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>主机转移到其他账号</h3>
                <button class="modal-close" onclick="document.getElementById('transferModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" onsubmit="return confirm('确认转移此主机？\n\n转移后主机将归属目标用户，原用户将无法再访问此主机。')">
                <input type="hidden" name="action" value="transfer_host">
                <input type="hidden" name="host_id" id="transfer_host_id">
                <div class="form-group">
                    <label>当前归属用户</label>
                    <div id="transfer_current_user" style="padding:8px 12px; background:#f5f7fa; border-radius:6px; font-size:13px; color:#86909c;">--</div>
                </div>
                <div class="form-group">
                    <label>主机标识</label>
                    <div id="transfer_host_name" style="padding:8px 12px; background:#f5f7fa; border-radius:6px; font-size:13px; color:#86909c; font-family:monospace;">--</div>
                </div>
                <div class="form-group">
                    <label>目标用户 <span style="color:#ef4444;">*</span></label>
                    <select class="form-control" name="target_user_id" id="transfer_target_user" required>
                        <option value="">请选择目标用户</option>
                        <?php foreach ($users_list as $u): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo e($u['username']); ?> (ID: <?php echo $u['id']; ?><?php echo !empty($u['email']) ? ' · ' . e($u['email']) : ''; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px; color:#1677ff; margin-top:4px;">💡 仅显示状态正常的活跃用户</div>
                </div>
                <div class="form-group">
                    <label>转移原因</label>
                    <textarea class="form-control" name="transfer_reason" rows="3" placeholder="请填写转移原因（可选）"></textarea>
                </div>
                <div style="padding:10px; background:rgba(245,158,11,0.08); border-radius:6px; font-size:12px; color:#b45309; margin-bottom:12px;">
                    ⚠️ <strong>注意：</strong>转移后主机将完全归属目标用户，包括所有数据和配置。此操作会记录到转移日志并通知双方用户。
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">确认转移</button>
            </form>
        </div>
    </div>

    <!-- KVM迁移弹窗 -->
    <div class="modal-overlay" id="migrateModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>迁移虚拟机到其他节点</h3>
                <button class="modal-close" onclick="document.getElementById('migrateModal').classList.remove('active')">&times;</button>
            </div>
            <form method="POST" onsubmit="return confirm('确认迁移此虚拟机？\n\n此操作将执行在线热迁移，需要共享存储支持。')">
                <input type="hidden" name="action" value="vm_migrate">
                <input type="hidden" name="host_id" id="migrate_host_id">
                <div class="form-group">
                    <label>虚拟机</label>
                    <div id="migrate_vm_name" style="padding:8px 12px; background:#f5f7fa; border-radius:6px; font-size:13px; color:#86909c; font-family:monospace;">--</div>
                </div>
                <div class="form-group">
                    <label>目标节点 <span style="color:#ef4444;">*</span></label>
                    <select class="form-control" name="target_node_id" id="migrate_target_node" required>
                        <option value="">请选择目标节点</option>
                        <?php foreach ($kvm_nodes as $node): ?>
                            <option value="<?php echo $node['id']; ?>">
                                <?php echo e($node['node_name']); ?> (<?php echo e($node['node_ip']); ?>)
                                <?php if ($node['status'] !== 'online'): ?>
                                    <span style="color:#ef4444;">(离线)</span>
                                <?php else: ?>
                                    <span style="color:#22c55e;">在线</span> · CPU: <?php echo $node['cpu_usage']; ?>% · MEM: <?php echo $node['memory_usage']; ?>% · VM: <?php echo $node['current_vms']; ?>/<?php echo $node['max_vms']; ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px; color:#1677ff; margin-top:4px;">💡 推荐选择CPU和内存使用率较低的在线节点</div>
                </div>
                <div style="padding:10px; background:rgba(245,158,11,0.08); border-radius:6px; font-size:12px; color:#b45309; margin-bottom:12px;">
                    ⚠️ <strong>注意：</strong>热迁移需要源节点和目标节点共享存储（如NFS），否则迁移将失败。建议在迁移前确保存储已正确配置。
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">开始迁移</button>
            </form>
        </div>
    </div>

    <script>
        function showRenew(id) {
            document.getElementById('renew_host_id').value = id;
            document.getElementById('renewModal').classList.add('active');
        }
        function showReset(id) {
            document.getElementById('reset_host_id').value = id;
            document.getElementById('resetModal').classList.add('active');
        }
        function showKvmInfo(id, ip, ssh, vnc, pwd, vmState) {
            document.getElementById('kvm_info_host_id').value = id;
            document.getElementById('kvm_ip').value = ip || '';
            document.getElementById('kvm_ssh_port').value = ssh || 22;
            document.getElementById('kvm_vnc_port').value = vnc || 5900;
            document.getElementById('kvm_root_pwd').value = pwd || '';
            
            // 显示虚拟机状态
            var stateDiv = document.getElementById('kvm_vm_state');
            var stateText = vmState || '--';
            var stateColor = '#86909c';
            if (vmState === 'running') {
                stateColor = '#22c55e';
                stateText = '🟢 运行中 - 密码修改将直接生效';
            } else if (vmState === 'stopped') {
                stateColor = '#ef4444';
                stateText = '🔴 已关机 - 密码将在下次开机时应用';
            } else if (vmState === 'shut off') {
                stateColor = '#ef4444';
                stateText = '🔴 已关机 - 密码将在下次开机时应用';
            } else {
                stateText = '⚪ ' + stateText + ' - 状态未知';
            }
            stateDiv.innerHTML = '<span style="color:' + stateColor + '; font-weight:600;">' + stateText + '</span>';
            
            document.getElementById('kvmInfoModal').classList.add('active');
        }
        function showReinstall(id) {
            document.getElementById('reinstall_host_id').value = id;
            document.getElementById('reinstallModal').classList.add('active');
        }
        function showTransfer(id, userName, hostName) {
            document.getElementById('transfer_host_id').value = id;
            document.getElementById('transfer_current_user').textContent = userName || '--';
            document.getElementById('transfer_host_name').textContent = hostName || ('#' + id);
            document.getElementById('transfer_target_user').value = '';
            document.getElementById('transferModal').classList.add('active');
        }
        function showMigrate(id, vmName) {
            document.getElementById('migrate_host_id').value = id;
            document.getElementById('migrate_vm_name').textContent = vmName || ('#' + id);
            document.getElementById('migrate_target_node').value = '';
            document.getElementById('migrateModal').classList.add('active');
        }

        function toggleAdminSelectAll() {
            var checked = document.getElementById('adminSelectAll').checked;
            var boxes = document.querySelectorAll('.admin-host-checkbox');
            boxes.forEach(function(b) { b.checked = checked; });
            updateAdminSelectedCount();
        }

        function updateAdminSelectedCount() {
            var boxes = document.querySelectorAll('.admin-host-checkbox:checked');
            var count = boxes.length;
            document.getElementById('selectedCount').textContent = '已选 ' + count + ' 台';
            var batchDiv = document.getElementById('adminBatchActions');
            if (batchDiv) {
                batchDiv.style.display = count > 0 ? 'flex' : 'none';
            }
        }

        function adminBatchAction(action) {
            var boxes = document.querySelectorAll('.admin-host-checkbox:checked');
            var ids = [];
            boxes.forEach(function(b) { ids.push(b.dataset.hostId); });
            if (ids.length === 0) {
                alert('请先选择要操作的主机');
                return;
            }
            var actionNames = {
                'start': '开机',
                'stop': '关机',
                'restart': '重启',
                'suspend': '暂停',
                'resume': '恢复'
            };
            var name = actionNames[action] || action;
            if (!confirm('确定对 ' + ids.length + ' 台主机执行' + name + '操作？')) {
                return;
            }

            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="batch_kvm_action"><input type="hidden" name="vm_action" value="' + action + '"><input type="hidden" name="host_ids" value="' + ids.join(',') + '">';
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>