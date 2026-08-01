<?php
require_once __DIR__ . '/../config/helper.php';

$uid = intval($_SESSION['user_id']);
$user = auth_user();

// 获取用户的所有KVM主机（包括有vm_name的主机）
$hosts = Database::fetchAll("SELECT h.*, p.name as package_name 
    FROM hosts h 
    LEFT JOIN packages p ON h.package_id = p.id 
    WHERE h.user_id = ? AND (h.vm_type = 'kvm' OR h.vm_name IS NOT NULL OR h.vm_name != '') AND h.status != 'deleted'
    ORDER BY h.created_at DESC", [$uid]);

// 获取价格配置
$prices = resize_get_prices();

$selected_host = null;
$resize_history = [];

if (isset($_GET['host_id'])) {
    $host_id = intval($_GET['host_id']);
    foreach ($hosts as $h) {
        if ($h['id'] == $host_id) {
            $selected_host = $h;
            break;
        }
    }
    if ($selected_host) {
        $resize_history = resize_get_history($host_id);
    }
}

// 处理升级提交
if (is_post() && $selected_host) {
    $action = post('action', '');
    
    // 核验码验证
    if ($action === 'upgrade') {
        license_require_for_service('主机升级');
    }
    
    if ($action === 'upgrade') {
        $vcpu = intval(post('vcpu', 0));
        $memory_mb = intval(post('memory_mb', 0));
        $disk_gb = intval(post('disk_gb', 0));
        $confirm = post('confirm', '');
        
        if ($confirm !== 'yes') {
            flash('error', '请勾选确认升级选项');
        } else {
            $calc = resize_calculate_price($selected_host, $vcpu, $memory_mb, $disk_gb);
            if (!$calc['success']) {
                flash('error', $calc['message']);
            } else {
                $order = resize_create_order($selected_host, $user, $vcpu, $memory_mb, $disk_gb);
                if ($order['success']) {
                    $pay = resize_pay_order($order['order_id'], $user);
                    if ($pay['success']) {
                        $exec = resize_execute_order($order['order_id']);
                        if ($exec['success']) {
                            send_notification($uid, 'host', '主机规格升级成功',
                                '您的主机 ' . ($selected_host['vm_name'] ?? $selected_host['mnbt_username']) . ' 规格已升级成功。CPU: ' . $vcpu . '核, 内存: ' . $memory_mb . 'MB, 磁盘: ' . $disk_gb . 'GB',
                                'host', $selected_host['id']);
                            flash('success', "升级成功！费用 ¥{$order['total_price']} 已从账户扣除");
                        } else {
                            send_notification($uid, 'host', '主机规格升级失败',
                                '您的主机 ' . ($selected_host['vm_name'] ?? $selected_host['mnbt_username']) . ' 规格升级失败，原因：' . $exec['message'],
                                'host', $selected_host['id']);
                            flash('error', "支付成功，但升级执行失败: " . $exec['message']);
                        }
                    } else {
                        flash('error', $pay['message']);
                    }
                } else {
                    flash('error', $order['message']);
                }
            }
        }
        redirect('/user/resize.php?host_id=' . $selected_host['id']);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>规格调整 - <?php echo e($site_config['title'] ?? '商城'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f5f5f5; }
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 24px; color: #1d2129; margin: 0 0 8px; }
        .page-desc { color: #86909c; font-size: 14px; margin: 0; }
        .card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .card-title { font-size: 16px; color: #1d2129; margin: 0 0 16px; font-weight: 600; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
        .alert-error { background: #fff2f0; border: 1px solid #ffccc7; color: #ff4d4f; }
        .host-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .host-card { border: 1px solid #e5e6eb; border-radius: 8px; padding: 16px; cursor: pointer; transition: all 0.2s; }
        .host-card:hover { border-color: #1677ff; box-shadow: 0 2px 8px rgba(22,119,255,0.1); }
        .host-card.selected { border-color: #1677ff; background: #e6f4ff; }
        .host-name { font-weight: 600; color: #1d2129; margin-bottom: 4px; }
        .host-info { font-size: 13px; color: #86909c; }
        .host-status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-top: 8px; }
        .status-running { background: #f6ffed; color: #52c41a; }
        .status-stopped { background: #f5f5f5; color: #86909c; }
        .price-info { background: #f6ffed; border: 1px solid #b7eb8f; border-radius: 6px; padding: 12px; margin-bottom: 16px; }
        .price-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .price-row:last-child { border-bottom: none; }
        .price-label { color: #4e5969; }
        .price-value { color: #1d2129; font-weight: 500; }
        .config-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .config-item label { display: block; font-size: 13px; color: #1d2129; font-weight: 600; margin-bottom: 8px; }
        .config-item label span { font-weight: normal; color: #86909c; }
        .config-item .input-group { display: flex; align-items: center; gap: 8px; }
        .config-item button { width: 36px; height: 36px; border-radius: 6px; border: 1px solid #d1d9e6; background: #fff; cursor: pointer; font-size: 18px; }
        .config-item button:hover { background: #f5f5f5; }
        .config-item input { flex: 1; padding: 8px 12px; border-radius: 6px; border: 1px solid #d1d9e6; font-size: 14px; text-align: center; }
        .btn { display: inline-block; padding: 10px 24px; border-radius: 6px; font-size: 14px; cursor: pointer; border: none; text-decoration: none; }
        .btn-primary { background: #1677ff; color: #fff; }
        .btn-primary:hover { background: #4096ff; }
        .btn-primary:disabled { background: #d9d9d9; cursor: not-allowed; }
        .notice { background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; padding: 12px; margin-bottom: 16px; font-size: 13px; color: #d46b08; }
        .notice ul { margin: 8px 0 0 16px; padding: 0; }
        .notice li { margin-bottom: 4px; }
        .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .history-table th, .history-table td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        .history-table th { color: #86909c; font-weight: 500; }
        .empty-state { text-align: center; padding: 40px; color: #86909c; }
        .balance { color: #1677ff; font-weight: 600; }
        .price-preview { background: #e6f4ff; border: 1px solid #91caff; border-radius: 6px; padding: 12px; margin-bottom: 16px; display: none; }
        .price-preview.show { display: block; }
        .price-preview .title { font-size: 14px; font-weight: 600; color: #1677ff; margin-bottom: 8px; }
        .price-preview .details { font-size: 13px; color: #4e5969; margin-bottom: 8px; }
        .price-preview .total { font-size: 16px; font-weight: 700; color: #1677ff; }
    </style>
</head>
<body>
    <div class="dashboard">
    <?php require __DIR__ . '/_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">⚙️ 规格调整</h1>
            <p class="page-desc">灵活调整主机配置，资源只支持增大不支持缩小</p>
        </div>
        
        <?php if ($msg = flash('success')): ?>
            <div class="alert alert-success"><?php echo e($msg); ?></div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="alert alert-error"><?php echo e($msg); ?></div>
        <?php endif; ?>
        
        <?php if (empty($hosts)): ?>
            <div class="card">
                <div class="empty-state">
                    <p>您还没有KVM主机</p>
                    <a href="/checkout.php" class="btn btn-primary" style="margin-top:16px;">去购买</a>
                </div>
            </div>
        <?php elseif (empty($prices)): ?>
            <div class="card">
                <div class="empty-state">
                    <p>管理员尚未配置升级价格，请联系管理员</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-title">选择主机</div>
                <div class="host-list">
                    <?php foreach ($hosts as $h): ?>
                        <a href="/user/resize.php?host_id=<?php echo $h['id']; ?>" class="host-card <?php echo $selected_host && $selected_host['id'] == $h['id'] ? 'selected' : ''; ?>">
                            <div class="host-name"><?php echo e($h['vm_name']); ?></div>
                            <div class="host-info">
                                CPU: <?php echo intval($h['vcpu']); ?>核 | 
                                内存: <?php echo intval($h['memory_mb']); ?>MB | 
                                磁盘: <?php echo intval($h['disk_gb']); ?>GB
                            </div>
                            <div>
                                <span class="host-status <?php echo $h['vm_power_status'] === 'running' ? 'status-running' : 'status-stopped'; ?>">
                                    <?php echo $h['vm_power_status'] === 'running' ? '运行中' : '已停止'; ?>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php if ($selected_host): ?>
                <div class="card">
                    <div class="card-title">
                        调整 <?php echo e($selected_host['vm_name']); ?> 的配置
                    </div>
                    
                    <div class="price-info">
                        <div class="price-row">
                            <span class="price-label">当前CPU</span>
                            <span class="price-value"><?php echo intval($selected_host['vcpu']); ?> 核</span>
                        </div>
                        <div class="price-row">
                            <span class="price-label">当前内存</span>
                            <span class="price-value"><?php echo intval($selected_host['memory_mb']); ?> MB</span>
                        </div>
                        <div class="price-row">
                            <span class="price-label">当前磁盘</span>
                            <span class="price-value"><?php echo intval($selected_host['disk_gb']); ?> GB</span>
                        </div>
                        <div class="price-row">
                            <span class="price-label">当前余额</span>
                            <span class="price-value balance">¥<?php echo number_format(floatval($user['balance'] ?? 0), 2); ?></span>
                        </div>
                    </div>
                    
                    <form method="POST" id="resizeForm">
                        <input type="hidden" name="action" value="upgrade">
                        
                        <div class="config-grid">
                            <div class="config-item">
                                <label>
                                    CPU 核心数
                                    <span>（当前: <?php echo intval($selected_host['vcpu']); ?> 核）</span>
                                </label>
                                <div class="input-group">
                                    <button type="button" onclick="adjustValue('vcpu', -1)">-</button>
                                    <input type="number" name="vcpu" id="vcpu" value="<?php echo intval($selected_host['vcpu']); ?>" min="<?php echo intval($selected_host['vcpu']); ?>" max="64">
                                    <button type="button" onclick="adjustValue('vcpu', 1)">+</button>
                                </div>
                                <div style="font-size:11px; color:#86909c; margin-top:4px;">单价 ¥<?php echo $prices['cpu']['unit_price'] ?? '0'; ?>/核</div>
                            </div>
                            
                            <div class="config-item">
                                <label>
                                    内存大小
                                    <span>（当前: <?php echo intval($selected_host['memory_mb']); ?> MB）</span>
                                </label>
                                <div class="input-group">
                                    <button type="button" onclick="adjustValue('memory_mb', -1024)">-</button>
                                    <input type="number" name="memory_mb" id="memory_mb" value="<?php echo intval($selected_host['memory_mb']); ?>" min="<?php echo intval($selected_host['memory_mb']); ?>" max="262144" step="1024">
                                    <button type="button" onclick="adjustValue('memory_mb', 1024)">+</button>
                                </div>
                                <div style="font-size:11px; color:#86909c; margin-top:4px;">单价 ¥<?php echo $prices['memory']['unit_price'] ?? '0'; ?>/GB</div>
                            </div>
                            
                            <div class="config-item">
                                <label>
                                    磁盘大小
                                    <span>（当前: <?php echo intval($selected_host['disk_gb']); ?> GB）</span>
                                </label>
                                <div class="input-group">
                                    <button type="button" disabled style="opacity:0.5; cursor:not-allowed;">-</button>
                                    <input type="number" name="disk_gb" id="disk_gb" value="<?php echo intval($selected_host['disk_gb']); ?>" min="<?php echo intval($selected_host['disk_gb']); ?>" max="2000" step="10">
                                    <button type="button" onclick="adjustValue('disk_gb', 10)">+</button>
                                </div>
                                <div style="font-size:11px; color:#d97706; margin-top:4px;">只能增大，单价 ¥<?php echo $prices['disk']['unit_price'] ?? '0'; ?>/GB</div>
                            </div>
                        </div>
                        
                        <div id="pricePreview" class="price-preview">
                            <div class="title">💰 升级费用预览</div>
                            <div id="priceDetails" class="details"></div>
                            <div class="total">总费用: <span id="totalPrice">¥0.00</span></div>
                        </div>
                        
                        <div class="notice">
                            <strong>⚠️ 注意事项：</strong>
                            <ul>
                                <li>资源只能增大，不能缩小</li>
                                <li>CPU/内存调整可能需要重启虚拟机生效</li>
                                <li>磁盘扩容后需在系统内手动分区扩展</li>
                            </ul>
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <label>
                                <input type="checkbox" name="confirm" value="yes" required>
                                我已了解风险，确认升级
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" onclick="return submitForm()">
                            确认升级并支付
                        </button>
                    </form>
                </div>
                
                <?php if (!empty($resize_history)): ?>
                    <div class="card">
                        <div class="card-title">升级历史</div>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>订单号</th>
                                    <th>规格变更</th>
                                    <th>金额</th>
                                    <th>状态</th>
                                    <th>时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resize_history as $h): ?>
                                    <tr>
                                        <td><?php echo e($h['order_no']); ?></td>
                                        <td>
                                            <?php if ($h['old_vcpu'] != $h['new_vcpu']): ?>
                                                CPU: <?php echo $h['old_vcpu']; ?>→<?php echo $h['new_vcpu']; ?>核<br>
                                            <?php endif; ?>
                                            <?php if ($h['old_memory_mb'] != $h['new_memory_mb']): ?>
                                                内存: <?php echo $h['old_memory_mb']; ?>→<?php echo $h['new_memory_mb']; ?>MB<br>
                                            <?php endif; ?>
                                            <?php if ($h['old_disk_gb'] != $h['new_disk_gb']): ?>
                                                磁盘: <?php echo $h['old_disk_gb']; ?>→<?php echo $h['new_disk_gb']; ?>GB
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:#1677ff; font-weight:600;">¥<?php echo $h['total_price']; ?></td>
                                        <td>
                                            <?php
                                            $status_map = ['pending'=>'待支付', 'paid'=>'已支付', 'completed'=>'已完成', 'failed'=>'失败', 'cancelled'=>'已取消'];
                                            $status_class = ['pending'=>'#d46b08', 'paid'=>'#1890ff', 'completed'=>'#52c41a', 'failed'=>'#ff4d4f', 'cancelled'=>'#86909c'];
                                            ?>
                                            <span style="color:<?php echo $status_class[$h['status']]; ?>">
                                                <?php echo $status_map[$h['status']]; ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_date($h['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        var priceConfig = <?php echo json_encode($prices); ?>;
        var currentSpec = {
            vcpu: <?php echo $selected_host ? intval($selected_host['vcpu']) : 2; ?>,
            memory_mb: <?php echo $selected_host ? intval($selected_host['memory_mb']) : 2048; ?>,
            disk_gb: <?php echo $selected_host ? intval($selected_host['disk_gb']) : 40; ?>
        };
        
        function adjustValue(id, delta) {
            var input = document.getElementById(id);
            if (!input) return;
            var current = parseInt(input.value) || 0;
            var min = parseInt(input.min) || 0;
            var max = parseInt(input.max) || 999999;
            var newValue = current + delta;
            if (newValue < min) newValue = min;
            if (newValue > max) newValue = max;
            input.value = newValue;
            calculatePrice();
        }
        
        function calculatePrice() {
            var vcpu = parseInt(document.getElementById('vcpu').value) || currentSpec.vcpu;
            var memory_mb = parseInt(document.getElementById('memory_mb').value) || currentSpec.memory_mb;
            var disk_gb = parseInt(document.getElementById('disk_gb').value) || currentSpec.disk_gb;
            
            var total = 0;
            var details = [];
            
            if (vcpu > currentSpec.vcpu && priceConfig.cpu) {
                var diff = vcpu - currentSpec.vcpu;
                var price = diff * parseFloat(priceConfig.cpu.unit_price);
                total += price;
                details.push("CPU: " + currentSpec.vcpu + "核 → " + vcpu + "核 (+" + diff + "核) ¥" + price.toFixed(2));
            }
            
            if (memory_mb > currentSpec.memory_mb && priceConfig.memory) {
                var diff_gb = (memory_mb - currentSpec.memory_mb) / 1024;
                var price = diff_gb * parseFloat(priceConfig.memory.unit_price);
                total += price;
                details.push("内存: " + currentSpec.memory_mb + "MB → " + memory_mb + "MB (+" + diff_gb + "GB) ¥" + price.toFixed(2));
            }
            
            if (disk_gb > currentSpec.disk_gb && priceConfig.disk) {
                var diff = disk_gb - currentSpec.disk_gb;
                var price = diff * parseFloat(priceConfig.disk.unit_price);
                total += price;
                details.push("磁盘: " + currentSpec.disk_gb + "GB → " + disk_gb + "GB (+" + diff + "GB) ¥" + price.toFixed(2));
            }
            
            var preview = document.getElementById('pricePreview');
            var detailsDiv = document.getElementById('priceDetails');
            var totalSpan = document.getElementById('totalPrice');
            
            if (total > 0) {
                preview.classList.add('show');
                detailsDiv.innerHTML = details.join('<br>');
                totalSpan.textContent = '¥' + total.toFixed(2);
            } else {
                preview.classList.remove('show');
            }
        }
        
        function submitForm() {
            var vcpu = parseInt(document.getElementById('vcpu').value) || currentSpec.vcpu;
            var memory_mb = parseInt(document.getElementById('memory_mb').value) || currentSpec.memory_mb;
            var disk_gb = parseInt(document.getElementById('disk_gb').value) || currentSpec.disk_gb;
            
            if (vcpu == currentSpec.vcpu && memory_mb == currentSpec.memory_mb && disk_gb == currentSpec.disk_gb) {
                alert('请至少调整一项规格');
                return false;
            }
            
            calculatePrice();
            var total = parseFloat(document.getElementById('totalPrice').textContent.replace('¥', ''));
            return confirm('确认升级？总费用: ¥' + total.toFixed(2));
        }
        
        // 只在存在输入框时添加事件监听器
        var vcpuInput = document.getElementById('vcpu');
        var memoryInput = document.getElementById('memory_mb');
        var diskInput = document.getElementById('disk_gb');
        if (vcpuInput) vcpuInput.addEventListener('input', calculatePrice);
        if (memoryInput) memoryInput.addEventListener('input', calculatePrice);
        if (diskInput) diskInput.addEventListener('input', calculatePrice);
    </script>
    </div><!-- /.dashboard -->
</body>
</html>