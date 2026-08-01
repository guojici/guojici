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

$network_config = kvm_get_network_config($host);

// 获取 SSH 和 VNC 的 NAT 规则
$ssh_nat_rule = Database::fetch(
    "SELECT * FROM nat_rules WHERE host_id = ? AND local_port = 22 AND status = 'active' ORDER BY id DESC LIMIT 1",
    [$host_id]
);

$vnc_nat_rule = Database::fetch(
    "SELECT * FROM nat_rules WHERE host_id = ? AND rule_name LIKE '%VNC%' AND status = 'active' ORDER BY id DESC LIMIT 1",
    [$host_id]
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网络配置 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f5f7fa; }
        .kvm-page { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .kvm-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 24px; border-radius: 12px; margin-bottom: 20px; }
        .kvm-header h1 { margin: 0 0 8px; font-size: 20px; }
        .kvm-header p { margin: 0; opacity: 0.9; font-size: 13px; }
        .kvm-card { background: #fff; border-radius: 8px; border: 1px solid #e5e6eb; padding: 24px; margin-bottom: 16px; }
        .kvm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .kvm-info-item { background: #f5f9ff; border-radius: 8px; padding: 14px; }
        .kvm-info-label { font-size: 12px; color: #86909c; margin-bottom: 6px; }
        .kvm-info-value { font-size: 16px; color: #1d2129; font-weight: 600; }
        .nav-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav-tab { padding: 10px 20px; background: #fff; border: 1px solid #e5e6eb; border-radius: 8px; text-decoration: none; color: #4e5969; font-size: 14px; transition: all 0.2s; }
        .nav-tab:hover { border-color: #1677ff; color: #1677ff; }
        .nav-tab.active { background: #1677ff; color: #fff; border-color: #1677ff; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #667eea; text-decoration: none; font-size: 14px; margin-bottom: 16px; }
        .back-link:hover { text-decoration: underline; }
        .nat-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 16px; }
        .nat-info-item { background: #fafafa; padding: 12px; border-radius: 6px; }
        .nat-info-item .label { font-size: 12px; color: #86909c; margin-bottom: 4px; }
        .nat-info-item .value { font-size: 15px; color: #1d2129; font-weight: 600; font-family: monospace; }
        .readonly-badge { display: inline-block; padding: 4px 10px; background: #fff7e6; border: 1px solid #ffd591; color: #d46b08; border-radius: 12px; font-size: 12px; margin-left: 8px; }
        
        /* 外网连接信息卡片 */
        .external-ssh-card { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #86efac; padding: 20px; border-radius: 12px; margin-bottom: 16px; }
        .external-ssh-title { font-size: 16px; color: #166534; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .ssh-command-box { background: #fff; border: 1px solid #bbf7d0; padding: 14px 18px; border-radius: 8px; font-family: 'SF Mono', Monaco, monospace; font-size: 14px; color: #166534; word-break: break-all; }
        .ssh-copy-btn { background: #22c55e; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; margin-top: 12px; }
        .ssh-copy-btn:hover { background: #16a34a; }
        .ssh-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 12px; }
        .ssh-info-item { background: rgba(255,255,255,0.7); padding: 10px; border-radius: 6px; }
        .ssh-info-item .label { font-size: 11px; color: #166534; margin-bottom: 4px; }
        .ssh-info-item .value { font-size: 14px; color: #14532d; font-weight: 600; font-family: monospace; }
        
        .no-ssh-card { background: #fef2f2; border: 1px solid #fecaca; padding: 16px; border-radius: 8px; color: #991b1b; font-size: 13px; }
    </style>
</head>
<body>
    <div class="kvm-page">
        <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="back-link">← 返回控制台</a>

        <div class="kvm-header">
            <h1>🌐 网络配置</h1>
            <p>虚拟机: <?php echo e($host['vm_name']); ?> · 查看网络参数</p>
        </div>

        <div class="nav-tabs">
            <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="nav-tab">控制台</a>
            <a href="/user/host_nat.php?id=<?php echo $host_id; ?>" class="nav-tab">🌐 NAT端口映射</a>
            <a href="/user/host_snapshots.php?id=<?php echo $host_id; ?>" class="nav-tab">📸 快照管理</a>
            <a href="/user/host_network.php?id=<?php echo $host_id; ?>" class="nav-tab active">🌐 网络配置</a>
            <a href="/user/host_firewall.php?id=<?php echo $host_id; ?>" class="nav-tab">🛡️ 防火墙</a>
            <a href="/user/host_monitor.php?id=<?php echo $host_id; ?>" class="nav-tab">📊 资源监控</a>
        </div>

        <!-- 外网 SSH 连接信息 -->
        <?php if ($ssh_nat_rule && !empty($ssh_nat_rule['remote_ip']) && !empty($ssh_nat_rule['remote_port'])): ?>
        <div class="external-ssh-card">
            <div class="external-ssh-title">🌐 外网 SSH 连接信息（IP池自动分配）</div>
            
            <div class="ssh-command-box" id="sshCommand">
                ssh <?php echo e(!empty($host['image_id']) ? 'root' : 'user'); ?>@<?php echo e($ssh_nat_rule['remote_ip']); ?> -p <?php echo intval($ssh_nat_rule['remote_port']); ?>
            </div>
            <button class="ssh-copy-btn" onclick="copySSH()">📋 复制 SSH 命令</button>
            
            <div class="ssh-info-grid">
                <div class="ssh-info-item">
                    <div class="label">公网IP地址</div>
                    <div class="value"><?php echo e($ssh_nat_rule['remote_ip']); ?></div>
                </div>
                <div class="ssh-info-item">
                    <div class="label">外网SSH端口</div>
                    <div class="value"><?php echo intval($ssh_nat_rule['remote_port']); ?></div>
                </div>
                <div class="ssh-info-item">
                    <div class="label">内网IP地址</div>
                    <div class="value"><?php echo e($ssh_nat_rule['local_ip'] ?: $host['ip_address']); ?></div>
                </div>
                <div class="ssh-info-item">
                    <div class="label">内网SSH端口</div>
                    <div class="value"><?php echo intval($ssh_nat_rule['local_port'] ?: 22); ?></div>
                </div>
                <div class="ssh-info-item">
                    <div class="label">协议</div>
                    <div class="value"><?php echo strtoupper($ssh_nat_rule['protocol'] ?? 'TCP'); ?></div>
                </div>
                <div class="ssh-info-item">
                    <div class="label">状态</div>
                    <div class="value" style="color:#22c55e;">✓ <?php echo e($ssh_nat_rule['frp_status'] ?? '在线'); ?></div>
                </div>
            </div>
            
            <div style="font-size:12px; color:#166534; margin-top:16px; padding:10px; background:rgba(255,255,255,0.5); border-radius:6px;">
                💡 <strong>使用说明：</strong>您可以直接通过上述外网IP和端口连接虚拟机，无需在内网环境。<br>
                NAT规则名称: <code><?php echo e($ssh_nat_rule['rule_name'] ?? 'SSH远程登录'); ?></code> | 创建时间: <?php echo e($ssh_nat_rule['created_at'] ?? '-'); ?>
            </div>
        </div>
        <?php else: ?>
        <div class="no-ssh-card">
            <div style="font-weight:600; margin-bottom:8px;">⚠️ 外网SSH连接未配置</div>
            当前虚拟机没有外网SSH端口映射。如需外网访问，请在 <a href="/user/host_nat.php?id=<?php echo $host_id; ?>" style="color:#b91c1c;">NAT端口映射</a> 页面添加SSH端口映射规则。
        </div>
        <?php endif; ?>

        <!-- 当前网络信息 -->
        <div class="kvm-card">
            <h3 style="margin:0 0 16px; font-size:16px; color:#1d2129;">
                📋 当前网络信息
                <span class="readonly-badge">仅查看</span>
            </h3>

            <div class="nat-info-grid">
                <div class="nat-info-item">
                    <div class="label">内网IP地址</div>
                    <div class="value"><?php echo e($network_config['ip_address'] ?: '未分配'); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">子网掩码</div>
                    <div class="value"><?php echo e($network_config['netmask']); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">网关</div>
                    <div class="value"><?php echo e($network_config['gateway'] ?: '自动获取'); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">主DNS</div>
                    <div class="value"><?php echo e($network_config['dns1']); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">备DNS</div>
                    <div class="value"><?php echo e($network_config['dns2']); ?></div>
                </div>
                <?php if (!empty($host['public_ip'])): ?>
                <div class="nat-info-item" style="background:#fff7e6;">
                    <div class="label" style="color:#ad6800;">公网IP (NAT)</div>
                    <div class="value" style="color:#d97706;"><?php echo e($host['public_ip']); ?></div>
                </div>
                <?php endif; ?>
                <div class="nat-info-item">
                    <div class="label">SSH 端口</div>
                    <div class="value"><?php echo intval($host['ssh_port'] ?: 22); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">VNC 端口</div>
                    <div class="value"><?php echo intval($host['vnc_port'] ?: 5900); ?></div>
                </div>
            </div>
        </div>

        <?php if ($vnc_nat_rule && !empty($vnc_nat_rule['remote_ip'])): ?>
        <div class="kvm-card">
            <h3 style="margin:0 0 16px; font-size:16px; color:#1d2129;">🖥️ VNC 外网连接</h3>
            <div class="nat-info-grid">
                <div class="nat-info-item">
                    <div class="label">VNC公网IP</div>
                    <div class="value"><?php echo e($vnc_nat_rule['remote_ip']); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">VNC外网端口</div>
                    <div class="value"><?php echo intval($vnc_nat_rule['remote_port']); ?></div>
                </div>
                <div class="nat-info-item">
                    <div class="label">状态</div>
                    <div class="value" style="color:#22c55e;">✓ <?php echo e($vnc_nat_rule['frp_status'] ?? '在线'); ?></div>
                </div>
            </div>
            <div style="font-size:12px; color:#86909c; margin-top:12px;">
                VNC viewer 连接地址: <code><?php echo e($vnc_nat_rule['remote_ip']); ?>:<?php echo intval($vnc_nat_rule['remote_port']); ?></code>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function copySSH() {
        var sshCmd = document.getElementById('sshCommand').textContent.trim();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(sshCmd).then(function() {
                alert('已复制到剪贴板：' + sshCmd);
            }).catch(function() {
                fallbackCopy(sshCmd);
            });
        } else {
            fallbackCopy(sshCmd);
        }
    }
    
    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('已复制到剪贴板：' + text);
    }
    </script>
</body>
</html>
