<?php
$is_installed = file_exists(__DIR__ . '/../config/.installed');
if (!$is_installed) {
    header('Location: /install.php');
    exit;
}

require_once __DIR__ . '/../config/helper.php';
require_admin();
license_require();

$total_users = Database::fetch("SELECT COUNT(*) as c FROM users")['c'];
$total_orders = Database::fetch("SELECT COUNT(*) as c FROM orders")['c'];
$total_hosts = Database::fetch("SELECT COUNT(*) as c FROM hosts")['c'];
$total_revenue = Database::fetch("SELECT SUM(amount) as s FROM orders WHERE status IN ('paid','completed')")['s'] ?? 0;

$today = date('Y-m-d');
$today_orders = Database::fetch("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) = ?", [$today])['c'];
$today_revenue = Database::fetch("SELECT IFNULL(SUM(amount),0) as s FROM orders WHERE DATE(created_at) = ? AND status IN ('paid','completed')", [$today])['s'];
$today_users = Database::fetch("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = ?", [$today])['c'];

$recent_orders = Database::fetchAll("SELECT o.*, u.username as user_name, p.name as package_name FROM orders o LEFT JOIN users u ON o.user_id = u.id LEFT JOIN packages p ON o.package_id = p.id ORDER BY o.created_at DESC LIMIT 10");

// 待处理工单数
$pending_tickets = Database::fetch("SELECT COUNT(*) as c FROM tickets WHERE status = 'open'")['c'];
// 待处理订单数
$pending_orders = Database::fetch("SELECT COUNT(*) as c FROM orders WHERE status = 'pending'")['c'];
// 最近问题（最近7天创建的工单）
$recent_tickets = Database::fetchAll("SELECT t.*, u.username FROM tickets t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 5");

// 近30天订单趋势
$thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
$daily_orders = Database::fetchAll("SELECT DATE(created_at) as date, COUNT(*) as cnt, SUM(amount) as revenue FROM orders WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY date ASC", [$thirty_days_ago . ' 00:00:00']);

// 系统状态检测
$db_status = '正常';
try {
    Database::fetch("SELECT 1");
} catch (Exception $e) {
    $db_status = '异常: ' . $e->getMessage();
}

$frp_status = '未配置';
$frp_cfg = config('frp');
if ($frp_cfg && !empty($frp_cfg['admin_api_url'])) {
    $frp_result = frp_api_request('GET', '/status');
    $frp_status = $frp_result['success'] ? '正常' : '异常';
}

$pay_status = '未配置';
$pay_cfg = config('payment');
if ($pay_cfg && !empty($pay_cfg['enabled'])) {
    $pay_status = '已启用';
} else {
    $pay_status = '未启用';
}

