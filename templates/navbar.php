<?php
require_once __DIR__ . '/../config/helper.php';
$user = auth_check() ? auth_user() : null;
$current_page = $_SERVER['REQUEST_URI'];
$unread_count = 0;
$notification_list = [];
if ($user) {
    $unread_count = get_unread_notification_count($user['id']);
    $notification_list = get_user_notifications($user['id'], 8);
}

// 动态获取地区列表
$nav_regions = [];
$nav_default_region = null;
try {
    $nav_regions = Database::fetchAll("SELECT * FROM regions WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
    foreach ($nav_regions as $r) {
        if ($r['is_default']) { $nav_default_region = $r; break; }
    }
    // 没有默认则取第一个
    if (!$nav_default_region && !empty($nav_regions)) {
        $nav_default_region = $nav_regions[0];
    }
} catch (Exception $e) {}

// 当前选中的地区（支持用户切换，保存到session）
if ($user && !empty($nav_regions)) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $selected_region_id = intval($_SESSION['selected_region_id'] ?? 0);
    $selected_region = null;
    if ($selected_region_id > 0) {
        foreach ($nav_regions as $r) {
            if (intval($r['id']) === $selected_region_id) { $selected_region = $r; break; }
        }
    }
    if (!$selected_region) {
        $selected_region = $nav_default_region ?: $nav_regions[0];
    }
} else {
    $selected_region = $nav_default_region;
}
?>
<nav class="navbar">
    <div class="nav-container">
        <div class="nav-left">
            <a href="/" class="logo">
                <span class="logo-icon"><?php echo e(config('site.logo_icon')); ?></span>
                <span class="logo-text"><?php echo e(config('site.logo_text')); ?></span>
            </a>
            <div class="nav-links">
                <a href="/" class="<?php echo ($current_page === '/' || strpos($current_page, 'index.php') !== false) && strpos($current_page, '/user') === false && strpos($current_page, '/admin') === false ? 'active' : ''; ?>"><?php echo __('nav.home'); ?></a>
                <a href="#pricing" class="<?php echo strpos($current_page, '#pricing') !== false ? 'active' : ''; ?>"><?php echo __('nav.pricing'); ?></a>
                <a href="/user/hosts.php" class="<?php echo strpos($current_page, '/user/hosts') !== false || strpos($current_page, '/user/host_detail') !== false || strpos($current_page, '/user/host_kvm') !== false ? 'active' : ''; ?>"><?php echo __('nav.hosts'); ?></a>
                <a href="#contact" class="<?php echo strpos($current_page, '#contact') !== false ? 'active' : ''; ?>"><?php echo __('nav.contact'); ?></a>
            </div>
        </div>
        <div class="nav-right">
            <?php if (auth_check()): ?>
            <div class="nav-search">
                <input type="text" placeholder="<?php echo __('nav.search'); ?>" class="nav-search-input" onkeydown="if(event.key==='Enter')searchHosts()">
            </div>
            <button class="btn btn-primary nav-create-btn" onclick="location.href='/checkout.php'">
                <span>＋</span> <?php echo __('nav.create_host'); ?>
            </button>
            <div class="nav-region" id="navRegion" onclick="toggleRegionPanel(event)">
                <span>🌍 <span id="navRegionText"><?php
                    if ($selected_region) {
                        echo e($selected_region['name'] . ' (' . $selected_region['code'] . ')');
                    } else {
                        echo '区域：上海 (AP-Shanghai)';
                    }
                ?></span> <?php if (count($nav_regions) > 1) echo '<span style="font-size:10px;">▼</span>'; ?></span>
                <?php if (count($nav_regions) > 1): ?>
                <div class="region-dropdown" id="regionDropdown">
                    <div class="region-dropdown-header">选择地区</div>
                    <?php foreach ($nav_regions as $r): ?>
                    <div class="region-dropdown-item <?php if ($selected_region && intval($r['id']) === intval($selected_region['id'])) echo 'active'; ?>" onclick="selectRegion(<?php echo $r['id']; ?>)">
                        <span><?php echo e($r['name']); ?></span>
                        <code style="font-size:11px;color:#86909c;"><?php echo e($r['code']); ?></code>
                        <?php if ($r['is_default']): ?><span style="font-size:10px;color:var(--primary);">默认</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php echo lang_switcher_css(); ?>
            <?php echo lang_switcher_html('compact'); ?>
            <div class="nav-notifications" id="notificationBtn" onclick="toggleNotificationPanel()">
                <span class="notification-icon">🔔</span>
                <?php if ($unread_count > 0): ?>
                <span class="notification-badge" id="notificationBadge"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
                <?php endif; ?>
            </div>
            <div class="nav-user-info" onclick="toggleUserMenu()">
                <div class="avatar-mini">
                    <?php echo mb_substr($user['username'], 0, 1); ?>
                </div>
                <span class="nav-username"><?php echo e($user['username']); ?></span>
                <span class="nav-caret">▼</span>
            </div>
            <a href="/user/index.php" class="nav-console-btn">
                <span>🖥️</span> <?php echo __('nav.console'); ?>
            </a>
            <?php else: ?>
            <a href="/login.php" class="btn btn-sm btn-secondary"><?php echo __('nav.login'); ?></a>
            <a href="/register.php" class="btn btn-sm btn-primary"><?php echo __('nav.register'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (auth_check()): ?>
    <div class="user-menu" id="userMenu">
        <div class="user-menu-header">
            <div class="user-menu-avatar"><?php echo mb_substr($user['username'], 0, 1); ?></div>
            <div class="user-menu-info">
                <div class="user-menu-name"><?php echo e($user['username']); ?></div>
                <div class="user-menu-balance"><?php echo __('user_menu.balance'); ?>: ¥<?php echo number_format($user['balance'], 2); ?></div>
            </div>
        </div>
        <div class="user-menu-divider"></div>
        <div class="user-menu-items">
            <a href="/user/profile.php"><span class="user-menu-icon">👤</span> <?php echo __('user_menu.profile'); ?></a>
            <a href="/user/api_keys.php"><span class="user-menu-icon">🔑</span> <?php echo __('user_menu.api_keys'); ?></a>
        </div>
        <div class="user-menu-divider"></div>
        <a href="/logout.php" class="user-menu-logout"><span class="user-menu-icon">🚪</span> <?php echo __('user_menu.logout'); ?></a>
    </div>
    <?php endif; ?>
</nav>

<?php if (auth_check()): ?>
<div class="notification-panel" id="notificationPanel">
    <div class="notification-panel-header">
        <div class="notification-panel-title">
            <span>🔔 <?php echo __('notification.title'); ?></span>
            <span class="notification-panel-count" id="panelUnreadCount"><?php echo __p('notification.unread', ['count' => $unread_count]); ?></span>
        </div>
        <div class="notification-panel-actions">
            <a href="javascript:void(0)" onclick="markAllRead()"><?php echo __('notification.mark_all_read'); ?></a>
            <a href="/user/notifications.php"><?php echo __('notification.view_all'); ?></a>
        </div>
    </div>
    <div class="notification-tabs">
        <span class="notification-tab active" onclick="filterNotifications('all', this)"><?php echo __('notification.all'); ?></span>
        <span class="notification-tab" onclick="filterNotifications('host', this)"><?php echo __('notification.host'); ?></span>
        <span class="notification-tab" onclick="filterNotifications('order', this)"><?php echo __('notification.order'); ?></span>
        <span class="notification-tab" onclick="filterNotifications('system', this)"><?php echo __('notification.system'); ?></span>
    </div>
    <div class="notification-list" id="notificationList">
        <?php if (empty($notification_list)): ?>
        <div class="notification-empty">
            <div class="empty-icon">📭</div>
            <div><?php echo __('notification.empty'); ?></div>
        </div>
        <?php else: ?>
        <?php foreach ($notification_list as $n): ?>
        <div class="notification-item <?php echo $n['is_read'] ? 'is-read' : 'is-unread'; ?>" data-type="<?php echo e($n['type']); ?>" data-id="<?php echo $n['id']; ?>" onclick="openNotification(<?php echo $n['id']; ?>, '<?php echo e($n['related_type']); ?>', <?php echo intval($n['related_id']); ?>)">
            <div class="notification-item-icon">
                <?php echo notification_type_icon($n['type']); ?>
            </div>
            <div class="notification-item-content">
                <div class="notification-item-title">
                    <?php if (!$n['is_read']): ?>
                    <span class="notification-dot"></span>
                    <?php endif; ?>
                    <?php echo e($n['title']); ?>
                </div>
                <?php if (!empty($n['content'])): ?>
                <div class="notification-item-desc"><?php echo e(mb_substr($n['content'], 0, 50)); ?></div>
                <?php endif; ?>
                <div class="notification-item-time"><?php echo format_time_ago($n['created_at']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<div class="notification-mask" id="notificationMask" onclick="closeNotificationPanel()"></div>
<?php endif; ?>

<style>
.nav-left {
    display: flex;
    align-items: center;
    gap: 32px;
    flex: 1;
}
.nav-right {
    display: flex;
    align-items: center;
    gap: 14px;
}
.logo-text {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: var(--text-primary);
}
.nav-search {
    position: relative;
}
.nav-search-input {
    width: 200px;
    padding: 7px 12px 7px 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    background: var(--bg-page);
    transition: all 0.2s;
    outline: none;
}
.nav-search-input:focus {
    border-color: var(--primary);
    background: #fff;
    width: 260px;
    box-shadow: 0 0 0 3px rgba(22,119,255,0.1);
}
.nav-search::before {
    content: "🔍";
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    opacity: 0.5;
}
.nav-create-btn {
    padding: 7px 16px;
    font-size: 13px;
    font-weight: 600;
    border-radius: var(--radius-sm);
}
.nav-region {
    font-size: 12px;
    color: var(--text-regular);
    padding: 5px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: #fff;
    position: relative;
    cursor: pointer;
    transition: all 0.2s;
}
.nav-region:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.region-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 6px;
    min-width: 220px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    z-index: 999;
    overflow: hidden;
}
.region-dropdown.show {
    display: block;
}
.region-dropdown-header {
    padding: 10px 14px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-page);
}
.region-dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: var(--text-regular);
    cursor: pointer;
    transition: background 0.2s;
    border-bottom: 1px solid var(--border-light);
}
.region-dropdown-item:last-child {
    border-bottom: none;
}
.region-dropdown-item:hover {
    background: var(--bg-secondary);
    color: var(--primary);
}
.region-dropdown-item.active {
    background: var(--primary-lighter);
    color: var(--primary);
    font-weight: 500;
}
.nav-notifications {
    position: relative;
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
}
.nav-notifications:hover {
    background: var(--bg-secondary);
}
.notification-icon {
    font-size: 18px;
}
.notification-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    min-width: 16px;
    height: 16px;
    padding: 0 3px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    box-shadow: 0 2px 4px rgba(245,63,63,0.3);
}
.nav-user-info {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
}
.nav-user-info:hover {
    background: var(--bg-secondary);
}
.nav-username {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
}
.nav-caret {
    font-size: 10px;
    color: var(--text-secondary);
}
.nav-console-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}
.nav-console-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    color: #fff;
}
.navbar {
    height: 52px;
    background: #fff;
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: var(--shadow-sm);
}
.nav-container {
    height: 52px;
    padding: 0 20px;
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.nav-links {
    margin-left: 0;
    gap: 2px;
    display: flex;
}
.nav-links a {
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-regular);
    border-radius: var(--radius-sm);
    transition: all 0.2s;
    position: relative;
    text-decoration: none;
}
.nav-links a:hover {
    color: var(--primary);
    background: var(--primary-lighter);
}
.nav-links a.active {
    color: var(--primary);
    font-weight: 600;
}
.nav-links a.active::after {
    content: "";
    position: absolute;
    bottom: -13px;
    left: 50%;
    transform: translateX(-50%);
    width: 16px;
    height: 2px;
    background: var(--primary);
    border-radius: 2px 2px 0 0;
}

