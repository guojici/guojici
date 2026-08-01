<?php
require_once __DIR__ . '/../config/helper.php';
require_admin();

$user = admin_user();

$tab = $_GET['tab'] ?? 'overview';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 30;

$search_attack_type = $_GET['attack_type'] ?? '';
$search_severity = $_GET['severity'] ?? '';
$search_ip = trim($_GET['ip'] ?? '');

$ip_search = trim($_GET['ip_search'] ?? '');

$login_username = trim($_GET['username'] ?? '');
$login_ip = trim($_GET['login_ip'] ?? '');
$login_success = $_GET['success'] ?? '';

$new_api_secret = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_ip_block') {
        $ip_address = trim($_POST['ip_address'] ?? '');
        $block_type = $_POST['block_type'] ?? 'temporary';
        $reason = trim($_POST['reason'] ?? '');
        $duration = intval($_POST['duration'] ?? 3600);

        if ($ip_address === '') {
            flash('error', 'IP地址不能为空');
        } elseif (!in_array($block_type, ['permanent', 'temporary'], true)) {
            flash('error', '封禁类型非法');
        } else {
            try {
                if ($block_type === 'permanent') {
                    sec_block_ip_permanent($ip_address, $reason, 'admin');
                } else {
                    sec_block_ip_temporary($ip_address, $reason, $duration);
                }
                sec_admin_audit('add_ip_block', 'security_ip_blocks', 0, [
                    'ip' => $ip_address,
                    'type' => $block_type,
                    'reason' => $reason,
                    'duration' => $duration,
                ]);
                flash('success', 'IP封禁已添加');
            } catch (Exception $e) {
                flash('error', '添加失败：' . $e->getMessage());
            }
        }
        header('Location: /admin/security.php?tab=ip_blocks');
        exit;
    }

    if ($action === 'unblock_ip') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'ID无效');
        } else {
            $block = Database::fetch("SELECT * FROM security_ip_blocks WHERE id = ?", [$id]);
            if ($block) {
                Database::query("DELETE FROM security_ip_blocks WHERE id = ?", [$id]);
                sec_admin_audit('unblock_ip', 'security_ip_blocks', $id, [
                    'ip' => $block['ip_address'],
                ]);
                flash('success', 'IP已解封');
            } else {
                flash('error', '记录不存在');
            }
        }
        header('Location: /admin/security.php?tab=ip_blocks');
        exit;
    }

    if ($action === 'create_api_key') {
        $name = trim($_POST['name'] ?? '');
        $rate_limit = intval($_POST['rate_limit'] ?? 100);

        if ($name === '') {
            flash('error', '密钥名称不能为空');
        } else {
            try {
                $pair = sec_generate_api_key_pair();
                $id = Database::insert('api_keys', [
                    'user_id' => 0,
                    'api_key' => $pair['api_key'],
                    'api_secret' => $pair['api_secret'],
                    'status' => 'active',
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                ]);
                sec_admin_audit('create_api_key', 'api_keys', $id, [
                    'name' => $name,
                    'api_key' => $pair['api_key'],
                    'rate_limit' => $rate_limit,
                ]);
                $new_api_secret = $pair['api_secret'];
                flash('success', 'API密钥创建成功，请妥善保存Secret，仅显示一次');
            } catch (Exception $e) {
                flash('error', '创建失败：' . $e->getMessage());
            }
        }
        header('Location: /admin/security.php?tab=api_keys');
        exit;
    }

    if ($action === 'toggle_api_key') {
        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($id <= 0) {
            flash('error', 'ID无效');
        } elseif (!in_array($status, ['active', 'suspended'], true)) {
            flash('error', '状态非法');
        } else {
            Database::query("UPDATE api_keys SET status = ? WHERE id = ?", [$status, $id]);
            sec_admin_audit('toggle_api_key', 'api_keys', $id, ['status' => $status]);
            flash('success', '密钥状态已更新');
        }
        header('Location: /admin/security.php?tab=api_keys');
        exit;
    }

    if ($action === 'delete_api_key') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'ID无效');
        } else {
            $key = Database::fetch("SELECT * FROM api_keys WHERE id = ?", [$id]);
            Database::query("DELETE FROM api_keys WHERE id = ?", [$id]);
            sec_admin_audit('delete_api_key', 'api_keys', $id, [
                'api_key' => $key['api_key'] ?? '',
                'name' => $key['name'] ?? '',
            ]);
            flash('success', '密钥已删除');
        }
        header('Location: /admin/security.php?tab=api_keys');
        exit;
    }

    if ($action === 'batch_verify') {
        try {
            $tampered = sec_batch_verify_critical_data();
            sec_admin_audit('batch_verify_data', 'data_integrity', 0, [
                'tampered_count' => $tampered,
            ]);
            flash('success', '数据完整性校验完成，发现 ' . $tampered . ' 条疑似篡改记录');
        } catch (Exception $e) {
            flash('error', '校验失败：' . $e->getMessage());
        }
        header('Location: /admin/security.php?tab=data_integrity');
        exit;
    }
}

