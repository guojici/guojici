<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$uid = auth_id();
$id_param = get('id', '');

if (empty($id_param)) {
    flash('error', '缺少主机参数');
    header('Location: /user/hosts.php');
    exit;
}

$host = null;
if (is_numeric($id_param)) {
    $host_id = intval($id_param);
    $host = Database::fetch("SELECT * FROM hosts WHERE id = ? AND user_id = ?", [$host_id, $uid]);
} else {
    $host_uuid = $id_param;
    $host = Database::fetch("SELECT * FROM hosts WHERE uuid = ? AND user_id = ?", [$host_uuid, $uid]);
}

if (!$host) {
    flash('error', '主机不存在或无权访问');
    header('Location: /user/hosts.php');
    exit;
}

$host_id = $host['id'];
$host_uuid = $host['uuid'] ?? $host_id;

if (empty($host['vm_name'])) {
    flash('error', '该主机不是KVM虚拟机');
    header('Location: /user/host_kvm.php?id=' . $host_uuid);
    exit;
}

$firewall_rules = firewall_get_rules($host_id, $uid);

if (is_post()) {
    $action = post('action', '');

    if ($action === 'add_firewall') {
        $rule_name = trim(post('rule_name', ''));
        $protocol = post('protocol', 'tcp');
        $port = intval(post('port', 0));
        $direction = post('direction', 'inbound');
        $rule_action = post('action_type', 'accept');
        $source_ip = trim(post('source_ip', ''));

        if (empty($rule_name)) {
            flash('error', '请输入规则名称');
        } else {
            $result = firewall_add_rule($host_id, $uid, [
                'rule_name' => $rule_name,
                'protocol' => $protocol,
                'port' => $port,
                'direction' => $direction,
                'action' => $rule_action,
                'source_ip' => $source_ip,
            ]);
            if ($result['success']) {
                flash('success', '防火墙规则已添加: ' . htmlspecialchars($rule_name));
            } else {
                flash('error', '添加规则失败: ' . $result['message']);
            }
        }
    } elseif ($action === 'toggle_firewall') {
        $rule_id = intval(post('rule_id', 0));
        $new_status = post('new_status', 'disabled');
        $result = firewall_update_rule($rule_id, $uid, ['status' => $new_status]);
        if ($result['success']) {
            flash('success', '防火墙规则状态已更新');
        } else {
            flash('error', '更新规则失败: ' . $result['message']);
        }
    } elseif ($action === 'delete_firewall') {
        $rule_id = intval(post('rule_id', 0));
        $result = firewall_delete_rule($rule_id, $uid);
        if ($result['success']) {
            flash('success', '防火墙规则已删除');
        } else {
            flash('error', '删除规则失败: ' . $result['message']);
        }
    } elseif ($action === 'sync_firewall') {
        $result = firewall_sync_all_rules($host_id, $uid);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', '同步失败: ' . $result['message']);
        }
    }

    header('Location: /user/host_firewall.php?id=' . $host_id);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>防火墙规则 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f5f7fa; }
        .kvm-page { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .kvm-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 24px; border-radius: 12px; margin-bottom: 20px; }
        .kvm-header h1 { margin: 0 0 8px; font-size: 20px; }
        .kvm-header p { margin: 0; opacity: 0.9; font-size: 13px; }
        .kvm-card { background: #fff; border-radius: 8px; border: 1px solid #e5e6eb; padding: 24px; margin-bottom: 16px; }
        .nav-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav-tab { padding: 10px 20px; background: #fff; border: 1px solid #e5e6eb; border-radius: 8px; text-decoration: none; color: #4e5969; font-size: 14px; transition: all 0.2s; }
        .nav-tab:hover { border-color: #1677ff; color: #1677ff; }
        .nav-tab.active { background: #1677ff; color: #fff; border-color: #1677ff; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-success { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
        .alert-error { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; cursor: pointer; border: none; }
        .btn-primary { background: #1677ff; color: #fff; }
        .btn-primary:hover { background: #0958d9; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        .btn-secondary:hover { background: #e5e5e5; }
        .btn-danger { background: #fff1f0; color: #ef4444; border: 1px solid #ffa39e; }
        .form-section { background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e5e6eb; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #d1d9e6; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #fafafa; padding: 12px; text-align: left; font-weight: 600; font-size: 13px; color: #4e5969; border-bottom: 1px solid #e5e6eb; }
        .data-table td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .empty-state { text-align: center; padding: 60px 20px; color: #86909c; }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #667eea; text-decoration: none; font-size: 14px; margin-bottom: 16px; }
        .back-link:hover { text-decoration: underline; }
        .fw-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .fw-count { background: #f0f5ff; padding: 8px 16px; border-radius: 20px; font-size: 13px; color: #1677ff; }
        .info-box { background: #f0f5ff; border: 1px solid #91caff; border-radius: 8px; padding: 16px; margin-top: 20px; }
        .info-box-title { font-weight: 600; color: #1677ff; margin-bottom: 8px; }
        .info-box-content { font-size: 13px; color: #4e5969; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="kvm-page">
        <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="back-link">← 返回控制台</a>

        <div class="kvm-header">
            <h1>🛡️ 防火墙规则</h1>
            <p>虚拟机: <?php echo e($host['vm_name']); ?> · 管理入站和出站网络规则</p>
        </div>

        <?php if ($msg = flash('error')): ?>
        <div class="alert alert-error"><?php echo e($msg); ?></div>
        <?php endif; ?>
        <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success"><?php echo e($msg); ?></div>
        <?php endif; ?>

        <div class="nav-tabs">
            <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="nav-tab">控制台</a>
            <a href="/user/host_nat.php?id=<?php echo $host_id; ?>" class="nav-tab">🌐 NAT端口映射</a>
            <a href="/user/host_snapshots.php?id=<?php echo $host_id; ?>" class="nav-tab">📸 快照管理</a>
            <a href="/user/host_network.php?id=<?php echo $host_id; ?>" class="nav-tab">🌐 网络配置</a>
            <a href="/user/host_firewall.php?id=<?php echo $host_id; ?>" class="nav-tab active">🛡️ 防火墙</a>
            <a href="/user/host_monitor.php?id=<?php echo $host_id; ?>" class="nav-tab">📊 资源监控</a>
        </div>

        <div class="kvm-card">
            <div class="fw-header">
                <h3 style="margin:0; font-size:16px; color:#1d2129;">防火墙规则列表</h3>
                <div style="display:flex; gap:10px; align-items:center;">
                    <div class="fw-count">🛡️ 共 <?php echo count($firewall_rules); ?> 条规则</div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="sync_firewall">
                        <button type="submit" class="btn btn-secondary" style="padding:6px 14px; font-size:13px;">
                            🔄 立即同步
                        </button>
                    </form>
                </div>
            </div>

            <div style="margin-bottom:16px; display:flex; gap:10px;">
                <button onclick="document.getElementById('firewallForm').style.display = document.getElementById('firewallForm').style.display === 'none' ? 'block' : 'none';" class="btn btn-primary">
                    ➕ 添加规则
                </button>
                <?php if (!empty($host['ip_address'])): ?>
                <span style="font-size:13px; color:#4e5969; line-height:32px;">
                    📍 虚拟机IP: <code style="background:#f0f5ff; padding:2px 6px; border-radius:4px;"><?php echo e($host['ip_address']); ?></code>
                </span>
                <?php endif; ?>
            </div>

            <div id="firewallForm" class="form-section" style="display:none;">
                <h4 style="margin:0 0 12px; font-size:14px; color:#1d2129;">➕ 添加防火墙规则</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="add_firewall">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">规则名称 *</label>
                            <input type="text" name="rule_name" class="form-control" placeholder="如: 开放SSH">
                        </div>
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">协议类型</label>
                            <select name="protocol" class="form-control">
                                <option value="tcp">TCP</option>
                                <option value="udp">UDP</option>
                                <option value="icmp">ICMP (Ping)</option>
                                <option value="all">全部协议</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">端口 (0=全部)</label>
                            <input type="number" name="port" class="form-control" value="0" min="0" max="65535" placeholder="如: 22">
                        </div>
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">方向</label>
                            <select name="direction" class="form-control">
                                <option value="inbound">入站</option>
                                <option value="outbound">出站</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">动作</label>
                            <select name="action_type" class="form-control">
                                <option value="accept">允许</option>
                                <option value="drop">丢弃</option>
                                <option value="reject">拒绝</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:12px; color:#4e5969; display:block; margin-bottom:4px;">源IP (留空=全部)</label>
                            <input type="text" name="source_ip" class="form-control" placeholder="如: 192.168.1.0/24">
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <button type="submit" class="btn btn-primary">添加规则</button>
                    </div>
                </form>
            </div>

            <?php if (empty($firewall_rules)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🛡️</div>
                <div style="font-size:16px; margin-bottom:8px;">暂无防火墙规则</div>
                <div style="font-size:13px;">所有端口默认允许访问，点击「添加规则」创建限制</div>
            </div>
            <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>规则名称</th>
                        <th>协议</th>
                        <th>端口</th>
                        <th>源IP</th>
                        <th>方向</th>
                        <th>动作</th>
                        <th>状态</th>
                        <th>应用状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($firewall_rules as $rule): ?>
                    <tr>
                        <td style="font-weight:500; color:#1d2129;"><?php echo e($rule['rule_name']); ?></td>
                        <td style="font-family:monospace;"><?php echo firewall_protocol_text($rule['protocol']); ?></td>
                        <td style="font-family:monospace;">
                            <?php
                            if (!empty($rule['port_range'])) {
                                echo e($rule['port_range']);
                            } elseif ($rule['port'] == 0) {
                                echo '全部';
                            } else {
                                echo $rule['port'];
                            }
                            ?>
                        </td>
                        <td style="font-family:monospace; font-size:12px;"><?php echo e($rule['source_ip'] ?: '全部'); ?></td>
                        <td><?php echo firewall_direction_text($rule['direction']); ?></td>
                        <td>
                            <span style="color:<?php echo $rule['action'] === 'accept' ? '#22c55e' : '#ef4444'; ?>; font-weight:500;">
                                <?php echo firewall_action_text($rule['action']); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_firewall">
                                <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $rule['status'] === 'active' ? 'disabled' : 'active'; ?>">
                                <button type="submit" style="background:none; border:none; cursor:pointer; padding:0; color:<?php echo $rule['status'] === 'active' ? '#22c55e' : '#9ca3af'; ?>; font-size:13px;">
                                    <?php echo firewall_status_text($rule['status']); ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php if ($rule['status'] === 'disabled'): ?>
                                <span style="color:#9ca3af; font-size:12px;">已禁用</span>
                            <?php elseif (!empty($rule['applied'])): ?>
                                <span style="color:#22c55e; font-size:12px;">✅ 已生效</span>
                            <?php else: ?>
                                <span style="color:#f59e0b; font-size:12px;" title="<?php echo e($rule['apply_error'] ?? ''); ?>">⚠️ 未生效</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_firewall">
                                <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 12px; font-size:12px;" onclick="return confirm('确认删除此防火墙规则？');">删除</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="info-box">
                <div class="info-box-title">💡 防火墙规则说明</div>
                <div class="info-box-content">
                    <ul style="margin:8px 0; padding-left:20px;">
                        <li><strong>入站规则</strong>：控制外部访问虚拟机的流量</li>
                        <li><strong>出站规则</strong>：控制虚拟机访问外部的流量</li>
                        <li><strong>允许</strong>：放行匹配的流量</li>
                        <li><strong>丢弃</strong>：静默丢弃匹配的流量（不返回响应）</li>
                        <li><strong>拒绝</strong>：拒绝匹配的流量（返回ICMP错误）</li>
                        <li>端口0表示匹配所有端口</li>
                        <li>源IP留空表示匹配所有来源IP（支持CIDR格式，如 192.168.1.0/24）</li>
                        <li>规则通过宿主机 iptables 的 FORWARD 链生效，针对虚拟机IP进行过滤</li>
                        <li>点击「立即同步」可将所有启用规则重新应用到防火墙</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