.user-menu {
    display: none;
    position: fixed;
    top: 58px;
    right: 140px;
    width: 240px;
    background: #fff;
    border-radius: var(--radius-lg);
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    z-index: 999;
    border: 1px solid var(--border);
    overflow: hidden;
}
.user-menu.show {
    display: block;
}
.user-menu-header {
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-page);
}
.user-menu-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary-gradient);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 600;
}
.user-menu-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 2px;
}
.user-menu-balance {
    font-size: 12px;
    color: var(--primary);
    font-weight: 500;
}
.user-menu-divider {
    height: 1px;
    background: var(--border-light);
}
.user-menu-items {
    padding: 8px 0;
}
.user-menu-items a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: var(--text-regular);
    font-size: 13px;
    text-decoration: none;
    transition: background 0.2s;
}
.user-menu-items a:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
}
.user-menu-icon {
    font-size: 14px;
}
.user-menu-logout {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: var(--danger);
    font-size: 13px;
    text-decoration: none;
    transition: background 0.2s;
    border-top: 1px solid var(--border-light);
}
.user-menu-logout:hover {
    background: var(--danger-light);
    color: var(--danger);
}

.notification-mask {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 998;
}
.notification-panel {
    display: none;
    position: fixed;
    top: 58px;
    right: 140px;
    width: 380px;
    max-height: 520px;
    background: #fff;
    border-radius: var(--radius-lg);
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    z-index: 999;
    border: 1px solid var(--border);
    overflow: hidden;
    flex-direction: column;
}
.notification-panel.show {
    display: flex;
}
.notification-mask.show {
    display: block;
}
.notification-panel-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.notification-panel-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}
.notification-panel-count {
    font-size: 12px;
    color: var(--primary);
    font-weight: 500;
    background: var(--primary-lighter);
    padding: 2px 8px;
    border-radius: 10px;
}
.notification-panel-actions {
    display: flex;
    gap: 12px;
    font-size: 12px;
}
.notification-panel-actions a {
    color: var(--text-secondary);
    cursor: pointer;
    transition: color 0.2s;
    text-decoration: none;
}
.notification-panel-actions a:hover {
    color: var(--primary);
}
.notification-tabs {
    display: flex;
    gap: 0;
    padding: 0 16px;
    border-bottom: 1px solid var(--border);
}
.notification-tab {
    padding: 10px 12px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.2s;
}
.notification-tab:hover {
    color: var(--text-primary);
}
.notification-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 500;
}
.notification-list {
    flex: 1;
    overflow-y: auto;
    max-height: 400px;
}
.notification-empty {
    padding: 48px 20px;
    text-align: center;
    color: var(--text-secondary);
    font-size: 13px;
}
.empty-icon {
    font-size: 36px;
    margin-bottom: 8px;
    opacity: 0.6;
}
.notification-item {
    display: flex;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.2s;
}
.notification-item:hover {
    background: var(--bg-secondary);
}
.notification-item.is-read {
    opacity: 0.7;
}
.notification-item-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.notification-item-content {
    flex: 1;
    min-width: 0;
}
.notification-item-title {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 500;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.notification-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--primary);
    flex-shrink: 0;
}
.notification-item-desc {
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.notification-item-time {
    font-size: 11px;
    color: var(--text-placeholder);
}

@media (max-width: 1024px) {
    .nav-links {
        display: none;
    }
    .nav-search {
        display: none;
    }
    .nav-region {
        display: none;
    }
    .nav-create-btn {
        font-size: 12px;
        padding: 6px 12px;
    }
    .nav-console-btn {
        font-size: 12px;
        padding: 6px 12px;
    }
}
</style>

<script>
function toggleNotificationPanel() {
    var panel = document.getElementById('notificationPanel');
    var mask = document.getElementById('notificationMask');
    if (panel.classList.contains('show')) {
        closeNotificationPanel();
    } else {
        panel.classList.add('show');
        mask.classList.add('show');
        loadNotifications();
    }
}

function closeNotificationPanel() {
    var panel = document.getElementById('notificationPanel');
    var mask = document.getElementById('notificationMask');
    panel.classList.remove('show');
    mask.classList.remove('show');
    document.getElementById('userMenu')?.classList.remove('show');
}

function toggleUserMenu() {
    var menu = document.getElementById('userMenu');
    if (menu) {
        menu.classList.toggle('show');
        document.getElementById('notificationPanel')?.classList.remove('show');
        document.getElementById('regionDropdown')?.classList.remove('show');
    }
}

function toggleRegionPanel(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    var dropdown = document.getElementById('regionDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
        document.getElementById('userMenu')?.classList.remove('show');
        document.getElementById('notificationPanel')?.classList.remove('show');
    }
}

function selectRegion(regionId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/regions.php?action=select', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.code === 0) {
                // 更新显示
                var text = document.getElementById('navRegionText');
                if (text && res.region) {
                    text.textContent = res.region.name + ' (' + res.region.code + ')';
                }
                // 更新选中状态
                document.querySelectorAll('.region-dropdown-item').forEach(function(item) {
                    item.classList.remove('active');
                });
                // 刷新页面以应用地区
                location.reload();
            }
        } catch(e) {}
    };
    xhr.send('region_id=' + regionId);
    document.getElementById('regionDropdown')?.classList.remove('show');
}

