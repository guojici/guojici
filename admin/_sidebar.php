<?php
$admin = admin_user();
$current_page = $_SERVER['REQUEST_URI'];

// 社区免费版菜单结构
$menu_groups = [
    [
        'name' => '概览监控',
        'icon' => '📊',
        'items' => [
            ['url' => '/admin/index.php', 'icon' => '📊', 'name' => '数据概览'],
        ]
    ],
    [
        'name' => '用户管理',
        'icon' => '👥',
        'items' => [
            ['url' => '/admin/users.php', 'icon' => '👥', 'name' => '用户管理'],
            ['url' => '/admin/user_quotas.php', 'icon' => '📊', 'name' => '租户配额'],
            ['url' => '/admin/api_keys.php', 'icon' => '🔑', 'name' => 'API密钥审核', 'badge' => function() {
                try {
                    $pk = Database::fetch("SELECT COUNT(*) as cnt FROM api_keys WHERE status = 'pending'");
                    return intval($pk['cnt'] ?? 0);
                } catch (Exception $e) { return 0; }
            }],
        ]
    ],
    [
        'name' => '订单财务',
        'icon' => '💰',
        'items' => [
            ['url' => '/admin/orders.php', 'icon' => '📋', 'name' => '订单管理'],
            ['url' => '/admin/refunds.php', 'icon' => '↩️', 'name' => '退款管理', 'badge' => function() {
                try {
                    $prc = Database::fetch("SELECT COUNT(*) as cnt FROM refund_requests WHERE status = 'pending'");
                    return intval($prc['cnt'] ?? 0);
                } catch (Exception $e) { return 0; }
            }],
            ['url' => '/admin/finance.php', 'icon' => '💰', 'name' => '财务统计'],
        ]
    ],
    [
        'name' => '主机管理',
        'icon' => '🖥️',
        'items' => [
            ['url' => '/admin/hosts.php', 'icon' => '🖥️', 'name' => '主机管理'],
        ]
    ],
    [
        'name' => '套餐管理',
        'icon' => '📦',
        'items' => [
            ['url' => '/admin/packages.php', 'icon' => '📦', 'name' => '套餐管理'],
            ['url' => '/admin/package_categories.php', 'icon' => '🏷️', 'name' => '套餐分类'],
            ['url' => '/admin/regions.php', 'icon' => '🌍', 'name' => '地区管理'],
            ['url' => '/admin/vm_images.php', 'icon' => '💿', 'name' => 'KVM镜像管理'],
        ]
    ],
    [
        'name' => '系统支持',
        'icon' => '🔔',
        'items' => [
            ['url' => '/admin/notifications.php', 'icon' => '🔔', 'name' => '通知管理'],
        ]
    ],
    [
        'name' => '系统安全',
        'icon' => '🛡️',
        'items' => [
            ['url' => '/admin/alerts.php', 'icon' => '🚨', 'name' => '告警中心'],
            ['url' => '/admin/security.php', 'icon' => '🛡️', 'name' => '安全中心'],
        ]
    ],
    [
        'name' => '管理员管理',
        'icon' => '👤',
        'items' => [
            ['url' => '/admin/admin_users.php', 'icon' => '👤', 'name' => '管理员账号'],
            ['url' => '/admin/operation_logs.php', 'icon' => '📝', 'name' => '操作日志'],
        ]
    ],
    [
        'name' => '系统设置',
        'icon' => '⚙️',
        'items' => [
            ['url' => '/admin/settings.php', 'icon' => '⚙️', 'name' => '系统设置'],
            ['url' => '/admin/theme.php', 'icon' => '🎨', 'name' => '主题配置'],
        ]
    ],
    [
        'name' => '快捷链接',
        'icon' => '🔗',
        'items' => [
            ['url' => '/', 'icon' => '🏠', 'name' => '前台首页', 'style' => 'color: var(--text-regular);'],
            ['url' => '/admin/logout.php', 'icon' => '🚪', 'name' => '退出登录', 'style' => 'color: var(--danger);'],
        ]
    ],
];