$admin = admin_user();
$greeting = '';
$hour = date('H');
if ($hour < 6) {
    $greeting = '夜深了，';
} elseif ($hour < 9) {
    $greeting = '早上好，';
} elseif ($hour < 12) {
    $greeting = '上午好，';
} elseif ($hour < 14) {
    $greeting = '中午好，';
} elseif ($hour < 18) {
    $greeting = '下午好，';
} elseif ($hour < 22) {
    $greeting = '晚上好，';
} else {
    $greeting = '夜深了，';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
        <div class="main-content">
            <!-- 问候语 -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px;">
                <div>
                    <h1 class="page-title" style="margin-bottom: 6px;"><?php echo $greeting; ?><?php echo e($admin['username']); ?> 👋</h1>
                    <p class="page-subtitle" style="margin: 0;"><?php echo date('Y年m月d日'); ?> · 祝您工作愉快</p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 13px; color: var(--text-secondary);">当前时间</div>
                    <div style="font-size: 16px; font-weight: 600; color: var(--text-primary);" id="current-time"><?php echo date('H:i:s'); ?></div>
                </div>
            </div>

            <?php if (flash('success')): ?><div class="alert alert-success"><?php echo e(flash('success')); ?></div><?php endif; ?>
            <?php if (flash('error')): ?><div class="alert alert-error"><?php echo e(flash('error')); ?></div><?php endif; ?>

            <!-- 第一行：4个统计卡片 -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px;">
                <!-- 总用户数 -->
                <div style="background: linear-gradient(135deg, #1677ff 0%, #69b1ff 100%); border-radius: 12px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 16px rgba(22, 119, 255, 0.3);">
                    <div style="position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div style="position: absolute; right: 10px; bottom: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.08); border-radius: 50%;"></div>
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 12px;">总用户数</div>
                    <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px; font-family: 'SF Mono', Monaco, monospace;"><?php echo number_format($total_users); ?></div>
                    <div style="font-size: 13px; opacity: 0.85;">今日新增 +<?php echo $today_users; ?></div>
                    <div style="margin-top: 12px; font-size: 22px;">👥</div>
                </div>
                <!-- 总订单 -->
                <div style="background: linear-gradient(135deg, #00b42a 0%, #69d980 100%); border-radius: 12px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 16px rgba(0, 180, 42, 0.3);">
                    <div style="position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div style="position: absolute; right: 10px; bottom: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.08); border-radius: 50%;"></div>
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 12px;">总订单</div>
                    <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px; font-family: 'SF Mono', Monaco, monospace;"><?php echo number_format($total_orders); ?></div>
                    <div style="font-size: 13px; opacity: 0.85;">今日订单 +<?php echo $today_orders; ?></div>
                    <div style="margin-top: 12px; font-size: 22px;">📋</div>
                </div>
                <!-- 总主机 -->
                <div style="background: linear-gradient(135deg, #ff7d00 0%, #ffb347 100%); border-radius: 12px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 16px rgba(255, 125, 0, 0.3);">
                    <div style="position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div style="position: absolute; right: 10px; bottom: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.08); border-radius: 50%;"></div>
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 12px;">总主机</div>
                    <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px; font-family: 'SF Mono', Monaco, monospace;"><?php echo number_format($total_hosts); ?></div>
                    <div style="font-size: 13px; opacity: 0.85;">运行中 <?php echo Database::fetch("SELECT COUNT(*) as c FROM hosts WHERE status = 'running'")['c']; ?></div>
                    <div style="margin-top: 12px; font-size: 22px;">🖥️</div>
                </div>
                <!-- 累计收入 -->
                <div style="background: linear-gradient(135deg, #722ed1 0%, #b37af5 100%); border-radius: 12px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 16px rgba(114, 46, 209, 0.3);">
                    <div style="position: absolute; right: -20px; top: -20px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%;"></div>
                    <div style="position: absolute; right: 10px; bottom: -10px; width: 60px; height: 60px; background: rgba(255,255,255,0.08); border-radius: 50%;"></div>
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 12px;">累计收入</div>
                    <div style="font-size: 36px; font-weight: 700; margin-bottom: 8px; font-family: 'SF Mono', Monaco, monospace;">¥<?php echo number_format($total_revenue, 2); ?></div>
                    <div style="font-size: 13px; opacity: 0.85;">今日 +¥<?php echo number_format($today_revenue, 2); ?></div>
                    <div style="margin-top: 12px; font-size: 22px;">💰</div>
                </div>
            </div>

            <!-- 第二行：快捷操作 -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px;">
                <a href="/admin/orders.php?status=pending" style="text-decoration: none;">
                    <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.04);" onmouseover="this.style.borderColor='#ff7d00'; this.style.boxShadow='0 4px 12px rgba(255,125,0,0.15)'" onmouseout="this.style.borderColor='var(--border)'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.04)'">
                        <div style="width: 48px; height: 48px; background: #fff7e8; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">📝</div>
                        <div>
                            <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">待处理订单</div>
                            <div style="font-size: 13px; color: var(--text-secondary);"><?php echo $pending_orders; ?> 个订单待支付</div>
                        </div>
                        <div style="margin-left: auto; font-size: 20px; color: var(--text-placeholder);">→</div>
                    </div>
                </a>
                <a href="/admin/tickets.php?status=open" style="text-decoration: none;">
                    <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.04);" onmouseover="this.style.borderColor='#ef4444'; this.style.boxShadow='0 4px 12px rgba(239,68,68,0.15)'" onmouseout="this.style.borderColor='var(--border)'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.04)'">
                        <div style="width: 48px; height: 48px; background: #ffe8e8; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">🎫</div>
                        <div>
                            <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">待处理工单</div>
                            <div style="font-size: 13px; color: var(--text-secondary);"><?php echo $pending_tickets; ?> 个工单待回复</div>
                        </div>
                        <div style="margin-left: auto; font-size: 20px; color: var(--text-placeholder);">→</div>
                    </div>
                </a>
                <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="width: 48px; height: 48px; background: #f0f5ff; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">📊</div>
                    <div>
                        <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">近30天订单</div>
                        <div style="font-size: 13px; color: var(--text-secondary);"><?php echo count($daily_orders); ?> 天有订单</div>
                    </div>
                </div>
            </div>

            <!-- 第三行：订单趋势 + 系统状态 -->
            <div style="display: grid; grid-template-columns: 7fr 3fr; gap: 20px; margin-bottom: 24px;">
                <!-- 近30天订单趋势 -->
                <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light);">📈 近30天订单趋势</div>
                    <?php if (empty($daily_orders)): ?>
                        <div style="text-align: center; padding: 40px 0; color: var(--text-secondary);">暂无订单数据</div>
                    <?php else: ?>
                        <div style="max-height: 280px; overflow-y: auto;">
                            <?php foreach ($daily_orders as $day): ?>
                                <div style="display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                                    <div style="width: 100px; font-size: 13px; color: var(--text-secondary);"><?php echo $day['date']; ?></div>
                                    <div style="flex: 1; display: flex; align-items: center; gap: 12px;">
                                        <div style="background: linear-gradient(90deg, #1677ff 0%, #69b1ff 100%); height: 8px; border-radius: 4px; width: <?php echo min(100, $day['cnt'] * 10); ?>%;"></div>
                                        <div style="font-size: 13px; color: var(--text-primary); font-weight: 600;"><?php echo $day['cnt']; ?> 单</div>
                                    </div>
                                    <div style="font-size: 13px; color: var(--success); font-weight: 600;">¥<?php echo number_format($day['revenue'] ?? 0, 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- 系统状态 -->
                <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light);">🔧 系统状态</div>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $db_status === '正常' ? 'var(--success)' : 'var(--danger)'; ?>;"></div>
                                <span style="font-size: 14px; color: var(--text-regular);">数据库</span>
                            </div>
                            <span style="font-size: 13px; color: <?php echo $db_status === '正常' ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600;"><?php echo e($db_status); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $frp_status === '正常' ? 'var(--success)' : ($frp_status === '未配置' ? 'var(--text-placeholder)' : 'var(--danger)'); ?>;"></div>
                                <span style="font-size: 14px; color: var(--text-regular);">FRP API</span>
                            </div>
                            <span style="font-size: 13px; color: <?php echo $frp_status === '正常' ? 'var(--success)' : ($frp_status === '未配置' ? 'var(--text-placeholder)' : 'var(--danger)'); ?>; font-weight: 600;"><?php echo e($frp_status); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $pay_status === '已启用' ? 'var(--success)' : 'var(--text-placeholder)'; ?>;"></div>
                                <span style="font-size: 14px; color: var(--text-regular);">支付API</span>
                            </div>
                            <span style="font-size: 13px; color: <?php echo $pay_status === '已启用' ? 'var(--success)' : 'var(--text-placeholder)'; ?>; font-weight: 600;"><?php echo e($pay_status); ?></span>
                        </div>
                    </div>
                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-light);">
                        <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 12px;">最新工单</div>
                        <?php if (empty($recent_tickets)): ?>
                            <div style="font-size: 13px; color: var(--text-placeholder);">暂无工单</div>
                        <?php else: ?>
                            <?php foreach ($recent_tickets as $t): ?>
                                <div style="padding: 8px 0; border-bottom: 1px dashed var(--border-light);">
                                    <div style="font-size: 13px; color: var(--text-primary); margin-bottom: 2px;"><?php echo e($t['title']); ?></div>
                                    <div style="font-size: 12px; color: var(--text-secondary);"><?php echo e($t['username']); ?> · <?php echo ticket_status_name($t['status']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 底部：最新订单列表 -->
            <div style="background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light);">
                    <div style="font-size: 16px; font-weight: 600; color: var(--text-primary);">📋 最新10条订单</div>
                    <a href="/admin/orders.php" style="font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 500;">查看全部 →</a>
                </div>
                <div class="table-container" style="border: none; border-radius: 8px; overflow: hidden;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>订单号</th>
                                <th>用户</th>
                                <th>套餐</th>
                                <th>金额</th>
                                <th>状态</th>
                                <th>时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_orders)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-secondary); padding: 40px;">暂无订单</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td style="font-family: 'SF Mono', Monaco, monospace; font-size: 13px;"><?php echo e($order['order_no']); ?></td>
                                    <td><?php echo e($order['user_name']); ?></td>
                                    <td><?php echo e($order['package_name'] ?? '-'); ?></td>
                                    <td style="color: var(--primary); font-weight: 600;">¥<?php echo number_format($order['amount'], 2); ?></td>
                                    <td><?php echo get_status_label($order['status'], 'order'); ?></td>
                                    <td style="color: var(--text-secondary); font-size: 13px;"><?php echo format_date($order['created_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        // 更新时间
        function updateTime() {
            var now = new Date();
            var h = now.getHours().toString().padStart(2, '0');
            var m = now.getMinutes().toString().padStart(2, '0');
            var s = now.getSeconds().toString().padStart(2, '0');
            document.getElementById('current-time').textContent = h + ':' + m + ':' + s;
        }
        setInterval(updateTime, 1000);
    </script>
</body>
</html>