$today_attacks_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_logs WHERE DATE(created_at) = CURDATE()");
$today_attacks = intval($today_attacks_row['cnt'] ?? 0);

$blocked_ips_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_ip_blocks WHERE block_type = 'permanent' OR expires_at > NOW()");
$blocked_ips = intval($blocked_ips_row['cnt'] ?? 0);

$api_keys_count_row = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys WHERE status = 'active'");
$api_keys_count = intval($api_keys_count_row['cnt'] ?? 0);

$today_login_fail_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_login_attempts WHERE success = 0 AND DATE(created_at) = CURDATE()");
$today_login_fail = intval($today_login_fail_row['cnt'] ?? 0);

$attack_type_dist = Database::fetchAll("SELECT attack_type, COUNT(*) as cnt FROM security_logs WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY attack_type ORDER BY cnt DESC");
$total_attack_dist = 0;
foreach ($attack_type_dist as $d) $total_attack_dist += intval($d['cnt']);

$recent_attacks = Database::fetchAll("SELECT * FROM security_logs ORDER BY id DESC LIMIT 20");

$page_title = '安全中心';
$active_menu = 'security';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安全中心 - <?php echo e(config('app.name')); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .badge-low { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .badge-medium { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .badge-high { background: #fff1f0; color: #ff4d4f; border: 1px solid #ffa39e; }
        .badge-critical { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; font-weight: 600; }
        .badge-active { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .badge-suspended { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .badge-revoked { background: #f5f5f5; color: #999; border: 1px solid #e8e8e8; }
        .badge-permanent { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
        .badge-temporary { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .badge-valid { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .badge-tampered { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; font-weight: 600; }
        .badge-unknown { background: #f5f5f5; color: #999; border: 1px solid #e8e8e8; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; color: var(--text-secondary); font-size: 13px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); }
        .form-inline { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
        .form-inline .form-group { margin-bottom: 0; }
        .filter-bar { display: flex; gap: 10px; align-items: end; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-bar select, .filter-bar input { padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; }
        .filter-bar .btn { padding: 6px 14px; }
        .progress-bar-container { background: #f0f0f0; border-radius: 4px; height: 24px; overflow: hidden; margin-bottom: 8px; }
        .progress-bar-fill { height: 100%; border-radius: 4px; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px; color: #fff; font-size: 12px; font-weight: 500; transition: width 0.3s; }
        .progress-label { display: flex; justify-content: space-between; font-size: 13px; color: #666; margin-bottom: 4px; }
        .secret-box { background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; padding: 12px; margin-bottom: 16px; }
        .secret-box code { background: #fff; padding: 4px 8px; border-radius: 4px; font-size: 13px; word-break: break-all; display: block; margin-top: 6px; }
        .tampered-row { background: #fff1f0 !important; }
        .text-muted { color: #999; font-size: 12px; }
        .detail-text { color: var(--text-secondary); font-size: 12px; max-width: 280px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/_sidebar.php'; ?>

        <div class="main-content">
            <div class="page-header">
                <h2>安全中心</h2>
                <p class="subtitle">攻击防护、IP封禁、密钥管理与数据完整性监控</p>
            </div>

            <?php if ($msg = flash('success')): ?>
                <div class="alert alert-success"><?php echo e($msg); ?></div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="alert alert-error"><?php echo e($msg); ?></div>
            <?php endif; ?>

            <div class="tabs">
                <a href="/admin/security.php?tab=overview" class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">📊 安全概览</a>
                <a href="/admin/security.php?tab=attack_logs" class="tab <?php echo $tab === 'attack_logs' ? 'active' : ''; ?>">⚔️ 攻击日志</a>
                <a href="/admin/security.php?tab=ip_blocks" class="tab <?php echo $tab === 'ip_blocks' ? 'active' : ''; ?>">🚫 IP封禁</a>
                <a href="/admin/security.php?tab=api_keys" class="tab <?php echo $tab === 'api_keys' ? 'active' : ''; ?>">🔑 API密钥</a>
                <a href="/admin/security.php?tab=data_integrity" class="tab <?php echo $tab === 'data_integrity' ? 'active' : ''; ?>">🔒 数据完整性</a>
                <a href="/admin/security.php?tab=login_audit" class="tab <?php echo $tab === 'login_audit' ? 'active' : ''; ?>">📝 登录审计</a>
            </div>

            <?php if ($tab === 'overview'): ?>
                <div class="stat-cards">
                    <div class="stat-card">
                        <div class="label">今日攻击数</div>
                        <div class="value"><?php echo $today_attacks; ?></div>
                        <div class="trend">近24小时</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">封禁IP数</div>
                        <div class="value" style="color: var(--warning);"><?php echo $blocked_ips; ?></div>
                        <div class="trend">当前封禁</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">活跃API密钥</div>
                        <div class="value" style="color: var(--success);"><?php echo $api_keys_count; ?></div>
                        <div class="trend">有效密钥</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">今日登录失败</div>
                        <div class="value" style="color: var(--danger);"><?php echo $today_login_fail; ?></div>
                        <div class="trend">登录审计</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📈 攻击类型分布（近7天）</div>
                    </div>
                    <?php if (empty($attack_type_dist)): ?>
                        <div class="empty-state">暂无攻击数据</div>
                    <?php else: ?>
                        <?php
                        $attack_type_names = [
                            'sql_inject' => 'SQL注入',
                            'xss' => 'XSS攻击',
                            'csrf' => 'CSRF攻击',
                            'rate_limit' => '限流触发',
                            'replay' => '重放攻击',
                            'sign_invalid' => '签名无效',
                            'file_upload' => '非法文件上传',
                            'illegal_access' => '非法访问',
                            'path_traversal' => '目录遍历',
                            'ip_blocked' => 'IP封禁拦截',
                            'data_tamper' => '数据篡改',
                        ];
                        $colors = [
                            '#ff4d4f', '#fa8c16', '#fadb14', '#52c41a',
                            '#13c2c2', '#1890ff', '#722ed1', '#eb2f96',
                            '#fa541c', '#a0d911',
                        ];
                        foreach ($attack_type_dist as $idx => $item):
                            $pct = $total_attack_dist > 0 ? round(intval($item['cnt']) / $total_attack_dist * 100, 1) : 0;
                            $color = $colors[$idx % count($colors)];
                            $name = $attack_type_names[$item['attack_type']] ?? $item['attack_type'];
                        ?>
                            <div class="progress-label">
                                <span><?php echo e($name); ?></span>
                                <span><?php echo intval($item['cnt']); ?> 次 (<?php echo $pct; ?>%)</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;">
                                    <?php if ($pct > 15): ?><?php echo $pct; ?>%<?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🚨 最近攻击记录（最近20条）</div>
                        <a href="/admin/security.php?tab=attack_logs" class="btn btn-default btn-sm">查看全部</a>
                    </div>
                    <?php if (empty($recent_attacks)): ?>
                        <div class="empty-state">暂无攻击记录</div>
                    <?php else: ?>
                        <?php
                        $severity_map = [
                            'low' => ['低', 'badge-low'],
                            'medium' => ['中', 'badge-medium'],
                            'high' => ['高', 'badge-high'],
                            'critical' => ['严重', 'badge-critical'],
                        ];
                        ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>时间</th>
                                    <th>攻击类型</th>
                                    <th>严重程度</th>
                                    <th>IP</th>
                                    <th>URI</th>
                                    <th>是否拦截</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_attacks as $log): ?>
                                    <tr>
                                        <td><?php echo intval($log['id']); ?></td>
                                        <td><?php echo e($log['created_at']); ?></td>
                                        <td><?php echo e($attack_type_names[$log['attack_type']] ?? $log['attack_type']); ?></td>
                                        <td>
                                            <?php
                                            $sv = $severity_map[$log['severity']] ?? [$log['severity'], 'badge-low'];
                                            echo '<span class="status-badge ' . $sv[1] . '">' . $sv[0] . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo e($log['ip_address']); ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($log['request_uri']); ?>">
                                            <?php echo e($log['request_uri']); ?>
                                        </td>
                                        <td><?php echo !empty($log['blocked']) ? '✅ 已拦截' : '❌ 未拦截'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'attack_logs'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">⚔️ 攻击日志</div>
                    </div>

                    <form method="GET" action="" class="filter-bar">
                        <input type="hidden" name="tab" value="attack_logs">
                        <div class="form-group">
                            <label>攻击类型</label>
                            <select name="attack_type">
                                <option value="">全部</option>
                                <?php foreach ($attack_type_names as $k => $v): ?>
                                    <option value="<?php echo e($k); ?>" <?php echo $search_attack_type === $k ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>严重程度</label>
                            <select name="severity">
                                <option value="">全部</option>
                                <option value="low" <?php echo $search_severity === 'low' ? 'selected' : ''; ?>>低</option>
                                <option value="medium" <?php echo $search_severity === 'medium' ? 'selected' : ''; ?>>中</option>
                                <option value="high" <?php echo $search_severity === 'high' ? 'selected' : ''; ?>>高</option>
                                <option value="critical" <?php echo $search_severity === 'critical' ? 'selected' : ''; ?>>严重</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>IP地址</label>
                            <input type="text" name="ip" value="<?php echo e($search_ip); ?>" placeholder="搜索IP">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                        <a href="/admin/security.php?tab=attack_logs" class="btn btn-default btn-sm">重置</a>
                    </form>

                    <?php
                    $where = ' WHERE 1=1';
                    $params = [];
                    if ($search_attack_type !== '') {
                        $where .= " AND attack_type = ?";
                        $params[] = $search_attack_type;
                    }
                    if ($search_severity !== '') {
                        $where .= " AND severity = ?";
                        $params[] = $search_severity;
                    }
                    if ($search_ip !== '') {
                        $where .= " AND ip_address LIKE ?";
                        $params[] = '%' . $search_ip . '%';
                    }

                    $cnt_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_logs" . $where, $params);
                    $total = intval($cnt_row['cnt'] ?? 0);
                    $total_pages = max(1, ceil($total / $per_page));
                    $offset = ($page - 1) * $per_page;

                    $logs = Database::fetchAll("SELECT * FROM security_logs" . $where . " ORDER BY id DESC LIMIT $offset, $per_page", $params);
                    ?>

                    <?php if (empty($logs)): ?>
                        <div class="empty-state">暂无攻击日志</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>时间</th>
                                    <th>攻击类型</th>
                                    <th>严重程度</th>
                                    <th>IP</th>
                                    <th>用户ID</th>
                                    <th>URI</th>
                                    <th>是否拦截</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo intval($log['id']); ?></td>
                                        <td><?php echo e($log['created_at']); ?></td>
                                        <td><?php echo e($attack_type_names[$log['attack_type']] ?? $log['attack_type']); ?></td>
                                        <td>
                                            <?php
                                            $sv = $severity_map[$log['severity']] ?? [$log['severity'], 'badge-low'];
                                            echo '<span class="status-badge ' . $sv[1] . '">' . $sv[0] . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo e($log['ip_address']); ?></td>
                                        <td><?php echo intval($log['user_id']) ?: '-'; ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($log['request_uri']); ?>">
                                            <?php echo e($log['request_uri']); ?>
                                        </td>
                                        <td><?php echo !empty($log['blocked']) ? '✅ 已拦截' : '❌ 未拦截'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="/admin/security.php?tab=attack_logs&attack_type=<?php echo e($search_attack_type); ?>&severity=<?php echo e($search_severity); ?>&ip=<?php echo e($search_ip); ?>&page=<?php echo $page - 1; ?>">上一页</a>
                                <?php endif; ?>
                                <span class="current">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页 (共 <?php echo $total; ?> 条)</span>
                                <?php if ($page < $total_pages): ?>
                                    <a href="/admin/security.php?tab=attack_logs&attack_type=<?php echo e($search_attack_type); ?>&severity=<?php echo e($search_severity); ?>&ip=<?php echo e($search_ip); ?>&page=<?php echo $page + 1; ?>">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pagination"><span class="current">共 <?php echo $total; ?> 条</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'ip_blocks'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">➕ 添加IP封禁</div>
                    </div>
                    <form method="POST" action="/admin/security.php?tab=ip_blocks" class="form-inline">
                        <input type="hidden" name="action" value="add_ip_block">
                        <div class="form-group">
                            <label>IP地址</label>
                            <input type="text" name="ip_address" placeholder="如 1.2.3.4" required style="width: 160px;">
                        </div>
                        <div class="form-group">
                            <label>封禁类型</label>
                            <select name="block_type" style="width: 120px;" onchange="toggleDuration(this.value)">
                                <option value="temporary">临时</option>
                                <option value="permanent">永久</option>
                            </select>
                        </div>
                        <div class="form-group" id="durationGroup">
                            <label>时长(秒)</label>
                            <input type="number" name="duration" value="3600" min="60" style="width: 120px;">
                        </div>
                        <div class="form-group">
                            <label>原因</label>
                            <input type="text" name="reason" placeholder="封禁原因" style="width: 200px;">
                        </div>
                        <button type="submit" class="btn btn-danger">添加封禁</button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🚫 IP封禁列表</div>
                    </div>

                    <form method="GET" action="" class="filter-bar">
                        <input type="hidden" name="tab" value="ip_blocks">
                        <div class="form-group">
                            <label>IP搜索</label>
                            <input type="text" name="ip_search" value="<?php echo e($ip_search); ?>" placeholder="搜索IP地址">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">搜索</button>
                        <a href="/admin/security.php?tab=ip_blocks" class="btn btn-default btn-sm">重置</a>
                    </form>

                    <?php
                    $ip_where = ' WHERE 1=1';
                    $ip_params = [];
                    if ($ip_search !== '') {
                        $ip_where .= " AND ip_address LIKE ?";
                        $ip_params[] = '%' . $ip_search . '%';
                    }

                    $ip_cnt_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_ip_blocks" . $ip_where, $ip_params);
                    $ip_total = intval($ip_cnt_row['cnt'] ?? 0);
                    $ip_total_pages = max(1, ceil($ip_total / $per_page));
                    $ip_offset = ($page - 1) * $per_page;
                    $ip_blocks = Database::fetchAll("SELECT * FROM security_ip_blocks" . $ip_where . " ORDER BY id DESC LIMIT $ip_offset, $per_page", $ip_params);
                    ?>

                    <?php if (empty($ip_blocks)): ?>
                        <div class="empty-state">暂无IP封禁记录</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>IP地址</th>
                                    <th>类型</th>
                                    <th>原因</th>
                                    <th>封禁者</th>
                                    <th>封禁时间</th>
                                    <th>过期时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ip_blocks as $ip): ?>
                                    <tr>
                                        <td><?php echo intval($ip['id']); ?></td>
                                        <td><?php echo e($ip['ip_address']); ?></td>
                                        <td>
                                            <?php if ($ip['block_type'] === 'permanent'): ?>
                                                <span class="status-badge badge-permanent">永久</span>
                                            <?php else: ?>
                                                <span class="status-badge badge-temporary">临时</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 200px;"><?php echo e($ip['reason'] ?: '-'); ?></td>
                                        <td><?php echo e($ip['blocked_by']); ?></td>
                                        <td><?php echo e($ip['created_at']); ?></td>
                                        <td>
                                            <?php if ($ip['block_type'] === 'permanent'): ?>
                                                永久
                                            <?php else: ?>
                                                <?php echo e($ip['expires_at']); ?>
                                                <?php if (strtotime($ip['expires_at']) < time()): ?>
                                                    <span class="text-muted"> (已过期)</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="/admin/security.php?tab=ip_blocks" style="display:inline;" onsubmit="return confirm('确定解封此IP？');">
                                                <input type="hidden" name="action" value="unblock_ip">
                                                <input type="hidden" name="id" value="<?php echo intval($ip['id']); ?>">
                                                <button type="submit" class="btn btn-success btn-sm">解封</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($ip_total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="/admin/security.php?tab=ip_blocks&ip_search=<?php echo e($ip_search); ?>&page=<?php echo $page - 1; ?>">上一页</a>
                                <?php endif; ?>
                                <span class="current">第 <?php echo $page; ?> / <?php echo $ip_total_pages; ?> 页 (共 <?php echo $ip_total; ?> 条)</span>
                                <?php if ($page < $ip_total_pages): ?>
                                    <a href="/admin/security.php?tab=ip_blocks&ip_search=<?php echo e($ip_search); ?>&page=<?php echo $page + 1; ?>">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pagination"><span class="current">共 <?php echo $ip_total; ?> 条</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'api_keys'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">➕ 创建API密钥</div>
                    </div>
                    <form method="POST" action="/admin/security.php?tab=api_keys" class="form-inline">
                        <input type="hidden" name="action" value="create_api_key">
                        <div class="form-group">
                            <label>密钥名称</label>
                            <input type="text" name="name" placeholder="密钥标识名称" required style="width: 200px;">
                        </div>
                        <div class="form-group">
                            <label>限流(次/分钟)</label>
                            <input type="number" name="rate_limit" value="100" min="1" max="10000" style="width: 140px;">
                        </div>
                        <button type="submit" class="btn btn-primary">创建密钥</button>
                    </form>
                </div>

                <?php if (!empty($new_api_secret)): ?>
                    <div class="alert alert-warning">
                        <strong>⚠️ 请妥善保存以下API Secret，仅显示一次，关闭页面后无法找回！</strong>
                        <div class="secret-box">
                            <div>API Secret:</div>
                            <code><?php echo e($new_api_secret); ?></code>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🔑 API密钥列表</div>
                    </div>

                    <?php
                    $key_cnt_row = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys");
                    $key_total = intval($key_cnt_row['cnt'] ?? 0);
                    $key_total_pages = max(1, ceil($key_total / $per_page));
                    $key_offset = ($page - 1) * $per_page;
                    $api_keys = Database::fetchAll("SELECT * FROM api_keys ORDER BY id DESC LIMIT $key_offset, $per_page");

                    $key_status_map = [
                        'pending' => ['待审核', 'badge-temporary'],
                        'active' => ['已启用', 'badge-active'],
                        'suspended' => ['已停用', 'badge-suspended'],
                        'revoked' => ['已吊销', 'badge-revoked'],
                    ];
                    ?>

                    <?php if (empty($api_keys)): ?>
                        <div class="empty-state">暂无API密钥</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>名称</th>
                                    <th>API Key</th>
                                    <th>状态</th>
                                    <th>限流(次/分)</th>
                                    <th>最后使用时间</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($api_keys as $key): ?>
                                    <tr>
                                        <td><?php echo intval($key['id']); ?></td>
                                        <td><strong><?php echo e($key['name']); ?></strong></td>
                                        <td><code style="font-size: 11px;"><?php echo e($key['api_key']); ?></code></td>
                                        <td>
                                            <?php
                                            $ks = $key_status_map[$key['status']] ?? [$key['status'], 'badge-revoked'];
                                            echo '<span class="status-badge ' . $ks[1] . '">' . $ks[0] . '</span>';
                                            ?>
                                        </td>
                                        <td><?php echo intval($key['rate_limit']); ?></td>
                                        <td><?php echo $key['last_used_at'] ? e($key['last_used_at']) : '<span class="text-muted">从未使用</span>'; ?></td>
                                        <td><?php echo e($key['created_at']); ?></td>
                                        <td style="white-space: nowrap;">
                                            <?php if ($key['status'] === 'active'): ?>
                                                <form method="POST" action="/admin/security.php?tab=api_keys" style="display:inline;" onsubmit="return confirm('确定停用此密钥？');">
                                                    <input type="hidden" name="action" value="toggle_api_key">
                                                    <input type="hidden" name="id" value="<?php echo intval($key['id']); ?>">
                                                    <input type="hidden" name="status" value="suspended">
                                                    <button type="submit" class="btn btn-warning btn-sm">停用</button>
                                                </form>
                                            <?php elseif ($key['status'] === 'suspended' || $key['status'] === 'pending'): ?>
                                                <form method="POST" action="/admin/security.php?tab=api_keys" style="display:inline;" onsubmit="return confirm('确定启用此密钥？');">
                                                    <input type="hidden" name="action" value="toggle_api_key">
                                                    <input type="hidden" name="id" value="<?php echo intval($key['id']); ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="btn btn-success btn-sm">启用</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="/admin/security.php?tab=api_keys" style="display:inline;" onsubmit="return confirm('确定删除此密钥？删除后不可恢复！');">
                                                <input type="hidden" name="action" value="delete_api_key">
                                                <input type="hidden" name="id" value="<?php echo intval($key['id']); ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($key_total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="/admin/security.php?tab=api_keys&page=<?php echo $page - 1; ?>">上一页</a>
                                <?php endif; ?>
                                <span class="current">第 <?php echo $page; ?> / <?php echo $key_total_pages; ?> 页 (共 <?php echo $key_total; ?> 条)</span>
                                <?php if ($page < $key_total_pages): ?>
                                    <a href="/admin/security.php?tab=api_keys&page=<?php echo $page + 1; ?>">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pagination"><span class="current">共 <?php echo $key_total; ?> 条</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'data_integrity'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🔒 数据完整性校验</div>
                        <form method="POST" action="/admin/security.php?tab=data_integrity" style="display:inline;" onsubmit="return confirm('确定开始批量校验？可能需要一些时间。');">
                            <input type="hidden" name="action" value="batch_verify">
                            <button type="submit" class="btn btn-primary btn-sm">🔍 手动校验</button>
                        </form>
                    </div>
                    <p class="text-muted">校验关键数据表的指纹信息，检测是否存在数据篡改。</p>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📋 数据完整性指纹记录</div>
                    </div>

                    <?php
                    $di_cnt_row = Database::fetch("SELECT COUNT(*) as cnt FROM data_integrity_fingerprints");
                    $di_total = intval($di_cnt_row['cnt'] ?? 0);
                    $di_total_pages = max(1, ceil($di_total / $per_page));
                    $di_offset = ($page - 1) * $per_page;
                    $fingerprints = Database::fetchAll("SELECT * FROM data_integrity_fingerprints ORDER BY id DESC LIMIT $di_offset, $per_page");

                    $di_status_map = [
                        'valid' => ['正常', 'badge-valid'],
                        'tampered' => ['篡改', 'badge-tampered'],
                        'unknown' => ['未知', 'badge-unknown'],
                    ];
                    ?>

                    <?php if (empty($fingerprints)): ?>
                        <div class="empty-state">暂无指纹记录</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>表名</th>
                                    <th>记录ID</th>
                                    <th>版本</th>
                                    <th>状态</th>
                                    <th>指纹</th>
                                    <th>创建时间</th>
                                    <th>校验时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fingerprints as $fp): ?>
                                    <tr class="<?php echo $fp['status'] === 'tampered' ? 'tampered-row' : ''; ?>">
                                        <td><?php echo intval($fp['id']); ?></td>
                                        <td><?php echo e($fp['table_name']); ?></td>
                                        <td><?php echo intval($fp['record_id']); ?></td>
                                        <td>v<?php echo intval($fp['version']); ?></td>
                                        <td>
                                            <?php
                                            $ds = $di_status_map[$fp['status']] ?? [$fp['status'], 'badge-unknown'];
                                            echo '<span class="status-badge ' . $ds[1] . '">' . $ds[0] . '</span>';
                                            ?>
                                            <?php if ($fp['status'] === 'tampered'): ?>
                                                <span style="color: #cf1322; font-weight: bold; margin-left: 4px;">⚠️</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 160px; overflow: hidden; text-overflow: ellipsis; font-family: monospace; font-size: 11px;" title="<?php echo e($fp['fingerprint']); ?>">
                                            <?php echo e(substr($fp['fingerprint'], 0, 24) . '...'); ?>
                                        </td>
                                        <td><?php echo e($fp['created_at']); ?></td>
                                        <td><?php echo $fp['verified_at'] ? e($fp['verified_at']) : '<span class="text-muted">-</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($di_total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="/admin/security.php?tab=data_integrity&page=<?php echo $page - 1; ?>">上一页</a>
                                <?php endif; ?>
                                <span class="current">第 <?php echo $page; ?> / <?php echo $di_total_pages; ?> 页 (共 <?php echo $di_total; ?> 条)</span>
                                <?php if ($page < $di_total_pages): ?>
                                    <a href="/admin/security.php?tab=data_integrity&page=<?php echo $page + 1; ?>">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pagination"><span class="current">共 <?php echo $di_total; ?> 条</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'login_audit'): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📝 登录审计</div>
                    </div>

                    <form method="GET" action="" class="filter-bar">
                        <input type="hidden" name="tab" value="login_audit">
                        <div class="form-group">
                            <label>用户名</label>
                            <input type="text" name="username" value="<?php echo e($login_username); ?>" placeholder="搜索用户名">
                        </div>
                        <div class="form-group">
                            <label>IP地址</label>
                            <input type="text" name="login_ip" value="<?php echo e($login_ip); ?>" placeholder="搜索IP">
                        </div>
                        <div class="form-group">
                            <label>状态</label>
                            <select name="success">
                                <option value="">全部</option>
                                <option value="1" <?php echo $login_success === '1' ? 'selected' : ''; ?>>成功</option>
                                <option value="0" <?php echo $login_success === '0' ? 'selected' : ''; ?>>失败</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                        <a href="/admin/security.php?tab=login_audit" class="btn btn-default btn-sm">重置</a>
                    </form>

                    <?php
                    $la_where = ' WHERE 1=1';
                    $la_params = [];
                    if ($login_username !== '') {
                        $la_where .= " AND username LIKE ?";
                        $la_params[] = '%' . $login_username . '%';
                    }
                    if ($login_ip !== '') {
                        $la_where .= " AND ip_address LIKE ?";
                        $la_params[] = '%' . $login_ip . '%';
                    }
                    if ($login_success !== '') {
                        $la_where .= " AND success = ?";
                        $la_params[] = intval($login_success);
                    }

                    $la_cnt_row = Database::fetch("SELECT COUNT(*) as cnt FROM security_login_attempts" . $la_where, $la_params);
                    $la_total = intval($la_cnt_row['cnt'] ?? 0);
                    $la_total_pages = max(1, ceil($la_total / $per_page));
                    $la_offset = ($page - 1) * $per_page;
                    $login_attempts = Database::fetchAll("SELECT * FROM security_login_attempts" . $la_where . " ORDER BY id DESC LIMIT $la_offset, $per_page", $la_params);
                    ?>

                    <?php if (empty($login_attempts)): ?>
                        <div class="empty-state">暂无登录记录</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>时间</th>
                                    <th>用户名</th>
                                    <th>IP</th>
                                    <th>UA</th>
                                    <th>是否成功</th>
                                    <th>失败原因</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($login_attempts as $att): ?>
                                    <tr>
                                        <td><?php echo intval($att['id']); ?></td>
                                        <td><?php echo e($att['created_at']); ?></td>
                                        <td><?php echo e($att['username']); ?></td>
                                        <td><?php echo e($att['ip_address']); ?></td>
                                        <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px;" title="<?php echo e($att['user_agent']); ?>">
                                            <?php echo e(mb_substr($att['user_agent'], 0, 50)); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($att['success'])): ?>
                                                <span class="status-badge badge-valid">成功</span>
                                            <?php else: ?>
                                                <span class="status-badge badge-critical">失败</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($att['fail_reason'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($la_total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="/admin/security.php?tab=login_audit&username=<?php echo e($login_username); ?>&login_ip=<?php echo e($login_ip); ?>&success=<?php echo e($login_success); ?>&page=<?php echo $page - 1; ?>">上一页</a>
                                <?php endif; ?>
                                <span class="current">第 <?php echo $page; ?> / <?php echo $la_total_pages; ?> 页 (共 <?php echo $la_total; ?> 条)</span>
                                <?php if ($page < $la_total_pages): ?>
                                    <a href="/admin/security.php?tab=login_audit&username=<?php echo e($login_username); ?>&login_ip=<?php echo e($login_ip); ?>&success=<?php echo e($login_success); ?>&page=<?php echo $page + 1; ?>">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pagination"><span class="current">共 <?php echo $la_total; ?> 条</span></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleDuration(type) {
            var durationGroup = document.getElementById('durationGroup');
            if (type === 'permanent') {
                durationGroup.style.display = 'none';
            } else {
                durationGroup.style.display = 'block';
            }
        }
    </script>
</body>
</html>
