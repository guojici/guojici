<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

migrate_new_tables();

$page_title = '财务统计';
$active_menu = 'finance';

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$where = "WHERE created_at >= ? AND created_at <= ?";
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];

$stats = Database::fetch("SELECT 
    COUNT(*) as total_records,
    SUM(CASE WHEN bill_type IN ('package','renew','upgrade','hourly') AND amount > 0 THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN bill_type = 'renew' AND amount > 0 THEN amount ELSE 0 END) as renew_income,
    SUM(CASE WHEN bill_type = 'package' AND amount > 0 THEN amount ELSE 0 END) as new_income,
    SUM(CASE WHEN bill_type = 'upgrade' AND amount > 0 THEN amount ELSE 0 END) as upgrade_income,
    SUM(CASE WHEN bill_type IN ('refund') THEN ABS(amount) ELSE 0 END) as total_refund,
    COUNT(DISTINCT user_id) as paying_users
    FROM billing_records br $where", $params);

$order_stats = Database::fetch("SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_amount
    FROM orders WHERE created_at >= ? AND created_at <= ?", 
    [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);

$user_stats = Database::fetch("SELECT 
    COUNT(*) as total_users,
    SUM(balance) as total_balance
    FROM users WHERE status = 'active'");

$daily_data = Database::fetchAll("SELECT 
    DATE(created_at) as date,
    SUM(CASE WHEN bill_type IN ('package','renew','upgrade','hourly') AND amount > 0 THEN amount ELSE 0 END) as income,
    SUM(CASE WHEN bill_type = 'refund' THEN ABS(amount) ELSE 0 END) as refund,
    COUNT(DISTINCT user_id) as user_count
    FROM billing_records br $where
    GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30", $params);

$by_type = Database::fetchAll("SELECT 
    bill_type,
    COUNT(*) as count,
    SUM(amount) as total_amount
    FROM billing_records br $where
    GROUP BY bill_type ORDER BY total_amount DESC", $params);

$type_names = [
    'package' => '新购',
    'renew' => '续费',
    'upgrade' => '升级',
    'overage' => '超量',
    'refund' => '退款',
    'adjust' => '调整',
    'hourly' => '按量',
];

$page = max(1, intval($_GET['page'] ?? 1));
$page_size = 20;
$offset = ($page - 1) * $page_size;

$bill_type_filter = $_GET['bill_type'] ?? '';
$list_where = str_replace('created_at', 'br.created_at', $where);
$list_params = $params;
if ($bill_type_filter) {
    $list_where .= " AND bill_type = ?";
    $list_params[] = $bill_type_filter;
}

$total_records = Database::fetch("SELECT COUNT(*) as cnt FROM billing_records br $list_where", $list_params);
$records = Database::fetchAll("SELECT br.*, u.username 
    FROM billing_records br 
    LEFT JOIN users u ON br.user_id = u.id 
    $list_where
    ORDER BY br.id DESC LIMIT ? OFFSET ?", 
    array_merge($list_params, [$page_size, $offset]));

$total_pages = ceil(intval($total_records['cnt']) / $page_size);

$page_title = '财务统计 - ' . config('app.name');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>财务统计 - <?php echo config('app.name'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>
<div class="main-content">
    <div class="page-header">
        <h2>💰 财务统计</h2>
        <div class="breadcrumb">首页 / 财务统计</div>
    </div>

    <div class="card">
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>开始日期</label>
                <input type="date" name="start_date" value="<?php echo e($start_date); ?>" class="form-control">
            </div>
            <div class="filter-group">
                <label>结束日期</label>
                <input type="date" name="end_date" value="<?php echo e($end_date); ?>" class="form-control">
            </div>
            <div class="filter-group">
                <label>账单类型</label>
                <select name="bill_type" class="form-control">
                    <option value="">全部</option>
                    <?php foreach ($type_names as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $bill_type_filter == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">查询</button>
            </div>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" style="color: #22c55e;">¥<?php echo number_format(floatval($stats['total_income'] ?? 0), 2); ?></div>
            <div class="stat-label">总收入</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #ef4444;">¥<?php echo number_format(floatval($stats['total_refund'] ?? 0), 2); ?></div>
            <div class="stat-label">总退款</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">¥<?php echo number_format(floatval(($stats['total_income'] ?? 0) - ($stats['total_refund'] ?? 0)), 2); ?></div>
            <div class="stat-label">净收入</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo intval($stats['paying_users'] ?? 0); ?></div>
            <div class="stat-label">付费用户数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #3b82f6;">¥<?php echo number_format(floatval($stats['new_income'] ?? 0), 2); ?></div>
            <div class="stat-label">新购收入</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #8b5cf6;">¥<?php echo number_format(floatval($stats['renew_income'] ?? 0), 2); ?></div>
            <div class="stat-label">续费收入</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">¥<?php echo number_format(floatval($stats['upgrade_income'] ?? 0), 2); ?></div>
            <div class="stat-label">升级收入</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">¥<?php echo number_format(floatval($user_stats['total_balance'] ?? 0), 2); ?></div>
            <div class="stat-label">用户余额总计</div>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><span>每日收入趋势（近30天）</span></div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>收入</th>
                        <th>退款</th>
                        <th>付费用户</th>
                        <th>净收入</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_data as $d): ?>
                    <tr>
                        <td><?php echo $d['date']; ?></td>
                        <td style="color: #22c55e;">¥<?php echo number_format(floatval($d['income'] ?? 0), 2); ?></td>
                        <td style="color: #ef4444;">¥<?php echo number_format(floatval($d['refund'] ?? 0), 2); ?></td>
                        <td><?php echo intval($d['user_count'] ?? 0); ?></td>
                        <td>¥<?php echo number_format(floatval(($d['income'] ?? 0) - ($d['refund'] ?? 0)), 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($daily_data)): ?>
                    <tr><td colspan="5" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><span>按类型统计</span></div>
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <?php foreach ($by_type as $t): ?>
            <div class="stat-card" style="text-align: center;">
                <div class="stat-value">¥<?php echo number_format(floatval($t['total_amount'] ?? 0), 2); ?></div>
                <div class="stat-label"><?php echo $type_names[$t['bill_type']] ?? $t['bill_type']; ?> (<?php echo intval($t['count']); ?>笔)</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            <span>账单明细</span>
            <span style="font-size: 13px; font-weight: normal; color: var(--text-secondary);">共 <?php echo intval($total_records['cnt']); ?> 条</span>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户</th>
                        <th>类型</th>
                        <th>金额</th>
                        <th>描述</th>
                        <th>计费周期</th>
                        <th>状态</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo $r['id']; ?></td>
                        <td><?php echo e($r['username'] ?? '-'); ?></td>
                        <td><span class="badge badge-<?php echo $r['bill_type'] == 'refund' ? 'danger' : 'success'; ?>"><?php echo $type_names[$r['bill_type']] ?? $r['bill_type']; ?></span></td>
                        <td style="<?php echo floatval($r['amount']) >= 0 ? 'color: #22c55e;' : 'color: #ef4444;'; ?>">
                            <?php echo floatval($r['amount']) >= 0 ? '+' : ''; ?>¥<?php echo number_format(floatval($r['amount']), 2); ?>
                        </td>
                        <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo e($r['description'] ?? ''); ?></td>
                        <td><?php echo e($r['billing_period'] ?? '-'); ?></td>
                        <td><?php echo $r['status']; ?></td>
                        <td><?php echo $r['created_at']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($records)): ?>
                    <tr><td colspan="8" style="text-align:center; color: var(--text-secondary); padding: 30px;">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="page-btn active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&bill_type=<?php echo urlencode($bill_type_filter); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
    </div>
</body>
</html>
