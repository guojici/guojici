<?php
require_once __DIR__ . '/../config/helper.php';
require_auth();

$uid = auth_id();
$id_param = get('id', '');

if (empty($id_param)) {
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
    header('Location: /user/hosts.php');
    exit;
}

$host_id = $host['id'];
$host_uuid = $host['uuid'] ?? $host_id;

if (empty($host['vm_name'])) {
    header('Location: /user/host_kvm.php?id=' . $host_uuid);
    exit;
}

// API 模式：返回 JSON 数据
if (isset($_GET['action']) && $_GET['action'] === 'api') {
    header('Content-Type: application/json');
    
    $vm_power = $host['vm_power_status'] ?? 'unknown';
    $result = [
        'success' => false,
        'vm_power' => $vm_power,
        'data' => null,
        'source' => 'local',
        'error' => '',
        'debug' => [],
    ];
    
    if (!empty($host['vm_name']) && $vm_power === 'running') {
        $vm_usage = null;

        // 社区版：直接使用本地 virsh 获取监控数据
        if (!$vm_usage) {
            $kvm = kvm_get_manager();
            if ($kvm) {
                $usage_result = $kvm->getVMUsage($host['vm_name']);
                if ($usage_result && !empty($usage_result['success'])) {
                    $vm_usage = $usage_result;
                    $result['source'] = 'virsh';
                } else {
                    $result['error'] = $usage_result['error'] ?? $kvm->getError() ?? '获取监控数据失败';
                    $result['debug']['kvm_error'] = $kvm->getError();
                }
            } else {
                $result['error'] = 'KVM管理器初始化失败';
            }
        }
        
        if ($vm_usage) {
            $mem_usage = 0;
            if (!empty($vm_usage['memory_total']) && $vm_usage['memory_total'] > 0) {
                $mem_usage = round($vm_usage['memory_used'] / $vm_usage['memory_total'] * 100, 1);
            }
            if (!empty($vm_usage['memory_percent'])) {
                $mem_usage = floatval($vm_usage['memory_percent']);
            }

            $disk_percent = 0;
            if (!empty($vm_usage['disk_total']) && $vm_usage['disk_total'] > 0) {
                $disk_percent = round($vm_usage['disk_used'] / $vm_usage['disk_total'] * 100, 1);
            }

            $rx_mbps = round(floatval($vm_usage['rx_speed'] ?? 0) * 8 / 1024, 2);
            $tx_mbps = round(floatval($vm_usage['tx_speed'] ?? 0) * 8 / 1024, 2);

            $result['success'] = true;
            $result['data'] = [
                'cpu_usage' => round(floatval($vm_usage['cpu_usage'] ?? 0), 1),
                'mem_usage' => $mem_usage,
                'mem_used' => round(floatval($vm_usage['memory_used'] ?? 0), 0),
                'mem_total' => round(floatval($vm_usage['memory_total'] ?? 0), 0),
                'disk_used' => round(floatval($vm_usage['disk_used'] ?? 0), 2),
                'disk_total' => round(floatval($vm_usage['disk_total'] ?? 0), 2),
                'disk_read' => round(floatval($vm_usage['disk_read_mb'] ?? 0), 2),
                'disk_write' => round(floatval($vm_usage['disk_write_mb'] ?? 0), 2),
                'network_rx' => $rx_mbps,
                'network_tx' => $tx_mbps,
                'rx_speed' => round(floatval($vm_usage['rx_speed'] ?? 0), 2),
                'tx_speed' => round(floatval($vm_usage['tx_speed'] ?? 0), 2),
                'memory_percent' => $mem_usage,
                'disk_percent' => $disk_percent,
                'time' => date('H:i:s')
            ];
        }
    } else if ($vm_power !== 'running') {
        $result['error'] = '虚拟机未运行，当前状态: ' . $vm_power;
    }
    
    echo json_encode($result);
    exit;
}

