<?php
require_once __DIR__ . '/config/helper.php';
require_auth();

$user = auth_user();
$uid = auth_id();

$order_no = trim(get('order_no', ''));

$order = Database::fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [$order_no, $uid]);
if (!$order) {
    flash('error', '订单不存在');
    header('Location: /user/orders.php');
    exit;
}

if (!in_array($order['status'], ['paid', 'processing', 'completed'])) {
    flash('error', '订单未支付');
    header('Location: /checkout.php?order_id=' . urlencode($order_no));
    exit;
}

$pkg_info = json_decode($order['package_info'] ?? '{}', true);
$image_id = intval($pkg_info['image_id'] ?? 0);
$is_kvm_order = ($image_id > 0);

$image = null;
if ($is_kvm_order) {
    $image = Database::fetch("SELECT * FROM vm_images WHERE id = ?", [$image_id]);
}

$existing_host = Database::fetch("SELECT * FROM hosts WHERE order_id = ?", [$order['id']]);
$host_created = !empty($existing_host);
$host_id = $existing_host ? intval($existing_host['id']) : 0;
$host_uuid = $existing_host ? ($existing_host['uuid'] ?? $host_id) : 0;
$host = $existing_host;

$is_processing = ($order['status'] === 'processing');
$is_completed = ($order['status'] === 'completed' && $host_created);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>正在创建主机 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            background: var(--bg-page);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .creating-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 40px 48px;
            max-width: 560px;
            width: 100%;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            border: 1px solid var(--border);
        }
        .success-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #fff;
        }
        .error-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #fff;
        }
        .loading-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }
        .title {
            font-size: 22px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.6;
            text-align: center;
        }
        .host-info {
            background: var(--bg-light);
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 24px;
        }
        .host-info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .host-info-row:last-child {
            border-bottom: none;
        }
        .host-info-label {
            color: var(--text-secondary);
            font-size: 13px;
        }
        .host-info-value {
            color: var(--text-primary);
            font-size: 13px;
            font-weight: 500;
        }
        .btn {
            display: inline-block;
            padding: 10px 24px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
            border: none;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--primary-shadow);
        }
        .btn-outline {
            background: #fff;
            color: var(--primary);
            border: 1px solid var(--primary);
            margin-left: 12px;
        }
        .btn-outline:hover {
            background: var(--primary-lighter);
        }
        .btn-danger {
            background: var(--primary);
            color: #fff;
            border: none;
        }
        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .step-done { color: var(--success); }
        .step-active { color: var(--primary); font-weight: 500; }
        .step-pending { color: var(--text-secondary); }
        .dot-done { background: var(--success); }
        .dot-active { background: var(--primary); animation: pulse 1.5s infinite; }
        .dot-pending { background: var(--border); }
        .creating-card .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
    </style>