function loadNotifications() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/api/notifications.php?action=list&limit=8', true);
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.code === 0) {
                renderNotifications(res.list);
                updateBadge(res.unread_count);
            }
        } catch(e) {}
    };
    xhr.send();
}

function renderNotifications(list) {
    var container = document.getElementById('notificationList');
    if (!list || list.length === 0) {
        container.innerHTML = '<div class="notification-empty"><div class="empty-icon">📭</div><div><?php echo addslashes(__('notification.empty')); ?></div></div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < list.length; i++) {
        var n = list[i];
        html += '<div class="notification-item ' + (n.is_read ? 'is-read' : 'is-unread') + '" data-type="' + n.type + '" data-id="' + n.id + '" onclick="openNotification(' + n.id + ', \'' + n.related_type + '\', ' + n.related_id + ')">';
        html += '<div class="notification-item-icon">' + n.icon + '</div>';
        html += '<div class="notification-item-content">';
        html += '<div class="notification-item-title">';
        if (!n.is_read) html += '<span class="notification-dot"></span>';
        html += n.title + '</div>';
        if (n.content) {
            var desc = n.content.length > 50 ? n.content.substring(0, 50) + '...' : n.content;
            html += '<div class="notification-item-desc">' + desc + '</div>';
        }
        html += '<div class="notification-item-time">' + n.time_text + '</div>';
        html += '</div></div>';
    }
    container.innerHTML = html;
}