if (!function_exists('is_menu_active')) {
    function is_menu_active($url) {
        global $current_page;
        return strpos($current_page, $url) !== false;
    }
}
?>
<aside class="sidebar">
    <div class="user-profile">
        <div class="avatar" style="background: linear-gradient(135deg, #1677ff 0%, #69b1ff 100%);"><?php echo mb_substr($admin['username'], 0, 1); ?></div>
        <h3><?php echo e($admin['username']); ?></h3>
        <p class="email"><?php echo $admin['role'] == 'super_admin' ? '超级管理员' : '管理员'; ?></p>
    </div>
    <ul class="sidebar-menu">
        <?php foreach ($menu_groups as $group): ?>
        <?php
            $has_active = false;
            foreach ($group['items'] as $item) {
                if (is_menu_active($item['url'])) { $has_active = true; break; }
            }
        ?>
        <li class="menu-group">
            <div class="menu-group-header" onclick="toggleMenuGroup(this)" <?php echo $has_active ? 'class="menu-group-header expanded"' : ''; ?>>
                <span class="group-icon"><?php echo $group['icon']; ?></span>
                <span class="group-name"><?php echo $group['name']; ?></span>
                <span class="group-arrow">▶</span>
            </div>
            <ul class="menu-group-items" <?php echo $has_active ? 'style="display:block;"' : ''; ?>>
                <?php foreach ($group['items'] as $item): ?>
                <li><a href="<?php echo $item['url']; ?>" <?php echo !empty($item['style']) ? 'style="' . $item['style'] . '"' : ''; ?> class="<?php echo is_menu_active($item['url']) ? 'active' : ''; ?>">
                    <span class="icon"><?php echo $item['icon']; ?></span>
                    <span><?php echo $item['name']; ?></span>
                    <?php if (!empty($item['badge'])): ?>
                    <?php $badge_cnt = is_callable($item['badge']) ? $item['badge']() : $item['badge']; ?>
                    <?php if ($badge_cnt > 0): ?>
                    <span class="badge-count"><?php echo $badge_cnt; ?></span>
                    <?php endif; ?>
                    <?php endif; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endforeach; ?>
    </ul>
</aside>

<style>
.menu-group { margin-bottom: 2px; }
.menu-group-header {
    display: flex; align-items: center; padding: 8px 16px;
    font-size: 13px; font-weight: 600; color: #4e5969;
    cursor: pointer; transition: all 0.2s;
    background: #f7f8fa; border-radius: 4px; margin: 0 8px;
}
.menu-group-header:hover { background: #eef2f7; color: #1677ff; }
.menu-group-header.expanded { background: #1677ff; color: #fff; }
.group-icon { margin-right: 8px; font-size: 14px; }
.group-name { flex: 1; }
.group-arrow { font-size: 10px; transition: transform 0.2s; }
.menu-group-header.expanded .group-arrow { transform: rotate(90deg); }
.menu-group-items { display: none; padding-left: 12px; margin: 4px 0; list-style: none; }
.menu-group-items li { margin: 1px 0; }
.menu-group-items li a {
    display: flex; align-items: center; padding: 6px 16px 6px 28px;
    font-size: 12px; color: #4e5969; text-decoration: none;
    transition: all 0.2s; border-radius: 4px; margin: 0 8px;
}
.menu-group-items li a:hover { background: #f0f5ff; color: #1677ff; }
.menu-group-items li a.active { background: #e6f4ff; color: #1677ff; font-weight: 500; }
.menu-group-items .icon { margin-right: 8px; font-size: 13px; }
.badge-count {
    background: var(--danger); color: #fff; font-size: 10px;
    padding: 1px 6px; border-radius: 10px; margin-left: 4px;
}
</style>

<script>
function toggleMenuGroup(el) {
    el.classList.toggle('expanded');
    var items = el.nextElementSibling;
    items.style.display = items.style.display === 'block' ? 'none' : 'block';
}
</script>