</head>
<body>
    <!-- 创建成功 -->
    <?php if ($is_completed && $host): ?>
    <div class="creating-card">
        <div class="success-icon">✓</div>
        <h1 class="title">主机创建成功！</h1>
        <p class="subtitle">
            您的 <?php echo e($order['package_name']); ?> 已成功创建并开通服务。
            <?php if ($is_kvm_order): ?>
            请牢记以下登录信息。
            <?php endif; ?>
        </p>
        
        <div class="host-info">
            <div class="host-info-row">
                <span class="host-info-label">主机名称</span>
                <span class="host-info-value"><?php echo e($host['vm_name'] ?? $host['package_name']); ?></span>
            </div>
            <?php if ($is_kvm_order && !empty($host['ip_address'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">内网IP</span>
                <span class="host-info-value" style="font-family: monospace;"><?php echo e($host['ip_address']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($is_kvm_order && !empty($host['public_ip'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">公网IP</span>
                <span class="host-info-value" style="font-family: monospace; color: var(--primary);"><?php echo e($host['public_ip']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($is_kvm_order && !empty($host['ssh_port']) && $host['ssh_port'] != 22): ?>
            <div class="host-info-row">
                <span class="host-info-label">SSH端口</span>
                <span class="host-info-value" style="font-family: monospace;"><?php echo e($host['ssh_port']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($is_kvm_order && !empty($host['root_password'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">Root密码</span>
                <span class="host-info-value" style="font-family: monospace; background: #fff; padding: 2px 8px; border-radius: 4px;"><?php echo e($host['root_password']); ?></span>
            </div>
            <?php elseif ($is_kvm_order && !empty($pkg_info['root_password'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">Root密码</span>
                <span class="host-info-value" style="font-family: monospace; background: #fff; padding: 2px 8px; border-radius: 4px;"><?php echo e($pkg_info['root_password']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!$is_kvm_order && !empty($host['mnbt_username'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">主机账号</span>
                <span class="host-info-value"><?php echo e($host['mnbt_username']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!$is_kvm_order && !empty($host['mnbt_password'])): ?>
            <div class="host-info-row">
                <span class="host-info-label">主机密码</span>
                <span class="host-info-value" style="font-family: monospace; background: #fff; padding: 2px 8px; border-radius: 4px;"><?php echo e($host['mnbt_password']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!$is_kvm_order && !empty($host['frp_public_url'])): ?>
            <div class="host-info-row" style="background: rgba(34,197,94,0.05); padding: 10px 12px; border-radius: 6px; border: 1px solid rgba(34,197,94,0.15); margin: 8px 0;">
                <span class="host-info-label" style="color: var(--success); font-weight: 600;">🌐 公网访问地址</span>
                <span class="host-info-value" style="color: var(--success); font-weight: 600; font-family: monospace;"><?php echo e($host['frp_public_url']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($image): ?>
            <div class="host-info-row">
                <span class="host-info-label">操作系统</span>
                <span class="host-info-value"><?php echo e($image['name']); ?></span>
            </div>
            <?php endif; ?>
            <div class="host-info-row">
                <span class="host-info-label">服务周期</span>
                <span class="host-info-value"><?php echo $order['duration']; ?> 个月</span>
            </div>
            <div class="host-info-row">
                <span class="host-info-label">到期时间</span>
                <span class="host-info-value"><?php echo e($host['expire_at']); ?></span>
            </div>
        </div>
        
        <div style="margin-top: 24px; text-align: center;">
            <?php if ($is_kvm_order): ?>
            <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="btn btn-primary">前往控制台</a>
            <?php else: ?>
            <a href="/user/host_detail.php?id=<?php echo $host_uuid; ?>" class="btn btn-primary">前往管理</a>
            <?php endif; ?>
            <a href="/user/orders.php" class="btn btn-outline">查看订单</a>
        </div>
        
        <p style="margin-top: 20px; font-size: 12px; color: var(--text-secondary); text-align: center;">
            提示：KVM云主机首次启动可能需要1-3分钟，请耐心等待。
            <?php if ($is_kvm_order): ?>
            您可以在控制台中使用VNC查看启动进度。
            <?php endif; ?>
            <br>
            <span style="color: var(--primary);">页面将在 <span id="jumpCountdown">5</span> 秒后自动跳转...</span>
        </p>
    </div>
    
    <script>
    var jumpSeconds = 5;
    var jumpUrl = '<?php echo $is_kvm_order ? "/user/host_kvm.php?id=" . $host_uuid : "/user/host_detail.php?id=" . $host_uuid; ?>';
    function jumpTick() {
        jumpSeconds--;
        if (jumpSeconds <= 0) { location.href = jumpUrl; }
        else { document.getElementById('jumpCountdown').textContent = jumpSeconds; setTimeout(jumpTick, 1000); }
    }
    setTimeout(jumpTick, 1000);
    </script>
    
    <!-- 正在创建 -->
    <?php else: ?>
    <div class="creating-card">
        <div class="loading-icon">
            <div class="spinner"></div>
        </div>
        <h1 class="title" id="statusTitle">正在为您创建主机...</h1>
        <p class="subtitle">
            订单号：<?php echo e($order_no); ?><br>
            套餐：<?php echo e($order['package_name']); ?>
            <?php if ($is_kvm_order): ?>
            <br>正在配置云服务器，请稍候...
            <?php else: ?>
            <br>正在开通虚拟主机，请稍候...
            <?php endif; ?>
        </p>
        
        <div style="background: var(--bg-light); border-radius: var(--radius-md); padding: 20px; margin-top: 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <div id="dot1" style="width: 8px; height: 8px; border-radius: 50%;" class="dot-done"></div>
                <span id="step1" style="color: var(--text-primary); font-size: 13px;">订单验证完成</span>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <div id="dot2" style="width: 8px; height: 8px; border-radius: 50%;" class="dot-done"></div>
                <span id="step2" style="color: var(--text-primary); font-size: 13px;">支付确认完成</span>
            </div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <div id="dot3" style="width: 8px; height: 8px; border-radius: 50%;" class="dot-active"></div>
                <span id="step3" style="color: var(--primary); font-size: 13px; font-weight: 500;">
                    <?php if ($is_kvm_order): ?>正在创建云服务器...<?php else: ?>正在开通虚拟主机...<?php endif; ?>
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div id="dot4" style="width: 8px; height: 8px; border-radius: 50%;" class="dot-pending"></div>
                <span id="step4" style="color: var(--text-secondary); font-size: 13px;">完成初始化</span>
            </div>
        </div>
        
        <div id="errorBox" style="display: none; margin-top: 20px; padding: 16px; background: var(--primary-lighter); border: 1px solid var(--primary-light); border-radius: var(--radius-md);">
            <p style="color: var(--primary); font-size: 13px; margin: 0;"><strong>创建失败：</strong><span id="errorMsg"></span></p>
        </div>
        
        <div style="margin-top: 32px; text-align: center;">
            <a href="/user/orders.php" class="btn btn-outline">返回订单列表</a>
        </div>
        
        <p style="margin-top: 20px; font-size: 12px; color: var(--text-secondary); text-align: center;">
            预计需要 30-60 秒，请勿关闭此页面
        </p>
    </div>
    
    <script>
    var orderNo = '<?php echo $order_no; ?>';
    var isKvm = <?php echo $is_kvm_order ? 'true' : 'false'; ?>;
    
    function checkStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/api/create_status.php?order_no=' + encodeURIComponent(orderNo), true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.status === 'completed') {
                        location.reload();
                    } else if (res.status === 'error') {
                        document.getElementById('statusTitle').textContent = '创建失败';
                        document.getElementById('errorBox').style.display = 'block';
                        document.getElementById('errorMsg').textContent = res.message || '未知错误';
                        document.getElementById('dot3').className = 'dot-done';
                        document.getElementById('dot4').className = 'dot-pending';
                        document.getElementById('step3').style.color = 'var(--primary)';
                        return;
                    } else {
                        setTimeout(checkStatus, 2000);
                    }
                } catch(e) {
                    setTimeout(checkStatus, 3000);
                }
            } else {
                setTimeout(checkStatus, 3000);
            }
        };
        xhr.onerror = function() {
            setTimeout(checkStatus, 3000);
        };
        xhr.send();
    }
    
    function startCreate() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/create_vm.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            setTimeout(checkStatus, 1000);
        };
        xhr.onerror = function() {
            setTimeout(checkStatus, 2000);
        };
        xhr.send('order_no=' + encodeURIComponent(orderNo));
    }
    
    <?php if (!$is_processing && !$host_created): ?>
    startCreate();
    <?php else: ?>
    setTimeout(checkStatus, 1500);
    <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