function updateBadge(count) {
    var badge = document.getElementById('notificationBadge');
    var panelCount = document.getElementById('panelUnreadCount');
    panelCount.textContent = count + '<?php echo addslashes(__('notification.unread')); ?>'.replace(':count', '');
    
    if (count > 0) {
        if (!badge) {
            var btn = document.getElementById('notificationBtn');
            var span = document.createElement('span');
            span.className = 'notification-badge';
            span.id = 'notificationBadge';
            btn.appendChild(span);
            badge = span;
        }
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
    } else {
        if (badge) {
            badge.style.display = 'none';
        }
    }
}

function markAllRead() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/notifications.php?action=read', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.code === 0) {
                loadNotifications();
            }
        } catch(e) {}
    };
    xhr.send();
}

function openNotification(id, relatedType, relatedId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/notifications.php?action=read', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send('id=' + id);
    
    if (relatedType === 'host' && relatedId > 0) {
        window.location.href = '/user/host_kvm.php?id=' + relatedId;
    } else if (relatedType === 'order' && relatedId > 0) {
        window.location.href = '/user/orders.php';
    }
    
    closeNotificationPanel();
}

function filterNotifications(type, el) {
    var tabs = document.querySelectorAll('.notification-tab');
    tabs.forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    
    var items = document.querySelectorAll('.notification-item');
    items.forEach(function(item) {
        if (type === 'all' || item.dataset.type === type) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function searchHosts() {
    var input = document.querySelector('.nav-search-input');
    if (input && input.value.trim()) {
        window.location.href = '/user/hosts.php?q=' + encodeURIComponent(input.value.trim());
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNotificationPanel();
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-user-info') && !e.target.closest('.user-menu')) {
        document.getElementById('userMenu')?.classList.remove('show');
    }
    if (!e.target.closest('.nav-region')) {
        document.getElementById('regionDropdown')?.classList.remove('show');
    }
});

setInterval(function() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/api/notifications.php?action=count', true);
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.code === 0 && typeof res.unread_count !== 'undefined') {
                updateBadge(res.unread_count);
            }
        } catch(e) {}
    };
    xhr.send();
}, 30000);
</script>