$vm_power = $host['vm_power_status'] ?? 'unknown';
$vm_name = $host['vm_name'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>资源监控 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { background: #f5f7fa; }
        .kvm-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: #1677ff; text-decoration: none; font-size: 14px; margin-bottom: 16px; }
        .back-link:hover { text-decoration: underline; }
        .nav-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav-tab { padding: 10px 20px; background: #fff; border: 1px solid #e5e6eb; border-radius: 8px; text-decoration: none; color: #4e5969; font-size: 14px; transition: all 0.2s; }
        .nav-tab:hover { border-color: #1677ff; color: #1677ff; }
        .nav-tab.active { background: #1677ff; color: #fff; border-color: #1677ff; }

        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #4e5969;
        }
        .refresh-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        .refresh-dot.offline { background: #ef4444; animation: none; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
        .refresh-btn {
            padding: 8px 16px; background: #fff; border: 1px solid #e5e6eb;
            border-radius: 8px; cursor: pointer; font-size: 13px; color: #1d2129; transition: all 0.2s;
        }
        .refresh-btn:hover { border-color: #1677ff; color: #1677ff; }

        .monitor-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        @media (max-width: 900px) { .monitor-container { grid-template-columns: 1fr; } }
        .stat-card {
            background: #fff; border: 1px solid #e5e6eb; border-radius: 12px; padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: box-shadow 0.3s;
        }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .stat-card-header {
            display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;
        }
        .stat-card-title {
            font-size: 16px; font-weight: 600; color: #1d2129; display: flex; align-items: center; gap: 8px;
        }
        .stat-card-title .icon { font-size: 20px; }
        .stat-card-subtitle { font-size: 12px; color: #86909c; margin-top: 4px; }
        .stat-value { text-align: right; }
        .stat-main-value {
            font-size: 32px; font-weight: 700; font-family: 'SF Mono', Monaco, monospace; line-height: 1;
        }
        .stat-main-value.cpu { color: #ef4444; }
        .stat-main-value.memory { color: #1677ff; }
        .stat-main-value.disk { color: #f59e0b; }
        .stat-main-value.network { color: #22c55e; }
        .stat-unit { font-size: 14px; font-weight: 500; color: #86909c; margin-left: 2px; }
        .stat-detail { font-size: 13px; color: #86909c; margin-top: 4px; }
        .progress-bar {
            width: 100%; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; margin: 16px 0;
        }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .progress-fill.cpu { background: linear-gradient(90deg, #fca5a5, #ef4444); }
        .progress-fill.memory { background: linear-gradient(90deg, #93c5fd, #1677ff); }
        .progress-fill.disk { background: linear-gradient(90deg, #fcd34d, #f59e0b); }
        .progress-fill.network { background: linear-gradient(90deg, #86efac, #22c55e); }
        .chart-container { width: 100%; height: 180px; position: relative; }
        .chart-container canvas { width: 100% !important; height: 100% !important; }

        .network-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
        .network-item { text-align: center; padding: 12px; background: #f7f8fa; border-radius: 8px; }
        .network-label { font-size: 12px; color: #86909c; margin-bottom: 4px; }
        .network-value { font-size: 18px; font-weight: 600; font-family: 'SF Mono', Monaco, monospace; color: #1d2129; }
        .network-value.rx { color: #22c55e; }
        .network-value.tx { color: #1677ff; }
        .network-unit { font-size: 11px; color: #86909c; }

        .stat-grid-info { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 12px; }
        .stat-info-item { text-align: center; }
        .stat-info-label { font-size: 11px; color: #86909c; margin-bottom: 2px; }
        .stat-info-value { font-size: 14px; font-weight: 600; color: #1d2129; font-family: 'SF Mono', Monaco, monospace; }

        .disk-io-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
        .disk-io-item { text-align: center; padding: 12px; background: #f7f8fa; border-radius: 8px; }
        .disk-io-label { font-size: 12px; color: #86909c; margin-bottom: 4px; }
        .disk-io-value { font-size: 18px; font-weight: 600; font-family: 'SF Mono', Monaco, monospace; color: #f59e0b; }
        .disk-io-unit { font-size: 11px; color: #86909c; }

        .error-box {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;
        }
        .empty-state { text-align: center; padding: 80px 20px; color: #86909c; }
        .empty-icon { font-size: 80px; margin-bottom: 20px; opacity: 0.5; }
        .empty-title { font-size: 18px; margin-bottom: 8px; color: #4e5969; }
        .empty-desc { font-size: 14px; }
    </style>
</head>
<body>
    <div class="kvm-page">
        <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="back-link">← 返回控制台</a>

        <div class="nav-tabs">
            <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="nav-tab">控制台</a>
            <a href="/user/host_nat.php?id=<?php echo $host_id; ?>" class="nav-tab">🌐 NAT端口映射</a>
            <a href="/user/host_snapshots.php?id=<?php echo $host_id; ?>" class="nav-tab">📸 快照管理</a>
            <a href="/user/host_network.php?id=<?php echo $host_id; ?>" class="nav-tab">🌐 网络配置</a>
            <a href="/user/host_firewall.php?id=<?php echo $host_id; ?>" class="nav-tab">🛡️ 防火墙</a>
            <a href="/user/host_monitor.php?id=<?php echo $host_id; ?>" class="nav-tab active">📊 资源监控</a>
        </div>

        <div class="page-header-row">
            <div>
                <h1 style="margin:0 0 6px; font-size:22px; color:#1d2129;">📊 资源监控</h1>
                <p style="margin:0; font-size:13px; color:#86909c;">虚拟机: <?php echo e($vm_name); ?> · 实时资源使用情况</p>
            </div>
            <div class="refresh-indicator">
                <div class="refresh-dot" id="refreshDot"></div>
                <span id="lastUpdate">加载中...</span>
                <button class="refresh-btn" onclick="forceRefresh()">↻ 手动刷新</button>
            </div>
        </div>

        <div id="offlineAlert" class="error-box" style="display:none;">
            <strong>⚠️ 虚拟机未运行</strong><br>
            资源监控需要虚拟机处于运行状态。请先在控制台开机。
        </div>

        <div id="monitorContainer" class="monitor-container" style="display:none;">
            <!-- CPU 使用率 -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-title">
                            <span class="icon">⚡</span>
                            CPU 使用率
                        </div>
                        <div class="stat-card-subtitle">
                            <?php echo intval($host['vcpu'] ?? 2); ?> 核心 · 实时监测
                        </div>
                    </div>
                    <div class="stat-value">
                        <div class="stat-main-value cpu">
                            <span id="cpuValue">0</span>
                            <span class="stat-unit">%</span>
                        </div>
                        <div class="stat-detail" id="cpuDetail">
                            <?php echo intval($host['vcpu'] ?? 2); ?> 核处理器
                        </div>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill cpu" id="cpuProgress" style="width: 0%;"></div>
                </div>
                <div class="chart-container">
                    <canvas id="cpuChart"></canvas>
                </div>
            </div>

            <!-- 内存使用 -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-title">
                            <span class="icon">💾</span>
                            内存使用
                        </div>
                        <div class="stat-card-subtitle">
                            虚拟机内存使用情况
                        </div>
                    </div>
                    <div class="stat-value">
                        <div class="stat-main-value memory">
                            <span id="memValue">0</span>
                            <span class="stat-unit">MB</span>
                        </div>
                        <div class="stat-detail" id="memDetail">
                            共 <?php echo intval($host['memory_mb'] ?? 2048); ?> MB
                        </div>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill memory" id="memProgress" style="width: 0%;"></div>
                </div>
                <div class="chart-container">
                    <canvas id="memChart"></canvas>
                </div>
            </div>

            <!-- 磁盘存储 -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-title">
                            <span class="icon">💿</span>
                            磁盘存储
                        </div>
                        <div class="stat-card-subtitle">
                            系统盘空间使用
                        </div>
                    </div>
                    <div class="stat-value">
                        <div class="stat-main-value disk">
                            <span id="diskValue">0</span>
                            <span class="stat-unit">GB</span>
                        </div>
                        <div class="stat-detail" id="diskDetail">
                            共 <?php echo intval($host['disk_gb'] ?? 40); ?> GB
                        </div>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill disk" id="diskProgress" style="width: 0%;"></div>
                </div>
                <!-- 磁盘 IO 速率 -->
                <div class="disk-io-stats">
                    <div class="disk-io-item">
                        <div class="disk-io-label">↓ 读取速率</div>
                        <div class="disk-io-value">
                            <span id="diskRead">0</span>
                            <span class="disk-io-unit"> MB/s</span>
                        </div>
                    </div>
                    <div class="disk-io-item">
                        <div class="disk-io-label">↑ 写入速率</div>
                        <div class="disk-io-value">
                            <span id="diskWrite">0</span>
                            <span class="disk-io-unit"> MB/s</span>
                        </div>
                    </div>
                </div>
                <div class="chart-container" style="margin-top:16px;">
                    <canvas id="diskChart"></canvas>
                </div>
            </div>

            <!-- 带宽流量 -->
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-card-title">
                            <span class="icon">🌐</span>
                            带宽流量
                        </div>
                        <div class="stat-card-subtitle" id="netIface">
                            峰值带宽: <?php echo intval($host['bandwidth_mbps'] ?? 100); ?> Mbps
                        </div>
                    </div>
                    <div class="stat-value">
                        <div class="stat-main-value network">
                            <span id="netSpeed">0</span>
                            <span class="stat-unit">Mbps</span>
                        </div>
                        <div class="stat-detail">
                            总流量速率
                        </div>
                    </div>
                </div>
                <div class="network-stats">
                    <div class="network-item">
                        <div class="network-label">↓ 下载速率</div>
                        <div class="network-value rx">
                            <span id="rxSpeed">0</span>
                            <span class="network-unit"> Mbps</span>
                        </div>
                        <div style="font-size: 11px; color: #c9cdd4; margin-top: 4px;">
                            累计: <span id="rxTotal">0</span> GB
                        </div>
                    </div>
                    <div class="network-item">
                        <div class="network-label">↑ 上传速率</div>
                        <div class="network-value tx">
                            <span id="txSpeed">0</span>
                            <span class="network-unit"> Mbps</span>
                        </div>
                        <div style="font-size: 11px; color: #c9cdd4; margin-top: 4px;">
                            累计: <span id="txTotal">0</span> GB
                        </div>
                    </div>
                </div>
                <div class="chart-container" style="margin-top: 16px;">
                    <canvas id="netChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 空状态 -->
        <div id="emptyState" class="stat-card">
            <div class="empty-state">
                <div class="empty-icon">📡</div>
                <div class="empty-title">无法获取资源数据</div>
                <div class="empty-desc" id="emptyDesc">
                    虚拟机处于 <strong style="color:#f59e0b;"><?php echo e($vm_power); ?></strong> 状态<br>
                    <span>请先开机后再查看资源监控</span>
                </div>
                <div style="margin-top:24px;">
                    <a href="/user/host_kvm.php?id=<?php echo $host_uuid; ?>" class="refresh-btn" style="display:inline-block; background:#1677ff; color:#fff; border-color:#1677ff; padding:12px 24px;">返回控制台</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    var hostId = <?php echo $host_id; ?>;
    var vmPower = '<?php echo $vm_power; ?>';
    var updateInterval = null;
    var MAX_DATA_POINTS = 20;
    var cpuData = [], memData = [], netRxData = [], netTxData = [], diskReadData = [], diskWriteData = [];
    var labels = [];
    for (var i = 0; i < MAX_DATA_POINTS; i++) {
        labels.push(''); cpuData.push(0); memData.push(0);
        netRxData.push(0); netTxData.push(0);
        diskReadData.push(0); diskWriteData.push(0);
    }

    var chartOptions = {
        responsive: true, maintainAspectRatio: false,
        animation: { duration: 300 },
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', titleFont: {size:12}, bodyFont: {size:12}, padding: 10, cornerRadius: 6 }
        },
        scales: {
            x: { display: false },
            y: {
                beginAtZero: true, max: 100,
                grid: { color: 'rgba(0,0,0,0.05)' },
                ticks: { font: {size:10}, color: '#86909c', callback: function(v){ return v + '%'; } }
            }
        },
        elements: { line: { tension: 0.4, borderWidth: 2 }, point: { radius: 0, hoverRadius: 4 } }
    };

    function createGradient(ctx, c1, c2) {
        var g = ctx.createLinearGradient(0, 0, 0, 180);
        g.addColorStop(0, c1); g.addColorStop(1, c2);
        return g;
    }

    var cpuChart, memChart, netChart, diskChart;

    function initCharts() {
        var cpuCtx = document.getElementById('cpuChart').getContext('2d');
        cpuChart = new Chart(cpuCtx, {
            type: 'line', data: { labels: labels, datasets: [{ data: cpuData, borderColor: '#ef4444', backgroundColor: createGradient(cpuCtx, 'rgba(239,68,68,0.2)', 'rgba(239,68,68,0)'), fill: true }] },
            options: chartOptions
        });

        var memCtx = document.getElementById('memChart').getContext('2d');
        memChart = new Chart(memCtx, {
            type: 'line', data: { labels: labels, datasets: [{ data: memData, borderColor: '#1677ff', backgroundColor: createGradient(memCtx, 'rgba(22,119,255,0.2)', 'rgba(22,119,255,0)'), fill: true }] },
            options: chartOptions
        });

        var diskCtx = document.getElementById('diskChart').getContext('2d');
        diskChart = new Chart(diskCtx, {
            type: 'line',
            data: { labels: labels, datasets: [
                { label: '读取', data: diskReadData, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.1)', fill: false, borderWidth: 2, tension: 0.4 },
                { label: '写入', data: diskWriteData, borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,0.1)', fill: false, borderWidth: 2, tension: 0.4 }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: true, position: 'top', align: 'end',
                        labels: { font: {size:11}, color: '#86909c', boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'circle' } },
                    tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', titleFont: {size:12}, bodyFont: {size:12}, padding: 10, cornerRadius: 6 }
                },
                scales: {
                    x: { display: false },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: {size:10}, color: '#86909c', callback: function(v){ return v + ' MB/s'; } } }
                },
                elements: { point: { radius: 0, hoverRadius: 4 } }
            }
        });

        var netCtx = document.getElementById('netChart').getContext('2d');
        netChart = new Chart(netCtx, {
            type: 'line',
            data: { labels: labels, datasets: [
                { label: '下载', data: netRxData, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.1)', fill: false, borderWidth: 2, tension: 0.4 },
                { label: '上传', data: netTxData, borderColor: '#1677ff', backgroundColor: 'rgba(22,119,255,0.1)', fill: false, borderWidth: 2, tension: 0.4 }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: true, position: 'top', align: 'end',
                        labels: { font: {size:11}, color: '#86909c', boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'circle' } },
                    tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', titleFont: {size:12}, bodyFont: {size:12}, padding: 10, cornerRadius: 6 }
                },
                scales: {
                    x: { display: false },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: {size:10}, color: '#86909c', callback: function(v){ return v + ' Mbps'; } } }
                },
                elements: { point: { radius: 0, hoverRadius: 4 } }
            }
        });
    }

    function updateChart(chart, arr, val) {
        arr.shift(); arr.push(val);
        chart.data.datasets[0].data = arr;
        chart.update('none');
    }

    function updateMonitor() {
        fetch('/user/host_monitor.php?id=' + hostId + '&action=api&t=' + Date.now())
            .then(function(res) { return res.json(); })
            .then(function(result) {
                if (!result.success || !result.data) {
                    showOfflineState(result.error || '无法获取监控数据');
                    return;
                }
                var d = result.data;

                document.getElementById('monitorContainer').style.display = 'grid';
                document.getElementById('emptyState').style.display = 'none';
                document.getElementById('offlineAlert').style.display = 'none';
                document.getElementById('refreshDot').className = 'refresh-dot';

                // CPU
                document.getElementById('cpuValue').textContent = d.cpu_usage.toFixed(1);
                document.getElementById('cpuProgress').style.width = Math.min(100, d.cpu_usage) + '%';
                updateChart(cpuChart, cpuData, d.cpu_usage);

                // 内存
                document.getElementById('memValue').textContent = Math.round(d.mem_used);
                document.getElementById('memProgress').style.width = Math.min(100, d.memory_percent) + '%';
                document.getElementById('memDetail').textContent = Math.round(d.mem_used) + ' / ' + Math.round(d.mem_total) + ' MB';
                updateChart(memChart, memData, d.memory_percent);

                // 磁盘
                document.getElementById('diskValue').textContent = d.disk_used.toFixed(1);
                document.getElementById('diskProgress').style.width = Math.min(100, d.disk_percent) + '%';
                document.getElementById('diskDetail').textContent = d.disk_used.toFixed(1) + ' / ' + d.disk_total.toFixed(1) + ' GB';
                document.getElementById('diskRead').textContent = d.disk_read.toFixed(2);
                document.getElementById('diskWrite').textContent = d.disk_write.toFixed(2);

                diskReadData.shift(); diskReadData.push(d.disk_read);
                diskWriteData.shift(); diskWriteData.push(d.disk_write);
                diskChart.data.datasets[0].data = diskReadData;
                diskChart.data.datasets[1].data = diskWriteData;
                diskChart.update('none');

                // 网络
                var totalSpeed = (d.network_rx + d.network_tx).toFixed(2);
                document.getElementById('netSpeed').textContent = totalSpeed;
                document.getElementById('rxSpeed').textContent = d.network_rx.toFixed(2);
                document.getElementById('txSpeed').textContent = d.network_tx.toFixed(2);
                document.getElementById('rxTotal').textContent = (d.rx_speed / 1024).toFixed(2);
                document.getElementById('txTotal').textContent = (d.tx_speed / 1024).toFixed(2);

                netRxData.shift(); netRxData.push(d.network_rx);
                netTxData.shift(); netTxData.push(d.network_tx);
                netChart.data.datasets[0].data = netRxData;
                netChart.data.datasets[1].data = netTxData;
                netChart.update('none');

                var now = new Date();
                document.getElementById('lastUpdate').textContent = '最后更新: ' + now.toLocaleTimeString('zh-CN');
            })
            .catch(function(err) {
                console.error('获取监控数据失败:', err);
                document.getElementById('lastUpdate').textContent = '请求失败';
                document.getElementById('refreshDot').className = 'refresh-dot offline';
            });
    }

    function showOfflineState(errMsg) {
        document.getElementById('monitorContainer').style.display = 'none';
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('refreshDot').className = 'refresh-dot offline';
        document.getElementById('lastUpdate').textContent = '监控未连接';

        var desc = document.getElementById('emptyDesc');
        if (errMsg) {
            desc.innerHTML = '<div style="color:#ef4444; margin-bottom:12px;">⚠️ ' + errMsg + '</div>' +
                '<div style="font-size:12px; color:#86909c;">常见原因：www用户缺少sudo virsh权限、libvirtd未运行</div>';
        } else {
            desc.innerHTML = '虚拟机处于 <strong style="color:#f59e0b;">' + vmPower + '</strong> 状态<br><span>请先开机后再查看资源监控</span>';
        }

        if (vmPower !== 'running') {
            document.getElementById('offlineAlert').style.display = 'block';
            document.getElementById('emptyState').style.display = 'none';
        }
    }

    function forceRefresh() {
        for (var i = 0; i < MAX_DATA_POINTS; i++) {
            cpuData[i] = 0; memData[i] = 0; netRxData[i] = 0; netTxData[i] = 0;
            diskReadData[i] = 0; diskWriteData[i] = 0;
        }
        updateMonitor();
    }

    document.addEventListener('DOMContentLoaded', function() {
        initCharts();
        if (vmPower === 'running') {
            updateMonitor();
            updateInterval = setInterval(updateMonitor, 5000);
        } else {
            showOfflineState();
        }
    });

    window.addEventListener('beforeunload', function() {
        if (updateInterval) clearInterval(updateInterval);
    });
    </script>
</body>
</html>
